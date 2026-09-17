<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Api\ApiController;
use App\Models\Holiday;
use App\Services\Academic\HolidayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HolidayController extends ApiController
{
    public function __construct(private readonly HolidayService $service) {}

    public function index(Request $request): JsonResponse
    {
        $rows = Holiday::query()
            ->when($request->date('from'), fn ($q, $d) => $q->where('ends_on', '>=', $d->toDateString()))
            ->when($request->date('to'), fn ($q, $d) => $q->where('starts_on', '<=', $d->toDateString()))
            ->orderBy('starts_on')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (Holiday $h) => [
            'id' => $h->id, 'name' => $h->name, 'kind' => $h->kind, 'kind_label' => Holiday::KINDS[$h->kind] ?? $h->kind,
            'starts_on' => $h->starts_on->toDateString(), 'ends_on' => $h->ends_on->toDateString(), 'days' => $h->starts_on->diffInDays($h->ends_on) + 1,
            'cancel_sessions' => $h->cancel_sessions, 'cancelled_count' => $h->cancelled_count, 'notes' => $h->notes,
        ]), 'kinds' => Holiday::KINDS]);
    }

    public function store(Request $request): JsonResponse
    {
        $h = $this->service->create($this->validated($request), $request->user()?->id);

        return response()->json(['message' => ! $h->cancel_sessions ? 'Tatil eklendi; dersler etkilenmedi.' : ($h->cancelled_count > 0 ? "Tatil eklendi; {$h->cancelled_count} ders iptal edildi ve öğrencilere bildirildi." : 'Tatil eklendi; bu tarihlerde iptal edilecek ders yoktu.'), 'id' => $h->id], 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $h = $this->service->update($holiday, $this->validated($request));

        return $this->ok("Tatil güncellendi; {$h->cancelled_count} ders iptal durumunda.");
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $released = $this->service->delete($holiday);

        return $this->ok("Tatil silindi; gelecekteki {$released} dersin iptali geri alındı.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'kind' => ['required', Rule::in(array_keys(Holiday::KINDS))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'cancel_sessions' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['ends_on.after_or_equal' => 'Bitiş tarihi başlangıçtan önce olamaz.'], [
            'name' => 'Tatil adı', 'kind' => 'Tatil türü', 'starts_on' => 'Başlangıç tarihi', 'ends_on' => 'Bitiş tarihi', 'cancel_sessions' => 'Dersleri iptal et', 'notes' => 'Not',
        ]);
    }
}
