// Windows'ta sürüm derlemesinde ek konsol penceresi açılmasın
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    erbaa_kurs_lib::run()
}
