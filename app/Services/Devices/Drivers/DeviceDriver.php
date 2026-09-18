<?php

namespace App\Services\Devices\Drivers;

use App\Models\Device;

/**
 * SÜRÜCÜ ARAYÜZÜ — bir cihaz ailesiyle nasıl konuşulacağını tanımlar.
 *
 * Amaç: "biyometrik cihaz" ekranının tek bir markaya (ZKTeco/Perkotek) çakılı kalmaması.
 * Yeni marka eklemek = yeni bir sürücü sınıfı + kayıt defterine bir satır. Ekran, formu ve
 * yetenekleri sürücüden okur; ön yüzde marka listesi elle yazılmaz.
 */
interface DeviceDriver
{
    /** Kayıt anahtarı; `devices.protocol` sütununa yazılır (zk | adms | hikvision | anviz). */
    public function key(): string;

    /** Ekranda görünen ad. */
    public function label(): string;

    /** Bu sürücünün kapsadığı markalar (kullanıcı "benim cihazım burada mı?" diye bakar). */
    public function vendors(): array;

    /** 'hazir' = yazıldı ve çalışıyor · 'yakinda' = arayüzde görünür, henüz kod yok. */
    public function status(): string;

    /**
     * Yetenekler:
     *   tarama  → ağda aranabilir mi?
     *   cekme   → sunucu/masaüstü cihaza bağlanıp kayıt çeker mi? (pull)
     *   itme    → cihaz kendisi bize kayıt gönderir mi? (push)
     *   kullanicilar → cihazdaki kullanıcı listesi okunabilir mi?
     */
    public function capabilities(): array;

    /**
     * Bağlantı formunun alanları. Ham JSON yerine ALANLI form için tek kaynak budur.
     *
     * @return list<array{ad:string, etiket:string, tur:string, zorunlu?:bool, varsayilan?:mixed, ipucu?:string, secenekler?:array}>
     */
    public function fields(): array;

    /** Kullanıcının cihaz menüsünde yapması gerekenler (Türkçe, sırayla). */
    public function setupSteps(): array;

    /** Cihazla konuşup künye döner. Desteklenmiyorsa 'durum' => 'desteklenmiyor'. */
    public function test(Device $device): array;
}
