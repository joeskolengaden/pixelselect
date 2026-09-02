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
#include <map>
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
#include "Scheduler.h"

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
const std::string kSetsPath    = configDir() + "/pixelselect_sets.tsv";
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
    int set = 0;         // which switch owns this design
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

// One toggle switch and the designs it selects. The pushbutton is shared: it
// advances within whichever set is currently switched on.
struct DesignSet {
    std::string name;
    std::string pin;
    std::string pull = "gpio_pu";
    bool activeLow = true;
    DebouncedInput in;
    std::string selectedKey;   // this set remembers its own last design
};

}  // namespace

class PixelSelectPlugin : public FPPPlugin {
public:
    PixelSelectPlugin() : FPPPlugin("pixelselect") {
        loadState();
        applySettings();      // base ctor already populated `settings`
        loadSets(true);
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
        for (auto& ds : mSets) ds.in.release();
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
        mTakeover      = toBool(cfg("takeover"), true);
        mHandBack      = toBool(cfg("hand_back"), true);
        mVirtualSet    = (int)toLong(cfg("virtual_set"), 0);
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
            d.set     = f.size() > 4 ? (int)toLong(trim(f[4]), 0) : 0;   // absent = set 1
            if (d.set < 0) d.set = 0;
            if (d.name.empty()) continue;
            if (d.label.empty()) d.label = d.name;
            list.push_back(d);
        }
        mDesigns.swap(list);
        rebuildSetOrder();

