/* khronoz review harness. Loaded synchronously in <head> so the token set is
   stamped on <html> before first paint. Not product code.
   ?mode=light|dark and ?accent=violet|blue override the stored choice. */
(function () {
  var KEY = 'khronoz.flat.theme';
  var q = new URLSearchParams(location.search);
  var saved = {};
  try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { saved = {}; }

  var mode = q.get('mode') || saved.mode || 'light';
  var accent = q.get('accent') || saved.accent || 'violet';
  if (mode !== 'dark') { mode = 'light'; }
  if (accent !== 'blue') { accent = 'violet'; }

  var root = document.documentElement;
  root.setAttribute('data-mode', mode);
  root.setAttribute('data-accent', accent);

  function save() {
    try { localStorage.setItem(KEY, JSON.stringify({ mode: mode, accent: accent })); } catch (e) {}
  }
  function set(k, v) {
    if (k === 'mode') { mode = v; } else { accent = v; }
    root.setAttribute('data-' + k, v);
    save();
    paint();
  }

  var els = {};
  function paint() {
    Object.keys(els).forEach(function (id) {
      var on = (id === 'm-' + mode) || (id === 'a-' + accent);
      els[id].setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function seg(label, keys, kind) {
    var wrap = document.createElement('div');
    wrap.className = 'seg';
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', label);
    keys.forEach(function (k) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = k.charAt(0).toUpperCase() + k.slice(1);
      b.setAttribute('aria-pressed', 'false');
      b.addEventListener('click', function () { set(kind, k); });
      els[(kind === 'mode' ? 'm-' : 'a-') + k] = b;
      wrap.appendChild(b);
    });
    return wrap;
  }

  function stick() {
    Array.prototype.forEach.call(document.querySelectorAll('.scroll'), function (s) {
      var upd = function () { s.setAttribute('data-stuck', s.scrollTop > 0 ? '1' : '0'); };
      s.addEventListener('scroll', upd, { passive: true });
      upd();
    });
  }

  function tips() {
    /* one rail tooltip is shown open on the roster frame, marked data-tip-open */
    Array.prototype.forEach.call(document.querySelectorAll('[data-tip-open]'), function (el) {
      el.classList.add('tip-on');
    });
  }

  function bare() {
    /* review-only: ?bare=1 strips the review chrome so a frame's own x=0 is the
       page's x=0, and ?only=N keeps just the Nth frame. Screenshot bookkeeping. */
    if (q.get('bare') !== '1') { return; }
    document.body.classList.add('is-bare');
    var crop = (q.get('crop') || '').split(',');
    if (crop.length === 2) {
      var fs = document.querySelector('.frames');
      fs.style.marginLeft = '-' + (parseInt(crop[0], 10) || 0) + 'px';
      fs.style.marginTop = '-' + (parseInt(crop[1], 10) || 0) + 'px';
    }
    var only = parseInt(q.get('only') || '0', 10);
    if (only > 0) {
      var fr = document.querySelectorAll('.frames > *');
      Array.prototype.forEach.call(fr, function (el, i) {
        var n = el.classList.contains('caption') ? 0 : 1;
        if (n) { el.dataset.fi = el.dataset.fi || ''; }
      });
      var frames = document.querySelectorAll('.frames > .frame');
      Array.prototype.forEach.call(frames, function (el, i) {
        if (i !== only - 1) { el.style.display = 'none'; }
      });
    }
  }

  function jump() {
    /* review-only: ?sy= scrolls the sheet, ?iy=/?ix= the first in-frame scroller,
       ?rx=/?ry= the first roster grid. Lets a screenshot be reproduced exactly.
       data-stuck is refreshed by hand: a programmatic scroll fires its event
       asynchronously, and a headless screenshot can beat it. */
    var n = function (k) { var v = q.get(k); return v === null ? null : parseInt(v, 10) || 0; };
    if (n('sx') !== null || n('sy') !== null) { window.scrollTo(n('sx') || 0, n('sy') || 0); }
    var sc = document.querySelector('.scroll');
    if (sc && (n('iy') !== null || n('ix') !== null)) {
      sc.scrollTo(n('ix') || 0, n('iy') || 0);
      sc.setAttribute('data-stuck', sc.scrollTop > 0 ? '1' : '0');
    }
    var rg = document.querySelector('.rg');
    if (rg && (n('rx') !== null || n('ry') !== null)) { rg.scrollTo(n('rx') || 0, n('ry') || 0); }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var bar = document.createElement('div');
    bar.className = 'switcher';
    bar.appendChild(seg('Color mode', ['light', 'dark'], 'mode'));
    bar.appendChild(seg('Accent', ['violet', 'blue'], 'accent'));
    document.body.appendChild(bar);
    paint();
    stick();
    tips();
    bare();
    jump();
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(jump); }
  });
})();
