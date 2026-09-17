<?php

namespace App\Services\Discipline;

use App\Exceptions\BusinessRuleException;
use App\Models\DisciplineBoardItem;
use App\Models\DisciplineBoardMeeting;
use App\Models\DisciplineIncident;
use App\Models\DisciplineSanction;
use App\Models\User;
use App\Support\Audit;
use App\Support\BranchContext;
use App\Support\Discipline\DisciplineCatalog as C;
use App\Support\Sequence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Disiplin kurulu: toplantı, üyeler (hazır bulunanlar), gündem maddeleri, oylama ve karar.
 * Gündeme alınan olayın "kurul kararı bekleyen" önerileri madde olur; kabul edilen öneri yürürlüğe girer.
 */
class DisciplineBoardService
{
    public function __construct(private readonly DisciplineService $discipline) {}

    /** @param array{title:string, scheduled_at:string, location?:?string, members?:array, notes?:?string, incident_ids?:list<int>} $data */
    public function create(array $data, User $user): DisciplineBoardMeeting
    {
        $branchId = app(BranchContext::class)->require();

        return DB::transaction(function () use ($data, $user, $branchId) {
            $meeting = DisciplineBoardMeeting::query()->create([
                'branch_id' => $branchId,
                'meeting_no' => Sequence::next('discipline_board', 'DKR', $branchId),
                'title' => $data['title'],
                'scheduled_at' => CarbonImmutable::parse($data['scheduled_at']),
                'location' => $data['location'] ?? null,
                'status' => 'planned',
                'members' => $this->members($data['members'] ?? []),
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);
            foreach ($data['incident_ids'] ?? [] as $incidentId) {
                $this->addIncident($meeting, (int) $incidentId, $user);
            }
            Audit::log('discipline.board_created', "{$meeting->meeting_no} disiplin kurulu toplantısını planladı ({$meeting->scheduled_at->format('d.m.Y H:i')}).", $meeting);

            return $meeting;
        });
    }

    public function update(DisciplineBoardMeeting $meeting, array $data, User $user): DisciplineBoardMeeting
    {
        if ($meeting->status === 'cancelled') {
            throw new BusinessRuleException('İptal edilmiş toplantı düzenlenemez.', 'discipline_board_cancelled', [], 422);
        }
        $fill = array_intersect_key($data, array_flip(['title', 'location', 'notes']));
        if (isset($data['scheduled_at'])) {
            $fill['scheduled_at'] = CarbonImmutable::parse($data['scheduled_at']);
        }
        if (array_key_exists('members', $data)) {
            $fill['members'] = $this->members($data['members'] ?? []);
        }
        $meeting->fill($fill)->save();
        Audit::log('discipline.board_updated', "{$meeting->meeting_no} kurul toplantısını güncelledi.", $meeting);

        return $meeting;
    }

    /** @return list<array{user_id:int, name:string, role:string, present:bool}> */
    private function members(array $rows): array
    {
        $ids = collect($rows)->pluck('user_id')->filter()->map(fn ($v) => (int) $v)->unique()->values();
        $users = User::query()->whereIn('id', $ids)->where('is_active', true)->whereIn('user_type', [User::TYPE_STAFF, User::TYPE_TEACHER])->pluck('name', 'id');
        $out = [];
        foreach ($rows as $r) {
            $id = (int) ($r['user_id'] ?? 0);
            if (! isset($users[$id]) || isset($out[$id])) {
                continue;
            }
            $role = $r['role'] ?? 'member';
            $out[$id] = ['user_id' => $id, 'name' => (string) $users[$id], 'role' => array_key_exists($role, C::BOARD_ROLES) ? $role : 'member', 'present' => (bool) ($r['present'] ?? true)];
        }
        if (count(array_filter($out, fn ($m) => $m['role'] === 'chair')) > 1) {
            throw new BusinessRuleException('Kurulun tek başkanı olabilir.', 'discipline_board_chair', [], 422);
        }

        return array_values($out);
    }

    public function addIncident(DisciplineBoardMeeting $meeting, int $incidentId, User $user): int
    {
        if ($meeting->status !== 'planned' && $meeting->status !== 'held') {
            throw new BusinessRuleException('İptal edilmiş toplantıya gündem eklenemez.', 'discipline_board_cancelled', [], 422);
        }
        $incident = DisciplineIncident::query()->findOrFail($incidentId);
        if ($incident->kind !== 'negative' || $incident->status === 'closed') {
            throw new BusinessRuleException("{$incident->incident_no} kurul gündemine alınamaz (kapalı ya da olumlu kayıt).", 'discipline_board_incident_state', [], 422);
        }
        $pos = (int) DisciplineBoardItem::query()->where('meeting_id', $meeting->id)->max('position');
        $already = DisciplineBoardItem::query()->where('incident_id', $incident->id)->where('result', 'pending')->pluck('sanction_id')->filter()->all();
        $proposed = DisciplineSanction::query()->where('incident_id', $incident->id)->where('status', 'proposed')->whereNotIn('id', $already)->get();
        $added = 0;
        foreach ($proposed as $s) {
            DisciplineBoardItem::query()->create(['meeting_id' => $meeting->id, 'incident_id' => $incident->id, 'student_id' => $s->student_id, 'sanction_id' => $s->id, 'position' => ++$pos]);
            $s->forceFill(['board_meeting_id' => $meeting->id])->save();
            $added++;
        }
        if ($added === 0) {
            if (DisciplineBoardItem::query()->where('meeting_id', $meeting->id)->where('incident_id', $incident->id)->exists()) {
                throw new BusinessRuleException("{$incident->incident_no} zaten bu toplantının gündeminde.", 'discipline_board_duplicate', [], 422);
            }
            // Öneri yoksa olay genel madde olarak görüşülür (kurul yaptırımı kendisi belirleyebilir)
            DisciplineBoardItem::query()->create(['meeting_id' => $meeting->id, 'incident_id' => $incident->id, 'position' => ++$pos]);
            $added = 1;
        }
        if ($incident->status === 'open') {
            $incident->forceFill(['status' => 'review'])->save();
        }
        $this->discipline->event($incident, $user, 'board_agenda', "{$meeting->meeting_no} disiplin kurulu gündemine alındı ({$meeting->scheduled_at->format('d.m.Y H:i')}).", ['meeting_id' => $meeting->id]);

        return $added;
    }

    public function removeItem(DisciplineBoardItem $item, User $user): void
    {
        if ($item->result !== 'pending') {
            throw new BusinessRuleException('Karara bağlanmış madde gündemden çıkarılamaz.', 'discipline_board_item_done', [], 422);
        }
        if ($item->sanction_id) {
            DisciplineSanction::query()->whereKey($item->sanction_id)->where('board_meeting_id', $item->meeting_id)->update(['board_meeting_id' => null]);
        }
        $this->discipline->event($item->incident, $user, 'board_agenda', "{$item->meeting->meeting_no} gündeminden çıkarıldı.");
        $item->delete();
    }

    /**
     * @param array{result:string, votes_for:int, votes_against:int, votes_abstain?:int, decision?:?string, sanction_type_id?:?int,
     *   student_id?:?int, starts_on?:?string, days?:?int, duty_description?:?string} $data
     */
    public function decideItem(DisciplineBoardItem $item, array $data, User $user): DisciplineBoardItem
    {
        $meeting = $item->meeting;
        if ($meeting->status === 'cancelled') {
            throw new BusinessRuleException('İptal edilmiş toplantıda karar verilemez.', 'discipline_board_cancelled', [], 422);
        }
        if ($item->result !== 'pending' && $item->result !== 'postponed') {
            throw new BusinessRuleException('Bu madde zaten karara bağlandı.', 'discipline_board_item_done', [], 422);
        }
        if ($meeting->scheduled_at->gt(now()->addHours(12))) {
            throw new BusinessRuleException('Toplantı zamanı gelmeden karar girilemez.', 'discipline_board_not_yet', [], 422);
        }
        $present = count(array_filter($meeting->members ?? [], fn ($m) => ! empty($m['present'])));
        $for = (int) $data['votes_for'];
        $against = (int) $data['votes_against'];
        $abstain = (int) ($data['votes_abstain'] ?? 0);
        DisciplineRules::assertVotes($data['result'], $for, $against, $abstain, $present);

        return DB::transaction(function () use ($item, $data, $user, $meeting, $for, $against, $abstain) {
            $result = $data['result'];
            $sanction = $item->sanction;

            if ($result === 'accepted') {
                if ($sanction) {
                    $sanction = $this->discipline->activateProposed($sanction, $user, $meeting->id, $data['sanction_type_id'] ?? null, $data);
                } elseif (! empty($data['sanction_type_id']) && ! empty($data['student_id'])) {
                    // Genel madde: kurul yaptırımı doğrudan belirler → öneri + hemen yürürlük
                    if (! $item->incident->participants()->where('student_id', (int) $data['student_id'])->where('role', 'involved')->exists()) {
                        throw new BusinessRuleException('Seçilen öğrenci bu olaya karışanlar arasında değil.', 'discipline_not_involved', [], 422);
                    }
                    $proposed = DisciplineSanction::query()->create([
                        'branch_id' => $item->incident->branch_id,
                        'sanction_no' => Sequence::next('discipline_sanction', 'YPT', (int) $item->incident->branch_id),
                        'incident_id' => $item->incident_id, 'student_id' => (int) $data['student_id'],
                        'sanction_type_id' => (int) $data['sanction_type_id'], 'status' => 'proposed', 'board_meeting_id' => $meeting->id,
                        'decision_note' => $data['decision'] ?? null, 'visible_to_portal' => true,
                    ]);
                    $sanction = $this->discipline->activateProposed($proposed, $user, $meeting->id, null, $data);
                    $item->student_id = $proposed->student_id;
                }
                if ($sanction && ! empty($data['decision'])) {
                    $sanction->forceFill(['decision_note' => $data['decision']])->save();
                }
            } elseif ($result === 'rejected' && $sanction && $sanction->status === 'proposed') {
                $this->discipline->changeSanctionStatus($sanction, 'cancelled', $user, 'Disiplin kurulu öneriyi kabul etmedi');
            }

            $item->fill([
                'result' => $result, 'votes_for' => $for, 'votes_against' => $against, 'votes_abstain' => $abstain,
                'decision' => $data['decision'] ?? null, 'sanction_id' => $sanction?->id,
            ])->save();

            if ($meeting->status === 'planned') {
                $meeting->forceFill(['status' => 'held', 'held_at' => now()])->save();
            }
            $labels = ['accepted' => 'kabul edildi', 'rejected' => 'reddedildi', 'postponed' => 'ertelendi'];
            $this->discipline->event($item->incident, $user, 'board_decision',
                "{$meeting->meeting_no} kurul kararı: {$labels[$result]} ({$for} kabul, {$against} ret, {$abstain} çekimser)".(! empty($data['decision']) ? ' — '.rtrim($data['decision'], '. ') : '').'.',
                ['meeting_id' => $meeting->id, 'item_id' => $item->id]);
            Audit::log('discipline.board_decision', "{$meeting->meeting_no} toplantısında {$item->incident->incident_no} maddesini karara bağladı: {$labels[$result]}.", $meeting);

            return $item;
        });
    }

    public function setStatus(DisciplineBoardMeeting $meeting, string $status, User $user, ?array $members = null): DisciplineBoardMeeting
    {
        if ($status === 'cancelled') {
            if ($meeting->items()->whereIn('result', ['accepted', 'rejected'])->exists()) {
                throw new BusinessRuleException('Karar alınmış toplantı iptal edilemez.', 'discipline_board_has_decisions', [], 422);
            }
            foreach ($meeting->items()->whereNotNull('sanction_id')->get() as $item) {
                DisciplineSanction::query()->whereKey($item->sanction_id)->update(['board_meeting_id' => null]);
            }
            $meeting->items()->delete();
        }
        $meeting->forceFill([
            'status' => $status,
            'held_at' => $status === 'held' ? ($meeting->held_at ?? now()) : $meeting->held_at,
            'members' => $members !== null ? $this->members($members) : $meeting->members,
        ])->save();
        Audit::log('discipline.board_status', "{$meeting->meeting_no} kurul toplantısının durumunu \"".C::BOARD_STATUSES[$status].'" yaptı.', $meeting);

        return $meeting;
    }
}
