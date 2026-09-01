#!/bin/bash
set -e
PLUGINDIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGINDIR"
FPPDIR="${FPPDIR:-/opt/fpp}"
echo "Building pixelselect plugin (FPPDIR=$FPPDIR)..."
make clean FPPDIR="$FPPDIR" || true
make FPPDIR="$FPPDIR"
echo "pixelselect build complete. Restart fppd to load it (GET /api/system/fppd/restart)."
