<?php

namespace App\Services\Imports;

use App\Services\Staff\TeacherService;

/**
 * Öğretmen içe aktarma. Kayıt TeacherService::create ile (branş eşitleme, isteğe bağlı sistem kullanıcısı).
 */
class TeacherImporter extends RowImporter
{
    /** @var array<string, int> katlanmış ders adı/kodu => ders id */
    private array $subjects;

    public function __construct(?callable $lookup = null, array $subjects = [])
    {
        parent::__construct($lookup);
        $this->subjects = $subjects;
    }

    public function entity(): string
    {
        return 'teachers';
    }

    public function label(): string
    {
        return 'Öğretmen';
    }

    public function permission(): string
    {
        return 'teachers.manage';
    }

    public function columns(): array
    {
        return [
            'first_name' => ['label' => 'Ad', 'required' => true, 'example' => 'Mehmet', 'aliases' => ['Adı', 'Öğretmen Adı'], 'width' => 16],
            'last_name' => ['label' => 'Soyad', 'required' => true, 'example' => 'Kaya', 'aliases' => ['Soyadı', 'Öğretmen Soyadı'], 'width' => 16],
            'title' => ['label' => 'Unvan', 'example' => 'Öğretmen', 'hint' => 'Örn. Öğretmen, Uzman Öğretmen, Rehber Öğretmen', 'width' => 16],
            'specialty' => ['label' => 'Branş', 'example' => 'Matematik', 'hint' => 'Serbest metin (örn. Matematik)', 'aliases' => ['Uzmanlık', 'Alan'], 'width' => 16],
            'subjects' => ['label' => 'Verdiği Dersler', 'example' => 'Matematik, Geometri', 'hint' => 'Virgülle ayırın; ders adı ya da kodu. Tanımlı olmayan dersler atlanır.', 'aliases' => ['Dersler', 'Ders'], 'width' => 26],
            'phone' => ['label' => 'Telefon', 'example' => '0532 444 55 66', 'aliases' => ['Cep Telefonu', 'GSM'], 'width' => 16],
            'email' => ['label' => 'E-posta', 'example' => '', 'aliases' => ['Eposta', 'Mail'], 'width' => 22],
            'employment_type' => ['label' => 'Çalışma Şekli', 'example' => 'Tam zamanlı', 'hint' => 'Tam zamanlı / Yarı zamanlı / Saatlik (boşsa Tam zamanlı)', 'aliases' => ['Çalışma Türü', 'Kadro'], 'width' => 14],
            'hired_on' => ['label' => 'İşe Başlama', 'example' => '', 'hint' => 'GG.AA.YYYY', 'aliases' => ['İşe Başlama Tarihi', 'Başlama Tarihi'], 'width' => 13],
            'max_weekly_hours' => ['label' => 'Haftalık Azami Saat', 'example' => '30', 'hint' => 'Ders programında aşılmayacak üst sınır', 'aliases' => ['Azami Saat', 'Haftalık Saat'], 'width' => 12],
            'create_user' => ['label' => 'Sistem Kullanıcısı', 'example' => 'Evet', 'hint' => 'Evet ise öğretmen paneli için kullanıcı açılır; geçici şifre içe aktarma sonunda bir kez gösterilir.', 'aliases' => ['Kullanıcı Aç', 'Hesap Aç'], 'width' => 12],
            'username' => ['label' => 'Kullanıcı Adı', 'example' => '', 'hint' => 'Boşsa ad.soyad', 'width' => 16],
            'notes' => ['label' => 'Notlar', 'example' => '', 'aliases' => ['Not', 'Açıklama'], 'width' => 28],
        ];
    }

