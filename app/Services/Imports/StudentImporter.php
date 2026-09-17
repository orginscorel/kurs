<?php

namespace App\Services\Imports;

use App\Models\Guardian;
use App\Services\Students\StudentService;

/**
 * Öğrenci + veli içe aktarma. Kayıt StudentService::create ile açılır (veli eşitleme,
 * portal hesabı, denetim kaydı ve olaylar tek yoldan).
 */
class StudentImporter extends RowImporter
{
    public const GRADES = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', 'Mezun'];

    public function entity(): string
    {
        return 'students';
    }

    public function label(): string
    {
        return 'Öğrenci';
    }

    public function permission(): string
    {
        return 'students.create';
    }

    public function columns(): array
    {
        return [
            'first_name' => ['label' => 'Ad', 'required' => true, 'example' => 'Elif', 'aliases' => ['Öğrenci Adı', 'Adı'], 'width' => 16],
            'last_name' => ['label' => 'Soyad', 'required' => true, 'example' => 'Yılmaz', 'aliases' => ['Soyadı', 'Öğrenci Soyadı'], 'width' => 16],
            'national_id' => ['label' => 'TC Kimlik No', 'example' => '10000000146', 'hint' => '11 hane; boş bırakılabilir. Hücre biçimi "Metin" olmalı.', 'aliases' => ['TC', 'TC No', 'TC Kimlik', 'Kimlik No', 'TCKN'], 'width' => 16],
            'student_no' => ['label' => 'Öğrenci No', 'example' => '', 'hint' => 'Boşsa sistem otomatik numara verir (portal kullanıcı adı da budur).', 'aliases' => ['Okul No', 'Numara', 'No'], 'width' => 12],
            'birth_date' => ['label' => 'Doğum Tarihi', 'example' => '14.03.2009', 'hint' => 'GG.AA.YYYY', 'width' => 14],
            'gender' => ['label' => 'Cinsiyet', 'example' => 'Kız', 'hint' => 'Kız / Erkek', 'width' => 10],
            'phone' => ['label' => 'Telefon', 'example' => '0532 111 22 33', 'hint' => 'Öğrencinin kendi numarası (isteğe bağlı)', 'aliases' => ['Öğrenci Telefonu', 'Cep Telefonu', 'GSM'], 'width' => 16],
            'email' => ['label' => 'E-posta', 'example' => '', 'aliases' => ['Eposta', 'Mail', 'E-mail'], 'width' => 22],
            'school_name' => ['label' => 'Okul', 'example' => 'Erbaa Anadolu Lisesi', 'aliases' => ['Okulu', 'Okul Adı'], 'width' => 24],
            'school_grade' => ['label' => 'Sınıf', 'example' => '12', 'hint' => '5–12 arası ya da Mezun', 'aliases' => ['Sınıf Seviyesi', 'Seviye'], 'width' => 8],
            'field' => ['label' => 'Alan', 'example' => 'SAY', 'hint' => 'SAY / EA / SÖZ / DİL / TYT / LGS', 'width' => 8],
            'target_department' => ['label' => 'Hedef Bölüm', 'example' => '', 'aliases' => ['Hedef'], 'width' => 18],
            'address' => ['label' => 'Adres', 'example' => '', 'width' => 28],
            'registered_on' => ['label' => 'Kayıt Tarihi', 'example' => '', 'hint' => 'Boşsa bugün', 'width' => 13],
            'guardian_first_name' => ['label' => 'Veli Adı', 'required' => true, 'example' => 'Ayşe', 'width' => 16],
            'guardian_last_name' => ['label' => 'Veli Soyadı', 'required' => true, 'example' => 'Yılmaz', 'width' => 16],
            'guardian_phone' => ['label' => 'Veli Telefonu', 'required' => true, 'example' => '0533 222 33 44', 'hint' => 'Aynı numaralı kayıtlı veli varsa öğrenci ona bağlanır (kardeşler).', 'aliases' => ['Veli Tel', 'Veli GSM', 'Veli Cep'], 'width' => 16],
            'guardian_relationship' => ['label' => 'Yakınlık', 'example' => 'Anne', 'hint' => 'Anne / Baba / Vasi / Diğer', 'aliases' => ['Veli Yakınlığı', 'Yakınlık Derecesi'], 'width' => 10],
            'guardian_email' => ['label' => 'Veli E-posta', 'example' => '', 'aliases' => ['Veli Eposta', 'Veli Mail'], 'width' => 22],
            'guardian_occupation' => ['label' => 'Veli Meslek', 'example' => '', 'aliases' => ['Veli Mesleği'], 'width' => 16],
            'whatsapp_consent' => ['label' => 'WhatsApp İzni', 'example' => 'Evet', 'hint' => 'Veliye bilgilendirme mesajı izni: Evet / Hayır (boşsa Evet)', 'aliases' => ['Veli WhatsApp İzni', 'İleti İzni'], 'width' => 12],
            'notes' => ['label' => 'Notlar', 'example' => '', 'aliases' => ['Not', 'Açıklama'], 'width' => 28],
        ];
    }

