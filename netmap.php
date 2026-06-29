<?php
/**
 * netmap.php – AllStarLink Network Node Discovery
 *
 * HOW IT WORKS
 *   1. Reads /etc/allmon3/netmap-settings.ini for one or more Asterisk AMI servers, each
 *      with host, port, user, pass, and a comma-separated list of node numbers.
 *   2. Connects to each AMI server in turn and issues RptStatus XStat for
 *      every node listed under that server.
 *   3. Parses the LinkedNodes: field, which contains the FULL transitive set of
 *      every node reachable through the network – not just direct connections.
 *   4. Results from all servers are merged into a single flat JSON object.
 *   5. Deduplicates, excludes private nodes (< 2000), and enriches each with
 *      metadata from /var/lib/asterisk/astdb.txt and optional lat/lon from
 *      /etc/allmon3/netmap-nodelist.ini.
 *   6. For nodes still missing coordinates, looks them up on QRZ.com by
 *      callsign (requires a [qrz] section in netmap-settings.ini).  Newly-found lat/lon
 *      pairs are appended to netmap-nodelist.ini automatically so future
 *      requests skip the lookup.
 *
 * KEY DESIGN NOTES
 *   • No recursive BFS needed – LinkedNodes already contains the full picture.
 *   • The AMI response reader uses ActionID matching and discards Event: blocks
 *     so that unsolicited events (RPT_RXKEYED etc.) cannot corrupt subsequent
 *     command responses.
 *   • Conn: line IP parsing: format is NODE IP EXTRA DIR CTIME STATE (6 tokens),
 *     so the real IP is tokens[1].
 *
 * INSTALL
 *   sudo cp netmap.php /usr/share/allmon3/
 *
 * ACCESS
 *   http://<pi>/allmon3/netmap.php
 *   http://<pi>/allmon3/netmap.php?pretty=1
 *   http://<pi>/allmon3/netmap.php?format=kml   ← KML (Google Earth / Google Maps)
 *   http://<pi>/allmon3/netmap.php?format=geojson   ← GeoJSON (GIS / mapping tools)
 *   http://<pi>/allmon3/netmap.php?template=1   ← netmap-nodelist.ini template
 */

// ── Configuration ──────────────────────────────────────────────────────────────

define('SETTINGS_INI', '/etc/allmon3/netmap-settings.ini'); // AMI servers, QRZ credentials
define('ASTDB_PATH',   '/var/lib/asterisk/astdb.txt');      // node metadata flat file
define('COORDS_INI',   '/etc/allmon3/netmap-nodelist.ini'); // per-node lat/lon overrides
define('AMI_TIMEOUT',  10);    // seconds to wait for each AMI response
define('FETCH_NODEDB', true);  // true → fall back to allmondb.allstarlink.org if astdb.txt is empty
define('MAX_NODES',    1000);  // safety cap on total nodes returned
define('QRZ_BATCH',    20);    // max QRZ lookups per request (remainder cached on next run)

// ── HTTP headers ───────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

// ── Config parsers ─────────────────────────────────────────────────────────────

/**
 * Parse the AMI server sections from netmap-settings.ini and return an array of
 * server configs.  The reserved [qrz] section is silently skipped.
 *
 * Each AMI section defines one Asterisk instance.  Example:
 *
 *   [my_repeater]
 *   host  = 127.0.0.1
 *   port  = 5038
 *   user  = admin
 *   pass  = secret
 *   nodes = 499600, 499601
 *
 * Returns an array keyed by section name, each value an array with keys:
 *   host, port, user, pass, nodes (array of positive integers).
 * Sections that have no valid nodes are included but will be skipped at query
 * time with an error entry in the response.
 */
function parse_ami_ini(string $path): array
{
    $servers = [];
    if (!is_readable($path)) {
        return $servers;
    }
    $ini = @parse_ini_file($path, true, INI_SCANNER_RAW);
    if ($ini === false) {
        return $servers;
    }

    foreach ($ini as $section => $cfg) {
        if (strtolower($section) === 'qrz') {
            continue;  // reserved for QRZ credentials — not an AMI server section
        }
        $nodes = [];
        foreach (explode(',', $cfg['nodes'] ?? '') as $n) {
            $n = trim($n);
            if (is_numeric($n) && (int) $n > 0) {
                $nodes[] = (int) $n;
            }
        }
        $servers[$section] = [
            'host'  => trim($cfg['host'] ?? '127.0.0.1'),
            'port'  => (int) ($cfg['port'] ?? 5038),
            'user'  => trim($cfg['user'] ?? ''),
            'pass'  => trim($cfg['pass'] ?? ''),
            'nodes' => array_values(array_unique($nodes)),
        ];
    }

    return $servers;
}

