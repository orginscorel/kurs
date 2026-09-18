fn main() {
    // Uygulama komutları yalnız yetenek (capability) dosyalarında izin verilen pencere/adreslerden çağrılabilir.
    // Kimlikler: allow-<komut, _ yerine -> (ör. allow-desktop-notify).
    tauri_build::try_build(
        tauri_build::Attributes::new().app_manifest(tauri_build::AppManifest::new().commands(&[
            // kurulum / karşılama ön yüzü
            "app_state",
            "check_server",
            "choose_remote",
            "setup_local",
            "resume_setup",
            "start_local",
            "reset_setup",
            "open_logs",
            "quit_app",
            // güncelleme penceresi
            "update_info",
            "update_install",
            "update_later",
            // web sayfalarına (yerel ya da kurum sunucusu) açılan köprü
            "desktop_info",
            "desktop_notify",
            "desktop_badge",
            "open_downloaded_path",
            "reveal_downloaded_path",
        ])),
    )
    .expect("tauri-build başarısız");
}