    public function validate(array $raw, int $rowNumber, array &$seen): array
    {
        $errors = [];
        $warnings = [];

        $first = $this->maxLen($this->required($raw, 'first_name', $errors, [ImportValue::class, 'name']), 80, 'Ad', $errors);
        $last = $this->maxLen($this->required($raw, 'last_name', $errors, [ImportValue::class, 'name']), 80, 'Soyad', $errors);

        if ($first && $last) {
            if ($row = $this->seenBefore($seen, 'name', ImportValue::fold("{$first} {$last}"), $rowNumber)) {
                $errors[] = "Aynı öğretmen dosyada {$row}. satırda da var.";
            } elseif ($this->existing('teacher_name', "{$first} {$last}")) {
                $errors[] = "{$first} {$last} adlı öğretmen zaten kayıtlı.";
            }
        }

        $phone = ImportValue::phone($raw['phone'] ?? null);
        if ($phone === false) {
            $errors[] = 'Telefon biçimi hatalı ('.ImportValue::text($raw['phone']).'). Örnek: 0532 444 55 66';
            $phone = null;
        } elseif ($phone !== null) {
            if ($row = $this->seenBefore($seen, 'phone', $phone, $rowNumber)) {
                $errors[] = "Bu telefon dosyada {$row}. satırda da var.";
            } elseif ($who = $this->existing('teacher_phone', $phone)) {
                $errors[] = "Bu telefonla kayıtlı öğretmen var: {$who}.";
            }
        }

        $email = ImportValue::email($raw['email'] ?? null);
        if ($email === false) {
            $errors[] = 'E-posta adresi geçersiz.';
            $email = null;
        } elseif ($email !== null && ($row = $this->seenBefore($seen, 'email', $email, $rowNumber))) {
            $errors[] = "Bu e-posta dosyada {$row}. satırda da var.";
        }

        $employment = ImportValue::choice($raw['employment_type'] ?? null, ImportValue::EMPLOYMENT);
        if ($employment === false) {
            $warnings[] = 'Çalışma şekli anlaşılamadı ("'.ImportValue::text($raw['employment_type']).'"); Tam zamanlı kabul edilecek.';
            $employment = null;
        }

        $hired = ImportValue::date($raw['hired_on'] ?? null);
        if ($hired === false) {
            $errors[] = 'İşe başlama tarihi okunamadı; GG.AA.YYYY biçiminde yazın.';
            $hired = null;
        }

        $maxHours = ImportValue::text($raw['max_weekly_hours'] ?? null);
        if ($maxHours !== null) {
            if (! preg_match('/^\d{1,3}$/', $maxHours) || (int) $maxHours > 168) {
                $errors[] = 'Haftalık azami saat 0–168 arası tam sayı olmalı.';
                $maxHours = null;
            } else {
                $maxHours = (int) $maxHours;
            }
        }

        $subjectIds = [];
        $subjectText = ImportValue::text($raw['subjects'] ?? null);
        if ($subjectText !== null) {
            $unknown = [];
            foreach (preg_split('/[,;\/]+/u', $subjectText) as $part) {
                $key = ImportValue::fold($part);
                if ($key === '') {
                    continue;
                }
                if (isset($this->subjects[$key])) {
                    $subjectIds[] = $this->subjects[$key];
                } else {
                    $unknown[] = trim($part);
                }
            }
            if ($unknown) {
                $warnings[] = 'Tanımlı olmayan ders atlanacak: '.implode(', ', $unknown).'. Önce Akademik > Dersler ekranından ekleyin.';
            }
        }

        $createUser = ImportValue::bool($raw['create_user'] ?? null, false);
        $username = ImportValue::text($raw['username'] ?? null);
        if ($username !== null) {
            if (! $createUser) {
                $warnings[] = 'Kullanıcı adı yazılmış ama "Sistem Kullanıcısı" Evet değil; kullanıcı açılmayacak.';
            } elseif (! preg_match('/^[A-Za-z0-9._\-]{3,60}$/', $username)) {
                $errors[] = 'Kullanıcı adı 3–60 karakter; harf, rakam, nokta, tire içerebilir (Türkçe karakter yok).';
            } elseif ($this->existing('username', mb_strtolower($username))) {
                $warnings[] = "\"{$username}\" kullanıcı adı dolu; sonuna sayı eklenecek.";
            }
        }
        if ($createUser && ! $phone && ! $email) {
            $warnings[] = 'Sistem kullanıcısı için telefon ya da e-posta girilmesi önerilir.';
        }

        return [
            'data' => [
                'first_name' => $first, 'last_name' => $last,
                'title' => $this->maxLen(ImportValue::text($raw['title'] ?? null), 60, 'Unvan', $errors),
                'specialty' => $this->maxLen(ImportValue::text($raw['specialty'] ?? null), 120, 'Branş', $errors),
                'phone' => $phone, 'whatsapp_phone' => ImportValue::isMobile($phone) ? $phone : null, 'email' => $email,
                'employment_type' => $employment ?? 'full_time', 'hired_on' => $hired, 'max_weekly_hours' => $maxHours,
                'subject_ids' => array_values(array_unique($subjectIds)),
                'create_user' => $createUser, 'username' => $createUser ? $username : null,
                'notes' => $this->maxLen(ImportValue::text($raw['notes'] ?? null), 2000, 'Notlar', $errors),
            ],
            'errors' => $errors,
            'warnings' => $warnings,
            'title' => trim(($first ?? '').' '.($last ?? '')) ?: '(adsız satır)',
            'subtitle' => implode(' · ', array_filter([
                ImportValue::text($raw['specialty'] ?? null),
                $subjectIds ? count($subjectIds).' ders' : null,
                $createUser ? 'Kullanıcı açılacak' : null,
            ])) ?: null,
        ];
    }

    public function import(array $data): array
    {
        $payload = array_filter($data, fn ($v) => $v !== null && $v !== '');
        $payload['subject_ids'] = $data['subject_ids'];
        $payload['is_active'] = true;

        $result = app(TeacherService::class)->create($payload);
        $teacher = $result['teacher'];

        return [
            'id' => $teacher->id,
            'title' => $teacher->full_name,
            'note' => $result['temp_password'] ? 'Kullanıcı: '.($teacher->user?->username ?? '') : null,
            'secret' => $result['temp_password'],
        ];
    }
}
