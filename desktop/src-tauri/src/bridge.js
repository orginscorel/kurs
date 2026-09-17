/*
 * Erbaa Kurs masaüstü köprüsü — ana pencerede her sayfa yüklenişinde çalışır.
 * Yalnız izinli kökenlerde (kurum sunucusu, paketteki yerel sunucu) etkinleşir: izin denetimi Rust tarafındaki
 * yetenek (capability) listesindedir; izinsiz kökende `desktop_info` reddedilir ve betik hiçbir şey yapmaz.
 *
 *  - window.__KURS_DESKTOP__ = { version, mode }  (web'deki /uygulamalar sayfası kullanır)
 *  - window.Notification → yerel macOS bildirimi
 *  - Uygulama bildirimleri (/api/v1/notifications) dakikada bir yoklanır; yeni gelen en fazla 3 tanesi yerel
 *    bildirim olarak gösterilir, okunmamış sayısı Dock rozetine yazılır.
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
  }).catch(function () { /* izinsiz köken */ })
})()
