# PixelSelect — pick the show with two GPIO pins

An FPP plugin for a display that people walk up to. A **toggle switch** hands
playback to the plugin, and a **pushbutton** steps through an ordered list of
your own designs. Whatever is selected keeps looping until the button is pressed
again or the switch is flipped off.

Both pins are chosen in the plugin's own UI — no editing of FPP's GPIO Inputs
page, no FPP Commands to wire up. The settings page shows both inputs live and
can act as a virtual pushbutton, so the whole thing can be set up and tested
before a single wire is soldered.

Works on **FPP 5.4 through 9.x**, on BeagleBone and Raspberry Pi, from one source
tree.

![Content Setup → Plugins → PixelSelect](docs/screenshot.png)

## What a "design" is

Either of the two things you already have on the device:

| Type | What it is | How it loops |
|---|---|---|
| **Sequence** | an `.fseq` in `media/sequences`, straight out of xLights | FPP builds a one-item playlist for it and repeats that — it also picks up the audio file named inside the fseq header |
| **Playlist** | an FPP playlist, built however you like | repeats the whole playlist |

You can mix both in one list, rename each entry to something the audience would
recognise, drag them into the order the button should walk, and switch individual
entries off without deleting them.

## Install

In the FPP UI: **Content Setup → Plugins**, and in *"Find a Plugin or Enter a
plugininfo.json URL"* paste the raw URL:

```
https://raw.githubusercontent.com/joeskolengaden/pixelselect/main/pluginInfo.json
```

Use the **raw** URL, not the `github.com/.../blob/...` page — FPP 5.x fetches it
directly as JSON and only 9.x rewrites blob URLs.

Click **Install**. FPP clones the repo, runs `scripts/fpp_install.sh` to compile
`libpixelselect.so` on the device, and then asks to restart. **Restart FPP** —
until fppd has reloaded, the settings page will say the plugin is *not running*
and the pins do nothing.

`allowUpdates` is on, so later commits show up as an **Update** button.

## Wiring

The defaults assume the simplest possible wiring: each switch sits between its
pin and ground, with FPP's internal pull-up holding the pin high when open.

```
   GPIO pin ──────o/ o────── GND      toggle switch  (enable)
   GPIO pin ──────o⌐o─────── GND      pushbutton     (next)
```

If you wire to 3.3 V instead, set *"closes to"* to **3.3 V (active high)** and the
resistor to **Internal pull-down** for that pin.

Beagle pin names look like `P8-11` / `P9-15`, Raspberry Pi like `P1-11`. The
pickers only offer pins the board actually has — the list comes from FPP itself.

**Do not use a pin that is also listed on Input/Output Setup → GPIO Inputs.**
FPP claims those pins for its own command triggers and the two will fight.

## Settings

**Inputs**

| Setting | Default | Notes |
|---|---|---|
| Enable pin | — | The toggle switch. Leave blank to run without one. |
| Next pin | — | The momentary pushbutton. |
| Closes to | Ground | Ground for a switch wired to GND, 3.3 V for the other polarity. |
| Resistor | Internal pull-up | Match it to the wiring; use *None/External* if you fitted your own. |
| Debounce | 30 ms | Raise it if one press advances two designs. |
| Software override | off | Act as if the switch were on — for testing before it is wired. |

**Behaviour**

| Setting | Default | Notes |
|---|---|---|
| Loop the selected design | on | Repeat until something changes it. |
| Wrap around | on | Off makes the last design the end of the line. |
| Remember the last design | on | Survives a reboot. A design you have since switched off is skipped. |
| Keep it playing | on | If playback stops on its own, start the selected design again. |
| When the switch goes off | Stop immediately | Or finish the current item / current loop. |
| Long press does | nothing | Set it and a short press acts on release, so the two can be told apart. |

## How it works

`libpixelselect.so` is a compiled FPP plugin. A worker thread samples both pins
every 5 ms through FPP's own `PinCapabilities`, which is why pin names here mean
the same thing as on FPP's GPIO Inputs page on both BeagleBone and Pi. Playback
is driven by POSTing `Start Playlist` / `Stop Now` to fppd's command endpoint on
`127.0.0.1:32322` — going through the HTTP API rather than linking against
`Player`/`CommandManager` is what keeps one source tree building on 5.4 and 9.x,
whose plugin and web-server headers differ substantially.

Files it owns:

| Path | What |
|---|---|
| `config/plugin.pixelselect` | settings, written by the UI |
| `config/pixelselect_designs.tsv` | the ordered design list |
| `config/pixelselect_state.txt` | which design was selected last |
| `/dev/shm/pixelselect_status.json` | live status for the UI |
| `/dev/shm/pixelselect_pins.json` | the board's pin names, for the UI pickers |
| `/dev/shm/pixelselect_cmd` | one-shot commands from the UI's virtual button |

The design list is a TSV rather than JSON so the plugin needs no jsoncpp linkage
of its own, which keeps it compiling on a device that only has FPP's headers.

## Tests

The behaviour that is annoying to debug on a Beagle — debounce, ordering, the
enable/next state machine — runs on a laptop:

```bash
FPPSRC=/path/to/fpp-checkout ./tests/run.sh
```

It compiles the real plugin, `dlopen`s it into a host that stubs the five FPP
symbols the plugin uses, fakes the two GPIO pins, and answers fppd's command
endpoint so every `Start Playlist` can be asserted on. On a device, `FPPSRC=/opt/fpp`
works too.

## Troubleshooting

| Symptom | Cause |
|---|---|
| Badge says **not running** | fppd has not been restarted since the install, or the build failed — check `scripts/fpp_install.sh` output in the plugin install log. |
| Lamps never change | Wrong pin, wrong polarity, or the pin is also claimed on FPP's GPIO Inputs page. |
| One press advances two designs | Raise **Debounce**. |
| A design is badged **missing** | The sequence or playlist is no longer on the device — re-upload it or remove the entry. |
| Nothing plays but the lamps look right | Check the design list is not empty and that at least one entry is switched on. |

## Licence

GPL-2.0-or-later, matching FPP.
