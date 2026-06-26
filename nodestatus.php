<?php
/**
 * netmap.php – AllStarLink Network Node Discovery
 *
 * HOW IT WORKS
 *   1. Reads /etc/allmon3/ami.ini for one or more Asterisk AMI servers, each
 *      with host, port, user, pass, and a comma-separated list of node numbers.
 *   2. Connects to each AMI server in turn and issues RptStatus XStat for
 *      every node listed under that server.
 *   3. Parses the LinkedNodes: field, which contains the FULL transitive set of
 *      every node reachable through the network – not just direct connections.
 *   4. Results from all servers are merged into a single flat JSON object.
 *   5. Deduplicates, excludes private nodes (< 2000), and enriches each with
 *      metadata from /var/lib/asterisk/astdb.txt and optional lat/lon from
 *      /etc/allmon3/node-coords.ini.
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
 *   http://<pi>/allmon3/netmap.php?template=1   ← node-coords.ini template
 */

// ── Configuration ──────────────────────────────────────────────────────────────

define('AMI_INI',      '/etc/allmon3/ami.ini');          // AMI servers + node lists
define('ASTDB_PATH',   '/var/lib/asterisk/astdb.txt');   // node metadata flat file
define('COORDS_INI',   '/etc/allmon3/node-coords.ini');  // per-node lat/lon overrides
define('AMI_TIMEOUT',  10);    // seconds to wait for each AMI response
define('FETCH_NODEDB', false); // true → fall back to allmondb.allstarlink.org if astdb.txt is empty
define('MAX_NODES',    1000);  // safety cap on total nodes returned

// ── HTTP headers ───────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

// ── Config parsers ─────────────────────────────────────────────────────────────

/**
 * Parse /etc/allmon3/ami.ini and return an array of AMI server configs.
 *
 * Each INI section defines one Asterisk AMI server.  Example:
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
 * Load per-node coordinate overrides from node-coords.ini.
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
 * this node.  Prefix T/R/C/U is stripped; callsign-based entries (EchoLink,
 * phone portals) and private nodes (< 2000) are filtered out.
 *
 * Returns an array with keys: node, txkeyed, txekeyed, rxkeyed, numlinks,
 *   uptime, reloadtime, linked_nodes (internal).
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
        'linked_nodes' => [],  // internal – removed before output
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
 * Output a node-coords.ini template pre-populated with every discovered node.
 * Nodes that already have coordinates in the current COORDS_INI are pre-filled.
 * Local (directly-queried) nodes are listed first, remote (LinkedNodes) second.
 * Triggered by the ?template query parameter.
 */
function output_coords_template(array $result_nodes, array $coords): void
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: inline; filename="node-coords.ini"');

    $date = gmdate('Y-m-d H:i:s') . ' UTC';
    echo "; node-coords.ini – generated by netmap.php on $date\n";
    echo "; Copy to /etc/allmon3/node-coords.ini and fill in the lat/lon values.\n";
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

    $sort_fn = static function (array $a, array $b) use ($coords): int {
        $nid_a = (string) $a['node'];
        $nid_b = (string) $b['node'];
        $has_a = isset($coords[$nid_a]['lat']) && $coords[$nid_a]['lat'] !== '';
        $has_b = isset($coords[$nid_b]['lat']) && $coords[$nid_b]['lat'] !== '';
        if ($has_a !== $has_b) {
            return $has_a ? 1 : -1;  // no-coords first
        }
        return $a['node'] <=> $b['node'];  // then numeric ascending
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

// ── Main ───────────────────────────────────────────────────────────────────────

$servers = parse_ami_ini(AMI_INI);
$nodedb  = load_local_nodedb(ASTDB_PATH);
$coords  = load_coords(COORDS_INI);

if (empty($nodedb) && FETCH_NODEDB) {
    $nodedb = fetch_remote_nodedb();
}

if (empty($servers)) {
    http_response_code(500);
    echo json_encode(['error' => 'No AMI servers configured in ' . AMI_INI]);
    exit;
}

// ── Query each AMI server ─────────────────────────────────────────────────────

$result_nodes      = [];  // nid_string => node entry  (merged across all servers)
$all_network_nodes = [];  // nid_int    => true         (union of every LinkedNodes list)
$errors            = [];  // non-fatal per-server errors reported in the response

foreach ($servers as $server_name => $server) {

    if (empty($server['user']) || empty($server['pass'])) {
        $errors[] = "[$server_name] missing user or pass in " . AMI_INI;
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

        // Accumulate the full network list from this node's LinkedNodes
        foreach (array_keys($entry['linked_nodes']) as $linked_nid) {
            $all_network_nodes[$linked_nid] = true;
        }

        // linked_nodes is internal – do not expose in output
        unset($entry['linked_nodes']);

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
        'lat'      => null,
        'lon'      => null,
    ];
    apply_coords($entry, $nid_str, $coords);
    $result_nodes[$nid_str] = $entry;
}

// ── Emit output ──────────────────────────────────────────────────────────────

if (isset($_GET['template'])) {
    output_coords_template($result_nodes, $coords);
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
