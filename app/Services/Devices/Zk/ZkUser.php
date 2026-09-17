<?php

namespace App\Services\Devices\Zk;

/**
 * Cihazdaki bir kullanıcı kaydı.
 *
 * KVKK: parmak izi ŞABLONU asla okunmaz/taşınmaz. Yalnız kullanıcı numarası, ad ve kart
 * numarası alınır; eşleme bu numara üzerinden yapılır (device_identities.identifier).
 */
final class ZkUser
{
    public function __construct(
        public readonly int $uid,           // cihaz iç kimliği (sıra no)
        public readonly string $userId,     // ekranda görünen kullanıcı numarası — EŞLEME ANAHTARI
        public readonly string $name = '',
        public readonly int $privilege = 0, // 0 kullanıcı, 2 kayıt yetkilisi, 14 yönetici
        public readonly string $card = '',
        public readonly int $group = 0,
        public readonly bool $hasPassword = false,
    ) {}

    public function privilegeLabel(): string
    {
        return match ($this->privilege) {
            14 => 'Yönetici',
            12 => 'Süpervizör',
            6, 2 => 'Kayıt yetkilisi',
            default => 'Kullanıcı',
        };
    }

    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'kullanici_no' => $this->userId,
            'ad' => $this->name,
            'yetki' => $this->privilegeLabel(),
            'kart' => $this->card,
            'grup' => $this->group,
            'sifre_var' => $this->hasPassword,
        ];
    }
}
