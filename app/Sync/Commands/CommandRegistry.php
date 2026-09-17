<?php

namespace App\Sync\Commands;

/**
 * Yerel düğümde yakalanıp sunucuda SERVİSLE yeniden yürütülen işlemler (finans kök belgeleri).
 * 'spec': argümandaki kimlik yolları → tablo (uuid'ye çevrilir; '*' joker, '[]' liste). 'root': sonucun tablosu.
 * Yeni finans işlemi çevrimdışı desteklenecekse: buraya tanım + FinanceCommands işleyicisi +
 * app/Sync/Local altında servis alt sınıfı (LocalCommandRecorder::run) + SyncServiceProvider bağlaması.
 * Tanımsız finans yazmaları yerelde ChangeRecorder::guard ile açık hatayla engellenir.
 */
final class CommandRegistry
{
    /**
     * Finans komutlarının ürettiği satırlar (uuid'leri iki tarafta aynı tutulur). Anlık oluşturulan katalog
     * satırları (hesap planı, finans kategorisi) pakete girmez; iki tarafta doğal anahtarla birleşir.
     */
    public const FINANCE_PRODUCTS = [
        'payments', 'payment_allocations', 'payment_card_details', 'account_transactions', 'journal_entries', 'journal_lines',
        'audit_logs', 'enrollments', 'installments', 'class_group_student', 'contracts',
        'refunds', 'refund_allocations', 'finance_entries', 'account_transfers', 'pos_settlements',
        'promissory_notes', 'collection_notes',
    ];

    /** @return list<string> */
    public static function produces(string $name): array
    {
        return self::FINANCE_PRODUCTS;
    }

    /** @return array<string, array{root: string, spec: array<string, string>, handler: array{0: class-string, 1: string}, label: string}> */
    public static function all(): array
    {
        $h = fn (string $method) => [FinanceCommands::class, $method];

        return [
            // ------------------------------------------------ tahsilat / kayıt
            'payment.collect' => [
                'label' => 'Tahsilat',
                'root' => 'payments',
                'spec' => [
                    'student' => 'students',
                    'data.finance_account_id' => 'finance_accounts',
                    'data.enrollment_id' => 'enrollments',
                    'data.guardian_id' => 'guardians',
                    'data.installment_ids' => 'installments[]',
                ],
                'handler' => $h('collect'),
            ],
            'payment.void' => ['label' => 'Tahsilat iptali', 'root' => 'payments', 'spec' => ['payment' => 'payments'], 'handler' => $h('void')],
            'payment.card_details' => ['label' => 'Kart komisyonu / taksit', 'root' => 'payments', 'spec' => ['payment' => 'payments'], 'handler' => $h('cardDetails')],
            'enrollment.create' => [
                'label' => 'Kayıt ve ödeme planı',
                'root' => 'enrollments',
                'spec' => [
                    'student' => 'students',
                    'data.academic_term_id' => 'academic_terms',
                    'data.program_id' => 'programs',
                    'data.education_package_id' => 'education_packages',
                    'data.class_group_id' => 'class_groups',
                    'data.financial_guardian_id' => 'guardians',
                ],
                'handler' => $h('enroll'),
            ],

            // ------------------------------------------------ iade
            'refund.create' => [
                'label' => 'İade',
                'root' => 'refunds',
                'spec' => ['payment' => 'payments', 'data.finance_account_id' => 'finance_accounts'],
                'handler' => $h('refund'),
            ],
            'refund.void' => ['label' => 'İade iptali', 'root' => 'refunds', 'spec' => ['refund' => 'refunds'], 'handler' => $h('refundVoid')],

            // ------------------------------------------------ gelir / gider
            'finance_entry.create' => [
                'label' => 'Gelir / gider',
                'root' => 'finance_entries',
                'spec' => ['data.finance_category_id' => 'finance_categories', 'data.finance_account_id' => 'finance_accounts'],
                'handler' => $h('entry'),
            ],
            'finance_entry.void' => ['label' => 'Gelir / gider iptali', 'root' => 'finance_entries', 'spec' => ['entry' => 'finance_entries'], 'handler' => $h('entryVoid')],

            // ------------------------------------------------ hesaplar arası aktarım
            'account_transfer.create' => [
                'label' => 'Hesaplar arası aktarım',
                'root' => 'account_transfers',
                'spec' => ['from' => 'finance_accounts', 'to' => 'finance_accounts'],
                'handler' => $h('transfer'),
            ],
            'account_transfer.void' => ['label' => 'Aktarım iptali', 'root' => 'account_transfers', 'spec' => ['transfer' => 'account_transfers'], 'handler' => $h('transferVoid')],

            // ------------------------------------------------ POS yatışı
            'pos_settlement.create' => [
                'label' => 'POS yatışı',
                'root' => 'pos_settlements',
                'spec' => [
                    'data.pos_account_id' => 'finance_accounts',
                    'data.bank_account_id' => 'finance_accounts',
                    'data.detail_ids' => 'payment_card_details[]',
                ],
                'handler' => $h('posSettle'),
            ],
            'pos_settlement.void' => ['label' => 'POS yatışı iptali', 'root' => 'pos_settlements', 'spec' => ['settlement' => 'pos_settlements'], 'handler' => $h('posVoid')],

            // ------------------------------------------------ senet (basım kaydı)
            // 'notes': cihazda yeni oluşan senetler taksit uuid'i => [senet uuid, senet no] (basılı numara korunur)
            'promissory_note.prepare' => [
                'label' => 'Senet hazırlama',
                'root' => 'promissory_notes',
                'spec' => ['installments' => 'installments[]'],
                'handler' => $h('notesPrepare'),
            ],
            'promissory_note.print' => [
                'label' => 'Senet basımı',
                'root' => 'promissory_notes',
                'spec' => ['notes' => 'promissory_notes[]'],
                'handler' => $h('notesPrinted'),
            ],

            // ------------------------------------------------ tahsilat takibi
            'collection_note.add' => [
                'label' => 'Tahsilat takip notu',
                'root' => 'collection_notes',
                'spec' => ['data.student_id' => 'students', 'data.guardian_id' => 'guardians', 'data.responsible_user_id' => 'users'],
                'handler' => $h('collectionAdd'),
            ],
            'collection_note.status' => [
                'label' => 'Tahsilat takip durumu',
                'root' => 'collection_notes',
                'spec' => ['note' => 'collection_notes', 'responsible_user_id' => 'users'],
                'handler' => $h('collectionStatus'),
            ],
            'collection_note.reminders' => [
                'label' => 'Hatırlatma taslakları',
                'root' => 'collection_notes',
                'spec' => ['student_ids' => 'students[]'],
                'handler' => $h('collectionReminders'),
            ],

            // ------------------------------------------------ ödeme planı
            'installment_plan.restructure' => [
                'label' => 'Ödeme planı düzenleme',
                'root' => 'enrollments',
                'spec' => ['enrollment' => 'enrollments', 'rows.*.id' => 'installments'],
                'handler' => $h('restructure'),
            ],
            'installment_plan.adjust_price' => [
                'label' => 'İndirim / burs düzenleme',
                'root' => 'enrollments',
                'spec' => ['enrollment' => 'enrollments'],
                'handler' => $h('adjustPrice'),
            ],
        ];
    }

    public static function get(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    public static function label(string $name): string
    {
        return self::get($name)['label'] ?? $name;
    }
}
