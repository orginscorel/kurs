<?php

namespace Database\Seeders;

use App\Models\AcademicTerm;
use App\Models\AutomationRule;
use App\Models\Branch;
use App\Models\ExamType;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Üretimde güvenle tekrar çalıştırılabilir (idempotent): mevcut kayıtları ezmez, eksikleri ekler.
 */
class CoreSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->firstOrCreate(['code' => 'ERBAA'], [
            'name' => 'Erbaa Merkez', 'city' => 'Tokat', 'address' => 'Erbaa / Tokat',
        ]);

        app(BranchContext::class)->run($branch->id, function () use ($branch) {
            $this->permissions();
            $this->term($branch);
            $this->finance($branch);
            $this->examTypes($branch);
            $this->templates($branch);
            $this->automations($branch);
            $this->admin($branch);
        });
    }

    private function permissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (Permissions::defaultRoles() as $name => $def) {
            $role = Role::findOrCreate($name, 'web');
            // Var olan role yalnızca ilk kurulumda yetki yaz; yönetici sonradan değiştirdiyse ezme.
            if ($role->wasRecentlyCreated && $def['permissions'] !== '*') {
                $role->syncPermissions($def['permissions']);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function term(Branch $branch): void
    {
        $year = now()->month >= 7 ? now()->year : now()->year - 1;

        AcademicTerm::query()->firstOrCreate(
            ['branch_id' => $branch->id, 'name' => "{$year}-".($year + 1)],
            ['starts_on' => "{$year}-09-01", 'ends_on' => ($year + 1).'-06-30', 'is_current' => true],
        );
    }

    private function finance(Branch $branch): void
    {
        foreach ([['cash', 'Merkez Kasa'], ['pos', 'POS Cihazı'], ['bank', 'Banka Hesabı']] as [$kind, $name]) {
            FinanceAccount::query()->firstOrCreate(['branch_id' => $branch->id, 'kind' => $kind, 'name' => $name]);
        }

        $categories = [
            'income' => ['student_payment' => 'Öğrenci ödemeleri', 'book_sale' => 'Kitap satışı', 'private_lesson' => 'Özel ders', 'other_income' => 'Diğer gelir'],
            'expense' => ['salary' => 'Maaş', 'rent' => 'Kira', 'electricity' => 'Elektrik', 'internet' => 'İnternet', 'food' => 'Yemek',
                'stationery' => 'Kırtasiye', 'advertising' => 'Reklam', 'cleaning' => 'Temizlik', 'maintenance' => 'Bakım', 'tax' => 'Vergi',
                'book_purchase' => 'Kitap alımı', 'other_expense' => 'Diğer gider'],
        ];

        foreach ($categories as $direction => $items) {
            foreach ($items as $code => $name) {
                FinanceCategory::query()->firstOrCreate(
                    ['branch_id' => $branch->id, 'direction' => $direction, 'code' => $code],
                    ['name' => $name, 'is_system' => $code === 'student_payment'],
                );
            }
        }
    }

    /**
     * Katsayılar yaklaşık değerlerdir; ÖSYM her yıl günceller. Ayarlar > Sınav Türleri'nden düzenlenir.
     */
    private function examTypes(Branch $branch): void
    {
        $types = [
            'TYT' => ['TYT', 0.25, 100, [
                ['TUR', 'Türkçe', 'TUR', 40, 3.3], ['SOS', 'Sosyal Bilimler', 'SOS', 20, 3.4],
                ['MAT', 'Temel Matematik', 'MAT', 40, 3.3], ['FEN', 'Temel Bilimler', 'FEN', 20, 3.4],
            ]],
            'AYT_SAY' => ['AYT Sayısal', 0.25, 100, [
                ['MAT', 'Matematik', 'MAT', 40, 3.0], ['FIZ', 'Fizik', 'FIZ', 14, 2.85],
                ['KIM', 'Kimya', 'KIM', 13, 3.07], ['BIY', 'Biyoloji', 'BIY', 13, 3.07],
            ]],
            'AYT_EA' => ['AYT Eşit Ağırlık', 0.25, 100, [
                ['TDE', 'Türk Dili ve Edebiyatı', 'EDB', 24, 3.0], ['TAR1', 'Tarih-1', 'TAR', 10, 2.8],
                ['COG1', 'Coğrafya-1', 'COG', 6, 3.33], ['MAT', 'Matematik', 'MAT', 40, 3.0],
            ]],
            'AYT_SOZ' => ['AYT Sözel', 0.25, 100, [
                ['TDE', 'Türk Dili ve Edebiyatı', 'EDB', 24, 3.0], ['TAR1', 'Tarih-1', 'TAR', 10, 2.8],
                ['COG1', 'Coğrafya-1', 'COG', 6, 3.33], ['TAR2', 'Tarih-2', 'TAR', 11, 2.91],
                ['COG2', 'Coğrafya-2', 'COG', 11, 2.91], ['FEL', 'Felsefe Grubu', 'FEL', 12, 3.0], ['DIN', 'Din Kültürü', 'DIN', 6, 3.33],
            ]],
            'LGS' => ['LGS', 0.33, 194.752082, [
                ['TUR', 'Türkçe', 'TUR', 20, 4.348], ['MAT', 'Matematik', 'MAT', 20, 4.2538],
                ['FEN', 'Fen Bilimleri', 'FEN', 20, 4.1230], ['INK', 'T.C. İnkılap Tarihi', 'TAR', 10, 1.666],
                ['DIN', 'Din Kültürü', 'DIN', 10, 1.899], ['ING', 'İngilizce', 'ING', 10, 1.5075],
            ]],
        ];

        foreach ($types as $code => [$name, $penalty, $base, $sections]) {
            ExamType::query()->firstOrCreate(['branch_id' => $branch->id, 'code' => $code], [
                'name' => $name,
                'wrong_penalty_ratio' => $penalty,
                'base_score' => $base,
                'sections' => array_map(fn ($s) => [
                    'code' => $s[0], 'name' => $s[1], 'subject_code' => $s[2], 'question_count' => $s[3], 'coefficient' => $s[4],
                ], $sections),
            ]);
        }
    }

    private function templates(Branch $branch): void
    {
        $sign = "\n\nErbaa Bilgi Eğitim";
        $templates = [
            'guardian.entry' => ['Veli: kuruma giriş', "Sayın Velimiz,\n\nÖğrencimiz {{ogrenci_adi}} saat {{saat}}'de kuruma giriş yapmıştır.".$sign],
            'guardian.exit' => ['Veli: kurumdan çıkış', "Sayın Velimiz,\n\nÖğrencimiz {{ogrenci_adi}} saat {{saat}}'de kurumdan çıkış yapmıştır.".$sign],
            'guardian.absent' => ['Veli: derse katılmadı', "Sayın Velimiz,\n\nÖğrencimiz {{ogrenci_adi}}'ın bugün saat {{ders_saati}}'da başlayan {{ders_adi}} dersine katılım kaydı bulunmamaktadır.".$sign],
            'guardian.late' => ['Veli: derse geç kaldı', "Sayın Velimiz,\n\nÖğrencimiz {{ogrenci_adi}} bugün saat {{ders_saati}}'da başlayan {{ders_adi}} dersine {{gec_dakika}} dakika geç katılmıştır.".$sign],
            'guardian.no_show_today' => ['Veli: bugün gelmedi', "Sayın Velimiz,\n\nÖğrencimiz {{ogrenci_adi}} bugün kuruma giriş yapmamıştır. Bilginize sunarız.".$sign],
            'payment.upcoming' => ['Ödeme hatırlatma (yaklaşan)', "Sayın {{veli_adi}},\n\n{{ogrenci_adi}} için {{vade_tarihi}} vadeli {{tutar}} TL tutarındaki taksit ödemenizi hatırlatırız.".$sign],
            'payment.due' => ['Ödeme hatırlatma (bugün)', "Sayın {{veli_adi}},\n\n{{ogrenci_adi}} için {{tutar}} TL tutarındaki taksitin son ödeme günü bugündür.".$sign],
            'payment.overdue' => ['Gecikmiş taksit', "Sayın {{veli_adi}},\n\n{{ogrenci_adi}} için {{vade_tarihi}} vadeli {{tutar}} TL tutarındaki taksit ödemesi {{gecikme_gun}} gündür gecikmiştir. Bilgi için kurumumuzu arayabilirsiniz.".$sign],
            'exam.result.student' => ['Deneme sonucu (öğrenci)', "Merhaba {{ogrenci_adi}},\n\n{{sinav_adi}} sonucun: {{toplam_net}} net, kurum sıran {{kurum_sirasi}}.\n{{ders_netleri}}".$sign],
            'exam.result.guardian' => ['Deneme sonucu (veli)', "Sayın Velimiz,\n\nÖğrencimiz {{ogrenci_adi}}'ın {{sinav_adi}} sonucu: {{toplam_net}} net, kurum sırası {{kurum_sirasi}}.\n{{ders_netleri}}".$sign],
            'student.schedule.tomorrow' => ['Yarınki ders programı', "Merhaba {{ogrenci_adi}},\n\nYarınki derslerin:\n{{ders_listesi}}".$sign],
            'lesson.reminder' => ['Ders hatırlatma', "Merhaba {{ogrenci_adi}}, {{ders_adi}} dersin {{dakika}} dakika sonra {{derslik}}'da başlayacak.".$sign],
            'lesson.cancelled' => ['Ders iptali', "Merhaba {{ogrenci_adi}}, {{tarih}} {{ders_saati}} {{ders_adi}} dersi iptal edilmiştir. {{aciklama}}".$sign],
            'lesson.room_changed' => ['Derslik değişikliği', "Merhaba {{ogrenci_adi}}, {{tarih}} {{ders_saati}} {{ders_adi}} dersi {{derslik}}'da yapılacaktır.".$sign],
            'homework.reminder' => ['Ödev hatırlatma', "Merhaba {{ogrenci_adi}}, \"{{odev_adi}}\" ödevinin son teslim zamanı {{teslim_tarihi}}.".$sign],
            'exam.reminder' => ['Sınav hatırlatma', "Merhaba {{ogrenci_adi}}, {{sinav_adi}} {{tarih}} tarihinde yapılacaktır.".$sign],
            'announcement' => ['Duyuru', "{{baslik}}\n\n{{metin}}".$sign],
        ];

        foreach ($templates as $key => [$name, $body]) {
            MessageTemplate::query()->firstOrCreate(
                ['branch_id' => $branch->id, 'key' => $key, 'channel' => 'whatsapp'],
                ['name' => $name, 'body' => $body],
            );
        }
    }

    /** Varsayılan otomasyonlar PASİF gelir: WhatsApp bağlanınca yönetici açar. */
    private function automations(Branch $branch): void
    {
        $rules = [
            ['Derse gelmeyen öğrencinin velisine bildir', 'attendance.absent', null, [['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'guardian.absent']], 15],
            ['Derse geç kalan öğrencinin velisine bildir', 'attendance.late', null, [['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'guardian.late']], 0],
            ['Kuruma girişte veliye bildir', 'student.entry', null, [['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'guardian.entry']], 0],
            ['Kurumdan çıkışta veliye bildir', 'student.exit', null, [['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'guardian.exit']], 0],
            ['Taksit 3 gün gecikince veliye hatırlat', 'installment.overdue', ['days' => 3], [['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'payment.overdue']], 0],
            ['Taksit vadesinden 2 gün önce hatırlat', 'installment.upcoming', ['days' => 2], [['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'payment.upcoming']], 0],
            ['Deneme sonucunu öğrenci ve veliye gönder', 'exam.result_published', null, [
                ['type' => 'whatsapp', 'to' => 'student', 'template' => 'exam.result.student', 'attach_report_card' => true],
                ['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'exam.result.guardian', 'attach_report_card' => true],
            ], 0],
            ['Akşam yarınki ders programını gönder', 'schedule.tomorrow', ['time' => '20:00'], [['type' => 'whatsapp', 'to' => 'student', 'template' => 'student.schedule.tomorrow']], 0],
        ];

        foreach ($rules as [$name, $trigger, $conditions, $actions, $delay]) {
            AutomationRule::query()->firstOrCreate(['branch_id' => $branch->id, 'trigger' => $trigger, 'name' => $name], [
                'conditions' => $conditions, 'actions' => $actions, 'delay_minutes' => $delay, 'is_active' => false,
            ]);
        }
    }

    private function admin(Branch $branch): void
    {
        if (User::query()->where('username', 'admin')->exists()) {
            return;
        }

        $password = env('KURS_ADMIN_PASSWORD') ?: Str::password(16, symbols: false);

        $user = User::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Sistem Yöneticisi',
            'username' => 'admin',
            'user_type' => User::TYPE_STAFF,
            'password' => $password,
            'must_change_password' => ! env('KURS_ADMIN_PASSWORD'),
        ]);
        $user->assignRole('super-admin');

        if (! env('KURS_ADMIN_PASSWORD')) {
            $file = storage_path('app/private/initial-admin-password.txt');
            @mkdir(dirname($file), 0700, true);
            file_put_contents($file, "admin\n{$password}\n");
            chmod($file, 0600);
            $this->command?->warn("İlk yönetici parolası: {$file} (ilk girişte değiştirilecek)");
        }
    }
}
