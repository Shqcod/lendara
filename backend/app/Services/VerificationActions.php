<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\DomainError;
use App\Models\ApplicationSubmission;
use App\Models\Assignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VerificationActions
{
    public function store(int $actor, int $submissionId, array $data): array
    {
        $context = ApplicationSubmission::findOrFail($submissionId);

        return DB::transaction(function () use ($actor, $submissionId, $data, $context) {
            $user = User::whereKey($actor)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && $user->role === Role::Verifier, 403);
            $application = app(ReviewScope::class)->applications($user)->whereKey($context->application_id)->lockForUpdate()->firstOrFail();
            if ($application->current_submission_id !== $submissionId) {
                throw new DomainError('SUBMISSION_VERSION_CONFLICT', 'Versi pengajuan berubah. Periksa data terbaru.');
            }
            $assignment = Assignment::where('application_id', $application->id)->whereNull('ended_at')->lockForUpdate()->first();
            abort_unless($assignment && $assignment->assigned_to === $actor, 403);
            if ($assignment->submission_id !== $submissionId || $assignment->stage !== 'verification') {
                throw new DomainError('ASSIGNMENT_NOT_ACTIVE', 'Tugas tidak aktif pada versi dan tahap ini.');
            }
            $documents = DB::table('submission_documents')->where('submission_id', $submissionId)->pluck('document_type_id')->all();
            foreach ($data['document_checklist'] as $type => $checked) {
                if (! in_array((string) $type, array_map('strval', $documents), true) || ! is_bool($checked)) {
                    throw ValidationException::withMessages(['document_checklist' => 'Checklist hanya untuk jenis dokumen pada snapshot ini.']);
                }
            }
            $check = DB::table('anomaly_checks')->where('submission_id', $submissionId)->where('status', 'succeeded')->lockForUpdate()->first();
            $type = $data['result_type'];
            $notes = isset($data['notes']) ? trim($data['notes']) : null;
            if ($type === 'request_revision' && ($notes === null || $notes === '')) {
                throw ValidationException::withMessages(['notes' => 'Alasan revisi wajib diisi.']);
            }
            if ($type === 'forward') {
                if (! $check) {
                    throw new DomainError('ANOMALY_CHECK_NOT_READY', 'Pemeriksaan anomali harus berhasil sebelum diteruskan.');
                }
                if ($check->is_anomaly && ($notes === null || $notes === '')) {
                    throw new DomainError('ANOMALY_REVIEW_NOTE_REQUIRED', 'Catatan peninjauan anomali wajib diisi.', 422, ['notes' => ['Jelaskan hasil peninjauan anomali.']]);
                }
            }
            $created = now();
            $id = DB::table('verification_results')->insertGetId([
                'submission_id' => $submissionId, 'assignment_id' => $assignment->id, 'verifier_id' => $actor,
                'result_type' => $type, 'notes' => $notes, 'document_checklist' => json_encode((object) $data['document_checklist']),
                'anomaly_check_id' => $check?->id, 'created_at' => $created,
            ]);
            if ($type !== 'note') {
                $next = $type === 'forward' ? 'pending_approval_1' : 'revision_required';
                DB::table('application_status_histories')->insert([
                    'application_id' => $application->id, 'submission_id' => $submissionId,
                    'from_status' => 'pending_verification', 'to_status' => $next,
                    'changed_by' => $actor, 'notes' => $notes, 'changed_at' => $created,
                ]);
                $application->status = $next;
                $application->save();
                $assignment->ended_at = $created;
                $assignment->end_type = 'completed';
                $assignment->ended_by = $actor;
                $assignment->save();
            }
            DB::table('activity_logs')->insert([
                'actor_id' => $actor, 'action' => 'verification.'.$type, 'target_type' => 'verification_result', 'target_id' => $id,
                'metadata' => json_encode(['application_id' => $application->id, 'submission_id' => $submissionId, 'assignment_id' => $assignment->id]),
                'created_at' => $created,
            ]);

            return ['id' => $id, 'submission_id' => $submissionId, 'result_type' => $type, 'application_status' => $application->status->value];
        });
    }
}
