<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait DomainFixtures
{
    protected function loan(?int $customer = null, array $overrides = []): int
    {
        return DB::table('loan_applications')->insertGetId(array_replace([
            'application_number' => 'TEST-'.Str::ulid(),
            'customer_id' => $customer ?? User::factory()->create()->id,
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    protected function submission(int $application, int $version = 1): int
    {
        $owner = DB::table('loan_applications')->where('id', $application)->value('customer_id');

        return DB::table('application_submissions')->insertGetId([
            'application_id' => $application, 'version_number' => $version,
            'customer_snapshot' => json_encode(['name' => 'Identitas Snapshot Fiktif', 'identity_number' => '0000000000000000']),
            'loan_amount' => '20000000.00', 'tenor_months' => 12, 'purpose' => 'Keperluan demonstrasi',
            'monthly_income' => '8000000.00', 'monthly_expenses' => '3000000.00', 'existing_installments' => '1000000.00',
            'submitted_by' => $owner, 'submitted_at' => now(),
        ]);
    }

    protected function modelVersion(array $overrides = []): int
    {
        return DB::table('model_versions')->insertGetId(array_replace([
            'version_code' => 'stub-test-'.Str::ulid(), 'algorithm' => 'stub',
            'feature_names' => json_encode(['monthly_income', 'monthly_expenses', 'existing_installments', 'loan_amount', 'tenor_months']),
            'parameters' => json_encode(['source' => 'stub', 'feature_schema' => 'loan-finance-v1']),
            'contamination' => '0.10000', 'decision_threshold' => 0, 'score_method' => 'decision_function',
            'artifact_path' => 'test-fixture://stub-code', 'artifact_hash' => str_repeat('0', 64),
            'training_data_version' => 'test-fixture-only', 'is_active' => false, 'created_at' => now(),
        ], $overrides));
    }

    protected function check(int $submission, string $status = 'succeeded', int $attempt = 1, array $overrides = []): int
    {
        return DB::table('anomaly_checks')->insertGetId(array_replace([
            'submission_id' => $submission, 'model_version_id' => $this->modelVersion(), 'attempt_number' => $attempt,
            'status' => $status, 'input_features' => json_encode(['monthly_income' => 8000000, 'monthly_expenses' => 3000000, 'existing_installments' => 1000000, 'loan_amount' => 20000000, 'tenor_months' => 12]),
            'score' => $status === 'succeeded' ? 0.08 : null,
            'is_anomaly' => $status === 'succeeded' ? false : null,
            'started_at' => now(), 'deadline_at' => now()->addSeconds(120),
            'finished_at' => $status === 'processing' ? null : now(),
            'failure_reason' => $status === 'failed' ? 'fixture_failure' : null,
        ], $overrides));
    }

    protected function assignment(int $application, int $submission, array $overrides = []): int
    {
        return DB::table('assignments')->insertGetId(array_replace([
            'application_id' => $application, 'submission_id' => $submission,
            'assigned_to' => User::factory()->create(['role' => 'verifikator'])->id,
            'stage' => 'verification', 'claimed_at' => now(),
        ], $overrides));
    }

    protected function verification(int $submission, int $assignment, ?int $check = null, array $overrides = []): int
    {
        return DB::table('verification_results')->insertGetId(array_replace([
            'submission_id' => $submission, 'assignment_id' => $assignment,
            'verifier_id' => DB::table('assignments')->where('id', $assignment)->value('assigned_to'),
            'result_type' => 'forward', 'notes' => 'Catatan internal fiktif',
            'document_checklist' => '{}', 'anomaly_check_id' => $check, 'created_at' => now(),
        ], $overrides));
    }

    protected function decision(int $submission, int $assignment, int $check, array $overrides = []): int
    {
        return DB::table('approval_decisions')->insertGetId(array_replace([
            'submission_id' => $submission, 'assignment_id' => $assignment,
            'approver_id' => DB::table('assignments')->where('id', $assignment)->value('assigned_to'),
            'level' => 1, 'decision' => 'approved', 'anomaly_check_id' => $check, 'decided_at' => now(),
        ], $overrides));
    }

    protected function document(int $application, array $overrides = []): int
    {
        $type = DB::table('document_types')->insertGetId([
            'code' => 'T'.Str::upper(Str::random(8)), 'name' => 'Dokumen fiktif', 'is_required' => true, 'is_active' => true,
        ]);

        return DB::table('document_versions')->insertGetId(array_replace([
            'application_id' => $application, 'document_type_id' => $type, 'version_number' => 1,
            'original_filename' => 'dummy.pdf', 'storage_path' => 'loan-documents/'.Str::ulid().'/dummy.pdf',
            'mime_type' => 'application/pdf', 'file_size_bytes' => 20, 'file_hash' => str_repeat('0', 64),
            'uploaded_by' => DB::table('loan_applications')->where('id', $application)->value('customer_id'), 'uploaded_at' => now(),
        ], $overrides));
    }
}
