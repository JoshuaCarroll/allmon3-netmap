#!/usr/bin/env bash
# install.sh – Install allmon3-netmap into an existing Allmon3 deployment.
#
# What this does:
#   1. Creates a symlink  ALLMON_WEB/netmap.php  → this repo's netmap.php
#      (symlink approach means Allmon3 package upgrades leave the file untouched,
#       and re-running this script silently recreates the link if it was removed)
#   2. Copies netmap-settings.ini and netmap-nodelist.ini to CONF_DIR only when the target file
#      does not already exist (never overwrites user edits)
#
# Usage:
#   sudo bash install.sh
#
# To target a different web root (e.g. a future Allmon4 path), change ALLMON_WEB.

set -euo pipefail

# ── Configuration ────────────────────────────────────────────────────────────
ALLMON_WEB=/usr/share/allmon3
CONF_DIR=/etc/allmon3
# ─────────────────────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_PHP="${SCRIPT_DIR}/netmap.php"
LINK_TARGET="${ALLMON_WEB}/netmap.php"

echo "==> allmon3-netmap installer"
echo "    Repo:     ${SCRIPT_DIR}"
echo "    Web root: ${ALLMON_WEB}"
echo "    Conf dir: ${CONF_DIR}"
echo

# ── Preflight checks ─────────────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    echo "ERROR: This script must be run as root (use sudo)." >&2
    exit 1
fi

if [[ ! -d "${ALLMON_WEB}" ]]; then
    echo "ERROR: Allmon3 web directory not found: ${ALLMON_WEB}" >&2
    echo "       Install Allmon3 first, or update ALLMON_WEB in this script." >&2
    exit 1
fi

if [[ ! -f "${REPO_PHP}" ]]; then
    echo "ERROR: netmap.php not found in repo: ${REPO_PHP}" >&2
    exit 1
fi

# ── Step 1: Symlink netmap.php ──────────────────────────────────────────────
echo "--> Installing symlink: ${LINK_TARGET} -> ${REPO_PHP}"
# Remove any existing file or stale symlink so ln -s succeeds cleanly.
if [[ -e "${LINK_TARGET}" || -L "${LINK_TARGET}" ]]; then
    rm -f "${LINK_TARGET}"
    echo "    (removed existing entry)"
fi
ln -s "${REPO_PHP}" "${LINK_TARGET}"
echo "    OK"

# ── Step 2: Copy config templates (copy-once; never overwrite) ───────────────
mkdir -p "${CONF_DIR}"

for conf_file in netmap-settings.ini netmap-nodelist.ini; do
    src="${SCRIPT_DIR}/${conf_file}"
    dst="${CONF_DIR}/${conf_file}"

    if [[ ! -f "${src}" ]]; then
        echo "WARN: Source template not found, skipping: ${src}" >&2
        continue
    fi

    if [[ -e "${dst}" ]]; then
        echo "--> ${dst} already exists — skipping (preserving your edits)"
    else
        echo "--> Copying ${conf_file} to ${dst}"
        cp "${src}" "${dst}"
        # netmap-settings.ini holds credentials: readable by web server, not writable (640).
        # netmap-nodelist.ini is written by the web server for QRZ auto-appending (660).
        if [[ "${conf_file}" == "netmap-nodelist.ini" ]]; then
            chmod 660 "${dst}"
        else
            chmod 640 "${dst}"
        fi
        chown root:www-data "${dst}" 2>/dev/null \
            || echo "    WARN: could not chown ${dst} to root:www-data (is www-data the web user?)"
        echo "    OK"
    fi
done

# ── Done ─────────────────────────────────────────────────────────────────────
echo
echo "Installation complete."
echo
echo "Next steps:"
echo "  1. Edit ${CONF_DIR}/netmap-settings.ini  — set your AMI credentials and (optionally)"
echo "     add a [qrz] section with your QRZ.com username and password."
echo "  2. Edit ${CONF_DIR}/netmap-nodelist.ini  — add lat/lon for any nodes you want"
echo "     to appear on the map (or let QRZ fill them in automatically)."
echo "  3. Verify the endpoint:  curl http://localhost/allmon3/netmap.php"
