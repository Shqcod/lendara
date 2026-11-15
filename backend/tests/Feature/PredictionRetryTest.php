<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PredictionRetries;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class PredictionRetryTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    private function ready(): array
    {
        $actor = User::factory()->create(['role' => 'verifikator']);
        $app = $this->loan(overrides: ['status' => 'pending_verification']);
        $id = $this->submission($app);
        DB::table('loan_applications')->where('id', $app)->update(['current_submission_id' => $id]);
        $assignment = $this->assignment($app, $id, ['assigned_to' => $actor->id]);
        $this->artisan('ml:register-stub');
        $model = DB::table('model_versions')->where('version_code', 'stub-v0')->value('id');
        $check = $this->check($id, 'failed', overrides: ['model_version_id' => $model]);
        $this->login($actor);

        return [$actor, $app, $id, $assignment, $check];
    }

    private function login(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->withHeader('Origin', 'http://localhost:5173');
    }

    private function url(int $id): string
    {
        return '/api/v1/internal/submissions/'.$id.'/anomaly-checks';
    }

    public function test_retry_preserves_snapshot_prior_check_and_task_and_pins_inputs(): void
    {
        [$actor, $app, $id, $assignment, $check] = $this->ready();
        $snapshot = (array) DB::table('application_submissions')->find($id);
        $old = (array) DB::table('anomaly_checks')->find($check);
        $new = $this->postJson($this->url($id))->assertStatus(202)->assertJsonPath('data.attempt_number', 2)->json('data.id');
        $this->assertSame($snapshot, (array) DB::table('application_submissions')->find($id));
        $this->assertSame($old, (array) DB::table('anomaly_checks')->find($check));
        $this->assertSame($old['input_features'], DB::table('anomaly_checks')->where('id', $new)->value('input_features'));
        $this->assertDatabaseHas('anomaly_checks', ['id' => $new, 'model_version_id' => $old['model_version_id'], 'requested_by' => $actor->id, 'status' => 'processing']);
        $this->assertDatabaseHas('assignments', ['id' => $assignment, 'ended_at' => null]);
        $this->assertDatabaseMissing('application_status_histories', ['application_id' => $app]);
        $this->postJson($this->url($id))->assertConflict()->assertJsonPath('code', 'ANOMALY_RETRY_NOT_ALLOWED');
        $this->getJson($this->url($id))->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.input_features');
        $this->login(User::findOrFail(DB::table('loan_applications')->where('id', $app)->value('customer_id')));
        $this->getJson($this->url($id))->assertForbidden();
        $this->getJson('/api/v1/applications/'.$app.'/submissions/'.$id)->assertOk()->assertJsonMissingPath('data.anomaly_checks');
    }

    public function test_roles_ownership_scope_version_and_forbidden_client_fields(): void
    {
        [$actor, $app, $id, $assignment] = $this->ready();
        $this->postJson($this->url($id), ['model_version_id' => 1])->assertUnprocessable();
        $old = $this->submission($app, 2);
        $this->postJson($this->url($old))->assertConflict();
        foreach (['admin', 'nasabah', 'penyetuju_1', 'penyetuju_2', 'verifikator'] as $role) {
            $this->login(User::factory()->create(['role' => $role]));
            $this->postJson($this->url($id))->assertForbidden();
        }
        $this->login($actor);
        DB::table('assignments')->where('id', $assignment)->update(['ended_at' => now(), 'end_type' => 'released']);
        $this->postJson($this->url($id))->assertForbidden();
    }

    public function test_success_processing_missing_and_wrong_mode_cannot_retry(): void
    {
        [$actor, $app, $id] = $this->ready();
        config(['services.ml.mode' => 'model']);
        $this->postJson($this->url($id))->assertStatus(503);
        config(['services.ml.mode' => 'stub']);
        $this->check($id, 'succeeded', 2);
        $this->postJson($this->url($id))->assertConflict();
    }

    public function test_audit_failure_rolls_back_attempt_and_actor_is_rechecked(): void
    {
        [$actor, $app, $id] = $this->ready();
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        try {
            app(PredictionRetries::class)->retry($actor->id, $id);
            $this->fail('Inactive accepted');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        DB::listen(function ($q) {
            if (str_starts_with($q->sql, 'insert into `activity_logs`')) {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->postJson($this->url($id))->assertStatus(500);
        $this->assertSame(1, DB::table('anomaly_checks')->where('submission_id', $id)->count());
    }
}
