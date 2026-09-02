<?php
/*
 * Settings page for the "pixelselect" plugin.
 *
 * Everything here is driven by two endpoints in this directory:
 *   action.php  writes settings / switches / the design list, and fires the virtual button
 *   status.php  live state from the running plugin (/dev/shm snapshot)
 * The page renders from action.php?action=lists on load, so it never depends on
 * FPP having injected $pluginSettings.
 *
 * Layout intent: the two things you actually set up - the switches and the
 * designs - are the whole page. Wiring polarity is one shared line rather than
 * two dropdowns repeated on every switch, and the behaviour knobs (which have
 * sensible defaults nobody usually changes) are folded into Options with a
 * plain-English summary of what they currently add up to.
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
  max-width:900px;margin:0 auto;color:var(--tx);
  font-size:14px;line-height:1.45;-webkit-font-smoothing:antialiased;
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
#ps .tiny{font-size:12.5px;color:var(--tx2)}
#ps .grow{flex:1}

#ps .topbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 6px}
#ps .lede{color:var(--tx2);font-size:13.5px;margin:0 0 18px;max-width:70ch}

#ps .card{border:1px solid var(--line);border-radius:14px;background:var(--bg);
  box-shadow:var(--shadow);margin:0 0 16px;overflow:hidden}
#ps .card>.hd{display:flex;align-items:center;gap:10px;padding:13px 18px;
  background:var(--bg2);border-bottom:1px solid var(--line)}
#ps .card>.hd .t{font-size:14.5px;font-weight:640;flex:1;letter-spacing:-.005em}
#ps .card>.bd{padding:18px}

#ps .hero{border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);
  background:linear-gradient(135deg,var(--bg2),var(--bg));padding:20px;margin:0 0 16px;
  display:flex;gap:22px;align-items:center;flex-wrap:wrap}
#ps .hero .now{flex:1;min-width:250px}
#ps .hero .eyebrow{font-size:11.5px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--tx3)}
#ps .hero .title{font-size:27px;font-weight:680;line-height:1.15;margin:5px 0 4px;
  letter-spacing:-.02em;overflow-wrap:anywhere}
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
#ps .rowline{display:flex;align-items:center;gap:11px;flex-wrap:wrap}

#ps .dl{display:flex;flex-direction:column;gap:8px}
#ps .d{display:flex;align-items:center;gap:11px;padding:10px 12px;border:1px solid var(--line);
  border-radius:11px;background:var(--bg);transition:.12s}
#ps .d.cur{border-color:var(--acc);background:var(--acc2)}
#ps .d.off{opacity:.55}
#ps .d.dragover{border-style:dashed;border-color:var(--acc)}
#ps .d .hnd{cursor:grab;color:var(--tx3);font-size:15px;line-height:1;user-select:none;padding:2px 3px}
#ps .d .idx{font-size:11.5px;font-weight:700;color:var(--tx3);width:19px;text-align:right;
  font-variant-numeric:tabular-nums;flex:none}
#ps .d .mid,#ps .d .nm{flex:1;min-width:0}
#ps .d .lb{font-weight:600;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#ps .d .lb input,#ps .d .nm input{border:1px solid transparent;background:transparent;padding:3px 6px;
  border-radius:6px;font-weight:600;font-size:14px;width:100%;color:var(--tx)}
#ps .d .lb input:hover,#ps .d .nm input:hover{border-color:var(--line)}
#ps .d .src{font-size:12px;color:var(--tx2);display:flex;align-items:center;gap:7px;margin-top:1px}
#ps .tag{font-size:10px;font-weight:750;letter-spacing:.06em;text-transform:uppercase;
  padding:2px 6px;border-radius:5px;background:var(--line2);color:var(--tx2);flex:none}
#ps .tag.seq{background:var(--acc2);color:var(--acc)}
#ps .tag.miss{background:var(--bad2);color:var(--bad)}
#ps .d .acts{display:flex;align-items:center;gap:3px;flex:none}
#ps .d .led2{width:11px;height:11px;border-radius:50%;background:var(--line);flex:none;
  box-shadow:inset 0 0 0 1px rgba(0,0,0,.10);transition:.15s}
#ps .d .led2.on{background:var(--ok);box-shadow:0 0 0 3px var(--ok2)}
#ps .d .led2.err{background:var(--bad);box-shadow:0 0 0 3px var(--bad2)}
#ps .d .pinsel{font-size:12.5px;padding:5px 8px;border-radius:8px;width:120px}
#ps .d .setsel{font-size:12px;padding:4px 6px;border-radius:7px;max-width:130px}
#ps .d.fixed{background:var(--bg2)}

#ps .empty{text-align:center;padding:26px 16px;color:var(--tx2);border:1px dashed var(--line);
  border-radius:11px;font-size:13.5px}
#ps .addbar{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:12px}
#ps .addbar input,#ps .addbar select{flex:1;min-width:140px}
#ps .foot{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:14px;
  padding-top:14px;border-top:1px solid var(--line2);font-size:12.5px;color:var(--tx2)}
#ps .foot select{font-size:12.5px;padding:4px 7px;border-radius:8px}

#ps .tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px;padding:4px;background:var(--line2);border-radius:11px}
#ps .tabs button{background:transparent;color:var(--tx2);border:0;padding:6px 13px;border-radius:8px;font-weight:600;font-size:13px}
#ps .tabs button:hover{background:var(--bg);color:var(--tx)}
#ps .tabs button.on{background:var(--bg);color:var(--tx);box-shadow:var(--shadow)}
#ps .tabs button .n{color:var(--tx3);font-weight:600;margin-left:5px;font-size:11.5px}

#ps .banner{display:flex;gap:10px;align-items:flex-start;padding:11px 14px;border-radius:11px;
  font-size:13px;margin:0 0 16px;border:1px solid transparent}
#ps .banner.warn{background:var(--warn2);border-color:var(--warn);color:var(--warn)}
#ps .banner.bad{background:var(--bad2);border-color:var(--bad);color:var(--bad)}
#ps .toast{position:fixed;right:18px;bottom:18px;z-index:9999;background:var(--tx);color:var(--bg);
  padding:9px 15px;border-radius:10px;font-size:13px;font-weight:600;opacity:0;
  transform:translateY(8px);transition:.18s;pointer-events:none;box-shadow:0 6px 20px rgba(0,0,0,.2)}
#ps .toast.show{opacity:1;transform:none}
#ps .toast.bad{background:var(--bad);color:#fff}

#ps details.fold{border:1px solid var(--line);border-radius:14px;background:var(--bg);
  box-shadow:var(--shadow);overflow:hidden;margin:0 0 16px}
#ps details.fold>summary{padding:13px 18px;background:var(--bg2);font-weight:640;cursor:pointer;
  font-size:14.5px;list-style:none;display:flex;align-items:center;gap:8px}
#ps details.fold>summary::-webkit-details-marker{display:none}
#ps details.fold>summary:before{content:"›";display:inline-block;transition:.15s;font-size:17px;color:var(--tx3)}
#ps details.fold[open]>summary:before{transform:rotate(90deg)}
#ps details.fold>summary .sum{font-weight:500;color:var(--tx2);font-size:12.5px;flex:1;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#ps details.fold .bd{padding:18px;border-top:1px solid var(--line)}
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
  <p class="lede">Flip a switch and the plugin takes over the show. Press the button for the next design.
  Open the switch and FPP goes back to whatever it was doing.</p>

  <div id="ps-banner"></div>

  <div class="hero">
    <div class="now">
      <div class="eyebrow">Now playing</div>
      <div class="title" id="ps-title">—</div>
      <div class="sub" id="ps-sub">Waiting for status from the plugin…</div>
      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
        <button class="sec" id="ps-btn-next">Next design ›</button>
        <button class="sec" id="ps-btn-stop">Stop</button>
      </div>
    </div>
    <div class="lamps">
      <div class="lamp" id="ps-lamp-sw"><div class="nm">Switch</div><div class="led"></div><div class="vl" id="ps-sw-v">—</div></div>
      <div class="lamp" id="ps-lamp-bt"><div class="nm">Button</div><div class="led"></div><div class="vl" id="ps-bt-v">—</div></div>
    </div>
  </div>

  <!-- 1. what is wired -->
  <div class="card">
    <div class="hd"><span class="t">Switches &amp; button</span><span class="tiny" id="ps-scount"></span></div>
    <div class="bd">
      <div class="dl" id="ps-sets"></div>
      <div class="addbar">
        <input type="text" id="ps-set-name" placeholder="Add a switch — name it, e.g. Halloween">
        <select id="ps-set-pin"></select>
        <button id="ps-set-add">Add</button>
      </div>
      <div class="foot">
        <span>Everything is wired</span>
        <select id="ps-wire-low">
          <option value="1">to ground</option>
          <option value="0">to 3.3&nbsp;V</option>
        </select>
        <span>with</span>
        <select id="ps-wire-pull">
          <option value="gpio_pu">the internal pull-up</option>
          <option value="gpio_pd">the internal pull-down</option>
          <option value="gpio">no internal resistor</option>
        </select>
        <span class="grow"></span>
        <label class="sw" title="Act as if a switch were closed"><input type="checkbox" id="ps-virtual_enable"><span class="sl"></span></label>
        <span>Test without switches</span>
        <select id="ps-virtual_set" style="display:none"></select>
      </div>
    </div>
  </div>

  <!-- 2. what it plays -->
  <div class="card">
    <div class="hd"><span class="t">Designs</span><span class="tiny" id="ps-dcount"></span></div>
    <div class="bd">
      <div class="tabs" id="ps-tabs"></div>
      <div class="dl" id="ps-list"></div>
      <div class="addbar">
        <select id="ps-add-name"></select>
        <button id="ps-add-btn">Add design</button>
      </div>
      <div class="tiny" style="margin-top:10px">Drag to reorder — the button walks this list top to bottom.
      Click a name to rename it.</div>
    </div>
  </div>

  <!-- 3. the knobs almost nobody touches -->
  <details class="fold" id="ps-opts">
    <summary>Options<span class="sum" id="ps-optsum"></span></summary>
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
          <div class="rowline"><label class="sw"><input type="checkbox" id="ps-resume_last"><span class="sl"></span></label><span class="tiny">Per switch, across reboots</span></div>
        </div>
        <div class="fld">
          <label>Keep it playing</label>
          <div class="rowline"><label class="sw"><input type="checkbox" id="ps-keep_playing"><span class="sl"></span></label><span class="tiny">Restart if playback stops</span></div>
        </div>
        <div class="fld">
          <label>Override everything else</label>
          <div class="rowline"><label class="sw"><input type="checkbox" id="ps-takeover"><span class="sl"></span></label><span class="tiny">Take the player back</span></div>
          <div class="hint">Beats a schedule, a remote, or anyone pressing play in the FPP UI.</div>
        </div>
        <div class="fld">
          <label>Back to the schedule on release</label>
          <div class="rowline"><label class="sw"><input type="checkbox" id="ps-hand_back"><span class="sl"></span></label><span class="tiny">Hand the player back</span></div>
          <div class="hint">FPP will not resume a schedule mid-window on its own once something else has played.</div>
        </div>
        <div class="fld">
          <label>When every switch is open</label>
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
          <div class="rowline" id="ps-lp-row"><span class="tiny">held for</span><input type="number" id="ps-long_press_ms" min="200" max="10000" step="100" style="width:90px"><span class="tiny">ms</span></div>
        </div>
        <div class="fld">
          <label>Debounce</label>
          <div class="rowline"><input type="number" id="ps-debounce_ms" min="1" max="1000" step="1" style="width:90px"><span class="tiny">ms</span></div>
          <div class="hint">Raise it if one press advances two designs.</div>
        </div>
      </div>
    </div>
  </details>

  <details class="fold">
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
          <text x="104" y="52" opacity=".9">a switch</text>
          <text x="104" y="116" opacity=".9">the button</text>
          <text x="104" y="152" opacity=".9">GND</text>
        </g>
      </svg>
      <p class="tiny" style="text-align:center;margin:-4px 0 16px">Every switch and the button sit between their pin
      and ground — which is what the wiring line above the Options section says.</p>
      <ol>
        <li>Add a switch, give it a name and a free pin. Add more for more sets of designs — the button
          always walks whichever switch is closed.</li>
        <li>Don't use a pin that is also listed on <b>Input/Output Setup → GPIO Inputs</b>, or one your cape
          drives, or FPP will fight you for it.</li>
        <li>Watch the lamps on each row — they follow the pins live, so you can check the wiring before
          adding a single design.</li>
        <li>If nothing responds at all, check the badge at the top says <b>live</b>. That needs fppd restarted
          after installing.</li>
      </ol>
      <p class="tiny">Beagle pin names look like <code>P8-11</code> / <code>P9-15</code>, Raspberry Pi like
      <code>P1-11</code>. The pickers only offer pins this board really has.</p>
    </div>
  </details>
</div>
<div class="toast" id="ps-toast"></div>

<script>
(function(){
  var BASE = 'plugin.php?plugin=pixelselect&page=';
  var D = [], SETS = [], PINS = [], SEQ = [], PL = [], CFG = {};
  var curSet = 0, liveSets = [], saveTimer = null, dragFrom = -1;
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
  var VALS  = ['debounce_ms','stop_mode','long_press_action','long_press_ms','virtual_set'];

  function applyConfig(c){
    CFG = c;
    BOOLS.forEach(function(k){ var e = $('ps-'+k); if (e) e.checked = (c[k] === '1'); });
    VALS.forEach(function(k){ var e = $('ps-'+k); if (e && c[k] !== undefined) e.value = c[k]; });
    syncLongPress(); renderWiring(); renderOptSummary();
  }
  function syncLongPress(){
    $('ps-lp-row').style.display = $('ps-long_press_action').value !== 'none' ? '' : 'none';
  }
  function saveSetting(k, v, cb){
    var d = {}; d[k] = v;
    post('save', d, function(r){
      if (r.ok) { CFG = r.config; renderOptSummary(); toast('Saved'); }
      else toast(r.error || 'Could not save', true);
      if (cb) cb(r);
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

  // One line of plain English instead of making people read eight toggles.
  function renderOptSummary(){
    var on = function(k){ return CFG[k] === '1'; };
    var bits = [];
    bits.push(on('repeat') ? 'loops' : 'plays once');
    if (on('wrap')) bits.push('wraps');
    if (on('resume_last')) bits.push('remembers');
    if (on('takeover')) bits.push('overrides everything else');
    if (on('hand_back')) bits.push('returns to the schedule');
    if (CFG.long_press_action && CFG.long_press_action !== 'none') bits.push('long press');
    $('ps-optsum').textContent = bits.join(' · ');
  }

  /* ------------------------------------------- one wiring line for every pin */
  function renderWiring(){
    var lows = SETS.filter(function(s){ return s.pin; }).map(function(s){ return s.active_low ? '1' : '0'; });
    var pulls = SETS.filter(function(s){ return s.pin; }).map(function(s){ return s.pull || 'gpio_pu'; });
    if (CFG.next_pin) { lows.push(CFG.next_active_low); pulls.push(CFG.next_pull); }
    setUniform($('ps-wire-low'), lows, '1');
    setUniform($('ps-wire-pull'), pulls, 'gpio_pu');
  }
  // Shows the shared value, or adds a "mixed" entry if someone has set one pin
  // differently (nothing in this UI does, but a hand-edited config can).
  function setUniform(sel, vals, dflt){
    var mixedOpt = sel.querySelector('[value="__mixed"]');
    var uniform = vals.length ? vals.every(function(v){ return v === vals[0]; }) : true;
    if (uniform) {
      if (mixedOpt) mixedOpt.remove();
      sel.value = vals.length ? vals[0] : dflt;
    } else {
      if (!mixedOpt) {
        mixedOpt = document.createElement('option');
        mixedOpt.value = '__mixed'; mixedOpt.textContent = 'differently per pin';
        sel.insertBefore(mixedOpt, sel.firstChild);
      }
      sel.value = '__mixed';
    }
  }
  function applyWiring(){
    var low = $('ps-wire-low').value, pull = $('ps-wire-pull').value;
    if (low === '__mixed' || pull === '__mixed') return;
    SETS.forEach(function(s){ s.active_low = (low === '1'); s.pull = pull; });
    post('save', {next_active_low: low, next_pull: pull}, function(r){
      if (r.ok) CFG = r.config;
      saveSets();
    });
  }

  /* ---------------------------------------------------------------- switches */
  function pinOptions(cur){
    var o = '<option value="">— no pin —</option>';
    PINS.forEach(function(p){ o += '<option value="' + esc(p.pin) + '">' + esc(p.pin) + '</option>'; });
    if (cur && !PINS.some(function(p){ return p.pin === cur; }))
      o += '<option value="' + esc(cur) + '">' + esc(cur) + ' (not on this board)</option>';
    return o;
  }
  function saveSets(cb){
    post('sets', {sets: JSON.stringify(SETS)}, function(r){
      if (r.ok){ SETS = r.sets; D = r.designs; if (curSet >= SETS.length) curSet = 0;
                 renderSets(); renderTabs(); render(); toast('Saved'); }
      else toast(r.error || 'Could not save', true);
      if (cb) cb(r);
    });
  }
  function renderSets(){
    var wrap = $('ps-sets');
    $('ps-scount').textContent = SETS.length > 1 ? (SETS.length + ' switches') : '';
    var h = '';
    SETS.forEach(function(st, i){
      var live = liveSets[i] || {};
      var cls = !st.pin ? '' : (live.ok === false ? ' err' : (live.on ? ' on' : ''));
      var n = D.filter(function(d){ return d.set === i; }).length;
      h += '<div class="d" data-s="' + i + '">' +
             '<span class="led2' + cls + '" title="Live switch state"></span>' +
             '<div class="nm"><input type="text" value="' + esc(st.name) + '" data-sname="' + i + '" ' +
               'aria-label="Name for switch ' + (i+1) + '"></div>' +
             '<span class="tiny" style="width:70px;text-align:right">' + n + (n === 1 ? ' design' : ' designs') + '</span>' +
             '<select class="pinsel" data-spin="' + i + '">' + pinOptions(st.pin) + '</select>' +
             '<div class="acts">' +
               (SETS.length > 1 ? '<button class="danger" data-sdel="' + i + '" title="Remove">✕</button>'
                                : '<span style="width:30px"></span>') +
             '</div>' +
           '</div>';
    });
    // The pushbutton is just another wired input, so it lives in the same list.
    var bl = liveSets.__btn || {};
    h += '<div class="d fixed">' +
           '<span class="led2' + (!CFG.next_pin ? '' : (bl.ok === false ? ' err' : (bl.down ? ' on' : ''))) + '"></span>' +
           '<div class="nm" style="font-weight:600;padding:3px 6px">Pushbutton <span class="tiny">— steps through the switch that is on</span></div>' +
           '<select class="pinsel" id="ps-next_pin">' + pinOptions(CFG.next_pin || '') + '</select>' +
           '<div class="acts"><span style="width:30px"></span></div>' +
         '</div>';
    wrap.innerHTML = h;

    wrap.querySelectorAll('[data-spin]').forEach(function(e){ e.value = SETS[+e.dataset.spin].pin || ''; });
    $('ps-next_pin').value = CFG.next_pin || '';
    $('ps-next_pin').addEventListener('change', function(){ saveSetting('next_pin', this.value); });
    wrap.querySelectorAll('[data-sname]').forEach(function(e){
      e.addEventListener('input', function(){
        SETS[+e.dataset.sname].name = e.value;
        clearTimeout(saveTimer); saveTimer = setTimeout(saveSets, 700);
      });
    });
    wrap.querySelectorAll('[data-spin]').forEach(function(e){
      e.addEventListener('change', function(){ SETS[+e.dataset.spin].pin = e.value; saveSets(); });
    });
    wrap.querySelectorAll('[data-sdel]').forEach(function(e){
      e.addEventListener('click', function(){
        var i = +e.dataset.sdel;
        var n = D.filter(function(d){ return d.set === i; }).length;
        if (n && !confirm('Remove "' + SETS[i].name + '"? Its ' + n + ' design(s) move to ' + SETS[0].name + '.')) return;
        SETS.splice(i, 1);
        D.forEach(function(d){ if (d.set === i) d.set = 0; else if (d.set > i) d.set--; });
        post('designs', {designs: JSON.stringify(D)}, function(){ saveSets(); });
      });
    });

    var vs = $('ps-virtual_set');
    vs.style.display = SETS.length > 1 ? '' : 'none';
    vs.innerHTML = SETS.map(function(st, i){ return '<option value="' + i + '">' + esc(st.name) + '</option>'; }).join('');
    vs.value = CFG.virtual_set || '0';
    $('ps-set-pin').innerHTML = pinOptions('');
    renderWiring();
  }

  function renderTabs(){
    var t = $('ps-tabs');
    t.style.display = SETS.length > 1 ? '' : 'none';
    t.innerHTML = SETS.map(function(st, i){
      var n = D.filter(function(d){ return d.set === i; }).length;
      return '<button data-tab="' + i + '" class="' + (i === curSet ? 'on' : '') + '">' +
             esc(st.name) + '<span class="n">' + n + '</span></button>';
    }).join('');
    t.querySelectorAll('[data-tab]').forEach(function(b){
      b.addEventListener('click', function(){ curSet = +b.dataset.tab; renderTabs(); render(); });
    });
  }

  /* ----------------------------------------------------------------- designs */
  function saveDesigns(cb){
    clearTimeout(saveTimer);
    post('designs', {designs: JSON.stringify(D)}, function(r){
      if (r.ok) { D = r.designs; renderTabs(); renderSets(); render(); toast('Saved'); }
      else toast(r.error || 'Could not save', true);
      if (cb) cb(r);
    });
  }
  function queueSave(){ clearTimeout(saveTimer); saveTimer = setTimeout(saveDesigns, 600); }

  function render(){
    var wrap = $('ps-list');
    if (curSet >= SETS.length) curSet = 0;
    var mine = D.map(function(d, i){ return {d: d, i: i}; }).filter(function(x){ return x.d.set === curSet; });
    $('ps-dcount').textContent = mine.length ? (mine.length + (mine.length === 1 ? ' design' : ' designs')) : '';
    if (!mine.length){
      wrap.innerHTML = '<div class="empty">No designs here yet. Pick one below and add it.</div>';
      return;
    }
    var setOpts = SETS.map(function(st, si){ return '<option value="' + si + '">' + esc(st.name) + '</option>'; }).join('');
    var h = '';
    mine.forEach(function(x, pos){
      var d = x.d, i = x.i;
      h += '<div class="d' + (d.enabled ? '' : ' off') + '" data-i="' + i + '" draggable="true">' +
             '<span class="hnd" title="Drag to reorder">⣿</span>' +
             '<span class="idx">' + (pos+1) + '</span>' +
             '<div class="mid">' +
               '<div class="lb"><input type="text" value="' + esc(d.label) + '" data-lab="' + i + '" ' +
                 'aria-label="Name for design ' + (pos+1) + '"></div>' +
               '<div class="src">' +
                 '<span class="tag ' + (d.type === 'sequence' ? 'seq' : '') + '">' + (d.type === 'sequence' ? 'seq' : 'list') + '</span>' +
                 '<span>' + esc(d.name) + '</span>' +
                 (d.missing ? '<span class="tag miss">missing</span>' : '') +
               '</div>' +
             '</div>' +
             '<div class="acts">' +
               (SETS.length > 1 ? '<select class="setsel" data-move="' + i + '" title="Move to another switch">' + setOpts + '</select>' : '') +
               '<button class="ghost" data-play="' + i + '" title="Play this now">▶</button>' +
               '<label class="sw" title="Include in the button order"><input type="checkbox" data-en="' + i + '"' +
                 (d.enabled ? ' checked' : '') + '><span class="sl"></span></label>' +
               '<button class="danger" data-del="' + i + '" title="Remove">✕</button>' +
             '</div>' +
           '</div>';
    });
    wrap.innerHTML = h;
    wrap.querySelectorAll('[data-move]').forEach(function(e){ e.value = D[+e.dataset.move].set; });
    wire(wrap);
    markCurrent();
  }

  function wire(wrap){
    wrap.querySelectorAll('[data-lab]').forEach(function(e){
      e.addEventListener('input', function(){ D[+e.dataset.lab].label = e.value; queueSave(); });
    });
    wrap.querySelectorAll('[data-move]').forEach(function(e){
      e.addEventListener('change', function(){ D[+e.dataset.move].set = +e.value; saveDesigns(); });
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

  // One picker for both kinds, grouped - no "type" dropdown to get wrong first.
  function fillAddNames(){
    var o = '';
    if (SEQ.length) o += '<optgroup label="Sequences">' + SEQ.map(function(n){
      return '<option value="s|' + esc(n) + '">' + esc(n) + '</option>'; }).join('') + '</optgroup>';
    if (PL.length) o += '<optgroup label="Playlists">' + PL.map(function(n){
      return '<option value="p|' + esc(n) + '">' + esc(n) + '</option>'; }).join('') + '</optgroup>';
    $('ps-add-name').innerHTML = o || '<option value="">— nothing on this device —</option>';
    $('ps-add-btn').disabled = !(SEQ.length || PL.length);
  }

  /* ------------------------------------------------------------------ status */
  function banner(html, kind){
    $('ps-banner').innerHTML = html ? '<div class="banner ' + kind + '">' + html + '</div>' : '';
  }
  function renderSetLamps(){
    document.querySelectorAll('#ps-sets .d[data-s]').forEach(function(row){
      var i = +row.dataset.s, live = liveSets[i] || {}, lamp = row.querySelector('.led2');
      if (!lamp) return;
      lamp.className = 'led2' + (!SETS[i] || !SETS[i].pin ? '' : (live.ok === false ? ' err' : (live.on ? ' on' : '')));
    });
    var brow = document.querySelector('#ps-sets .d.fixed .led2'), bl = liveSets.__btn || {};
    if (brow) brow.className = 'led2' + (!CFG.next_pin ? '' : (bl.ok === false ? ' err' : (bl.down ? ' on' : '')));
  }
  function poll(){
    fetch(BASE + 'status.php&nopage=1', {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(s){
        var live = $('ps-live');
        if (!s.live){
          live.className = 'pill bad'; live.innerHTML = '<span class="dot"></span>not running';
          banner('<span><b>The plugin is not loaded.</b> Restart FPP after installing — until then the '
               + 'switches do nothing and this page cannot show live pin state.</span>', 'bad');
          $('ps-title').textContent = '—';
          $('ps-sub').textContent = 'No live data from fppd.';
          ['sw','bt'].forEach(function(x){ $('ps-lamp-'+x).className = 'lamp'; $('ps-'+x+'-v').textContent = '—'; });
          return;
        }
        live.className = 'pill ok'; live.innerHTML = '<span class="dot"></span>live';

        if (s.pinError) banner('<span><b>Pin problem:</b> ' + esc(s.pinError) + '</span>', 'bad');
        else if (!s.pluginEnabled) banner('<span>The plugin is switched off, so the switches are ignored. '
               + 'Turn it on with the toggle at the top right.</span>', 'warn');
        else if (!s.totalDesigns) banner('<span>No designs yet — add one below.</span>', 'warn');
        else banner('', '');

        if (s.sets) { liveSets = s.sets; }
        liveSets.__btn = {ok: s.buttonOk, down: s.buttonDown};
        renderSetLamps();
        window._psIndex = s.index;

        $('ps-title').textContent = (s.index >= 0 && s.label) ? s.label : (s.totalDesigns ? 'Nothing selected' : 'No designs');
        var bits = [];
        if (!s.pluginEnabled) bits.push('plugin off');
        else if (!s.active) bits.push('waiting for a switch');
        else bits.push(s.playing ? 'playing' : 'starting…');
        if (s.active && s.activeSetName && s.setCount > 1) bits.push(s.activeSetName);
        if (s.count) bits.push(((s.pos >= 0 ? s.pos : 0) + 1) + ' of ' + s.count);
        if (s.active && s.heldByOther) bits.push(s.takeover ? 'taking the player back…' : 'something else has the player');
        if (!s.active && s.handBackPending) bits.push('handing back to the schedule…');
        $('ps-sub').textContent = bits.join(' · ');

        var sw = $('ps-lamp-sw');
        sw.className = 'lamp' + (!s.switchConfigured ? '' : (!s.switchOk ? ' err' : (s.switchOn ? ' on' : '')));
        $('ps-sw-v').textContent = s.virtualEnable ? 'test mode'
                                 : (!s.switchConfigured ? 'no pin'
                                 : (!s.switchOk ? 'error' : (s.switchOn ? 'on' : 'off')));
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
      SEQ = r.sequences || []; PL = r.playlists || []; PINS = r.pins || [];
      D = r.designs || []; SETS = r.sets || [];
      // A throw in here used to leave the page silently half-drawn, which is a
      // miserable thing to debug from a screenshot.
      try {
        applyConfig(r.config || {});
        fillAddNames(); renderSets(); renderTabs(); render();
      } catch (e) {
        console.error('pixelselect: ' + (e && e.stack ? e.stack : e));
        toast('Page failed to draw: ' + (e && e.message ? e.message : e), true);
      }
    });
  }

  $('ps-set-add').addEventListener('click', function(){
    var nm = $('ps-set-name').value.trim() || ('Switch ' + (SETS.length + 1));
    SETS.push({name: nm, pin: $('ps-set-pin').value,
               active_low: $('ps-wire-low').value !== '0',
               pull: $('ps-wire-pull').value === '__mixed' ? 'gpio_pu' : $('ps-wire-pull').value});
    $('ps-set-name').value = '';
    saveSets(function(r){ if (r.ok) { curSet = SETS.length - 1; renderTabs(); render(); } });
  });
  $('ps-add-btn').addEventListener('click', function(){
    var v = $('ps-add-name').value;
    if (!v) { toast('Nothing to add', true); return; }
    var type = v.charAt(0) === 'p' ? 'playlist' : 'sequence', name = v.slice(2);
    D.push({type: type, name: name, set: curSet, enabled: true,
            label: name.replace(/\.fseq$/i, '')});
    saveDesigns();
  });
  $('ps-wire-low').addEventListener('change', applyWiring);
  $('ps-wire-pull').addEventListener('change', applyWiring);
  $('ps-btn-next').addEventListener('click', function(){ post('cmd', {cmd:'next'}, function(r){ if(!r.ok) toast(r.error||'Failed', true); }); });
  $('ps-btn-stop').addEventListener('click', function(){ post('cmd', {cmd:'stop'}, function(r){ if(!r.ok) toast(r.error||'Failed', true); }); });

  bindSettings();
  load();
  poll();
  setInterval(poll, 1500);
})();
</script>