/**
 * Load the local AllStarLink node database flat file.
 * Format: node|callsign|desc|loc  (same as allmondb.allstarlink.org)
 */
function load_local_nodedb(string $path): array
{
    $db = [];
    if (!is_readable($path)) {
        return $db;
    }
    foreach (explode("\n", file_get_contents($path)) as $line) {
        $p = explode('|', trim($line));
        if (count($p) === 4) {
            $db[$p[0]] = [
                'callsign' => trim($p[1]),
                'desc'     => trim($p[2]),
                'loc'      => trim($p[3]),
            ];
        }
    }
    return $db;
}

/**
 * Optionally fetch the node database from allmondb.allstarlink.org.
 */
function fetch_remote_nodedb(): array
{
    $db  = [];
    $ctx = stream_context_create([
        'http' => ['timeout' => 10, 'user_agent' => 'Allmon3-netmap-php/1.0'],
    ]);
    $raw = @file_get_contents('https://allmondb.allstarlink.org/', false, $ctx);
    if (!$raw) {
        return $db;
    }
    foreach (explode("\n", $raw) as $line) {
        $p = explode('|', trim($line));
        if (count($p) === 4) {
            $db[$p[0]] = [
                'callsign' => trim($p[1]),
                'desc'     => trim($p[2]),
                'loc'      => trim($p[3]),
            ];
        }
    }
    return $db;
}

/**
 * Look up a node in the database and return callsign + combined description.
 */
function node_lookup(string $nid, array $nodedb): array
{
    if (isset($nodedb[$nid])) {
        $r = $nodedb[$nid];
        return [
            'callsign' => $r['callsign'],
            'desc'     => trim("{$r['callsign']} {$r['desc']} {$r['loc']}"),
        ];
    }
    return ['callsign' => null, 'desc' => 'Unavailable'];
}

/**
 * Load per-node coordinate overrides from netmap-nodelist.ini.
 */
function load_coords(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $ini = @parse_ini_file($path, true, INI_SCANNER_RAW);
    return $ini === false ? [] : $ini;
}

/**
 * Merge lat/lon and optional label from the coords map into a node entry.
 * Always sets lat and lon so every entry has them (null when unset).
 */
function apply_coords(array &$entry, string $nid, array $coords): void
{
    $c            = $coords[$nid] ?? [];
    $entry['lat'] = isset($c['lat']) && $c['lat'] !== '' ? (float) trim($c['lat']) : null;
    $entry['lon'] = isset($c['lon']) && $c['lon'] !== '' ? (float) trim($c['lon']) : null;
    if (!empty($c['label'])) {
        $entry['desc'] = trim($c['label']);
    }
}

// ── AMI socket helpers ─────────────────────────────────────────────────────────

/**
 * Open a TCP connection to the Asterisk AMI and authenticate.
 * Returns an open socket resource on success, or null on failure.
 */
function ami_connect(string $host, int $port, string $user, string $pass): mixed
{
    $sock = @fsockopen($host, $port, $errno, $errstr, AMI_TIMEOUT);
    if (!$sock) {
        return null;
    }
    stream_set_timeout($sock, AMI_TIMEOUT);

    // Read the one-line banner: "Asterisk Call Manager/X.X\r\n"
    $banner = fgets($sock, 256);
    if ($banner === false || strpos($banner, 'Asterisk Call Manager') === false) {
        fclose($sock);
        return null;
    }

    // Authenticate
    $action_id = uniqid('am3_', true);
    fwrite($sock,
        "ACTION: LOGIN\r\n"
        . "USERNAME: $user\r\n"
        . "SECRET: $pass\r\n"
        . "EVENTS: 0\r\n"
        . "ActionID: $action_id\r\n\r\n"
    );
    $resp = ami_read_response($sock, $action_id);
    if (strpos($resp, 'Response: Success') === false) {
        fclose($sock);
        return null;
    }

    return $sock;
}

/**
 * Send an AMI action block and return the matching response.
 * Appends a unique ActionID; discards Event: blocks and unrelated responses.
 */
function ami_send(mixed $sock, string $action): string
{
    $action_id = uniqid('am3_', true);
    fwrite($sock, $action . "ActionID: $action_id\r\n\r\n");
    return ami_read_response($sock, $action_id);
}

/**
 * Read from the socket until we receive the block whose ActionID matches
 * $expected_id.  Unsolicited Event: blocks are silently discarded.
 * A safety limit of 100 blocks prevents infinite loops during event storms.
 */
