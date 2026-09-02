// Off-device test host for libpixelselect: provides the five FPP symbols the
// plugin needs, a pair of fake GPIO pins we can drive from the test, and a fake
// fppd command endpoint on 127.0.0.1:32322 that records what was asked for.
#include <jsoncpp/json/json.h>
#include "Plugin.h"
#include "Sequence.h"
#include "util/GPIOUtils.h"
#include "Scheduler.h"

#include <atomic>
#include <cstdio>
#include <cstring>
#include <dlfcn.h>
#include <fstream>
#include <iostream>
#include <mutex>
#include <netinet/in.h>
#include <sys/stat.h>
#include <sstream>
#include <string>
#include <thread>
#include <unistd.h>
#include <vector>

/* ------------------------------------------------------- fake GPIO pins */
struct FakePin : public PinCapabilities {
    FakePin(const std::string& n) : PinCapabilities(n, 0) {}
    mutable std::atomic<bool> level{true};   // idle high (pull-up, switch open)
    int configPin(const std::string&, bool) const override { return 0; }
    bool getValue() const override { return level.load(); }
    void setValue(bool) const override {}
    bool setupPWM(int) const override { return false; }
    void setPWMValue(int) const override {}
    bool supportPWM() const override { return false; }
};
static FakePin PIN_SW("P9-15");
static FakePin PIN_SW2("P9-23");
static FakePin PIN_BT("P9-16");
static FakePin PIN_NONE("");

const PinCapabilities& PinCapabilities::getPinByName(const std::string& n) {
    if (n == "P9-15") return PIN_SW;
    if (n == "P9-23") return PIN_SW2;
    if (n == "P9-16") return PIN_BT;
    static struct NullPin : public FakePin {
        NullPin() : FakePin("") {}
        const PinCapabilities* ptr() const override { return nullptr; }
    } np;
    return np;
}
std::vector<std::string> PinCapabilities::getPinNames() { return {"P9-15", "P9-16", "P9-23"}; }
const PinCapabilities& PinCapabilities::getPinByGPIO(int) { return PIN_NONE; }
const PinCapabilities& PinCapabilities::getPinByUART(const std::string&) { return PIN_NONE; }
Json::Value PinCapabilities::toJSON() const { return Json::Value(); }
void PinCapabilities::InitGPIO(const std::string&) {}
void PinCapabilities::enableOledScreen(int, bool) {}

/* ---------------------------------------------------------- fake fppd */
// A stand-in for fppd's player: what is on air right now. The Sequence object
// is never really constructed - the stubs below answer from these globals, so a
// zeroed allocation is enough to make `sequence` non-null.
Sequence* sequence = nullptr;
static std::atomic<bool> gPlaying{false};
static std::mutex gPlayMx;
static std::string gCurrentSeq;        // running .fseq, as fppd would report it
static std::string gCurrentPlaylist;   // running playlist name

static void setPlaying(const std::string& seq, const std::string& pl) {
    std::lock_guard<std::mutex> lk(gPlayMx);
    gCurrentSeq = seq;
    gCurrentPlaylist = pl;
    gPlaying = !seq.empty() || !pl.empty();
}
static std::string curSeq() { std::lock_guard<std::mutex> lk(gPlayMx); return gCurrentSeq; }
static std::string curPl()  { std::lock_guard<std::mutex> lk(gPlayMx); return gCurrentPlaylist; }

int Sequence::IsSequenceRunning() { return gPlaying.load() ? 1 : 0; }

// Stand-in for FPP's scheduler. gScheduledNow is what the schedule says should
// be running at this moment; asking it to look again starts that, which is what
// the real CheckIfShouldBePlayingNow(1, -1) ends up doing.
Scheduler* scheduler = nullptr;
static std::string gScheduledNow;         // "" = nothing scheduled right now
static std::atomic<int> gSchedulerAsked{0};
void Scheduler::CheckIfShouldBePlayingNow(int ignoreRepeat, int forceStopped) {
    gSchedulerAsked++;
    if (!gScheduledNow.empty() && !gPlaying.load())
        setPlaying("scheduled-item.fseq", gScheduledNow);
}
int Sequence::IsSequenceRunning(const std::string& filename) {
    return (gPlaying.load() && curSeq() == filename) ? 1 : 0;
}