    public function validate(array $raw, int $rowNumber, array &$seen): array
    {
        $errors = [];
        $warnings = [];
        $c = $this->columns();

        $first = $this->maxLen($this->required($raw, 'first_name', $errors, [ImportValue::class, 'name']), 80, 'Ad', $errors);
        $last = $this->maxLen($this->required($raw, 'last_name', $errors, [ImportValue::class, 'name']), 80, 'Soyad', $errors);

        $nationalId = ImportValue::nationalId($raw['national_id'] ?? null);
        if ($nationalId === false) {
            $errors[] = 'TC kimlik numarası geçersiz ('.ImportValue::text($raw['national_id']).').';
            $nationalId = null;
        } elseif ($nationalId !== null) {
            if ($row = $this->seenBefore($seen, 'national_id', $nationalId, $rowNumber)) {
                $errors[] = "Bu TC kimlik numarası dosyada {$row}. satırda da var.";
            } elseif ($who = $this->existing('national_id', $nationalId)) {
                $errors[] = "Bu TC kimlik numarasıyla kayıtlı öğrenci var: {$who}.";
            }
        }

        $studentNo = ImportValue::text($raw['student_no'] ?? null);
        if ($studentNo !== null) {
            if (! preg_match('/^[A-Za-z0-9\-_.]{1,20}$/', $studentNo)) {
                $errors[] = 'Öğrenci no yalnızca harf, rakam, tire ve nokta içerebilir (en fazla 20 karakter).';
            } elseif ($row = $this->seenBefore($seen, 'student_no', $studentNo, $rowNumber)) {
                $errors[] = "Öğrenci no {$studentNo} dosyada {$row}. satırda da var.";
            } elseif ($who = $this->existing('student_no', $studentNo)) {
                $errors[] = "Öğrenci no {$studentNo} başka bir öğrencide kullanılıyor: {$who}.";
            } elseif ($this->existing('username', $studentNo)) {
                $errors[] = "Öğrenci no {$studentNo} bir kullanıcı adıyla çakışıyor; başka numara verin ya da boş bırakın.";
            }
        }

        if ($first && $last && ! $nationalId && ($who = $this->existing('student_name', "{$first} {$last}"))) {
            $warnings[] = "Aynı adla kayıtlı öğrenci var ({$who}); yinelenen kayıt olmadığından emin olun.";
        }
        if ($first && $last && ($row = $this->seenBefore($seen, 'name', ImportValue::fold("{$first} {$last}"), $rowNumber)) && ! $nationalId) {
            $warnings[] = "Aynı ad soyad dosyada {$row}. satırda da geçiyor.";
        }

        $birth = ImportValue::date($raw['birth_date'] ?? null);
        if ($birth === false) {
            $errors[] = 'Doğum tarihi okunamadı; GG.AA.YYYY biçiminde yazın.';
            $birth = null;
        } elseif ($birth !== null && ($birth >= date('Y-m-d') || $birth < '1940-01-01')) {
            $errors[] = 'Doğum tarihi geçerli bir geçmiş tarih olmalı.';
            $birth = null;
        }

        $registered = ImportValue::date($raw['registered_on'] ?? null);
        if ($registered === false) {
            $errors[] = 'Kayıt tarihi okunamadı; GG.AA.YYYY biçiminde yazın.';
            $registered = null;
        }

        $gender = ImportValue::choice($raw['gender'] ?? null, ImportValue::GENDERS);
        if ($gender === false) {
            $warnings[] = 'Cinsiyet anlaşılamadı ("'.ImportValue::text($raw['gender']).'"); boş bırakılacak.';
            $gender = null;
        }

        $phone = ImportValue::phone($raw['phone'] ?? null);
        if ($phone === false) {
            $errors[] = 'Öğrenci telefonu biçimi hatalı ('.ImportValue::text($raw['phone']).'). Örnek: 0532 111 22 33';
            $phone = null;
        }

        $email = ImportValue::email($raw['email'] ?? null);
        if ($email === false) {
            $errors[] = 'Öğrenci e-posta adresi geçersiz.';
            $email = null;
        }

        $grade = ImportValue::text($raw['school_grade'] ?? null);
        if ($grade !== null) {
            $g = ImportValue::fold($grade);
            if (preg_match('/^(\d{1,2})( sinif)?$/', $g, $m) && in_array($m[1], self::GRADES, true)) {
                $grade = $m[1];
            } elseif (str_starts_with($g, 'mezun')) {
                $grade = 'Mezun';
            } else {
                $warnings[] = "Sınıf \"{$grade}\" tanınmadı; olduğu gibi kaydedilecek.";
                $grade = mb_substr($grade, 0, 20);
            }
        }

        $field = ImportValue::choice($raw['field'] ?? null, ImportValue::FIELDS);
        if ($field === false) {
            $warnings[] = 'Alan anlaşılamadı ("'.ImportValue::text($raw['field']).'"); boş bırakılacak.';
            $field = null;
        }

        // Veli
        $gFirst = $this->maxLen($this->required($raw, 'guardian_first_name', $errors, [ImportValue::class, 'name']), 80, 'Veli adı', $errors);
        $gLast = $this->maxLen($this->required($raw, 'guardian_last_name', $errors, [ImportValue::class, 'name']), 80, 'Veli soyadı', $errors);
        $gPhoneRaw = $raw['guardian_phone'] ?? null;
        $gPhone = ImportValue::phone($gPhoneRaw);
        if (ImportValue::text($gPhoneRaw) === null) {
            $errors[] = 'Veli telefonu boş olamaz.';
            $gPhone = null;
        } elseif ($gPhone === false) {
            $errors[] = 'Veli telefonu biçimi hatalı ('.ImportValue::text($gPhoneRaw).'). Örnek: 0533 222 33 44';
            $gPhone = null;
        } elseif (! ImportValue::isMobile($gPhone)) {
            $warnings[] = 'Veli telefonu cep telefonu değil; WhatsApp bildirimi gitmeyebilir.';
        }
        if ($gPhone && $phone && $gPhone === $phone) {
            $warnings[] = 'Öğrenci ve veli telefonu aynı.';
        }

        $existingGuardianId = null;
        if ($gPhone) {
            $match = $this->existing('guardian_phone', $gPhone);
            if ($match !== null) {
                [$gid, $gname] = explode('|', $match, 2) + [1 => ''];
                $existingGuardianId = (int) $gid;
                $warnings[] = "Kayıtlı veli {$gname} ile eşleştirilecek (aynı telefon); veli bilgileri değiştirilmez.";
            } elseif ($row = $this->seenBefore($seen, 'guardian_phone', $gPhone, $rowNumber)) {
                $warnings[] = "Veli, {$row}. satırdaki veliyle aynı kişi olarak bağlanacak (kardeş).";
            }
        }

        $relationship = ImportValue::choice($raw['guardian_relationship'] ?? null, ImportValue::RELATIONSHIPS);
        if ($relationship === false) {
            $warnings[] = 'Yakınlık anlaşılamadı ("'.ImportValue::text($raw['guardian_relationship']).'"); "Veli" olarak kaydedilecek.';
            $relationship = null;
        }

        $gEmail = ImportValue::email($raw['guardian_email'] ?? null);
        if ($gEmail === false) {
            $errors[] = 'Veli e-posta adresi geçersiz.';
            $gEmail = null;
        }

        $consentText = ImportValue::text($raw['whatsapp_consent'] ?? null);
        $consent = ImportValue::bool($raw['whatsapp_consent'] ?? null, null);
        if ($consentText !== null && $consent === null) {
            $warnings[] = "WhatsApp izni \"{$consentText}\" anlaşılamadı; izin verilmedi sayılacak.";
            $consent = false;
        }

        $data = [
            'first_name' => $first, 'last_name' => $last, 'national_id' => $nationalId, 'student_no' => $studentNo,
            'birth_date' => $birth, 'gender' => $gender, 'phone' => $phone, 'email' => $email,
            'school_name' => $this->maxLen(ImportValue::text($raw['school_name'] ?? null), 160, $c['school_name']['label'], $errors),
            'school_grade' => $grade, 'field' => $field,
            'target_department' => $this->maxLen(ImportValue::text($raw['target_department'] ?? null), 160, $c['target_department']['label'], $errors),
            'address' => $this->maxLen(ImportValue::text($raw['address'] ?? null), 500, 'Adres', $errors),
            'registered_on' => $registered,
            'notes' => $this->maxLen(ImportValue::text($raw['notes'] ?? null), 2000, 'Notlar', $errors),
            'guardian' => [
                'id' => $existingGuardianId, 'first_name' => $gFirst, 'last_name' => $gLast, 'phone' => $gPhone,
                'email' => $gEmail, 'occupation' => $this->maxLen(ImportValue::text($raw['guardian_occupation'] ?? null), 120, 'Veli meslek', $errors),
                'relationship' => $relationship ?? 'parent', 'whatsapp_consent' => $consent ?? true,
            ],
        ];

        return [
            'data' => $data,
            'errors' => $errors,
            'warnings' => $warnings,
            'title' => trim(($first ?? '').' '.($last ?? '')) ?: '(adsız satır)',
            'subtitle' => implode(' · ', array_filter([
                $studentNo ? "No {$studentNo}" : null,
                $grade ? ($grade === 'Mezun' ? 'Mezun' : "{$grade}. sınıf") : null,
                $gFirst ? "Veli: {$gFirst} {$gLast}" : null,
            ])) ?: null,
        ];
    }

