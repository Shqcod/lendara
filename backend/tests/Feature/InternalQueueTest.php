<?php

namespace Tests\Feature;

use App\Exceptions\DomainError;
use App\Models\User;
use App\Services\ReviewAssignments;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class InternalQueueTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function ready(string $status = 'pending_verification'): array
    {
        $application = $this->loan(overrides: ['status' => $status]);
        $submission = $this->submission($application);
        DB::table('loan_applications')->where('id', $application)->update(['current_submission_id' => $submission]);

        return [$application, $submission];
    }

    public function test_anonymous_customers_and_admin_cannot_read_or_mutate_reviewer_routes(): void
    {
        [$id, $submission] = $this->ready();
        $this->getJson('/api/v1/internal/applications')->assertUnauthorized();
        foreach (['nasabah', 'admin'] as $role) {
            $this->authenticate(User::factory()->create(['role' => $role]));
            $this->getJson('/api/v1/internal/applications')->assertForbidden();
            $this->getJson('/api/v1/internal/application-summary')->assertForbidden();
            $this->getJson('/api/v1/internal/applications/'.$id)->assertForbidden();
            $this->postJson('/api/v1/internal/applications/'.$id.'/assignments', ['expected_current_submission_id' => $submission])->assertForbidden();
        }
    }

    public function test_three_roles_see_only_their_queue_and_filters_and_summary_cannot_expand_scope(): void
    {
        $ids = [];
        foreach (['pending_verification', 'pending_approval_1', 'pending_approval_2', 'draft', 'revision_required', 'approved', 'rejected'] as $status) {
            $ids[$status] = $this->ready($status);
        }
        foreach (['verifikator' => 'pending_verification', 'penyetuju_1' => 'pending_approval_1', 'penyetuju_2' => 'pending_approval_2'] as $role => $status) {
            $this->authenticate(User::factory()->create(['role' => $role]));
            $response = $this->getJson('/api/v1/internal/applications?per_page=100')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $rows = $response->json('data');
            $number = DB::table('loan_applications')->where('id', $ids[$status][0])->value('application_number');
            $this->getJson('/api/v1/internal/applications?application_number='.$number)->assertOk()->assertJsonPath('data.0.id', $ids[$status][0]);
            foreach ($rows as $row) {
                $this->assertSame($status, $row['status']);
            }
            $summary = $this->getJson('/api/v1/internal/application-summary')->assertOk()->assertJsonPath('data.status', $status)->json('data');
            $this->assertSame($response->json('meta.total'), $summary['total']);
            $this->assertSame([$status], array_keys($summary['by_status']));
            $this->getJson('/api/v1/internal/applications?status=draft')->assertUnprocessable();
            $this->getJson('/api/v1/internal/applications?assignment=other')->assertUnprocessable();
            $this->getJson('/api/v1/internal/applications?per_page=101')->assertUnprocessable();
            $this->getJson('/api/v1/internal/applications/'.$ids['approved'][0])->assertNotFound();
        }
    }

    public function test_claim_is_pinned_server_owned_and_release_preserves_status_and_full_assignment_history(): void
    {
        [$id, $submission] = $this->ready();
        $actor = User::factory()->create(['role' => 'verifikator']);
        $other = User::factory()->create(['role' => 'verifikator']);
        $this->authenticate($actor);
        $url = '/api/v1/internal/applications/'.$id.'/assignments';
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('expected_current_submission_id');
        $this->postJson($url, ['expected_current_submission_id' => $submission, 'assigned_to' => $other->id, 'stage' => 'approval_2'])->assertUnprocessable()->assertJsonValidationErrors(['assigned_to', 'stage']);
        $this->postJson($url, ['expected_current_submission_id' => $submission + 1234])->assertConflict()->assertJsonPath('code', 'SUBMISSION_VERSION_CONFLICT');
        $assignment = $this->postJson($url, ['expected_current_submission_id' => $submission])->assertCreated()
            ->assertJsonPath('data.assigned_to', $actor->id)->assertJsonPath('data.stage', 'verification')->json('data');
        $this->postJson($url, ['expected_current_submission_id' => $submission])->assertConflict()->assertJsonPath('code', 'ASSIGNMENT_ALREADY_CLAIMED');
        $mine = $this->getJson('/api/v1/internal/applications?assignment=mine')->assertOk()->json('data');
        $this->assertContains($id, array_column($mine, 'id'));
        $unassigned = $this->getJson('/api/v1/internal/applications?assignment=unassigned&per_page=100')->assertOk()->json('data');
        $this->assertNotContains($id, array_column($unassigned, 'id'));
        $this->authenticate($other);
        $this->postJson('/api/v1/internal/assignments/'.$assignment['id'].'/release', ['reason' => 'Bukan pemilik'])->assertForbidden();
        $this->postJson($url, ['expected_current_submission_id' => $submission])->assertConflict();
        $this->getJson('/api/v1/internal/applications/'.$id)->assertOk()->assertJsonPath('data.current_assignment.assignee.id', $actor->id);
        $this->authenticate($actor);
        $this->postJson('/api/v1/internal/assignments/'.$assignment['id'].'/release', ['reason' => ' '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/internal/assignments/'.$assignment['id'].'/release', ['reason' => 'Alasan pelepasan fiktif'])->assertOk()
            ->assertJsonPath('data.end_type', 'released')->assertJsonPath('data.end_reason', 'Alasan pelepasan fiktif');
        $this->postJson('/api/v1/internal/assignments/'.$assignment['id'].'/release', ['reason' => 'Ulang'])->assertConflict()->assertJsonPath('code', 'ASSIGNMENT_NOT_ACTIVE');
        $this->authenticate($other);
        $new = $this->postJson($url, ['expected_current_submission_id' => $submission])->assertCreated()->json('data.id');
        $this->assertNotSame($assignment['id'], $new);
        $this->getJson('/api/v1/internal/applications/'.$id)->assertOk()->assertJsonPath('data.assignments.meta.total', 2);
        $this->assertDatabaseHas('loan_applications', ['id' => $id, 'status' => 'pending_verification', 'current_submission_id' => $submission]);
        $this->assertSame(0, DB::table('application_status_histories')->where('application_id', $id)->count());
        $this->assertSame(3, DB::table('activity_logs')->where('target_type', 'assignment')->whereIn('target_id', [$assignment['id'], $new])->count());
    }

    public function test_approvers_cannot_skip_stage_and_stage_is_determined_by_server(): void
    {
        [$verification, $v] = $this->ready();
        [$approval, $a] = $this->ready('pending_approval_1');
        [$last, $l] = $this->ready('pending_approval_2');
        foreach ([['penyetuju_1', $approval, $a, 'approval_1'], ['penyetuju_2', $last, $l, 'approval_2']] as [$role, $id, $sub, $stage]) {
            $this->authenticate(User::factory()->create(['role' => $role]));
            $this->postJson('/api/v1/internal/applications/'.$verification.'/assignments', ['expected_current_submission_id' => $v])->assertNotFound();
            $this->postJson('/api/v1/internal/applications/'.$id.'/assignments', ['expected_current_submission_id' => $sub])->assertCreated()->assertJsonPath('data.stage', $stage);
        }
    }

    public function test_internal_snapshots_and_documents_require_current_role_scope_and_immutable_submission_links(): void
    {
        [$id, $old] = $this->ready();
        $document = $this->document($id);
        $record = DB::table('document_versions')->where('id', $document)->first();
        Storage::disk('local')->put($record->storage_path, 'dokumen fiktif');
        DB::table('submission_documents')->insert(['submission_id' => $old, 'document_type_id' => $record->document_type_id, 'document_version_id' => $document, 'created_at' => now()]);
        $unlinked = $this->document($id);
        Storage::disk('local')->put(DB::table('document_versions')->where('id', $unlinked)->value('storage_path'), 'Berkas kerja fiktif yang belum dikirim');
        $current = $this->submission($id, 2);
        DB::table('loan_applications')->where('id', $id)->update(['current_submission_id' => $current]);
        $this->check($old);
        $this->authenticate(User::factory()->create(['role' => 'verifikator']));
        $this->getJson('/api/v1/internal/applications/'.$id.'/submissions/'.$old)->assertOk()->assertJsonPath('data.anomaly_checks.0.status', 'succeeded')
            ->assertJsonMissingPath('data.documents.0.storage_path')->assertJsonMissingPath('data.anomaly_checks.0.input_features');
        $this->getJson('/api/v1/document-versions/'.$document.'/download')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/document-versions/'.$unlinked.'/download')->assertNotFound();
        [$foreign, $foreignSubmission] = $this->ready();
        $this->getJson('/api/v1/internal/applications/'.$id.'/submissions/'.$foreignSubmission)->assertNotFound();
        DB::table('loan_applications')->where('id', $id)->update(['status' => 'pending_approval_1']);
        $this->getJson('/api/v1/document-versions/'.$document.'/download')->assertNotFound();
        $this->getJson('/api/v1/internal/applications/'.$id)->assertNotFound();
        $this->authenticate(User::factory()->create(['role' => 'penyetuju_1']));
        $this->getJson('/api/v1/document-versions/'.$document.'/download')->assertOk();
        $this->authenticate(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/document-versions/'.$document.'/download')->assertForbidden();
        $this->authenticate(User::findOrFail(DB::table('loan_applications')->where('id', $id)->value('customer_id')));
        $this->getJson('/api/v1/applications/'.$id.'/submissions/'.$old)->assertOk()->assertJsonMissingPath('data.anomaly_checks');
    }

    public function test_mutations_recheck_account_and_role_and_audit_failure_rolls_back_claim_and_release(): void
    {
        [$id, $submission] = $this->ready();
        $actor = User::factory()->create(['role' => 'verifikator']);
        $this->authenticate($actor);
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        try {
            app(ReviewAssignments::class)->claim($actor->id, $id, $submission);
            $this->fail('Inactive account accepted.');
        } catch (DomainError $error) {
            $this->assertSame('ACCOUNT_INACTIVE', $error->domainCode);
        }
        DB::table('users')->where('id', $actor->id)->update(['is_active' => true, 'role' => 'admin']);
        try {
            app(ReviewAssignments::class)->claim($actor->id, $id, $submission);
            $this->fail('Changed role accepted.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        DB::table('users')->where('id', $actor->id)->update(['role' => 'verifikator']);
        $assignment = app(ReviewAssignments::class)->claim($actor->id, $id, $submission);
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into `activity_logs`')) {
                throw new \RuntimeException('Synthetic assignment audit failure.');
            }
        });
        $this->postJson('/api/v1/internal/assignments/'.$assignment->id.'/release', ['reason' => 'Fiktif'])->assertStatus(500);
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id, 'ended_at' => null, 'end_reason' => null]);
        [$other, $otherSubmission] = $this->ready();
        $this->postJson('/api/v1/internal/applications/'.$other.'/assignments', ['expected_current_submission_id' => $otherSubmission])->assertStatus(500);
        $this->assertDatabaseMissing('assignments', ['application_id' => $other]);
    }
}
