<?php

namespace Tests\Unit;

use App\Exceptions\BusinessRuleException;
use App\Services\Discipline\DisciplineRules as R;
use App\Support\Discipline\DisciplineCatalog as C;
use App\Support\Permissions;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Disiplin: durum geçişleri, yetki (tek başına / kurul), uzaklaştırma tarihleri ve çakışma, eşikler, rota yetkileri. */
class DisciplineRulesTest extends TestCase
{
    public function test_incident_transitions(): void
    {
        $this->assertTrue(R::canMoveIncident('open', 'review'));
        $this->assertTrue(R::canMoveIncident('review', 'decided'));
        $this->assertTrue(R::canMoveIncident('decided', 'appealed'));
        $this->assertTrue(R::canMoveIncident('appealed', 'decided'));
        $this->assertTrue(R::canMoveIncident('closed', 'review'));
        $this->assertFalse(R::canMoveIncident('open', 'appealed'));
        $this->assertFalse(R::canMoveIncident('closed', 'decided'));
        $this->expectException(BusinessRuleException::class);
        R::assertIncidentMove('closed', 'open');
    }

    public function test_sanction_transitions_are_terminal_after_close(): void
    {
        $this->assertTrue(R::canMoveSanction('proposed', 'active'));
        $this->assertTrue(R::canMoveSanction('active', 'appealed'));
        $this->assertTrue(R::canMoveSanction('appealed', 'overturned'));
        $this->assertFalse(R::canMoveSanction('proposed', 'appealed'));
        foreach (['completed', 'expired', 'overturned', 'cancelled'] as $end) {
            foreach (array_keys(C::SANCTION_STATUSES) as $to) {
                $this->assertFalse(R::canMoveSanction($end, $to), "$end → $to");
            }
        }
    }

    public function test_board_sanctions_always_start_as_proposal(): void
    {
        $all = fn (string $p) => true;
        $decideOnly = fn (string $p) => $p === 'discipline.decide';
        $createOnly = fn (string $p) => $p === 'discipline.create';

        $this->assertSame('proposed', R::initialSanctionStatus('board', $all));
        $this->assertSame('proposed', R::initialSanctionStatus('board', $decideOnly));
        $this->assertSame('active', R::initialSanctionStatus('staff', $decideOnly));

        try {
            R::initialSanctionStatus('staff', $createOnly);
            $this->fail('Karar yetkisi olmadan yaptırım verilmemeli');
        } catch (BusinessRuleException $e) {
            $this->assertSame(403, $e->status);
        }
        $this->expectException(BusinessRuleException::class);
        R::initialSanctionStatus('board', $createOnly);
    }

    public function test_appeal_authority_follows_sanction_level(): void
    {
        $this->assertSame('discipline.board', R::appealPermission('board'));
        $this->assertSame('discipline.decide', R::appealPermission('staff'));
    }

    public function test_board_requires_settled_defense(): void
    {
        R::assertDefenseSettled('submitted');
        R::assertDefenseSettled('waived');
        foreach ([null, 'requested'] as $status) {
            try {
                R::assertDefenseSettled($status);
                $this->fail('Savunma alınmadan kurul kararı verilmemeli');
            } catch (BusinessRuleException $e) {
                $this->assertStringStartsWith('discipline_defense_', $e->errorCode);
            }
        }
    }

    public function test_suspension_range_is_inclusive_and_bounded(): void
    {
        $this->assertSame(['2026-09-17', '2026-09-17'], R::suspensionRange('2026-09-17', 1));
        $this->assertSame(['2026-09-29', '2026-10-01'], R::suspensionRange('2026-09-29', 3));
        $this->expectException(BusinessRuleException::class);
        R::suspensionRange('2026-09-17', 31);
    }

    #[DataProvider('overlapCases')]
    public function test_suspension_overlap(string $as, string $ae, string $bs, string $be, bool $expected): void
    {
        $this->assertSame($expected, R::rangesOverlap($as, $ae, $bs, $be));
    }

    public static function overlapCases(): array
    {
        return [
            'aynı gün' => ['2026-09-17', '2026-09-17', '2026-09-17', '2026-09-18', true],
            'uç uca değen' => ['2026-09-15', '2026-09-17', '2026-09-17', '2026-09-20', true],
            'içeride' => ['2026-09-10', '2026-09-20', '2026-09-12', '2026-09-13', true],
            'ardışık' => ['2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', false],
            'önce' => ['2026-09-01', '2026-09-02', '2026-09-10', '2026-09-12', false],
        ];
    }

