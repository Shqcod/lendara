<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ApplicationSubmission;
use App\Models\User;
use App\Services\CustomerWorkspace;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class CustomerWorkspaceTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    public function test_profile_can_be_read_without_creating_a_row_and_saved_partially(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);
        $this->getJson('/api/v1/customer/profile')->assertOk()->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.identity_number', null)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseMissing('customer_profiles', ['user_id' => $user->id]);
        $this->patchJson('/api/v1/customer/profile', ['name' => 'Nama Fiktif Baru', 'identity_number' => '0000000000000001'])
            ->assertOk()->assertJsonPath('data.identity_number', '0000000000000001')->assertJsonPath('data.name', 'Nama Fiktif Baru');
        $this->patchJson('/api/v1/customer/profile', ['phone' => '+620000000000', 'work_duration_months' => 0])
            ->assertOk()->assertJsonPath('data.identity_number', '0000000000000001')->assertJsonPath('data.work_duration_months', 0);
        $this->getJson('/api/v1/customer/profile')->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.password');
    }

    public function test_profile_validation_and_unique_identity_preserve_the_entire_transaction(): void
    {
        $a = User::factory()->create();
        app(CustomerWorkspace::class)->updateProfile($a->id, ['identity_number' => '0000000000000002']);
        $b = User::factory()->create();
        $this->authenticate($b);
        $this->patchJson('/api/v1/customer/profile', ['name' => 'Tidak Boleh Tersimpan', 'identity_number' => '0000000000000002'])
            ->assertUnprocessable()->assertJsonValidationErrors('identity_number');
        $this->assertSame($b->name, $b->fresh()->name);
        $this->patchJson('/api/v1/customer/profile', ['identity_number' => '123', 'birth_date' => '2026-02-30', 'work_duration_months' => -1, 'role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors(['identity_number', 'birth_date', 'work_duration_months', 'role']);
    }

    public function test_customer_routes_require_authentication_and_deny_all_internal_roles(): void
    {
        $this->getJson('/api/v1/customer/profile')->assertUnauthorized();
        $this->postJson('/api/v1/applications', [])->assertUnauthorized();
        foreach ([Role::Verifier, Role::ApproverOne, Role::ApproverTwo, Role::Admin] as $role) {
            $this->authenticate(User::factory()->create(['role' => $role]));
            $this->getJson('/api/v1/customer/profile')->assertForbidden();
            $this->patchJson('/api/v1/customer/profile', [])->assertForbidden();
            $this->getJson('/api/v1/applications')->assertForbidden();
            $this->postJson('/api/v1/applications', [])->assertForbidden();
        }
    }

    public function test_draft_can_be_incomplete_and_creation_has_a_log_without_status_history(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);
        $response = $this->postJson('/api/v1/applications', [])->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.loan_amount', null)
            ->assertJsonPath('data.current_submission_id', null);
        $id = $response->json('data.id');
        $this->assertDatabaseHas('activity_logs', ['target_id' => $id, 'action' => 'loan_application.created', 'actor_id' => $user->id]);
        $this->assertDatabaseMissing('application_status_histories', ['application_id' => $id]);
        $this->postJson('/api/v1/applications', [])->assertConflict()->assertJsonPath('code', 'ACTIVE_APPLICATION_EXISTS');
        $this->assertSame(1, DB::table('loan_applications')->where('customer_id', $user->id)->count());
    }

    public function test_money_keeps_decimal_precision_and_only_type_capacity_is_limited(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);
        $id = $this->loan($user->id);
        $this->patchJson('/api/v1/applications/'.$id, [
            'loan_amount' => '9999999999999999.99', 'monthly_income' => '0.01',
            'monthly_expenses' => '0', 'existing_installments' => '100.1', 'tenor_months' => 4294967295,
        ])->assertOk()->assertJsonPath('data.loan_amount', '9999999999999999.99')
            ->assertJsonPath('data.monthly_income', '0.01')->assertJsonPath('data.monthly_expenses', '0.00')
            ->assertJsonPath('data.existing_installments', '100.10')->assertJsonPath('data.tenor_months', 4294967295);
        foreach (['1e3', '1,000', '0.001', '-1.00', '10000000000000000.00', 1000, true, '0.00'] as $invalid) {
            $this->patchJson('/api/v1/applications/'.$id, ['loan_amount' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('loan_amount');
        }
        $this->patchJson('/api/v1/applications/'.$id, ['tenor_months' => 1.5, 'monthly_income' => '0'])
            ->assertUnprocessable()->assertJsonValidationErrors(['tenor_months', 'monthly_income']);
    }

    public function test_client_cannot_supply_ownership_status_or_internal_results(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);
        $this->postJson('/api/v1/applications', ['customer_id' => $user->id, 'status' => 'approved', 'is_anomaly' => true, 'score' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'status', 'is_anomaly', 'score']);
        $this->assertDatabaseMissing('loan_applications', ['customer_id' => $user->id]);
    }

    public function test_cross_customer_detail_edit_delete_and_lists_do_not_leak(): void
    {
        $other = $this->loan();
        $user = User::factory()->create();
        $own = $this->loan($user->id);
        $this->authenticate($user);
        $this->getJson('/api/v1/applications/'.$other)->assertNotFound();
        $this->patchJson('/api/v1/applications/'.$other, ['loan_amount' => 'invalid'])->assertNotFound();
        $this->deleteJson('/api/v1/applications/'.$other)->assertNotFound();
        $this->getJson('/api/v1/applications?customer_id=1&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own)
            ->assertJsonStructure(['meta' => ['current_page', 'per_page', 'total', 'last_page'], 'links' => ['first', 'last', 'prev', 'next']]);
        $this->getJson('/api/v1/applications?status=approved')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/applications?status=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/applications?per_page=101')->assertUnprocessable();
    }

    public function test_forbidden_workflow_states_cannot_be_edited_or_deleted(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);
        $id = $this->loan($user->id);
        foreach (['pending_verification', 'pending_approval_1', 'pending_approval_2', 'approved', 'rejected'] as $status) {
            DB::table('loan_applications')->where('id', $id)->update(['status' => $status]);
            $this->patchJson('/api/v1/applications/'.$id, [])->assertConflict()->assertJsonPath('code', 'APPLICATION_NOT_EDITABLE');
            $this->deleteJson('/api/v1/applications/'.$id)->assertConflict()->assertJsonPath('code', 'DRAFT_ALREADY_SUBMITTED');
        }
    }

    public function test_profile_and_revision_edits_do_not_mutate_previous_snapshot_or_expose_internal_notes(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);
        $id = $this->loan($user->id);
        $submission = $this->submission($id);
        $assignment = $this->assignment($id, $submission);
        $check = $this->check($submission);
        $this->verification($submission, $assignment, $check);
        DB::table('loan_applications')->where('id', $id)->update(['status' => 'revision_required', 'current_submission_id' => $submission]);
        $before = (array) DB::table('application_submissions')->where('id', $submission)->first();
        $this->patchJson('/api/v1/customer/profile', ['name' => 'Identitas Kerja Berubah'])->assertOk();
        $this->patchJson('/api/v1/applications/'.$id, ['loan_amount' => '1.00'])->assertOk();
        $this->assertSame($before, (array) DB::table('application_submissions')->where('id', $submission)->first());
        foreach (['/api/v1/applications', '/api/v1/applications/'.$id] as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();
            foreach (['score', 'is_anomaly', 'anomaly_checks', 'document_checklist', 'notes', 'Catatan internal', 'storage_path', 'active_customer_id'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body);
            }
        }
        $this->deleteJson('/api/v1/applications/'.$id)->assertConflict();
        $this->expectException(LogicException::class);
        ApplicationSubmission::findOrFail($submission)->update(['purpose' => 'Tidak boleh']);
    }

    public function test_account_and_role_are_rechecked_under_lock_during_mutations(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->postJson('/api/v1/applications', [])->assertForbidden()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        DB::table('users')->where('id', $user->id)->update(['is_active' => true, 'role' => 'admin']);
        $this->patchJson('/api/v1/customer/profile', ['name' => 'Tidak tersimpan'])->assertForbidden();
        $this->assertDatabaseMissing('loan_applications', ['customer_id' => $user->id]);
    }

    public function test_creation_rolls_back_content_and_audit_together(): void
    {
        $user = User::factory()->create();
        $application = null;
        try {
            DB::transaction(function () use ($user, &$application) {
                $application = app(CustomerWorkspace::class)->createDraft($user->id, []);
                throw new RuntimeException('Simulasi rollback pengujian.');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Simulasi rollback pengujian.', $error->getMessage());
        }
        $this->assertDatabaseMissing('loan_applications', ['id' => $application->id]);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'loan_application.created', 'target_id' => $application->id]);
    }

    public function test_draft_deletion_keeps_generic_audit_and_does_not_create_status_history(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);
        $id = $this->postJson('/api/v1/applications', [])->assertCreated()->json('data.id');
        $this->deleteJson('/api/v1/applications/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('loan_applications', ['id' => $id]);
        $this->assertDatabaseHas('activity_logs', ['target_id' => $id, 'action' => 'loan_application.deleted']);
        $this->assertDatabaseHas('activity_logs', ['target_id' => $id, 'action' => 'loan_application.created']);
        $this->assertDatabaseMissing('application_status_histories', ['application_id' => $id]);
        $this->postJson('/api/v1/applications', [])->assertCreated();
    }

    public function test_delete_rollback_preserves_documents_files_and_cancels_persistent_cleanup(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $id = $this->loan($user->id);
        $doc = $this->document($id);
        $path = DB::table('document_versions')->where('id', $doc)->value('storage_path');
        Storage::disk('local')->put($path, 'dokumen fiktif');
        $before = DB::table('jobs')->where('queue', 'document-cleanup')->count();
        try {
            DB::transaction(function () use ($user, $id, $before, $path) {
                app(CustomerWorkspace::class)->deleteDraft($user->id, $id);
                $this->assertSame($before + 1, DB::table('jobs')->where('queue', 'document-cleanup')->count());
                Storage::disk('local')->assertExists($path);
                throw new RuntimeException('Simulasi rollback pengujian.');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Simulasi rollback pengujian.', $error->getMessage());
        }
        $this->assertDatabaseHas('loan_applications', ['id' => $id]);
        $this->assertDatabaseHas('document_versions', ['id' => $doc]);
        $this->assertSame($before, DB::table('jobs')->where('queue', 'document-cleanup')->count());
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseMissing('activity_logs', ['target_id' => $id, 'action' => 'loan_application.deleted']);
    }
}
