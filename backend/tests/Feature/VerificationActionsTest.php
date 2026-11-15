<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VerificationActions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class VerificationActionsTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    private function ready(): array
    {
        $actor = User::factory()->create(['role' => 'verifikator']);
        $app = $this->loan(overrides: ['status' => 'pending_verification']);
        $id = $this->submission($app);
        DB::table('loan_applications')->where('id', $app)->update(['current_submission_id' => $id]);
        $assignment = $this->assignment($app, $id, ['assigned_to' => $actor->id]);
        $this->login($actor);

        return [$actor, $app, $id, $assignment];
    }

    private function login(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->withHeader('Origin', 'http://localhost:5173');
    }

    private function send(int $id, string $type = 'note', ?string $notes = null, array $extra = [])
    {
        return $this->postJson('/api/v1/internal/submissions/'.$id.'/verification-results', array_replace([
            'result_type' => $type, 'notes' => $notes, 'document_checklist' => [],
        ], $extra));
    }

    public function test_note_keeps_task_and_snapshot_immutable_and_is_private(): void
    {
        [$actor, $app, $id, $assignment] = $this->ready();
        $before = (array) DB::table('application_submissions')->find($id);
        $this->send($id, notes: 'Internal fiktif')->assertCreated()->assertJsonPath('data.application_status', 'pending_verification');
        $this->assertDatabaseHas('assignments', ['id' => $assignment, 'ended_at' => null]);
        $this->assertDatabaseMissing('application_status_histories', ['application_id' => $app]);
        $this->getJson('/api/v1/internal/applications/'.$app)->assertOk()->assertJsonPath('data.current_submission.verification_results.data.0.notes', 'Internal fiktif');
        $this->assertSame($before, (array) DB::table('application_submissions')->find($id));
        $this->login(User::findOrFail(DB::table('loan_applications')->where('id', $app)->value('customer_id')));
        $response = $this->getJson('/api/v1/applications/'.$app.'/submissions/'.$id)->assertOk()->assertJsonMissingPath('data.verification_results');
        $this->assertStringNotContainsString('Internal fiktif', $response->getContent());
    }

    public function test_revision_requires_reason_and_exposes_only_public_reason(): void
    {
        [$actor, $app, $id, $assignment] = $this->ready();
        $this->send($id, 'request_revision', '   ')->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->send($id, 'request_revision', 'Lengkapi berkas fiktif')->assertCreated()->assertJsonPath('data.application_status', 'revision_required');
        $this->assertDatabaseHas('assignments', ['id' => $assignment, 'end_type' => 'completed', 'ended_by' => $actor->id]);
        $this->assertDatabaseHas('application_status_histories', ['application_id' => $app, 'to_status' => 'revision_required']);
        $this->send($id, 'request_revision', 'Duplikat')->assertNotFound();
        $this->assertSame(1, DB::table('verification_results')->where('submission_id', $id)->count());
        $this->login(User::findOrFail(DB::table('loan_applications')->where('id', $app)->value('customer_id')));
        $this->getJson('/api/v1/applications/'.$app.'/status-histories')->assertOk()->assertJsonPath('data.0.revision_reason', 'Lengkapi berkas fiktif')->assertJsonMissingPath('data.0.notes');
    }

    public function test_forward_requires_success_and_anomaly_notes_and_ends_task(): void
    {
        [$actor, $app, $id, $assignment] = $this->ready();
        $this->send($id, 'forward')->assertConflict()->assertJsonPath('code', 'ANOMALY_CHECK_NOT_READY');
        $this->check($id, 'failed');
        $this->send($id, 'forward')->assertConflict();
        $check = $this->check($id, attempt: 2, overrides: ['score' => -0.12, 'is_anomaly' => true]);
        $this->send($id, 'forward')->assertUnprocessable()->assertJsonPath('code', 'ANOMALY_REVIEW_NOTE_REQUIRED');
        $this->send($id, 'forward', 'Sudah ditinjau manusia')->assertCreated()->assertJsonPath('data.application_status', 'pending_approval_1');
        $this->assertDatabaseHas('verification_results', ['submission_id' => $id, 'anomaly_check_id' => $check, 'verifier_id' => $actor->id]);
        $this->assertDatabaseHas('assignments', ['id' => $assignment, 'end_type' => 'completed']);
        $this->getJson('/api/v1/internal/applications/'.$app)->assertNotFound();
        $this->login(User::factory()->create(['role' => 'penyetuju_1']));
        $this->getJson('/api/v1/internal/applications/'.$app)->assertOk()->assertJsonPath('data.current_assignment', null);
    }

    public function test_scope_owner_version_and_server_fields(): void
    {
        [$actor, $app, $id, $assignment] = $this->ready();
        $this->send($id, extra: ['verifier_id' => 1])->assertUnprocessable();
        $this->send($id, extra: ['document_checklist' => ['999999999' => true]])->assertUnprocessable();
        $this->send($id, extra: ['document_checklist' => ['1' => 'true']])->assertUnprocessable();
        $old = $this->submission($app, 2);
        $this->send($old)->assertConflict()->assertJsonPath('code', 'SUBMISSION_VERSION_CONFLICT');
        foreach (['nasabah', 'admin', 'penyetuju_1', 'penyetuju_2', 'verifikator'] as $role) {
            $this->login(User::factory()->create(['role' => $role]));
            $this->send($id)->assertForbidden();
        }
        $this->login($actor);
        DB::table('assignments')->where('id', $assignment)->update(['ended_at' => now(), 'end_type' => 'released']);
        $this->send($id)->assertForbidden();
    }

    public function test_normal_forward_with_linked_checklist(): void
    {
        [$actor, $app, $id] = $this->ready();
        $this->check($id);
        $document = $this->document($app);
        $type = DB::table('document_versions')->where('id', $document)->value('document_type_id');
        DB::table('submission_documents')->insert(['submission_id' => $id, 'document_type_id' => $type, 'document_version_id' => $document, 'created_at' => now()]);
        $this->send($id, 'forward', extra: ['document_checklist' => [$type => true]])->assertCreated();
    }

    public function test_audit_failure_rolls_back_every_effect(): void
    {
        [$actor, $app, $id, $assignment] = $this->ready();
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into `activity_logs`')) {
                throw new \RuntimeException('Synthetic verification audit failure.');
            }
        });
        $this->send($id, 'request_revision', 'Fiktif')->assertStatus(500);
        $this->assertDatabaseMissing('verification_results', ['submission_id' => $id]);
        $this->assertDatabaseMissing('application_status_histories', ['application_id' => $app]);
        $this->assertDatabaseHas('loan_applications', ['id' => $app, 'status' => 'pending_verification']);
        $this->assertDatabaseHas('assignments', ['id' => $assignment, 'ended_at' => null]);
    }

    public function test_service_rechecks_actor(): void
    {
        [$actor, $app, $id] = $this->ready();
        foreach ([['is_active' => false], ['is_active' => true, 'role' => 'admin']] as $update) {
            DB::table('users')->where('id', $actor->id)->update($update);
            try {
                app(VerificationActions::class)->store($actor->id, $id, ['result_type' => 'note', 'document_checklist' => []]);
                $this->fail('Stale actor accepted');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
    }
}
