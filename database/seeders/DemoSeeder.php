<?php

namespace Database\Seeders;

use App\Models\AcademicTerm;
use App\Models\Announcement;
use App\Models\Branch;
use App\Models\ClassGroup;
use App\Models\Classroom;
use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Models\EducationPackage;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSection;
use App\Models\ExamType;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceEntry;
use App\Models\GuidanceMeeting;
use App\Models\Guardian;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LessonSchedule;
use App\Models\OutboundMessage;
use App\Models\Product;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentGoal;
use App\Models\StudentNote;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\Tag;
use App\Models\Teacher;
use App\Models\Topic;
use App\Models\User;
use App\Services\Academic\SessionGenerator;
use App\Services\Attendance\AutoAttendanceService;
use App\Services\Attendance\PresenceService;
use App\Services\Exams\ExamResultService;
use App\Services\Finance\EnrollmentService;
use App\Services\Finance\InstallmentMaintenance;
use App\Services\Finance\Ledger;
use App\Services\Finance\PaymentService;
use App\Support\BranchContext;
use App\Support\Sensitive;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DEMO VERİ — üretim verisinden ayrıdır.
 *
 * - Yalnızca `DEMO_SEED=1 php artisan db:seed --class=DemoSeeder` ile çalışır.
 * - İçinde öğrenci bulunan bir şubede çalışmayı reddeder (gerçek veri ezilmez).
 * - Tüm demo öğrencileri "Demo" etiketi taşır; settings.demo.seeded_at işaretlenir.
 * - `php artisan kurs:demo-purge` demo verisini temizler.
 */
class DemoSeeder extends Seeder
{
    private const FIRST_M = ['Ahmet', 'Mehmet', 'Mustafa', 'Emre', 'Yusuf', 'Burak', 'Can', 'Ömer', 'Kerem', 'Eren', 'Efe', 'Arda', 'Berk', 'Kaan', 'Umut', 'Furkan', 'Enes', 'Baran', 'Alperen', 'Hüseyin', 'Serkan', 'Oğuzhan', 'Tuna', 'Deniz', 'Batuhan', 'Yiğit', 'Emir', 'Mert', 'Onur', 'Selim'];
    private const FIRST_F = ['Elif', 'Ayşe', 'Zeynep', 'Fatma', 'Merve', 'Esra', 'Büşra', 'Ecrin', 'Defne', 'İrem', 'Selin', 'Nisa', 'Ceren', 'Buse', 'Melis', 'Sude', 'Azra', 'Hira', 'Beyza', 'Nehir', 'Ela', 'Duru', 'Asya', 'Yağmur', 'Gizem', 'Sena', 'Rabia', 'Hande', 'Damla', 'Ezgi'];
    private const LAST = ['Yılmaz', 'Kaya', 'Demir', 'Şahin', 'Çelik', 'Yıldız', 'Yıldırım', 'Öztürk', 'Aydın', 'Özdemir', 'Arslan', 'Doğan', 'Kılıç', 'Aslan', 'Çetin', 'Kara', 'Koç', 'Kurt', 'Özkan', 'Şimşek', 'Polat', 'Erdoğan', 'Güneş', 'Aksoy', 'Tekin', 'Bulut', 'Korkmaz', 'Uçar', 'Karaca', 'Taş', 'Duman', 'Keskin', 'Ateş', 'Bozkurt', 'Güler'];
    private const SCHOOLS = ['Erbaa Anadolu Lisesi', 'Erbaa Fen Lisesi', 'Gazi Osman Paşa Anadolu Lisesi', 'Erbaa Mesleki ve Teknik Anadolu Lisesi', 'Erbaa Kız Anadolu İmam Hatip Lisesi', 'Cumhuriyet Ortaokulu', 'Atatürk Ortaokulu', 'Tokat Bilim ve Sanat Merkezi'];

    private Branch $branch;
    private AcademicTerm $term;
    private array $users = [];
    private array $subjects = [];
    private array $topics = [];
    private array $programs = [];
    private array $teachers = [];
    private array $classrooms = [];
    private array $groups = [];
    private array $students = [];
    private array $ability = [];
    private string $demoPassword;

    public function run(): void
    {
        if (! env('DEMO_SEED')) {
            throw new RuntimeException('Demo veri yalnızca DEMO_SEED=1 ile yüklenir.');
        }

        $this->branch = Branch::query()->where('code', 'ERBAA')->firstOrFail();

        // Tek transaction: yarıda kalırsa geride yarım demo verisi kalmaz.
        app(BranchContext::class)->run($this->branch->id, fn () => DB::transaction(function () {
            if (Student::query()->exists()) {
                throw new RuntimeException('Şubede öğrenci kaydı var; demo veri gerçek verinin üzerine yüklenmez.');
            }

            mt_srand(20260914);
            config(['kurs.silent_events' => true]);
            $this->term = AcademicTerm::query()->where('is_current', true)->firstOrFail();
            $this->demoPassword = 'Demo'.date('Y').'!';

            $this->step('Kullanıcılar', fn () => $this->staffUsers());
            $this->step('Dersler ve konular', fn () => $this->subjectsAndTopics());
            $this->step('Programlar', fn () => $this->programs());
            $this->step('Derslikler', fn () => $this->classrooms());
            $this->step('Öğretmenler', fn () => $this->teachers());
            $this->step('Sınıflar', fn () => $this->classGroups());
            $this->step('Ders programı', fn () => $this->schedules());
            $this->step('Ders oturumları', fn () => app(SessionGenerator::class)->generate($this->branch->id, CarbonImmutable::today()->subDays(24), CarbonImmutable::today()->addDays(14)));
            $this->step('Öğrenciler, veliler, kayıtlar', fn () => $this->studentsAndEnrollments());
            $this->step('Tahsilatlar', fn () => $this->payments());
            $this->step('Gelir/gider', fn () => $this->financeEntries());
            $this->step('Cihazlar ve giriş/çıkış', fn () => $this->presence());
            $this->step('Deneme sınavları', fn () => $this->exams());
            $this->step('Rehberlik, hedef, not', fn () => $this->guidance());
            $this->step('CRM adayları', fn () => $this->leads());
            $this->step('Ödev ve etüt', fn () => $this->homeworkAndStudy());
            $this->step('İletişim ve envanter', fn () => $this->communicationAndInventory());

            Settings::put('demo', ['seeded_at' => now()->toAtomString(), 'password_hint' => 'storage/app/private/demo-users.txt']);
            $this->writeCredentials();
        }));
    }

    private function step(string $label, callable $fn): void
    {
        $start = microtime(true);
        $fn();
        $this->command?->info(sprintf('  ✓ %s (%.1f sn)', $label, microtime(true) - $start));
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }

    private function tc(): string
    {
        $d = [mt_rand(1, 9)];
        for ($i = 1; $i < 9; $i++) {
            $d[] = mt_rand(0, 9);
        }
        $odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $even = $d[1] + $d[3] + $d[5] + $d[7];
        $d[] = ((($odd * 7) - $even) % 10 + 10) % 10;
        $d[] = array_sum($d) % 10;

        return implode('', $d);
    }

    private function phone(): string
    {
        return '05'.$this->pick(['30', '32', '33', '35', '36', '38', '42', '43', '44', '45', '52', '53', '55']).mt_rand(1000000, 9999999);
    }