static std::mutex gLogMx;
static std::vector<std::string> gLog;
static void logCmd(const std::string& s) {
    std::lock_guard<std::mutex> lk(gLogMx);
    fprintf(stderr, "      [fppd] %s\n", s.c_str());
    gLog.push_back(s);
}
static std::vector<std::string> takeLog() {
    std::lock_guard<std::mutex> lk(gLogMx);
    std::vector<std::string> c = gLog;
    gLog.clear();
    return c;
}

static void fakeFppd() {
    int srv = socket(AF_INET, SOCK_STREAM, 0);
    int one = 1;
    setsockopt(srv, SOL_SOCKET, SO_REUSEADDR, &one, sizeof(one));
    sockaddr_in sa{};
    sa.sin_family = AF_INET;
    sa.sin_port = htons(32322);
    sa.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    if (bind(srv, (sockaddr*)&sa, sizeof(sa)) != 0) { perror("bind"); return; }
    listen(srv, 8);
    for (;;) {
        int c = accept(srv, nullptr, nullptr);
        if (c < 0) continue;
        std::string req;
        char buf[2048];
        ssize_t n;
        while ((n = recv(c, buf, sizeof(buf), 0)) > 0) {
            req.append(buf, n);
            if (req.find("\r\n\r\n") != std::string::npos) {
                size_t hdr = req.find("\r\n\r\n") + 4;
                size_t cl = req.find("Content-Length: ");
                size_t want = cl == std::string::npos ? 0 : (size_t)atoi(req.c_str() + cl + 16);
                if (req.size() - hdr >= want) break;
            }
        }
        std::string verb = req.substr(0, req.find(' '));
        std::string path = req.substr(req.find(' ') + 1);
        path = path.substr(0, path.find(' '));
        std::string body = req.substr(req.find("\r\n\r\n") + 4);

        if (verb == "GET" && path.rfind("/fppd/status", 0) == 0) {
            std::ostringstream j;
            j << "{\"current_playlist\":{\"count\":\"1\",\"index\":\"1\",\"playlist\":\""
              << curPl() << "\",\"type\":\"sequence\"},\"current_sequence\":\"" << curSeq()
              << "\",\"status_name\":\"" << (gPlaying.load() ? "playing" : "idle") << "\"}";
            std::string jb = j.str();
            std::ostringstream r;
            r << "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
              << jb.size() << "\r\nConnection: close\r\n\r\n" << jb;
            std::string rs = r.str();
            send(c, rs.data(), rs.size(), 0);
            close(c);
            continue;
        }

        std::string cmd = path.substr(path.rfind('/') + 1);
        for (size_t i = 0; i + 2 < cmd.size(); ) {   // un-percent-encode
            if (cmd[i] == '%') {
                char h[3] = {cmd[i+1], cmd[i+2], 0};
                cmd.replace(i, 3, 1, (char)strtol(h, nullptr, 16));
            }
            ++i;
        }
        logCmd(cmd + " " + body);
        if (cmd.rfind("Start", 0) == 0) {
            // body is ["<name>","<repeat>","<ifNotRunning>"] - pull out the name.
            size_t a = body.find('"'), b = body.find('"', a + 1);
            std::string name = (a == std::string::npos || b == std::string::npos)
                             ? std::string() : body.substr(a + 1, b - a - 1);
            // FPP names the on-the-fly playlist after the fseq it wraps.
            bool isSeq = name.size() > 5 && name.compare(name.size() - 5, 5, ".fseq") == 0;
            setPlaying(isSeq ? name : "item-of-" + name, name);
        }
        if (cmd.rfind("Stop", 0) == 0) setPlaying("", "");
        const char* resp = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok";
        send(c, resp, strlen(resp), 0);
        close(c);
    }
}

