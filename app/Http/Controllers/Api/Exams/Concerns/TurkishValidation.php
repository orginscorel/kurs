<?php

namespace App\Http\Controllers\Api\Exams\Concerns;

/**
 * Uygulamada lang/tr/validation.php olmadığından doğrulama mesajları "validation.required"
 * olarak dönüyor. Sınav uçları bu Türkçe kural mesajlarını kullanır; genel çözüm
 * APP_LOCALE=tr + lang/tr/validation.php.
 */
trait TurkishValidation
{
    /** @return array<string, string> */
    protected function messages(array $extra = []): array
    {
        return $extra + [
            'required' => ':attribute alanı zorunludur.',
            'required_if' => ':attribute alanı zorunludur.',
            'present' => ':attribute alanı gönderilmelidir.',
            'string' => ':attribute metin olmalıdır.',
            'integer' => ':attribute tam sayı olmalıdır.',
            'numeric' => ':attribute sayı olmalıdır.',
            'boolean' => ':attribute evet/hayır olmalıdır.',
            'array' => ':attribute liste olmalıdır.',
            'date' => ':attribute geçerli bir tarih olmalıdır.',
            'in' => ':attribute için geçersiz değer.',
            'exists' => 'Seçilen :attribute bulunamadı.',
            'min' => ':attribute en az :min olmalıdır.',
            'max' => ':attribute en fazla :max olabilir.',
            'file' => ':attribute bir dosya olmalıdır.',
            'mimes' => ':attribute türü desteklenmiyor.',
            'prohibited' => ':attribute sonradan değiştirilemez.',
            'regex' => ':attribute biçimi geçersiz.',
        ];
    }

    /** @return array<string, string> */
    protected function attributes(): array
    {
        return [
            'exam_type_id' => 'Sınav türü', 'name' => 'Deneme adı', 'exam_date' => 'Sınav tarihi', 'publisher' => 'Yayınevi', 'scope' => 'Kapsam',
            'booklets' => 'Kitapçıklar', 'wrong_penalty_ratio' => 'Yanlış cezası oranı', 'base_score' => 'Taban puan', 'academic_term_id' => 'Dönem',
            'sections' => 'Bölümler', 'sections.*.code' => 'Bölüm kodu', 'sections.*.name' => 'Bölüm adı', 'sections.*.question_count' => 'Soru sayısı', 'sections.*.coefficient' => 'Katsayı',
            'sections.*.answers' => 'Cevaplar', 'sections.*.booklet_orders' => 'Kitapçık sırası', 'sections.*.topics' => 'Konular',
            'student_id' => 'Öğrenci', 'booklet' => 'Kitapçık', 'answers' => 'Cevaplar', 'answers.*' => 'Bölüm cevapları', 'file' => 'Dosya', 'format' => 'Biçim', 'mapping' => 'Kolon eşleşmesi',
            'save_layout_as' => 'Şablon adı', 'rows' => 'Satırlar', 'rows.*.student_no' => 'Öğrenci no', 'rows.*.answers' => 'Cevaplar',
            'section_code' => 'Bölüm', 'from' => 'Başlangıç', 'to' => 'Bitiş', 'topic_id' => 'Konu', 'cancelled' => 'İptal durumu',
        ];
    }
}