    public function test_overlap_assertion_names_existing_sanction(): void
    {
        R::assertNoSuspensionOverlap('2026-09-20', '2026-09-21', [['starts_on' => '2026-09-17', 'ends_on' => '2026-09-18', 'sanction_no' => 'YPT-1']]);
        try {
            R::assertNoSuspensionOverlap('2026-09-18', '2026-09-19', [['starts_on' => '2026-09-17', 'ends_on' => '2026-09-18', 'sanction_no' => 'YPT-1']]);
            $this->fail('Çakışma yakalanmalı');
        } catch (BusinessRuleException $e) {
            $this->assertSame('discipline_suspension_overlap', $e->errorCode);
            $this->assertStringContainsString('YPT-1', $e->getMessage());
        }
    }

    public function test_expiry_counts_from_end_of_suspension(): void
    {
        $this->assertNull(R::expiresOn(null, '2026-09-17'));
        $this->assertSame('2026-11-16', R::expiresOn(60, '2026-09-17'));
        $this->assertSame('2026-09-30', R::expiresOn(10, '2026-09-17', '2026-09-20'));
        $this->assertSame('2026-09-27', R::expiresOn(10, '2026-09-17', '2026-09-10'));
    }

    public function test_levels_and_net_points(): void
    {
        $s = ['threshold_watch' => 10, 'threshold_warning' => 20, 'threshold_critical' => 35];
        $this->assertSame(0, R::netPoints(5, 9, true));
        $this->assertSame(5, R::netPoints(5, 9, false));
        $this->assertSame('none', R::level(9, $s));
        $this->assertSame('watch', R::level(10, $s));
        $this->assertSame('warning', R::level(20, $s));
        $this->assertSame('critical', R::level(99, $s));
        $this->assertSame(10.0, R::riskPoints(70, $s));
        $this->assertSame(0.0, R::riskPoints(0, $s));
        $this->assertSame('high', R::maxSeverity(['low', 'high', 'medium']));
        $this->assertSame('low', R::maxSeverity([]));
    }

