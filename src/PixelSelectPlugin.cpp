/*
 * FPP "pixelselect" plugin  -  pick a design with two GPIO pins (FPP 5.4 - 9.x)
 *
 * Wiring model
 * ------------
 *   ENABLE pin  - a toggle switch. While it is asserted the plugin owns playback:
 *                 it starts the currently selected design and keeps it looping.
 *                 When it is released, playback stops.
 *   NEXT pin    - a momentary pushbutton. Each press advances to the next design
 *                 in the user's ordered list and starts it immediately.
 *
 * A "design" is either an xLights sequence (a .fseq in media/sequences) or an FPP
 * playlist. Both are started with FPP's "Start Playlist" command: FPP generates an
 * on-the-fly single-item playlist when the name ends in .fseq (and even picks up
 * the media file named in the fseq header), so a raw sequence loops just like a
 * playlist does. That behaviour is identical on 5.4 and on 9.x.
 *
 * Portability notes (one source, FPP 5.4 -> 9.x)
 * ----------------------------------------------
 *  - GPIO goes through FPP's own PinCapabilities so pin names ("P9-15", "P1-11")
 *    mean the same thing here as on FPP's GPIO Inputs page, on both BBB and Pi.
 *  - Playback is driven by POSTing to fppd's own command endpoint on
 *    127.0.0.1:32322 rather than by calling Player/CommandManager directly.
 *    Those headers drag in libhttpserver (5.4) or drogon (9.x), which are not
 *    reliably installed on a device that is only compiling a plugin; the HTTP
 *    command API has been stable across every version in range.
 *  - The design list is a plain TSV, not JSON, so the plugin needs no jsoncpp
 *    linkage of its own.
 *
 * Files
 * -----
 *  config/plugin.pixelselect        settings (written by the web UI, read here)
 *  config/pixelselect_designs.tsv   the ordered design list (web UI writes it)
 *  config/pixelselect_state.txt     which design was selected last (survives reboot)
 *  /dev/shm/pixelselect_status.json live status for the web UI
 *  /dev/shm/pixelselect_pins.json   pin names this board offers, for the UI pickers
 *  /dev/shm/pixelselect_cmd         one-shot commands from the UI (virtual button)
 */
#include <algorithm>
#include <atomic>
#include <chrono>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <fstream>
#include <mutex>
#include <condition_variable>
#include <sstream>
#include <string>
#include <thread>
#include <vector>

#include <sys/socket.h>
#include <sys/stat.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <arpa/inet.h>
#include <unistd.h>
#include <dlfcn.h>

// Include order matters: on FPP 9.x util/GPIOUtils.h uses Json::Value without
// including jsoncpp itself, and Plugin.h is what pulls it in. Do not sort these.
#include "Plugin.h"
#include "Sequence.h"
#include "util/GPIOUtils.h"

