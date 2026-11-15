<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PredictionClient;
use App\Services\PredictionResults;
use App\Services\PredictionRetries;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class PredictionRetryCommitTest extends TestCase
{
    use DomainFixtures;

    public function test_dispatch_failure_after_commit_preserves_accepted_response_and_new_attempt(): void
    {
        $actor = User::factory()->create(['role' => 'verifikator']);
        $app = $this->loan(overrides: ['status' => 'pending_verification']);
        $id = $this->submission($app);
        DB::table('loan_applications')->where('id', $app)->update(['current_submission_id' => $id]);
        $this->assignment($app, $id, ['assigned_to' => $actor->id]);
        $this->artisan('ml:register-stub');
        $model = DB::table('model_versions')->where('version_code', 'stub-v0')->value('id');
        $old = $this->check($id, 'failed', overrides: ['model_version_id' => $model]);
        config(['database.connections.retry_observer' => config('database.connections.mysql')]);
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function ($job) use ($id) {
            $this->assertSame(0, DB::transactionLevel());
            $observer = DB::connection('retry_observer');
            $this->assertSame('kkp_pinjaman_test', $observer->selectOne('SELECT DATABASE() AS name')->name);
            $this->assertTrue($observer->table('anomaly_checks')->where('id', $job->checkId)->where('submission_id', $id)->where('status', 'processing')->exists());
            throw new \RuntimeException('Synthetic dispatch failure');
        });
        try {
            $this->actingAs($actor, 'web')->withHeader('Origin', 'http://localhost:5173');
            $new = $this->postJson('/api/v1/internal/submissions/'.$id.'/anomaly-checks')->assertStatus(202)->json('data.id');
            $this->assertDatabaseHas('anomaly_checks', ['id' => $new, 'attempt_number' => 2, 'status' => 'failed', 'failure_reason' => 'dispatch_failed']);
            $this->assertDatabaseHas('anomaly_checks', ['id' => $old, 'status' => 'failed', 'failure_reason' => 'fixture_failure']);
        } finally {
            DB::disconnect('retry_observer');
        }
    }

    public function test_persisted_retry_job_succeeds_with_fake_http_and_old_result_cannot_change_new_attempt(): void
    {
        $actor = User::factory()->create(['role' => 'verifikator']);
        $app = $this->loan(overrides: ['status' => 'pending_verification']);
        $id = $this->submission($app);
        DB::table('loan_applications')->where('id', $app)->update(['current_submission_id' => $id]);
        $this->assignment($app, $id, ['assigned_to' => $actor->id]);
        $this->artisan('ml:register-stub');
        $model = DB::table('model_versions')->where('version_code', 'stub-v0')->value('id');
        $old = $this->check($id, 'failed', overrides: ['model_version_id' => $model]);

        $new = app(PredictionRetries::class)->retry($actor->id, $id)['id'];
        $job = DB::table('jobs')->where('queue', 'predictions')->orderByDesc('id')->first();
        $command = unserialize(json_decode($job->payload, true)['data']['command']);
        $this->assertSame($new, $command->checkId);
        $response = ['contract_version' => '1', 'feature_schema' => 'loan-finance-v1', 'anomaly_check_id' => $new,
            'submission_id' => $id, 'model_version_code' => 'stub-v0', 'source' => 'stub', 'score_method' => 'decision_function',
            'decision_threshold' => 0.0, 'score' => 0.08, 'is_anomaly' => false];
        config(['services.ml.key' => 'synthetic-test-key']);
        Http::fake(function ($request) use ($response) {
            $this->assertSame(0, DB::transactionLevel());

            return Http::response($response);
        });
        app(PredictionResults::class)->store($old, array_replace($response, ['anomaly_check_id' => $old]));
        $this->assertDatabaseHas('anomaly_checks', ['id' => $new, 'status' => 'processing']);
        $command->handle(app(PredictionClient::class), app(PredictionResults::class));
        Http::assertSentCount(1);
        $this->assertDatabaseHas('anomaly_checks', ['id' => $new, 'status' => 'succeeded']);
        $this->assertDatabaseHas('anomaly_checks', ['id' => $old, 'status' => 'failed']);
        $this->assertDatabaseHas('loan_applications', ['id' => $app, 'status' => 'pending_verification', 'current_submission_id' => $id]);
    }
}
