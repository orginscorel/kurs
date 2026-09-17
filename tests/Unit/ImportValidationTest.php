<?php

namespace Tests\Unit;

use App\Services\Imports\ImportValue;
use App\Services\Imports\StudentImporter;
use App\Services\Imports\TeacherImporter;
use PHPUnit\Framework\TestCase;

class ImportValidationTest extends TestCase
{
    private function studentRow(array $over = []): array
    {
        return array_merge([
            'first_name' => 'elif', 'last_name' => 'YILMAZ', 'national_id' => '10000000146', 'student_no' => null,
            'birth_date' => '14.03.2009', 'gender' => 'Kız', 'phone' => '532 111 22 33', 'school_grade' => '12. sınıf',
            'field' => 'sayısal', 'guardian_first_name' => 'Ayşe', 'guardian_last_name' => 'Yılmaz',
            'guardian_phone' => '0533 222 33 44', 'guardian_relationship' => 'Anne', 'whatsapp_consent' => 'Evet',
        ], $over);
    }

    public function test_value_parsers(): void
    {
        $this->assertSame('ogrenci no', ImportValue::fold('Öğrenci No *'));
        $this->assertSame('tc kimlik no', ImportValue::fold('TC KİMLİK NO (11 hane)'));

        $this->assertSame('05321112233', ImportValue::phone('0532 111 22 33'));
        $this->assertSame('05321112233', ImportValue::phone('+90 (532) 111-2233'));
        $this->assertSame('05321112233', ImportValue::phone(5321112233.0));
        $this->assertSame('03624441122', ImportValue::phone('0362 444 11 22'));
        $this->assertFalse(ImportValue::phone('12345'));
        $this->assertFalse(ImportValue::phone('yok'));
        $this->assertNull(ImportValue::phone('  '));
        $this->assertTrue(ImportValue::isMobile('05321112233'));
        $this->assertFalse(ImportValue::isMobile('03624441122'));

        $this->assertSame('2009-03-14', ImportValue::date('14.03.2009'));
        $this->assertSame('2009-03-14', ImportValue::date('2009-03-14'));
        $this->assertSame('2009-03-14', ImportValue::date(39886));
        $this->assertSame('2009-03-14', ImportValue::date(new \DateTimeImmutable('2009-03-14 00:00:00')));
        $this->assertFalse(ImportValue::date('31.02.2009'));
        $this->assertFalse(ImportValue::date('dün'));

        $this->assertSame('10000000146', ImportValue::nationalId('10000000146'));
        $this->assertSame('10000000146', ImportValue::nationalId(10000000146.0));
        $this->assertFalse(ImportValue::nationalId('12345678901'));
        $this->assertFalse(ImportValue::nationalId('1000000014'));

        $this->assertTrue(ImportValue::bool('EVET'));
        $this->assertFalse(ImportValue::bool('hayır'));
        $this->assertNull(ImportValue::bool('belki'));
        $this->assertSame('SOZ', ImportValue::choice('Sözel', ImportValue::FIELDS));
        $this->assertSame('mother', ImportValue::choice('ANNE', ImportValue::RELATIONSHIPS));
        $this->assertSame('hourly', ImportValue::choice('ek ders', ImportValue::EMPLOYMENT));
        $this->assertFalse(ImportValue::choice('uzay', ImportValue::GENDERS));

        $this->assertSame('Elif İpek', ImportValue::name('ELİF İPEK'));
        $this->assertSame('İsmail', ImportValue::name('ismail'));
        $this->assertSame('McDonald', ImportValue::name('McDonald'));
    }

    public function test_header_mapping_accepts_aliases_and_reports_missing(): void
    {
        $imp = new StudentImporter();
        $m = $imp->mapHeaders(['Adı', 'SOYADI', 'TC', 'Veli Adı *', 'Bilinmeyen', null]);
        $this->assertSame([0 => 'first_name', 1 => 'last_name', 2 => 'national_id', 3 => 'guardian_first_name'], $m['map']);
        $this->assertSame(['Bilinmeyen'], $m['unknown']);
        $this->assertSame(['Veli Soyadı', 'Veli Telefonu'], $m['missing']);
    }

    public function test_valid_student_row_is_normalized(): void
    {
        $seen = [];
        $v = (new StudentImporter())->validate($this->studentRow(), 2, $seen);
        $this->assertSame([], $v['errors']);
        $this->assertSame([], $v['warnings']);
        $d = $v['data'];
        $this->assertSame(['Elif', 'Yılmaz'], [$d['first_name'], $d['last_name']]);
        $this->assertSame('05321112233', $d['phone']);
        $this->assertSame('12', $d['school_grade']);
        $this->assertSame('SAY', $d['field']);
        $this->assertSame('female', $d['gender']);
        $this->assertSame('2009-03-14', $d['birth_date']);
        $this->assertSame('mother', $d['guardian']['relationship']);
        $this->assertTrue($d['guardian']['whatsapp_consent']);
        $this->assertSame('Elif Yılmaz', $v['title']);
    }

