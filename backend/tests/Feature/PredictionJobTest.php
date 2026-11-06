<?php

namespace Tests\Feature;

use App\Jobs\RunPrediction;
use App\Services\PredictionClient;
use App\Services\PredictionResults;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class PredictionJobTest extends TestCase
{
    use DomainFixtures;

    private function pending(): int
    {
        config(['services.ml.key' => 'synthetic-test-key', 'services.ml.mode' => 'stub']);
        $application = $this->loan(overrides: ['status' => 'pending_verification']);
        $submission = $this->submission($application);
        DB::table('loan_applications')->where('id', $application)->update(['current_submission_id' => $submission]);

        return $this->check($submission, 'processing');
    }

    private function response(int $id, array $extra = []): array
    {
        $check = DB::table('anomaly_checks')->where('id', $id)->sole();

        return array_replace(['contract_version' => '1', 'feature_schema' => 'loan-finance-v1', 'anomaly_check_id' => $id,
            'submission_id' => $check->submission_id, 'model_version_code' => DB::table('model_versions')->where('id', $check->model_version_id)->value('version_code'),
            'source' => 'stub', 'score_method' => 'decision_function', 'decision_threshold' => 0.0, 'score' => 0.08, 'is_anomaly' => false], $extra);
    }

    private function executeJob(int $id): void
    {
        (new RunPrediction($id))->handle(app(PredictionClient::class), app(PredictionResults::class));
    }

    public function test_job_requests_pinned_model_outside_database_transaction_and_does_not_make_an_automatic_decision(): void
    {
        $id = $this->pending();
        $response = $this->response($id, ['score' => -0.12, 'is_anomaly' => true]);
        Http::fake(function ($request) use ($response) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($response['model_version_code'], $request['model_version_code']);

            return Http::response($response);
        });
        $this->executeJob($id);
        $this->executeJob($id);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('anomaly_checks', ['id' => $id, 'status' => 'succeeded', 'is_anomaly' => true]);
        $application = DB::table('application_submissions')->where('id', $response['submission_id'])->value('application_id');
        $this->assertDatabaseHas('loan_applications', ['id' => $application, 'status' => 'pending_verification']);
    }

    public function test_connection_timeout_or_failed_service_has_one_http_attempt_and_safe_failure(): void
    {
        $id = $this->pending();
        Http::fake(fn () => throw new ConnectionException('Synthetic connection timeout.'));
        $this->executeJob($id);
        $this->assertDatabaseHas('anomaly_checks', ['id' => $id, 'status' => 'failed', 'failure_reason' => 'prediction_failed', 'score' => null]);
        $id2 = $this->pending();
        Http::fake(fn () => Http::response(['code' => 'PREDICTION_UNAVAILABLE'], 503));
        $this->executeJob($id2);
        $this->assertDatabaseHas('anomaly_checks', ['id' => $id2, 'status' => 'failed']);
    }

    public function test_expired_or_insufficient_budget_makes_no_http_request_and_scheduler_recovers_lost_jobs(): void
    {
        $id = $this->pending();
        DB::table('anomaly_checks')->where('id', $id)->update(['deadline_at' => now()->addSeconds(5)]);
        Http::fake();
        $this->executeJob($id);
        Http::assertNothingSent();
        $this->assertDatabaseHas('anomaly_checks', ['id' => $id, 'status' => 'failed', 'failure_reason' => 'deadline_expired']);
        $lost = $this->pending();
        $this->travel(121)->seconds();
        $this->artisan('anomalies:expire')->assertExitCode(0);
        $this->assertDatabaseHas('anomaly_checks', ['id' => $lost, 'status' => 'failed', 'failure_reason' => 'deadline_expired']);
        app(PredictionResults::class)->store($lost, $this->response($lost));
        $this->assertDatabaseHas('anomaly_checks', ['id' => $lost, 'status' => 'failed', 'score' => null]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'anomaly.response_ignored', 'target_id' => $lost]);
        $this->travelBack();
    }

    public function test_response_after_deadline_and_duplicate_or_wrong_identity_cannot_overwrite_terminal_state(): void
    {
        $id = $this->pending();
        Http::fake(function () use ($id) {
            $this->travel(121)->seconds();

            return Http::response($this->response($id));
        });
        $this->executeJob($id);
        $this->assertDatabaseHas('anomaly_checks', ['id' => $id, 'status' => 'failed', 'failure_reason' => 'deadline_expired']);
        $this->travelBack();
        foreach ([['submission_id' => 0], ['model_version_code' => 'wrong'], ['score' => INF], ['is_anomaly' => true]] as $invalid) {
            $current = $this->pending();
            app(PredictionResults::class)->store($current, $this->response($current, $invalid));
            $this->assertDatabaseHas('anomaly_checks', ['id' => $current, 'status' => 'failed', 'failure_reason' => 'invalid_response']);
        }
        $successful = $this->pending();
        app(PredictionResults::class)->store($successful, $this->response($successful));
        app(PredictionResults::class)->store($successful, $this->response($successful, ['score' => -0.12, 'is_anomaly' => true]));
        $this->assertDatabaseHas('anomaly_checks', ['id' => $successful, 'status' => 'succeeded', 'score' => 0.08, 'is_anomaly' => false]);
    }

    public function test_obsolete_snapshot_and_database_execution_lock_prevent_stale_or_duplicate_http(): void
    {
        $id = $this->pending();
        $check = DB::table('anomaly_checks')->where('id', $id)->sole();
        $application = DB::table('application_submissions')->where('id', $check->submission_id)->value('application_id');
        $new = $this->submission($application, 2);
        DB::table('loan_applications')->where('id', $application)->update(['current_submission_id' => $new]);
        Http::fake();
        $this->executeJob($id);
        Http::assertNothingSent();
        $this->assertDatabaseHas('anomaly_checks', ['id' => $id, 'status' => 'failed', 'failure_reason' => 'obsolete_snapshot']);
        $duplicate = $this->pending();
        $lock = Cache::store('database')->lock('anomaly-check:'.$duplicate, 60);
        $this->assertTrue($lock->get());
        try {
            $this->executeJob($duplicate);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_real_scheduler_subprocess_recovers_an_expired_check_on_the_testing_database(): void
    {
        $id = $this->pending();
        DB::table('anomaly_checks')->where('id', $id)->update(['started_at' => now()->subSeconds(121), 'deadline_at' => now()->subSecond()]);
        $this->artisan('schedule:run')->assertExitCode(0);
        $this->assertDatabaseHas('anomaly_checks', ['id' => $id, 'status' => 'failed', 'failure_reason' => 'deadline_expired']);
    }
}