function ami_read_response(mixed $sock, string $expected_id): string
{
    $deadline   = microtime(true) + AMI_TIMEOUT;
    $max_blocks = 100;
    $attempts   = 0;

    while (microtime(true) < $deadline && $attempts < $max_blocks) {
        $block = ami_read_block($sock, $deadline);
        $attempts++;

        if ($block === '') {
            continue;
        }

        // Discard unsolicited event blocks (start with "Event:")
        if (strncmp($block, 'Event:', 6) === 0) {
            continue;
        }

        // Return the block that belongs to our action
        if (strpos($block, "ActionID: $expected_id") !== false) {
            return $block;
        }

        // Any other block (e.g. a late response for a previous action) is discarded
    }

    return '';
}

/**
 * Read one complete AMI message block (terminated by \r\n\r\n) from the socket.
 * Returns an empty string if the deadline passes or the connection drops.
 */
function ami_read_block(mixed $sock, float $deadline): string
{
    $buf = '';
    while (microtime(true) < $deadline) {
        $chunk = fread($sock, 4096);
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($sock);
            if ($meta['timed_out'] || feof($sock)) {
                break;
            }
            usleep(500);
            continue;
        }
        $buf .= $chunk;
        if (substr($buf, -4) === "\r\n\r\n") {
            break;
        }
    }
    return $buf;
}

// ── Response parsers ───────────────────────────────────────────────────────────

/**
 * Parse an RptStatus XStat response.
 *
 * LinkedNodes: line contains the FULL transitive set of nodes reachable through
 * this node.  Prefix T/R/C/U is stripped; private nodes (< 2000) are filtered
 * out.  Callsign-based entries (EchoLink, phone portals) are collected into
 * linked_echolink for separate processing.
 *
 * Returns an array with keys: node, txkeyed, txekeyed, rxkeyed, numlinks,
 *   uptime, reloadtime, linked_nodes (internal), linked_echolink (internal).
 */
function parse_xstat(string $resp, int $node): array
{
    $entry = [
        'node'         => $node,
        'txkeyed'      => false,
        'txekeyed'     => false,
        'rxkeyed'      => false,
        'numlinks'     => 0,
        'uptime'       => -1,
        'reloadtime'   => -1,
        'linked_nodes'    => [],  // internal – removed before output
        'linked_echolink' => [],  // internal – removed before output
    ];

    foreach (preg_split('/[\r\n]+/', $resp) as $line) {

        // ── LinkedNodes: ──────────────────────────────────────────────────────
        // Full transitive network list, comma-separated, each prefixed with mode.
        // Example: "LinkedNodes: T1917, T65017, TKJ5CWR-P, ..."
        if (strncmp($line, 'LinkedNodes:', 12) === 0) {
            foreach (explode(',', substr($line, 12)) as $link) {
                $link = trim($link);
                if ($link === '') {
                    continue;
                }
                // Format: MODE-prefix + node-id (e.g. T499601); skip callsign-based entries
                if (!preg_match('/^[TRCU](\S+)$/', $link, $m)) {
                    continue;
                }
                $nid = $m[1];
                if (is_numeric($nid) && (int) $nid >= 2000) {
                    $entry['linked_nodes'][(int) $nid] = true;
                } elseif (!is_numeric($nid)) {
                    // Callsign-based entry (EchoLink link, repeater, phone portal, etc.)
                    // Strip the EchoLink suffix (-L, -R, -P, -B) to get the base callsign.
                    $base = strtoupper(preg_replace('/-[LRPBlrpb]$/i', '', $nid));
                    if (strlen($base) >= 3) {
                        $entry['linked_echolink'][$base] = true;
                    }
                }
            }
            continue;
        }

        // ── Var: lines ────────────────────────────────────────────────────────
        if      (preg_match('/^Var:\s+RPT_TXKEYED=(\d)/',   $line, $m)) { $entry['txkeyed']  = ($m[1] === '1'); }
        elseif  (preg_match('/^Var:\s+RPT_TXEKEYED=(\d)/',  $line, $m)) { $entry['txekeyed'] = ($m[1] === '1'); }
        elseif  (preg_match('/^Var:\s+RPT_ETXKEYED=(\d)/',  $line, $m)) { $entry['txekeyed'] = ($m[1] === '1'); } // ASL3
        elseif  (preg_match('/^Var:\s+RPT_RXKEYED=(\d)/',   $line, $m)) { $entry['rxkeyed']  = ($m[1] === '1'); }
        elseif  (preg_match('/^Var:\s+RPT_NUMLINKS=(\d+)/', $line, $m)) { $entry['numlinks'] = (int) $m[1]; }
    }

    return $entry;
}

/**
 * Parse "core show uptime seconds" AMI response.
 * Handles both raw output and ASL3 Output:-prefixed forms.
 */
