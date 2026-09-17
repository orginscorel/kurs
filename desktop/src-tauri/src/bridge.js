/*
 * Erbaa Kurs masaüstü köprüsü — ana pencerede her sayfa yüklenişinde çalışır.
 * Yalnız izinli kökenlerde (kurum sunucusu, paketteki yerel sunucu) etkinleşir: izin denetimi Rust tarafındaki
 * yetenek (capability) listesindedir; izinsiz kökende `desktop_info` reddedilir ve betik hiçbir şey yapmaz.
 *
 *  - window.__KURS_DESKTOP__ = { version, mode }  (web'deki /uygulamalar sayfası kullanır)
 *  - window.Notification → yerel macOS bildirimi
 *  - Uygulama bildirimleri (/api/v1/notifications) dakikada bir yoklanır; yeni gelen en fazla 3 tanesi yerel
 *    bildirim olarak gösterilir, okunmamış sayısı Dock rozetine yazılır.
 *  - Güncelleme şeridi: ayrı pencere yerine sayfanın üstünde ince bir bant (shadow DOM; sayfanın DOM'una ve
 *    CSS'ine karışmaz). "Güncelle" indirmeyi başlatır, yüzde aynı şeritte akar, kurulum bitince Rust uygulamayı
 *    yeniden başlatır. "Daha sonra" mevcut erteleme (snooze) kuralını çalıştırır.
 */
