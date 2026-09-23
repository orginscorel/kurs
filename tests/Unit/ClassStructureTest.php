<?php

namespace Tests\Unit;

use App\Exceptions\BusinessRuleException;
use App\Services\Placement\ClassStructure;
use App\Services\Placement\Core\PromotionPlanner;
use App\Services\Placement\Core\TrackPartitioner;
use PHPUnit\Framework\TestCase;

class ClassStructureTest extends TestCase
{
    public function test_default_structure_matches_legacy_nine_to_twelve_ab(): void
    {
        $s = ClassStructure::normalize(ClassStructure::defaultStructure([9, 10, 11, 12], ['A', 'B'], 15));

        $this->assertSame([9, 10, 11, 12], array_column($s['levels'], 'grade'));
        foreach ($s['levels'] as $l) {
            $this->assertTrue($l['sectioned']);
            $this->assertSame(['A', 'B'], array_column($l['sections'], 'code'));
            $this->assertSame([15, 15], array_column($l['sections'], 'capacity'));
        }
        // 9-11 TYT, 12'de şube harfine göre alan (A → SAY, B → EA)
        $this->assertSame(['TYT', 'TYT'], array_column($s['levels'][0]['sections'], 'track'));
        $this->assertSame(['SAY', 'EA'], array_column($s['levels'][3]['sections'], 'track'));
    }

    public function test_sectionless_level_and_graduates_are_supported(): void
    {
        $s = ClassStructure::normalize([
            'default_capacity' => 20,
            'levels' => [
                ['grade' => 13, 'sectioned' => true, 'sections' => [['code' => 'b', 'track' => 'EA', 'capacity' => 18], ['code' => 'A', 'track' => 'SAY']]],
                ['grade' => 11, 'sectioned' => false, 'sections' => [['code' => 'X', 'track' => 'TYT', 'capacity' => 25, 'short_name' => ' 11 ']]],
            ],
        ]);

        $this->assertSame([11, 13], array_column($s['levels'], 'grade'));
        $eleven = $s['levels'][0];
        $this->assertFalse($eleven['sectioned']);
        $this->assertSame(ClassStructure::NO_SECTION, $eleven['sections'][0]['code']);
        $this->assertSame(25, $eleven['sections'][0]['capacity']);
        $this->assertSame('11', $eleven['sections'][0]['short_name']);
        // şubeler sıralanır, küçük harf büyütülür, kapasite yoksa varsayılan
        $this->assertSame(['A', 'B'], array_column($s['levels'][1]['sections'], 'code'));
        $this->assertSame([20, 18], array_column($s['levels'][1]['sections'], 'capacity'));
        $this->assertSame('Mezun', $s['levels'][1]['label']);

        $this->assertSame('11', ClassStructure::className(11, ClassStructure::NO_SECTION));
        $this->assertSame('10-A', ClassStructure::className(10, 'A'));
        $this->assertSame('Mezun-B', ClassStructure::className(13, 'B'));
        $this->assertSame('11--', ClassStructure::key(11, ClassStructure::NO_SECTION));
    }

