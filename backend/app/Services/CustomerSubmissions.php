<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Exceptions\DomainError;
use App\Models\ApplicationSubmission;
use App\Models\CustomerProfile;
use App\Models\DocumentVersion;
use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CustomerSubmissions
{
    public function submit(int $actor, int $id, ?int $expected): ApplicationSubmission
    {
        return DB::transaction(function () use ($actor, $id, $expected) {
            $user = User::whereKey($actor)->lockForUpdate()->firstOrFail();
            if (! $user->is_active) {
                throw new DomainError('ACCOUNT_INACTIVE', 'Akun dinonaktifkan. Hubungi administrator.', 403);
            }
            abort_unless($user->role === Role::Customer, 403);
            $application = LoanApplication::whereKey($id)->where('customer_id', $actor)->lockForUpdate()->firstOrFail();
            if ($application->current_submission_id !== $expected) {
                throw new DomainError('SUBMISSION_VERSION_CONFLICT', 'Versi pengajuan telah berubah. Periksa data terbaru sebelum mengirim kembali.');
            }
            if (! $application->status->editable()) {
                throw new DomainError('INVALID_STATUS_TRANSITION', 'Pengajuan hanya dapat dikirim saat draf atau diminta revisi.');
            }
            $profile = CustomerProfile::where('user_id', $actor)->lockForUpdate()->first();
            $types = DB::table('document_types')->where('is_active', true)->orderBy('id')->sharedLock()->get();
            $documents = DocumentVersion::where('application_id', $id)->whereIn('document_type_id', $types->pluck('id'))
                ->orderByDesc('version_number')->orderBy('id')->lockForUpdate()->get()->unique('document_type_id')->keyBy('document_type_id');
            $snapshot = app(SubmissionData::class)->snapshot($user, $profile, $application, $types, $documents);
            $model = DB::table('model_versions')->where('is_active', true)->lockForUpdate()->first();
            if (! $model || ! $this->modelAllowed($model)) {
                throw new DomainError('SERVICE_NOT_READY', 'Pengajuan belum dapat dikirim. Hubungi pengelola lalu coba lagi nanti.', 503);
            }
            // The application row serializes version allocation; snapshots remain SELECT/INSERT-only.
            $latest = ApplicationSubmission::where('application_id', $id)->orderByDesc('version_number')->sharedLock()->first();
            $submission = new ApplicationSubmission($application->only(SubmissionData::FINANCE) + [
                'application_id' => $id, 'version_number' => ($latest?->version_number ?? 0) + 1,
                'customer_snapshot' => $snapshot, 'submitted_by' => $actor, 'submitted_at' => now(),
            ]);
            $submission->save();
            foreach ($documents as $document) {
                DB::table('submission_documents')->insert(['submission_id' => $submission->id, 'document_type_id' => $document->document_type_id,
                    'document_version_id' => $document->id, 'created_at' => now()]);
            }
            $features = [];
            foreach (['monthly_income', 'monthly_expenses', 'existing_installments', 'loan_amount', 'tenor_months'] as $field) {
                $features[$field] = $field === 'tenor_months' ? (int) $submission->$field : (float) $submission->$field;
            }
            $check = DB::table('anomaly_checks')->insertGetId([
                'submission_id' => $submission->id, 'model_version_id' => $model->id, 'attempt_number' => 1, 'status' => 'processing',
                'input_features' => json_encode($features, JSON_THROW_ON_ERROR), 'started_at' => now(), 'deadline_at' => now()->addSeconds(120), 'requested_by' => $actor,
            ]);
            DB::table('application_status_histories')->insert([
                'application_id' => $id, 'submission_id' => $submission->id, 'from_status' => $application->status->value,
                'to_status' => 'pending_verification', 'changed_by' => $actor, 'changed_at' => now(),
            ]);
            $application->current_submission_id = $submission->id;
            $application->status = ApplicationStatus::PendingVerification;
            $application->save();
            DB::table('activity_logs')->insert(['actor_id' => $actor, 'action' => 'application_submission.created', 'target_type' => 'application_submission',
                'target_id' => $submission->id, 'metadata' => '{}', 'created_at' => now()]);
            // Register the callback explicitly: enqueue errors must not turn an already committed submission into an HTTP failure.
            app(PredictionDispatch::class)->afterCommit($check);

            // Keep the accepted response independent of a second document query after commit.
            return $submission->setRelation('documents', $documents->values());
        });
    }

    public function modelAllowed(object $model): bool
    {
        $stub = str_starts_with($model->version_code, 'stub-');
        $parameters = json_decode($model->parameters, true);

        return $model->score_method === 'decision_function' && (float) $model->decision_threshold === 0.0
            && ($parameters['feature_schema'] ?? null) === 'loan-finance-v1'
            && (config('services.ml.mode') === 'stub'
                ? app()->environment('local', 'testing') && $stub && $model->version_code === 'stub-v0'
                : config('services.ml.mode') === 'model' && ! $stub);
    }
}
