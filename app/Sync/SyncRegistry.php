<?php

namespace App\Sync;

/**
 * EŞİTLEME KAYIT DEFTERİ — hangi tablo nasıl eşitlenir? Tek doğruluk kaynağı.
 *
 * Yeni bir migration tablo eklediğinde buraya da eklenmelidir; `tests/Unit/SyncRegistryTest`
 * tanımsız tabloyu yakalar ("tanımsız tablo" hatası). Türler:
 *
 *  reference  İki yönlü, satır/alan bazında. Çakışmada alan bazında SON YAZAN KAZANIR, çakışma kaydı tutulur.
 *  keyed      İki yönlü, doğal anahtarla (ör. yoklama: ders + öğrenci). Aynı anahtar iki cihazda ayrı
 *             oluşturulsa bile tek satırda birleşir; son yazan kazanır, önceki değer tarihçede (sync_changes) kalır.
 *  append     İki yönlü, yalnız ekleme (olay günlükleri). Güncelleme/çakışma yok.
 *  ledger     Finans kök belgeleri (tahsilat, kayıt/ödeme planı…). ASLA satır olarak itilmez; yerel düğüm
 *             işlemi KOMUT olarak gönderir, sunucu kendi servisiyle (iş kurallarıyla) yeniden yürütür.
 *             Sunucudan aşağı yönde sunucu-otoriteli satır olarak iner.
 *  server     Yalnız sunucudan aşağı (sunucu-otoriteli): türetilmiş finans satırları, katalog, kullanıcı/rol.
 *             Yereldeki değişiklik geçicidir; bir sonraki çekmede sunucu değeri yazılır.
 *  pivot      id'siz ara tablo; kimlik = anahtar sütunları (uuid'ye çevrilmiş). Ekle/sil olarak eşitlenir.
 *             'direction' => 'down' ise yalnız sunucudan iner.
 *  upload     Yalnız yukarı (denetim kaydı): yerelde yazılır, sunucuya eklenir, aşağı inmez.
 *  derived    EŞİTLENMEZ; her tarafta yeniden hesaplanır (RecomputeService / ilgili komut).
 *  local      EŞİTLENMEZ; düğüme özel (bildirim, canlı akış, mesaj kuyruğu, entegrasyon sırları…).
 *  system     EŞİTLENMEZ; çerçeve tabloları (oturum, önbellek, kuyruk, migration, eşitleme tabloları).
 *
 * Ayrıca: 'exclude' = hiç taşınmayan sütunlar (sayaç/bakiye/sır), 'sensitive' = yalnız yetkiyle inen sütunlar,
 * 'permission' = cihaz kullanıcısında bu yetki yoksa tablo hiç inmez, 'branch' = şube kapsamı çözümü,
 * 'fk' = şemada yabancı anahtar olarak tanımlı olmayan referans sütunları (sütun => tablo | null = referans değil),
 * 'filter' = kayıt sırasında süzgeç (ör. yalnız personel kullanıcıları),
 * 'fill' = hariç sütun boş bırakılamıyorsa alıcı düğümde yeni satıra verilecek değer ('@random64' = rastgele),
 * 'since' = tablo eşitlemeye sonradan katıldı: eşleşmiş kurulumlar bu tabloyu bir kez ayrıca anlık görüntüyle çeker.
 */
final class SyncRegistry
{
    public const REFERENCE = 'reference';
    public const KEYED = 'keyed';
    public const APPEND = 'append';
    public const LEDGER = 'ledger';
    public const SERVER = 'server';
    public const PIVOT = 'pivot';
    public const UPLOAD = 'upload';
    public const DERIVED = 'derived';
    public const LOCAL = 'local';
    public const SYSTEM = 'system';

    /** uuid sütunu taşıyan (satır kimliği olan) türler */
    public const UUID_KINDS = [self::REFERENCE, self::KEYED, self::APPEND, self::LEDGER, self::SERVER, self::UPLOAD];

    /** Yerel düğümden yukarı satır olarak itilebilen türler */
    public const PUSHABLE_KINDS = [self::REFERENCE, self::KEYED, self::APPEND, self::UPLOAD, self::PIVOT];

    private const USERS = 'users';

