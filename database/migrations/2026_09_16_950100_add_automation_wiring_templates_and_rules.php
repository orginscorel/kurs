<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Otomasyon bağlantıları için eksik şablonlar + varsayılan kurallar (veri migration'ı, idempotent).
 * CoreSeeder donmuş olduğundan burada eklenir. Kurallar PASİF gelir: WhatsApp bağlanınca yönetici açar.
 * Var olan şablon/kurala dokunulmaz (firstOrCreate mantığı).
 */
return new class extends Migration
{
    private const SIGN = "\n\nErbaa Bilgi Eğitim";

    private function templates(): array
    {
        return [
            'payment.receipt' => ['Tahsilat makbuz bilgisi', "Sayın {{veli_adi}},\n\n{{ogrenci_adi}} için {{tarih}} tarihinde {{tutar}} TL ödemeniz alınmıştır (makbuz no: {{makbuz_no}}, {{odeme_yontemi}}). Kalan bakiye: {{kalan_bakiye}} TL.\n\nTeşekkür ederiz.".self::SIGN],
            'enrollment.welcome' => ['Kayıt: hoş geldiniz', "Sayın {{veli_adi}},\n\n{{ogrenci_adi}} için {{program_adi}} kaydı tamamlanmıştır (kayıt no: {{kayit_no}}). Ödeme planı: {{taksit_sayisi}} taksit, toplam {{net_tutar}} TL, ilk vade {{ilk_vade}}.\n\nAramıza hoş geldiniz!".self::SIGN],
            'homework.missed.student' => ['Ödev teslim edilmedi (öğrenci)', "Merhaba {{ogrenci_adi}},\n\n{{ders_adi}} dersinin \"{{odev_adi}}\" ödevi ({{teslim_tarihi}}) teslim edilmedi. Lütfen öğretmeninle görüş.".self::SIGN],
            'homework.missed.guardian' => ['Ödev teslim edilmedi (veli, tekrar)', "Sayın Velimiz,\n\nÖğrencimiz {{ogrenci_adi}} son 30 günde {{tekrar_sayisi}} ödevini teslim etmemiştir. Son olarak: {{ders_adi}} – \"{{odev_adi}}\". Bilginize sunarız.".self::SIGN],
        ];
    }

    private function rules(): array
    {
        return [
            ['Tahsilatta veliye makbuz bilgisi gönder', 'payment.received', null, [['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'payment.receipt']]],
            ['Yeni kayıtta veliye hoş geldin mesajı', 'enrollment.welcome', null, [['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'enrollment.welcome']]],
            ['Bugün kuruma gelmeyen öğrencinin velisine bildir', 'student.no_show_today', null, [['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'guardian.no_show_today']]],
            ['Ödev teslimi yarın: öğrenciye hatırlat', 'homework.due_tomorrow', null, [['type' => 'whatsapp', 'to' => 'student', 'template' => 'homework.reminder']]],
            ['Teslim edilmeyen ödevi öğrenciye bildir', 'homework.missed', null, [['type' => 'whatsapp', 'to' => 'student', 'template' => 'homework.missed.student']]],
            ['Ödev yapmamayı tekrarlayan öğrencinin velisine bildir', 'homework.missed', ['min_missed_count' => 2], [['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'homework.missed.guardian']]],
        ];
    }

    public function up(): void
    {
        $now = now();
        foreach (DB::table('branches')->pluck('id') as $branchId) {
            foreach ($this->templates() as $key => [$name, $body]) {
                $exists = DB::table('message_templates')->where('branch_id', $branchId)->where('key', $key)->where('channel', 'whatsapp')->exists();
                if (! $exists) {
                    DB::table('message_templates')->insert([
                        'branch_id' => $branchId, 'key' => $key, 'channel' => 'whatsapp', 'name' => $name, 'body' => $body,
                        'language' => 'tr', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }

            foreach ($this->rules() as [$name, $trigger, $conditions, $actions]) {
                $exists = DB::table('automation_rules')->where('branch_id', $branchId)->where('trigger', $trigger)->where('name', $name)->exists();
                if (! $exists) {
                    DB::table('automation_rules')->insert([
                        'branch_id' => $branchId, 'name' => $name, 'trigger' => $trigger,
                        'conditions' => $conditions ? json_encode($conditions) : null, 'actions' => json_encode($actions, JSON_UNESCAPED_UNICODE),
                        'delay_minutes' => 0, 'is_active' => false, 'run_count' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Yalnız hiç çalışmamış kurallar ve bu migration'ın şablon anahtarları kaldırılır.
        foreach ($this->rules() as [$name, $trigger]) {
            DB::table('automation_rules')->where('trigger', $trigger)->where('name', $name)->where('run_count', 0)->delete();
        }
        DB::table('message_templates')->whereIn('key', array_keys($this->templates()))->where('channel', 'whatsapp')->delete();
    }
};
