#!/bin/bash
#
# Off-device test for the pixelselect plugin.
#
# Builds libpixelselect against a checkout of the FPP source, then dlopen()s it
# into a host that stubs the five FPP symbols the plugin uses, fakes the two GPIO
# pins, and answers fppd's command endpoint on 127.0.0.1:32322. That means the
# debounce, ordering and state-machine behaviour can be exercised on a laptop
# instead of on the Beagle.
#
#   FPPSRC=/path/to/fpp-checkout ./tests/run.sh
#
# On a real device you can point it at /opt/fpp instead:
#   FPPSRC=/opt/fpp ./tests/run.sh
set -e

HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$(dirname "$HERE")"
FPPSRC="${FPPSRC:-/opt/fpp}"
SRC="$FPPSRC/src"
[ -f "$SRC/Plugin.h" ] || { echo "No FPP source at $SRC - set FPPSRC=/path/to/fpp"; exit 2; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/media/config" "$WORK/media/plugins/pixelselect"

# Some FPP versions include <jsoncpp/json/json.h>, some ship the headers as
# <json/json.h>. Bridge whichever this machine has.
INC=""
for d in /usr/include /usr/local/include /opt/homebrew/include; do
    if [ -d "$d/json" ] && [ ! -d "$d/jsoncpp" ]; then
        mkdir -p "$WORK/shim/jsoncpp"
        ln -sfn "$d/json" "$WORK/shim/jsoncpp/json"
        INC="-I$WORK/shim"
        break
    fi
done

case "$(uname -s)" in
    Darwin) EXT=.dylib; JSONLIB="-L/opt/homebrew/lib -ljsoncpp";;
    *)      EXT=.so;    JSONLIB="-ljsoncpp";;
esac

echo "Building the plugin..."
make -C "$PLUGIN" clean >/dev/null 2>&1 || true
make -C "$PLUGIN" FPPDIR="$FPPSRC" SRCDIR="$SRC" \
     CXXFLAGS="-std=gnu++2a -fPIC -O2 -Wall -fvisibility=default $INC -I$SRC" >/dev/null
cp "$PLUGIN/libpixelselect$EXT" "$WORK/media/plugins/pixelselect/"

echo "Building the test host..."
c++ -std=gnu++2a -O1 -g $INC -I"$SRC" -o "$WORK/testhost" "$HERE/testhost.cpp" $JSONLIB

"$WORK/testhost" "$WORK/media" "$WORK/media/plugins/pixelselect/libpixelselect$EXT"
