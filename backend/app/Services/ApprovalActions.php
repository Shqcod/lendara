<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\DomainError;
use App\Models\ApplicationSubmission;
use App\Models\Assignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalActions
{
    public function store(int $actor, int $id, array $data): array
    {
        $snapshot = ApplicationSubmission::findOrFail($id);

        return DB::transaction(function () use ($actor, $id, $data, $snapshot) {
            $user = User::whereKey($actor)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && in_array($user->role, [Role::ApproverOne, Role::ApproverTwo], true), 403);
            $scope = app(ReviewScope::class);
            $application = $scope->applications($user)->whereKey($snapshot->application_id)->lockForUpdate()->firstOrFail();
            if ($application->current_submission_id !== $id) {
                throw new DomainError('SUBMISSION_VERSION_CONFLICT', 'Versi pengajuan berubah. Periksa data terbaru.');
            }
            $assignment = Assignment::where('application_id', $application->id)->whereNull('ended_at')->lockForUpdate()->first();
            abort_unless($assignment && $assignment->assigned_to === $actor, 403);
            if ($assignment->submission_id !== $id || $assignment->stage !== $scope->context($user)['stage']) {
                throw new DomainError('ASSIGNMENT_NOT_ACTIVE', 'Tugas tidak aktif pada versi dan tahap ini.');
            }
            $level = $user->role === Role::ApproverOne ? 1 : 2;
            // The application row already serializes decisions; history is never updated.
            $previous = DB::table('approval_decisions')->where('submission_id', $id)->orderBy('level')->sharedLock()->get();
            if ($previous->contains('level', $level) || ($level === 2 && ! $previous->contains(fn ($d) => $d->level === 1 && $d->decision === 'approved'))) {
                throw new DomainError('INVALID_STATUS_TRANSITION', 'Urutan persetujuan tidak valid atau keputusan sudah dibuat.');
            }
            $check = DB::table('anomaly_checks')->where('submission_id', $id)->where('status', 'succeeded')->lockForUpdate()->first();
            if (! $check) {
                throw new DomainError('ANOMALY_CHECK_NOT_READY', 'Pemeriksaan anomali harus berhasil sebelum keputusan dibuat.');
            }
            $notes = isset($data['notes']) ? trim($data['notes']) : null;
            if ($data['decision'] === 'rejected' && ($notes === null || $notes === '')) {
                throw ValidationException::withMessages(['notes' => 'Alasan penolakan wajib diisi.']);
            }
            $next = $data['decision'] === 'rejected' ? 'rejected' : ($level === 1 ? 'pending_approval_2' : 'approved');
            $at = now();
            $decision = DB::table('approval_decisions')->insertGetId(['submission_id' => $id, 'assignment_id' => $assignment->id,
                'approver_id' => $actor, 'level' => $level, 'decision' => $data['decision'], 'notes' => $notes, 'anomaly_check_id' => $check->id, 'decided_at' => $at]);
            DB::table('application_status_histories')->insert(['application_id' => $application->id, 'submission_id' => $id,
                'from_status' => $application->status->value, 'to_status' => $next, 'changed_by' => $actor, 'notes' => $notes, 'changed_at' => $at]);
            $application->status = $next;
            $application->save();
            $assignment->ended_at = $at;
            $assignment->end_type = 'completed';
            $assignment->ended_by = $actor;
            $assignment->save();
            DB::table('activity_logs')->insert(['actor_id' => $actor, 'action' => 'approval.'.$data['decision'], 'target_type' => 'approval_decision', 'target_id' => $decision,
                'metadata' => json_encode(['application_id' => $application->id, 'submission_id' => $id, 'level' => $level]), 'created_at' => $at]);

            return ['id' => $decision, 'submission_id' => $id, 'level' => $level, 'decision' => $data['decision'], 'application_status' => $next];
        });
    }
}
