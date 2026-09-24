#!/usr/bin/env bash
set -euo pipefail

# Manual deploy from your own machine (uses your "hetzner" SSH alias)
REMOTE_HOST="hetzner"
REMOTE_PATH="/home/elgersmav/html/hps-calendar/"

echo "==> Checking event files..."
bad=0
for f in events/*.json; do
    if ! python3 -m json.tool "$f" >/dev/null 2>/tmp/hps-json-err; then
        echo "  ✗ $f: $(cat /tmp/hps-json-err)"
        bad=1
    fi
done
if [ "$bad" -ne 0 ]; then
    echo "Fix the file(s) above first. Nothing was uploaded."
    exit 1
fi
echo "  ✓ $(ls events/*.json | wc -l | tr -d ' ') event files OK"

echo "==> Uploading to $REMOTE_HOST..."
# --delete only applies inside events/: removing an event file locally removes that event online
rsync -avz --delete index.php style.css events "$REMOTE_HOST:$REMOTE_PATH"

echo "==> Done: https://hps-calendar.vjbe.net"