    /**
     * @var array<string, array<string, mixed>>
     */
    private const TABLES = [
        // ------------------------------------------------------------------ kurum / kişiler
        'branches' => ['kind' => self::SERVER, 'branch' => 'self'],
        'settings' => ['kind' => self::SERVER, 'filter' => 'public_settings', 'key' => ['branch_id', 'group', 'key']],   // eşleştirme kodu (sync grubu) inmez
        'users' => ['kind' => self::SERVER, 'filter' => 'staff_users', 'exclude' => ['initial_password', 'remember_token', 'last_login_ip'], 'key' => ['username']],
        'roles' => ['kind' => self::SERVER, 'branch' => 'global', 'key' => ['name', 'guard_name']],
        'permissions' => ['kind' => self::SERVER, 'branch' => 'global', 'key' => ['name', 'guard_name']],
        'role_has_permissions' => ['kind' => self::PIVOT, 'direction' => 'down', 'branch' => 'global', 'key' => ['permission_id', 'role_id']],
        'model_has_roles' => ['kind' => self::PIVOT, 'direction' => 'down', 'key' => ['role_id', 'model_type', 'model_id'], 'filter' => 'staff_model', 'fk' => ['model_id' => 'morph:model_type']],
        'model_has_permissions' => ['kind' => self::PIVOT, 'direction' => 'down', 'key' => ['permission_id', 'model_type', 'model_id'], 'filter' => 'staff_model', 'fk' => ['model_id' => 'morph:model_type']],
        'students' => ['kind' => self::REFERENCE, 'permission' => 'students.view',
            // user_id: portal hesabı masaüstüne inmez. photo_path satırla, dosyası sync_files ile (docs/SYNC.md › Dosyalar)
            'exclude' => ['user_id'],
            'sensitive' => ['national_id_encrypted' => 'students.view_sensitive']],
        'guardians' => ['kind' => self::REFERENCE, 'permission' => 'guardians.view', 'exclude' => ['user_id'],
            'sensitive' => ['national_id_encrypted' => 'students.view_sensitive']],
        'guardian_student' => ['kind' => self::REFERENCE, 'branch' => 'via:student_id', 'key' => ['guardian_id', 'student_id']],
        'teachers' => ['kind' => self::REFERENCE, 'exclude' => ['user_id']],
        'employees' => ['kind' => self::REFERENCE, 'exclude' => ['user_id']],
        'teacher_subject' => ['kind' => self::PIVOT, 'key' => ['teacher_id', 'subject_id'], 'branch' => 'via:teacher_id'],
        'teacher_availabilities' => ['kind' => self::REFERENCE, 'branch' => 'via:teacher_id'],
        'teacher_leaves' => ['kind' => self::REFERENCE, 'branch' => 'via:teacher_id'],
        'schools' => ['kind' => self::REFERENCE, 'key' => ['name', 'district']],
        'tags' => ['kind' => self::REFERENCE],
        'taggables' => ['kind' => self::PIVOT, 'key' => ['tag_id', 'taggable_type', 'taggable_id'], 'branch' => 'via:tag_id', 'fk' => ['taggable_id' => 'morph:taggable_type']],
        // Belgeler (öğretmen belgesi, ödev dosyası/teslimi, disiplin eki): satır iki yönlü, içerik sync_files ile
        'documents' => ['kind' => self::REFERENCE, 'permission' => 'documents.view', 'fk' => ['documentable_id' => 'morph:documentable_type']],

        // ------------------------------------------------------------------ akademik
        'academic_terms' => ['kind' => self::REFERENCE],
        'programs' => ['kind' => self::REFERENCE],
        'subjects' => ['kind' => self::REFERENCE],
        'topics' => ['kind' => self::REFERENCE, 'branch' => 'via:subject_id'],
        'program_subject' => ['kind' => self::REFERENCE, 'branch' => 'via:program_id', 'key' => ['program_id', 'subject_id']],
        'program_subject_teacher' => ['kind' => self::PIVOT, 'key' => ['program_id', 'subject_id', 'teacher_id'], 'branch' => 'via:program_id'],
        'classrooms' => ['kind' => self::REFERENCE],
        // 3D derslik tasarımı + oturma düzeni: iki yönlü (uuid). Öğrenci ataması data JSON'unda öğrenci UUID'si ile tutulur.
        'classroom_layouts' => ['kind' => self::REFERENCE, 'since' => '2026-09-18'],
        'classroom_layout_versions' => ['kind' => self::REFERENCE, 'since' => '2026-09-18'],
        // Sınıf oturma planı (oda düzeninden ayrı): masa kimliği → öğrenci UUID
        'class_seating_plans' => ['kind' => self::REFERENCE, 'since' => '2026-09-19'],
        'class_groups' => ['kind' => self::REFERENCE],
        'class_group_student' => ['kind' => self::REFERENCE, 'branch' => 'via:class_group_id', 'key' => ['class_group_id', 'student_id', 'joined_on']],
        'class_group_subject_hours' => ['kind' => self::REFERENCE, 'branch' => 'via:class_group_id'],
        'class_group_time_template' => ['kind' => self::PIVOT, 'key' => ['class_group_id', 'time_template_id'], 'branch' => 'via:class_group_id'],
        'class_waitlist' => ['kind' => self::REFERENCE],
        'time_templates' => ['kind' => self::REFERENCE],
        'holidays' => ['kind' => self::REFERENCE],
        'lesson_schedules' => ['kind' => self::REFERENCE],
        // Oturumlar sunucuda üretilir (kurs:generate-sessions yerelde kapalı); yoklama işaretleri iki yönlü.
        'lesson_sessions' => ['kind' => self::REFERENCE, 'key' => ['lesson_schedule_id', 'date']],
        'study_sessions' => ['kind' => self::REFERENCE],
        'study_session_student' => ['kind' => self::REFERENCE, 'branch' => 'via:study_session_id', 'key' => ['study_session_id', 'student_id']],
        'homework' => ['kind' => self::REFERENCE],
        'homework_submissions' => ['kind' => self::REFERENCE, 'branch' => 'via:homework_id', 'key' => ['homework_id', 'student_id']],
        'announcements' => ['kind' => self::REFERENCE],
        'timetable_runs' => ['kind' => self::LOCAL, 'reason' => 'Program botu çalıştırmaları büyük JSON; yalnız sunucuda.'],
        'placement_runs' => ['kind' => self::LOCAL, 'reason' => 'Yerleştirme çalıştırmaları yalnız sunucuda.'],

        // ------------------------------------------------------------------ yoklama
        'attendances' => ['kind' => self::KEYED, 'key' => ['lesson_session_id', 'student_id'], 'permission' => 'attendance.view'],
        'attendance_events' => ['kind' => self::APPEND, 'key' => ['idempotency_key'], 'fk' => ['person_id' => 'morph:person_type'], 'permission' => 'attendance.view'],
        'daily_presences' => ['kind' => self::KEYED, 'key' => ['student_id', 'date'], 'permission' => 'attendance.view'],
        // Terminal kaydı + LAN bağlantı bilgisi: YALNIZ masaüstünde yazılır (web uçları 409 — EnsureTerminalDesktop), web'e
        // salt okunur çıkar, birden çok Mac aynı kaydı görsün diye aşağı da iner. Düğüme özel sütunlar taşınmaz: eski köprü API jetonu özeti, çekme imleci ve son çekme durumu (Mac üretir,
        // web'e ayrı "terminal durumu raporu" ile çıkar: sync/terminal-status → sync_terminal_reports), ADMS damgası. İletişim şifresi kurum veri
        // anahtarıyla şifreli (App\Casts\DataEncrypted) → şifreli metin olduğu gibi taşınır, yalnız devices.manage ile iner.
        'devices' => ['kind' => self::REFERENCE, 'since' => '2026-09-18', 'key' => ['branch_id', 'serial_no'],
            'exclude' => ['api_token_hash', 'api_token_prefix', 'last_seen_at', 'last_ip', 'zk_cursor_at', 'zk_cursor_key',
                'zk_last_pull_at', 'zk_last_status', 'zk_last_error', 'zk_last_record_count', 'adms_stamp'],
            'fill' => ['api_token_hash' => '@random64', 'api_token_prefix' => ''],
            'sensitive' => ['zk_comm_key_encrypted' => 'devices.manage']],
        // Parmak izi / kart no ↔ öğrenci eşlemesi: terminalle birlikte masaüstünde yönetilir, web'e (salt okunur) çıkar;
        // birden çok Mac aynı eşlemeyi görsün diye aşağı da iner. Aynı kimlik iki yerde ayrı eklenirse anahtarla birleşir.
        'device_identities' => ['kind' => self::REFERENCE, 'since' => '2026-09-18', 'key' => ['branch_id', 'kind', 'identifier'],
            'fk' => ['person_id' => 'morph:person_type']],

        // ------------------------------------------------------------------ sınav
        'exam_types' => ['kind' => self::SERVER, 'key' => ['branch_id', 'code']],
        'exams' => ['kind' => self::REFERENCE, 'exclude' => ['participant_count']],
        'exam_sections' => ['kind' => self::REFERENCE, 'branch' => 'via:exam_id', 'key' => ['exam_id', 'subject_id']],
        'exam_questions' => ['kind' => self::REFERENCE, 'branch' => 'via:exam_section_id'],
        // Sonuçlar optik okuma/yayın servisiyle sunucuda üretilir
        'exam_results' => ['kind' => self::SERVER, 'branch' => 'via:exam_id', 'permission' => 'exams.view'],
        'exam_result_sections' => ['kind' => self::SERVER, 'branch' => 'via:exam_result_id', 'permission' => 'exams.view'],
        'exam_question_stats' => ['kind' => self::SERVER, 'branch' => 'via:exam_question_id', 'permission' => 'exams.view'],
        'student_topic_stats' => ['kind' => self::SERVER, 'branch' => 'via:student_id', 'permission' => 'exams.view'],
        'optical_imports' => ['kind' => self::LOCAL, 'reason' => 'Optik dosyalar sunucu diskinde.'],
        'optical_layouts' => ['kind' => self::SERVER],

        // ------------------------------------------------------------------ CRM / rehberlik / disiplin
        'leads' => ['kind' => self::REFERENCE, 'permission' => 'crm.view'],
        'lead_activities' => ['kind' => self::APPEND, 'branch' => 'via:lead_id', 'permission' => 'crm.view'],
        'tasks' => ['kind' => self::REFERENCE, 'fk' => ['taskable_id' => 'morph:taskable_type', 'assigned_to' => 'users']],
        'guidance_meetings' => ['kind' => self::REFERENCE, 'permission' => 'guidance.view'],
        'student_goals' => ['kind' => self::REFERENCE, 'branch' => 'via:student_id'],
        'student_notes' => ['kind' => self::REFERENCE, 'branch' => 'via:student_id'],
        'student_observations' => ['kind' => self::REFERENCE],
        'student_risk_scores' => ['kind' => self::DERIVED, 'reason' => 'kurs:compute-risk her düğümde yeniden hesaplar.'],
        'contact_requests' => ['kind' => self::REFERENCE],
        'discipline_behaviors' => ['kind' => self::REFERENCE],
        'discipline_sanction_types' => ['kind' => self::REFERENCE],
        'discipline_incidents' => ['kind' => self::REFERENCE, 'permission' => 'discipline.view'],
        'discipline_incident_students' => ['kind' => self::REFERENCE, 'branch' => 'via:incident_id', 'permission' => 'discipline.view'],
        'discipline_defenses' => ['kind' => self::REFERENCE, 'permission' => 'discipline.view'],
        'discipline_board_meetings' => ['kind' => self::REFERENCE, 'permission' => 'discipline.view'],
        'discipline_board_items' => ['kind' => self::REFERENCE, 'branch' => 'via:meeting_id', 'permission' => 'discipline.view'],
        'discipline_sanctions' => ['kind' => self::REFERENCE, 'permission' => 'discipline.view'],
        'discipline_appeals' => ['kind' => self::REFERENCE, 'permission' => 'discipline.view'],
        'discipline_events' => ['kind' => self::APPEND, 'branch' => 'via:incident_id', 'permission' => 'discipline.view'],

        // ------------------------------------------------------------------ finans (kök belgeler: komutla)
        'enrollments' => ['kind' => self::LEDGER, 'permission' => 'students.view'],
        'payments' => ['kind' => self::LEDGER, 'permission' => 'finance.view'],
        'refunds' => ['kind' => self::LEDGER, 'permission' => 'finance.view'],
        'finance_entries' => ['kind' => self::LEDGER, 'permission' => 'finance.view'],
        'account_transfers' => ['kind' => self::LEDGER, 'permission' => 'finance.view'],
        'promissory_notes' => ['kind' => self::LEDGER, 'permission' => 'finance.view', 'sensitive' => ['debtor_tax_id' => 'students.view_sensitive'], 'fk' => ['debtor_tax_id' => null]],
        'invoices' => ['kind' => self::LEDGER, 'permission' => 'finance.view', 'sensitive' => ['buyer_tax_id' => 'students.view_sensitive'],
            'fk' => ['buyer_id' => 'morph:buyer_type', 'buyer_tax_id' => null]],
        'pos_settlements' => ['kind' => self::LEDGER, 'permission' => 'finance.view'],
        // türetilmiş finans satırları: sunucu-otoriteli
        // ödenen tutar + durum: RecomputeService (tahsilat/iade dağıtımlarından) her düğümde yeniden hesaplar
        'installments' => ['kind' => self::SERVER, 'permission' => 'finance.view', 'exclude' => ['paid_amount', 'status']],
        'payment_allocations' => ['kind' => self::SERVER, 'branch' => 'via:payment_id', 'permission' => 'finance.view'],
        'payment_card_details' => ['kind' => self::SERVER, 'permission' => 'finance.view'],
        'refund_allocations' => ['kind' => self::SERVER, 'branch' => 'via:refund_id', 'permission' => 'finance.view'],
        'invoice_lines' => ['kind' => self::SERVER, 'branch' => 'via:invoice_id', 'permission' => 'finance.view'],
        'invoice_payments' => ['kind' => self::SERVER, 'branch' => 'via:invoice_id', 'permission' => 'finance.view'],
        'account_transactions' => ['kind' => self::SERVER, 'permission' => 'finance.view', 'fk' => ['source_id' => 'morph:source_type']],
        'journal_entries' => ['kind' => self::SERVER, 'permission' => 'finance.view', 'fk' => ['source_id' => 'morph:source_type']],
        'journal_lines' => ['kind' => self::SERVER, 'permission' => 'finance.view', 'fk' => ['partner_id' => 'morph:partner_type']],
        'contracts' => ['kind' => self::SERVER, 'branch' => 'via:enrollment_id', 'permission' => 'students.view'],
        'collection_notes' => ['kind' => self::SERVER, 'permission' => 'finance.view'],
        'reconciliation_marks' => ['kind' => self::SERVER, 'permission' => 'finance.view'],
        'finance_accounts' => ['kind' => self::SERVER, 'exclude' => ['balance']],   // bakiye: RecomputeService
        'finance_categories' => ['kind' => self::SERVER, 'key' => ['branch_id', 'direction', 'code']],
        'education_packages' => ['kind' => self::SERVER],
        'products' => ['kind' => self::SERVER, 'exclude' => ['stock']],             // stok: RecomputeService
        'stock_movements' => ['kind' => self::SERVER, 'permission' => 'finance.view'],
        'accounting_periods' => ['kind' => self::SERVER, 'permission' => 'finance.view', 'key' => ['branch_id', 'period']],
        'ledger_accounts' => ['kind' => self::SERVER, 'permission' => 'finance.view', 'key' => ['branch_id', 'code']],
        'ledger_mappings' => ['kind' => self::SERVER, 'permission' => 'finance.view', 'fk' => ['source_key' => null], 'key' => ['branch_id', 'source_type', 'source_key']],
        'installment_reminders' => ['kind' => self::LOCAL, 'reason' => 'Hatırlatma gönderim izi; gönderim yalnız sunucuda.'],

        // ------------------------------------------------------------------ iletişim / entegrasyon (yalnız sunucu)
        'outbound_messages' => ['kind' => self::LOCAL, 'reason' => 'Mesaj kuyruğu; gönderim yalnız sunucuda.'],
        'message_templates' => ['kind' => self::LOCAL, 'reason' => 'Şablonlar gönderimde kullanılır; gönderim yalnız sunucuda.'],
        'message_campaigns' => ['kind' => self::LOCAL, 'reason' => 'Toplu gönderim yalnız sunucuda.'],
        'message_campaign_recipients' => ['kind' => self::LOCAL, 'reason' => 'Toplu gönderim yalnız sunucuda.'],
        'communication_consents' => ['kind' => self::SERVER, 'fk' => ['consentable_id' => 'morph:consentable_type']],
        'communication_suppressions' => ['kind' => self::LOCAL, 'reason' => 'Ret listesi gönderim tarafında (sunucu).'],
        'automation_rules' => ['kind' => self::LOCAL, 'reason' => 'Otomasyon yalnız sunucuda çalışır.'],
        'automation_runs' => ['kind' => self::LOCAL, 'reason' => 'Otomasyon yalnız sunucuda çalışır.'],
        'integrations' => ['kind' => self::LOCAL, 'reason' => 'Sağlayıcı API sırları (şifreli) cihaza inmez.'],
        'webhooks' => ['kind' => self::LOCAL, 'reason' => 'Webhook imza sırları cihaza inmez.'],
        'webhook_deliveries' => ['kind' => self::LOCAL, 'reason' => 'Webhook teslimleri yalnız sunucuda.'],
        'push_tokens' => ['kind' => self::LOCAL, 'reason' => 'Mobil bildirim jetonları.'],
        'calendar_feeds' => ['kind' => self::LOCAL, 'reason' => 'iCal jeton özetleri.'],
        'announcement_reads' => ['kind' => self::LOCAL, 'reason' => 'Portal okundu bilgisi (portal yalnız sunucuda).'],
        // Personel bildirimleri sunucudan iner (zil + Bildirimler sayfası masaüstünde de dolu, çevrimdışıyken son liste).
        // Masaüstünde okundu işareti satır olarak itilmez; ayrı rapor (sync/notification-reads) sunucuya taşır.
        // Yerelde üretilen bildirim (uuid'siz) yerelde kalır: sunucu aynı işlemi yeniden yürütürken kendisi üretir.
        'app_notifications' => ['kind' => self::SERVER, 'since' => '2026-09-18', 'filter' => 'staff_owned', 'branch' => 'via:user_id'],
        'activity_feed' => ['kind' => self::LOCAL, 'reason' => 'Canlı akış her düğümde servislerce yeniden üretilir.'],
        'import_jobs' => ['kind' => self::LOCAL, 'reason' => 'Excel içe aktarma dosyası düğüme özel.'],
        'terminal_raw_packets' => ['kind' => self::LOCAL, 'reason' => 'Yoklama terminalinin push dinleyicisine gönderdiği ham baytlar: yalnız dinleyen Mac\'te anlamlı teşhis verisi; yoklama sunucuya attendance_events ile gider.'],
        'backup_runs' => ['kind' => self::LOCAL, 'reason' => 'Yedek yalnız sunucuda.'],
        'login_events' => ['kind' => self::LOCAL, 'reason' => 'Giriş kayıtları düğüme özel (KVKK: IP).'],
        'sequences' => ['kind' => self::LOCAL, 'reason' => 'Numara sayaçları: yerelde cihaz önekli / blok (SyncNumbers).'],
        'audit_logs' => ['kind' => self::UPLOAD, 'fk' => ['subject_id' => 'morph:subject_type']],
    ];

