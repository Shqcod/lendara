<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\DomainError;
use App\Models\ApplicationSubmission;
use App\Models\Assignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PredictionRetries
{
    public function retry(int $actor, int $id): array
    {
        $snapshot = ApplicationSubmission::findOrFail($id);

        return DB::transaction(function () use ($actor, $id, $snapshot) {
            $user = User::whereKey($actor)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && $user->role === Role::Verifier, 403);
            $application = app(ReviewScope::class)->applications($user)->whereKey($snapshot->application_id)->lockForUpdate()->firstOrFail();
            if ($application->current_submission_id !== $id) {
                throw new DomainError('SUBMISSION_VERSION_CONFLICT', 'Versi pengajuan berubah. Periksa data terbaru.');
            }
            $assignment = Assignment::where('application_id', $application->id)->whereNull('ended_at')->lockForUpdate()->first();
            abort_unless($assignment && $assignment->assigned_to === $actor, 403);
            if ($assignment->submission_id !== $id || $assignment->stage !== 'verification') {
                throw new DomainError('ASSIGNMENT_NOT_ACTIVE', 'Tugas tidak aktif pada versi dan tahap ini.');
            }
            $checks = DB::table('anomaly_checks')->where('submission_id', $id)->orderByDesc('attempt_number')->lockForUpdate()->get();
            $latest = $checks->first();
            if (! $latest || $latest->status !== 'failed' || $checks->contains(fn ($c) => $c->status !== 'failed')) {
                throw new DomainError('ANOMALY_RETRY_NOT_ALLOWED', 'Pemeriksaan hanya dapat diulang setelah gagal dan belum memiliki hasil berhasil.');
            }
            $model = DB::table('model_versions')->where('id', $latest->model_version_id)->first();
            if (! $model || ! app(CustomerSubmissions::class)->modelAllowed($model)) {
                throw new DomainError('SERVICE_NOT_READY', 'Versi model pemeriksaan belum tersedia pada mode ini.', 503);
            }
            $start = now();
            $check = DB::table('anomaly_checks')->insertGetId([
                'submission_id' => $id, 'model_version_id' => $latest->model_version_id, 'attempt_number' => $latest->attempt_number + 1,
                'status' => 'processing', 'input_features' => $latest->input_features,
                'started_at' => $start, 'deadline_at' => $start->copy()->addSeconds(120), 'requested_by' => $actor,
            ]);
            DB::table('activity_logs')->insert(['actor_id' => $actor, 'action' => 'anomaly.retry_requested', 'target_type' => 'anomaly_check',
                'target_id' => $check, 'metadata' => json_encode(['submission_id' => $id, 'previous_check_id' => $latest->id, 'assignment_id' => $assignment->id]), 'created_at' => $start]);
            app(PredictionDispatch::class)->afterCommit($check);

            return ['id' => $check, 'submission_id' => $id, 'attempt_number' => $latest->attempt_number + 1, 'status' => 'processing'];
        });
    }
}