function parse_uptime(string $resp): array
{
    $uptime = -1;
    $reload = -1;
    foreach (preg_split('/[\r\n]+/', $resp) as $line) {
        if (preg_match('/System uptime:\s+(\d+)/', $line, $m)) { $uptime = (int) $m[1]; }
        if (preg_match('/Last reload:\s+(\d+)/',   $line, $m)) { $reload = (int) $m[1]; }
    }
    return ['uptime' => $uptime, 'reloadtime' => $reload];
}

/**
 * Output a netmap-nodelist.ini template pre-populated with every discovered node.
 * Nodes that already have coordinates in the current COORDS_INI are pre-filled.
 * Local (directly-queried) nodes are listed first, remote (LinkedNodes) second.
 * Triggered by the ?template query parameter.
 */
function output_coords_template(array $result_nodes, array $coords): void
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: inline; filename="netmap-nodelist.ini"');

    $date = gmdate('Y-m-d H:i:s') . ' UTC';
    echo "; netmap-nodelist.ini – generated by netmap.php on $date\n";
    echo "; Copy to /etc/allmon3/netmap-nodelist.ini and fill in the lat/lon values.\n";
    echo "; Nodes that already have coordinates in the current file are pre-filled.\n";
    echo "; The optional label= key overrides the node description shown in the UI.\n";

    // Separate and sort: local nodes first, remote second.
    // Within each group: nodes WITHOUT coordinates come first (so they are easy
    // to spot and fill in), then nodes that already have coords; both sub-groups
    // sorted numerically ascending.
    $local_nodes  = [];
    $remote_nodes = [];
    foreach ($result_nodes as $nid_str => $entry) {
        if (!empty($entry['local'])) {
            $local_nodes[$nid_str] = $entry;
        } else {
            $remote_nodes[$nid_str] = $entry;
        }
    }

    $sort_fn = static function (array $a, array $b): int {
        $has_a = $a['lat'] !== null;
        $has_b = $b['lat'] !== null;
        if ($has_a !== $has_b) {
            return $has_a ? 1 : -1;  // no-coords first
        }
        // ASL nodes (integer IDs) sort numerically; EchoLink nodes sort by callsign
        $a_is_asl = ($a['type'] ?? 'asl') === 'asl';
        $b_is_asl = ($b['type'] ?? 'asl') === 'asl';
        if ($a_is_asl && $b_is_asl) {
            return $a['node'] <=> $b['node'];  // numeric ascending
        }
        if (!$a_is_asl && !$b_is_asl) {
            return strcmp($a['callsign'] ?? '', $b['callsign'] ?? '');  // alphabetically
        }
        return $a_is_asl ? -1 : 1;  // ASL nodes before EchoLink
    };

    uasort($local_nodes,  $sort_fn);
    uasort($remote_nodes, $sort_fn);

    $groups = [];
    if (!empty($local_nodes))  { $groups['Locally-queried nodes'] = $local_nodes; }
    if (!empty($remote_nodes)) { $groups['Network nodes']         = $remote_nodes; }

    foreach ($groups as $heading => $nodes) {
        $bar = str_repeat('-', max(0, 78 - strlen($heading) - 6));
        echo "\n; -- $heading $bar\n\n";
        foreach ($nodes as $nid_str => $entry) {
            $tag  = !empty($entry['local']) ? 'local' : 'remote';
            $desc = $entry['desc'] ?? 'Unavailable';
            $c    = $coords[$nid_str] ?? [];
            $lat  = isset($c['lat']) && $c['lat'] !== '' ? trim($c['lat']) : '';
            $lon  = isset($c['lon']) && $c['lon'] !== '' ? trim($c['lon']) : '';
            echo "[$nid_str]\n";
            echo "; $desc  [$tag]\n";
            echo "lat = $lat\n";
            echo "lon = $lon\n";
            echo "; label =\n";
            echo "\n";
        }
    }
}
/**
 * Output the node list as a KML document.
 * Only nodes that have both lat and lon are emitted as Placemarks.
 * Triggered by the ?format=kml query parameter.
 *
 * Three icon styles distinguish node categories:
 *   local-asl   – nodes directly queried via AMI (blue paddle)
 *   remote-asl  – nodes discovered through LinkedNodes (green paddle)
 *   echolink    – EchoLink / phone-portal nodes (yellow paddle)
 */
