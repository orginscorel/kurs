<?php

namespace App\Services\Finance;

use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Audit::log sarmalayıcı. Morph haritasında olmayan modeller (FinanceAccount, FinanceCategory, Product,
 * EducationPackage) doğrudan konu olarak yazılamaz (enforceMorphMap). Bu durumda konu, değişiklik verisine
 * {subject: {type, id}} olarak eklenir. Haritaya eklendiklerinde otomatik olarak gerçek konu olarak yazılır.
 */
final class FinanceAudit
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
