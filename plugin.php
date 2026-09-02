<?php
/*
 * Settings page for the "pixelselect" plugin.
 *
 * Everything on this page is driven by two endpoints in this directory:
 *   action.php  writes settings / the design list, and fires the virtual button
 *   status.php  live state from the running plugin (/dev/shm snapshot)
 * The page renders from action.php?action=lists on load, so it never depends on
 * FPP having injected $pluginSettings.
 */
require_once(dirname(__FILE__) . '/lib/common.php');
$ps_cfg = ps_cfg_read();
?>
<style>
#ps{
  --bg:#ffffff; --bg2:#f7f9fc; --line:#e3e8ef; --line2:#eef1f6;
  --tx:#1b2230; --tx2:#5b6675; --tx3:#8a93a3;
  --acc:#3b6ef0; --acc2:#eaf0ff;
  --ok:#17a06a; --ok2:#e7f6ef; --warn:#d98324; --warn2:#fdf3e5; --bad:#d4453e; --bad2:#fdecec;
  --shadow:0 1px 2px rgba(16,24,40,.05),0 1px 3px rgba(16,24,40,.06);
  max-width:940px;margin:0 auto;color:var(--tx);
  font-size:14px;line-height:1.45;
  -webkit-font-smoothing:antialiased;
}
/* Dark tokens are keyed off the host page, not the OS: FPP 9 marks its own dark
   mode with data-bs-theme, FPP 5.4 is light unless a custom theme says otherwise,
   and either can disagree with prefers-color-scheme. The attribute below is set
   synchronously from the host page's real background - see the script by #ps. */
#ps[data-theme="dark"]{--bg:#161b23;--bg2:#1c222c;--line:#2b333f;--line2:#242b35;
      --tx:#e8ecf2;--tx2:#a4aebd;--tx3:#7c8697;--acc:#6f97ff;--acc2:#22304f;
      --ok:#3ec98d;--ok2:#16332a;--warn:#e0a052;--warn2:#33291a;--bad:#f0736c;--bad2:#3a2020;
      --shadow:0 1px 2px rgba(0,0,0,.3);}
#ps *{box-sizing:border-box}
#ps h2{font-size:19px;font-weight:650;margin:0;letter-spacing:-.01em;color:var(--tx)}
#ps .muted{color:var(--tx2)}
#ps .tiny{font-size:12.5px;color:var(--tx2)}

#ps .topbar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:0 0 6px}
#ps .topbar .grow{flex:1}
#ps .lede{color:var(--tx2);font-size:13.5px;margin:0 0 18px;max-width:70ch}

#ps .card{border:1px solid var(--line);border-radius:14px;background:var(--bg);
  box-shadow:var(--shadow);margin:0 0 16px;overflow:hidden}
#ps .card>.hd{display:flex;align-items:center;gap:10px;padding:13px 18px;
  background:var(--bg2);border-bottom:1px solid var(--line)}
#ps .card>.hd .t{font-size:14.5px;font-weight:640;flex:1;letter-spacing:-.005em}
#ps .card>.bd{padding:18px}

/* hero */
#ps .hero{border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);
  background:linear-gradient(135deg,var(--bg2),var(--bg));padding:20px;margin:0 0 16px;
  display:flex;gap:22px;align-items:center;flex-wrap:wrap}
#ps .hero .now{flex:1;min-width:250px}
#ps .hero .eyebrow{font-size:11.5px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--tx3)}
#ps .hero .title{font-size:27px;font-weight:680;line-height:1.15;margin:5px 0 4px;letter-spacing:-.02em;
  overflow-wrap:anywhere}
#ps .hero .sub{font-size:13px;color:var(--tx2)}
#ps .lamps{display:flex;gap:12px}
#ps .lamp{border:1px solid var(--line);border-radius:12px;background:var(--bg);
  padding:11px 13px;min-width:112px;text-align:center}
#ps .lamp .led{width:15px;height:15px;border-radius:50%;margin:2px auto 8px;
  background:var(--line);box-shadow:inset 0 0 0 1px rgba(0,0,0,.06);transition:.15s}
#ps .lamp.on .led{background:var(--ok);box-shadow:0 0 0 4px var(--ok2)}
#ps .lamp.hit .led{background:var(--acc);box-shadow:0 0 0 4px var(--acc2)}
#ps .lamp.err .led{background:var(--bad);box-shadow:0 0 0 4px var(--bad2)}
#ps .lamp .nm{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--tx3)}
#ps .lamp .vl{font-size:12.5px;font-weight:600;margin-top:2px}

