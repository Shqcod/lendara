<?php

namespace Tests\Feature;

use App\Jobs\RunPrediction;
use App\Models\User;
use App\Services\CustomerSubmissions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\SubmissionFixtures;
use Tests\TestCase;

class SubmissionCommitTest extends TestCase
{
    use SubmissionFixtures;

    public function test_enqueue_happens_after_commit_and_persists_a_database_job_visible_to_another_connection(): void
    {
        $customer = User::factory()->create();
        $application = $this->completeApplication($customer);
        $submission = app(CustomerSubmissions::class)->submit($customer->id, $application, null);
        $this->assertSame(0, DB::transactionLevel());
        $check = DB::table('anomaly_checks')->where('submission_id', $submission->id)->sole();
        $job = DB::table('jobs')->where('queue', 'predictions')->orderByDesc('id')->first();
        $this->assertNotNull($job);
        $command = unserialize(json_decode($job->payload, true)['data']['command']);
        $this->assertInstanceOf(RunPrediction::class, $command);
        $this->assertSame($check->id, $command->checkId);
        config(['database.connections.submission_observer' => config('database.connections.mysql')]);
        try {
            $observer = DB::connection('submission_observer');
            $this->assertSame('kkp_pinjaman_test', $observer->selectOne('SELECT DATABASE() AS name')->name);
            $this->assertTrue($observer->table('jobs')->where('id', $job->id)->exists());
            $this->assertTrue($observer->table('application_submissions')->where('id', $submission->id)->exists());
            $this->assertTrue($observer->table('anomaly_checks')->where('id', $check->id)->where('status', 'processing')->exists());
        } finally {
            DB::disconnect('submission_observer');
        }
    }

    public function test_failed_enqueue_is_compensated_after_commit_without_turning_the_accepted_submission_into_a_failure(): void
    {
        $customer = User::factory()->create();
        $application = $this->completeApplication($customer);
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function ($job) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseHas('anomaly_checks', ['id' => $job->checkId, 'status' => 'processing']);
            DB::listen(function ($query) {
                if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '`document_versions`')) {
                    throw new RuntimeException('The accepted response must not reload documents after commit.');
                }
            });
            throw new RuntimeException('Synthetic queue unavailable after commit.');
        });
        $this->withHeader('Origin', 'http://localhost:5173')->actingAs($customer, 'web');
        $id = $this->postJson('/api/v1/applications/'.$application.'/submissions', ['confirmed' => true, 'expected_current_submission_id' => null])
            ->assertStatus(202)->json('data.id');
        $this->assertDatabaseHas('loan_applications', ['id' => $application, 'status' => 'pending_verification', 'current_submission_id' => $id]);
        $this->assertDatabaseHas('anomaly_checks', ['submission_id' => $id, 'status' => 'failed', 'failure_reason' => 'dispatch_failed']);
    }
}