    public function test_student_row_errors(): void
    {
        $seen = [];
        $v = (new StudentImporter())->validate($this->studentRow([
            'first_name' => '', 'national_id' => '12345678901', 'phone' => '123', 'guardian_phone' => '', 'birth_date' => '2999-01-01',
        ]), 3, $seen);
        $this->assertContains('Ad boş olamaz.', $v['errors']);
        $this->assertContains('TC kimlik numarası geçersiz (12345678901).', $v['errors']);
        $this->assertContains('Veli telefonu boş olamaz.', $v['errors']);
        $this->assertContains('Doğum tarihi geçerli bir geçmiş tarih olmalı.', $v['errors']);
        $this->assertTrue((bool) array_filter($v['errors'], fn ($e) => str_starts_with($e, 'Öğrenci telefonu biçimi hatalı')));
    }

    public function test_student_duplicates_in_file_and_database(): void
    {
        $lookup = fn (string $kind, string $value) => match ([$kind, $value]) {
            ['student_no', '2026001'] => 'Ali Veli',
            ['national_id', '10000000078'] => 'Kayıtlı Öğrenci',
            ['guardian_phone', '05332223344'] => '42|Ayşe Yılmaz',
            default => null,
        };
        $imp = new StudentImporter($lookup);
        $seen = [];

        $first = $imp->validate($this->studentRow(['student_no' => '9001']), 2, $seen);
        $this->assertSame([], $first['errors']);
        $this->assertSame(42, $first['data']['guardian']['id']);
        $this->assertTrue((bool) array_filter($first['warnings'], fn ($w) => str_contains($w, 'Kayıtlı veli Ayşe Yılmaz')));

        $dup = $imp->validate($this->studentRow(['student_no' => '9001', 'first_name' => 'Can']), 3, $seen);
        $this->assertContains('Bu TC kimlik numarası dosyada 2. satırda da var.', $dup['errors']);

        $dupNo = $imp->validate($this->studentRow(['national_id' => null, 'student_no' => '9001', 'first_name' => 'Deniz']), 4, $seen);
        $this->assertContains('Öğrenci no 9001 dosyada 2. satırda da var.', $dupNo['errors']);

        $dbNo = $imp->validate($this->studentRow(['national_id' => '10000000078', 'student_no' => '2026001']), 5, $seen);
        $this->assertContains('Bu TC kimlik numarasıyla kayıtlı öğrenci var: Kayıtlı Öğrenci.', $dbNo['errors']);
        $this->assertContains('Öğrenci no 2026001 başka bir öğrencide kullanılıyor: Ali Veli.', $dbNo['errors']);
    }

    public function test_sibling_rows_share_guardian_with_warning(): void
    {
        $imp = new StudentImporter();
        $seen = [];
        $imp->validate($this->studentRow(['national_id' => null]), 2, $seen);
        $v = $imp->validate($this->studentRow(['national_id' => null, 'first_name' => 'Ece']), 3, $seen);
        $this->assertSame([], $v['errors']);
        $this->assertTrue((bool) array_filter($v['warnings'], fn ($w) => str_contains($w, '2. satırdaki veliyle')));
    }

    public function test_teacher_rows(): void
    {
        $lookup = fn (string $kind, string $value) => $kind === 'teacher_phone' && $value === '05329998877' ? 'Eski Öğretmen' : null;
        $imp = new TeacherImporter($lookup, ['matematik' => 3, 'mat' => 3, 'fizik' => 5]);
        $this->assertSame([], $imp->mapHeaders(['Ad', 'Soyad'])['missing']);

        $seen = [];
        $ok = $imp->validate(['first_name' => 'mehmet', 'last_name' => 'kaya', 'subjects' => 'Matematik, MAT; Fizik, Kimya', 'phone' => '0532 444 55 66',
            'employment_type' => 'Yarı zamanlı', 'max_weekly_hours' => '24', 'create_user' => 'Evet'], 2, $seen);
        $this->assertSame([], $ok['errors']);
        $this->assertSame([3, 5], $ok['data']['subject_ids']);
        $this->assertSame('part_time', $ok['data']['employment_type']);
        $this->assertSame(24, $ok['data']['max_weekly_hours']);
        $this->assertTrue($ok['data']['create_user']);
        $this->assertSame('05324445566', $ok['data']['whatsapp_phone']);
        $this->assertTrue((bool) array_filter($ok['warnings'], fn ($w) => str_contains($w, 'Kimya')));

        $dup = $imp->validate(['first_name' => 'Mehmet', 'last_name' => 'Kaya', 'phone' => '05324445566'], 3, $seen);
        $this->assertContains('Aynı öğretmen dosyada 2. satırda da var.', $dup['errors']);
        $this->assertContains('Bu telefon dosyada 2. satırda da var.', $dup['errors']);

        $db = $imp->validate(['first_name' => 'Zeynep', 'last_name' => 'Ak', 'phone' => '5329998877', 'max_weekly_hours' => '200', 'email' => 'x@'], 4, $seen);
        $this->assertContains('Bu telefonla kayıtlı öğretmen var: Eski Öğretmen.', $db['errors']);
        $this->assertContains('Haftalık azami saat 0–168 arası tam sayı olmalı.', $db['errors']);
        $this->assertContains('E-posta adresi geçersiz.', $db['errors']);
        $this->assertSame('full_time', $db['data']['employment_type']);
    }
}