#ps .pill{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:99px;
  font-size:12px;font-weight:650;white-space:nowrap}
#ps .pill .dot{width:8px;height:8px;border-radius:50%;background:currentColor;flex:none}
#ps .pill.ok{background:var(--ok2);color:var(--ok)}
#ps .pill.warn{background:var(--warn2);color:var(--warn)}
#ps .pill.bad{background:var(--bad2);color:var(--bad)}
#ps .pill.off{background:var(--line2);color:var(--tx2)}

/* controls */
#ps button{font:inherit;padding:7px 13px;border:1px solid transparent;border-radius:9px;
  background:var(--acc);color:#fff;font-weight:600;cursor:pointer;transition:.12s;font-size:13.5px}
#ps button:hover{filter:brightness(1.07)}
#ps button:active{transform:translateY(1px)}
#ps button.sec{background:var(--bg);color:var(--tx);border-color:var(--line)}
#ps button.sec:hover{background:var(--bg2)}
#ps button.ghost{background:transparent;color:var(--tx2);border-color:transparent;padding:5px 8px}
#ps button.ghost:hover{background:var(--line2);color:var(--tx)}
#ps button.danger{background:transparent;color:var(--bad);border-color:transparent;padding:5px 8px}
#ps button.danger:hover{background:var(--bad2)}
#ps button:disabled{opacity:.45;cursor:not-allowed;transform:none;filter:none}
#ps input[type=text],#ps input[type=number],#ps select{font:inherit;font-size:13.5px;
  padding:7px 10px;border:1px solid var(--line);border-radius:9px;background:var(--bg);
  color:var(--tx);max-width:100%}
#ps input:focus,#ps select:focus{outline:2px solid var(--acc2);outline-offset:0;border-color:var(--acc)}
#ps .sw{position:relative;display:inline-block;width:44px;height:24px;flex:none}
#ps .sw input{opacity:0;width:0;height:0}
#ps .sw .sl{position:absolute;cursor:pointer;inset:0;background:var(--line);border-radius:99px;transition:.16s}
#ps .sw .sl:before{content:"";position:absolute;height:18px;width:18px;left:3px;top:3px;
  background:#fff;border-radius:50%;transition:.16s;box-shadow:0 1px 2px rgba(0,0,0,.25)}
#ps .sw input:checked + .sl{background:var(--ok)}
#ps .sw input:checked + .sl:before{transform:translateX(20px)}

#ps .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:16px 20px}
#ps .fld{display:flex;flex-direction:column;gap:5px}
#ps .fld > label{font-size:12.5px;font-weight:600;color:var(--tx2)}
#ps .fld .hint{font-size:12px;color:var(--tx3);line-height:1.35}
#ps .rowline{display:flex;align-items:center;gap:11px}

/* design list */
#ps .dl{display:flex;flex-direction:column;gap:8px}
#ps .d{display:flex;align-items:center;gap:11px;padding:10px 12px;border:1px solid var(--line);
  border-radius:11px;background:var(--bg);transition:.12s}
#ps .d.cur{border-color:var(--acc);background:var(--acc2)}
#ps .d.off{opacity:.55}
#ps .d.dragover{border-style:dashed;border-color:var(--acc)}
#ps .d .hnd{cursor:grab;color:var(--tx3);font-size:15px;line-height:1;user-select:none;padding:2px 3px}
#ps .d .idx{font-size:11.5px;font-weight:700;color:var(--tx3);width:19px;text-align:right;
  font-variant-numeric:tabular-nums;flex:none}
#ps .d .mid{flex:1;min-width:0}
#ps .d .lb{font-weight:600;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#ps .d .lb input{border:1px solid transparent;background:transparent;padding:2px 5px;
  border-radius:6px;font-weight:600;font-size:14px;width:100%}
#ps .d .lb input:hover{border-color:var(--line)}
#ps .d .src{font-size:12px;color:var(--tx2);display:flex;align-items:center;gap:7px;margin-top:1px}
#ps .tag{font-size:10px;font-weight:750;letter-spacing:.06em;text-transform:uppercase;
  padding:2px 6px;border-radius:5px;background:var(--line2);color:var(--tx2);flex:none}
