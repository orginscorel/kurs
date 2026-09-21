<?php

namespace App\Models;

use App\Casts\DataEncrypted;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Device extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = [
        'branch_id', 'name', 'kind', 'location', 'direction', 'serial_no', 'api_token_hash', 'api_token_prefix', 'firmware', 'is_active',
        // Biyometrik terminal köprüsü (docs/CIHAZ-KOPRUSU.md) — yalnız köprüyü çalıştıran düğümde anlamlı
        'protocol', 'zk_ip', 'zk_port', 'zk_transport', 'machine_no', 'terminal_connection',
        // Web paneli (Perkotek YT33 "Dynamic Face" HTTP /bin/cmd) yazma kanalı — panel şifresi ayrı mutatörle
        'panel_user', 'panel_port',
        // Ağ keşfi / künye (docs/CIHAZ-KESIF.md)
        'device_model', 'vendor', 'discovered_at',
    ];

    protected $hidden = ['api_token_hash', 'zk_comm_key_encrypted', 'panel_password_encrypted'];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'is_active' => 'boolean',
        'zk_comm_key_encrypted' => DataEncrypted::class,
        'panel_password_encrypted' => DataEncrypted::class,
        'zk_cursor_at' => 'datetime',
        'zk_last_pull_at' => 'datetime',
        'discovered_at' => 'datetime',
    ];

    /** Cihazın iletişim şifresi (comm key). Veritabanında şifreli durur, hiçbir yanıtta dönmez. */
    public function getZkCommKeyAttribute(): ?string
    {
        return $this->zk_comm_key_encrypted;
    }

    public function setZkCommKeyAttribute(?string $value): void
    {
        $this->zk_comm_key_encrypted = $value === null || $value === '' ? null : $value;
    }

    /** Cihaz web paneli (Dynamic Face) şifresi. Şifreli durur, hiçbir yanıtta dönmez. */
    public function getPanelPasswordAttribute(): ?string
    {
        return $this->panel_password_encrypted;
    }

    public function setPanelPasswordAttribute(?string $value): void
    {
        $this->panel_password_encrypted = $value === null || $value === '' ? null : $value;
    }

    /** Web paneli (HTTP /bin/cmd) üzerinden cihaza yazma/okuma yapılabilir mi? (kullanıcı + şifre girilmiş) */
    public function supportsWebPanel(): bool
    {
        return ! empty($this->zk_ip) && ! empty($this->panel_user) && ! empty($this->panel_password_encrypted);
    }

    /** ZKTeco protokolüyle konuşulacak ve bağlantı bilgileri tam mı? */
    public function supportsZkBridge(): bool
    {
        return $this->protocol === 'zk' && ! empty($this->zk_ip);
    }

    /** ADMS (iclock): cihaz kendisi gönderiyor; kimlik seri numarasıdır. */
    public function supportsAdms(): bool
    {
        return $this->protocol === 'adms' && ! empty($this->serial_no);
    }

    /** Yeni jeton üretir; düz metin yalnızca bir kez döner, veritabanında özeti kalır. */
    public function issueToken(): string
    {
        $token = 'dev_'.Str::random(48);
        $this->forceFill([
            'api_token_hash' => hash('sha256', $token),
            'api_token_prefix' => substr($token, 0, 12),
        ])->save();

        return $token;
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(5));
    }
}