function output_kml(array $result_nodes, bool $truncated): void
{
    header('Content-Type: application/vnd.google-earth.kml+xml; charset=utf-8');
    header('Content-Disposition: inline; filename="netmap.kml"');

    $date = gmdate('Y-m-d H:i:s') . ' UTC';

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<kml xmlns="http://www.opengis.net/kml/2.2">' . "\n";
    echo "  <Document>\n";
    echo '    <name>' . htmlspecialchars('AllStarLink Network Map', ENT_XML1, 'UTF-8') . "</name>\n";
    echo '    <description>' . htmlspecialchars("Generated by netmap.php on $date", ENT_XML1, 'UTF-8') . "</description>\n";

    // ── Icon styles ──────────────────────────────────────────────────────────
    $styles = [
        'local-asl'  => 'http://maps.google.com/mapfiles/kml/paddle/blu-circle.png',
        'remote-asl' => 'http://maps.google.com/mapfiles/kml/paddle/grn-circle.png',
        'echolink'   => 'http://maps.google.com/mapfiles/kml/paddle/ylw-circle.png',
    ];
    foreach ($styles as $id => $href) {
        echo "    <Style id=\"$id\"><IconStyle><Icon>"
           . '<href>' . htmlspecialchars($href, ENT_XML1, 'UTF-8') . "</href>"
           . "</Icon></IconStyle></Style>\n";
    }

    // ── Placemarks ───────────────────────────────────────────────────────────
    foreach ($result_nodes as $nid_str => $entry) {
        if ($entry['lat'] === null || $entry['lon'] === null) {
            continue;  // no coordinates — nothing to plot
        }

        $type    = $entry['type'] ?? 'asl';
        $local   = !empty($entry['local']);
        $node_id = $entry['node'];

        if ($type === 'echolink') {
            $style_id = 'echolink';
        } elseif ($local) {
            $style_id = 'local-asl';
        } else {
            $style_id = 'remote-asl';
        }

        $name_text = $entry['callsign'] ?? (string) $node_id;

        // Build a small HTML snippet for the Google Earth balloon.
        $type_label  = $type === 'echolink' ? 'EchoLink' : ('ASL ' . ($local ? 'local' : 'remote'));
        $desc_html   = '<b>' . htmlspecialchars($name_text, ENT_QUOTES, 'UTF-8') . '</b><br/>'
                     . 'Node: ' . htmlspecialchars((string) $node_id, ENT_QUOTES, 'UTF-8') . '<br/>'
                     . htmlspecialchars($entry['desc'] ?? '', ENT_QUOTES, 'UTF-8') . '<br/>'
                     . '<i>' . htmlspecialchars($type_label, ENT_QUOTES, 'UTF-8') . '</i>';

        // KML coordinates are lon,lat,altitude
        $lon = $entry['lon'];
        $lat = $entry['lat'];

        echo "    <Placemark>\n";
        echo '      <name>' . htmlspecialchars($name_text, ENT_XML1, 'UTF-8') . "</name>\n";
        echo "      <description><![CDATA[$desc_html]]></description>\n";
        echo "      <styleUrl>#$style_id</styleUrl>\n";
        echo "      <Point><coordinates>$lon,$lat,0</coordinates></Point>\n";
        echo "    </Placemark>\n";
    }

    if ($truncated) {
        echo '    <!-- Result was truncated at MAX_NODES=' . MAX_NODES . "; increase MAX_NODES in netmap.php to see more -->\n";
    }

    echo "  </Document>\n";
    echo "</kml>\n";
}

/**
 * Output the node list as GeoJSON.
 * Only nodes that have both lat and lon are emitted as Features.
 * Triggered by the ?format=geojson query parameter.
 */
function output_geojson(array $result_nodes, bool $truncated, array $errors = []): void
{
    header('Content-Type: application/geo+json; charset=utf-8');
    header('Content-Disposition: inline; filename="netmap.geojson"');

    $features = [];
    foreach ($result_nodes as $entry) {
        if ($entry['lat'] === null || $entry['lon'] === null) {
            continue;
        }

        $properties = [
            'node'      => $entry['node'],
            'callsign'  => $entry['callsign'],
            'desc'      => $entry['desc'] ?? '',
            'local'     => !empty($entry['local']),
            'type'      => $entry['type'] ?? 'asl',
            'lat'       => $entry['lat'],
            'lon'       => $entry['lon'],
        ];

        foreach (['txkeyed', 'txekeyed', 'rxkeyed', 'numlinks', 'uptime', 'reloadtime'] as $key) {
            if (array_key_exists($key, $entry)) {
                $properties[$key] = $entry[$key];
            }
        }

        $features[] = [
            'type'       => 'Feature',
            'geometry'   => [
                'type'        => 'Point',
                'coordinates' => [$entry['lon'], $entry['lat']],
            ],
            'properties' => $properties,
        ];
    }

    $output = [
        'type'     => 'FeatureCollection',
        'features' => $features,
        'total'    => count($features),
    ];

    if (!empty($errors)) {
        $output['errors'] = $errors;
    }
    if ($truncated) {
        $output['truncated'] = true;
        $output['note'] = sprintf(
            'Result capped at MAX_NODES (%d). Increase MAX_NODES in netmap.php to see more.',
            MAX_NODES
        );
    }

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (isset($_GET['pretty'])) {
        $flags |= JSON_PRETTY_PRINT;
    }
    echo json_encode($output, $flags);
}