namespace {

// FPP's media directory is /home/fpp/media on every stock image, but the macro
// that names it changed shape between 5.4 and 9.x, so including settings.h would
// cost us the single-source build. Instead work it out from where this shared
// library was loaded: it always lives at <media>/plugins/pixelselect/, which is
// true no matter where the media directory has been moved to.
std::string mediaDir() {
    static std::string cached;
    if (!cached.empty()) return cached;
    if (const char* env = getenv("FPP_MEDIA_DIR")) {   // escape hatch + tests
        cached = env;
        return cached;
    }
    Dl_info info;
    if (dladdr((void*)&mediaDir, &info) && info.dli_fname) {
        std::string p(info.dli_fname);
        for (int i = 0; i < 3; ++i) {                  // .so -> pixelselect -> plugins -> media
            size_t slash = p.find_last_of('/');
            if (slash == std::string::npos || slash == 0) { p.clear(); break; }
            p.erase(slash);
        }
        if (!p.empty()) { cached = p; return cached; }
    }
    cached = "/home/fpp/media";
    return cached;
}
std::string configDir() { return mediaDir() + "/config"; }

// Live state goes in shared memory: no SD-card wear, and Apache can read it
// (a /tmp file would land in Apache's PrivateTmp namespace). The fallback only
// fires off-device, where /dev/shm does not exist.
std::string shmDir() {
    struct stat st;
    if (stat("/dev/shm", &st) == 0 && S_ISDIR(st.st_mode)) return "/dev/shm";
    const char* t = getenv("TMPDIR");
    return t && *t ? std::string(t) : std::string("/tmp");
}

const std::string kDesignsPath = configDir() + "/pixelselect_designs.tsv";
const std::string kStatePath   = configDir() + "/pixelselect_state.txt";
const std::string kStatusPath  = shmDir()    + "/pixelselect_status.json";
const std::string kPinsPath    = shmDir()    + "/pixelselect_pins.json";
const std::string kCmdPath     = shmDir()    + "/pixelselect_cmd";

long toLong(const std::string& v, long d) {
    if (v.empty()) return d;
    char* e = nullptr;
    long r = std::strtol(v.c_str(), &e, 10);
    return e == v.c_str() ? d : r;
}
bool toBool(const std::string& v, bool d) {
    if (v.empty()) return d;
    return v == "1" || v == "true" || v == "on" || v == "yes";
}
std::string trim(const std::string& s) {
    size_t a = s.find_first_not_of(" \t\r\n");
    if (a == std::string::npos) return "";
    size_t b = s.find_last_not_of(" \t\r\n");
    return s.substr(a, b - a + 1);
}
// Minimal JSON string escaping - design labels come from the web UI.
std::string jesc(const std::string& s) {
    std::string o;
    o.reserve(s.size() + 8);
    for (char c : s) {
        switch (c) {
            case '"':  o += "\\\""; break;
            case '\\': o += "\\\\"; break;
            case '\n': o += "\\n";  break;
            case '\r': o += "\\r";  break;
            case '\t': o += "\\t";  break;
            default:
                if ((unsigned char)c < 0x20) { char b[8]; snprintf(b, sizeof(b), "\\u%04x", c); o += b; }
                else o += c;
        }
    }
    return o;
}
std::string urlEncode(const std::string& s) {
    static const char* hex = "0123456789ABCDEF";
    std::string o;
    for (unsigned char c : s) {
        if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') o += (char)c;
        else { o += '%'; o += hex[c >> 4]; o += hex[c & 0xF]; }
    }
    return o;
}
// mtime alone is a 1-second clock: a UI that saves twice in the same second
// would not be noticed. Fold the size in so any realistic edit changes the stamp.
long long fileStamp(const char* path) {
    struct stat st;
    if (stat(path, &st) != 0) return 0;
    return (long long)st.st_mtime * 1000003LL + (long long)st.st_size + 1;
}

// One entry of the user's ordered list.
struct Design {
    std::string type;    // "sequence" | "playlist"
    std::string name;    // "Foo.fseq" or a playlist name
    std::string label;   // friendly name shown in the UI
    bool enabled = true;
    std::string key() const { return type + "\t" + name; }
};

// A debounced digital input. Raw level is sampled fast; the logical level only
// changes once the raw level has been stable for debounceMs, which is what makes
// a mechanical switch or button usable without external hardware.
struct DebouncedInput {
    const PinCapabilities* pin = nullptr;
    std::string configuredName;   // pin name we last configured
    std::string configuredMode;   // "gpio" / "gpio_pu" / "gpio_pd"
    bool activeLow = true;
    bool raw = false;             // last raw sample (already inverted)
    bool stable = false;          // debounced logical level
    bool valid = false;           // pin resolved and configured
    std::chrono::steady_clock::time_point lastEdge{};

    void release() {
        pin = nullptr;
        valid = false;
        configuredName.clear();
        configuredMode.clear();
    }

