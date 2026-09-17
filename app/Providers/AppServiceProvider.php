<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(\App\Support\BranchContext::class);
    }

    public function boot(): void
    {
        // Sunucu php.ini serialize_precision=100: JSON'da 75.31 → 75.310000000000002 olmasın
        ini_set('serialize_precision', '-1');

        // Geliştirmede N+1 ve toplu atama hatalarını erken yakala.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Veritabanında sınıf adı yerine kararlı takma adlar (sınıf taşınsa da veri bozulmaz).
        Relation::enforceMorphMap([
            'user' => \App\Models\User::class,
            'student' => \App\Models\Student::class,
            'guardian' => \App\Models\Guardian::class,
            'teacher' => \App\Models\Teacher::class,
            'employee' => \App\Models\Employee::class,
            'lead' => \App\Models\Lead::class,
            'payment' => \App\Models\Payment::class,
            'finance_entry' => \App\Models\FinanceEntry::class,
            'account_transfer' => \App\Models\AccountTransfer::class,
            'enrollment' => \App\Models\Enrollment::class,
            'installment' => \App\Models\Installment::class,
            'exam' => \App\Models\Exam::class,
            'lesson_session' => \App\Models\LessonSession::class,
            'class_group' => \App\Models\ClassGroup::class,
            'program' => \App\Models\Program::class,
            'subject' => \App\Models\Subject::class,
            'topic' => \App\Models\Topic::class,
            'classroom' => \App\Models\Classroom::class,
            'lesson_schedule' => \App\Models\LessonSchedule::class,
            'study_session' => \App\Models\StudySession::class,
            'homework' => \App\Models\Homework::class,
            'finance_account' => \App\Models\FinanceAccount::class,
            'finance_category' => \App\Models\FinanceCategory::class,
            'education_package' => \App\Models\EducationPackage::class,
            'product' => \App\Models\Product::class,
            'contract' => \App\Models\Contract::class,
            // Denetim kaydı konusu olabilen diğer modeller (Audit::log $subject) — kararlı takma adlar.
            'academic_term' => \App\Models\AcademicTerm::class,
            'account_transaction' => \App\Models\AccountTransaction::class,
            'activity_feed' => \App\Models\ActivityFeed::class,
            'announcement' => \App\Models\Announcement::class,
            'app_notification' => \App\Models\AppNotification::class,
            'attendance' => \App\Models\Attendance::class,
            'attendance_event' => \App\Models\AttendanceEvent::class,
            'audit_log' => \App\Models\AuditLog::class,
            'automation_rule' => \App\Models\AutomationRule::class,
            'automation_run' => \App\Models\AutomationRun::class,
            'backup_run' => \App\Models\BackupRun::class,
            'branch' => \App\Models\Branch::class,
            'calendar_feed' => \App\Models\CalendarFeed::class,
            'class_group_subject_hour' => \App\Models\ClassGroupSubjectHour::class,
            'class_waitlist_entry' => \App\Models\ClassWaitlistEntry::class,
            'communication_consent' => \App\Models\CommunicationConsent::class,
            'daily_presence' => \App\Models\DailyPresence::class,
            'device' => \App\Models\Device::class,
            'device_identity' => \App\Models\DeviceIdentity::class,
            'document' => \App\Models\Document::class,
            'exam_question' => \App\Models\ExamQuestion::class,
            'exam_question_stat' => \App\Models\ExamQuestionStat::class,
            'exam_result' => \App\Models\ExamResult::class,
            'exam_result_section' => \App\Models\ExamResultSection::class,
            'exam_section' => \App\Models\ExamSection::class,
            'exam_type' => \App\Models\ExamType::class,
            'guidance_meeting' => \App\Models\GuidanceMeeting::class,
            'holiday' => \App\Models\Holiday::class,
            'homework_submission' => \App\Models\HomeworkSubmission::class,
            'import_job' => \App\Models\ImportJob::class,
            'installment_reminder' => \App\Models\InstallmentReminder::class,
            'integration' => \App\Models\Integration::class,
            'lead_activity' => \App\Models\LeadActivity::class,
            'login_event' => \App\Models\LoginEvent::class,
            'message_template' => \App\Models\MessageTemplate::class,
            'optical_import' => \App\Models\OpticalImport::class,
            'optical_layout' => \App\Models\OpticalLayout::class,
            'outbound_message' => \App\Models\OutboundMessage::class,
            'payment_allocation' => \App\Models\PaymentAllocation::class,
            'placement_run' => \App\Models\PlacementRun::class,
            'push_token' => \App\Models\PushToken::class,
            'setting' => \App\Models\Setting::class,
            'stock_movement' => \App\Models\StockMovement::class,
            'student_goal' => \App\Models\StudentGoal::class,
            'student_note' => \App\Models\StudentNote::class,
            'student_observation' => \App\Models\StudentObservation::class,
            'contact_request' => \App\Models\ContactRequest::class,
            'discipline_incident' => \App\Models\DisciplineIncident::class,
            'discipline_incident_student' => \App\Models\DisciplineIncidentStudent::class,
            'discipline_sanction' => \App\Models\DisciplineSanction::class,
            'discipline_sanction_type' => \App\Models\DisciplineSanctionType::class,
            'discipline_behavior' => \App\Models\DisciplineBehavior::class,
            'discipline_defense' => \App\Models\DisciplineDefense::class,
            'discipline_appeal' => \App\Models\DisciplineAppeal::class,
            'discipline_board_meeting' => \App\Models\DisciplineBoardMeeting::class,
            'discipline_board_item' => \App\Models\DisciplineBoardItem::class,
            'discipline_event' => \App\Models\DisciplineEvent::class,
            'student_risk_score' => \App\Models\StudentRiskScore::class,
            'student_topic_stat' => \App\Models\StudentTopicStat::class,
            'tag' => \App\Models\Tag::class,
            'school' => \App\Models\School::class,
            'task' => \App\Models\Task::class,
            'teacher_availability' => \App\Models\TeacherAvailability::class,
            'teacher_leave' => \App\Models\TeacherLeave::class,
            'time_template' => \App\Models\TimeTemplate::class,
            'timetable_run' => \App\Models\TimetableRun::class,
            'webhook' => \App\Models\Webhook::class,
            'webhook_delivery' => \App\Models\WebhookDelivery::class,
            'role' => \Spatie\Permission\Models\Role::class,
            // Finans: fatura, iade, POS yatışı, yevmiye
            'invoice' => \App\Models\Invoice::class,
            'refund' => \App\Models\Refund::class,
            'pos_settlement' => \App\Models\PosSettlement::class,
            'journal_entry' => \App\Models\JournalEntry::class,
            'collection_note' => \App\Models\CollectionNote::class,
            'promissory_note' => \App\Models\PromissoryNote::class,
        ]);

        // Tahsilat / gelir-gider / hesap belgelerinden otomatik yevmiye fişi (aynı transaction içinde)
        \App\Services\Accounting\AccountingObserver::register();

        // "Sistem Yöneticisi" rolü tüm yetki denetimlerini geçer.
        Gate::before(fn ($user) => $user->hasRole('super-admin') ? true : null);

        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)
            ->by($request->user()?->id ?: $request->ip()));

        // Giriş: kullanıcı adı + IP başına dakikada 5, IP başına dakikada 20.
        // Telefonla girişte farklı yazımlar ("0532 …", "+90532…") aynı sayaca düşer.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower(
                \App\Services\Guardians\GuardianAccountService::usernameFor((string) $request->input('login')) ?? (string) $request->input('login')
            ).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(90)
            ->by($request->user()?->id ?: $request->ip()));

        // Donanım köprüsü toplu olay gönderir; cihaz başına geniş bant.
        RateLimiter::for('device', fn (Request $request) => Limit::perMinute(600)
            ->by($request->attributes->get('device')?->id ?: $request->ip()));
    }
}