    public function test_invalid_structures_are_rejected_with_turkish_messages(): void
    {
        $cases = [
            ['levels' => []],
            ['levels' => [['grade' => 14, 'sections' => [['code' => 'A']]]]],
            ['levels' => [['grade' => 9, 'sections' => [['code' => 'A']]], ['grade' => 9, 'sections' => [['code' => 'B']]]]],
            ['levels' => [['grade' => 9, 'sectioned' => true, 'sections' => []]]],
            ['levels' => [['grade' => 9, 'sections' => [['code' => 'A'], ['code' => 'a']]]]],
            ['levels' => [['grade' => 9, 'sections' => [['code' => 'AB']]]]],
        ];
        foreach ($cases as $k => $input) {
            try {
                ClassStructure::normalize($input);
                $this->fail("Durum {$k} reddedilmeliydi");
            } catch (BusinessRuleException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_names_and_school_grades_are_parsed(): void
    {
        $this->assertSame([10, 'A'], ClassStructure::parseName('10-A'));
        $this->assertSame([11, '-'], ClassStructure::parseName('11'));
        $this->assertSame([13, 'B'], ClassStructure::parseName('Mezun-B'));
        $this->assertSame([13, '-'], ClassStructure::parseName('MEZUN'));
        $this->assertNull(ClassStructure::parseName('12-SAY-A'));
        $this->assertNull(ClassStructure::parseName('MEZUN-SAY'));

        $this->assertSame(13, ClassStructure::gradeOf('Mezun'));
        $this->assertSame(10, ClassStructure::gradeOf('10. sınıf'));
        $this->assertNull(ClassStructure::gradeOf(''));

        // Seviye işaretli + şube boş → şubesiz; seviye boş → addan
        $this->assertSame([11, '-'], ClassStructure::resolve('11 Özel', 11, null));
        $this->assertSame([12, 'B'], ClassStructure::resolve('12-B', null, null));
        $this->assertSame([null, null], ClassStructure::resolve('12-SAY-A', null, null));
    }

    public function test_promotion_to_level_without_matching_section_leaves_student_unplaced(): void
    {
        $plan = (new PromotionPlanner)->plan([
            ['id' => 1, 'grade' => 10, 'section' => 'A', 'status' => 'active'],
            ['id' => 2, 'grade' => 11, 'section' => '-', 'status' => 'active'],
        ], [10, 11, 12], ['11--' => 30, '12-A' => 15, '12-B' => 15]);

        $this->assertSame([
            ['id' => 1, 'from' => 10, 'to' => 11, 'section' => null],
            ['id' => 2, 'from' => 11, 'to' => 12, 'section' => null],
        ], $plan['promote']);
    }

    // ------------------------------------------------------------------ alan kovaları

    private const SAY_EA = ['A' => ['track' => 'SAY', 'capacity' => 15], 'B' => ['track' => 'EA', 'capacity' => 15]];

    public function test_single_track_level_keeps_one_bucket(): void
    {
        $r = TrackPartitioner::split(['A' => ['track' => 'TYT', 'capacity' => 15], 'B' => ['track' => 'TYT', 'capacity' => 15]],
            [3 => ['field' => 'SAY', 'current' => null, 'pinned' => false], 1 => ['field' => null, 'current' => 'B', 'pinned' => false]]);

        $this->assertCount(1, $r['buckets']);
        $this->assertSame(['A', 'B'], $r['buckets'][0]['sections']);
        $this->assertSame([1, 3], $r['buckets'][0]['students']);
        $this->assertSame([], $r['warnings']);
    }

    public function test_students_follow_their_field_and_pinned_ones_stay(): void
    {
        $r = TrackPartitioner::split(self::SAY_EA, [
            1 => ['field' => 'SAY', 'current' => null, 'pinned' => false],
            2 => ['field' => 'EA', 'current' => 'A', 'pinned' => false],   // yanlış şubede → EA kovasına
            3 => ['field' => 'EA', 'current' => 'A', 'pinned' => true],    // sabit → A'da kalır
            4 => ['field' => 'SAY', 'current' => 'B', 'pinned' => false],
        ]);
        $by = array_column($r['buckets'], 'students', 'track');
        $this->assertSame([1, 3, 4], $by['SAY']);
        $this->assertSame([2], $by['EA']);

        // "Yalnız sınıfsızlar" kipinde mevcut üyeler bulundukları şubede kalır
        $r2 = TrackPartitioner::split(self::SAY_EA, [
            2 => ['field' => 'EA', 'current' => 'A', 'pinned' => false],
            5 => ['field' => 'EA', 'current' => null, 'pinned' => false],
        ], 'unplaced');
        $by2 = array_column($r2['buckets'], 'students', 'track');
        $this->assertSame([2], $by2['SAY']);
        $this->assertSame([5], $by2['EA']);
    }

    public function test_unmatched_fields_go_to_general_or_emptiest_bucket(): void
    {
        $withGeneral = TrackPartitioner::split(['A' => ['track' => 'SAY', 'capacity' => 15], 'B' => ['track' => null, 'capacity' => 15]],
            [7 => ['field' => 'SOZ', 'current' => null, 'pinned' => false]]);
        $this->assertSame([7], array_column($withGeneral['buckets'], 'students', 'track')['']);
        $this->assertSame([], $withGeneral['warnings']);

        $students = [];
        for ($i = 1; $i <= 5; $i++) {
            $students[$i] = ['field' => 'SAY', 'current' => null, 'pinned' => false];
        }
        $students[20] = ['field' => 'SOZ', 'current' => null, 'pinned' => false];   // boş yeri çok olan EA'ya
        $students[21] = ['field' => null, 'current' => 'A', 'pinned' => false];     // mevcut şubesinde kalır
        $r = TrackPartitioner::split(self::SAY_EA, $students, 'redistribute', ['SOZ' => 'Sözel']);
        $by = array_column($r['buckets'], 'students', 'track');
        $this->assertSame([20], $by['EA']);
        $this->assertContains(21, $by['SAY']);
        $this->assertCount(1, $r['warnings']);
        $this->assertStringContainsString('Sözel', $r['warnings'][0]);
    }

    /**
     * Yerleştirme paneli seçenekleri: seviyeye uyan aktif şubeler döner; aynı seviyede farklı alanlar
     * (12-SAY-A ve 12-EA-A aynı şube harfi "A") çakışıp elenmez — kurum sınıf yapısı hiç tanımlanmamış
     * öğrencilerin (ör. "12-SAY-A") yine de bir şubeye yerleştirilebilmesi için gerekir.
     */
    public function test_level_options_lists_all_active_branches_without_section_collision(): void
    {
        $groups = [
            (object) ['id' => 1, 'name' => '12-SAY-A', 'grade_level' => 12, 'section' => 'A', 'capacity' => 24],
            (object) ['id' => 2, 'name' => '12-SAY-B', 'grade_level' => 12, 'section' => 'B', 'capacity' => 22],
            (object) ['id' => 3, 'name' => '12-EA-A', 'grade_level' => 12, 'section' => 'A', 'capacity' => 24],
            (object) ['id' => 4, 'name' => '11-EA-A', 'grade_level' => 11, 'section' => 'A', 'capacity' => 20],
        ];
        $counts = [1 => 11, 2 => 22, 3 => 5];

        $options = ClassStructure::levelOptions($groups, 12, $counts, 1);

        // 12. seviyenin üç şubesi de gelir (aynı "A" harfli iki şube ayrı seçenek kalır), 11 elenir
        $this->assertSame([1, 2, 3], array_column($options, 'class_group_id'));
        $this->assertSame(['12-SAY-A', '12-SAY-B', '12-EA-A'], array_column($options, 'name'));
        // is_current yalnız mevcut şube (id=1) için; dolu (count >= capacity) yalnız 12-SAY-B (22/22)
        $this->assertSame([true, false, false], array_column($options, 'is_current'));
        $this->assertSame([false, true, false], array_column($options, 'full'));
        $this->assertSame([11, 22, 5], array_column($options, 'count'));

        // Yerleşmemiş öğrenci (mevcut yok): hepsi seçilebilir, hiçbiri "is_current" değil
        $unplaced = ClassStructure::levelOptions($groups, 12, $counts, null);
        $this->assertCount(3, $unplaced);
        $this->assertSame([false, false, false], array_column($unplaced, 'is_current'));

        // Seviye bilinmiyorsa (okul sınıfı girilmemiş) seçenek yok
        $this->assertSame([], ClassStructure::levelOptions($groups, null, $counts, null));
    }
}
