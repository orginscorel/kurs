<?php

namespace Tests\Unit;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * Zamanlanmış çekme YALNIZ yerel düğümde (Mac masaüstü) çalışır.
 * Web sunucusu kurumun yerel ağına ulaşamaz; orada zamanlamak boşuna hata üretirdi.
 */
class ZkScheduleTest extends TestCase
{
    /** @return list<string> */
    private function scheduled(string $node): array
    {
        config(['kurs.node' => $node]);

        $schedule = new Schedule();
        $this->app->instance(Schedule::class, $schedule);
        Facade::clearResolvedInstance(Schedule::class);

        // routes/console.php ile aynı süzgeç (config/kurs.php › local_schedule_files)
        $localNode = $node === 'local';
        foreach (glob(base_path('routes/schedules/*.php')) as $file) {
            if ($localNode && ! in_array(basename($file), config('kurs.local_schedule_files', []), true)) {
                continue;
            }
            require $file;
        }

        return array_map(fn (Event $e) => (string) $e->command, $schedule->events());
    }

    public function test_device_pull_is_scheduled_only_on_the_local_node(): void
    {
        $local = $this->scheduled('local');
        $server = $this->scheduled('server');

        $this->assertTrue(
            (bool) array_filter($local, fn ($c) => str_contains($c, 'kurs:cihaz-cek')),
            'Yerel düğümde kurs:cihaz-cek zamanlanmalı',
        );
        $this->assertSame([], array_values(array_filter($server, fn ($c) => str_contains($c, 'kurs:cihaz-cek'))),
            'Sunucuda kurs:cihaz-cek zamanlanmamalı (cihaza ulaşamaz)');
    }

    public function test_devices_schedule_file_is_allowed_on_the_local_node(): void
    {
        $this->assertContains('devices.php', config('kurs.local_schedule_files'));
        $this->assertFileExists(base_path('routes/schedules/devices.php'));
    }

    public function test_pull_command_is_offline_safe_when_no_device_is_configured(): void
    {
        $this->artisan('migrate', ['--force' => true])->run();

        $this->artisan('kurs:cihaz-cek')
            ->expectsOutputToContain('ZK protokolüyle yapılandırılmış etkin cihaz yok')
            ->assertExitCode(0);
    }
}
