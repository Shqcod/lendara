<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApprovalActions;
use App\Services\ReviewAssignments;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class ApprovalActionsTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    private function ready(int $level = 1, bool $check = true): array
    {
        $actor = User::factory()->create(['role' => 'penyetuju_'.$level]);
        $app = $this->loan(overrides: ['status' => 'pending_approval_'.$level]);
        $id = $this->submission($app);
        DB::table('loan_applications')->where('id', $app)->update(['current_submission_id' => $id]);
        $assignment = $this->assignment($app, $id, ['assigned_to' => $actor->id, 'stage' => 'approval_'.$level]);
        $checkId = $check ? $this->check($id) : null;
        $this->login($actor);

        return [$actor, $app, $id, $assignment, $checkId];
    }

    private function login(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->withHeader('Origin', 'http://localhost:5173');
    }

    private function send(int $id, string $decision = 'approved', ?string $notes = null, array $extra = [])
    {
        return $this->postJson('/api/v1/internal/submissions/'.$id.'/approval-decisions', array_replace(['decision' => $decision, 'notes' => $notes], $extra));
    }

    public function test_two_levels_approve_in_order_and_keep_snapshot_immutable(): void
    {
        [$actor, $app, $id, $task, $check] = $this->ready();
        $before = (array) DB::table('application_submissions')->find($id);
        $this->send($id, notes: 'Catatan internal tingkat 1')->assertCreated()->assertJsonPath('data.application_status', 'pending_approval_2')->assertJsonPath('data.level', 1);
        $this->assertDatabaseHas('assignments', ['id' => $task, 'end_type' => 'completed', 'ended_by' => $actor->id]);
        $this->send($id)->assertNotFound();
        $l2 = User::factory()->create(['role' => 'penyetuju_2']);
        $this->login($l2);
        $this->getJson('/api/v1/internal/applications/'.$app)->assertOk()->assertJsonPath('data.current_submission.approval_decisions.0.notes', 'Catatan internal tingkat 1');
        app(ReviewAssignments::class)->claim($l2->id, $app, $id);
        $this->send($id)->assertCreated()->assertJsonPath('data.application_status', 'approved')->assertJsonPath('data.level', 2);
        $this->assertSame(2, DB::table('approval_decisions')->where('submission_id', $id)->count());
        $this->assertSame($before, (array) DB::table('application_submissions')->find($id));
        $this->assertDatabaseHas('approval_decisions', ['submission_id' => $id, 'level' => 2, 'anomaly_check_id' => $check]);
        $this->login(User::findOrFail(DB::table('loan_applications')->where('id', $app)->value('customer_id')));
        $this->getJson('/api/v1/applications/'.$app.'/status-histories')->assertOk()->assertJsonMissingPath('data.0.notes')->assertJsonPath('data.0.revision_reason', null);
        $r = $this->getJson('/api/v1/applications/'.$app.'/submissions/'.$id)->assertOk()->assertJsonMissingPath('data.approval_decisions');
        $this->assertStringNotContainsString('Catatan internal tingkat 1', $r->getContent());
        $this->postJson('/api/v1/applications')->assertCreated();
    }

    public function test_both_levels_reject_with_required_reason_and_final_status(): void
    {
        foreach ([1, 2] as $level) {
            [$actor, $app, $id, $task, $check] = $this->ready($level);
            if ($level === 2) {
                $prior = $this->assignment($app, $id, ['stage' => 'approval_1', 'ended_at' => now(), 'end_type' => 'completed']);
                $this->decision($id, $prior, $check);
            }
            $this->send($id, 'rejected', '   ')->assertUnprocessable()->assertJsonValidationErrors('notes');
            $this->send($id, 'rejected', 'Alasan internal fiktif')->assertCreated()->assertJsonPath('data.application_status', 'rejected');
            $this->send($id, 'rejected', 'Duplikat')->assertNotFound();
            $this->assertDatabaseHas('application_status_histories', ['application_id' => $app, 'from_status' => 'pending_approval_'.$level, 'to_status' => 'rejected']);
            $this->assertDatabaseHas('assignments', ['id' => $task, 'end_type' => 'completed']);
            $this->login(User::findOrFail(DB::table('loan_applications')->where('id', $app)->value('customer_id')));
            $public = $this->getJson('/api/v1/applications/'.$app.'/status-histories')->assertOk()->assertJsonPath('data.0.revision_reason', null);
            $this->assertStringNotContainsString('Alasan internal fiktif', $public->getContent());

        }
    }

    public function test_level_two_requires_approved_level_one_on_same_snapshot_even_for_rejection(): void
    {
        [$actor, $app, $id, $task, $check] = $this->ready(2);
        $this->send($id)->assertConflict()->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
        $this->send($id, 'rejected', 'Fiktif')->assertConflict();
        $old = $this->submission($app, 2);
        $oldCheck = $this->check($old);
        $prior = $this->assignment($app, $old, ['stage' => 'approval_1', 'ended_at' => now(), 'end_type' => 'completed']);
        $this->decision($old, $prior, $oldCheck);
        $this->send($id)->assertConflict();
    }

    public function test_scope_owner_version_fields_and_no_revision_action(): void
    {
        [$actor, $app, $id, $task] = $this->ready();
        foreach (['level', 'approver_id', 'anomaly_check_id', 'assignment_id', 'status'] as $field) {
            $this->send($id, extra: [$field => 1])->assertUnprocessable();
        }
        $this->send($id, 'request_revision')->assertUnprocessable();
        $old = $this->submission($app, 2);
        $this->send($old)->assertConflict();
        foreach (['nasabah', 'admin', 'verifikator', 'penyetuju_1'] as $role) {
            $this->login(User::factory()->create(['role' => $role]));
            $this->send($id)->assertForbidden();
        }
        $this->login(User::factory()->create(['role' => 'penyetuju_2']));
        $this->send($id)->assertNotFound();
        $this->login($actor);
        DB::table('assignments')->where('id', $task)->update(['ended_at' => now(), 'end_type' => 'released']);
        $this->send($id)->assertForbidden();
    }

    public function test_result_required_and_audit_failure_rolls_back_all_effects(): void
    {
        [$actor, $app, $id, $task] = $this->ready(check: false);
        $this->send($id)->assertConflict()->assertJsonPath('code', 'ANOMALY_CHECK_NOT_READY');
        $this->send($id, 'rejected', 'Fiktif')->assertConflict();
        $this->check($id);
        DB::listen(function ($q) {
            if (str_starts_with($q->sql, 'insert into `activity_logs`')) {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->send($id)->assertStatus(500);
        $this->assertDatabaseMissing('approval_decisions', ['submission_id' => $id]);
        $this->assertDatabaseMissing('application_status_histories', ['application_id' => $app]);
        $this->assertDatabaseHas('loan_applications', ['id' => $app, 'status' => 'pending_approval_1']);
        $this->assertDatabaseHas('assignments', ['id' => $task, 'ended_at' => null]);
    }

    public function test_locked_actor_is_rechecked(): void
    {
        [$actor, $app, $id] = $this->ready();
        foreach ([['is_active' => false], ['is_active' => true, 'role' => 'admin']] as $values) {
            DB::table('users')->where('id', $actor->id)->update($values);
            try {
                app(ApprovalActions::class)->store($actor->id, $id, ['decision' => 'approved']);
                $this->fail('Stale actor');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }
}