    // (Re)resolve the pin if the user changed the name or the pull mode.
    void configure(const std::string& name, const std::string& mode, bool aLow, std::string& errOut) {
        activeLow = aLow;
        if (name.empty()) { release(); return; }
        if (valid && name == configuredName && mode == configuredMode) return;
        pin = PinCapabilities::getPinByName(name).ptr();
        if (!pin) {
            valid = false;
            configuredName = name;
            configuredMode = mode;
            errOut = "Unknown pin \"" + name + "\" on this board";
            return;
        }
        pin->configPin(mode, false);   // false = input
        configuredName = name;
        configuredMode = mode;
        valid = true;
        raw = stable = readRaw();
        lastEdge = std::chrono::steady_clock::now();
    }

    bool readRaw() const {
        if (!pin) return false;
        bool v = pin->getValue();
        return activeLow ? !v : v;
    }

    // Returns true when the debounced level changed on this sample.
    bool sample(long debounceMs, std::chrono::steady_clock::time_point now) {
        if (!valid) return false;
        bool v = readRaw();
        if (v != raw) {
            raw = v;
            lastEdge = now;
            return false;
        }
        if (v != stable &&
            std::chrono::duration_cast<std::chrono::milliseconds>(now - lastEdge).count() >= debounceMs) {
            stable = v;
            return true;
        }
        return false;
    }
};

}  // namespace

class PixelSelectPlugin : public FPPPlugin {
public:
    PixelSelectPlugin() : FPPPlugin("pixelselect") {
        loadState();
        applySettings();      // base ctor already populated `settings`
        loadDesigns(true);
        mStart = std::chrono::steady_clock::now();
        mWorker = std::thread([this] { workerLoop(); });
    }

    ~PixelSelectPlugin() override {
        {
            std::lock_guard<std::mutex> lk(mMx);
            mStop = true;
        }
        mCv.notify_all();
        if (mWorker.joinable()) mWorker.join();
        saveState();
        mEnableIn.release();
        mNextIn.release();
    }

private:
    // ---------------------------------------------------------------- settings
    std::string cfg(const std::string& k, const std::string& d = "") const {
        auto it = settings.find(k);
        return it == settings.end() ? d : it->second;
    }

    void applySettings() {
        mPluginEnabled = toBool(cfg("enabled"), false);
        mVirtualEnable = toBool(cfg("virtual_enable"), false);
        mEnablePin     = trim(cfg("enable_pin"));
        mEnablePull    = cfg("enable_pull", "gpio_pu");
        mEnableLow     = toBool(cfg("enable_active_low"), true);
        mNextPin       = trim(cfg("next_pin"));
        mNextPull      = cfg("next_pull", "gpio_pu");
        mNextLow       = toBool(cfg("next_active_low"), true);
        mDebounceMs    = std::max(1L, std::min(1000L, toLong(cfg("debounce_ms"), 30)));
        mLongPressMs   = std::max(0L, toLong(cfg("long_press_ms"), 1200));
        mLongAction    = cfg("long_press_action", "none");
        mRepeat        = toBool(cfg("repeat"), true);
        mWrap          = toBool(cfg("wrap"), true);
        mStopMode      = cfg("stop_mode", "now");
        mResumeLast    = toBool(cfg("resume_last"), true);
        mKeepPlaying   = toBool(cfg("keep_playing"), true);
    }

    // ------------------------------------------------------------- design list
    void loadDesigns(bool force) {
        long long stamp = fileStamp(kDesignsPath.c_str());
        if (!force && stamp == mDesignsStamp) return;
        mDesignsStamp = stamp;

        std::vector<Design> list;
        std::ifstream in(kDesignsPath.c_str());
        std::string line;
        while (std::getline(in, line)) {
            line = trim(line);
            if (line.empty() || line[0] == '#') continue;
            std::vector<std::string> f;
            size_t start = 0;
            for (;;) {
                size_t tab = line.find('\t', start);
                f.push_back(line.substr(start, tab == std::string::npos ? std::string::npos : tab - start));
                if (tab == std::string::npos) break;
                start = tab + 1;
            }
            if (f.size() < 2) continue;
            Design d;
            d.type    = trim(f[0]) == "playlist" ? "playlist" : "sequence";
            d.name    = trim(f[1]);
            d.label   = f.size() > 2 ? trim(f[2]) : d.name;
            d.enabled = f.size() > 3 ? toBool(trim(f[3]), true) : true;
            if (d.name.empty()) continue;
            if (d.label.empty()) d.label = d.name;
            list.push_back(d);
        }
        mDesigns.swap(list);

        // Keep pointing at the same design across an edit/reorder where possible.
        mIndex = resolveStart();
        if (mIndex >= 0) mSelectedKey = mDesigns[mIndex].key();
    }