    /** Çerçeve / eşitleme tabloları (hiç eşitlenmez) */
    private const SYSTEM_TABLES = [
        'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'personal_access_tokens',
    ];

    private const SYSTEM_PREFIXES = ['sync_', 'telescope_', 'pulse_', 'sqlite_'];

    /** @var array<string, SyncTable>|null */
    private static ?array $cache = null;

    /** @return array<string, SyncTable> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $out = [];
        foreach (self::TABLES as $table => $def) {
            $out[$table] = SyncTable::fromArray($table, $def);
        }
        foreach (self::SYSTEM_TABLES as $table) {
            $out[$table] = SyncTable::fromArray($table, ['kind' => self::SYSTEM]);
        }

        return self::$cache = $out;
    }

    public static function get(string $table): ?SyncTable
    {
        $all = self::all();
        if (isset($all[$table])) {
            return $all[$table];
        }
        foreach (self::SYSTEM_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return SyncTable::fromArray($table, ['kind' => self::SYSTEM]);
            }
        }

        return null;
    }

    public static function isKnown(string $table): bool
    {
        return self::get($table) !== null;
    }

    /**
     * Veritabanında olup kayıt defterinde olmayan tablolar (yeni migration unutulduysa).
     *
     * @param list<string> $tables
     * @return list<string>
     */
    public static function unknown(array $tables): array
    {
        return array_values(array_filter($tables, fn ($t) => ! self::isKnown($t)));
    }

    /**
     * Eşitlenen tablolar (uuid kimlikli + pivot), tanım sırasıyla.
     *
     * @return array<string, SyncTable>
     */
    public static function synced(): array
    {
        return array_filter(self::all(), fn (SyncTable $t) => $t->isSynced());
    }

    /** @return array<string, SyncTable> uuid sütunu gereken tablolar */
    public static function withUuid(): array
    {
        return array_filter(self::all(), fn (SyncTable $t) => $t->hasUuid());
    }

    /** @return array<string, SyncTable> yerelden yukarı satır olarak gidenler */
    public static function pushable(): array
    {
        return array_filter(self::all(), fn (SyncTable $t) => $t->isPushable());
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function usersTable(): string
    {
        return self::USERS;
    }
}
