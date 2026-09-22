<?php

namespace Database\Seeders;

use App\Models\AcademicTerm;
use App\Models\Branch;
use App\Models\ClassGroup;
use App\Models\Classroom;
use App\Models\EducationPackage;
use App\Models\FinanceAccount;
use App\Models\Guardian;
use App\Models\LessonSchedule;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Tag;
use App\Models\Teacher;
use App\Models\Topic;
use App\Models\User;
use App\Services\Finance\EnrollmentService;
use App\Services\Finance\PaymentService;
use App\Support\BranchContext;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ADDITIVE demo verisi (SUNUM için) — DemoSeeder'dan bağımsız, GUARD YOK ama
 * çalışmadan önce tekrar-çalıştırmayı engeller (Settings 'demo_topup').
 *
 * - Mevcut hiçbir kaydı silmez/değiştirmez (semih yılmaz #1 dokunulmaz).
 * - Sadece YENİ kayıt ekler; hepsi "Demo" (id 13) etiketiyle işaretlenir.
 * - Temizlik: student_no 2026301-2026399 aralığındaki öğrenciler + Demo etiketli.
 *   Ayrıntılı liste Settings 'demo_topup' altında saklanır.
 */
class DemoTopUpSeeder extends Seeder
{
    private const FIRST_M = ['Ahmet', 'Mehmet', 'Emre', 'Yusuf', 'Burak', 'Kerem', 'Eren', 'Arda', 'Kaan', 'Furkan', 'Enes', 'Alperen', 'Oğuzhan', 'Batuhan', 'Yiğit', 'Mert', 'Onur', 'Selim', 'Efe', 'Baran'];
    private const FIRST_F = ['Elif', 'Zeynep', 'Merve', 'Esra', 'Büşra', 'Defne', 'İrem', 'Selin', 'Ceren', 'Melis', 'Azra', 'Beyza', 'Ela', 'Duru', 'Asya', 'Sena', 'Rabia', 'Damla', 'Ezgi', 'Nisa'];
    private const LAST = ['Kaya', 'Demir', 'Şahin', 'Çelik', 'Yıldız', 'Öztürk', 'Aydın', 'Arslan', 'Doğan', 'Kılıç', 'Kara', 'Koç', 'Özkan', 'Şimşek', 'Polat', 'Güneş', 'Aksoy', 'Bulut', 'Korkmaz', 'Taş'];
    private const SCHOOLS = ['Erbaa Anadolu Lisesi', 'Erbaa Fen Lisesi', 'Gazi Osman Paşa Anadolu Lisesi', 'Erbaa Mesleki ve Teknik Anadolu Lisesi', 'Erbaa Kız Anadolu İmam Hatip Lisesi'];

    private Branch $branch;
    private AcademicTerm $term;
    private User $actor;
    private array $subjects = [];
    private array $topics = [];
    private array $teachers = [];
    private array $classrooms = [];
    private array $programs = [];
    private array $groups = [];
    private array $createdStudents = [];

    public function run(): void
    {
        $this->branch = Branch::query()->where('code', 'ERBAA')->firstOrFail();

        app(BranchContext::class)->run($this->branch->id, function () {
            if (Settings::get('demo.topup_seeded_at')) {
                throw new RuntimeException('DemoTopUp zaten çalıştırılmış (Settings demo.topup_seeded_at dolu). Tekrar çalıştırma.');
            }
            if (Subject::query()->exists() || Program::query()->exists()) {
                throw new RuntimeException('Şubede ders/program kaydı zaten var; çift veri riskine karşı durduruldu.');
            }

            mt_srand(20260922);
            config(['kurs.silent_events' => true]);

            $this->term = AcademicTerm::query()->where('is_current', true)->firstOrFail();
            $this->actor = User::query()->where('username', 'demo')->firstOrFail();
            Auth::setUser($this->actor);

            DB::transaction(function () {
                $this->subjectsAndTopics();
                $this->programs();
                $this->classrooms();
                $this->teachers();
                $this->classGroups();
                $this->schedules();
                $this->studentsAndEnrollments();
            });

            // Tahsilatlar ana oluşturma işlemi işlendikten sonra, bağımsız (hata çekirdeği bozmaz)
            $payments = $this->payments();

            Settings::put('demo', [
                'topup_seeded_at' => now()->toAtomString(),
                'topup_student_no_range' => '2026301-'.(2026300 + count($this->createdStudents)),
                'topup_student_count' => count($this->createdStudents),
                'topup_payments' => $payments,
                'topup_note' => 'SUNUM additive demo. Temizlik: Demo etiketli + bu no aralıği.',
            ]);

            $this->command?->info('DemoTopUp tamam: '.count($this->createdStudents).' öğrenci, '.$payments.' tahsilat.');
        });
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }

    private function phone(): string
    {
        return '05'.$this->pick(['30', '32', '33', '35', '36', '42', '44', '52', '53', '55']).mt_rand(1000000, 9999999);
    }

    private function subjectsAndTopics(): void
    {
        $defs = [
            'MAT' => ['Matematik', 'indigo', ['Temel Kavramlar', 'Problemler', 'Fonksiyonlar', 'Parabol', 'Türev']],
            'GEO' => ['Geometri', 'violet', ['Üçgenler', 'Dörtgenler', 'Analitik Geometri']],
            'TUR' => ['Türkçe', 'rose', ['Sözcükte Anlam', 'Paragraf', 'Dil Bilgisi']],
            'EDB' => ['Türk Dili ve Edebiyatı', 'pink', ['Şiir Bilgisi', 'Divan Edebiyatı', 'Cumhuriyet Dönemi']],
            'FIZ' => ['Fizik', 'sky', ['Kuvvet ve Hareket', 'Enerji', 'Elektrik']],
            'KIM' => ['Kimya', 'teal', ['Atom ve Periyodik Sistem', 'Mol Kavramı', 'Kimyasal Tepkimeler']],
        ];
        foreach ($defs as $code => [$name, $color, $topics]) {
            $subject = Subject::query()->create(['code' => $code, 'name' => $name, 'short_name' => $code, 'color' => $color]);
            $this->subjects[$code] = $subject;
            $i = 0;
            foreach ($topics as $t) {
                $topic = Topic::query()->create(['subject_id' => $subject->id, 'name' => $t, 'sort' => $i++, 'outcome_code' => $code.'.'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
                $this->topics[$code][] = $topic->id;
            }
        }
    }

    private function programs(): void
    {
        $defs = [
            'AYT_SAY' => ['AYT Sayısal', 'AYT_SAY', 'indigo', 62000, ['MAT' => 6, 'GEO' => 3, 'FIZ' => 4, 'KIM' => 3, 'TUR' => 3]],
            'AYT_EA' => ['AYT Eşit Ağırlık', 'AYT_EA', 'violet', 58000, ['MAT' => 6, 'GEO' => 2, 'EDB' => 4, 'TUR' => 4]],
        ];
        foreach ($defs as $code => [$name, $track, $color, $price, $subjects]) {
            $program = Program::query()->create(['code' => $code, 'name' => $name, 'exam_track' => $track, 'kind' => 'group', 'color' => $color]);
            foreach ($subjects as $s => $hours) {
                $program->subjects()->attach($this->subjects[$s]->id, ['weekly_hours' => $hours]);
            }
            $package = EducationPackage::query()->create([
                'program_id' => $program->id, 'academic_term_id' => $this->term->id, 'name' => "{$name} {$this->term->name}",
                'list_price' => $price, 'default_installments' => 8, 'includes' => 'Ders, deneme sınavları, rehberlik, yayın seti',
            ]);
            $this->programs[$code] = ['model' => $program, 'price' => $price, 'subjects' => $subjects, 'package_id' => $package->id];
        }
    }

    private function classrooms(): void
    {
        foreach ([['Derslik A', 'classroom', 24, 'Zemin'], ['Derslik B', 'classroom', 22, 'Zemin'], ['Derslik C', 'classroom', 24, '1. Kat'], ['Etüt 1', 'study', 8, '2. Kat']] as [$name, $kind, $cap, $floor]) {
            $this->classrooms[$name] = Classroom::query()->create(['name' => $name, 'kind' => $kind, 'capacity' => $cap, 'floor' => $floor]);
        }
    }

    private function teachers(): void
    {
        $defs = [
            ['Murat', 'Özer', 'MAT', 'indigo', ['GEO']],
            ['Cemile', 'Yurt', 'TUR', 'rose', []],
            ['Tolga', 'Başaran', 'FIZ', 'sky', []],
            ['Kenan', 'Akgül', 'KIM', 'teal', []],
            ['Nurcan', 'Ekinci', 'EDB', 'pink', []],
        ];
        foreach ($defs as [$first, $last, $subject, $color, $extra]) {
            $username = Str::slug(Str::ascii(mb_strtolower($first.'.'.$last, 'UTF-8')), '.');
            $user = User::query()->create([
                'branch_id' => $this->branch->id, 'name' => "$first $last", 'username' => $username,
                'user_type' => 'teacher', 'password' => 'Demo'.date('Y').'!',
            ]);
            $user->assignRole('ogretmen');
            $teacher = Teacher::query()->create([
                'user_id' => $user->id, 'first_name' => $first, 'last_name' => $last,
                'title' => $this->subjects[$subject]->name.' Öğretmeni', 'specialty' => $this->subjects[$subject]->name,
                'phone' => $this->phone(), 'color' => $color, 'hired_on' => '2022-09-01',
                'employment_type' => 'full_time', 'max_weekly_hours' => 30,
            ]);
            $teacher->subjects()->attach($this->subjects[$subject]->id);
            foreach ($extra as $ex) {
                $teacher->subjects()->attach($this->subjects[$ex]->id);
            }
            $this->teachers[$subject][] = $teacher;
            foreach ($extra as $ex) {
                $this->teachers[$ex][] = $teacher;
            }
        }
    }

    private function classGroups(): void
    {
        $defs = [
            ['12-SAY-A', 'AYT_SAY', 'Derslik A', 12, 'A', 'SAY'],
            ['12-SAY-B', 'AYT_SAY', 'Derslik B', 12, 'B', 'SAY'],
            ['12-EA-A', 'AYT_EA', 'Derslik C', 12, 'A', 'EA'],
            ['11-EA-A', 'AYT_EA', 'Derslik A', 11, 'A', 'EA'],
        ];
        foreach ($defs as [$name, $programCode, $room, $grade, $section, $track]) {
            $advisorSubject = array_key_first($this->programs[$programCode]['subjects']);
            $group = ClassGroup::query()->create([
                'academic_term_id' => $this->term->id, 'program_id' => $this->programs[$programCode]['model']->id,
                'homeroom_classroom_id' => $this->classrooms[$room]->id, 'advisor_teacher_id' => $this->teachers[$advisorSubject][0]->id,
                'name' => $name, 'capacity' => min(24, $this->classrooms[$room]->capacity),
                'grade_level' => $grade, 'section' => $section, 'track' => $track,
            ]);
            $group->setAttribute('program_code', $programCode);
            $this->groups[$name] = $group;
        }
    }

    /** Basit çakışmasız haftalık program (öğretmen/derslik/sınıf doluluk haritası). */
    private function schedules(): void
    {
        $slots = [['16:30', '17:20'], ['17:30', '18:20'], ['18:30', '19:20']];
        $busy = [];
        $validFrom = $this->term->starts_on->toDateString();

        foreach ($this->groups as $name => $group) {
            $programCode = $group->program_code;
            $queue = array_keys($this->programs[$programCode]['subjects']); // birer ders / gün, ilk 3 gün
            foreach (range(1, 5) as $weekday) {
                if ($queue === []) {
                    break;
                }
                foreach ($slots as [$start, $end]) {
                    if ($queue === []) {
                        break;
                    }
                    foreach ($queue as $qi => $code) {
                        $key = "$weekday|$start";
                        $teacher = collect($this->teachers[$code] ?? [])->first(fn ($t) => ! isset($busy['teacher'][$t->id][$key]));
                        if (! $teacher || isset($busy['group'][$name][$key])) {
                            continue;
                        }
                        $roomId = $group->homeroom_classroom_id;
                        if (isset($busy['room'][$roomId][$key])) {
                            continue;
                        }
                        LessonSchedule::query()->create([
                            'academic_term_id' => $this->term->id, 'class_group_id' => $group->id,
                            'subject_id' => $this->subjects[$code]->id, 'teacher_id' => $teacher->id,
                            'classroom_id' => $roomId, 'weekday' => $weekday, 'starts_at' => $start.':00',
                            'ends_at' => $end.':00', 'valid_from' => $validFrom,
                        ]);
                        $busy['teacher'][$teacher->id][$key] = true;
                        $busy['room'][$roomId][$key] = true;
                        $busy['group'][$name][$key] = true;
                        unset($queue[$qi]);
                        $queue = array_values($queue);
                        break;
                    }
                }
            }
        }
    }

    private function studentsAndEnrollments(): void
    {
        $service = app(EnrollmentService::class);
        $demoTag = Tag::query()->firstOrCreate(['name' => 'Demo'], ['color' => 'slate']);
        $burslu = Tag::query()->firstOrCreate(['name' => 'Burslu'], ['color' => 'emerald']);

        $no = 2026301;
        $sizes = ['12-SAY-A' => 11, '12-SAY-B' => 11, '12-EA-A' => 11, '11-EA-A' => 11];

        foreach ($sizes as $groupName => $size) {
            $group = $this->groups[$groupName];
            $programCode = $group->program_code;
            $grade = (string) $group->grade_level;
            $field = $group->track;

            for ($i = 0; $i < $size; $i++) {
                $female = $this->chance(0.5);
                $first = $this->pick($female ? self::FIRST_F : self::FIRST_M);
                $last = $this->pick(self::LAST);
                $birthYear = $grade === '11' ? 2009 : 2008;

                $student = Student::query()->create([
                    'student_no' => (string) $no++,
                    'first_name' => $first, 'last_name' => $last,
                    'birth_date' => CarbonImmutable::create($birthYear, mt_rand(1, 12), mt_rand(1, 28))->toDateString(),
                    'gender' => $female ? 'female' : 'male',
                    'school_name' => $this->pick(self::SCHOOLS),
                    'school_grade' => $grade, 'field' => $field,
                    'target_university' => $this->pick(['Hacettepe Üniversitesi', 'ODTÜ', 'Ankara Üniversitesi', 'Ondokuz Mayıs Üniversitesi', 'Tokat Gaziosmanpaşa Üniversitesi']),
                    'target_department' => $field === 'SAY'
                        ? $this->pick(['Tıp', 'Bilgisayar Mühendisliği', 'Diş Hekimliği', 'Elektrik-Elektronik Müh.'])
                        : $this->pick(['Hukuk', 'İşletme', 'Psikoloji', 'Uluslararası İlişkiler']),
                    'phone' => $this->phone(),
                    'address' => $this->pick(['Cumhuriyet', 'Yeni', 'Karşıyaka', 'Gazipaşa', 'Fatih']).' Mah., Erbaa / Tokat',
                    'status' => 'active',
                    'registered_on' => CarbonImmutable::parse('2026-08-01')->addDays(mt_rand(0, 45))->toDateString(),
                ]);
                $student->forceFill(['whatsapp_phone' => $student->phone])->save();

                $guardian = Guardian::query()->create([
                    'first_name' => $this->pick($this->chance(0.6) ? self::FIRST_F : self::FIRST_M),
                    'last_name' => $last, 'phone' => $p = $this->phone(), 'whatsapp_phone' => $p,
                    'occupation' => $this->pick(['Öğretmen', 'Esnaf', 'Memur', 'Çiftçi', 'Mühendis', 'Ev hanımı', 'Serbest meslek']),
                ]);
                $student->guardians()->attach($guardian->id, ['relationship' => $this->pick(['mother', 'father']), 'is_primary' => true, 'is_financially_responsible' => true]);

                $student->tags()->attach($demoTag->id);
                if ($this->chance(0.12)) {
                    $student->tags()->attach($burslu->id);
                }

                $price = $this->programs[$programCode]['price'];
                $discount = $this->chance(0.3) ? (string) (mt_rand(1, 5) * 1000) : '0';
                $scholarship = $this->chance(0.1) ? (string) round($price * 0.25, -2) : '0';
                $enrolledOn = CarbonImmutable::parse($student->registered_on);

                $service->enroll($student, [
                    'academic_term_id' => $this->term->id,
                    'program_id' => $this->programs[$programCode]['model']->id,
                    'education_package_id' => $this->programs[$programCode]['package_id'],
                    'class_group_id' => $group->id,
                    'list_price' => $price, 'discount_amount' => $discount,
                    'discount_reason' => $discount !== '0' ? 'Erken kayıt indirimi' : null,
                    'scholarship_amount' => $scholarship, 'scholarship_reason' => $scholarship !== '0' ? 'Başarı bursu' : null,
                    'enrolled_on' => $enrolledOn->toDateString(),
                    'financial_guardian_id' => $guardian->id,
                    'down_payment' => $this->chance(0.7) ? 10000 : 0,
                    'installment_count' => $this->pick([6, 8, 8, 10]),
                    'first_due_date' => '2026-09-15',
                ]);

                $this->createdStudents[$student->id] = $student;
            }
        }
    }

    /** Vadesi gelmiş ilk taksitlerden bir kısmını tahsil et (bağımsız, hata toleranslı). */
    private function payments(): int
    {
        $service = app(PaymentService::class);
        Auth::setUser($this->actor);
        $accounts = FinanceAccount::query()->pluck('id', 'kind');
        $today = CarbonImmutable::today();
        $done = 0;

        foreach ($this->createdStudents as $id => $student) {
            if (! $this->chance(0.65)) {
                continue;
            }
            try {
                $installments = DB::table('installments')->where('student_id', $id)->orderBy('sequence')->get();
                $payCount = 0;
                foreach ($installments as $inst) {
                    if (CarbonImmutable::parse($inst->due_date)->gt($today->addDays(3))) {
                        break;
                    }
                    if ($payCount >= ($this->chance(0.4) ? 2 : 1)) {
                        break;
                    }
                    $method = $this->pick(['cash', 'cash', 'pos', 'bank_transfer']);
                    $paidAt = CarbonImmutable::parse($inst->due_date)->subDays(mt_rand(0, 3))->setTime(mt_rand(9, 17), mt_rand(0, 59));
                    if ($paidAt->gt(CarbonImmutable::now())) {
                        $paidAt = CarbonImmutable::now()->subHours(mt_rand(1, 20));
                    }
                    $service->collect($student, [
                        'finance_account_id' => $accounts[match ($method) { 'cash' => 'cash', 'pos' => 'pos', default => 'bank' }],
                        'method' => $method,
                        'amount' => $inst->amount,
                        'paid_at' => $paidAt->toDateTimeString(),
                        'enrollment_id' => $inst->enrollment_id,
                        'installment_ids' => [$inst->id],
                        'payer_name' => $student->primaryGuardian()?->full_name,
                    ]);
                    $payCount++;
                    $done++;
                }
            } catch (\Throwable $e) {
                $this->command?->warn("Tahsilat atlandı (öğrenci $id): ".$e->getMessage());
            }
        }

        return $done;
    }
}
