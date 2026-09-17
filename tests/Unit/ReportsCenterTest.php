<?php

namespace Tests\Unit;

use App\Support\Permissions;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Rapor merkezi, takvim ve hedef takibi uçlarının yetki sınırları (DB'siz, rota tanımından).
 */
class ReportsCenterTest extends TestCase
{
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
    private function permissions(RoutingRoute $r): array
    {
        return array_values(array_map(fn ($m) => substr($m, 11), array_filter($r->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))));
    }

    public function test_every_report_route_requires_reports_view_and_module_permission(): void
    {
        $expect = [
            'reports/students' => 'students.view',
            'reports/attendance' => 'attendance.view',
            'reports/exams' => 'exams.view',
            'reports/leads' => 'crm.view',
            'reports/teacher-load' => 'teachers.view',
        ];
        foreach ($expect as $uri => $module) {
            $r = $this->route('GET', $uri);
            $perms = $this->permissions($r);
            $this->assertContains('reports.view', $perms, $uri);
            $this->assertContains($module, $perms, $uri);
            $this->assertContains('staff', $r->gatherMiddleware(), $uri);
            $this->assertNotContains('reports.export', $perms, "$uri görüntüleme dışa aktarma istememeli");
            // "a|b" (VEYA) biçimi kullanılmamalı: iki ayrı ara katman = VE
            foreach ($perms as $p) {
                $this->assertStringNotContainsString('|', $p, $uri);
            }
        }
    }

    public function test_every_report_export_requires_reports_export(): void
    {
        $exports = ['reports/students/export', 'reports/attendance/export', 'reports/attendance/pdf', 'reports/exams/export', 'reports/leads/export', 'reports/teacher-load/export'];
        foreach ($exports as $uri) {
            $perms = $this->permissions($this->route('GET', $uri));
            $this->assertContains('reports.view', $perms, $uri);
            $this->assertContains('reports.export', $perms, $uri);
            $this->assertGreaterThanOrEqual(3, count($perms), "$uri modül iznini de istemeli");
        }
    }

    public function test_all_report_routes_are_read_only(): void
    {
        $routes = array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/v1/reports'));
        $this->assertNotEmpty($routes);
        foreach ($routes as $r) {
            $this->assertSame(['GET', 'HEAD'], $r->methods(), $r->uri());
        }
    }

    public function test_default_roles_see_only_matching_reports(): void
    {
        $roles = Permissions::defaultRoles();
        $can = fn (string $role, array $perms) => collect($perms)->every(fn ($p) => in_array($p, $roles[$role]['permissions'], true));

        // Öğretmen rapor merkezini göremez
        $this->assertFalse($can('ogretmen', ['reports.view']));
        // Muhasebe: tahsilat evet, yoklama/sınav hayır
        $this->assertTrue($can('muhasebe', ['reports.view', 'reports.finance', 'reports.export']));
        $this->assertFalse($can('muhasebe', ['reports.view', 'attendance.view']));
        $this->assertFalse($can('muhasebe', ['reports.view', 'exams.view']));
        // Müdür: yoklama raporu + dışa aktarma
        $this->assertTrue($can('mudur', ['reports.view', 'attendance.view', 'reports.export']));
    }

    public function test_goal_tracking_options_do_not_need_risk_permission(): void
    {
        $perms = $this->permissions($this->route('GET', 'guidance/goals/options'));
        $this->assertSame(['guidance.view'], $perms);
        $this->assertContains('risk.view', $this->permissions($this->route('GET', 'risk/options')));
        // Rehber hedef seçeneklerini alabilir; öğretmen rolü artık yalnız öğretmen portalıdır (yönetim uçlarına girmez)
        $counselor = Permissions::defaultRoles()['rehber']['permissions'];
        $this->assertContains('guidance.view', $counselor);
        $teacher = Permissions::defaultRoles()['ogretmen']['permissions'];
        $this->assertNotContains('risk.view', $teacher);
        $this->assertNotContains('guidance.view', $teacher);
        $this->assertContains('teacher_portal.access', $teacher);
    }

    public function test_calendar_feed_routes(): void
    {
        $this->assertSame(['schedule.view'], $this->permissions($this->route('GET', 'calendar/feeds')));
        $this->assertSame(['schedule.view'], $this->permissions($this->route('POST', 'calendar/feeds')));
        $this->assertContains('schedule.manage', $this->permissions($this->route('POST', 'calendar/feeds/revoke')));
        $this->assertContains('schedule.manage', $this->permissions($this->route('PUT', 'holidays/{holiday}')));
        $this->assertSame(['schedule.view'], $this->permissions($this->route('GET', 'schedule/pdf')));
    }
}