    // Resolve a remembered design, but never land on one the user has since
    // switched off - fall through to the first entry that is still enabled.
    int resolveStart() const {
        int idx = indexOfKey(mSelectedKey);
        if (idx >= 0 && mDesigns[idx].enabled) return idx;
        return firstEnabled();
    }

    int indexOfKey(const std::string& key) const {
        if (key.empty()) return -1;
        for (size_t i = 0; i < mDesigns.size(); ++i)
            if (mDesigns[i].key() == key) return (int)i;
        return -1;
    }
    int firstEnabled() const {
        for (size_t i = 0; i < mDesigns.size(); ++i)
            if (mDesigns[i].enabled) return (int)i;
        return -1;
    }
    // Step `dir` places through the enabled entries, honouring the wrap setting.
    int stepIndex(int from, int dir) const {
        int n = (int)mDesigns.size();
        if (n == 0) return -1;
        for (int i = 1; i <= n; ++i) {
            int c = from + dir * i;
            if (c < 0 || c >= n) {
                if (!mWrap) return from >= 0 && from < n && mDesigns[from].enabled ? from : firstEnabled();
                c = ((c % n) + n) % n;
            }
            if (mDesigns[c].enabled) return c;
        }
        return firstEnabled();
    }

    // ------------------------------------------------------------------- state
    void loadState() {
        std::ifstream in(kStatePath.c_str());
        std::string line;
        while (std::getline(in, line)) {
            size_t sp = line.find(' ');
            if (sp == std::string::npos) continue;
            std::string k = line.substr(0, sp), v = trim(line.substr(sp + 1));
            if (k == "key") {
                // stored as "type|name" so a tab never has to survive the file
                size_t bar = v.find('|');
                if (bar != std::string::npos) mSelectedKey = v.substr(0, bar) + "\t" + v.substr(bar + 1);
            }
        }
    }
    void saveState() {
        if (mSelectedKey.empty()) return;
        std::string v = mSelectedKey;
        size_t tab = v.find('\t');
        if (tab != std::string::npos) v[tab] = '|';
        FILE* f = fopen(kStatePath.c_str(), "w");
        if (!f) return;
        fprintf(f, "PIXELSELECT_STATE 1\nkey %s\n", v.c_str());
        fclose(f);
    }

