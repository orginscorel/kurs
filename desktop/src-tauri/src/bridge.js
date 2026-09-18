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
 *  - İndirme kartı: dosya inince sağ altta küçük bir kart (şerit üstte olduğu için çakışmaz) — "Aç" ve
 *    "Klasörde göster". 10 sn sonra kendiliğinden kapanır, fare üzerindeyken sayaç durur.
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

    // --- Güncelleme şeridi ve indirme kartı
    startUpdateBanner()
    startDownloadCard()
    if (info.mode === 'local') startSyncToast()
  }).catch(function () { /* izinsiz köken */ })

  // ------------------------------------------------------------------ "Şimdi eşitle" sonucu (yerel kip)
  // Menü/tepsi/üst çubuktan tetiklenen eşitlemenin sonucu burada görünür: "Şimdi eşitle" asla sessiz kalmaz.
  // Üst çubuk (SyncStatus.tsx) `window.kursDesktop.syncNow()` ile doğrudan Rust görevine sinyal gönderir.
  function startSyncToast() {
    try {
      Object.defineProperty(window, 'kursDesktop', {
        value: Object.freeze({ syncNow: function () { return invoke('desktop_sync_now') } }),
        configurable: false,
      })
    } catch (e) { /* yok say */ }
    var host = null
    var box = null
    var timer = 0
    function ensure() {
      if (host && host.isConnected) return
      host = document.createElement('kurs-sync-toast')
      var root = host.attachShadow({ mode: 'closed' })
      root.innerHTML = '<style>' + TOKENS +
        '.t{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:2147483646;max-width:min(460px,calc(100vw - 32px));' +
        'background:var(--bg);color:var(--fg);border:1px solid var(--line);border-left:4px solid var(--accent);border-radius:3px;' +
        'box-shadow:0 8px 24px rgb(0 0 0/.14);padding:10px 14px;font:13px/1.45 var(--ff)}' +
        '.t[data-s="ok"]{border-left-color:var(--ok)}.t[data-s="offline"],.t[data-s="error"],.t[data-s="revoked"]{border-left-color:var(--danger)}' +
        '.h{font-weight:600;margin-bottom:2px}.m{color:var(--muted)}@media (prefers-color-scheme:dark){:host{--bg:#1b2533;--fg:#e8eef6;--muted:#a9b6c6;--line:#2c3a4d}}</style>' +
        '<div class="t" role="status" aria-live="polite"><div class="h"></div><div class="m"></div></div>'
      box = root.querySelector('.t')
      ;(document.body || document.documentElement).appendChild(host)
    }
    function show(o) {
      if (!o || typeof o !== 'object') return
      ensure()
      box.setAttribute('data-s', String(o.status || ''))
      box.querySelector('.h').textContent = String(o.title || 'Eşitleme')
      box.querySelector('.m').textContent = o.status === 'started' ? '' : String(o.message || '')
      host.hidden = false
      clearTimeout(timer)
      if (o.status !== 'started') timer = setTimeout(function () { if (host) host.hidden = true }, o.status === 'ok' ? 5000 : 12000)
    }
    listen('sync://result', show).catch(noop)
  }

  // ------------------------------------------------------------------ güncelleme şeridi (shadow DOM)

  // Şerit ve indirme kartının ortak tasarım dili (renk belirteçleri + düğme biçimi)
  var TOKENS = [
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
    '.ico{flex:0 0 auto;width:17px;height:17px;color:var(--accent);display:flex}',
    '.ico svg{width:17px;height:17px;display:block;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}',
    '.btn{-webkit-appearance:none;appearance:none;margin:0;border:1px solid transparent;border-radius:3px;',
    '  font:600 12.5px/1 var(--ff);padding:8px 13px;cursor:pointer;white-space:nowrap;background:transparent;color:inherit;',
    '  transition:background .12s ease,color .12s ease,border-color .12s ease}',
    '.btn:focus-visible{outline:2px solid var(--accent);outline-offset:2px}',
    '.btn.primary{background:var(--accent);color:var(--on-accent);border-color:var(--accent)}',
    '.btn.primary:hover{background:var(--accent-h);border-color:var(--accent-h)}',
    '.btn.ghost{color:var(--muted);border-color:var(--line)}',
    '.btn.ghost:hover{color:var(--fg);background:var(--hover)}',
  ]

  var CSS = TOKENS.concat([
    '.wrap{width:100%;background:var(--bg);color:var(--fg);border-bottom:1px solid var(--line);',
    '  box-shadow:0 1px 2px rgba(14,24,38,.10),0 8px 22px rgba(14,24,38,.07);',
    "  font:500 13px/1.35 var(--ff);-webkit-font-smoothing:antialiased;text-align:left;direction:ltr;",
    '  animation:kb-in .22s cubic-bezier(.2,.8,.3,1) both}',
    '@keyframes kb-in{from{transform:translateY(-100%);opacity:.4}to{transform:translateY(0);opacity:1}}',
    '.wrap[data-tone="danger"]{--accent:var(--danger);--accent-h:var(--danger-h)}',
    '.wrap[data-tone="ok"]{--accent:var(--ok)}',
    '.bar{display:flex;align-items:center;gap:12px;padding:8px 14px;min-height:42px}',
    '.lead{flex:1 1 auto;min-width:0;display:flex;align-items:center;gap:10px}',
    '.ico.spin svg{animation:kb-spin 1s linear infinite}',
    '@keyframes kb-spin{to{transform:rotate(360deg)}}',
    '.msg{min-width:0;display:flex;align-items:baseline;gap:8px;flex-wrap:nowrap;overflow:hidden}',
    '.line{flex:0 0 auto;display:flex;align-items:baseline;gap:7px}',
    '.ttl{font-weight:650;color:var(--fg);white-space:nowrap}',
    '.pct{font-weight:650;color:var(--accent);font-variant-numeric:tabular-nums;white-space:nowrap}',
    '.sub{font-weight:450;color:var(--muted);flex:0 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.acts{flex:0 0 auto;display:flex;align-items:center;gap:6px}',
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
  ]).join('\n')

  // --- sağ alttaki indirme kartı
  var CARD_CSS = TOKENS.concat([
    '.stack{display:flex;flex-direction:column;gap:8px;width:100%}',
    '.card{width:100%;background:var(--bg);color:var(--fg);border:1px solid var(--line);border-radius:3px;',
    '  box-shadow:0 2px 6px rgba(14,24,38,.12),0 14px 34px rgba(14,24,38,.14);',
    "  font:500 13px/1.35 var(--ff);-webkit-font-smoothing:antialiased;text-align:left;direction:ltr;",
    '  padding:10px 11px 10px 12px;overflow:hidden;animation:kc-in .22s cubic-bezier(.2,.8,.3,1) both}',
    '@keyframes kc-in{from{transform:translateY(12px) scale(.98);opacity:0}to{transform:none;opacity:1}}',
    '.card.out{animation:kc-out .18s ease both}',
    '@keyframes kc-out{to{transform:translateY(8px);opacity:0}}',
    '.card[data-tone="danger"]{--accent:var(--danger);--accent-h:var(--danger-h)}',
    '.top{display:flex;align-items:flex-start;gap:9px}',
    '.ico{margin-top:1px}',
    '.txt{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:2px}',
    '.nm{font-weight:650;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.mt{font-weight:450;font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.x{flex:0 0 auto;-webkit-appearance:none;appearance:none;background:transparent;border:0;border-radius:3px;',
    '  padding:3px;margin:-3px -3px 0 0;cursor:pointer;color:var(--muted);display:flex}',
    '.x:hover{color:var(--fg);background:var(--hover)}',
    '.x:focus-visible{outline:2px solid var(--accent);outline-offset:1px}',
    '.x svg{width:14px;height:14px;display:block;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round}',
    '.acts{display:flex;justify-content:flex-end;gap:6px;margin-top:10px}',
    '.acts:empty{display:none}',
    '.life{height:2px;background:var(--track);margin:10px -11px -10px -12px}',
    '.life>span{display:block;height:100%;width:100%;background:var(--accent);transform-origin:left;',
    '  animation:kc-life 10s linear both}',
    '@keyframes kc-life{to{transform:scaleX(0)}}',
    '.card.hold .life>span{animation-play-state:paused}',
    '.card[data-tone="danger"] .life{display:none}',
    '.card[data-tone="danger"] .mt{white-space:normal;overflow:visible}',
    '@media (max-width:560px){.card{padding:10px}.acts{flex-wrap:wrap}.life{margin:10px -10px -10px}}',
    '@media (prefers-reduced-motion:reduce){.card,.life>span{animation:none}}',
  ]).join('\n')

  var ICONS = {
    sparkles: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 13.6 8 18 9.6 13.6 11.2 12 15.7 10.4 11.2 6 9.6 10.4 8Z"/><path d="M18.5 15.5 19.2 17.3 21 18l-1.8.7-.7 1.8-.7-1.8L16 18l1.8-.7Z"/></svg>',
    spinner: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5a8.5 8.5 0 1 1-8.5 8.5" /></svg>',
    warn: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.2 21 19.5H3Z"/><path d="M12 10v4.2"/><path d="M12 17.1h.01"/></svg>',
    check: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="m8.4 12.2 2.5 2.5 4.7-5"/></svg>',
    saved: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.6v10.2"/><path d="m8 10 4 3.8 4-3.8"/><path d="M4.5 16.2v2.1a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-2.1"/></svg>',
    close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6.5 6.5 11 11"/><path d="m17.5 6.5-11 11"/></svg>',
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
          sub = state.detail || 'Bitince uygulama kendiliğinden yeniden başlar.'
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
    // İndirme hızı: son ~3 sn'lik kayan pencere (anlık dalgalanmayı yumuşatır)
    var samples = []
    function mb(bytes) { return (bytes / 1048576).toLocaleString('tr-TR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) }
    function eta(sec) {
      if (!isFinite(sec) || sec <= 0) return ''
      if (sec < 60) return 'yaklaşık ' + Math.max(1, Math.round(sec)) + ' sn kaldı'
      return 'yaklaşık ' + Math.round(sec / 60) + ' dk kaldı'
    }
    listen('update://progress', function (p) {
      if (!p) return
      var phase = p.phase || 'downloading'
      var pct = phase === 'downloading' && p.total ? Math.max(0, Math.min(100, Math.round((p.downloaded / p.total) * 100))) : null
      var detail = ''
      if (phase === 'downloading') {
        var now = Date.now()
        samples.push([now, p.downloaded || 0])
        while (samples.length > 2 && now - samples[0][0] > 3000) samples.shift()
        var dt = (now - samples[0][0]) / 1000
        var rate = dt > 0.4 ? ((p.downloaded || 0) - samples[0][1]) / dt : 0
        var parts = [mb(p.downloaded || 0) + (p.total ? ' / ' + mb(p.total) : '') + ' MB']
        if (rate > 0) parts.push(mb(rate) + ' MB/sn')
        if (rate > 0 && p.total) { var e = eta((p.total - p.downloaded) / rate); if (e) parts.push(e) }
        detail = parts.join(' · ')
      } else {
        samples = []
      }
      show({ kind: 'working', phase: phase, pct: pct, detail: detail })
    }).catch(noop)

    // Sayfa yeniden yüklendiyse ya da başka bir sayfaya geçildiyse şeridi geri getir (bu çağrı da bir ack'tir).
    invoke('update_info').then(function (i) {
      if (i && i.version && !i.snoozed && !state) show({ kind: 'available', info: i })
    }).catch(noop)
  }

  // ------------------------------------------------------------------ indirme kartı (sağ alt, shadow DOM)

  var LIFE_MS = 10000

  function fmtSize(n) {
    if (typeof n !== 'number' || !isFinite(n) || n < 0) return ''
    var u = ['B', 'KB', 'MB', 'GB', 'TB']
    var i = 0
    var v = n
    while (v >= 1024 && i < u.length - 1) { v /= 1024; i++ }
    var s = i === 0 ? String(Math.round(v)) : v.toFixed(v < 10 ? 1 : 0)
    try { s = Number(s).toLocaleString('tr-TR') } catch (e) { /* yok say */ }
    return s + ' ' + u[i]
  }

  function startDownloadCard() {
    var host = null
    var sr = null
    var stack = null

    function build() {
      host = document.createElement('kurs-download-card')
      var fixed = {
        position: 'fixed', right: '12px', bottom: '12px', left: 'auto', top: 'auto',
        width: '340px', 'max-width': 'calc(100vw - 24px)', margin: '0', padding: '0',
        'z-index': '2147483500', display: 'block', 'pointer-events': 'auto', contain: 'layout style',
        'color-scheme': 'light dark', visibility: 'visible', opacity: '1', transform: 'none', float: 'none',
      }
      for (var k in fixed) { if (Object.prototype.hasOwnProperty.call(fixed, k)) host.style.setProperty(k, fixed[k], 'important') }
      sr = host.attachShadow({ mode: 'open' })
      var style = document.createElement('style')
      style.textContent = CARD_CSS
      stack = document.createElement('div')
      stack.className = 'stack'
      stack.setAttribute('role', 'status')
      stack.setAttribute('aria-live', 'polite')
      sr.appendChild(style)
      sr.appendChild(stack)
      stack.addEventListener('click', function (ev) {
        var b = ev.target && ev.target.closest ? ev.target.closest('button[data-a]') : null
        if (!b) return
        var card = b.closest('.card')
        if (card) act(card, b.getAttribute('data-a'))
      })
    }

    function attach() {
      if (!host) build()
      var parent = document.documentElement || document.body
      if (parent && host.parentNode !== parent) parent.appendChild(host)
    }

    function drop(card) {
      clearTimeout(card.__t)
      card.classList.add('out')
      setTimeout(function () {
        if (card.parentNode) card.parentNode.removeChild(card)
        if (stack && !stack.children.length && host && host.parentNode) host.parentNode.removeChild(host)
      }, 180)
    }

    function arm(card) {
      if (card.getAttribute('data-tone') === 'danger') return
      clearTimeout(card.__t)
      card.__t = setTimeout(function () { drop(card) }, LIFE_MS)
    }

    function fail(card, message) {
      card.setAttribute('data-tone', 'danger')
      clearTimeout(card.__t)
      card.removeAttribute('data-path')
      card.querySelector('.ico').innerHTML = ICONS.warn
      card.querySelector('.nm').textContent = 'Dosya açılamadı'
      card.querySelector('.mt').textContent = message
      card.querySelector('.acts').innerHTML = ''
    }

    function act(card, a) {
      if (a === 'close') return drop(card)
      var path = card.getAttribute('data-path')
      if (!path) return drop(card)
      clearTimeout(card.__t)
      invoke(a === 'open' ? 'open_downloaded_path' : 'reveal_downloaded_path', { path: path })
        .then(function () { drop(card) })
        .catch(function (e) { fail(card, errText(e)) })
    }

    function add(info) {
      attach()
      var ok = !!info.ok && !!info.path
      var card = document.createElement('div')
      card.className = 'card'
      card.setAttribute('data-tone', ok ? 'ok' : 'danger')
      if (ok) card.setAttribute('data-path', info.path)
      card.innerHTML =
        '<div class="top"><span class="ico"></span><div class="txt"><span class="nm"></span><span class="mt"></span></div>' +
        '<button class="x" data-a="close" aria-label="Kapat"></button></div>' +
        '<div class="acts"></div><div class="life"><span></span></div>'
      card.querySelector('.ico').innerHTML = ok ? ICONS.saved : ICONS.warn
      card.querySelector('.x').innerHTML = ICONS.close
      card.querySelector('.nm').textContent = ok ? String(info.name || 'İndirilen dosya') : 'İndirme tamamlanamadı'
      var size = ok ? fmtSize(info.size) : ''
      card.querySelector('.mt').textContent = ok
        ? (size ? size + ' · İndirilenler klasörü' : 'İndirilenler klasörüne kaydedildi')
        : String(info.name && info.name !== 'İndirilen dosya' ? info.name + ' — ' : '') + 'Dosya kaydedilemedi. Tekrar deneyin.'
      if (ok) {
        card.querySelector('.acts').innerHTML =
          '<button class="btn ghost" data-a="reveal">Klasörde göster</button><button class="btn primary" data-a="open">Aç</button>'
      }

      var hold = function () { card.classList.add('hold'); clearTimeout(card.__t) }
      var go = function () { card.classList.remove('hold'); arm(card) }
      card.addEventListener('mouseenter', hold)
      card.addEventListener('mouseleave', go)
      card.addEventListener('focusin', hold)
      card.addEventListener('focusout', go)

      stack.appendChild(card)
      while (stack.children.length > 4) {
        var old = stack.children[0]
        clearTimeout(old.__t)
        stack.removeChild(old)
      }
      arm(card)
    }

    listen('download://done', function (p) {
      if (p && typeof p === 'object') add(p)
    }).catch(noop)
  }
})()
