<?php

namespace App\Services\Academic;

use App\Exceptions\BusinessRuleException;
use App\Models\ActivityFeed;
use App\Models\ClassGroup;
use App\Models\Document;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Student;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HomeworkService
{
    public const DISK = 'local';

    public const DIR = 'homework';

    public const MAX_FILE_KB = 15360;

    public const ALLOWED_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'webp', 'txt', 'zip'];

    /** Teslim zamanına göre durum: son teslimden önce → teslim, sonra → geç. */
    public static function statusForSubmission(CarbonImmutable $dueAt, CarbonImmutable $submittedAt): string
    {
        return $submittedAt->lte($dueAt) ? 'submitted' : 'late';
    }

    /** Ödev oluştur + hedef öğrencilere teslim satırı aç. */
    public function create(array $data, User $user): Homework
    {
        $studentIds = $this->targetStudents($data);
        if ($studentIds === []) {
            throw new BusinessRuleException('Ödev verilecek öğrenci bulunamadı: sınıfta aktif öğrenci yok ya da öğrenci seçilmedi.', 'no_students');
        }
        $due = CarbonImmutable::parse($data['due_at']);
        if ($due->lte(now())) {
            throw new BusinessRuleException('Son teslim tarihi ileri bir zaman olmalı.', 'due_in_past');
        }

        return DB::transaction(function () use ($data, $user, $studentIds, $due) {
            $hw = Homework::query()->create([
                'teacher_id' => $data['teacher_id'], 'subject_id' => $data['subject_id'], 'class_group_id' => $data['class_group_id'] ?? null,
                'topic_id' => $data['topic_id'] ?? null, 'title' => $data['title'], 'description' => $data['description'] ?? null,
                'assigned_at' => now(), 'due_at' => $due,
            ]);
            $now = now();
            HomeworkSubmission::query()->insert(array_map(fn ($sid) => [
                'homework_id' => $hw->id, 'student_id' => $sid, 'status' => 'assigned', 'created_at' => $now, 'updated_at' => $now,
            ], $studentIds));

            $hw->load(['subject:id,name', 'classGroup:id,name']);
            ActivityFeed::query()->create([
                'kind' => 'homework', 'message' => sprintf('%s%s ödevi verildi: %s', $hw->classGroup ? $hw->classGroup->name.' sınıfına ' : '', $hw->subject->name, $hw->title),
                'subject_type' => 'homework', 'subject_id' => $hw->id, 'occurred_at' => now(),
            ]);
            Audit::log('homework.created', sprintf('"%s" ödevini %d öğrenciye verdi (son teslim %s).', $hw->title, count($studentIds), $due->format('d.m.Y H:i')), $hw);

            return $hw;
        });
    }

    public function update(Homework $hw, array $data): Homework
    {
        $hw->fill(array_intersect_key($data, array_flip(['subject_id', 'topic_id', 'title', 'description', 'due_at'])))->save();
        $changes = Audit::diff($hw);
        if ($changes['after'] !== []) {
            Audit::log('homework.updated', "\"{$hw->title}\" ödevini güncelledi.", $hw, $changes);
        }

        return $hw;
    }

    public function delete(Homework $hw): void
    {
        DB::transaction(function () use ($hw) {
            $subIds = $hw->submissions()->pluck('id')->all();
            foreach ($this->documents($hw)->get()->merge($subIds ? $this->submissionFiles($subIds)->get() : []) as $doc) {
                Storage::disk($doc->disk)->delete($doc->path);
                $doc->forceDelete();
            }
            $hw->delete();
            Audit::log('homework.deleted', "\"{$hw->title}\" ödevini sildi.", $hw);
        });
    }

    /** @param list<array{student_id:int, status?:string, score?:int|null, teacher_note?:string|null}> $rows */
    public function grade(Homework $hw, array $rows, ?User $by = null): int
    {
        $updated = 0;
        $by ??= auth()->user();
        DB::transaction(function () use ($hw, $rows, &$updated, $by) {
            $subs = $hw->submissions()->get()->keyBy('student_id');
            foreach ($rows as $row) {
                $sub = $subs[(int) $row['student_id']] ?? null;
                if (! $sub) {
                    continue;
                }
                $patch = [];
                if (! empty($row['status']) && array_key_exists($row['status'], HomeworkSubmission::STATUSES)) {
                    $patch['status'] = $row['status'];
                    if (in_array($row['status'], ['submitted', 'late'], true) && ! $sub->submitted_at) {
                        $patch['submitted_at'] = now();
                    }
                    if ($row['status'] === 'seen' && ! $sub->seen_at) {
                        $patch['seen_at'] = now();
                    }
                }
                if (array_key_exists('score', $row)) {
                    $patch['score'] = $row['score'] === null || $row['score'] === '' ? null : max(0, min(100, (int) $row['score']));
                }
                if (array_key_exists('teacher_note', $row)) {
                    $patch['teacher_note'] = $row['teacher_note'] ?: null;
                }
                if ($patch) {
                    // Puan ya da geri bildirim verildiyse değerlendirme anı (öğrenci teslimi artık kilitlenir)
                    $score = array_key_exists('score', $patch) ? $patch['score'] : $sub->score;
                    $note = array_key_exists('teacher_note', $patch) ? $patch['teacher_note'] : $sub->teacher_note;
                    $scored = $score !== null || $note !== null;
                    if (array_key_exists('score', $patch) || array_key_exists('teacher_note', $patch)) {
                        $patch['graded_at'] = $scored ? now() : null;
                        $patch['graded_by'] = $scored ? $by?->id : null;
                    }
                    $sub->forceFill($patch)->save();
                    $updated++;
                }
            }
        });
        Audit::log('homework.graded', "\"{$hw->title}\" ödevinde {$updated} öğrenciyi değerlendirdi.", $hw);

        return $updated;
    }

    /** Öğrenci teslimi (portal/ödev ekranından): durum teslim tarihine göre belirlenir. */
    public function submit(Homework $hw, int $studentId, ?string $answer = null): HomeworkSubmission
    {
        $sub = $hw->submissions()->where('student_id', $studentId)->firstOrFail();
        $patch = ['status' => self::statusForSubmission(CarbonImmutable::instance($hw->due_at), CarbonImmutable::now()), 'submitted_at' => now(), 'seen_at' => $sub->seen_at ?? now()];
        if ($answer !== null) {
            $patch['answer_text'] = trim($answer) !== '' ? trim($answer) : null;
        }
        $sub->forceFill($patch)->save();

        return $sub;
    }

    /** Değerlendirilmiş teslim (puan ya da geri bildirim) öğrenci tarafından değiştirilemez. */
    public static function isGraded(HomeworkSubmission $sub): bool
    {
        return $sub->score !== null || $sub->graded_at !== null;
    }

    /** Öğrencinin teslimine eklediği dosya (belge türü 'homework_submission'). */
    public function attachSubmissionFile(HomeworkSubmission $sub, UploadedFile $file, User $user): Document
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            throw new BusinessRuleException('Bu dosya türü desteklenmiyor. PDF, Office, görsel veya zip yükleyin.', 'unsupported_file');
        }
        $name = bin2hex(random_bytes(8)).'.'.$ext;
        $path = Storage::disk(self::DISK)->putFileAs(self::DIR.'/'.$sub->homework_id.'/submissions/'.$sub->id, $file, $name);
        if (! $path) {
            throw new BusinessRuleException('Dosya kaydedilemedi.', 'upload_failed', [], 500);
        }

        return Document::query()->create([
            'documentable_type' => 'homework_submission', 'documentable_id' => $sub->id, 'category' => 'homework_submission',
            'title' => mb_substr($file->getClientOriginalName(), 0, 190), 'disk' => self::DISK, 'path' => $path,
            'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'visibility' => 'private', 'uploaded_by' => $user->id,
        ]);
    }

    public function submissionFiles(int|array $submissionIds)
    {
        return Document::query()->where('documentable_type', 'homework_submission')->whereIn('documentable_id', (array) $submissionIds)->orderBy('id');
    }

    public function attach(Homework $hw, UploadedFile $file, User $user): Document
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            throw new BusinessRuleException('Bu dosya türü desteklenmiyor. PDF, Office, görsel veya zip yükleyin.', 'unsupported_file');
        }
        $name = bin2hex(random_bytes(8)).'.'.$ext;
        $path = Storage::disk(self::DISK)->putFileAs(self::DIR.'/'.$hw->id, $file, $name);
        if (! $path) {
            throw new BusinessRuleException('Dosya kaydedilemedi.', 'upload_failed', [], 500);
        }

        return Document::query()->create([
            'documentable_type' => 'homework', 'documentable_id' => $hw->id, 'category' => 'homework',
            'title' => mb_substr($file->getClientOriginalName(), 0, 190), 'disk' => self::DISK, 'path' => $path,
            'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'visibility' => 'private', 'uploaded_by' => $user->id,
        ]);
    }

    public function detach(Homework $hw, Document $doc): void
    {
        if ($doc->documentable_type !== 'homework' || (int) $doc->documentable_id !== $hw->id) {
            throw new BusinessRuleException('Belge bu ödeve ait değil.', 'document_mismatch', [], 404);
        }
        Storage::disk($doc->disk)->delete($doc->path);
        $doc->forceDelete();
    }

    public function documents(Homework $hw)
    {
        return Document::query()->where('documentable_type', 'homework')->where('documentable_id', $hw->id)->orderBy('id');
    }

    /** Son teslimi geçen ve hâlâ teslim edilmemiş ödevleri YAPILMADI yapar. */
    public function closeOverdue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return HomeworkSubmission::query()
            ->whereIn('status', ['assigned', 'seen'])
            ->whereIn('homework_id', Homework::query()->withoutGlobalScope('branch')->where('due_at', '<', $now)->select('id'))
            ->update(['status' => 'missed', 'updated_at' => $now]);
    }

    /** @return list<int> */
    private function targetStudents(array $data): array
    {
        if (! empty($data['student_ids'])) {
            return Student::query()->whereIn('id', $data['student_ids'])->pluck('id')->map(fn ($v) => (int) $v)->all();
        }
        if (! empty($data['class_group_id'])) {
            $group = ClassGroup::query()->findOrFail($data['class_group_id']);

            return $group->activeStudents()->whereIn('students.status', ['active', 'enrolled'])->pluck('students.id')->map(fn ($v) => (int) $v)->all();
        }

        return [];
    }
}