    /** Telefonun son 10 hanesiyle kayıtlı veliyi bulur (biçim farkı önemsiz). */
    public static function guardianIdByPhone(?string $phone): ?int
    {
        $digits = substr(preg_replace('/\D/', '', (string) $phone), -10);
        if (strlen($digits) < 10) {
            return null;
        }
        $id = Guardian::query()
            ->where(fn ($q) => $q->whereRaw("RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) = ?", [$digits])
                ->orWhereRaw("RIGHT(REGEXP_REPLACE(whatsapp_phone, '[^0-9]', ''), 10) = ?", [$digits]))
            ->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }

    public function import(array $data): array
    {
        $g = $data['guardian'];
        // Kardeş: aynı dosyada önceki satırda açılan veli de bu anda veritabanında
        $guardianId = $g['id'] ?: self::guardianIdByPhone($g['phone']);

        $guardian = $guardianId
            ? ['id' => $guardianId, 'relationship' => $g['relationship'], 'is_primary' => true, 'is_financially_responsible' => true, 'whatsapp_consent' => $g['whatsapp_consent']]
            : [
                'first_name' => $g['first_name'], 'last_name' => $g['last_name'], 'phone' => $g['phone'], 'whatsapp_phone' => $g['phone'],
                'email' => $g['email'], 'occupation' => $g['occupation'], 'relationship' => $g['relationship'],
                'is_primary' => true, 'is_financially_responsible' => true, 'receives_notifications' => true, 'whatsapp_consent' => $g['whatsapp_consent'],
            ];

        $payload = array_filter([
            'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'national_id' => $data['national_id'],
            'student_no' => $data['student_no'], 'birth_date' => $data['birth_date'], 'gender' => $data['gender'],
            'phone' => $data['phone'], 'whatsapp_phone' => $data['phone'], 'email' => $data['email'],
            'school_name' => $data['school_name'], 'school_grade' => $data['school_grade'], 'field' => $data['field'],
            'target_department' => $data['target_department'], 'address' => $data['address'],
            'registered_on' => $data['registered_on'], 'notes' => $data['notes'],
        ], fn ($v) => $v !== null && $v !== '');
        $payload['status'] = 'active';
        $payload['guardians'] = [$guardian];

        $student = app(StudentService::class)->create($payload);

        return ['id' => $student->id, 'title' => "{$student->full_name} ({$student->student_no})", 'note' => $guardianId ? 'Kayıtlı veliye bağlandı' : null];
    }
}