    private function staffUsers(): void
    {
        $defs = [
            ['mudur', 'Hakan Aydemir', 'mudur'],
            ['muhasebe', 'Ayşe Demir', 'muhasebe'],
            ['rehber', 'Seda Karagöz', 'rehber'],
            ['kayit', 'Burcu Yalçın', 'danisman'],
        ];
        foreach ($defs as [$username, $name, $role]) {
            $user = User::query()->create([
                'branch_id' => $this->branch->id, 'name' => $name, 'username' => $username, 'user_type' => User::TYPE_STAFF,
                'password' => $this->demoPassword, 'email' => "{$username}@demo.erbaabilgi.local",
            ]);
            $user->assignRole($role);
            $this->users[$username] = $user;
            [$first, $last] = explode(' ', $name, 2);
            DB::table('employees')->insert([
                'branch_id' => $this->branch->id, 'user_id' => $user->id, 'first_name' => $first, 'last_name' => $last,
                'position' => ['mudur' => 'Müdür', 'muhasebe' => 'Muhasebe', 'rehber' => 'Rehber Öğretmen', 'danisman' => 'Kayıt Danışmanı'][$role],
                'hired_on' => '2023-09-01', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function subjectsAndTopics(): void
    {
        $subjects = [
            'MAT' => ['Matematik', 'indigo', ['Temel Kavramlar' => 0.1, 'Rasyonel Sayılar' => 0.05, 'Üslü Sayılar' => 0.08, 'Köklü Sayılar' => 0, 'Mutlak Değer' => -0.05, 'Problemler' => 0.12, 'Fonksiyonlar' => -0.18, 'Parabol' => -0.28, 'Olasılık' => -0.12, 'Logaritma' => -0.2]],
            'GEO' => ['Geometri', 'violet', ['Üçgenler' => 0, 'Dörtgenler' => -0.05, 'Daire' => -0.1, 'Analitik Geometri' => -0.2]],
            'TUR' => ['Türkçe', 'rose', ['Sözcükte Anlam' => 0.12, 'Cümlede Anlam' => 0.08, 'Paragraf' => 0.02, 'Dil Bilgisi' => -0.1, 'Yazım Kuralları' => 0.05, 'Noktalama' => 0]],
            'EDB' => ['Türk Dili ve Edebiyatı', 'pink', ['Şiir Bilgisi' => -0.05, 'Divan Edebiyatı' => -0.15, 'Cumhuriyet Dönemi' => 0]],
            'FIZ' => ['Fizik', 'sky', ['Kuvvet ve Hareket' => -0.08, 'Enerji' => 0, 'Elektrik' => -0.15, 'Optik' => -0.1]],
            'KIM' => ['Kimya', 'teal', ['Atom ve Periyodik Sistem' => 0.05, 'Mol Kavramı' => -0.12, 'Kimyasal Tepkimeler' => -0.05]],
            'BIY' => ['Biyoloji', 'emerald', ['Hücre' => 0.1, 'Kalıtım' => -0.1, 'Ekoloji' => 0.05]],
            'TAR' => ['Tarih', 'amber', ['İlk Türk Devletleri' => 0.05, 'Osmanlı Tarihi' => -0.05, 'İnkılap Tarihi' => 0.08]],
            'COG' => ['Coğrafya', 'lime', ['Harita Bilgisi' => 0.05, 'İklim' => 0, 'Nüfus' => 0.08]],
            'FEL' => ['Felsefe', 'orange', ['Bilgi Felsefesi' => -0.05, 'Mantık' => -0.12]],
            'DIN' => ['Din Kültürü', 'yellow', ['İnanç' => 0.15, 'İbadet' => 0.12]],
            'ING' => ['İngilizce', 'cyan', ['Grammar' => 0, 'Vocabulary' => 0.05, 'Reading' => -0.05]],
            'FEN' => ['Fen Bilimleri', 'green', ['Kuvvet ve Enerji' => 0, 'Madde ve Değişim' => 0.05, 'Canlılar' => 0.08]],
            'SOS' => ['Sosyal Bilimler', 'orange', ['Tarih' => 0.05, 'Coğrafya' => 0.03, 'Felsefe' => -0.05]],
        ];

        foreach ($subjects as $code => [$name, $color, $topics]) {
            $subject = Subject::query()->create(['code' => $code, 'name' => $name, 'short_name' => $code, 'color' => $color]);
            $this->subjects[$code] = $subject;
            $i = 0;
            foreach ($topics as $topicName => $difficulty) {
                $topic = Topic::query()->create(['subject_id' => $subject->id, 'name' => $topicName, 'sort' => $i++, 'outcome_code' => $code.'.'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
                $this->topics[$code][] = ['id' => $topic->id, 'difficulty' => $difficulty];
            }
        }
    }

    private function programs(): void
    {
        $defs = [
            'TYT' => ['TYT Hazırlık', 'TYT', 'group', 'sky', 45000, ['TUR' => 5, 'MAT' => 6, 'GEO' => 2, 'FIZ' => 2, 'KIM' => 2, 'BIY' => 2, 'TAR' => 1, 'COG' => 1]],
            'AYT_SAY' => ['AYT Sayısal', 'AYT_SAY', 'group', 'indigo', 62000, ['MAT' => 7, 'GEO' => 3, 'FIZ' => 4, 'KIM' => 3, 'BIY' => 3, 'TUR' => 3]],
            'AYT_EA' => ['AYT Eşit Ağırlık', 'AYT_EA', 'group', 'violet', 58000, ['MAT' => 7, 'GEO' => 2, 'EDB' => 4, 'TAR' => 3, 'COG' => 3, 'TUR' => 3]],
            'AYT_SOZ' => ['AYT Sözel', 'AYT_SOZ', 'group', 'amber', 55000, ['EDB' => 5, 'TAR' => 5, 'COG' => 4, 'FEL' => 3, 'DIN' => 2, 'TUR' => 3]],
            'MEZUN' => ['YKS Mezun', 'AYT_SAY', 'group', 'rose', 68000, ['MAT' => 8, 'GEO' => 3, 'FIZ' => 4, 'KIM' => 3, 'BIY' => 3, 'TUR' => 4]],
            'LGS' => ['LGS Hazırlık', 'LGS', 'group', 'emerald', 40000, ['TUR' => 5, 'MAT' => 6, 'FEN' => 5, 'TAR' => 2, 'DIN' => 2, 'ING' => 2]],
            'ARA' => ['Ara Sınıf (10-11)', 'TYT', 'group', 'teal', 36000, ['MAT' => 5, 'TUR' => 4, 'FIZ' => 2, 'KIM' => 2, 'BIY' => 2]],
            'BIREBIR' => ['Birebir Ders', null, 'private', 'orange', 1200, ['MAT' => 0]],
            'ETUT' => ['Etüt', null, 'study', 'slate', 0, ['MAT' => 0]],
        ];

        foreach ($defs as $code => [$name, $track, $kind, $color, $price, $subjects]) {
            $program = Program::query()->create(['code' => $code, 'name' => $name, 'exam_track' => $track, 'kind' => $kind, 'color' => $color]);
            foreach ($subjects as $s => $hours) {
                $program->subjects()->attach($this->subjects[$s]->id, ['weekly_hours' => $hours]);
            }
            if ($price > 0 && $kind === 'group') {
                EducationPackage::query()->create([
                    'program_id' => $program->id, 'academic_term_id' => $this->term->id, 'name' => "{$name} {$this->term->name}",
                    'list_price' => $price, 'default_installments' => 8, 'includes' => 'Ders, deneme sınavları, rehberlik, yayın seti',
                ]);
            }
            $this->programs[$code] = ['model' => $program, 'price' => $price, 'subjects' => $subjects];
        }
    }

    private function classrooms(): void
    {
        foreach ([['Derslik A', 'classroom', 24, 'Zemin'], ['Derslik B', 'classroom', 22, 'Zemin'], ['Derslik C', 'classroom', 24, '1. Kat'], ['Derslik D', 'classroom', 20, '1. Kat'],
            ['Derslik E', 'classroom', 22, '2. Kat'], ['Etüt 1', 'study', 8, '2. Kat'], ['Etüt 2', 'study', 8, '2. Kat'], ['Konferans Salonu', 'hall', 80, 'Zemin']] as [$name, $kind, $cap, $floor]) {
            $this->classrooms[$name] = Classroom::query()->create(['name' => $name, 'kind' => $kind, 'capacity' => $cap, 'floor' => $floor]);
        }
    }

    private function teachers(): void
    {
        $defs = [
            ['Murat', 'Özer', 'MAT'], ['Gülşen', 'Arıkan', 'MAT'], ['Volkan', 'Tuna', 'MAT'], ['Sibel', 'Erkan', 'GEO'],
            ['Cemile', 'Yurt', 'TUR'], ['Levent', 'Sarı', 'TUR'], ['Nurcan', 'Ekinci', 'EDB'], ['Tolga', 'Başaran', 'FIZ'],
            ['Pınar', 'Uysal', 'FIZ'], ['Kenan', 'Akgül', 'KIM'], ['Derya', 'Coşkun', 'BIY'], ['İsmail', 'Kocabaş', 'TAR'],
            ['Filiz', 'Durmaz', 'COG'], ['Orhan', 'Bayram', 'FEL'], ['Hatice', 'Toprak', 'DIN'], ['Gökhan', 'Sezer', 'ING'],
            ['Aylin', 'Karakaya', 'FEN'], ['Recep', 'Altun', 'MAT'], ['Esin', 'Önal', 'TUR'], ['Barış', 'Kalkan', 'KIM'],
        ];
        $colors = ['indigo', 'violet', 'sky', 'teal', 'rose', 'amber', 'emerald', 'orange', 'cyan', 'pink'];

        foreach ($defs as $i => [$first, $last, $subject]) {
            $username = Str::slug(Str::ascii(mb_strtolower($first.'.'.$last, 'UTF-8')), '.');
            $user = User::query()->create([
                'branch_id' => $this->branch->id, 'name' => "$first $last", 'username' => $username, 'user_type' => User::TYPE_TEACHER, 'password' => $this->demoPassword,
            ]);
            $user->assignRole('ogretmen');
            $teacher = Teacher::query()->create([
                'user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'title' => $this->subjects[$subject]->name.' Öğretmeni',
                'specialty' => $this->subjects[$subject]->name, 'phone' => $this->phone(), 'color' => $colors[$i % count($colors)],
                'hired_on' => CarbonImmutable::parse('2019-09-01')->addMonths(mt_rand(0, 60))->toDateString(),
                'employment_type' => $i % 5 === 4 ? 'hourly' : 'full_time', 'max_weekly_hours' => 30, 'hourly_rate' => $i % 5 === 4 ? 650 : null,
            ]);
            $teacher->subjects()->attach($this->subjects[$subject]->id);
            if ($subject === 'MAT' && $i === 0) {
                $teacher->subjects()->attach($this->subjects['GEO']->id);
            }
            $this->teachers[$subject][] = $teacher;
        }

        $this->users['rehber_teacher'] = $this->teachers['TUR'][0];
    }

    private function classGroups(): void
    {
        $defs = [
            ['12-SAY-A', 'AYT_SAY', 'Derslik A'], ['12-SAY-B', 'AYT_SAY', 'Derslik C'], ['12-EA-A', 'AYT_EA', 'Derslik B'],
            ['12-SÖZ-A', 'AYT_SOZ', 'Derslik D'], ['MEZUN-SAY', 'MEZUN', 'Derslik E'], ['11-TYT-A', 'TYT', 'Derslik B'],
            ['10-ARA-A', 'ARA', 'Derslik D'], ['8-LGS-A', 'LGS', 'Derslik C'], ['8-LGS-B', 'LGS', 'Derslik E'], ['MEZUN-EA', 'AYT_EA', 'Derslik A'],
        ];
        foreach ($defs as [$name, $program, $room]) {
            $subjectCode = array_key_first($this->programs[$program]['subjects']);
            $this->groups[$name] = ClassGroup::query()->create([
                'academic_term_id' => $this->term->id, 'program_id' => $this->programs[$program]['model']->id,
                'homeroom_classroom_id' => $this->classrooms[$room]->id, 'advisor_teacher_id' => $this->teachers[$subjectCode][0]->id,
                'name' => $name, 'capacity' => min(24, $this->classrooms[$room]->capacity),
            ]);
            $this->groups[$name]->setAttribute('program_code', $program);
        }
    }

    /**
     * Çakışmasız haftalık program: bellekte öğretmen/derslik/sınıf doluluk haritası tutulur.
     * Hafta içi akşam 16:30 sonrası + cumartesi tam gün (lise öğrencileri okul sonrası gelir);
     * mezun ve LGS sınıfları hafta içi gündüz de ders alır.
     */
    private function schedules(): void
    {
        $slotsWeekday = [['16:30', '17:20'], ['17:30', '18:20'], ['18:30', '19:20']];
        $slotsDay = [['09:00', '09:50'], ['10:00', '10:50'], ['11:00', '11:50'], ['13:00', '13:50'], ['14:00', '14:50'], ['15:00', '15:50']];
        $busy = [];
        $validFrom = $this->term->starts_on->toDateString();

        foreach ($this->groups as $name => $group) {
            $programCode = $group->program_code;
            $hours = array_filter($this->programs[$programCode]['subjects']);
            $queue = [];
            foreach ($hours as $code => $h) {
                for ($i = 0; $i < $h; $i++) {
                    $queue[] = $code;
                }
            }
            shuffle($queue);

            $daytime = str_starts_with($name, 'MEZUN');
            $slots = [];
            foreach (range(1, 6) as $weekday) {
                $daySlots = $weekday === 6 || $daytime ? $slotsDay : $slotsWeekday;
                foreach ($daySlots as $slot) {
                    $slots[] = [$weekday, $slot[0], $slot[1]];
                }
            }

            foreach ($slots as [$weekday, $start, $end]) {
                if ($queue === []) {
                    break;
                }
                $key = "$weekday|$start";
                if (isset($busy['group'][$name][$key])) {
                    continue;
                }

                foreach ($queue as $qi => $code) {
                    $teacher = collect($this->teachers[$code] ?? [])->first(fn ($t) => ! isset($busy['teacher'][$t->id][$key]));
                    if (! $teacher) {
                        continue;
                    }
                    $roomCandidates = array_merge([$group->homeroom_classroom_id], collect($this->classrooms)->where('kind', 'classroom')->pluck('id')->all());
                    $roomId = collect($roomCandidates)->first(fn ($r) => ! isset($busy['room'][$r][$key]));
                    if (! $roomId) {
                        continue;
                    }

                    LessonSchedule::query()->create([
                        'academic_term_id' => $this->term->id, 'class_group_id' => $group->id, 'subject_id' => $this->subjects[$code]->id,
                        'teacher_id' => $teacher->id, 'classroom_id' => $roomId, 'weekday' => $weekday,
                        'starts_at' => $start.':00', 'ends_at' => $end.':00', 'valid_from' => $validFrom,
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

    private function studentsAndEnrollments(): void
    {
        $service = app(EnrollmentService::class);
        $demoTag = Tag::query()->firstOrCreate(['name' => 'Demo'], ['color' => 'slate']);
        $tags = collect(['Burslu' => 'emerald', 'VIP' => 'amber', 'Yeni Kayıt' => 'sky', 'Kardeş' => 'violet', 'Riskli' => 'rose'])
            ->map(fn ($c, $n) => Tag::query()->firstOrCreate(['name' => $n], ['color' => $c]));
        Auth::setUser($this->users['kayit']);

        $no = 2026001;
        $sharedGuardians = [];
        $groupSizes = ['12-SAY-A' => 23, '12-SAY-B' => 21, '12-EA-A' => 22, '12-SÖZ-A' => 15, 'MEZUN-SAY' => 22, '11-TYT-A' => 20, '10-ARA-A' => 18, '8-LGS-A' => 22, '8-LGS-B' => 19, 'MEZUN-EA' => 18];

        foreach ($groupSizes as $groupName => $size) {
            $group = $this->groups[$groupName];
            $programCode = $group->program_code;
            $grade = match (true) {
                str_starts_with($groupName, '12') => '12', str_starts_with($groupName, '11') => '11', str_starts_with($groupName, '10') => '10',
                str_starts_with($groupName, '8') => '8', default => 'Mezun',
            };
            $field = match ($programCode) { 'AYT_SAY', 'MEZUN' => 'SAY', 'AYT_EA' => 'EA', 'AYT_SOZ' => 'SOZ', 'LGS' => 'LGS', default => 'TYT' };

            for ($i = 0; $i < $size; $i++) {
                $female = $this->chance(0.52);
                $first = $this->pick($female ? self::FIRST_F : self::FIRST_M);
                $last = $this->pick(self::LAST);
                $birthYear = match ($grade) { '8' => 2012, '10' => 2010, '11' => 2009, '12' => 2008, default => 2007 };

                $student = Student::query()->create(array_merge([
                    'student_no' => (string) $no++,
                    'first_name' => $first, 'last_name' => $last,
                    'birth_date' => CarbonImmutable::create($birthYear, mt_rand(1, 12), mt_rand(1, 28))->toDateString(),
                    'gender' => $female ? 'female' : 'male',
                    'school_name' => $grade === '8' ? $this->pick(array_slice(self::SCHOOLS, 5, 2)) : ($grade === 'Mezun' ? null : $this->pick(array_slice(self::SCHOOLS, 0, 5))),
                    'school_grade' => $grade, 'field' => $field,
                    'target_university' => $grade === '8' ? null : $this->pick(['Hacettepe Üniversitesi', 'ODTÜ', 'Ankara Üniversitesi', 'Ondokuz Mayıs Üniversitesi', 'Tokat Gaziosmanpaşa Üniversitesi', 'İstanbul Teknik Üniversitesi', 'Gazi Üniversitesi']),
                    'target_department' => match ($field) {
                        'SAY' => $this->pick(['Tıp', 'Bilgisayar Mühendisliği', 'Diş Hekimliği', 'Eczacılık', 'Elektrik-Elektronik Müh.']),
                        'EA' => $this->pick(['Hukuk', 'İşletme', 'Psikoloji', 'Uluslararası İlişkiler']),
                        'SOZ' => $this->pick(['Tarih Öğretmenliği', 'Türk Dili ve Edebiyatı', 'Gazetecilik']),
                        'LGS' => null, default => $this->pick(['Hemşirelik', 'Öğretmenlik', 'Mühendislik']),
                    },
                    'phone' => $grade === '8' ? null : $this->phone(),
                    'address' => $this->pick(['Ahmetyesevi', 'Cumhuriyet', 'Yeni', 'Karşıyaka', 'Gazipaşa', 'Hacıbey', 'Fatih', 'Hükümet']).' Mah., Erbaa / Tokat',
                    'status' => $this->chance(0.03) ? 'frozen' : 'active',
                    'registered_on' => CarbonImmutable::parse('2026-07-01')->addDays(mt_rand(0, 70))->toDateString(),
                ], Sensitive::nationalIdColumns($this->tc())));
                $student->forceFill(['whatsapp_phone' => $student->phone])->save();

                // Kardeşler: %8 öğrenci önceki bir veliyi paylaşır
                if ($sharedGuardians && $this->chance(0.08)) {
                    $guardian = $this->pick($sharedGuardians);
                    $student->forceFill(['last_name' => $guardian->last_name])->save();
                    $student->tags()->attach($tags['Kardeş']->id);
                } else {
                    $guardian = Guardian::query()->create(array_merge([
                        'first_name' => $this->pick($this->chance(0.6) ? self::FIRST_F : self::FIRST_M), 'last_name' => $last,
                        'phone' => $p = $this->phone(), 'whatsapp_phone' => $p, 'occupation' => $this->pick(['Öğretmen', 'Esnaf', 'Memur', 'Çiftçi', 'Mühendis', 'Ev hanımı', 'Hemşire', 'Serbest meslek']),
                    ], Sensitive::nationalIdColumns($this->tc())));
                    $sharedGuardians[] = $guardian;
                }
                $student->guardians()->attach($guardian->id, ['relationship' => $this->pick(['mother', 'father']), 'is_primary' => true, 'is_financially_responsible' => true]);
                DB::table('communication_consents')->insertOrIgnore([
                    'consentable_type' => 'guardian', 'consentable_id' => $guardian->id, 'channel' => 'whatsapp', 'purpose' => 'informational',
                    'granted' => true, 'source' => 'Kayıt formu', 'recorded_at' => now(),
                ]);

                $student->tags()->attach($demoTag->id);
                if ($this->chance(0.12)) {
                    $student->tags()->attach($tags['Burslu']->id);
                }
                if ($this->chance(0.06)) {
                    $student->tags()->attach($tags['VIP']->id);
                }

                $price = $this->programs[$programCode]['price'];
                $discount = $this->chance(0.35) ? (string) (mt_rand(1, 6) * 1000) : '0';
                $scholarship = $this->chance(0.12) ? (string) round($price * $this->pick([0.25, 0.5]), -2) : '0';
                $enrolledOn = CarbonImmutable::parse($student->registered_on);

                $service->enroll($student, [
                    'academic_term_id' => $this->term->id,
                    'program_id' => $this->programs[$programCode]['model']->id,
                    'education_package_id' => EducationPackage::query()->where('program_id', $this->programs[$programCode]['model']->id)->value('id'),
                    'class_group_id' => $group->id,
                    'list_price' => $price, 'discount_amount' => $discount, 'discount_reason' => $discount !== '0' ? 'Erken kayıt indirimi' : null,
                    'scholarship_amount' => $scholarship, 'scholarship_reason' => $scholarship !== '0' ? 'Başarı bursu' : null,
                    'enrolled_on' => $enrolledOn->toDateString(),
                    'financial_guardian_id' => $guardian->id,
                    'down_payment' => $this->chance(0.7) ? 10000 : 0,
                    'installment_count' => $this->pick([6, 8, 8, 9, 10]),
                    'first_due_date' => '2026-'.$this->pick(['08', '09']).'-15',
                ]);

                if ($student->status === 'frozen') {
                    $student->forceFill(['status' => 'frozen'])->save();
                }
                // Öğrenci yeteneği: sınav cevapları ve devam davranışı için
                $this->ability[$student->id] = ['base' => mt_rand(30, 82) / 100, 'discipline' => mt_rand(70, 99) / 100, 'trend' => mt_rand(-8, 30) / 1000];
                $this->students[$student->id] = ['model' => $student, 'group' => $groupName, 'program' => $programCode];
            }
        }

        app(InstallmentMaintenance::class)->markOverdue($this->branch->id);
    }

    private function payments(): void
    {
        $service = app(PaymentService::class);
        Auth::setUser($this->users['muhasebe']);
        $accounts = FinanceAccount::query()->pluck('id', 'kind');
        $today = CarbonImmutable::today();

        foreach ($this->students as $id => $s) {
            $payer = $this->ability[$id]['discipline'];
            $installments = DB::table('installments')->where('student_id', $id)->orderBy('sequence')->get();

            foreach ($installments as $inst) {
                $due = CarbonImmutable::parse($inst->due_date);
                if ($due->gt($today->addDays(3))) {
                    break;
                }
                // Disiplinli veliler vadesinde öder; bir kısmı gecikir ya da kısmi öder.
                if (! $this->chance($payer - ($due->gt($today->subDays(20)) ? 0.25 : 0))) {
                    continue;
                }
                $paidAt = $due->subDays(mt_rand(0, 4))->addDays($this->chance(0.25) ? mt_rand(1, 9) : 0)->setTime(mt_rand(9, 18), mt_rand(0, 59));
                if ($paidAt->gt(now())) {
                    $paidAt = CarbonImmutable::now()->subDays(mt_rand(0, 9))->setTime(mt_rand(9, 18), mt_rand(0, 59));
                    if ($paidAt->gt(now())) {
                        $paidAt = CarbonImmutable::now()->subHours(mt_rand(1, 8));
                    }
                }
                $partial = $this->chance(0.08);
                $method = $this->pick(['cash', 'cash', 'pos', 'credit_card', 'bank_transfer', 'eft']);
                $service->collect($s['model'], [
                    'finance_account_id' => $accounts[match ($method) { 'cash' => 'cash', 'pos', 'credit_card' => 'pos', default => 'bank' }],
                    'method' => $method,
                    'amount' => $partial ? bcdiv((string) $inst->amount, '2', 2) : $inst->amount,
                    'paid_at' => $paidAt->toDateTimeString(),
                    'enrollment_id' => $inst->enrollment_id,
                    'installment_ids' => [$inst->id],
                    'payer_name' => $s['model']->primaryGuardian()?->full_name,
                ]);
                if ($partial) {
                    break;
                }
            }
        }

        app(InstallmentMaintenance::class)->markOverdue($this->branch->id);
    }

    private function financeEntries(): void
    {
        Auth::setUser($this->users['muhasebe']);
        $ledger = app(Ledger::class);
        $bank = FinanceAccount::query()->where('kind', 'bank')->value('id');
        $cash = FinanceAccount::query()->where('kind', 'cash')->value('id');
        $cats = FinanceCategory::query()->get()->keyBy('code');
        $start = CarbonImmutable::today()->subMonths(3)->startOfMonth();

        // Kasadaki nakdi aylık bankaya aktar (gerçekçi akış: kasada birikmesin)
        $entries = [];
        for ($m = 0; $m < 4; $m++) {
            $month = $start->addMonths($m);
            $entries[] = ['expense', 'rent', $bank, 38000, $month->addDays(4), 'Bina kirası', 'Erbaa Emlak'];
            $entries[] = ['expense', 'salary', $bank, 385000, $month->addDays(14), 'Personel ve öğretmen maaşları', null];
            $entries[] = ['expense', 'electricity', $bank, mt_rand(5200, 8800), $month->addDays(19), 'Elektrik faturası', 'YEDAŞ'];
            $entries[] = ['expense', 'internet', $bank, 1450, $month->addDays(9), 'Fiber internet', 'Türk Telekom'];
            $entries[] = ['expense', 'cleaning', $cash, mt_rand(2500, 4200), $month->addDays(12), 'Temizlik malzemesi ve hizmet', null];
            $entries[] = ['expense', 'stationery', $cash, mt_rand(1200, 3900), $month->addDays(7), 'Kırtasiye', 'Erbaa Kitabevi'];
            $entries[] = ['expense', 'advertising', $bank, mt_rand(6000, 15000), $month->addDays(2), 'Instagram ve Google reklamları', 'Meta / Google'];
            $entries[] = ['expense', 'food', $cash, mt_rand(3000, 6000), $month->addDays(22), 'Öğretmenler odası ikram', null];
            $entries[] = ['income', 'book_sale', $cash, mt_rand(4000, 12000), $month->addDays(10), 'Yayın seti satışı', null];
            if ($m % 2 === 0) {
                $entries[] = ['expense', 'maintenance', $bank, mt_rand(3500, 9000), $month->addDays(17), 'Klima bakımı', 'Bakım servisi'];
                $entries[] = ['expense', 'tax', $bank, mt_rand(18000, 32000), $month->addDays(25), 'KDV / muhtasar', 'Vergi Dairesi'];
                $entries[] = ['income', 'private_lesson', $cash, mt_rand(8000, 20000), $month->addDays(20), 'Birebir ders ücretleri', null];
            }
        }

        foreach ($entries as [$dir, $code, $account, $amount, $date, $desc, $counterparty]) {
            if ($date->gt(CarbonImmutable::today())) {
                continue;
            }
            DB::transaction(function () use ($ledger, $dir, $code, $account, $amount, $date, $desc, $counterparty, $cats) {
                $entry = FinanceEntry::query()->create([
                    'direction' => $dir, 'finance_category_id' => $cats[$code]->id, 'finance_account_id' => $account, 'amount' => $amount,
                    'entry_date' => $date->toDateString(), 'description' => $desc, 'counterparty' => $counterparty, 'created_by' => Auth::id(),
                ]);
                try {
                    $ledger->post($account, $dir === 'income' ? (string) $amount : '-'.$amount, $entry, $desc, $date);
                } catch (\Throwable) {
                    // Kasa yetersizse bankadan öde
                    DB::table('finance_entries')->where('id', $entry->id)->update(['finance_account_id' => FinanceAccount::query()->where('kind', 'bank')->value('id')]);
                    $ledger->post(FinanceAccount::query()->where('kind', 'bank')->value('id'), '-'.$amount, $entry, $desc, $date);
                }
            });
        }
    }

    private function presence(): void
    {
        Auth::logout();
        $main = Device::query()->create(['name' => 'Ana Giriş Parmak İzi', 'kind' => 'fingerprint', 'location' => 'Ana giriş', 'direction' => 'both', 'serial_no' => 'ZK-F22-0831', 'api_token_hash' => hash('sha256', Str::random(40)), 'api_token_prefix' => 'dev_demo']);
        Device::query()->create(['name' => 'Arka Kapı Kart Okuyucu', 'kind' => 'rfid', 'location' => 'Otopark girişi', 'direction' => 'both', 'serial_no' => 'RF-200-1147', 'api_token_hash' => hash('sha256', Str::random(40)), 'api_token_prefix' => 'dev_demo']);
        $main->forceFill(['last_seen_at' => now()->subSeconds(40), 'firmware' => 'ZKTeco 6.60'])->save();

        foreach ($this->students as $id => $s) {
            DeviceIdentity::query()->create(['person_type' => 'student', 'person_id' => $id, 'kind' => 'fingerprint', 'identifier' => (string) (1000 + $id)]);
        }

        $presence = app(PresenceService::class);
        $auto = app(AutoAttendanceService::class);
        $now = CarbonImmutable::now();

        for ($d = 21; $d >= 0; $d--) {
            $day = CarbonImmutable::today()->subDays($d);
            $sessions = DB::table('lesson_sessions')->where('branch_id', $this->branch->id)->where('date', $day->toDateString())->get(['class_group_id', 'starts_at', 'ends_at']);
            if ($sessions->isEmpty()) {
                continue;
            }
            $byGroup = $sessions->groupBy('class_group_id');

            foreach ($this->students as $id => $s) {
                if ($s['model']->status !== 'active') {
                    continue;
                }
                $groupSessions = $byGroup[$this->groups[$s['group']]->id] ?? null;
                if (! $groupSessions) {
                    continue;
                }
                $discipline = $this->ability[$id]['discipline'];
                if (! $this->chance($discipline)) {
                    continue; // gelmedi
                }
                $first = CarbonImmutable::parse($groupSessions->min('starts_at'));
                $last = CarbonImmutable::parse($groupSessions->max('ends_at'));
                $entry = $first->subMinutes(mt_rand(3, 25))->addMinutes($this->chance(1.08 - $discipline) ? mt_rand(18, 40) : 0);
                $exit = $last->addMinutes(mt_rand(1, 35));

                if ($entry->gt($now)) {
                    continue;
                }
                $presence->ingest(['identifier' => (string) (1000 + $id), 'event_type' => 'ENTRY', 'occurred_at' => $entry->toAtomString(), 'idempotency_key' => (string) Str::uuid()], $main);
                if ($exit->lt($now)) {
                    $presence->ingest(['identifier' => (string) (1000 + $id), 'event_type' => 'EXIT', 'occurred_at' => $exit->toAtomString(), 'idempotency_key' => (string) Str::uuid()], $main);
                }
            }

            $auto->run($this->branch->id, $d === 0 ? $now : $day->setTime(23, 59));
            DB::table('lesson_sessions')->where('branch_id', $this->branch->id)->where('date', $day->toDateString())->where('ends_at', '<', $now)
                ->update(['status' => 'completed', 'attendance_taken_at' => DB::raw('ends_at')]);
        }
    }

    private function exams(): void
    {
        Auth::setUser($this->users['mudur']);
        $service = app(ExamResultService::class);
        $types = ExamType::query()->get()->keyBy('code');
        $sundays = collect(range(0, 9))->map(fn ($w) => CarbonImmutable::today()->previous(CarbonImmutable::SUNDAY)->subWeeks($w))->reverse()->values();

        $plan = [
            ['TYT', 'Özdebir TYT Türkiye Geneli 1', 'Özdebir', 'national'], ['LGS', 'LGS Kurum Deneme 1', null, 'institution'],
            ['TYT', '3D TYT Deneme 1', '3D Yayınları', 'national'], ['AYT_SAY', 'Limit AYT Sayısal 1', 'Limit', 'national'],
            ['TYT', 'Kurum TYT Deneme 2', null, 'institution'], ['LGS', 'LGS Türkiye Geneli 2', 'Hız ve Renk', 'national'],
            ['TYT', 'Bilgi Sarmal TYT Türkiye Geneli 3', 'Bilgi Sarmal', 'national'], ['AYT_SAY', 'Kurum AYT Sayısal 2', null, 'institution'],
            ['TYT', 'Özdebir TYT Türkiye Geneli 4', 'Özdebir', 'national'], ['TYT', 'Kurum TYT Deneme 5', null, 'institution'],
        ];

        $sectionTopic = ['TUR' => 'TUR', 'MAT' => 'MAT', 'SOS' => 'SOS', 'FEN' => 'FEN', 'FIZ' => 'FIZ', 'KIM' => 'KIM', 'BIY' => 'BIY', 'TDE' => 'EDB', 'TAR1' => 'TAR', 'COG1' => 'COG', 'INK' => 'TAR', 'DIN' => 'DIN', 'ING' => 'ING'];

        foreach ($plan as $k => [$typeCode, $name, $publisher, $scope]) {
            $type = $types[$typeCode];
            $exam = Exam::query()->create([
                'exam_type_id' => $type->id, 'academic_term_id' => $this->term->id, 'name' => $name, 'publisher' => $publisher, 'scope' => $scope,
                'exam_date' => $sundays[$k]->toDateString(), 'wrong_penalty_ratio' => $type->wrong_penalty_ratio, 'base_score' => $type->base_score,
                'booklets' => ['A', 'B'], 'status' => 'answer_key_ready', 'created_by' => Auth::id(),
            ]);

            $keys = [];
            foreach ($type->sections as $si => $sec) {
                $section = ExamSection::query()->create([
                    'exam_id' => $exam->id, 'subject_id' => ($this->subjects[$sec['subject_code']] ?? null)?->id, 'code' => $sec['code'], 'name' => $sec['name'],
                    'question_count' => $sec['question_count'], 'coefficient' => $sec['coefficient'], 'sort' => $si,
                ]);
                $n = (int) $sec['question_count'];
                $bOrder = range(1, $n);
                shuffle($bOrder);
                $topicPool = $this->topics[$sectionTopic[$sec['code']] ?? ''] ?? [];
                $questions = [];
                for ($q = 1; $q <= $n; $q++) {
                    $answer = $this->pick(['A', 'B', 'C', 'D', 'E']);
                    $topic = $topicPool ? $topicPool[($q - 1) % count($topicPool)] : null;
                    ExamQuestion::query()->create([
                        'exam_section_id' => $section->id, 'number' => $q,
                        'booklet_map' => ['A' => ['no' => $q, 'answer' => $answer], 'B' => ['no' => $bOrder[$q - 1], 'answer' => $answer]],
                        'topic_id' => $topic['id'] ?? null, 'is_cancelled' => $k === 6 && $sec['code'] === 'MAT' && $q === 17,
                    ]);
                    $questions[] = ['answer' => $answer, 'b' => $bOrder[$q - 1], 'difficulty' => $topic['difficulty'] ?? 0];
                }
                $keys[$sec['code']] = $questions;
            }

            $eligible = collect($this->students)->filter(fn ($s) => match ($typeCode) {
                'LGS' => $s['program'] === 'LGS',
                'AYT_SAY' => in_array($s['program'], ['AYT_SAY', 'MEZUN'], true),
                default => $s['program'] !== 'LGS',
            });

            foreach ($eligible as $id => $s) {
                if (! $this->chance(0.9)) {
                    continue;
                }
                $booklet = $this->chance(0.5) ? 'A' : 'B';
                $answers = [];
                foreach ($keys as $code => $questions) {
                    $string = array_fill(0, count($questions), ' ');
                    $subjectBias = (crc32($id.$code) % 21 - 10) / 100;
                    foreach ($questions as $i => $q) {
                        $p = $this->ability[$id]['base'] + $subjectBias + $q['difficulty'] + $this->ability[$id]['trend'] * $k;
                        $pos = $booklet === 'A' ? $i : $q['b'] - 1;
                        if ($this->chance(max(0.05, min(0.95, $p)))) {
                            $string[$pos] = $q['answer'];
                        } elseif ($this->chance(0.45)) {
                            $string[$pos] = $this->pick(array_values(array_diff(['A', 'B', 'C', 'D', 'E'], [$q['answer']])));
                        }
                    }
                    $answers[$code] = implode('', $string);
                }
                $service->recordAnswers($exam, $s['model'], $booklet, $answers, 'optical', $scope === 'national' ? mt_rand(800, 240000) : null);
            }

            $service->publish($exam);
        }

        // Yaklaşan sınavlar
        foreach ([['TYT', 'Özdebir TYT Türkiye Geneli 5', 6], ['AYT_SAY', 'Limit AYT Sayısal 3', 13], ['LGS', 'LGS Kurum Deneme 3', 6]] as [$code, $name, $days]) {
            $type = $types[$code];
            Exam::query()->create([
                'exam_type_id' => $type->id, 'academic_term_id' => $this->term->id, 'name' => $name, 'exam_date' => CarbonImmutable::today()->next(CarbonImmutable::SUNDAY)->addDays($days - 6)->toDateString(),
                'wrong_penalty_ratio' => $type->wrong_penalty_ratio, 'base_score' => $type->base_score, 'booklets' => ['A', 'B'], 'status' => 'draft', 'scope' => 'national',
            ]);
        }
    }

    private function guidance(): void
    {
        $counselor = $this->users['rehber'];
        $summaries = [
            'Deneme sonuçları birlikte değerlendirildi. Matematikte problemler konusunda ek etüt planlandı.',
            'Motivasyon düşüklüğü konuşuldu; haftalık çalışma programı yeniden düzenlendi.',
            'Hedef bölüm ve puan aralığı gözden geçirildi. Günlük soru hedefi 150 olarak belirlendi.',
            'Sınav kaygısı üzerine görüşüldü. Nefes egzersizleri ve deneme sonrası analiz alışkanlığı önerildi.',
            'Veli ile birlikte görüşüldü; devamsızlık nedenleri konuşuldu, düzenli takip kararı alındı.',
            'Paragraf hızını artırmak için her gün 20 paragraf sorusu çözmesi önerildi.',
        ];

        foreach ($this->students as $id => $s) {
            if ($s['program'] === 'LGS' && $this->chance(0.5)) {
                continue;
            }
            $count = mt_rand(0, 3);
            for ($i = 0; $i < $count; $i++) {
                $metAt = CarbonImmutable::today()->subDays(mt_rand(2, 70))->setTime(mt_rand(10, 17), $this->pick([0, 30]));
                GuidanceMeeting::query()->create([
                    'student_id' => $id, 'counselor_id' => $counselor->id, 'met_at' => $metAt, 'kind' => $this->pick(['individual', 'individual', 'guardian', 'phone']),
                    'summary' => $this->pick($summaries), 'goal' => $this->pick(['TYT 90 net', 'Matematik 30 net', 'Günlük 150 soru', 'Haftada 2 deneme']),
                    'motivation' => mt_rand(2, 5), 'study_discipline' => mt_rand(2, 5), 'visibility' => 'staff',
                    'next_meeting_on' => $this->chance(0.4) ? $metAt->addDays(mt_rand(14, 30))->toDateString() : null,
                ]);
            }

            if (in_array($s['program'], ['AYT_SAY', 'AYT_EA', 'AYT_SOZ', 'MEZUN'], true)) {
                StudentGoal::query()->create([
                    'student_id' => $id, 'university' => $s['model']->target_university, 'department' => $s['model']->target_department,
                    'target_rank' => $this->pick([5000, 15000, 30000, 60000, 120000]), 'target_tyt_net' => mt_rand(75, 110), 'target_ayt_net' => mt_rand(45, 70),
                    'subject_targets' => ['MAT' => mt_rand(25, 38), 'TUR' => mt_rand(28, 36)],
                ]);
            }

            if ($this->chance(0.15)) {
                StudentNote::query()->create(['student_id' => $id, 'user_id' => $this->users['mudur']->id, 'body' => $this->pick([
                    'Veli ödeme planında değişiklik talep etti, muhasebeye iletildi.', 'Kitap seti eksik teslim edildi, tamamlanacak.',
                    'Servis saatleri nedeniyle cumartesi derslerine 15 dk geç gelebiliyor.', 'Kardeşi de kayıt için görüşme yapmak istiyor.',
                ]), 'is_pinned' => $this->chance(0.3)]);
            }
        }
    }

    private function leads(): void
    {
        $stages = ['new' => 12, 'called' => 9, 'meeting_scheduled' => 6, 'met' => 6, 'offered' => 7, 'undecided' => 5, 'call_again' => 6, 'won' => 5, 'lost' => 6];
        $owners = [$this->users['kayit']->id, $this->users['mudur']->id];

        foreach ($stages as $stage => $count) {
            for ($i = 0; $i < $count; $i++) {
                $created = CarbonImmutable::now()->subDays(mt_rand(0, 45))->subMinutes(mt_rand(0, 600));
                $lead = Lead::query()->create([
                    'first_name' => $this->pick($this->chance(0.5) ? self::FIRST_F : self::FIRST_M), 'last_name' => $this->pick(self::LAST),
                    'phone' => $this->phone(), 'guardian_name' => $this->pick(self::FIRST_F).' '.$this->pick(self::LAST), 'guardian_phone' => $this->phone(),
                    'school_name' => $this->pick(self::SCHOOLS), 'school_grade' => $this->pick(['8', '10', '11', '12', 'Mezun']),
                    'interested_program_id' => $this->programs[$this->pick(['TYT', 'AYT_SAY', 'AYT_EA', 'LGS', 'MEZUN'])]['model']->id,
                    'source' => $this->pick(['instagram', 'instagram', 'google', 'whatsapp', 'referral', 'student_referral', 'phone', 'website', 'walk_in']),
                    'stage' => $stage, 'owner_id' => $this->pick($owners), 'stage_position' => $i,
                    'offered_price' => in_array($stage, ['offered', 'undecided', 'won'], true) ? $this->pick([42000, 55000, 58000, 62000]) : null,
                    'lost_reason' => $stage === 'lost' ? $this->pick(['Fiyat yüksek bulundu', 'Başka kuruma kayıt oldu', 'Ulaşım sorunu', 'Online eğitimi tercih etti']) : null,
                    'last_contacted_at' => $stage !== 'new' ? $created->addDays(mt_rand(0, 3)) : null,
                    'next_action_at' => in_array($stage, ['called', 'meeting_scheduled', 'offered', 'undecided', 'call_again'], true) ? CarbonImmutable::now()->addDays(mt_rand(-2, 6))->setTime(mt_rand(10, 18), 0) : null,
                    'next_action' => in_array($stage, ['call_again', 'undecided'], true) ? 'Tekrar ara, burs seçeneğini anlat' : ($stage === 'meeting_scheduled' ? 'Kurumda tanışma görüşmesi' : null),
                ]);
                DB::table('leads')->where('id', $lead->id)->update(['created_at' => $created, 'updated_at' => $created]);
                LeadActivity::query()->create(['lead_id' => $lead->id, 'user_id' => $lead->owner_id, 'kind' => 'note', 'body' => 'Aday kaydı oluşturuldu ('.Lead::SOURCES[$lead->source].').']);
                if ($stage !== 'new') {
                    LeadActivity::query()->create(['lead_id' => $lead->id, 'user_id' => $lead->owner_id, 'kind' => 'call', 'body' => $this->pick(['Veli ile görüşüldü, programları merak ediyor.', 'Ulaşılamadı, mesaj bırakıldı.', 'Fiyat bilgisi verildi.', 'Kurum ziyaretine davet edildi.'])]);
                }
            }
        }
    }

    private function homeworkAndStudy(): void
    {
        $titles = ['Problemler test 1-3', 'Paragraf 40 soru', 'Fonksiyonlar tarama testi', 'Üslü sayılar föy', 'Kuvvet-hareket soru bankası s.40-52', 'Mol kavramı çalışma kağıdı', 'Hücre konu özeti çıkar', 'Sözcükte anlam 60 soru'];

        foreach (array_slice($this->groups, 0, 8, true) as $name => $group) {
            foreach (range(1, 3) as $n) {
                $code = $this->pick(array_keys(array_filter($this->programs[$group->program_code]['subjects'])));
                $teacher = $this->teachers[$code][0];
                $assigned = CarbonImmutable::now()->subDays(mt_rand(1, 20));
                $due = $assigned->addDays(mt_rand(3, 10))->setTime(23, 59);
                $hw = Homework::query()->create([
                    'teacher_id' => $teacher->id, 'subject_id' => $this->subjects[$code]->id, 'class_group_id' => $group->id,
                    'title' => $this->pick($titles), 'description' => 'Çözümleri deftere yazarak getiriniz.', 'assigned_at' => $assigned, 'due_at' => $due,
                ]);
                foreach ($group->activeStudents()->pluck('students.id') as $sid) {
                    $past = $due->lt(now());
                    $status = $past ? ($this->chance($this->ability[$sid]['discipline'] - 0.1) ? ($this->chance(0.85) ? 'submitted' : 'late') : 'missed') : ($this->chance(0.6) ? 'seen' : 'assigned');
                    HomeworkSubmission::query()->create([
                        'homework_id' => $hw->id, 'student_id' => $sid, 'status' => $status,
                        'seen_at' => $status !== 'assigned' ? $assigned->addHours(mt_rand(1, 30)) : null,
                        'submitted_at' => in_array($status, ['submitted', 'late'], true) ? $due->addHours($status === 'late' ? mt_rand(2, 40) : -mt_rand(2, 40)) : null,
                        'score' => in_array($status, ['submitted', 'late'], true) ? mt_rand(55, 100) : null,
                    ]);
                }
            }
        }

        $studentIds = array_keys($this->students);
        foreach (range(1, 28) as $i) {
            $code = $this->pick(['MAT', 'MAT', 'FIZ', 'KIM', 'TUR', 'GEO']);
            $teacher = $this->teachers[$code][0];
            $day = CarbonImmutable::today()->addDays(mt_rand(-14, 10));
            if ($day->isSunday()) {
                $day = $day->addDay();
            }
            $start = $day->setTime($this->pick([12, 13, 20]), 0);
            $private = $i % 4 === 0;
            $session = StudySession::query()->create([
                'kind' => $private ? 'private' : 'study', 'teacher_id' => $teacher->id, 'subject_id' => $this->subjects[$code]->id,
                'classroom_id' => $this->classrooms[$private ? 'Etüt 2' : 'Etüt 1']->id, 'topic' => $this->pick(['Problemler', 'Parabol', 'Fonksiyonlar', 'Elektrik', 'Mol kavramı', 'Paragraf']),
                'starts_at' => $start, 'ends_at' => $start->addMinutes($private ? 60 : 50), 'capacity' => $private ? 1 : 6,
                'status' => $start->lt(now()) ? 'completed' : $this->pick(['approved', 'approved', 'requested']), 'fee' => $private ? 1200 : null,
                'requested_by' => $this->users['rehber']->id,
            ]);
            foreach ((array) array_rand(array_flip($studentIds), $private ? 1 : mt_rand(2, 6)) as $sid) {
                $session->students()->attach($sid, ['attendance' => $start->lt(now()) ? $this->pick(['present', 'present', 'absent']) : null]);
            }
        }
    }

    private function communicationAndInventory(): void
    {
        $templates = ['guardian.entry' => 'Öğrencimiz %s saat %s\'de kuruma giriş yapmıştır.', 'guardian.absent' => 'Öğrencimiz %s\'ın bugünkü dersine katılım kaydı bulunmamaktadır.', 'payment.upcoming' => '%s için %s vadeli taksit ödemenizi hatırlatırız.', 'exam.result.guardian' => '%s deneme sonucu yayımlandı: %s net.'];
        foreach (array_slice($this->students, 0, 160, true) as $id => $s) {
            $key = $this->pick(array_keys($templates));
            $guardian = $s['model']->primaryGuardian();
            $created = CarbonImmutable::now()->subMinutes(mt_rand(5, 60 * 24 * 12));
            $status = $this->pick(['read', 'read', 'delivered', 'delivered', 'sent', 'failed']);
            OutboundMessage::query()->create([
                'channel' => 'whatsapp', 'to' => Sensitive::normalizePhone($guardian?->phone) ?? '905300000000', 'recipient_type' => 'guardian', 'recipient_id' => $guardian?->id,
                'student_id' => $id, 'template_key' => $key, 'body' => sprintf($templates[$key], $s['model']->full_name, mt_rand(60, 105)),
                'status' => $status, 'attempts' => $status === 'failed' ? 3 : 1, 'provider' => 'meta_cloud',
                'error' => $status === 'failed' ? 'Alıcı numarası WhatsApp kullanmıyor.' : null,
                'sent_at' => $status !== 'failed' ? $created : null, 'delivered_at' => in_array($status, ['delivered', 'read'], true) ? $created->addSeconds(4) : null,
                'read_at' => $status === 'read' ? $created->addMinutes(mt_rand(1, 90)) : null, 'trigger' => 'automation', 'created_at' => $created, 'updated_at' => $created,
            ]);
        }

        Announcement::query()->create(['title' => 'Cumartesi deneme sınavı', 'body' => 'Bu cumartesi saat 10:00\'da Konferans Salonu\'nda TYT deneme sınavı yapılacaktır. Öğrencilerimizin 09:40\'ta salonda olmaları gerekmektedir.', 'audience' => ['all_students' => true], 'channels' => ['app', 'whatsapp'], 'published_at' => now()->subDays(2), 'recipient_count' => count($this->students), 'created_by' => $this->users['mudur']->id]);
        Announcement::query()->create(['title' => 'Veli toplantısı', 'body' => '12. sınıf velilerimizle 28 Eylül Pazar günü saat 14:00\'te dönem değerlendirme toplantısı yapılacaktır.', 'audience' => ['guardians' => true], 'channels' => ['app', 'whatsapp', 'sms'], 'published_at' => now()->subDays(5), 'recipient_count' => 190, 'created_by' => $this->users['mudur']->id]);

        foreach ([['TYT Matematik Soru Bankası', 'Karekök', 'MAT', 280, 420], ['TYT Türkçe Soru Bankası', 'Limit', 'TUR', 240, 360], ['AYT Fizik Konu Anlatımlı', 'Palme', 'FIZ', 310, 450], ['AYT Kimya Soru Bankası', 'Aydın', 'KIM', 260, 390], ['LGS 5 Deneme Seti', 'Hız ve Renk', 'MAT', 190, 290], ['Paragraf Kampı', 'Bilgi Sarmal', 'TUR', 160, 250]] as $i => [$name, $pub, $sub, $buy, $sell]) {
            $product = Product::query()->create(['name' => $name, 'publisher' => $pub, 'barcode' => '978605'.str_pad((string) ($i * 7919 + 100), 7, '0', STR_PAD_LEFT), 'subject_id' => $this->subjects[$sub]->id, 'purchase_price' => $buy, 'sale_price' => $sell, 'min_stock' => 10]);
            $qty = mt_rand(40, 120);
            DB::table('stock_movements')->insert(['branch_id' => $this->branch->id, 'product_id' => $product->id, 'quantity' => $qty, 'kind' => 'purchase', 'unit_price' => $buy, 'note' => 'Dönem başı alım', 'created_at' => now()->subDays(40)]);
            $delivered = mt_rand(20, $qty - 5);
            $sids = array_rand($this->students, $delivered);
            foreach ((array) $sids as $sid) {
                DB::table('stock_movements')->insert(['branch_id' => $this->branch->id, 'product_id' => $product->id, 'quantity' => -1, 'kind' => 'delivery', 'student_id' => $sid, 'note' => 'Öğrenciye teslim', 'created_at' => now()->subDays(mt_rand(1, 35))]);
            }
            $product->forceFill(['stock' => $qty - $delivered])->save();
        }
    }

    private function writeCredentials(): void
    {
        $lines = ["Erbaa Bilgi Eğitim — DEMO kullanıcıları (parola: {$this->demoPassword})", ''];
        foreach (['mudur' => 'Müdür', 'muhasebe' => 'Muhasebe', 'rehber' => 'Rehber öğretmen', 'kayit' => 'Kayıt danışmanı'] as $u => $label) {
            $lines[] = str_pad($label, 20).$u;
        }
        $lines[] = str_pad('Öğretmen (örnek)', 20).User::query()->where('user_type', 'teacher')->value('username');
        $file = storage_path('app/private/demo-users.txt');
        @mkdir(dirname($file), 0700, true);
        file_put_contents($file, implode("\n", $lines)."\n");
        chmod($file, 0600);
        $this->command?->warn("Demo kullanıcıları: {$file}");
    }
}
