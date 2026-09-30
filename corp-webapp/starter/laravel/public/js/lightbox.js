/*
 * lightbox.js — pregled priloga (PDF, slike, Office) u overlay-u.
 * Kućni standard (corp-webapp). Bez zavisnosti; radi i na sadržaju
 * ubačenom u modal/sheet (delegacija događaja na document).
 *
 * Upotreba u HTML-u:
 *   <a href="{URL_fajla}" data-lightbox="grupa"
 *      data-type="pdf|image|office"   (ako se izostavi, pogađa se iz ekstenzije)
 *      data-preview="{URL_html_pregleda}"   (za office: docx/xlsx renderovan u HTML)
 *      data-title="Naziv fajla">…</a>
 *
 * Bez JavaScript-a link i dalje radi (otvara/preuzima fajl) — progresivno poboljšanje.
 */
(function () {
  'use strict';
  if (window.__lightboxLoaded) return;
  window.__lightboxLoaded = true;

  var IMG = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
  var OFFICE = ['doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'ppt', 'pptx'];

  function extOf(a) {
    var e = (a.getAttribute('data-ext') || '').toLowerCase();
    if (e) return e;
    var url = (a.getAttribute('href') || '').split('?')[0];
    var m = url.match(/\.([a-z0-9]+)$/i);
    return m ? m[1].toLowerCase() : '';
  }

  function typeOf(a) {
    var t = (a.getAttribute('data-type') || '').toLowerCase();
    if (t) return t;
    var e = extOf(a);
    if (e === 'pdf') return 'pdf';
    if (IMG.indexOf(e) !== -1) return 'image';
    if (OFFICE.indexOf(e) !== -1) return 'office';
    return 'other';
  }

  // ---- stilovi (ubace se jednom) ------------------------------------------
  var css = ''
    + '.lb-ov{position:fixed;inset:0;z-index:2000;display:flex;flex-direction:column;'
    + 'background:rgba(15,18,22,.92);opacity:0;transition:opacity .15s ease}'
    + '.lb-ov.show{opacity:1}'
    + '.lb-bar{display:flex;align-items:center;gap:.5rem;padding:.6rem .9rem;color:#e9ecef;'
    + 'font:500 .95rem/1.2 Inter,system-ui,sans-serif;flex:0 0 auto}'
    + '.lb-title{flex:1 1 auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    + '.lb-btn{background:rgba(255,255,255,.12);color:#fff;border:0;border-radius:.5rem;'
    + 'width:38px;height:38px;font-size:1.1rem;cursor:pointer;flex:0 0 auto;line-height:1}'
    + '.lb-btn:hover{background:rgba(255,255,255,.25)}'
    + '.lb-btn[disabled]{opacity:.35;cursor:default}'
    + '.lb-stage{flex:1 1 auto;position:relative;display:flex;align-items:center;'
    + 'justify-content:center;overflow:auto;padding:0 .5rem .8rem}'
    + '.lb-stage img{max-width:100%;max-height:100%;object-fit:contain;'
    + 'box-shadow:0 8px 40px rgba(0,0,0,.5);background:#fff}'
    + '.lb-stage iframe{width:100%;height:100%;border:0;background:#fff;border-radius:.4rem}'
    + '.lb-msg{color:#ced4da;text-align:center;max-width:32rem;padding:2rem}'
    + '.lb-msg a{color:#8ecaff}'
    + '.lb-spin{width:42px;height:42px;border:4px solid rgba(255,255,255,.25);'
    + 'border-top-color:#fff;border-radius:50%;animation:lb-rot .8s linear infinite}'
    + '@keyframes lb-rot{to{transform:rotate(360deg)}}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  // ---- DOM ----------------------------------------------------------------
  var ov, stage, titleEl, btnPrev, btnNext, btnOpen;
  var group = [], idx = 0;

  function build() {
    ov = document.createElement('div');
    ov.className = 'lb-ov';
    ov.innerHTML =
      '<div class="lb-bar">'
      + '<button class="lb-btn" data-lb="prev" title="Prethodni">‹</button>'
      + '<button class="lb-btn" data-lb="next" title="Sledeći">›</button>'
      + '<span class="lb-title"></span>'
      + '<a class="lb-btn" data-lb="open" target="_blank" rel="noopener" title="Otvori u novom tabu">↗</a>'
      + '<button class="lb-btn" data-lb="close" title="Zatvori (Esc)">✕</button>'
      + '</div><div class="lb-stage"></div>';
    document.body.appendChild(ov);
    stage = ov.querySelector('.lb-stage');
    titleEl = ov.querySelector('.lb-title');
    btnPrev = ov.querySelector('[data-lb="prev"]');
    btnNext = ov.querySelector('[data-lb="next"]');
    btnOpen = ov.querySelector('[data-lb="open"]');
    ov.addEventListener('click', function (e) {
      var b = e.target.closest('[data-lb]');
      if (b) { var a = b.getAttribute('data-lb');
        if (a === 'close') close(); else if (a === 'prev') step(-1); else if (a === 'next') step(1);
        return; }
      if (e.target === ov || e.target === stage) close();
    });
  }

  function render() {
    var a = group[idx];
    var type = typeOf(a);
    var href = a.getAttribute('href');
    var title = a.getAttribute('data-title') || a.textContent.trim() || 'Prilog';
    titleEl.textContent = title;
    btnOpen.setAttribute('href', href);
    btnPrev.disabled = idx <= 0;
    btnNext.disabled = idx >= group.length - 1;
    stage.innerHTML = '<div class="lb-spin"></div>';

    if (type === 'image') {
      var img = new Image();
      img.onload = function () { stage.innerHTML = ''; stage.appendChild(img); };
      img.onerror = function () { fail(href); };
      img.src = href; img.alt = title;
    } else if (type === 'pdf') {
      frame(href, false); // PDF: bez sandbox-a (Chrome PDF viewer se u sandbox-u blokira)
    } else if (type === 'office') {
      var prev = a.getAttribute('data-preview');
      if (prev) frame(prev, true); else fail(href); // Office HTML: izolovan u sandbox-u
    } else {
      fail(href);
    }
  }

  function frame(src, sandbox) {
    var f = document.createElement('iframe');
    f.setAttribute('title', 'Pregled');
    // Sandbox samo za Office HTML pregled (izolacija). PDF NE sme u sandbox —
    // Chrome-ov ugrađeni PDF viewer se tada blokira ("blocked by Chrome").
    if (sandbox) f.setAttribute('sandbox', 'allow-same-origin allow-popups allow-downloads');
    stage.innerHTML = ''; stage.appendChild(f); f.src = src;
  }

  function fail(href) {
    stage.innerHTML = '<div class="lb-msg">Pregled nije moguć za ovaj tip fajla.<br>'
      + '<a href="' + href + '" target="_blank" rel="noopener">Otvori / preuzmi fajl ↗</a></div>';
  }

  function step(d) { var n = idx + d; if (n >= 0 && n < group.length) { idx = n; render(); } }

  function open(a) {
    if (!ov) build();
    var name = a.getAttribute('data-lightbox');
    group = name
      ? Array.prototype.slice.call(document.querySelectorAll('[data-lightbox="' + CSS.escape(name) + '"]'))
      : [a];
    idx = Math.max(0, group.indexOf(a));
    ov.style.display = 'flex';
    requestAnimationFrame(function () { ov.classList.add('show'); });
    document.body.style.overflow = 'hidden';
    render();
  }

  function close() {
    if (!ov) return;
    ov.classList.remove('show');
    document.body.style.overflow = '';
    setTimeout(function () { ov.style.display = 'none'; stage.innerHTML = ''; }, 150);
  }

  // ---- vezivanje (delegacija — radi i na ubačenom sadržaju) ----------------
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-lightbox]');
    if (!a) return;
    var type = typeOf(a);
    if (type === 'other') return; // neподržано → pusti da link radi normalno
    e.preventDefault();
    open(a);
  });
  document.addEventListener('keydown', function (e) {
    if (!ov || ov.style.display !== 'flex') return;
    if (e.key === 'Escape') close();
    else if (e.key === 'ArrowLeft') step(-1);
    else if (e.key === 'ArrowRight') step(1);
  });
})();