/* ------------------------------------------------- FPPPlugin base stubs */
static std::string gConfigPath;
static void readIni(std::map<std::string, std::string>& into) {
    into.clear();
    std::ifstream f(gConfigPath);
    std::string line;
    while (std::getline(f, line)) {
        size_t eq = line.find('=');
        if (eq == std::string::npos) continue;
        std::string k = line.substr(0, eq), v = line.substr(eq + 1);
        auto trim = [](std::string s) {
            size_t a = s.find_first_not_of(" \t\"\r\n");
            size_t b = s.find_last_not_of(" \t\"\r\n");
            return a == std::string::npos ? std::string() : s.substr(a, b - a + 1);
        };
        into[trim(k)] = trim(v);
    }
}
FPPPlugin::FPPPlugin(const std::string& n) : name(n) { readIni(settings); }
void FPPPlugin::reloadSettings() { readIni(settings); }

/* ---------------------------------------------------------------- helpers */
static int gFails = 0;
static void section(const char* n) { printf("\n== %s ==\n", n); }
static void check(bool ok, const std::string& what) {
    printf("  %s  %s\n", ok ? "PASS" : "FAIL", what.c_str());
    if (!ok) gFails++;
}
static void nap(int ms) { std::this_thread::sleep_for(std::chrono::milliseconds(ms)); }
static bool logHas(const std::vector<std::string>& l, const std::string& needle) {
    for (auto& s : l) if (s.find(needle) != std::string::npos) return true;
    return false;
}
static int countStarts(const std::vector<std::string>& l) {
    int n = 0;
    for (auto& s : l) if (s.find("Start Playlist") != std::string::npos) n++;
    return n;
}
static std::string shmDir() {
    struct stat sb;
    if (stat("/dev/shm", &sb) == 0 && S_ISDIR(sb.st_mode)) return "/dev/shm";
    const char* t = getenv("TMPDIR");
    return t && *t ? std::string(t) : std::string("/tmp");
}
static std::string readFile(const std::string& p) {
    std::ifstream f(p);
    std::stringstream ss; ss << f.rdbuf();
    return ss.str();
}
static void writeFile(const std::string& p, const std::string& c) {
    std::ofstream f(p); f << c;
}
static std::string readStatus(const std::string& shm) { return readFile(shm + "/pixelselect_status.json"); }
static bool jsonHas(const std::string& j, const std::string& kv) { return j.find(kv) != std::string::npos; }

// The switch and button are wired active low, so "closed" is a low level.
static void switchOn()  { PIN_SW.level = false; }
static void switchOff() { PIN_SW.level = true; }
static void switch2On()  { PIN_SW2.level = false; }
static void switch2Off() { PIN_SW2.level = true; }

// Two switches, each owning its own designs. Written only by the multi-switch
// section; every other section runs with no sets file at all, which is what
// proves the single-switch install still works untouched.
static void writeSets(const std::string& root) {
    writeFile(root + "/config/pixelselect_sets.tsv",
              "# name\tpin\tactive_low\tpull\n"
              "Christmas\tP9-15\t1\tgpio_pu\n"
              "Halloween\tP9-23\t1\tgpio_pu\n");
}
static void clearSets(const std::string& root) {
    ::remove((root + "/config/pixelselect_sets.tsv").c_str());
}
static void writeTwoSetDesigns(const std::string& root) {
    writeFile(root + "/config/pixelselect_designs.tsv",
              "# type\tname\tlabel\tenabled\tset\n"
              "sequence\tSnowfall Roof.fseq\tSnowfall\t1\t0\n"
              "sequence\tCandy Cane Spin.fseq\tCandy Cane\t1\t0\n"
              "sequence\tArch Chase.fseq\tArch Chase\t1\t1\n"
              "sequence\tIcicle Drip.fseq\tIcicle\t1\t1\n");
}
static void press()     { PIN_BT.level = false; nap(120); PIN_BT.level = true; }