// ── QRZ XML API helpers ──────────────────────────────────────────────────────

/**
 * Log in to the QRZ XML API and return a session key, or null on failure.
 *
 * QRZ requires an active XML Data subscription for full lat/lon access.
 * See: https://www.qrz.com/docs/xml/current_spec.html
 */
function qrz_login(string $user, string $pass, string &$error = ''): ?string
{
    $url = 'https://xmldata.qrz.com/xml/current/?'
         . 'username=' . urlencode($user) . ';'
         . 'password=' . urlencode($pass) . ';'
         . 'agent=allmon3-netmap/1.0';

    $ctx     = stream_context_create(['http' => ['timeout' => 5]]);
    $php_err = null;
    set_error_handler(static function (int $no, string $str) use (&$php_err): bool {
        $php_err = $str;
        return true;
    });
    $raw = file_get_contents($url, false, $ctx);
    restore_error_handler();

    if ($raw === false) {
        $error = 'QRZ network error: ' . ($php_err ?? 'unknown — check allow_url_fopen and SSL support');
        return null;
    }

    $xml = @simplexml_load_string($raw);
    if (!$xml) {
        $error = 'QRZ login: could not parse XML response';
        return null;
    }

    $api_error = trim((string) ($xml->Session->Error ?? ''));
    if ($api_error !== '') {
        $error = 'QRZ login: ' . $api_error;
        return null;
    }

    $key = trim((string) ($xml->Session->Key ?? ''));
    if ($key === '') {
        $error = 'QRZ login: no session key returned — subscription may be required';
        return null;
    }

    return $key;
}

/**
 * Fetch lat/lon for a callsign from the QRZ XML API.
 *
 * Returns ['lat' => float, 'lon' => float] on success.
 * Returns false  when the session key has expired (caller should re-login).
 * Returns null   when the callsign is not found or a network error occurred.
 */
function qrz_fetch(string $callsign, string $key): array|false|null
{
    $url = 'https://xmldata.qrz.com/xml/current/?'
         . 's='        . urlencode($key)      . ';'
         . 'callsign=' . urlencode($callsign);

    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) {
        return null;
    }

    $xml = @simplexml_load_string($raw);
    if (!$xml) {
        return null;
    }

    // A missing <Key> in the Session node signals session expiry.
    $session_key = trim((string) ($xml->Session->Key ?? ''));
    if ($session_key === '') {
        return false;  // expired — caller should re-login
    }

    // Any <Error> in the session means the lookup failed (not found, etc.).
    $error = trim((string) ($xml->Session->Error ?? ''));
    if ($error !== '') {
        return null;
    }

    if (!isset($xml->Callsign)) {
        return null;
    }

    $lat = trim((string) ($xml->Callsign->lat ?? ''));
    $lon = trim((string) ($xml->Callsign->lon ?? ''));
    if ($lat === '' || $lon === '') {
        return null;
    }

    return ['lat' => (float) $lat, 'lon' => (float) $lon];
}

/**
 * For every node in $result_nodes that has a known callsign but no lat/lon,
 * look up its coordinates on QRZ.com.  Updates $result_nodes in place and
 * appends all newly-found entries to COORDS_INI so future requests skip the
 * lookup.
 *
 * Returns an array of diagnostic strings (empty = no problems).  Errors are
 * included in the JSON response so they are visible without digging through
 * server logs.
 *
 * Lookups are capped at QRZ_BATCH per request to prevent PHP timeouts when
 * many new nodes are discovered at once.  Remaining nodes are resolved on
 * subsequent requests once earlier results are cached in COORDS_INI.
 *
 * Note: COORDS_INI must be group-writable (chmod 660) for appending to work.
 * The install.sh script sets this automatically.
 */