#ps .tag.seq{background:var(--acc2);color:var(--acc)}
#ps .tag.miss{background:var(--bad2);color:var(--bad)}
#ps .d .acts{display:flex;align-items:center;gap:3px;flex:none}
#ps .empty{text-align:center;padding:26px 16px;color:var(--tx2);border:1px dashed var(--line);
  border-radius:11px;font-size:13.5px}
#ps .addbar{display:flex;gap:9px;align-items:flex-end;flex-wrap:wrap;margin-top:14px;
  padding-top:15px;border-top:1px solid var(--line2)}
#ps .addbar .fld{flex:1;min-width:150px}

#ps .banner{display:flex;gap:10px;align-items:flex-start;padding:11px 14px;border-radius:11px;
  font-size:13px;margin:0 0 16px;border:1px solid transparent}
#ps .banner.warn{background:var(--warn2);border-color:var(--warn);color:var(--warn)}
#ps .banner.bad{background:var(--bad2);border-color:var(--bad);color:var(--bad)}
#ps .banner b{font-weight:700}
#ps .toast{position:fixed;right:18px;bottom:18px;z-index:9999;background:var(--tx);color:var(--bg);
  padding:9px 15px;border-radius:10px;font-size:13px;font-weight:600;opacity:0;
  transform:translateY(8px);transition:.18s;pointer-events:none;box-shadow:0 6px 20px rgba(0,0,0,.2)}
#ps .toast.show{opacity:1;transform:none}
#ps .toast.bad{background:var(--bad);color:#fff}

#ps details.help{border:1px solid var(--line);border-radius:14px;background:var(--bg);
  box-shadow:var(--shadow);overflow:hidden}
#ps details.help>summary{padding:13px 18px;background:var(--bg2);font-weight:640;cursor:pointer;
  font-size:14.5px;list-style:none;display:flex;align-items:center;gap:8px}
#ps details.help>summary::-webkit-details-marker{display:none}
#ps details.help>summary:before{content:"›";display:inline-block;transition:.15s;font-size:17px;color:var(--tx3)}
#ps details.help[open]>summary:before{transform:rotate(90deg)}
#ps details.help .bd{padding:18px;border-top:1px solid var(--line)}
#ps .wire{width:100%;max-width:430px;height:auto;display:block;margin:0 auto 14px}
#ps code{background:var(--line2);padding:1px 5px;border-radius:5px;font-size:12.5px}
#ps ol,#ps ul{padding-left:20px;margin:8px 0}
#ps li{margin:4px 0}
</style>

