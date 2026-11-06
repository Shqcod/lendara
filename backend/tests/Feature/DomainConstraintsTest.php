<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class DomainConstraintsTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    private function rejects(callable $operation, int $mysqlCode): void
    {
        try {
            $operation();
            $this->fail('MySQL seharusnya menolak pelanggaran constraint.');
        } catch (QueryException $error) {
            $this->assertSame($mysqlCode, $error->errorInfo[1]);
        }
    }

    public function test_all_fourteen_domain_tables_use_innodb_and_restrict_foreign_keys(): void
    {
        $tables = ['users', 'customer_profiles', 'loan_applications', 'application_submissions', 'document_types', 'document_versions', 'submission_documents', 'model_versions', 'anomaly_checks', 'assignments', 'verification_results', 'approval_decisions', 'application_status_histories', 'activity_logs'];
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame('InnoDB', DB::selectOne('SELECT ENGINE AS engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])->engine);
        }
        $keys = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')->whereIn('TABLE_NAME', $tables)->get();
        $this->assertGreaterThan(20, $keys->count());
        foreach ($keys as $key) {
            $this->assertSame('RESTRICT', $key->DELETE_RULE);
        }
    }

    public function test_one_active_application_includes_draft_and_revision_and_final_allows_new_one(): void
    {
        foreach (['draft', 'revision_required', 'pending_verification', 'pending_approval_1', 'pending_approval_2'] as $status) {
            $id = $this->loan(overrides: ['status' => $status]);
            $customer = DB::table('loan_applications')->where('id', $id)->value('customer_id');
            $this->rejects(fn () => $this->loan($customer), 1062);
            DB::table('loan_applications')->where('id', $id)->update(['status' => 'approved']);
            $this->assertIsInt($this->loan($customer));
        }
    }

    public function test_status_and_finance_cannot_bypass_markers_or_checks(): void
    {
        $id = $this->loan();
        foreach ([['status' => null, 'code' => 1048], ['status' => 'unknown', 'code' => 3819], ['loan_amount' => '0.00', 'code' => 3819], ['monthly_income' => '-1.00', 'code' => 3819], ['tenor_months' => 0, 'code' => 3819], ['monthly_expenses' => '-0.01', 'code' => 3819]] as $values) {
            $code = $values['code'];
            unset($values['code']);
            $this->rejects(fn () => DB::table('loan_applications')->where('id', $id)->update($values), $code);
        }
    }

    public function test_profile_identity_and_user_are_unique_even_without_api_validation(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        DB::table('customer_profiles')->insert(['user_id' => $a->id, 'identity_number' => '0000000000000009']);
        $this->rejects(fn () => DB::table('customer_profiles')->insert(['user_id' => $b->id, 'identity_number' => '0000000000000009']), 1062);
        $this->rejects(fn () => DB::table('customer_profiles')->insert(['user_id' => $a->id]), 1062);
        $this->rejects(fn () => DB::table('customer_profiles')->insert(['user_id' => $b->id, 'identity_number' => 'invalid']), 3819);
    }

    public function test_document_type_seeding_is_idempotent_and_never_overwrites_a_conflict(): void
    {
        $this->seed(DocumentTypeSeeder::class);
        $this->seed(DocumentTypeSeeder::class);
        $this->assertSame(4, DB::table('document_types')->whereIn('code', ['D01', 'D02', 'D03', 'D04'])->count());
        DB::table('document_types')->where('code', 'D03')->update(['is_required' => false]);
        try {
            $this->seed(DocumentTypeSeeder::class);
            $this->fail('Conflicting document configuration must be reported.');
        } catch (RuntimeException $error) {
            $this->assertFalse((bool) DB::table('document_types')->where('code', 'D03')->value('is_required'));
        }
    }

    public function test_snapshot_version_is_unique_and_current_pointer_must_belong_to_application(): void
    {
        $a = $this->loan();
        $b = $this->loan();
        $submission = $this->submission($a);
        $this->rejects(fn () => $this->submission($a), 1062);
        $this->rejects(fn () => DB::table('loan_applications')->where('id', $b)->update(['current_submission_id' => $submission]), 1452);
        $this->assertSame(1, DB::table('loan_applications')->where('id', $a)->update(['current_submission_id' => $submission]));
    }

    public function test_only_one_active_assignment_globally_even_across_snapshots(): void
    {
        $application = $this->loan();
        $first = $this->submission($application);
        $next = $this->submission($application, 2);
        $assignment = $this->assignment($application, $first);
        $this->rejects(fn () => $this->assignment($application, $next), 1062);
        DB::table('assignments')->where('id', $assignment)->update(['ended_at' => now(), 'end_type' => 'released', 'end_reason' => 'Fixture']);
        $this->assertIsInt($this->assignment($application, $next));
    }

    public function test_assignment_cannot_reference_snapshot_from_another_application(): void
    {
        $a = $this->loan();
        $b = $this->loan();
        $this->rejects(fn () => $this->assignment($a, $this->submission($b)), 1452);
    }

    public function test_assignment_and_verification_discriminators_cannot_free_unique_markers_with_invalid_values(): void
    {
        $application = $this->loan();
        $submission = $this->submission($application);
        $this->rejects(fn () => $this->assignment($application, $submission, ['stage' => 'unknown']), 3819);
        $assignment = $this->assignment($application, $submission);
        $this->rejects(fn () => DB::table('assignments')->where('id', $assignment)->update(['ended_at' => now(), 'end_type' => null]), 3819);
        $this->rejects(fn () => $this->verification($submission, $assignment, overrides: ['result_type' => null]), 1048);
        $this->rejects(fn () => $this->verification($submission, $assignment, overrides: ['result_type' => 'request_revision', 'notes' => null]), 3819);
    }

    public function test_processing_succeeded_and_attempt_numbers_are_unique_per_snapshot(): void
    {
        $submission = $this->submission($this->loan());
        $this->check($submission, 'processing');
        $this->rejects(fn () => $this->check($submission, 'processing', 2), 1062);
        $this->check($submission, 'succeeded', 2);
        $this->rejects(fn () => $this->check($submission, 'succeeded', 3), 1062);
        $this->rejects(fn () => $this->check($submission, 'failed', 2), 1062);
        $this->assertIsInt($this->check($submission, 'failed', 3));
    }

    public function test_check_state_and_score_consistency_cannot_be_bypassed(): void
    {
        $submission = $this->submission($this->loan());
        $this->rejects(fn () => $this->check($submission, overrides: ['is_anomaly' => true, 'score' => 0.1]), 3819);
        $this->rejects(fn () => $this->check($submission, overrides: ['status' => null]), 1048);
        $this->rejects(fn () => $this->check($submission, overrides: ['status' => 'unknown']), 3819);
        $this->rejects(fn () => $this->check($submission, 'processing', overrides: ['score' => 0.1]), 3819);
    }

    public function test_only_one_active_model_and_contamination_is_numeric_in_range(): void
    {
        if (! DB::table('model_versions')->where('is_active', true)->exists()) {
            $this->modelVersion(['is_active' => true]);
        }
        $this->rejects(fn () => $this->modelVersion(['is_active' => true]), 1062);
        $this->rejects(fn () => $this->modelVersion(['is_active' => null]), 1048);
        $this->rejects(fn () => $this->modelVersion(['contamination' => '0.00000']), 3819);
        $this->rejects(fn () => $this->modelVersion(['contamination' => '0.50001']), 3819);
    }

    public function test_terminal_verification_is_unique_and_requires_valid_assignment_and_check_snapshot(): void
    {
        $a = $this->loan();
        $s = $this->submission($a);
        $assignment = $this->assignment($a, $s);
        $other = $this->submission($this->loan());
        $otherCheck = $this->check($other);
        $this->rejects(fn () => $this->verification($s, $assignment, $otherCheck), 1452);
        $check = $this->check($s);
        $this->verification($s, $assignment, $check);
        $this->rejects(fn () => $this->verification($s, $assignment, null, ['result_type' => 'request_revision']), 1062);
        $this->rejects(fn () => $this->verification($other, $assignment, $otherCheck), 1452);
    }

    public function test_decision_level_is_unique_and_rejected_notes_cannot_be_null(): void
    {
        $a = $this->loan();
        $s = $this->submission($a);
        $assignment = $this->assignment($a, $s);
        $check = $this->check($s);
        $this->decision($s, $assignment, $check);
        $this->rejects(fn () => $this->decision($s, $assignment, $check), 1062);
        $this->rejects(fn () => $this->decision($s, $assignment, $check, ['level' => 3]), 3819);
        $this->rejects(fn () => $this->decision($s, $assignment, $check, ['level' => 2, 'decision' => 'rejected', 'notes' => null]), 3819);
        $otherCheck = $this->check($this->submission($this->loan()));
        $this->rejects(fn () => $this->decision($s, $assignment, $otherCheck, ['level' => 2]), 1452);
    }

    public function test_history_cannot_point_to_foreign_snapshot_or_have_null_from_status(): void
    {
        $a = $this->loan();
        $row = ['application_id' => $a, 'submission_id' => $this->submission($this->loan()), 'from_status' => 'draft', 'to_status' => 'pending_verification', 'changed_at' => now()];
        $this->rejects(fn () => DB::table('application_status_histories')->insert($row), 1452);
        $row['submission_id'] = null;
        $row['from_status'] = null;
        $this->rejects(fn () => DB::table('application_status_histories')->insert($row), 1048);
    }

    public function test_document_versions_and_submission_document_types_are_unique_and_restrict_deletion(): void
    {
        $a = $this->loan();
        $document = $this->document($a);
        $type = DB::table('document_versions')->where('id', $document)->value('document_type_id');
        $this->rejects(fn () => $this->document($a, ['document_type_id' => $type]), 1062);
        $row = ['submission_id' => $this->submission($a), 'document_type_id' => $type, 'document_version_id' => $document, 'created_at' => now()];
        DB::table('submission_documents')->insert($row);
        $this->rejects(fn () => DB::table('submission_documents')->insert($row), 1062);
        $this->rejects(fn () => DB::table('document_versions')->where('id', $document)->delete(), 1451);
    }
}
