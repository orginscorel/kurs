<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Controller;
use App\Models\CalendarFeed;
use App\Models\ClassGroup;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Academic\CalendarService;
use App\Services\Academic\ICalBuilder;
use App\Support\BranchContext;
use Illuminate\Http\Response;

/**
 * OTURUMSUZ iCal akışı. Kimlik = URL'deki 48 karakterlik rastgele jeton (yalnız SHA-256 özeti saklanır).
 * Bilinmeyen / iptal edilmiş jeton için ayrım yapmadan 404.
 */
class ICalFeedController extends Controller
{
    public function __invoke(string $token, CalendarService $calendar): Response
    {
        $token = preg_replace('/\.ics$/', '', $token);
        $feed = strlen($token) >= 32 ? CalendarFeed::query()->withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first() : null;
        abort_if(! $feed, 404);

        return app(BranchContext::class)->run((int) $feed->branch_id, function () use ($feed, $calendar) {
            $name = match ($feed->owner_type) {
                'teacher' => Teacher::query()->find($feed->owner_id)?->full_name,
                'student' => Student::query()->find($feed->owner_id)?->full_name,
                'class_group' => ClassGroup::query()->find($feed->owner_id)?->name,
                default => Classroom::query()->find($feed->owner_id)?->name,
            };
            abort_if($name === null, 404);

            CalendarFeed::query()->withoutGlobalScopes()->whereKey($feed->id)->update(['last_accessed_at' => now(), 'access_count' => $feed->access_count + 1]);
            $body = ICalBuilder::build("Ders programı · $name", $calendar->feedEvents($feed->owner_type, (int) $feed->owner_id), request()->getHost());

            return response($body, 200, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'inline; filename="ders-programi.ics"',
                'Cache-Control' => 'private, max-age=900',
                'X-Robots-Tag' => 'noindex',
            ]);
        });
    }
}