<div id="ps">
<script>
// Match whatever FPP is wearing. FPP 9 sets data-bs-theme on <html>; older FPP and
// custom themes only differ by the page background, so fall back to measuring it.
// Runs inline (not on DOMContentLoaded) so the page never flashes the wrong palette.
(function(){
  var el = document.getElementById('ps'), dark = null;
  if (document.documentElement.getAttribute('data-bs-theme') === 'dark') dark = true;
  if (dark === null) {
    for (var n = el.parentNode; n && n.nodeType === 1; n = n.parentNode) {
      var bg = getComputedStyle(n).backgroundColor || '';
      var m = bg.match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/);
      if (!m || (m[4] !== undefined && parseFloat(m[4]) === 0)) continue;  // transparent, keep looking
      dark = (0.299*m[1] + 0.587*m[2] + 0.114*m[3]) < 128;
      break;
    }
  }
  if (dark === null && window.matchMedia)          // nothing painted a background
    dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  if (dark) el.setAttribute('data-theme', 'dark');
})();
</script>
  <div class="topbar">
    <h2>PixelSelect</h2>
    <span id="ps-live" class="pill off"><span class="dot"></span>checking…</span>
    <span class="grow"></span>
    <span class="tiny" style="font-weight:600">Plugin</span>
    <label class="sw"><input type="checkbox" id="ps-enabled" <?php echo $ps_cfg['enabled'] === '1' ? 'checked' : ''; ?>><span class="sl"></span></label>
  </div>
  <p class="lede">Two GPIO pins drive the show. A <b>toggle switch</b> hands playback to the plugin;
  a <b>pushbutton</b> steps through your ordered list of designs. Whatever is selected keeps looping
  until you press the button again or flip the switch off.</p>

  <div id="ps-banner"></div>

  <div class="hero">
    <div class="now">
      <div class="eyebrow">Now playing</div>
      <div class="title" id="ps-title">—</div>
      <div class="sub" id="ps-sub">Waiting for status from the plugin…</div>
      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
        <button class="sec" id="ps-btn-next">Next design ›</button>
        <button class="sec" id="ps-btn-restart">Restart</button>
        <button class="sec" id="ps-btn-stop">Stop</button>
      </div>
    </div>
    <div class="lamps">
      <div class="lamp" id="ps-lamp-sw"><div class="nm">Switch</div><div class="led"></div><div class="vl" id="ps-sw-v">—</div></div>
      <div class="lamp" id="ps-lamp-bt"><div class="nm">Button</div><div class="led"></div><div class="vl" id="ps-bt-v">—</div></div>
    </div>
  </div>

  <div class="card">
    <div class="hd"><span class="t">Designs</span><span class="tiny" id="ps-dcount"></span></div>
    <div class="bd">
      <div class="dl" id="ps-list"></div>
      <div class="addbar">
        <div class="fld" style="max-width:150px">
          <label>Type</label>
          <select id="ps-add-type"><option value="sequence">Sequence</option><option value="playlist">Playlist</option></select>
        </div>
        <div class="fld" style="flex:2">
          <label id="ps-add-namelab">Sequence</label>
          <select id="ps-add-name"></select>
        </div>
        <div class="fld">
          <label>Name shown here (optional)</label>
          <input type="text" id="ps-add-label" placeholder="e.g. Snowfall">
        </div>
        <button id="ps-add-btn">Add design</button>
      </div>
      <div class="tiny" style="margin-top:10px">Drag to reorder — the pushbutton walks this list top to bottom.
      A sequence plays on its own (FPP loops it and picks up the audio named in the&nbsp;fseq); a playlist plays as you built it.</div>
    </div>
  </div>

  <div class="card">
    <div class="hd"><span class="t">Inputs</span></div>
    <div class="bd">
      <div class="grid">
        <div class="fld">
          <label>Enable pin — toggle switch</label>
          <select id="ps-enable_pin"></select>
          <div class="hint">Held in one position = the plugin runs the show. With no pin set, the plugin stays out of the way unless the software override below is on.</div>
        </div>
        <div class="fld">
          <label>Switch closes to</label>
          <select id="ps-enable_active_low">
            <option value="1">Ground (active low)</option>
            <option value="0">3.3&nbsp;V (active high)</option>
          </select>
        </div>
        <div class="fld">
          <label>Switch resistor</label>
          <select id="ps-enable_pull">
            <option value="gpio_pu">Internal pull-up</option>
            <option value="gpio_pd">Internal pull-down</option>
            <option value="gpio">None / external</option>
          </select>
        </div>

        <div class="fld">
          <label>Next pin — pushbutton</label>
          <select id="ps-next_pin"></select>
          <div class="hint">Momentary press = jump to the next design.</div>
        </div>
        <div class="fld">
          <label>Button closes to</label>
          <select id="ps-next_active_low">
            <option value="1">Ground (active low)</option>
            <option value="0">3.3&nbsp;V (active high)</option>
          </select>
        </div>
        <div class="fld">
          <label>Button resistor</label>
          <select id="ps-next_pull">
            <option value="gpio_pu">Internal pull-up</option>
            <option value="gpio_pd">Internal pull-down</option>
            <option value="gpio">None / external</option>
          </select>
        </div>

        <div class="fld">
          <label>Debounce</label>
          <div class="rowline"><input type="number" id="ps-debounce_ms" min="1" max="1000" step="1" style="width:96px"><span class="tiny">ms</span></div>
          <div class="hint">Raise it if one press advances two designs.</div>
        </div>
        <div class="fld">
          <label>Software override</label>
          <div class="rowline">
            <label class="sw"><input type="checkbox" id="ps-virtual_enable"><span class="sl"></span></label>
            <span class="tiny">Act as if the switch were on</span>
          </div>
          <div class="hint">For testing before the switch is wired.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="hd"><span class="t">Behaviour</span></div>
    <div class="bd">
      <div class="grid">
        <div class="fld">
          <label>Loop the selected design</label>
          <div class="rowline"><label class="sw"><input type="checkbox" id="ps-repeat"><span class="sl"></span></label><span class="tiny">Repeat until changed</span></div>
        </div>
        <div class="fld">
          <label>Wrap around</label>
          <div class="rowline"><label class="sw"><input type="checkbox" id="ps-wrap"><span class="sl"></span></label><span class="tiny">Last › first</span></div>
        </div>
        <div class="fld">
          <label>Remember the last design</label>
          <div class="rowline"><label class="sw"><input type="checkbox" id="ps-resume_last"><span class="sl"></span></label><span class="tiny">Resume it when the switch goes on</span></div>
        </div>
        <div class="fld">
          <label>Keep it playing</label>
          <div class="rowline"><label class="sw"><input type="checkbox" id="ps-keep_playing"><span class="sl"></span></label><span class="tiny">Restart if playback ever stops</span></div>
        </div>
        <div class="fld">
          <label>Back to the schedule on release</label>
          <div class="rowline"><label class="sw"><input type="checkbox" id="ps-hand_back"><span class="sl"></span></label><span class="tiny">Hand the player back</span></div>
          <div class="hint">When the switch opens, nudge FPP's scheduler so a schedule that is still inside its window picks up again.</div>
        </div>
        <div class="fld">
          <label>Override everything else</label>
          <div class="rowline"><label class="sw"><input type="checkbox" id="ps-takeover"><span class="sl"></span></label><span class="tiny">Take the player back</span></div>
          <div class="hint">While the switch is on, the plugin wins over a schedule, a remote, or anyone pressing play in the FPP UI.</div>
        </div>
        <div class="fld">
          <label>When the switch goes off</label>
          <select id="ps-stop_mode">
            <option value="now">Stop immediately</option>
            <option value="graceful">Finish the current item</option>
            <option value="afterloop">Finish the current loop</option>
          </select>
        </div>
        <div class="fld">
          <label>Long press does</label>
          <select id="ps-long_press_action">
            <option value="none">Nothing (fire on press)</option>
            <option value="first">Jump to the first design</option>
            <option value="prev">Go to the previous design</option>
            <option value="restart">Restart the current design</option>
          </select>
          <div class="rowline" id="ps-lp-row"><span class="tiny">held for</span><input type="number" id="ps-long_press_ms" min="200" max="10000" step="100" style="width:96px"><span class="tiny">ms</span></div>
          <div class="hint">With a long press set, a short press acts on release instead.</div>
        </div>
      </div>
    </div>
  </div>

  <details class="help">
    <summary>Wiring &amp; troubleshooting</summary>
    <div class="bd">
      <svg class="wire" viewBox="0 0 400 190" role="img" aria-label="Wiring diagram: a toggle switch and a pushbutton, each between a GPIO pin and ground">
        <g fill="none" stroke="currentColor" stroke-width="1.6" opacity=".85">
          <rect x="12" y="24" width="86" height="142" rx="9" stroke-opacity=".5"/>
          <path d="M98 58h58M98 122h58M98 158h74"/>
          <path d="M232 58h46v34M232 122h46v-34M278 92v44"/>
          <path d="M172 158h106v-22"/>
          <path d="M262 150h32M268 158h20M273 166h10"/>
        </g>
        <g stroke="currentColor" stroke-width="1.6" fill="none">
          <circle cx="164" cy="58" r="4"/><circle cx="224" cy="58" r="4"/>
          <path d="M167 55l54-9" stroke-linecap="round"/>
          <circle cx="164" cy="122" r="4"/><circle cx="224" cy="122" r="4"/>
          <path d="M168 122h52M186 108v9M202 108v9M186 108h16" stroke-linecap="round"/>
        </g>
        <g font-size="10.5" fill="currentColor" font-family="inherit">
          <text x="55" y="20" text-anchor="middle" opacity=".65">Pi / BBB</text>
          <text x="104" y="52" opacity=".9">enable pin</text>
          <text x="104" y="116" opacity=".9">next pin</text>
          <text x="104" y="152" opacity=".9">GND</text>
          <text x="150" y="44" opacity=".65">toggle</text>
          <text x="150" y="99" opacity=".65">button</text>
        </g>
      </svg>
      <p class="tiny" style="text-align:center;margin:-4px 0 16px">Both switches sit between their pin and ground — the default
      “closes to ground” + “internal pull-up” settings match this.</p>
      <ol>
        <li>Pick two free pins above. They must not also be listed on
          <b>Input/Output Setup → GPIO Inputs</b>, or FPP will grab them first.</li>
        <li>Wire the toggle switch between the enable pin and GND, the pushbutton between the next pin and GND.</li>
        <li>Watch the two lamps at the top of this page — they follow the pins live, so you can confirm the
          wiring before you add a single design.</li>
        <li>If a press advances two designs, raise <b>Debounce</b>. If nothing happens at all, check that
          the plugin badge says <b>live</b> — that needs fppd restarted after installing.</li>
      </ol>
      <p class="tiny">Beagle pin names look like <code>P8-11</code> / <code>P9-15</code>, Raspberry Pi like
      <code>P1-11</code>. The list above comes from FPP itself, so it only offers pins this board really has.</p>
    </div>
  </details>