(function () {
  'use strict'
  if (window.top !== window || window.__KURS_DESKTOP_BRIDGE__) return
  window.__KURS_DESKTOP_BRIDGE__ = true
  var internals = window.__TAURI_INTERNALS__
  if (!internals || typeof internals.invoke !== 'function') return
  if (location.protocol !== 'https:' && location.hostname !== '127.0.0.1') return

  var invoke = function (cmd, args) {
    try {
      return Promise.resolve(internals.invoke(cmd, args || {}))
    } catch (e) {
      return Promise.reject(e)
    }
  }
  var noop = function () {}

  // Tauri olay dinleyicisi (plugin:event|listen). İzin: capabilities/web-bridge.json › core:event:allow-listen
  var subs = []
  function listen(event, cb) {
    if (typeof internals.transformCallback !== 'function') return Promise.reject(new Error('transformCallback yok'))
    var handler = internals.transformCallback(function (e) {
      try { cb(e && e.payload) } catch (err) { /* yok say */ }
    })
    return invoke('plugin:event|listen', { event: event, target: { kind: 'Any' }, handler: handler }).then(function (id) {
      subs.push({ event: event, id: id })
      return id
    })
  }
  window.addEventListener('pagehide', function () {
    subs.splice(0).forEach(function (s) { invoke('plugin:event|unlisten', { event: s.event, eventId: s.id }).catch(noop) })
  })

  function errText(e) {
    if (!e) return 'Beklenmeyen bir hata oluştu.'
    if (typeof e === 'string') return e
    if (typeof e.message === 'string') return e.message
    return 'Beklenmeyen bir hata oluştu.'
  }

  invoke('desktop_info').then(function (info) {
    if (!info || typeof info !== 'object') return
    try {
      Object.defineProperty(window, '__KURS_DESKTOP__', { value: Object.freeze({ version: info.version, mode: info.mode }), configurable: false })
    } catch (e) { /* yok say */ }

    // --- Web Notification API → yerel bildirim
    function DesktopNotification(title, options) {
      options = options || {}
      this.title = String(title || '')
      this.body = String(options.body || '')
      this.onclick = null
      this.onclose = null
      invoke('desktop_notify', { title: this.title, body: this.body }).catch(function () {})
    }
    DesktopNotification.prototype.close = function () {}
    DesktopNotification.prototype.addEventListener = function () {}
    DesktopNotification.prototype.removeEventListener = function () {}
    Object.defineProperty(DesktopNotification, 'permission', { get: function () { return 'granted' } })
    DesktopNotification.requestPermission = function (cb) {
      if (typeof cb === 'function') cb('granted')
      return Promise.resolve('granted')
    }
    try { window.Notification = DesktopNotification } catch (e) { /* yok say */ }

    // --- Uygulama bildirimleri
    var KEY = 'kurs-desktop:last-notification-id:' + location.host
    var store = {
      get: function () { try { return Number(localStorage.getItem(KEY) || 0) } catch (e) { return 0 } },
      set: function (v) { try { localStorage.setItem(KEY, String(v)) } catch (e) { /* yok say */ } },
    }
    var busy = false
    function poll() {
      if (busy) return
      busy = true
      fetch('/api/v1/notifications?filter=unread&limit=20', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then(function (r) { return r.ok ? r.json() : null })
        .then(function (j) {
          if (!j || !Array.isArray(j.data)) return
          var items = j.data
          var last = store.get()
          var max = last
          items.forEach(function (n) { if (n.id > max) max = n.id })
          if (last > 0) {
            items
              .filter(function (n) { return n.id > last })
              .sort(function (a, b) { return a.id - b.id })
              .slice(-3)
              .forEach(function (n) {
                invoke('desktop_notify', { title: n.title || 'Yeni bildirim', body: n.body || '' }).catch(function () {})
              })
          }
          if (max !== last) store.set(max)
          invoke('desktop_badge', { count: items.length }).catch(function () {})
        })
        .catch(function () { /* çevrimdışı ya da oturum yok */ })
        .then(function () { busy = false })
    }
    setTimeout(poll, 4000)
    setInterval(poll, 60000)
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll() })

    // --- Güncelleme şeridi
    startUpdateBanner()
  }).catch(function () { /* izinsiz köken */ })

  // ------------------------------------------------------------------ güncelleme şeridi (shadow DOM)

  var CSS = [
    ':host{',
    "  --ff:Inter,'Inter Variable',-apple-system,BlinkMacSystemFont,'SF Pro Text','Segoe UI',Roboto,Helvetica,Arial,sans-serif;",
    '  --bg:#ffffff;--fg:#16212f;--muted:#5b6b7f;--line:#dbe2ea;--hover:#eef2f7;',
    '  --accent:#1f3b63;--accent-h:#2b4e80;--on-accent:#ffffff;--track:#e6ecf3;',
    '  --danger:#a3271c;--danger-h:#b8382c;--ok:#1f6b45;',
    '}',
    '@media (prefers-color-scheme:dark){:host{',
    '  --bg:#151d28;--fg:#e9eff6;--muted:#95a4b6;--line:#26313f;--hover:#1d2733;',
    '  --accent:#7ba6df;--accent-h:#93b8e9;--on-accent:#0d1620;--track:#222d3a;',
    '  --danger:#f0897d;--danger-h:#f5a096;--ok:#6cc79b;',
    '}}',
    '*{box-sizing:border-box}',
    '.wrap{width:100%;background:var(--bg);color:var(--fg);border-bottom:1px solid var(--line);',
    '  box-shadow:0 1px 2px rgba(14,24,38,.10),0 8px 22px rgba(14,24,38,.07);',
    "  font:500 13px/1.35 var(--ff);-webkit-font-smoothing:antialiased;text-align:left;direction:ltr;",
    '  animation:kb-in .22s cubic-bezier(.2,.8,.3,1) both}',
    '@keyframes kb-in{from{transform:translateY(-100%);opacity:.4}to{transform:translateY(0);opacity:1}}',
    '.wrap[data-tone="danger"]{--accent:var(--danger);--accent-h:var(--danger-h)}',
    '.wrap[data-tone="ok"]{--accent:var(--ok)}',
    '.bar{display:flex;align-items:center;gap:12px;padding:8px 14px;min-height:42px}',
    '.lead{flex:1 1 auto;min-width:0;display:flex;align-items:center;gap:10px}',
    '.ico{flex:0 0 auto;width:17px;height:17px;color:var(--accent);display:flex}',
    '.ico svg{width:17px;height:17px;display:block;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}',
    '.ico.spin svg{animation:kb-spin 1s linear infinite}',
    '@keyframes kb-spin{to{transform:rotate(360deg)}}',
    '.msg{min-width:0;display:flex;align-items:baseline;gap:8px;flex-wrap:nowrap;overflow:hidden}',
    '.line{flex:0 0 auto;display:flex;align-items:baseline;gap:7px}',
    '.ttl{font-weight:650;color:var(--fg);white-space:nowrap}',
    '.pct{font-weight:650;color:var(--accent);font-variant-numeric:tabular-nums;white-space:nowrap}',
    '.sub{font-weight:450;color:var(--muted);flex:0 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.acts{flex:0 0 auto;display:flex;align-items:center;gap:6px}',
    '.btn{-webkit-appearance:none;appearance:none;margin:0;border:1px solid transparent;border-radius:3px;',
    '  font:600 12.5px/1 var(--ff);padding:8px 13px;cursor:pointer;white-space:nowrap;background:transparent;color:inherit;',
    '  transition:background .12s ease,color .12s ease,border-color .12s ease}',
    '.btn:focus-visible{outline:2px solid var(--accent);outline-offset:2px}',
    '.btn.primary{background:var(--accent);color:var(--on-accent);border-color:var(--accent)}',
    '.btn.primary:hover{background:var(--accent-h);border-color:var(--accent-h)}',
    '.btn.ghost{color:var(--muted);border-color:var(--line)}',
    '.btn.ghost:hover{color:var(--fg);background:var(--hover)}',
    '.track{display:none;height:2px;background:var(--track);overflow:hidden}',
    '.wrap[data-state="working"] .track{display:block}',
    '.fill{display:block;height:100%;width:0;background:var(--accent);transition:width .28s ease}',
    '.fill.indet{width:32%;animation:kb-slide 1.15s ease-in-out infinite}',
    '@keyframes kb-slide{0%{transform:translateX(-110%)}100%{transform:translateX(330%)}}',
    '@media (max-width:560px){.bar{flex-wrap:wrap;gap:8px 10px;padding:9px 12px;align-items:flex-start}',
    '  .lead{flex:1 1 100%;align-items:flex-start}.ico{margin-top:1px}',
    '  .msg{flex-direction:column;align-items:flex-start;gap:2px;overflow:visible}',
    '  .sub{white-space:normal;overflow:visible;flex:1 1 auto}',
    '  .wrap:not([data-tone="danger"]) .sub{display:none}',
    '  .acts{flex:1 1 100%;justify-content:flex-end}.btn{flex:0 1 auto}}',
    '@media (prefers-reduced-motion:reduce){.wrap,.fill,.ico.spin svg,.fill.indet{animation:none;transition:none}}',
  ].join('\n')

  var ICONS = {
    sparkles: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 13.6 8 18 9.6 13.6 11.2 12 15.7 10.4 11.2 6 9.6 10.4 8Z"/><path d="M18.5 15.5 19.2 17.3 21 18l-1.8.7-.7 1.8-.7-1.8L16 18l1.8-.7Z"/></svg>',
    spinner: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5a8.5 8.5 0 1 1-8.5 8.5" /></svg>',
    warn: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.2 21 19.5H3Z"/><path d="M12 10v4.2"/><path d="M12 17.1h.01"/></svg>',
    check: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="m8.4 12.2 2.5 2.5 4.7-5"/></svg>',
  }

  function noteTitle(notes, version) {
    try {
      var data = JSON.parse(notes)
      if (!Array.isArray(data)) return ''
      for (var i = 0; i < data.length; i++) {
        if (data[i] && data[i].version === version && typeof data[i].title === 'string') return data[i].title
      }
    } catch (e) { /* düz metin sürüm notu */ }
    return ''
  }

  function startUpdateBanner() {
    var host = null
    var sr = null
    var wrap = null
    var state = null // {kind, info, phase, pct, message}
    var hideTimer = 0

    function build() {
      host = document.createElement('kurs-update-banner')
      var s = host.style
      var fixed = {
        position: 'fixed', top: '0px', left: '0px', right: '0px', width: '100%', margin: '0', padding: '0',
        'z-index': '2147483600', display: 'block', 'pointer-events': 'auto', contain: 'layout style',
        'color-scheme': 'light dark', visibility: 'visible', opacity: '1', transform: 'none', float: 'none',
      }
      for (var k in fixed) { if (Object.prototype.hasOwnProperty.call(fixed, k)) s.setProperty(k, fixed[k], 'important') }
      sr = host.attachShadow({ mode: 'open' })
      var style = document.createElement('style')
      style.textContent = CSS
      wrap = document.createElement('div')
      wrap.className = 'wrap'
      wrap.setAttribute('role', 'status')
      wrap.setAttribute('aria-live', 'polite')
      wrap.innerHTML =
        '<div class="bar">' +
        '<div class="lead"><span class="ico"></span><div class="msg"><span class="line"><span class="ttl"></span><span class="pct"></span></span><span class="sub"></span></div></div>' +
        '<div class="acts"></div>' +
        '</div><div class="track"><span class="fill"></span></div>'
      sr.appendChild(style)
      sr.appendChild(wrap)
      wrap.addEventListener('click', function (ev) {
        var b = ev.target && ev.target.closest ? ev.target.closest('button[data-a]') : null
        if (b) act(b.getAttribute('data-a'))
      })
    }

    function attach() {
      if (!host) build()
      var parent = document.documentElement || document.body
      if (!parent) return
      if (host.parentNode !== parent) parent.appendChild(host)
    }

    function q(sel) { return wrap.querySelector(sel) }

    function paint() {
      if (!state) return hide()
      attach()
      var k = state.kind
      var tone = k === 'error' ? 'danger' : k === 'none' ? 'ok' : 'info'
      var icon = k === 'error' ? 'warn' : k === 'none' ? 'check' : k === 'working' ? 'spinner' : 'sparkles'
      var ttl = ''
      var sub = ''
      var pct = ''
      var acts = ''

      if (k === 'available') {
        ttl = 'Yeni sürüm ' + state.info.version + ' hazır'
        sub = noteTitle(state.info.notes, state.info.version) || 'Güncelleme birkaç saniye sürer; uygulama kendiliğinden yeniden başlar.'
        acts = '<button class="btn ghost" data-a="later">Daha sonra</button><button class="btn primary" data-a="install">Güncelle</button>'
      } else if (k === 'working') {
        if (state.phase === 'installing') {
          ttl = 'Güncelleme kuruluyor'
          sub = 'Uygulamayı kapatmayın.'
        } else if (state.phase === 'restarting') {
          ttl = 'Uygulama yeniden başlatılıyor'
          sub = 'Birkaç saniye içinde yeni sürümle açılacak.'
        } else {
          ttl = 'Güncelleme indiriliyor'
          pct = state.pct == null ? '' : '%' + state.pct
          sub = 'Bitince uygulama kendiliğinden yeniden başlar.'
        }
      } else if (k === 'error') {
        ttl = 'Güncelleme tamamlanamadı'
        sub = state.message || 'Bilinmeyen bir hata oluştu.'
        acts = '<button class="btn ghost" data-a="dismiss">Kapat</button><button class="btn primary" data-a="install">Yeniden dene</button>'
      } else if (k === 'none') {
        ttl = 'Uygulama güncel'
        sub = 'Kullandığınız sürüm en yenisi.'
        acts = '<button class="btn ghost" data-a="dismiss">Kapat</button>'
      }

      wrap.setAttribute('data-tone', tone)
      wrap.setAttribute('data-state', k)
      q('.ico').className = 'ico' + (icon === 'spinner' ? ' spin' : '')
      q('.ico').innerHTML = ICONS[icon]
      q('.ttl').textContent = ttl
      q('.pct').textContent = pct
      q('.sub').textContent = sub
      q('.acts').innerHTML = acts
      var fill = q('.fill')
      if (k === 'working' && state.phase === 'downloading' && state.pct != null) {
        fill.className = 'fill'
        fill.style.width = state.pct + '%'
      } else if (k === 'working') {
        fill.className = 'fill indet'
        fill.style.width = ''
      }
    }

    function show(next) {
      clearTimeout(hideTimer)
      state = next
      paint()
      if (next && next.kind === 'none') hideTimer = setTimeout(hide, 6000)
    }

    function hide() {
      clearTimeout(hideTimer)
      state = null
      if (host && host.parentNode) host.parentNode.removeChild(host)
    }

    function act(a) {
      if (a === 'later') {
        invoke('update_later').catch(noop)
        hide()
      } else if (a === 'dismiss') {
        hide()
      } else if (a === 'install') {
        show({ kind: 'working', phase: 'downloading', pct: null })
        invoke('update_install').catch(function (e) { show({ kind: 'error', message: errText(e) }) })
      }
    }

    // Rust'a "şerit burada, ayrı pencere açma" işareti (bkz. src/updater.rs › present)
    function ack() { invoke('update_info').catch(noop) }

    listen('update://available', function (info) {
      ack()
      if (info && info.version) show({ kind: 'available', info: info })
    }).catch(noop)
    listen('update://none', function () {
      ack()
      if (!state || state.kind === 'available') show({ kind: 'none' })
    }).catch(noop)
    listen('update://dismissed', function () {
      if (!state || state.kind === 'available') hide()
    }).catch(noop)
    listen('update://progress', function (p) {
      if (!p) return
      var phase = p.phase || 'downloading'
      var pct = phase === 'downloading' && p.total ? Math.max(0, Math.min(100, Math.round((p.downloaded / p.total) * 100))) : null
      show({ kind: 'working', phase: phase, pct: pct })
    }).catch(noop)

    // Sayfa yeniden yüklendiyse ya da başka bir sayfaya geçildiyse şeridi geri getir (bu çağrı da bir ack'tir).
    invoke('update_info').then(function (i) {
      if (i && i.version && !i.snoozed && !state) show({ kind: 'available', info: i })
    }).catch(noop)
  }
})()