function qrz_enrich_and_persist(array &$result_nodes, string $qrz_user, string $qrz_pass): array
{
    $errs = [];

    if ($qrz_user === '') {
        return $errs;  // feature disabled — no [qrz] section in netmap-settings.ini
    }

    if (!function_exists('simplexml_load_string')) {
        $errs[] = 'QRZ disabled: the php-xml extension is not installed '
                . '(run: sudo apt install php-xml && sudo systemctl restart apache2)';
        return $errs;
    }

    // Collect nodes that have a known callsign but are still missing coordinates.
    $pending = [];  // nid_str => callsign
    foreach ($result_nodes as $nid_str => $entry) {
        if ($entry['callsign'] !== null && $entry['lat'] === null && $entry['lon'] === null) {
            $pending[$nid_str] = $entry['callsign'];
        }
    }
    if (empty($pending)) {
        return $errs;
    }

    // Cap per-request lookups to stay within PHP's max_execution_time.
    // Nodes resolved this run are written to COORDS_INI; the rest are handled
    // on the next request.
    $total_pending = count($pending);
    $pending       = array_slice($pending, 0, QRZ_BATCH, true);

    $login_error = '';
    $key = qrz_login($qrz_user, $qrz_pass, $login_error);
    if (!$key) {
        $errs[] = $login_error;
        return $errs;
    }

    $appended = [];  // nid_str => data for COORDS_INI append

    foreach ($pending as $nid_str => $callsign) {
        $coords = qrz_fetch($callsign, $key);

        // One automatic re-login on session expiry.
        if ($coords === false) {
            $relogin_error = '';
            $key = qrz_login($qrz_user, $qrz_pass, $relogin_error);
            if (!$key) {
                $errs[] = 'QRZ session re-login failed: ' . $relogin_error;
                break;
            }
            $coords = qrz_fetch($callsign, $key);
        }

        if (!is_array($coords)) {
            continue;  // not found or error — leave lat/lon null
        }

        // Update the in-memory result so this response reflects the new coords.
        $result_nodes[$nid_str]['lat'] = $coords['lat'];
        $result_nodes[$nid_str]['lon'] = $coords['lon'];

        $appended[$nid_str] = [
            'desc'  => $result_nodes[$nid_str]['desc']  ?? '',
            'local' => $result_nodes[$nid_str]['local'] ?? false,
            'lat'   => $coords['lat'],
            'lon'   => $coords['lon'],
        ];
    }

    if (!empty($appended)) {
        if (!is_writable(COORDS_INI)) {
            $errs[] = 'QRZ: found ' . count($appended) . ' coordinate(s) but cannot write to '
                    . COORDS_INI . ' (chmod 660 required for www-data)';
        } else {
            $ts    = gmdate('Y-m-d H:i:s') . ' UTC';
            $bar   = str_repeat('-', 40);
            $block = "\n; -- Added by QRZ lookup {$ts} {$bar}\n";

            foreach ($appended as $nid_str => $e) {
                $tag   = $e['local'] ? 'local' : 'remote';
                $block .= "\n[{$nid_str}]\n";
                $block .= "; {$e['desc']}  [{$tag}][qrz]\n";
                $block .= "lat = {$e['lat']}\n";
                $block .= "lon = {$e['lon']}\n";
                $block .= "; label =\n";
            }

            file_put_contents(COORDS_INI, $block, FILE_APPEND | LOCK_EX);
        }
    }

    if ($total_pending > QRZ_BATCH) {
        $errs[] = sprintf(
            'QRZ: resolved %d/%d node(s) this request (batch limit QRZ_BATCH=%d); remainder cached on next run',
            count($pending), $total_pending, QRZ_BATCH
        );
    }

    return $errs;
}
// ── Main ───────────────────────────────────────────────────────────────────────

$raw_settings = @parse_ini_file(SETTINGS_INI, true, INI_SCANNER_RAW) ?: [];
$qrz_user     = trim($raw_settings['qrz']['user'] ?? '');
$qrz_pass     = trim($raw_settings['qrz']['pass'] ?? '');
$servers      = parse_ami_ini(SETTINGS_INI);
$nodedb       = load_local_nodedb(ASTDB_PATH);
$coords       = load_coords(COORDS_INI);

if (empty($nodedb) && FETCH_NODEDB) {
    $nodedb = fetch_remote_nodedb();
}

if (empty($servers)) {
    http_response_code(500);
    echo json_encode(['error' => 'No AMI servers configured in ' . SETTINGS_INI]);
    exit;
}

// ── Query each AMI server ─────────────────────────────────────────────────────

$result_nodes       = [];  // nid_string => node entry  (merged across all servers)
$all_network_nodes  = [];  // nid_int    => true         (union of every LinkedNodes list)
$all_echolink_nodes = [];  // base_callsign => true      (EchoLink stations via LinkedNodes)
$errors             = [];  // non-fatal per-server errors reported in the response

