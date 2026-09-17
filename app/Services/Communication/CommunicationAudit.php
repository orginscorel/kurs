<?php

namespace App\Services\Communication;

use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Audit::log sarmalayıcı (bkz. App\Services\Finance\FinanceAudit — aynı desen). 2026-09-16'dan beri tüm
 * modeller morf haritasında; aşağıdaki geri düşüş yalnız haritaya eklenmemiş YENİ bir model için güvenlik ağıdır. Morph haritasında
 * olmayan iletişim modelleri (AutomationRule, MessageTemplate, Announcement, Integration, Webhook)
 * doğrudan konu olarak yazılamaz (AppServiceProvider::enforceMorphMap). Bu durumda konu, değişiklik
 * verisine {subject: {type, id}} olarak eklenir. Haritaya eklendiklerinde otomatik gerçek konu olur.
 */
final class CommunicationAudit
{
    public static function log(string $action, string $description, ?Model $subject = null, ?array $changes = null): void
    {
        if ($subject !== null && Relation::getMorphAlias($subject::class) === $subject::class) {
            $changes = ['subject' => ['type' => class_basename($subject), 'id' => $subject->getKey()]] + ($changes ?? []);
            $subject = null;
        }

        Audit::log($action, $description, $subject, $changes);
    }
}
