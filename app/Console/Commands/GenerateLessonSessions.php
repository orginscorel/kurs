<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Academic\SessionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateLessonSessions extends Command
{
    protected $signature = 'kurs:generate-sessions {--days=21 : Bugünden itibaren kaç gün}';

    protected $description = 'Haftalık ders programından somut ders oturumlarını üretir (var olanlara dokunmaz)';

    public function handle(SessionGenerator $generator): int
    {
        $from = CarbonImmutable::today();
        $to = $from->addDays((int) $this->option('days'));

        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $count = $generator->generate($branch->id, $from, $to);
            $this->info("{$branch->name}: {$count} yeni ders oturumu.");
        }

        return self::SUCCESS;
    }
}