foreach ($servers as $server_name => $server) {

    if (empty($server['user']) || empty($server['pass'])) {
        $errors[] = "[$server_name] missing user or pass in " . SETTINGS_INI;
        continue;
    }

    if (empty($server['nodes'])) {
        $errors[] = "[$server_name] no nodes configured – skipping";
        continue;
    }

    $sock = ami_connect($server['host'], $server['port'], $server['user'], $server['pass']);
    if (!$sock) {
        $errors[] = "[$server_name] could not connect to {$server['host']}:{$server['port']}";
        continue;
    }

    $uptime_data = null;  // reset per server

    foreach ($server['nodes'] as $node_num) {

        // XStat – LinkedNodes (full network) + keyed state
        $entry = parse_xstat(
            ami_send($sock, "ACTION: RptStatus\r\nNODE: $node_num\r\nCOMMAND: XStat\r\n"),
            $node_num
        );

        // Uptime – query once per server, share across that server's nodes
        if ($uptime_data === null) {
            $uptime_data = parse_uptime(
                ami_send($sock, "ACTION: COMMAND\r\nCOMMAND: core show uptime seconds\r\n")
            );
        }
        $entry['uptime']     = $uptime_data['uptime'];
        $entry['reloadtime'] = $uptime_data['reloadtime'];

        // Merge metadata
        $nid_str           = (string) $node_num;
        $d                 = node_lookup($nid_str, $nodedb);
        $entry['callsign'] = $d['callsign'];
        $entry['desc']     = $d['desc'];
        $entry['local']    = true;
        $entry['type']     = 'asl';

        // Accumulate the full network list from this node's LinkedNodes
        foreach (array_keys($entry['linked_nodes']) as $linked_nid) {
            $all_network_nodes[$linked_nid] = true;
        }
        foreach (array_keys($entry['linked_echolink']) as $el_callsign) {
            $all_echolink_nodes[$el_callsign] = true;
        }

        // linked_nodes and linked_echolink are internal – do not expose in output
        unset($entry['linked_nodes'], $entry['linked_echolink']);

        apply_coords($entry, $nid_str, $coords);

        // Only include public nodes (>= 2000) in the output
        if ($node_num >= 2000) {
            $result_nodes[$nid_str] = $entry;
        }
    }

    @fwrite($sock, "ACTION: Logoff\r\n\r\n");
    @fclose($sock);
}

// ── Add metadata-only entries for all discovered remote nodes ─────────────────

$truncated = false;

foreach (array_keys($all_network_nodes) as $nid) {
    $nid_str = (string) $nid;

    if (isset($result_nodes[$nid_str])) {
        continue; // already present as a locally-queried node
    }
    if (count($result_nodes) >= MAX_NODES) {
        $truncated = true;
        break;
    }

    $d     = node_lookup($nid_str, $nodedb);
    $entry = [
        'node'     => $nid,
        'callsign' => $d['callsign'],
        'desc'     => $d['desc'],
        'local'    => false,
        'type'     => 'asl',
        'lat'      => null,
        'lon'      => null,
    ];
    apply_coords($entry, $nid_str, $coords);
    $result_nodes[$nid_str] = $entry;
}

// ── Add EchoLink nodes discovered through LinkedNodes ─────────────────────────

foreach (array_keys($all_echolink_nodes) as $el_callsign) {
    if (isset($result_nodes[$el_callsign])) {
        continue;  // already present as an ASL node whose callsign matches
    }
    if (count($result_nodes) >= MAX_NODES) {
        $truncated = true;
        break;
    }

    $entry = [
        'node'     => $el_callsign,  // no numeric ASL ID; callsign used as node identifier
        'callsign' => $el_callsign,
        'desc'     => $el_callsign,  // QRZ lookup or label= in nodelist can override
        'local'    => false,
        'type'     => 'echolink',
        'lat'      => null,
        'lon'      => null,
    ];
    apply_coords($entry, $el_callsign, $coords);
    $result_nodes[$el_callsign] = $entry;
}

// ── QRZ coordinate enrichment ─────────────────────────────────────────────────
// Only runs on normal JSON requests; the ?template endpoint is a manual-edit
// aid and does not trigger network lookups (new coords appear there automatically
// on the next run, since they have already been written to COORDS_INI).
if (!isset($_GET['template'])) {
    $qrz_errors = qrz_enrich_and_persist($result_nodes, $qrz_user, $qrz_pass);
    $errors     = array_merge($errors, $qrz_errors);
}

// ── Emit output ──────────────────────────────────────────────────────────────

if (isset($_GET['template'])) {
    output_coords_template($result_nodes, $coords);
} elseif (isset($_GET['format']) && strtolower(trim($_GET['format'])) === 'kml') {
    output_kml($result_nodes, $truncated);
} elseif (isset($_GET['format']) && strtolower(trim($_GET['format'])) === 'geojson') {
    output_geojson($result_nodes, $truncated, $errors);
} else {
    $output = [
        'nodes'     => $result_nodes,
        'total'     => count($result_nodes),
        'truncated' => $truncated,
    ];
    if (!empty($errors)) {
        $output['errors'] = $errors;
    }
    if ($truncated) {
        $output['note'] = sprintf(
            'Result capped at MAX_NODES (%d). Increase MAX_NODES in netmap.php to see more.',
            MAX_NODES
        );
    }

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (isset($_GET['pretty'])) {
        $flags |= JSON_PRETTY_PRINT;
    }
    echo json_encode($output, $flags);
}