</div>
<div class="toast" id="ps-toast"></div>

<script>
(function(){
  var BASE = 'plugin.php?plugin=pixelselect&page=';
  var D = [], PINS = [], SEQ = [], PL = [], CFG = {}, saveTimer = null, dragFrom = -1;
  function $(id){ return document.getElementById(id); }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  function toast(msg, bad){
    var t = $('ps-toast');
    t.textContent = msg; t.className = 'toast show' + (bad ? ' bad' : '');
    clearTimeout(t._t); t._t = setTimeout(function(){ t.className = 'toast'; }, 2200);
  }
  function post(action, data, cb){
    var fd = new FormData(); fd.append('action', action);
    for (var k in data) fd.append(k, data[k]);
    fetch(BASE + 'action.php&nopage=1', {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(cb || function(){})
      .catch(function(){ (cb||function(){})({ok:false, error:'Request failed'}); });
  }

  /* ---------------------------------------------------------------- settings */
  var BOOLS = ['enabled','virtual_enable','repeat','wrap','resume_last','keep_playing','takeover','hand_back'];
  var VALS  = ['enable_pin','enable_active_low','enable_pull','next_pin','next_active_low',
               'next_pull','debounce_ms','stop_mode','long_press_action','long_press_ms'];

  function applyConfig(c){
    CFG = c;
    BOOLS.forEach(function(k){ var e = $('ps-'+k); if (e) e.checked = (c[k] === '1'); });
    VALS.forEach(function(k){ var e = $('ps-'+k); if (e && c[k] !== undefined) e.value = c[k]; });
    syncLongPress();
  }
  function syncLongPress(){
    var on = $('ps-long_press_action').value !== 'none';
    $('ps-lp-row').style.display = on ? '' : 'none';
  }
  function saveSetting(k, v){
    var d = {}; d[k] = v;
    post('save', d, function(r){
      if (r.ok) { CFG = r.config; toast('Saved'); }
      else toast(r.error || 'Could not save', true);
    });
  }
  function bindSettings(){
    BOOLS.forEach(function(k){
      var e = $('ps-'+k); if (!e) return;
      e.addEventListener('change', function(){ saveSetting(k, e.checked ? '1' : '0'); });
    });
    VALS.forEach(function(k){
      var e = $('ps-'+k); if (!e) return;
      e.addEventListener('change', function(){
        saveSetting(k, e.value);
        if (k === 'long_press_action') syncLongPress();
      });
    });
  }

  /* ----------------------------------------------------------------- designs */
  function saveDesigns(cb){
    clearTimeout(saveTimer);
    post('designs', {designs: JSON.stringify(D)}, function(r){
      if (r.ok) { D = r.designs; render(); toast('Design list saved'); }
      else toast(r.error || 'Could not save the list', true);
      if (cb) cb(r);
    });
  }
  function queueSave(){ clearTimeout(saveTimer); saveTimer = setTimeout(saveDesigns, 600); }

  function render(){
    var wrap = $('ps-list');
    $('ps-dcount').textContent = D.length ? (D.length + (D.length === 1 ? ' design' : ' designs')) : '';
    if (!D.length){
      wrap.innerHTML = '<div class="empty">No designs yet. Add one below — the pushbutton walks this list in order.</div>';
      return;
    }
    var h = '';
    D.forEach(function(d, i){
      h += '<div class="d' + (d.enabled ? '' : ' off') + '" data-i="' + i + '" draggable="true">' +
             '<span class="hnd" title="Drag to reorder">⣿</span>' +
             '<span class="idx">' + (i+1) + '</span>' +
             '<div class="mid">' +
               '<div class="lb"><input type="text" value="' + esc(d.label) + '" data-lab="' + i + '" ' +
                 'aria-label="Name for design ' + (i+1) + '"></div>' +
               '<div class="src">' +
                 '<span class="tag ' + (d.type === 'sequence' ? 'seq' : '') + '">' + (d.type === 'sequence' ? 'seq' : 'list') + '</span>' +
                 '<span>' + esc(d.name) + '</span>' +
                 (d.missing ? '<span class="tag miss">missing</span>' : '') +
               '</div>' +
             '</div>' +
             '<div class="acts">' +
               '<button class="ghost" data-play="' + i + '" title="Play this now">▶</button>' +
               '<label class="sw" title="Include in the button order"><input type="checkbox" data-en="' + i + '"' +
                 (d.enabled ? ' checked' : '') + '><span class="sl"></span></label>' +
               '<button class="danger" data-del="' + i + '" title="Remove">✕</button>' +
             '</div>' +
           '</div>';
    });
    wrap.innerHTML = h;
    wire(wrap);
    markCurrent();
  }

  function wire(wrap){
    wrap.querySelectorAll('[data-lab]').forEach(function(e){
      e.addEventListener('input', function(){ D[+e.dataset.lab].label = e.value; queueSave(); });
    });
    wrap.querySelectorAll('[data-en]').forEach(function(e){
      e.addEventListener('change', function(){ D[+e.dataset.en].enabled = e.checked; saveDesigns(); });
    });
    wrap.querySelectorAll('[data-del]').forEach(function(e){
      e.addEventListener('click', function(){ D.splice(+e.dataset.del, 1); saveDesigns(); });
    });
    wrap.querySelectorAll('[data-play]').forEach(function(e){
      e.addEventListener('click', function(){
        var i = +e.dataset.play;
        post('cmd', {cmd: 'select ' + i}, function(r){
          toast(r.ok ? 'Selected ' + (D[i] ? D[i].label : '') : (r.error || 'Failed'), !r.ok);
        });
      });
    });
    wrap.querySelectorAll('.d').forEach(function(row){
      row.addEventListener('dragstart', function(ev){
        dragFrom = +row.dataset.i; ev.dataTransfer.effectAllowed = 'move';
        try { ev.dataTransfer.setData('text/plain', String(dragFrom)); } catch(e){}
      });
      row.addEventListener('dragover', function(ev){ ev.preventDefault(); row.classList.add('dragover'); });
      row.addEventListener('dragleave', function(){ row.classList.remove('dragover'); });
      row.addEventListener('drop', function(ev){
        ev.preventDefault(); row.classList.remove('dragover');
        var to = +row.dataset.i;
        if (dragFrom < 0 || to === dragFrom) return;
        D.splice(to, 0, D.splice(dragFrom, 1)[0]);
        dragFrom = -1;
        saveDesigns();
      });
    });
  }

  function markCurrent(){
    var cur = window._psIndex;
    document.querySelectorAll('#ps-list .d').forEach(function(r){
      r.classList.toggle('cur', +r.dataset.i === cur);
    });
  }

  /* --------------------------------------------------------------- add + pins */
  function fillAddNames(){
    var t = $('ps-add-type').value;
    var src = (t === 'playlist') ? PL : SEQ;
    $('ps-add-namelab').textContent = (t === 'playlist') ? 'Playlist' : 'Sequence';
    $('ps-add-name').innerHTML = src.length
      ? src.map(function(n){ return '<option value="' + esc(n) + '">' + esc(n) + '</option>'; }).join('')
      : '<option value="">— none found on this device —</option>';
    $('ps-add-btn').disabled = !src.length;
  }
  function fillPins(){
    ['enable_pin','next_pin'].forEach(function(k){
      var sel = $('ps-' + k), cur = CFG[k] || '';
      var opts = '<option value="">— not used —</option>';
      PINS.forEach(function(p){ opts += '<option value="' + esc(p.pin) + '">' + esc(p.pin) + '</option>'; });
      if (cur && !PINS.some(function(p){ return p.pin === cur; }))
        opts += '<option value="' + esc(cur) + '">' + esc(cur) + ' (not on this board)</option>';
      sel.innerHTML = opts;
      sel.value = cur;
    });
  }

  /* ------------------------------------------------------------------ status */
  function banner(html, kind){
    $('ps-banner').innerHTML = html ? '<div class="banner ' + kind + '">' + html + '</div>' : '';
  }
  function poll(){
    fetch(BASE + 'status.php&nopage=1', {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(s){
        var live = $('ps-live');
        if (!s.live){
          live.className = 'pill bad'; live.innerHTML = '<span class="dot"></span>not running';
          banner('<span><b>The plugin is not loaded.</b> Restart FPP (or fppd) after installing — until then '
               + 'the switch and button do nothing and this page cannot show live pin state.</span>', 'bad');
          $('ps-title').textContent = '—';
          $('ps-sub').textContent = 'No live data from fppd.';
          ['sw','bt'].forEach(function(x){ $('ps-lamp-'+x).className = 'lamp'; $('ps-'+x+'-v').textContent = '—'; });
          return;
        }
        live.className = 'pill ok'; live.innerHTML = '<span class="dot"></span>live';

        if (s.pinError) banner('<span><b>Pin problem:</b> ' + esc(s.pinError) + '</span>', 'bad');
        else if (!s.pluginEnabled) banner('<span>The plugin is switched off, so the inputs are ignored. '
               + 'Turn it on with the toggle at the top right.</span>', 'warn');
        else if (!s.count) banner('<span>No designs in the list yet — add at least one below.</span>', 'warn');
        else banner('', '');

        window._psIndex = s.index;
        $('ps-title').textContent = (s.index >= 0 && s.label) ? s.label : (s.count ? 'Nothing selected' : 'No designs');
        var bits = [];
        if (!s.pluginEnabled) bits.push('plugin off');
        else if (!s.active) bits.push('waiting for the switch');
        else bits.push(s.playing ? 'playing' : 'starting…');
        if (s.name) bits.push(s.name);
        if (s.count) bits.push((s.index + 1) + ' of ' + s.count);
        if (s.active && s.repeat) bits.push('looping');
        if (s.active && s.heldByOther) bits.push(s.takeover ? 'taking the player back…' : 'something else has the player');
        if (s.reclaims) bits.push('took over ' + s.reclaims + '\u00d7');
        if (!s.active && s.handBackPending) bits.push('handing back to the schedule…');
        $('ps-sub').textContent = bits.join(' · ');

        var sw = $('ps-lamp-sw');
        sw.className = 'lamp' + (!s.switchConfigured ? '' : (!s.switchOk ? ' err' : (s.switchOn ? ' on' : '')));
        $('ps-sw-v').textContent = !s.switchConfigured ? (s.virtualEnable ? 'override' : 'no pin')
                                 : (!s.switchOk ? 'error' : (s.switchOn ? 'on' : 'off'));
        var bt = $('ps-lamp-bt');
        bt.className = 'lamp' + (!s.buttonConfigured ? '' : (!s.buttonOk ? ' err' : (s.buttonDown ? ' hit' : '')));
        $('ps-bt-v').textContent = !s.buttonConfigured ? 'no pin'
                                 : (!s.buttonOk ? 'error' : (s.buttonDown ? 'pressed' : 'ready'));
        markCurrent();
      })
      .catch(function(){});
  }

  /* -------------------------------------------------------------------- init */
  function load(){
    post('lists', {}, function(r){
      if (!r.ok){ toast('Could not load plugin data', true); return; }
      SEQ = r.sequences || []; PL = r.playlists || []; PINS = r.pins || []; D = r.designs || [];
      applyConfig(r.config || {});
      fillPins(); fillAddNames(); render();
    });
  }

  $('ps-add-type').addEventListener('change', fillAddNames);
  $('ps-add-btn').addEventListener('click', function(){
    var name = $('ps-add-name').value;
    if (!name){ toast('Nothing to add', true); return; }
    D.push({type: $('ps-add-type').value, name: name,
            label: $('ps-add-label').value.trim() || name.replace(/\.fseq$/i, ''), enabled: true});
    $('ps-add-label').value = '';
    saveDesigns();
  });
  $('ps-btn-next').addEventListener('click', function(){ post('cmd', {cmd:'next'}, function(r){ if(!r.ok) toast(r.error||'Failed', true); }); });
  $('ps-btn-restart').addEventListener('click', function(){ post('cmd', {cmd:'restart'}, function(r){ if(!r.ok) toast(r.error||'Failed', true); }); });
  $('ps-btn-stop').addEventListener('click', function(){ post('cmd', {cmd:'stop'}, function(r){ if(!r.ok) toast(r.error||'Failed', true); }); });

  bindSettings();
  load();
  poll();
  setInterval(poll, 1500);
})();
</script>
