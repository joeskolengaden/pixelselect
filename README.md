# PixelSelect — pick the show with GPIO switches

An FPP plugin for a display that people walk up to.

A **toggle switch** hands playback to the plugin and chooses a set of designs. A
**pushbutton** steps through that set. Whatever is selected keeps looping until
the button is pressed again or the switch is flipped off — and while the switch
is on, the plugin holds the player against anything else that tries to take it.

Add as many toggle switches as you have free pins. Each one owns its own set of
designs and remembers which one it was showing, so a panel can offer "Christmas",
"Halloween" and "Quiet" as three separate switches with one shared button.

Every pin is chosen in the plugin's own UI. There is no FPP GPIO Inputs entry to
create and no FPP Command to wire up. The settings page shows each input live and
doubles as a virtual pushbutton, so the whole thing can be set up and tested
before a single wire is soldered.

Runs on **FPP 5.4 through 9.x**, BeagleBone and Raspberry Pi, from one source tree.
Verified end to end on a BeagleBone Green running FPP 5.4.1.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/screenshot-dark.png">
  <img alt="The PixelSelect settings page under Content Setup → Plugins" src="docs/screenshot.png">
</picture>

![How it works: switch and button into the plugin, the plugin into FPP's player, FPP into your pixels](docs/flow.png)

---

## Contents

- [How it behaves](#how-it-behaves)
- [Several switches, one set each](#several-switches-one-set-each)
- [What a design is](#what-a-design-is)
- [Built-in solid colours](#built-in-solid-colours)
- [WLED-style patterns](#wled-style-patterns)
- [Install](#install)
- [Wiring](#wiring)
- [Settings](#settings)
- [Priority: the switch wins](#priority-the-switch-wins)
- [Giving the schedule back](#giving-the-schedule-back)
- [Trying it without hardware](#trying-it-without-hardware)
- [How it works](#how-it-works)
- [Tests](#tests)
- [Troubleshooting](#troubleshooting)

---

## How it behaves

| Event | What happens |
|---|---|
| A switch closes | That switch's selected design starts and loops. Whatever was playing is interrupted. |
| Button press | Jump to the next enabled design **in that switch's set** and start it. |
| Button press on the last one | Wrap to the first (or hold, if wrap is off). |
| A second switch closes | Hand over to its set. The most recently closed switch wins. |
| That switch opens again | Fall back to whichever switch is still closed. |
| Something else grabs the player | The plugin takes it back within a few seconds. |
| Playback stops on its own | The selected design is started again. |
| Every switch open | Playback stops, and FPP's schedule is handed back. |
| Power cycle | Each switch remembers the design it was last showing. |

The header of the settings page mirrors all of this live — the current design, and
a lamp per pin that follows the real pin level:

![Live status: current design, switch and button lamps, and manual controls](docs/ui-status.png)

## Several switches, one set each

Each switch is one row: a name, how many designs it owns, and its pin. The
pushbutton is the last row of the same list, because it is just another wired
input. The lamp on the left of each row follows the real pin level, so you can
confirm the wiring before adding a single design.

Polarity and the pull resistor are set once for everything, on the line below the
list — in practice every switch on a panel is wired the same way, and repeating
two dropdowns per row only made the page harder to read.

![The Switches and button card: named switches with live lamps, the shared pushbutton, and one wiring line](docs/ui-switches.png)

![Three switches, each owning its own list of designs; the button walks only the closed one](docs/sets.png)

The rules when more than one is closed:

- **The most recently closed switch wins.** Flipping a new one hands over
  immediately.
- **Opening it falls back** to whichever switch is still closed, rather than
  going dark.
- **With every switch open** the plugin stops and gives the schedule back.

That is also exactly what a **rotary selector switch** needs — wire one pin per
position and it behaves as you would expect, because only one position is ever
closed.

Each switch keeps its own place in its own list, so flipping away and back
returns to the design that switch was showing.

## What a design is

Either of the two things already on your device:

| Type | What it is | How it loops |
|---|---|---|
| **Sequence** | an `.fseq` in `media/sequences`, straight out of xLights | FPP builds a one-item playlist for it and repeats that. It also picks up the audio file named inside the fseq header, so a musical sequence just works |
| **Playlist** | an FPP playlist, built however you like | the whole playlist repeats |

Every design belongs to one switch. With more than one switch the design list
grows a tab per switch; the **Add design** picker adds into the tab you are
looking at, and the dropdown on each row moves a design to a different switch.
One picker lists your sequences and playlists together — there is no file type to
choose first.

Mix both types in one set, rename each entry to something an audience would
recognise, drag them into the order the button should walk, and switch entries
off without deleting them. An entry whose sequence or playlist is no longer on the device is
badged **missing** rather than silently doing nothing when the button reaches it.

![The design list, with a tab per switch](docs/ui-designs.png)

## Built-in solid colours

A customer who just wants "make it all red" should not have to open xLights. The
Designs card has a **+ Solid colours** button that writes 24 ready-made
sequences into `media/sequences`, one file per colour, which then appear in the
picker like anything else:

| | | | |
|---|---|---|---|
| Red `255,0,0` | Orange `255,70,0` | Amber `255,130,0` | Yellow `255,220,0` |
| Green `0,255,0` | Teal `0,200,120` | Cyan `0,255,255` | Blue `0,0,255` |
| Purple `130,0,255` | Magenta `255,0,200` | Warm White `255,150,70` | Cool White `255,255,255` |
| Pink `255,60,140` | Rose `255,20,70` | Coral `255,90,80` | Lime `140,255,0` |
| Mint `0,255,160` | Sky Blue `0,140,255` | Indigo `60,0,255` | Lavender `170,120,255` |
| Ice White `200,230,255` | Cream `255,220,160` | Deep Red `140,0,0` | Forest `0,120,40` |

Each is a 10 second loop and about **21 KB** — roughly 510 KB for the full set.

The design list shows a **colour swatch** against every one of them, an animated
gradient chip against each pattern, and a pulsing **playing** badge on whichever
design is on air, so it is obvious at a glance what is selected.

They are that small because a solid colour is the same bytes in every frame,
which zlib crushes to almost nothing, and FPP reads zlib-compressed FSEQ v2
natively. That is also what makes them **independent of your channel count**:
rather than guessing how many channels a given show has — FPP has no single API
that reports it, and guessing low would leave pixels dark — each file simply
covers 524,286 channels (174,762 RGB pixels). Channels past the end of a show are
ignored by FPP; channels short of it are not, so erring large is the safe
direction, and compression means the extra costs nothing. One frame per
compression block keeps the decode buffer to a single frame, so a BeagleBone
never sees more than half a megabyte at a time.

Colour order is applied by FPP's output driver, so the files hold canonical RGB
and your per-string `colorOrder` still applies. Two caveats worth knowing:

- They assume **RGB pixels (3 channels per node)**. RGBW strings are not handled.
- **Cool White is every channel at full**, which draws roughly three times the
  current of a single-colour fill. Check your power budget before running it
  across a large display.

The same button removes them again (and drops any designs that pointed at one).

## WLED-style patterns

**+ WLED patterns** writes ten animated sequences modelled on WLED's familiar
effects: Rainbow, Rainbow Cycle, Breathe, Colour Wipe, Theater Chase, Running
Lights, Comet, Larson Scanner, Twinkle and Fire Flicker. Ten seconds each at
20 fps.

Unlike a solid colour, **a pattern has to know where your pixels are** — "chase
down the string" is meaningless without a layout. So these are rendered against
your actual output configuration: every virtual string on every port, including
the `virtualStringsB/C/D` groups a port can carry, with the channels-per-node
rule FPP itself uses (4 when `colorOrder` is four characters, otherwise 3, so
RGBW strings get a zero white channel). **Regenerate them if you change your
outputs** — the same button does it.

They are built with whole-string operations rather than a per-pixel loop
(rotations via `substr`, fills via `str_repeat`, sparkles via `substr_replace`),
which is what keeps rendering all ten down to about a second on a BeagleBone
instead of minutes.

## Colour accuracy

FPP corrects gamma at the output with a per-string lookup table,
`f = maxB * pow(f/255, gamma)` — but only when that string's gamma is set, and
it defaults to 1.0, meaning your bytes go out untouched. Raw 8-bit values sent
that way look wrong: mid-tones read far brighter than the colour you asked for.

So the generators encode gamma (2.2) into the files, but **only when every
configured string is at gamma 1.0**. If you have set gamma on your strings, the
files are left linear and FPP keeps doing the correction — applying both would
compound and everything would come out too dark. Anything that cannot be read
confidently is treated as "leave it to FPP".

The palette values in this README are the colours you asked for; what is stored
in the file is the perceptually encoded version of them.

## Install

In the FPP UI, go to **Content Setup → Plugins**, and in *"Find a Plugin or Enter a
plugininfo.json URL"* paste this **raw** URL:

```
https://raw.githubusercontent.com/joeskolengaden/pixelselect/main/pluginInfo.json
```

Use the raw URL, not the `github.com/.../blob/...` page — FPP 5.x fetches it
directly as JSON, and only 9.x rewrites blob URLs.

Then click **Install**. FPP clones the repo, runs `scripts/fpp_install.sh` to
compile `libpixelselect.so` on the device, and asks to restart.

> **Restart FPP.** Until fppd has reloaded, the badge at the top of the settings
> page reads **not running**, the pins do nothing, and the page cannot show live
> pin state. This is the single most common "it doesn't work" cause.

`allowUpdates` is on, so later commits show an **Update** button that pulls and
rebuilds. Restart fppd after an update too — the running `.so` is only swapped on
restart.

## Wiring

The defaults assume the simplest possible wiring: each switch sits between its pin
and ground, with FPP's internal pull-up holding the pin high while it is open.

![Wiring: two toggle switches and a shared pushbutton, each between a GPIO pin and ground](docs/wiring.png)

If you wire to 3.3 V instead, change the line under the switch list to
*"Everything is wired **to 3.3 V** with **the internal pull-down**"*. It applies to
every switch and the button at once, because a panel is invariably wired one way
throughout. If you fitted your own resistors, choose *no internal resistor*.

**Choosing pins.** The pickers only offer pins the board actually has — the list
comes from FPP itself, so BeagleBone names look like `P8-11` / `P9-15` and
Raspberry Pi like `P1-11`. Three things to avoid:

- **The pushbutton pin, or another switch's pin.** The plugin refuses to save a
  duplicate, but it is worth knowing before you solder.
- **Any pin listed on Input/Output Setup → GPIO Inputs.** FPP claims those for its
  own command triggers and the two will fight. On a cape with an OLED, the
  navigation buttons usually live there (`P9-17`, `P9-18`, `P9-21`, `P9-22`, `P9-26`).
- **Any pin your cape uses for outputs.** On a 48-string BeagleBone cape that is
  most of the P8 header, which is why the P9 side is usually the free one.

## Settings

### Switches &amp; button

| Setting | Default | Notes |
|---|---|---|
| Name | — | Shown on the design tabs and on the status header. |
| Pin | — | Required to add a switch — one without a pin can never fire, and is badged **no pin** if a config ever contains one. The very first switch may sit pinless, which is the state a fresh install starts in: it never becomes active, so an unconfigured plugin cannot fight a schedule. |
| Everything is wired… | to ground, internal pull-up | Applies to every switch and the button at once. Set it to 3.3 V + pull-down for the other polarity, or "no internal resistor" if you fitted your own. |
| Test without switches | off | Behave as if a switch were closed, and choose which one. For testing before anything is wired. |

### Options

Folded away by default, because the defaults are the right answer for almost
everyone. The summary line on the header says what they currently add up to.

![The Options section](docs/ui-options.png)

| Setting | Default | Notes |
|---|---|---|
| Loop the selected design | on | Repeat until something changes it. |
| Wrap around | on | Off makes the last design the end of the line — pressing next there holds it rather than restarting it. |
| Remember the last design | on | Survives a reboot. A design you have since switched off is skipped rather than resumed. |
| Keep it playing | on | If playback stops on its own, start the selected design again. |
| Back to the schedule on release | on | When the switch opens, nudge FPP's scheduler so a schedule still inside its window resumes. See below. |
| Override everything else | on | While the switch is on, take the player back from a schedule, a remote, or the FPP UI. See below. |
| When every switch is open | Stop immediately | Or finish the current item / the current loop. |
| Debounce | 30 ms | How long an input must hold steady before it counts. Raise it if one press advances two designs. |
| Long press does | nothing | Set it and a short press acts on release instead, so the two can be told apart. |

## Priority: the switch wins

While any switch is closed, the plugin owns the player. Closing the switch
interrupts whatever was running, and if anything else grabs the player afterwards
— a scheduled playlist starting, a remote, someone pressing play on the FPP status
page — the plugin takes it straight back.

![Timeline: schedule plays, switch closes and the plugin takes over, the scheduler interferes and is reclaimed, switch opens and the schedule resumes](docs/priority.png)

It checks twice a second but only acts after the player has been somewhere else
for two seconds, with a three-second grace period after each of its own starts.
That is deliberately unhurried: the gap between playlist repeats never counts as
losing the player, and it cannot thrash against FPP's scheduler. On a live device
with an active schedule it reclaims once and then holds indefinitely.

Knowing whether the player is still "ours" is done differently per type. A
sequence design is checked with `Sequence::IsSequenceRunning(name)`. A playlist
design is checked by comparing the running playlist name from `/fppd/status`,
because a playlist legitimately changes sequence as it walks through its items.

Turn **Override everything else** off if you would rather a schedule win once it
starts.

## Giving the schedule back

Stopping is not enough on its own, and this is the part that is easy to get wrong.

Once any other playlist has owned the player, **FPP marks the day's scheduled
occurrence as already run and will not restart it mid-window** — not when the
player goes idle, and not on a schedule reload either. Left alone, a device would
simply sit dark after the switch was released and stay that way until the
schedule's next day.

So when the switch opens, the plugin waits for the player to actually go idle (a
graceful stop still gets to finish its item) and then asks the scheduler to look
again, with the "ignore repeat" flag so an already-run in-window item is re-armed.
FPP then starts it through its own scheduled path, which matters: the playlist
keeps its scheduled end time and stop type. Starting the playlist directly instead
would lose both, and the show would run past the time it was supposed to stop.

If nothing is scheduled for right now, the device correctly stays idle.

## Trying it without hardware

Every input has a software trigger, so the whole thing can be driven from the
page before a single wire is soldered.

1. Add your designs and pick your pins.
2. Hit **Test** on any switch row. The plugin behaves exactly as though that
   switch had been closed — same code path, not a simulation — and the button
   reads **Testing** while it is held.
3. Hit **Press** on the pushbutton row to advance within that switch's designs.
   **Next design ›** and **Stop** at the top of the page, and the ▶ on any
   design row, do the same job.
4. Hit **Testing** again to release. Now wire the real switches and watch the
   lamp on each row follow the pin.

The triggers drive the same `virtual_enable` / `virtual_set` settings the plugin
already honours, so what you test is what the hardware will do.

## How it works

`libpixelselect.so` is a compiled FPP plugin. A worker thread samples every switch
and the button every 5 ms through FPP's own `PinCapabilities`, which is why pin names here mean
the same thing as on FPP's GPIO Inputs page, on both BeagleBone and Pi.

Playback is driven by POSTing `Start Playlist` / `Stop Now` to fppd's command
endpoint on `127.0.0.1:32322` rather than by linking against `Player` or
`CommandManager`. Those headers pull in libhttpserver on 5.4 and drogon on 9.x,
neither of which is reliably present on a device that is only compiling a plugin;
the HTTP command API has been stable across every version in range.

That keeps the plugin's whole dependency on FPP down to seven functions and two
globals, every one of them from a header with no third-party includes and with an
identical signature on 5.4 and 9.x:

```
PinCapabilities::getPinByName / getPinNames      pins, by FPP's own names
Sequence::IsSequenceRunning() / (name)           is our design still on air
Scheduler::CheckIfShouldBePlayingNow(int, int)   hand the schedule back
FPPPlugin::FPPPlugin / reloadSettings            the plugin base itself
sequence, scheduler                              the two globals those need
```

Files the plugin owns:

| Path | What |
|---|---|
| `config/plugin.pixelselect` | settings, written by the UI |
| `config/pixelselect_sets.tsv` | the switches, in order: name, pin, polarity, pull |
| `config/pixelselect_designs.tsv` | the ordered design list, each row naming its switch |
| `config/pixelselect_state.txt` | the design each switch was last showing |
| `/dev/shm/pixelselect_status.json` | live status for the UI |
| `/dev/shm/pixelselect_pins.json` | the board's pin names, for the UI pickers |
| `/dev/shm/pixelselect_cmd` | one-shot commands from the UI's virtual button |

The design list is a TSV rather than JSON so the plugin needs no jsoncpp linkage of
its own, which keeps it compiling on a device that only has FPP's headers. The
media directory is found with `dladdr` on the loaded `.so` rather than an FPP
macro, because that macro changed shape between 5.4 and 9.x.

## Tests

The behaviour that is annoying to debug on a Beagle — debounce, ordering, the
switch/next state machine, handover between switches, takeover and hand-back —
runs on a laptop:

```bash
FPPSRC=/path/to/fpp-checkout ./tests/run.sh
```

It compiles the real plugin, `dlopen`s it into a host that stubs those few FPP
symbols, fakes the GPIO pins, models what is on air and what the schedule wants,
and answers fppd's command endpoint so every `Start Playlist` can be asserted on.
47 checks, including a bouncing press advancing exactly one design, an enabled
plugin with no pin staying out of playback, reclaiming the player from something
else, handing the schedule back, and a second switch taking over without ever
walking into the first switch's designs. On a device, `FPPSRC=/opt/fpp` works too.

## Troubleshooting

| Symptom | Cause |
|---|---|
| Badge says **not running** | fppd has not been restarted since the install or update, or the build failed — check the install log for `scripts/fpp_install.sh` output. |
| Lamps never change | Wrong pin, wrong polarity, or the pin is also claimed on FPP's GPIO Inputs page or by your cape. |
| The button walks the wrong designs | It always walks the set of the switch that is currently closed. Check the tab and the switch lamps. |
| One press advances two designs | Raise **Debounce**. |
| A switch is badged **no pin** | It has no pin assigned, so it can never fire. Pick one from its dropdown. |
| An entry is badged **missing** | Its sequence or playlist is no longer on the device — re-upload it or remove the entry. |
| Nothing plays, but the lamps look right | The design list is empty, or every entry is switched off. |
| The schedule does not resume after release | Check **Back to the schedule on release** is on, and that a schedule is actually in its time window right now. |
| The plugin keeps interrupting a schedule | That is **Override everything else** doing its job while the switch is closed. Open the switch, or turn the setting off. |

## Licence

GPL-2.0-or-later, matching FPP.