    public function test_votes(): void
    {
        R::assertVotes('accepted', 3, 1, 0, 4);
        R::assertVotes('rejected', 1, 3, 0, 4);
        R::assertVotes('postponed', 0, 0, 0, 0);
        foreach ([['accepted', 2, 2, 0, 4], ['rejected', 3, 1, 0, 4], ['accepted', 4, 1, 0, 4]] as [$r, $f, $a, $x, $p]) {
            try {
                R::assertVotes($r, $f, $a, $x, $p);
                $this->fail("$r $f/$a/$x ($p) geçmemeli");
            } catch (BusinessRuleException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_default_catalog_is_consistent(): void
    {
        $types = C::defaultSanctionTypes();
        $this->assertTrue($types['suspension']['is_suspension']);
        $this->assertSame('board', $types['suspension']['authority']);
        $this->assertSame('staff', $types['verbal_warning']['authority']);
        foreach (C::defaultBehaviors() as $code => $b) {
            $this->assertArrayHasKey($b['category'], C::CATEGORIES, $code);
            $this->assertSame($b['kind'] === 'positive', in_array($b['category'], C::POSITIVE_CATEGORIES, true), $code);
            if ($b['suggested_sanction']) {
                $this->assertArrayHasKey($b['suggested_sanction'], $types, $code);
            }
            $this->assertLessThanOrEqual(50, $b['points']);
        }
        $this->assertGreaterThanOrEqual(15, count(C::defaultBehaviors()));
    }

    /* ------------------------------------------------------------ yetkiler */

    public function test_role_permissions(): void
    {
        $roles = Permissions::defaultRoles();
        $all = ['discipline.view', 'discipline.create', 'discipline.decide', 'discipline.board', 'discipline.settings', 'discipline.export'];
        foreach ($all as $p) {
            $this->assertContains($p, Permissions::all());
            $this->assertContains($p, $roles['yonetici']['permissions']);
            $this->assertContains($p, $roles['mudur']['permissions']);
        }
        $this->assertContains('discipline.view', $roles['rehber']['permissions']);
        $this->assertContains('discipline.create', $roles['rehber']['permissions']);
        $this->assertNotContains('discipline.decide', $roles['rehber']['permissions']);
        $this->assertNotContains('discipline.board', $roles['rehber']['permissions']);
        $this->assertSame(['discipline.view'], array_values(array_filter($roles['danisman']['permissions'], fn ($p) => str_starts_with($p, 'discipline.'))));
        $this->assertSame([], array_values(array_filter($roles['ogretmen']['permissions'], fn ($p) => str_starts_with($p, 'discipline.'))));
        $this->assertContains('teacher_portal.access', $roles['ogretmen']['permissions']);
    }

    private function route(string $method, string $uri): RoutingRoute
    {
        foreach (Route::getRoutes()->getRoutes() as $r) {
            if ($r->uri() === 'api/v1/'.$uri && in_array($method, $r->methods(), true)) {
                return $r;
            }
        }
        $this->fail("Rota yok: $method $uri");
    }

    /** @return list<string> */
    private function middleware(RoutingRoute $r): array
    {
        return array_values(array_filter(app('router')->gatherRouteMiddleware($r), 'is_string'));
    }

    private function perms(RoutingRoute $r): array
    {
        return array_values(array_map(fn ($m) => substr($m, strlen('Spatie\Permission\Middleware\PermissionMiddleware:')),
            array_filter($this->middleware($r), fn ($m) => str_starts_with($m, 'Spatie\Permission\Middleware\PermissionMiddleware:'))));
    }

    public function test_route_permissions(): void
    {
        $expect = [
            ['GET', 'discipline/incidents', ['discipline.view']],
            ['POST', 'discipline/incidents', ['discipline.view', 'discipline.create']],
            ['POST', 'discipline/incidents/{incident}/defenses', ['discipline.view', 'discipline.create']],
            ['POST', 'discipline/incidents/{incident}/sanctions', ['discipline.view', 'discipline.decide']],
            ['DELETE', 'discipline/incidents/{incident}', ['discipline.view', 'discipline.decide']],
            ['POST', 'discipline/board', ['discipline.view', 'discipline.board']],
            ['POST', 'discipline/board/{meeting}/items/{item}/decide', ['discipline.view', 'discipline.board']],
            ['PUT', 'discipline/settings', ['discipline.view', 'discipline.settings']],
            ['PUT', 'discipline/behaviors/{behavior}', ['discipline.view', 'discipline.settings']],
            ['POST', 'discipline/appeals/{appeal}/decide', ['discipline.view', 'discipline.decide|discipline.board']],
            ['GET', 'reports/discipline', ['reports.view', 'discipline.view']],
            ['GET', 'reports/discipline/export', ['reports.view', 'discipline.view', 'reports.export', 'discipline.export']],
            ['GET', 'reports/discipline/pdf', ['reports.view', 'discipline.view', 'reports.export', 'discipline.export']],
        ];
        foreach ($expect as [$m, $uri, $perms]) {
            $r = $this->route($m, $uri);
            $this->assertEqualsCanonicalizing($perms, $this->perms($r), "$m $uri");
            $this->assertContains(\App\Http\Middleware\EnsureStaffUser::class, $this->middleware($r), "$m $uri staff dışı kalmamalı");
        }
    }

    public function test_portal_routes_skip_staff_and_require_portal_scope(): void
    {
        foreach ([['GET', 'portal/discipline', \App\Http\Middleware\EnsurePortalStudent::class],
            ['POST', 'portal/discipline/defenses/{defense}', \App\Http\Middleware\EnsurePortalStudent::class],
            ['GET', 'teacher-portal/discipline/options', \App\Http\Middleware\EnsurePortalTeacher::class],
            ['POST', 'teacher-portal/discipline/incidents', \App\Http\Middleware\EnsurePortalTeacher::class]] as [$m, $uri, $portal]) {
            $mw = $this->middleware($this->route($m, $uri));
            $this->assertNotContains(\App\Http\Middleware\EnsureStaffUser::class, $mw, $uri);
            $this->assertContains($portal, $mw, $uri);
            $this->assertContains(\App\Http\Middleware\BlockWritesWhileImpersonating::class, $mw, $uri);
            $this->assertSame([], $this->perms($this->route($m, $uri)), "$uri personel yetkisi istememeli");
        }
    }
}
