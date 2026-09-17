<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * İsteğin çalıştığı şube. Web/mobilde oturumdaki kullanıcının şubesi; kuyruk
 * işleri ve komutlarda açıkça set() edilir. Çok şubeli yöneticiler ileride
 * X-Branch başlığıyla şube değiştirebilir (izin kontrolü burada yapılır).
 */
class BranchContext
{
    private ?int $branchId = null;

    public function id(): ?int
    {
        if ($this->branchId !== null) {
            return $this->branchId;
        }

        return Auth::user()?->branch_id;
    }

    public function require(): int
    {
        $id = $this->id();

        if ($id === null) {
            throw new \RuntimeException('Şube bağlamı belirlenemedi.');
        }

        return $id;
    }

    public function set(?int $branchId): void
    {
        $this->branchId = $branchId;
    }

    /** Kuyruk işleri için: verilen şube bağlamında çalıştır, sonra geri al. */
    public function run(int $branchId, callable $callback): mixed
    {
        $previous = $this->branchId;
        $this->branchId = $branchId;

        try {
            return $callback();
        } finally {
            $this->branchId = $previous;
        }
    }
}
