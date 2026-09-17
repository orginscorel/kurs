<?php

namespace App\Http\Controllers\Api\Finance;

/**
 * Finans uçlarının Türkçe alan adları (+ tutar biçimi iletisi).
 * Tüm finans doğrulamaları FinanceController::validateTr üzerinden bu sözlüğü kullanır;
 * genel kural iletileri lang/tr/validation.php'dedir.
 */
final class FinanceValidation
{
    /** @return array<string, string> */
    public static function messages(): array
    {
        // Genel iletiler lang/tr/validation.php'de; burada yalnız finansa özgü olanlar.
        return [
            'regex' => ':attribute biçimi geçersiz. Örnek: 1500 ya da 1.500,50',
            'required_if' => ':attribute zorunlu.',
            'lines.*.quantity.regex' => 'Miktarı 1 ya da 1,5 biçiminde girin.',
            'lines.*.discount_rate.regex' => 'İndirim oranını 10 ya da 12,5 biçiminde girin.',
            'lines.*.vat_rate.regex' => 'KDV oranını 10 ya da 20 biçiminde girin.',
            'commission_rate.regex' => 'Komisyon oranını 2 ya da 2,5 biçiminde girin.',
            'pos_commission_rate.regex' => 'POS komisyon oranını 2 ya da 2,5 biçiminde girin.',
            'default_vat_rate.regex' => 'KDV oranını 10 ya da 20 biçiminde girin.',
            'vat_rates.*.regex' => 'KDV oranlarını 10 ya da 20 biçiminde girin.',
            'invoice_prefix.regex' => 'Fatura ön eki 3 harf/rakam olmalı (ör. EBE).',
            'return_prefix.regex' => 'İade ön eki 3 harf/rakam olmalı (ör. EBI).',
            'installment_ids.required' => 'En az bir taksit seçin.',
            'installment_ids.min' => 'En az bir taksit seçin.',
            'student_ids.required' => 'En az bir öğrenci seçin.',
            'student_ids.min' => 'En az bir öğrenci seçin.',
            'items.required' => 'En az bir öğrenci için tutar girin.',
            'items.min' => 'En az bir öğrenci için tutar girin.',
            'lines.required' => 'En az bir fatura satırı ekleyin.',
            'lines.min' => 'En az bir satır ekleyin.',
            'payment_ids.required' => 'En az bir tahsilat seçin.',
            'transaction_ids.required' => 'En az bir hareket seçin.',
            'enrollment_ids.required' => 'En az bir kayıt seçin.',
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            // ortak
            'student_id' => 'Öğrenci', 'student_ids' => 'Öğrenci seçimi', 'student_ids.*' => 'Öğrenci',
            'guardian_id' => 'Ödeyen veli', 'financial_guardian_id' => 'Ödeme sorumlusu veli',
            'amount' => 'Tutar', 'note' => 'Not', 'notes' => 'Notlar', 'reason' => 'Gerekçe', 'description' => 'Açıklama',
            'name' => 'Ad', 'code' => 'Kod', 'type' => 'Tür', 'kind' => 'Tür', 'status' => 'Durum', 'is_active' => 'Etkin',
            'from' => 'Başlangıç tarihi', 'to' => 'Bitiş tarihi', 'q' => 'Arama', 'per_page' => 'Sayfa başına kayıt',
            'sort_order' => 'Sıra', 'category' => 'Kategori', 'reference' => 'Referans', 'file' => 'Dosya',
            'academic_term_id' => 'Dönem', 'term_id' => 'Dönem', 'program_id' => 'Program', 'class_group_id' => 'Sınıf',

            // tahsilat
            'finance_account_id' => 'Kasa / banka hesabı', 'method' => 'Ödeme yöntemi', 'paid_at' => 'Ödeme tarihi',
            'installment_ids' => 'Taksit seçimi', 'installment_ids.*' => 'Taksit', 'payer_name' => 'Ödeyen adı',
            'idempotency_key' => 'İşlem anahtarı', 'overpayment' => 'Fazla ödeme tercihi',
            'commission_rate' => 'Komisyon oranı', 'card_installments' => 'Kart taksit sayısı',
            'items' => 'Öğrenci listesi', 'items.*.student_id' => 'Öğrenci', 'items.*.amount' => 'Öğrenci tutarı',
            'items.*.installment_ids' => 'Taksitler', 'items.*.installment_ids.*' => 'Taksit',
            'payment_id' => 'Tahsilat', 'payment_ids' => 'Tahsilat seçimi', 'payment_ids.*' => 'Tahsilat',
            'payments' => 'Tahsilat listesi', 'payments.*.payment_id' => 'Tahsilat', 'payments.*.amount' => 'Tahsilat tutarı',

            // kayıt ve plan
            'enrollment_id' => 'Kayıt', 'enrollment_ids' => 'Kayıt seçimi', 'enrollment_ids.*' => 'Kayıt',
            'education_package_id' => 'Eğitim paketi', 'enrolled_on' => 'Kayıt tarihi', 'list_price' => 'Liste fiyatı',
            'discount_amount' => 'İndirim tutarı', 'discount_reason' => 'İndirim gerekçesi',
            'scholarship_amount' => 'Burs tutarı', 'scholarship_reason' => 'Burs gerekçesi', 'down_payment' => 'Peşinat',
            'installment_count' => 'Taksit sayısı', 'first_due_date' => 'İlk taksit vadesi', 'create_contract' => 'Sözleşme oluştur',
            'rows' => 'Taksit satırları', 'rows.*.id' => 'Taksit', 'rows.*.amount' => 'Taksit tutarı', 'rows.*.due_date' => 'Vade tarihi',
            'signed_by_name' => 'İmzalayanın adı soyadı', 'default_installments' => 'Varsayılan taksit sayısı',

            // takip
            'promised_date' => 'Söz tarihi', 'promised_amount' => 'Söz verilen tutar', 'responsible_user_id' => 'Takip sorumlusu',
            'body' => 'Not', 'due_from' => 'Vade başlangıcı', 'due_to' => 'Vade bitişi', 'include_paid' => 'Ödenenler dahil',

            // hesap, gelir-gider, transfer
            'bank_name' => 'Banka adı', 'iban' => 'IBAN', 'opening_balance' => 'Açılış bakiyesi',
            'from_account_id' => 'Çıkış hesabı', 'to_account_id' => 'Giriş hesabı', 'transfer_date' => 'Transfer tarihi',
            'counted_balance' => 'Sayılan bakiye', 'counted' => 'Sayılan adet',
            'finance_category_id' => 'Kategori', 'entry_date' => 'İşlem tarihi', 'direction' => 'Yön',
            'document_no' => 'Belge no', 'counterparty' => 'Karşı taraf',

            // stok / katalog
            'barcode' => 'Barkod', 'publisher' => 'Yayınevi', 'unit_price' => 'Birim fiyat', 'purchase_price' => 'Alış fiyatı',
            'sale_price' => 'Satış fiyatı', 'min_stock' => 'Kritik stok', 'quantity' => 'Adet', 'charge' => 'Öğrenciye borç yaz',
            'default_unit' => 'Varsayılan birim', 'units' => 'Birimler',

            // fatura
            'buyer_type' => 'Alıcı türü', 'buyer_id' => 'Alıcı', 'buyer_name' => 'Alıcı adı / unvanı', 'buyer_tax_id' => 'TCKN / VKN',
            'buyer_tax_office' => 'Vergi dairesi', 'buyer_address' => 'Alıcı adresi', 'buyer_email' => 'Alıcı e-postası',
            'buyer_phone' => 'Alıcı telefonu', 'issue_date' => 'Fatura tarihi', 'document_type' => 'Belge türü',
            'lines' => 'Satırlar', 'lines.*.description' => 'Satır açıklaması', 'lines.*.quantity' => 'Miktar',
            'lines.*.unit' => 'Birim', 'lines.*.unit_price' => 'Birim fiyat', 'lines.*.vat_rate' => 'KDV oranı',
            'lines.*.discount_rate' => 'İndirim oranı', 'lines.*.discount_amount' => 'İndirim tutarı',
            'lines.*.withholding_tenths' => 'Tevkifat oranı', 'lines.*.code' => 'Hesap kodu',
            'lines.*.debit' => 'Borç', 'lines.*.credit' => 'Alacak',
            'invoice_prefix' => 'Fatura ön eki', 'return_prefix' => 'İade faturası ön eki', 'invoice_due_days' => 'Fatura vade günü',
            'invoice_note' => 'Fatura notu', 'default_vat_rate' => 'Varsayılan KDV oranı', 'vat_rates' => 'KDV oranları',
            'vat_rates.*' => 'KDV oranı', 'prices_include_vat' => 'Fiyatlara KDV dahil', 'service_description' => 'Hizmet açıklaması',
            'default_document_type' => 'Varsayılan belge türü', 'integrator' => 'Entegratör', 'withholding_enabled' => 'Tevkifat',

            // iade
            'refunded_at' => 'İade tarihi',

            // muhasebe
            'period' => 'Dönem', 'partner_type' => 'Cari türü', 'partner_id' => 'Cari', 'source_type' => 'Kaynak türü',
            'source_key' => 'Kaynak', 'auto_journal' => 'Otomatik yevmiye', 'income_account_id' => 'Gelir hesabı',
            'expense_account_id' => 'Gider hesabı',

            // mutabakat
            'bank_account_id' => 'Banka hesabı', 'pos_account_id' => 'POS hesabı', 'statement_date' => 'Ekstre tarihi',
            'statement_ref' => 'Ekstre referansı', 'transaction_ids' => 'Hareket seçimi', 'transaction_ids.*' => 'Hareket',
            'deposit_date' => 'Yatış tarihi', 'actual_net' => 'Hesaba geçen net tutar',
            'pos_commission_rate' => 'POS komisyon oranı', 'pos_settlement_days' => 'POS valör günü',
            'detail_ids' => 'Kalem seçimi', 'detail_ids.*' => 'Kalem',

            // senet
            'payee_name' => 'Alacaklı', 'note_place' => 'Düzenleme yeri', 'note_court' => 'Yetkili icra dairesi',
            'note_payee' => 'Alacaklı', 'note_acceleration' => 'Muacceliyet şartı', 'note_consideration' => 'Bedel',
            'copy' => 'Suret',
        ];
    }
}