        // Keep pointing at the same design across an edit/reorder where possible.
        mIndex = resolveStart();
        if (mIndex >= 0 && mActiveSet >= 0) mSets[mActiveSet].selectedKey = mDesigns[mIndex].key();
    }

    // ---------------------------------------------------------------- the sets
    // One line per set: name <TAB> pin <TAB> active_low <TAB> pull. Without the
    // file (or with it empty) there is a single set built from the original
    // single-switch settings, so an existing install keeps working untouched.
    void loadSets(bool force) {
        long long stamp = fileStamp(kSetsPath.c_str());
        bool legacyChanged = (mLegacyPin != mEnablePin) || (mLegacyPull != mEnablePull) ||
                             (mLegacyLow != mEnableLow);
        if (!force && stamp == mSetsStamp && !legacyChanged) return;
        mSetsStamp = stamp;
        mLegacyPin = mEnablePin; mLegacyPull = mEnablePull; mLegacyLow = mEnableLow;

        std::vector<DesignSet> want;
        std::ifstream in(kSetsPath.c_str());
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
            DesignSet ds;
            ds.name      = trim(f[0]);
            ds.pin       = f.size() > 1 ? trim(f[1]) : "";
            ds.activeLow = f.size() > 2 ? toBool(trim(f[2]), true) : true;
            ds.pull      = f.size() > 3 ? trim(f[3]) : "gpio_pu";
            if (ds.name.empty()) ds.name = "Set " + std::to_string(want.size() + 1);
            want.push_back(ds);
            if (want.size() >= 32) break;
        }
        if (want.empty()) {
            DesignSet ds;
            ds.name = "Designs";
            ds.pin = mEnablePin;
            ds.pull = mEnablePull;
            ds.activeLow = mEnableLow;
            want.push_back(ds);
        }

        // Carry the live input state and the remembered design across a reload
        // so editing a set name does not re-trigger its switch.
        for (size_t i = 0; i < want.size(); ++i) {
            if (i < mSets.size()) {
                want[i].in = mSets[i].in;
                want[i].selectedKey = mSets[i].selectedKey;
            }
            auto it = mLoadedSel.find((int)i);
            if (want[i].selectedKey.empty() && it != mLoadedSel.end())
                want[i].selectedKey = it->second;
        }
        for (size_t i = want.size(); i < mSets.size(); ++i) mSets[i].in.release();
        mSets.swap(want);

        for (size_t i = 0; i < mMru.size();) {           // forget vanished sets
            if (mMru[i] >= (int)mSets.size()) mMru.erase(mMru.begin() + i);
            else ++i;
        }
        if (mActiveSet >= (int)mSets.size()) mActiveSet = -1;
        rebuildSetOrder();
    }

    // Which designs belong to the set that is currently switched on, in order.
    void rebuildSetOrder() {
        mSetOrder.clear();
        if (mActiveSet < 0) return;
        for (size_t i = 0; i < mDesigns.size(); ++i)
            if (mDesigns[i].set == mActiveSet) mSetOrder.push_back((int)i);
    }

    bool inMru(int i) const {
        return std::find(mMru.begin(), mMru.end(), i) != mMru.end();
    }
    void mruRemove(int i) {
        mMru.erase(std::remove(mMru.begin(), mMru.end(), i), mMru.end());
    }
    void mruTouch(int i) {                 // most recently switched on goes first
        mruRemove(i);
        mMru.insert(mMru.begin(), i);
    }

    // Resolve a remembered design, but never land on one the user has since
    // switched off - fall through to the first entry that is still enabled.
    int resolveStart() const {
        if (mActiveSet < 0) return -1;
        if (mResumeLast) {
            int idx = indexOfKey(mSets[mActiveSet].selectedKey);
            if (idx >= 0 && mDesigns[idx].enabled) return idx;
        }
        return firstEnabled();
    }

    // All of these work in terms of indexes into mDesigns, but only ever walk
    // mSetOrder - the designs belonging to the set whose switch is on.
    int indexOfKey(const std::string& key) const {
        if (key.empty()) return -1;
        for (int gi : mSetOrder)
            if (mDesigns[gi].key() == key) return gi;
        return -1;
    }
    int firstEnabled() const {
        for (int gi : mSetOrder)
            if (mDesigns[gi].enabled) return gi;
        return -1;
    }
    int posInOrder(int designIdx) const {
        for (size_t i = 0; i < mSetOrder.size(); ++i)
            if (mSetOrder[i] == designIdx) return (int)i;
        return -1;
    }
    // Step `dir` places through the enabled entries, honouring the wrap setting.
    int stepIndex(int from, int dir) const {
        int n = (int)mSetOrder.size();
        if (n == 0) return -1;
        int p = posInOrder(from);
        int base = (p < 0) ? (dir > 0 ? -1 : 0) : p;
        for (int i = 1; i <= n; ++i) {
            int c = base + dir * i;
            if (c < 0 || c >= n) {
                if (!mWrap) {
                    if (p >= 0 && mDesigns[mSetOrder[p]].enabled) return mSetOrder[p];
                    return firstEnabled();
                }
                c = ((c % n) + n) % n;
            }
            if (mDesigns[mSetOrder[c]].enabled) return mSetOrder[c];
        }
        return firstEnabled();
    }

    // ------------------------------------------------------------------- state
    // Read before the sets exist, so park the selections and let loadSets apply
    // them. "key" is the single-set format written by earlier versions.
    void loadState() {
        std::ifstream in(kStatePath.c_str());
        std::string line;
        while (std::getline(in, line)) {
            size_t sp = line.find(' ');
            if (sp == std::string::npos) continue;
            std::string k = line.substr(0, sp), v = trim(line.substr(sp + 1));
            auto unpack = [](const std::string& x) {
                size_t bar = x.find('|');            // "type|name" - no tab in the file
                return bar == std::string::npos ? std::string() : x.substr(0, bar) + "\t" + x.substr(bar + 1);
            };
            if (k == "key") {
                std::string kk = unpack(v);
                if (!kk.empty()) mLoadedSel[0] = kk;
            } else if (k == "sel") {
                size_t sp2 = v.find(' ');
                if (sp2 == std::string::npos) continue;
                int idx = (int)toLong(v.substr(0, sp2), -1);
                std::string kk = unpack(trim(v.substr(sp2 + 1)));
                if (idx >= 0 && !kk.empty()) mLoadedSel[idx] = kk;
            }
        }
    }
    void saveState() {
        FILE* f = fopen(kStatePath.c_str(), "w");
        if (!f) return;
        fprintf(f, "PIXELSELECT_STATE 2\n");
        for (size_t i = 0; i < mSets.size(); ++i) {
            if (mSets[i].selectedKey.empty()) continue;
            std::string v = mSets[i].selectedKey;
            size_t tab = v.find('\t');
            if (tab != std::string::npos) v[tab] = '|';
            fprintf(f, "sel %d %s\n", (int)i, v.c_str());
        }
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
        std::string resp;
        return fppdRequest("POST", "/command/" + urlEncode(command), body, resp);
    }

    // GET fppd's status so we can tell whether the player is still ours.
    // /fppd/status is served on 32322 by both FPP 5.4 and 9.x.
    bool fppdStatus(std::string& out) {
        return fppdRequest("GET", "/fppd/status", "", out);
    }

    bool fppdRequest(const char* verb, const std::string& path,
                     const std::string& body, std::string& out) {
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
        req << verb << " " << path << " HTTP/1.1\r\n"
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
        out.clear();
        char buf[2048];
        ssize_t got;
        while ((got = recv(fd, buf, sizeof(buf), 0)) > 0) {
            out.append(buf, (size_t)got);
            if (out.size() > 262144) break;      // status JSON is far smaller
        }
        close(fd);
        return out.find(" 200 ") != std::string::npos;
    }

    void startCurrent() {
        if (mIndex < 0 || mIndex >= (int)mDesigns.size()) return;
        const Design& d = mDesigns[mIndex];
        if (mActiveSet >= 0) mSets[mActiveSet].selectedKey = d.key();
        saveState();
        fppdCommand("Start Playlist", { d.name, mRepeat ? "true" : "false", "false" });
        mLastStart = std::chrono::steady_clock::now();
        mLostSince = {};
        mHandBackArmed = false;
        mPlaylistAt = {};          // force a fresh read on the next check
        mLastEvent = mLastStart;
    }

    void stopPlayback() {
        if (mStopMode == "graceful")      fppdCommand("Stop Gracefully", { "false" });
        else if (mStopMode == "afterloop") fppdCommand("Stop Gracefully", { "true" });
        else                               fppdCommand("Stop Now", {});
        mLostSince = {};
        mHandBackArmed = mHandBack;
        mHandBackSince = std::chrono::steady_clock::now();
        mHandBackTries = 0;
        mHandBackAt = {};
        mLastEvent = std::chrono::steady_clock::now();
    }

    bool isPlaying() const {
        return sequence != nullptr && sequence->IsSequenceRunning();
    }

    // Pull "current_playlist":{... "playlist":"NAME" ...} out of fppd's status
    // without a JSON library. Cached: the watchdog runs twice a second and this
    // is a socket round-trip.
    std::string currentPlaylist(std::chrono::steady_clock::time_point now) {
        if (mPlaylistAt.time_since_epoch().count() &&
            now - mPlaylistAt < std::chrono::milliseconds(1000))
            return mPlaylistName;
        mPlaylistAt = now;
        mPlaylistName.clear();
        mPlaylistKnown = false;

        std::string body;
        if (!fppdStatus(body)) return mPlaylistName;
        size_t cp = body.find("\"current_playlist\"");
        if (cp == std::string::npos) return mPlaylistName;
        size_t key = body.find("\"playlist\"", cp);
        if (key == std::string::npos) return mPlaylistName;
        size_t colon = body.find(':', key + 10);
        if (colon == std::string::npos) return mPlaylistName;
        size_t open = body.find('"', colon);
        if (open == std::string::npos) return mPlaylistName;
        std::string v;
        for (size_t i = open + 1; i < body.size(); ++i) {
            if (body[i] == '\\' && i + 1 < body.size()) { v += body[++i]; continue; }
            if (body[i] == '"') break;
            v += body[i];
        }
        mPlaylistName = v;
        mPlaylistKnown = true;
        return mPlaylistName;
    }

    // Is the player actually running OUR design, as opposed to a schedule, a
    // remote, or someone pressing play in the FPP UI?
    bool designIsPlaying(std::chrono::steady_clock::time_point now) {
        if (mIndex < 0 || mIndex >= (int)mDesigns.size()) return false;
        const Design& d = mDesigns[mIndex];
        if (d.type == "sequence")
            return sequence != nullptr && sequence->IsSequenceRunning(d.name) != 0;
        // A playlist walks through items, so the sequence name keeps changing -
        // compare the playlist name instead. If fppd will not tell us, fall back
        // to "something is playing" rather than fighting blind.
        std::string cur = currentPlaylist(now);
        if (!mPlaylistKnown) return isPlaying();
        return cur == d.name;
    }

    // ------------------------------------------------------------------ inputs
    void configureInputs() {
        std::string err, firstErr;
        for (auto& ds : mSets) {
            ds.in.configure(ds.pin, ds.pull, ds.activeLow, err);
            if (!err.empty() && firstErr.empty()) firstErr = err;
            err.clear();
        }
        mNextIn.configure(mNextPin, mNextPull, mNextLow, err);
        if (!err.empty() && firstErr.empty()) firstErr = err;
        mPinError = firstErr;
    }

    // Which set should be running. There must be an explicit reason to be
    // active: a configured switch is closed, or the software override is on.
    // With several switches closed at once the most recently closed one wins,
    // and releasing it falls back to whichever other one is still closed - which
    // is also exactly what a rotary selector needs.
    int wantedSet() const {
        if (!mPluginEnabled || mSets.empty()) return -1;
        if (mVirtualEnable) {
            int i = mVirtualSet;
            if (i < 0 || i >= (int)mSets.size()) i = 0;
            return i;
        }
        for (int i : mMru)
            if (i >= 0 && i < (int)mSets.size() && mSets[i].in.valid && mSets[i].in.stable)
                return i;
        return -1;
    }

    // Keep the most-recently-closed list in step with the real switch levels.
    // Done declaratively rather than on edges so a switch that is already closed
    // at boot is picked up too.
    void trackSwitches(std::chrono::steady_clock::time_point now) {
        for (size_t i = 0; i < mSets.size(); ++i) {
            mSets[i].in.sample(mDebounceMs, now);
            bool asserted = mSets[i].in.valid && mSets[i].in.stable;
            if (asserted && !inMru((int)i)) mruTouch((int)i);
            else if (!asserted && inMru((int)i)) mruRemove((int)i);
        }
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

        if (verb == "next")      { if (mActive) fireNext(); }
        else if (verb == "prev") { if (mActive) { int i = stepIndex(mIndex, -1); if (i >= 0) { mIndex = i; startCurrent(); } } }
        else if (verb == "select") {
            // The UI sends an index into the whole design list. Picking one that
            // belongs to a set that is not switched on just stages it for later.
            int i = (int)toLong(arg, -1);
            if (i >= 0 && i < (int)mDesigns.size()) {
                int sIdx = mDesigns[i].set;
                if (sIdx >= 0 && sIdx < (int)mSets.size()) {
                    mSets[sIdx].selectedKey = mDesigns[i].key();
                    saveState();
                }
                if (sIdx == mActiveSet) { mIndex = i; startCurrent(); }
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
        // The set the switches have chosen, plus every set's live pin state so
        // the UI can show a lamp per switch.
        std::string sets = "[";
        for (size_t i = 0; i < mSets.size(); ++i) {
            const DesignSet& ds = mSets[i];
            int n = 0;
            for (const auto& dd : mDesigns) if (dd.set == (int)i) n++;
            char buf[512];
            snprintf(buf, sizeof(buf),
                     "%s{\"name\":\"%s\",\"pin\":\"%s\",\"configured\":%s,\"ok\":%s,\"on\":%s,\"designs\":%d}",
                     i ? "," : "", jesc(ds.name).c_str(), jesc(ds.pin).c_str(),
                     ds.pin.empty() ? "false" : "true",
                     ds.in.valid ? "true" : "false",
                     (ds.in.valid && ds.in.stable) ? "true" : "false", n);
            sets += buf;
        }
        sets += "]";

        // The hero lamp speaks for the switches as a group: configured if any
        // set has a pin, ok only if every configured one actually resolved, and
        // on when one of them has given us a set to play.
        bool anyPin = false, allOk = true;
        for (const auto& ds : mSets) {
            if (ds.pin.empty()) continue;
            anyPin = true;
            if (!ds.in.valid) allOk = false;
        }
        const DesignSet* as = (mActiveSet >= 0 && mActiveSet < (int)mSets.size()) ? &mSets[mActiveSet] : nullptr;
        fprintf(f,
            "{\"live\":true,\"pluginEnabled\":%s,\"active\":%s,\"virtualEnable\":%s,"
            "\"activeSet\":%d,\"activeSetName\":\"%s\",\"sets\":%s,"
            "\"switchConfigured\":%s,\"switchOk\":%s,\"switchOn\":%s,"
            "\"buttonConfigured\":%s,\"buttonOk\":%s,\"buttonDown\":%s,"
            "\"index\":%d,\"pos\":%d,\"count\":%d,\"totalDesigns\":%d,\"setCount\":%d,\"label\":\"%s\",\"name\":\"%s\",\"type\":\"%s\","
            "\"playing\":%s,\"repeat\":%s,\"wrap\":%s,\"pinError\":\"%s\","
            "\"takeover\":%s,\"heldByOther\":%s,\"reclaims\":%ld,"
            "\"handBack\":%s,\"handBackPending\":%s,\"handedBack\":%ld,"
            "\"lastEventAgo\":%.1f,\"uptime\":%.1f}",
            mPluginEnabled ? "true" : "false",
            mActive ? "true" : "false",
            mVirtualEnable ? "true" : "false",
            mActiveSet,
            as ? jesc(as->name).c_str() : "",
            sets.c_str(),
            anyPin ? "true" : "false",
            (anyPin && allOk) ? "true" : "false",
            mActive ? "true" : "false",
            mNextPin.empty() ? "false" : "true",
            mNextIn.valid ? "true" : "false",
            mNextIn.stable ? "true" : "false",
            mIndex, posInOrder(mIndex), (int)mSetOrder.size(), (int)mDesigns.size(), (int)mSets.size(),
            d ? jesc(d->label).c_str() : "",
            d ? jesc(d->name).c_str() : "",
            d ? d->type.c_str() : "",
            isPlaying() ? "true" : "false",
            mRepeat ? "true" : "false",
            mWrap ? "true" : "false",
            jesc(mPinError).c_str(),
            mTakeover ? "true" : "false",
            mHeldByOther ? "true" : "false",
            mReclaims,
            mHandBack ? "true" : "false",
            mHandBackArmed ? "true" : "false",
            mHandedBack,
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
                loadSets(false);
                loadDesigns(false);
                configureInputs();
                writePins();
            }
            if (tick % 10 == 0) pollCommandFile();   // ~50 ms

            // Sample every input on every pass so debouncing is accurate.
            trackSwitches(now);
            bool btnChanged = mNextIn.sample(mDebounceMs, now);

            int wantSet = wantedSet();
            if (wantSet != mActiveSet) {
                mActiveSet = wantSet;
                mActive = (mActiveSet >= 0);
                rebuildSetOrder();
                if (mActive) {
                    mIndex = resolveStart();
                    if (mIndex >= 0) startCurrent();
                    else stopPlayback();          // this set has nothing to play
                } else {
                    mIndex = -1;
                    stopPlayback();
                }
            }

            if (btnChanged && mActive) handleButtonEdge(mNextIn.stable, now);
            else if (btnChanged) mLastEvent = now;

            if (tick % 100 == 0) {
                watchdog(now);
                handBackToScheduler(now);
                writeStatus();
            }
            if (++tick >= 1000) tick = 0;
        }
        if (mActive) stopPlayback();
    }

    // While the switch is on, the plugin owns the player. Two ways it can lose it:
    // playback simply stopped (a sequence ended, fppd restarted), or something
    // else took over (the scheduler, a remote, someone pressing play in the FPP
    // UI). "Keep it playing" covers the first, "take over" covers the second.
    //
    // Both are confirmed over two seconds before acting, and nothing happens for
    // three seconds after our own start, so the gap between playlist repeats
    // never triggers a reclaim and we cannot thrash against the scheduler.
    // Handing the player back to FPP's scheduler.
    //
    // FPP will not resume a mid-window scheduled playlist on its own once
    // something else has owned the player: the day's occurrence is marked as
    // "ran" and stays that way (a schedule reload does not clear it either).
    // So when the switch opens we ask the scheduler to look again, with
    // ignoreRepeat set so an already-ran in-window item is re-armed. FPP then
    // starts it through its normal scheduled path, which means the schedule
    // keeps its own end time and stop type - starting the playlist ourselves
    // would lose both.
    //
    // We wait for the player to actually go idle first, so a graceful stop
    // still gets to finish its item.
    void handBackToScheduler(std::chrono::steady_clock::time_point now) {
        if (!mHandBackArmed || mActive) return;
        if (now - mHandBackSince > std::chrono::minutes(10)) { mHandBackArmed = false; return; }
        if (isPlaying()) {
            // Either our stop is still winding down, or the schedule (or
            // something else) is already playing - either way, nothing to do.
            if (mHandBackTries > 0) mHandBackArmed = false;
            return;
        }
        if (mHandBackAt.time_since_epoch().count() &&
            now - mHandBackAt < std::chrono::seconds(2)) return;   // space out retries

        if (scheduler != nullptr) {
            scheduler->CheckIfShouldBePlayingNow(1, -1);
            mHandedBack++;
        }
        mHandBackAt = now;
        if (++mHandBackTries >= 3) mHandBackArmed = false;
    }

    void watchdog(std::chrono::steady_clock::time_point now) {
        if (!mActive || mIndex < 0) { mLostSince = {}; mHeldByOther = false; return; }
        if (now - mLastStart < std::chrono::seconds(3)) return;

        if (designIsPlaying(now)) { mLostSince = {}; mHeldByOther = false; return; }

        bool somethingElse = isPlaying();
        mHeldByOther = somethingElse;
        if (somethingElse ? !mTakeover : !mKeepPlaying) { mLostSince = {}; return; }

        if (mLostSince.time_since_epoch().count() == 0) { mLostSince = now; return; }
        if (now - mLostSince >= std::chrono::seconds(2)) {
            mReclaims++;
            startCurrent();
        }
    }

    // --- settings ---
    bool mPluginEnabled = false, mVirtualEnable = false;
    std::string mEnablePin, mEnablePull = "gpio_pu", mNextPin, mNextPull = "gpio_pu";
    bool mEnableLow = true, mNextLow = true;
    long mDebounceMs = 30, mLongPressMs = 1200;
    std::string mLongAction = "none", mStopMode = "now";
    bool mRepeat = true, mWrap = true, mResumeLast = true, mKeepPlaying = true;
    bool mTakeover = true, mHandBack = true;

    int mVirtualSet = 0;            // which set the software override stands in for

    // --- runtime ---
    std::vector<Design> mDesigns;
    long long mDesignsStamp = -1;
    std::vector<DesignSet> mSets;   // one per toggle switch
    long long mSetsStamp = -1;
    std::vector<int> mSetOrder;     // designs of the active set, in list order
    std::vector<int> mMru;          // set indexes, most recently switched on first
    int mActiveSet = -1;
    std::map<int, std::string> mLoadedSel;   // per-set selection read at startup
    std::string mLegacyPin, mLegacyPull;     // last single-switch settings seen
    bool mLegacyLow = true;
    int mIndex = -1;
    bool mActive = false;
    std::string mPinError;
    bool mPinsWritten = false;
    DebouncedInput mNextIn;
    std::chrono::steady_clock::time_point mPressStart{}, mLastStart{}, mLostSince{},
                                          mLastEvent{}, mStart{}, mPlaylistAt{};
    std::string mPlaylistName;      // what fppd says is playing (playlist designs)
    bool mPlaylistKnown = false;
    bool mHeldByOther = false;      // something else has the player right now
    long mReclaims = 0;             // how many times we have taken it back
    bool mHandBackArmed = false;    // owe the scheduler a nudge after letting go
    int  mHandBackTries = 0;
    long mHandedBack = 0;
    std::chrono::steady_clock::time_point mHandBackSince{}, mHandBackAt{};

    std::thread mWorker;
    std::mutex mMx;
    std::condition_variable mCv;
    bool mStop = false;
};

extern "C" {
FPPPlugin* createPlugin() { return new PixelSelectPlugin(); }
}