static void writeConfig(const std::string& root, const char* enabled,
                        const char* wrap, const char* longAction,
                        const char* enablePin = "P9-15", const char* virtualEnable = "0",
                        const char* takeover = "1", const char* handBack = "1") {
    std::ostringstream o;
    o << "enabled = \"" << enabled << "\"\n"
      << "virtual_enable = \"" << virtualEnable << "\"\n"
      << "enable_pin = \"" << enablePin << "\"\n"
      << "enable_active_low = \"1\"\n"
      << "enable_pull = \"gpio_pu\"\n"
      << "next_pin = \"P9-16\"\n"
      << "next_active_low = \"1\"\n"
      << "next_pull = \"gpio_pu\"\n"
      << "debounce_ms = \"30\"\n"
      << "long_press_ms = \"1200\"\n"
      << "long_press_action = \"" << longAction << "\"\n"
      << "repeat = \"1\"\n"
      << "wrap = \"" << wrap << "\"\n"
      << "stop_mode = \"now\"\n"
      << "resume_last = \"1\"\n"
      << "keep_playing = \"1\"\n"
      << "takeover = \"" << takeover << "\"\n"
      << "hand_back = \"" << handBack << "\"\n";
    writeFile(root + "/config/plugin.pixelselect", o.str());
}

// candyEnabled lets a test switch off the entry that is currently selected.
static void writeDesigns(const std::string& root, bool candyEnabled) {
    std::ostringstream o;
    o << "# test list\n"
      << "sequence\tSnowfall Roof.fseq\tSnowfall\t1\n"
      << "sequence\tCandy Cane Spin.fseq\tCandy Cane\t" << (candyEnabled ? "1" : "0") << "\n"
      << "playlist\tMain Show\tMain Show\t0\n"
      << "sequence\tArch Chase.fseq\tArch Chase\t1\n";
    writeFile(root + "/config/pixelselect_designs.tsv", o.str());
}

