#!/bin/bash
# Leaves the design list and settings in place so a reinstall picks up where it left off.
PLUGINDIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGINDIR" && make clean 2>/dev/null
rm -f /dev/shm/pixelselect_status.json /dev/shm/pixelselect_pins.json /dev/shm/pixelselect_cmd
exit 0