    // ------------------------------------------------------------ fppd control
    // POST to fppd's own command endpoint. Short timeouts: this runs on the
    // worker thread and must never wedge the button handling.
    bool fppdCommand(const std::string& command, const std::vector<std::string>& args) {
        std::string body = "[";
        for (size_t i = 0; i < args.size(); ++i) {
            if (i) body += ",";
            body += "\"" + jesc(args[i]) + "\"";
        }
        body += "]";

        int fd = socket(AF_INET, SOCK_STREAM, 0);
        if (fd < 0) return false;
        struct timeval tv { 2, 0 };
        setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &tv, sizeof(tv));
        setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof(tv));

        struct sockaddr_in sa;
        memset(&sa, 0, sizeof(sa));
        sa.sin_family = AF_INET;
        sa.sin_port = htons(32322);
        sa.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
        if (connect(fd, (struct sockaddr*)&sa, sizeof(sa)) != 0) { close(fd); return false; }

        std::ostringstream req;
        req << "POST /command/" << urlEncode(command) << " HTTP/1.1\r\n"
            << "Host: 127.0.0.1:32322\r\n"
            << "Content-Type: application/json\r\n"
            << "Content-Length: " << body.size() << "\r\n"
            << "Connection: close\r\n\r\n"
            << body;
        std::string r = req.str();
        size_t sent = 0;
        while (sent < r.size()) {
            ssize_t n = send(fd, r.data() + sent, r.size() - sent, MSG_NOSIGNAL);
            if (n <= 0) { close(fd); return false; }
            sent += (size_t)n;
        }
        char buf[512];
        ssize_t got = recv(fd, buf, sizeof(buf) - 1, 0);
        close(fd);
        if (got <= 0) return false;
        buf[got] = 0;
        return strstr(buf, " 200 ") != nullptr;
    }

    void startCurrent() {
        if (mIndex < 0 || mIndex >= (int)mDesigns.size()) return;
        const Design& d = mDesigns[mIndex];
        mSelectedKey = d.key();
        saveState();
        fppdCommand("Start Playlist", { d.name, mRepeat ? "true" : "false", "false" });
        mLastStart = std::chrono::steady_clock::now();
        mIdleSince = {};
        mLastEvent = mLastStart;
    }

    void stopPlayback() {
        if (mStopMode == "graceful")      fppdCommand("Stop Gracefully", { "false" });
        else if (mStopMode == "afterloop") fppdCommand("Stop Gracefully", { "true" });
        else                               fppdCommand("Stop Now", {});
        mIdleSince = {};
        mLastEvent = std::chrono::steady_clock::now();
    }

    bool isPlaying() const {
        return sequence != nullptr && sequence->IsSequenceRunning();
    }

    // ------------------------------------------------------------------ inputs
    void configureInputs() {
        std::string err;
        mEnableIn.configure(mEnablePin, mEnablePull, mEnableLow, err);
        if (!err.empty()) { mPinError = err; err.clear(); }
        mNextIn.configure(mNextPin, mNextPull, mNextLow, err);
        if (!err.empty()) mPinError = err;
        if (mEnableIn.valid && (mNextIn.valid || mNextPin.empty())) mPinError.clear();
    }

    // The switch decides whether we own playback. There must be an explicit
    // reason to be active: either the configured pin is asserted, or the user
    // turned the software override on. With no enable pin configured we stay
    // OUT of the way - a plugin that seized playback the moment it was enabled
    // would fight the scheduler on a device that has one.
    bool enableAsserted() const {
        if (!mPluginEnabled) return false;
        if (mVirtualEnable) return true;
        if (mEnablePin.empty()) return false;
        if (!mEnableIn.valid) return false;
        return mEnableIn.stable;
    }

    void handleButtonEdge(bool nowDown, std::chrono::steady_clock::time_point now) {
        bool useLongPress = (mLongAction != "none" && mLongPressMs > 0);
        if (nowDown) {
            mPressStart = now;
            if (!useLongPress) fireNext();
            return;
        }
        if (!useLongPress) return;
        long held = std::chrono::duration_cast<std::chrono::milliseconds>(now - mPressStart).count();
        if (held >= mLongPressMs) fireLong();
        else fireNext();
    }

    void fireNext() {
        if (!mActive) { mLastEvent = std::chrono::steady_clock::now(); return; }
        int next = stepIndex(mIndex, 1);
        if (next < 0) return;
        // At the end of a non-wrapping list there is nowhere to go - hold the
        // current design rather than restarting it under the viewer.
        if (next == mIndex && !mWrap) { mLastEvent = std::chrono::steady_clock::now(); return; }
        mIndex = next;
        startCurrent();
    }

    void fireLong() {
        if (!mActive) return;
        if (mLongAction == "first") {
            int i = firstEnabled();
            if (i < 0) return;
            mIndex = i;
        } else if (mLongAction == "prev") {
            int i = stepIndex(mIndex, -1);
            if (i < 0) return;
            mIndex = i;
        }
        // "restart" keeps the current index and simply starts it again
        startCurrent();
    }

    // --------------------------------------------------------- UI command file
    // The settings page can act as a virtual pushbutton so the plugin can be set
    // up and tested before any wire is soldered.
    void pollCommandFile() {
        std::ifstream in(kCmdPath.c_str());
        if (!in) return;
        std::string line;
        std::getline(in, line);
        in.close();
        unlink(kCmdPath.c_str());
        line = trim(line);
        if (line.empty()) return;

        std::string verb = line, arg;
        size_t sp = line.find(' ');
        if (sp != std::string::npos) { verb = line.substr(0, sp); arg = trim(line.substr(sp + 1)); }

        if (verb == "next")      { if (mActive) fireNext(); else { int i = stepIndex(mIndex, 1); if (i >= 0) { mIndex = i; mSelectedKey = mDesigns[i].key(); saveState(); } } }
        else if (verb == "prev") { int i = stepIndex(mIndex, -1); if (i >= 0) { mIndex = i; mSelectedKey = mDesigns[i].key(); saveState(); if (mActive) startCurrent(); } }
        else if (verb == "select") {
            int i = (int)toLong(arg, -1);
            if (i >= 0 && i < (int)mDesigns.size()) {
                mIndex = i;
                mSelectedKey = mDesigns[i].key();
                saveState();
                if (mActive) startCurrent();
            }
        } else if (verb == "restart") { if (mActive) startCurrent(); }
        else if (verb == "stop")      { stopPlayback(); }
        mLastEvent = std::chrono::steady_clock::now();
    }

    // ------------------------------------------------------------------ output
    void writePins() {
        if (mPinsWritten) return;
        std::vector<std::string> names = PinCapabilities::getPinNames();
        if (names.empty()) return;    // fppd has not initialised GPIO yet - retry
        FILE* f = fopen(kPinsPath.c_str(), "w");
        if (!f) return;
        fprintf(f, "[");
        bool first = true;
        for (auto& n : names) {
            const PinCapabilities* p = PinCapabilities::getPinByName(n).ptr();
            if (!p) continue;
            fprintf(f, "%s{\"pin\":\"%s\",\"pu\":%s,\"pd\":%s}",
                    first ? "" : ",", jesc(n).c_str(),
                    p->supportsPullUp() ? "true" : "false",
                    p->supportsPullDown() ? "true" : "false");
            first = false;
        }
        fprintf(f, "]");
        fclose(f);
        mPinsWritten = true;
    }

    void writeStatus() {
        FILE* f = fopen(kStatusPath.c_str(), "w");
        if (!f) return;
        const Design* d = (mIndex >= 0 && mIndex < (int)mDesigns.size()) ? &mDesigns[mIndex] : nullptr;
        auto now = std::chrono::steady_clock::now();
        double ago = mLastEvent.time_since_epoch().count()
                     ? std::chrono::duration<double>(now - mLastEvent).count() : -1.0;
        fprintf(f,
            "{\"live\":true,\"pluginEnabled\":%s,\"active\":%s,\"virtualEnable\":%s,"
            "\"switchConfigured\":%s,\"switchOk\":%s,\"switchOn\":%s,"
            "\"buttonConfigured\":%s,\"buttonOk\":%s,\"buttonDown\":%s,"
            "\"index\":%d,\"count\":%d,\"label\":\"%s\",\"name\":\"%s\",\"type\":\"%s\","
            "\"playing\":%s,\"repeat\":%s,\"wrap\":%s,\"pinError\":\"%s\","
            "\"lastEventAgo\":%.1f,\"uptime\":%.1f}",
            mPluginEnabled ? "true" : "false",
            mActive ? "true" : "false",
            mVirtualEnable ? "true" : "false",
            mEnablePin.empty() ? "false" : "true",
            mEnableIn.valid ? "true" : "false",
            enableAsserted() ? "true" : "false",
            mNextPin.empty() ? "false" : "true",
            mNextIn.valid ? "true" : "false",
            mNextIn.stable ? "true" : "false",
            mIndex, (int)mDesigns.size(),
            d ? jesc(d->label).c_str() : "",
            d ? jesc(d->name).c_str() : "",
            d ? d->type.c_str() : "",
            isPlaying() ? "true" : "false",
            mRepeat ? "true" : "false",
            mWrap ? "true" : "false",
            jesc(mPinError).c_str(),
            ago,
            std::chrono::duration<double>(now - mStart).count());
        fclose(f);
    }

    // ------------------------------------------------------------ worker thread
    void workerLoop() {
        int tick = 0;
        for (;;) {
            {
                std::unique_lock<std::mutex> lk(mMx);
                mCv.wait_for(lk, std::chrono::milliseconds(5), [this] { return mStop; });
                if (mStop) break;
            }
            auto now = std::chrono::steady_clock::now();

            if (tick % 100 == 0) {            // ~500 ms: config, list, pin setup
                reloadSettings();
                applySettings();
                loadDesigns(false);
                configureInputs();
                writePins();
            }
            if (tick % 10 == 0) pollCommandFile();   // ~50 ms

            // Sample both inputs every pass so debouncing is accurate.
            mEnableIn.sample(mDebounceMs, now);
            bool btnChanged = mNextIn.sample(mDebounceMs, now);

            bool wantActive = enableAsserted();
            if (wantActive != mActive) {
                mActive = wantActive;
                if (mActive) {
                    mIndex = mResumeLast ? resolveStart() : firstEnabled();
                    if (mIndex >= 0) startCurrent();
                } else {
                    stopPlayback();
                }
            }

            if (btnChanged && mActive) handleButtonEdge(mNextIn.stable, now);
            else if (btnChanged) mLastEvent = now;

            if (tick % 100 == 0) {
                watchdog(now);
                writeStatus();
            }
            if (++tick >= 1000) tick = 0;
        }
        if (mActive) stopPlayback();
    }

    // If the toggle is still on but nothing is playing (a sequence ended, someone
    // hit Stop elsewhere, fppd restarted), put the selected design back on.
    // The grace period keeps us from fighting the gap between playlist items.
    void watchdog(std::chrono::steady_clock::time_point now) {
        if (!mKeepPlaying || !mActive || mIndex < 0) { mIdleSince = {}; return; }
        if (now - mLastStart < std::chrono::seconds(5)) return;
        if (isPlaying()) { mIdleSince = {}; return; }
        if (mIdleSince.time_since_epoch().count() == 0) { mIdleSince = now; return; }
        if (now - mIdleSince >= std::chrono::seconds(5)) startCurrent();
    }

    // --- settings ---
    bool mPluginEnabled = false, mVirtualEnable = false;
    std::string mEnablePin, mEnablePull = "gpio_pu", mNextPin, mNextPull = "gpio_pu";
    bool mEnableLow = true, mNextLow = true;
    long mDebounceMs = 30, mLongPressMs = 1200;
    std::string mLongAction = "none", mStopMode = "now";
    bool mRepeat = true, mWrap = true, mResumeLast = true, mKeepPlaying = true;

    // --- runtime ---
    std::vector<Design> mDesigns;
    long long mDesignsStamp = -1;
    int mIndex = -1;
    std::string mSelectedKey;
    bool mActive = false;
    std::string mPinError;
    bool mPinsWritten = false;
    DebouncedInput mEnableIn, mNextIn;
    std::chrono::steady_clock::time_point mPressStart{}, mLastStart{}, mIdleSince{},
                                          mLastEvent{}, mStart{};

    std::thread mWorker;
    std::mutex mMx;
    std::condition_variable mCv;
    bool mStop = false;
};

extern "C" {
FPPPlugin* createPlugin() { return new PixelSelectPlugin(); }
}