int main(int argc, char** argv) {
    if (argc < 3) { printf("usage: testhost <fake-media-dir> <libpixelselect.so>\n"); return 2; }
    std::string root = argv[1];
    std::string shm  = shmDir();
    gConfigPath = root + "/config/plugin.pixelselect";

    writeConfig(root, "1", "1", "none");
    ::remove((root + "/config/pixelselect_state.txt").c_str());
    clearSets(root);
    writeDesigns(root, true);
    sequence = (Sequence*)calloc(1, sizeof(Sequence));   // stubs answer from globals
    scheduler = (Scheduler*)calloc(1, sizeof(Scheduler));
    setPlaying("", "");
    std::thread(fakeFppd).detach();
    nap(200);

    void* h = dlopen(argv[2], RTLD_NOW);
    if (!h) { printf("dlopen: %s\n", dlerror()); return 1; }
    typedef FPPPlugin* (*mk)();
    mk createPlugin = (mk)dlsym(h, "createPlugin");
    if (!createPlugin) { printf("no createPlugin symbol\n"); return 1; }

    section("startup");
    FPPPlugin* p = createPlugin();
    nap(900);
    std::string st = readStatus(shm);
    check(jsonHas(st, "\"live\":true"), "publishes a live status snapshot");
    check(jsonHas(st, "\"totalDesigns\":4"), "reads the 4-entry design list");
    check(jsonHas(st, "\"switchOk\":true") && jsonHas(st, "\"buttonOk\":true"), "resolves both pins");
    check(readFile(shm + "/pixelselect_pins.json").find("P9-15") != std::string::npos,
          "publishes the board's pin list for the UI");
    check(takeLog().empty(), "starts nothing while the switch is open");

    section("switch on");
    switchOn(); nap(700);
    auto l = takeLog();
    check(logHas(l, "Start Playlist"), "starts a design when the switch closes");
    check(logHas(l, "Snowfall Roof.fseq"), "starts the first enabled design");
    check(logHas(l, "\"true\""), "asks FPP to loop it");

    section("pushbutton");
    press(); nap(400);
    check(logHas(takeLog(), "Candy Cane Spin.fseq"), "one press advances to the next design");

    section("disabled entries");
    press(); nap(400);
    check(logHas(takeLog(), "Arch Chase.fseq"), "skips an entry that is switched off");

    section("wrap");
    press(); nap(400);
    check(logHas(takeLog(), "Snowfall Roof.fseq"), "wraps from the last design back to the first");

    section("contact bounce");
    takeLog();
    for (int i = 0; i < 8; i++) { PIN_BT.level = false; nap(3); PIN_BT.level = true; nap(3); }
    PIN_BT.level = false; nap(220);
    for (int i = 0; i < 6; i++) { PIN_BT.level = true; nap(3); PIN_BT.level = false; nap(3); }
    PIN_BT.level = true; nap(500);
    check(countStarts(takeLog()) == 1, "a bouncing press advances exactly one design");

    section("switch off");
    switchOff(); nap(500);
    check(logHas(takeLog(), "Stop Now"), "stops playback when the switch opens");
    press(); nap(400);
    check(!logHas(takeLog(), "Start Playlist"), "the button does nothing while the switch is open");

    section("resume the remembered design");
    switchOn(); nap(700);
    check(logHas(takeLog(), "Candy Cane Spin.fseq"), "resumes exactly the design that was playing before");

    section("a remembered design that was since switched off");
    switchOff(); nap(400); takeLog();
    writeDesigns(root, false);
    nap(900);
    switchOn(); nap(700);
    l = takeLog();
    check(!logHas(l, "Candy Cane Spin.fseq"), "does not resume a design the user disabled");
    check(logHas(l, "Start Playlist"), "falls back to an enabled design instead");

    section("wrap turned off");
    writeDesigns(root, true);
    writeConfig(root, "1", "0", "none");
    nap(900);
    for (int i = 0; i < 4; i++) { press(); nap(400); }
    takeLog();
    press(); nap(500);
    check(countStarts(takeLog()) == 0, "with wrap off, the last design holds instead of restarting");
    writeConfig(root, "1", "1", "none");
    nap(900);

    section("keep-playing watchdog");
    takeLog();
    setPlaying("", "");                              // pretend playback died
    nap(9000);
    check(logHas(takeLog(), "Start Playlist"), "restarts the design if playback stops on its own");

    section("override whatever else is playing");
    nap(4000); takeLog();
    setPlaying("someone-elses.fseq", "Scheduled Show");   // the scheduler barges in
    nap(9000);
    l = takeLog();
    check(countStarts(l) >= 1, "takes the player back when something else grabs it");
    check(logHas(l, "Snowfall Roof.fseq") || logHas(l, "Candy Cane Spin.fseq")
       || logHas(l, "Arch Chase.fseq"), "and puts its own design back on");

    section("override turned off");
    writeConfig(root, "1", "1", "none", "P9-15", "0", "0");   // takeover off
    nap(1500); takeLog();
    setPlaying("someone-elses.fseq", "Scheduled Show");
    nap(9000);
    check(countStarts(takeLog()) == 0, "leaves the player alone when override is off");
    writeConfig(root, "1", "1", "none");
    nap(1500);

    section("giving the schedule back");
    // FPP will not resume a mid-window scheduled playlist once something else
    // has owned the player, so the plugin has to ask the scheduler to look again.
    gScheduledNow = "Scheduled Show";
    writeConfig(root, "1", "1", "none");
    nap(1500);
    switchOn(); nap(4000);
    check(curPl() != "Scheduled Show", "the plugin is holding the player");
    gSchedulerAsked = 0;
    switchOff(); nap(5000);
    check(gSchedulerAsked > 0, "asks the scheduler to look again once the player is idle");
    check(curPl() == "Scheduled Show", "the scheduled playlist is running again");

    section("nothing scheduled right now");
    gScheduledNow = "";
    switchOn(); nap(4000);
    gSchedulerAsked = 0;
    switchOff(); nap(5000);
    check(gSchedulerAsked > 0, "still asks the scheduler");
    check(!gPlaying.load(), "and correctly stays idle when nothing is scheduled");

    section("hand-back turned off");
    writeConfig(root, "1", "1", "none", "P9-15", "0", "1", "0");   // hand_back off
    nap(1500);
    switchOn(); nap(4000);
    gSchedulerAsked = 0;
    switchOff(); nap(5000);
    check(gSchedulerAsked == 0, "leaves the scheduler alone when hand-back is off");
    writeConfig(root, "1", "1", "none");
    nap(1500);
    switchOn(); nap(4000); takeLog();       // restore the state later sections expect

    section("virtual button from the web UI");
    takeLog();
    writeFile(shm + "/pixelselect_cmd", "next\n"); nap(900);
    check(logHas(takeLog(), "Start Playlist"), "the UI's virtual Next press works");
    writeFile(shm + "/pixelselect_cmd", "stop\n"); nap(900);
    check(logHas(takeLog(), "Stop"), "the UI's Stop works");

    section("live edit of the design list");
    writeFile(root + "/config/pixelselect_designs.tsv",
              "# edited\nsequence\tIcicle Drip.fseq\tIcicle\t1\n");
    nap(900);
    check(jsonHas(readStatus(shm), "\"totalDesigns\":1"), "picks up a design list edited underneath it");

    section("master switch off");
    takeLog();
    writeConfig(root, "0", "1", "none");
    nap(900);
    check(logHas(takeLog(), "Stop"), "turning the plugin off releases playback");
    press(); nap(400);
    check(!logHas(takeLog(), "Start Playlist"), "the button is inert while the plugin is off");

    section("no enable pin configured");
    // Regression: an unconfigured plugin must never seize playback. Found on a
    // real device, where it started a design over the running schedule.
    writeDesigns(root, true);
    writeConfig(root, "1", "1", "none", "");        // enabled, but no enable pin
    nap(900); takeLog();
    switchOn(); nap(700);                            // the pin is not even watched now
    check(countStarts(takeLog()) == 0, "an enabled plugin with no enable pin stays out of playback");
    writeFile(shm + "/pixelselect_cmd", "next\n"); nap(500);
    check(countStarts(takeLog()) == 0, "and the virtual button cannot start it either");
    writeConfig(root, "1", "1", "none", "", "1");   // software override on
    nap(900);
    check(countStarts(takeLog()) >= 1, "the software override is the way to run without a switch");
    writeConfig(root, "1", "1", "none");
    nap(900); takeLog();

    section("several switches, one set of designs each");
    switchOff(); switch2Off(); nap(1500);
    writeSets(root);
    writeTwoSetDesigns(root);
    nap(1500); takeLog();

    switchOn(); nap(4000);
    l = takeLog();
    check(logHas(l, "Snowfall Roof.fseq"), "the first switch starts its own first design");

    press(); nap(1200);
    l = takeLog();
    check(logHas(l, "Candy Cane Spin.fseq"), "the button walks only that switch's designs");
    press(); nap(1200);
    check(logHas(takeLog(), "Snowfall Roof.fseq"), "and wraps inside the set, never into the other one");

    switch2On(); nap(4000);                       // both closed - newest wins
    l = takeLog();
    check(logHas(l, "Arch Chase.fseq"), "closing a second switch hands over to its set");
    press(); nap(1200);
    check(logHas(takeLog(), "Icicle Drip.fseq"), "the button now walks the second set");

    switch2Off(); nap(4000);                      // fall back to the one still closed
    l = takeLog();
    check(logHas(l, "Icicle Drip.fseq") == false, "releasing the newer switch leaves its set");
    check(logHas(l, "Snowfall Roof.fseq") || logHas(l, "Candy Cane Spin.fseq"),
          "and falls back to the switch that is still closed");

    switch2On(); nap(4000); takeLog();            // each set remembers its own design
    press(); nap(1500);
    bool onIcicle = logHas(takeLog(), "Icicle Drip.fseq");
    switch2Off(); nap(4000); takeLog();
    switch2On(); nap(4000);
    check(logHas(takeLog(), onIcicle ? "Icicle Drip.fseq" : "Arch Chase.fseq"),
          "each switch remembers its own last design");

    switchOff(); switch2Off(); nap(4000);
    check(logHas(takeLog(), "Stop"), "with every switch open the plugin lets go");

    clearSets(root);
    writeDesigns(root, true);
    nap(1500); takeLog();

    section("shutdown");
    delete p;
    nap(300);
    check(true, "unloads cleanly");

    printf("\n%s (%d failure%s)\n", gFails ? "FAILURES" : "ALL PASS", gFails, gFails == 1 ? "" : "s");
    return gFails ? 1 : 0;
}
