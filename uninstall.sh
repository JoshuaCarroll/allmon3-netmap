#!/usr/bin/env bash
# uninstall.sh – Remove allmon3-netmap from an existing Allmon3 deployment.
#
# What this does:
#   1. Removes the symlink  ALLMON_WEB/netmap.php  (the only file written
#      into the system web directory by install.sh)
#   2. Warns about — but does NOT delete — the config files in CONF_DIR.
#      Those files may contain user data (credentials, coordinates) and must
#      be removed manually if desired.
#
# Usage:
#   sudo bash uninstall.sh

set -euo pipefail

# ── Configuration ────────────────────────────────────────────────────────────
ALLMON_WEB=/usr/share/allmon3
CONF_DIR=/etc/allmon3
# ─────────────────────────────────────────────────────────────────────────────

LINK_TARGET="${ALLMON_WEB}/netmap.php"

echo "==> allmon3-netmap uninstaller"
echo "    Web root: ${ALLMON_WEB}"
echo "    Conf dir: ${CONF_DIR}"
echo

# ── Preflight checks ─────────────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    echo "ERROR: This script must be run as root (use sudo)." >&2
    exit 1
fi

# ── Step 1: Remove the netmap.php symlink ───────────────────────────────────
if [[ -L "${LINK_TARGET}" ]]; then
    echo "--> Removing symlink: ${LINK_TARGET}"
    rm -f "${LINK_TARGET}"
    echo "    OK"
elif [[ -e "${LINK_TARGET}" ]]; then
    echo "WARN: ${LINK_TARGET} exists but is not a symlink — not removing." >&2
    echo "      Delete it manually if it was placed there by install.sh." >&2
else
    echo "--> ${LINK_TARGET} not found — nothing to remove."
fi

# ── Step 2: Warn about config files (do NOT delete) ──────────────────────────
echo
echo "NOTE: The following config files were NOT removed because they may"
echo "      contain your credentials and custom data:"
echo

for conf_file in ami.ini node-coords.ini; do
    target="${CONF_DIR}/${conf_file}"
    if [[ -e "${target}" ]]; then
        echo "      ${target}"
    fi
done

echo
echo "To delete them manually:"
echo "  sudo rm ${CONF_DIR}/ami.ini"
echo "  sudo rm ${CONF_DIR}/node-coords.ini"

# ── Done ─────────────────────────────────────────────────────────────────────
echo
echo "Uninstall complete."
