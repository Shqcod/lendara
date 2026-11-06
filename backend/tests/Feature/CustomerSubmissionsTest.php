<?php

namespace Tests\Feature;

use App\Exceptions\DomainError;
use App\Models\User;
use App\Services\CustomerSubmissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SubmissionFixtures;
use Tests\TestCase;

class CustomerSubmissionsTest extends TestCase
{
    use DatabaseTransactions, SubmissionFixtures;

    private User $customer;

    private int $application;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Bus::fake();
        $this->customer = User::factory()->create();
        $this->application = $this->completeApplication($this->customer);
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->actingAs($this->customer, 'web');
    }

    private function send(?int $expected = null)
    {
        return $this->postJson('/api/v1/applications/'.$this->application.'/submissions', ['confirmed' => true, 'expected_current_submission_id' => $expected]);
    }

    public function test_submission_copies_saved_values_documents_and_one_processing_attempt_atomically(): void
    {
        $response = $this->send()->assertStatus(202)->assertJsonPath('data.version_number', 1)->assertJsonPath('data.loan_amount', '20000000.01');
        $id = $response->json('data.id');
        $this->assertDatabaseHas('loan_applications', ['id' => $this->application, 'status' => 'pending_verification', 'current_submission_id' => $id]);
        $check = DB::table('anomaly_checks')->where('submission_id', $id)->sole();
        $this->assertSame('processing', $check->status);
        $this->assertSame(1, $check->attempt_number);
        $this->assertEquals(120, strtotime($check->deadline_at) - strtotime($check->started_at));
        $this->assertSame(20000000.01, json_decode($check->input_features, true)['loan_amount']);
        $this->assertDatabaseHas('application_status_histories', ['application_id' => $this->application, 'from_status' => 'draft', 'to_status' => 'pending_verification']);
        $this->assertSame(DB::table('document_versions')->where('application_id', $this->application)->count(), DB::table('submission_documents')->where('submission_id', $id)->count());
        $this->send()->assertConflict()->assertJsonPath('code', 'SUBMISSION_VERSION_CONFLICT');
        $this->send($id)->assertConflict()->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
        $this->patchJson('/api/v1/applications/'.$this->application, ['loan_amount' => '100'])->assertConflict();
        $this->deleteJson('/api/v1/applications/'.$this->application)->assertConflict();
    }

    public function test_incomplete_saved_profile_finance_and_documents_have_field_errors_without_partial_rows(): void
    {
        DB::table('customer_profiles')->where('user_id', $this->customer->id)->update(['phone' => null, 'work_duration_months' => null]);
        DB::table('loan_applications')->where('id', $this->application)->update(['loan_amount' => null]);
        $document = DB::table('document_versions')->where('application_id', $this->application)->first();
        Storage::disk('local')->delete($document->storage_path);
        $type = DB::table('document_types')->where('id', $document->document_type_id)->first();
        $this->send()->assertUnprocessable()->assertJsonPath('code', 'SUBMISSION_INCOMPLETE')
            ->assertJsonValidationErrors(['profile.phone', 'profile.work_duration_months', 'loan_amount', 'documents.'.$type->code]);
        $this->assertDatabaseMissing('application_submissions', ['application_id' => $this->application]);
        $this->assertDatabaseMissing('application_status_histories', ['application_id' => $this->application]);
        $this->assertDatabaseHas('loan_applications', ['id' => $this->application, 'status' => 'draft', 'current_submission_id' => null]);
        Bus::assertNothingDispatched();
    }

    public function test_request_requires_literal_confirmation_and_explicit_version_token(): void
    {
        $url = '/api/v1/applications/'.$this->application.'/submissions';
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['confirmed', 'expected_current_submission_id']);
        foreach ([false, 'true', 'yes', 1] as $confirmed) {
            $this->postJson($url, ['confirmed' => $confirmed, 'expected_current_submission_id' => null])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        }
        $this->postJson($url, ['confirmed' => true, 'expected_current_submission_id' => '1', 'model_version_id' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_current_submission_id', 'model_version_id']);
    }

    public function test_missing_or_incompatible_model_and_demo_stub_are_rejected_without_submitting(): void
    {
        DB::table('model_versions')->where('version_code', 'stub-v0')->update(['is_active' => false]);
        $this->send()->assertStatus(503)->assertJsonPath('code', 'SERVICE_NOT_READY')->assertJsonMissingPath('model');
        DB::table('model_versions')->where('version_code', 'stub-v0')->update(['is_active' => true]);
        config(['services.ml.mode' => 'model']);
        $this->send()->assertStatus(503);
        config(['services.ml.mode' => 'stub']);
        $this->app->instance('env', 'production');
        try {
            app(CustomerSubmissions::class)->submit($this->customer->id, $this->application, null);
            $this->fail('Demo/production must refuse an active stub.');
        } catch (DomainError $error) {
            $this->assertSame(503, $error->status);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertDatabaseMissing('application_submissions', ['application_id' => $this->application]);
    }

    public function test_revision_creates_new_snapshot_and_keeps_old_profile_finance_and_document_links(): void
    {
        $first = $this->send()->assertStatus(202)->json('data');
        DB::table('loan_applications')->where('id', $this->application)->update(['status' => 'revision_required', 'loan_amount' => '30000000.02']);
        DB::table('customer_profiles')->where('user_id', $this->customer->id)->update(['phone' => '+62000000001']);
        $type = DB::table('document_versions')->where('application_id', $this->application)->value('document_type_id');
        $replacement = $this->storedDocument($this->application, $type, 2);
        $second = $this->send($first['id'])->assertStatus(202)->assertJsonPath('data.version_number', 2)->json('data');
        $this->assertSame('30000000.02', $second['loan_amount']);
        $this->assertDatabaseHas('submission_documents', ['submission_id' => $second['id'], 'document_version_id' => $replacement]);
        $this->getJson('/api/v1/applications/'.$this->application.'/submissions/'.$first['id'])->assertOk()
            ->assertJsonPath('data.loan_amount', '20000000.01')->assertJsonPath('data.customer_snapshot.phone', '+62000000000');
        $this->assertDatabaseHas('submission_documents', ['submission_id' => $first['id'], 'document_version_id' => $first['documents'][0]['id']]);
        $this->assertSame(2, DB::table('anomaly_checks')->whereIn('submission_id', [$first['id'], $second['id']])->count());
        $this->send($first['id'])->assertConflict();
    }

    public function test_customer_reads_are_allowlisted_and_other_customer_and_internal_roles_are_denied(): void
    {
        $id = $this->send()->assertStatus(202)->json('data.id');
        DB::table('application_status_histories')->where('application_id', $this->application)->update(['notes' => 'INTERNAL SECRET NOTE']);
        $this->getJson('/api/v1/applications/'.$this->application.'/submissions')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('data.0.anomaly_checks')->assertJsonMissingPath('data.0.documents.0.storage_path')->assertJsonMissingPath('data.0.customer_snapshot.user_id');
        $history = $this->getJson('/api/v1/applications/'.$this->application.'/status-histories')->assertOk();
        $this->assertStringNotContainsString('INTERNAL SECRET NOTE', $history->getContent());
        foreach ([User::factory()->create(), User::factory()->create(['role' => 'admin']), User::factory()->create(['role' => 'verifikator']),
            User::factory()->create(['role' => 'penyetuju_1']), User::factory()->create(['role' => 'penyetuju_2'])] as $user) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user, 'web');
            $status = $user->role->value === 'nasabah' ? 404 : 403;
            $this->getJson('/api/v1/applications/'.$this->application.'/submissions')->assertStatus($status);
            $this->getJson('/api/v1/applications/'.$this->application.'/submissions/'.$id)->assertStatus($status);
            $this->getJson('/api/v1/applications/'.$this->application.'/status-histories')->assertStatus($status);
            $this->postJson('/api/v1/applications/'.$this->application.'/submissions', [])->assertStatus($status);
        }
    }

    public function test_account_is_rechecked_under_lock_and_audit_failure_rolls_back_every_submission_row(): void
    {
        DB::table('users')->where('id', $this->customer->id)->update(['is_active' => false]);
        try {
            app(CustomerSubmissions::class)->submit($this->customer->id, $this->application, null);
            $this->fail('Inactive customer must be rejected.');
        } catch (DomainError $error) {
            $this->assertSame('ACCOUNT_INACTIVE', $error->domainCode);
        }
        DB::table('users')->where('id', $this->customer->id)->update(['is_active' => true]);
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into `activity_logs`')) {
                throw new \RuntimeException('Synthetic audit failure.');
            }
        });
        $this->send()->assertStatus(500);
        $this->assertDatabaseMissing('application_submissions', ['application_id' => $this->application]);
        $this->assertDatabaseMissing('application_status_histories', ['application_id' => $this->application]);
        $this->assertDatabaseHas('loan_applications', ['id' => $this->application, 'status' => 'draft', 'current_submission_id' => null]);
        Bus::assertNothingDispatched();
    }

    public function test_history_exposes_only_explicit_revision_reason_and_nested_snapshot_scope_is_enforced(): void
    {
        $id = $this->send()->assertStatus(202)->json('data.id');
        $assignment = $this->assignment($this->application, $id);
        $this->verification($id, $assignment, overrides: ['result_type' => 'request_revision', 'notes' => 'Perbarui bukti penghasilan fiktif.']);
        DB::table('application_status_histories')->insert(['application_id' => $this->application, 'submission_id' => $id,
            'from_status' => 'pending_verification', 'to_status' => 'revision_required', 'notes' => 'Private workflow note', 'changed_at' => now()]);
        $this->getJson('/api/v1/applications/'.$this->application.'/status-histories?per_page=1')->assertOk()
            ->assertJsonPath('data.0.revision_reason', 'Perbarui bukti penghasilan fiktif.')->assertJsonMissingPath('data.0.notes')->assertJsonPath('meta.total', 2);
        $otherApplication = $this->loan($this->customer->id, ['status' => 'approved']);
        $this->getJson('/api/v1/applications/'.$otherApplication.'/submissions/'.$id)->assertNotFound();
        $this->getJson('/api/v1/applications/'.$this->application.'/submissions?per_page=101')->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/applications/'.$this->application.'/submissions')->assertUnauthorized();
    }
}
