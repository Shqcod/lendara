<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\SubmissionFixtures;
use Tests\TestCase;

class DomainConcurrencyTest extends TestCase
{
    use SubmissionFixtures;

    // Intentionally no DatabaseTransactions: both connections need committed fixtures.
    // Unique synthetic records stay in the test database; no user data is cleaned up.
    private function race(string $mode, int $actor, int $application = 0, int $submission = 0, ?int $secondActor = null): array
    {
        $directory = storage_path('framework/testing/concurrency/'.Str::uuid());
        mkdir($directory, 0777, true);
        if ($mode === 'document') {
            file_put_contents($directory.'/fiktif.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        }
        $processes = [];
        foreach (['a', 'b'] as $worker) {
            $processes[$worker] = new Process([
                PHP_BINARY, base_path('tests/Support/concurrent-worker.php'), $mode,
                (string) ($worker === 'b' ? ($secondActor ?? $actor) : $actor), (string) $application, (string) $submission, $directory, $worker,
            ], base_path(), ['APP_ENV' => 'testing'], timeout: 25);
            $processes[$worker]->start();
        }
        $wait = function (array $files) use ($directory, $processes) {
            $until = microtime(true) + 15;
            foreach ($files as $file) {
                while (! is_file($directory.'/'.$file)) {
                    foreach ($processes as $process) {
                        $process->checkTimeout();
                        if (! $process->isRunning()) {
                            $this->fail('Concurrency worker ended before barrier: '.$process->getErrorOutput());
                        }
                    }
                    if (microtime(true) > $until) {
                        $this->fail('Concurrency barrier timed out.');
                    }
                    usleep(10000);
                    clearstatcache(true, $directory.'/'.$file);
                }
            }
        };
        try {
            $wait(['a.ready', 'b.ready']);
            $this->assertNotSame(file_get_contents($directory.'/a.ready'), file_get_contents($directory.'/b.ready'));
            touch($directory.'/start');
            $wait(['a.inserted', 'b.attempting']);
            $this->assertTrue($processes['b']->isRunning());
            if (in_array($mode, ['claim_http', 'verification_http', 'retry_http', 'approval_http', 'accounts_http', 'deactivate_claim', 'claim_deactivate'], true)) {
                $a = (int) file_get_contents($directory.'/a.ready');
                $b = (int) file_get_contents($directory.'/b.ready');
                $until = microtime(true) + 8;
                do {
                    $waiting = DB::selectOne('SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w
                        JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID
                        JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID
                        WHERE r.PROCESSLIST_ID = ? AND b.PROCESSLIST_ID = ?', [$b, $a])->n;
                    if ($waiting) {
                        break;
                    }
                    if ($mode === 'accounts_http' && ! $processes['b']->isRunning()) {
                        $this->fail('Account worker B ended before lock observation: '.$processes['b']->getOutput().' '.$processes['b']->getErrorOutput());
                    }
                    usleep(20000);
                } while (microtime(true) < $until);
                $this->assertGreaterThan(0, $waiting, 'MySQL must observe B actually waiting for A before A commits.');
                fwrite(STDOUT, 'MySQL observed claim lock wait: '.$b.' waits for '.$a.PHP_EOL);
            }
            touch($directory.'/release');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            fwrite(STDOUT, 'MySQL concurrent '.$mode.': connections '.$results[0]['connection'].'/'.$results[1]['connection'].', results '.$results[0]['result'].'/'.$results[1]['result'].PHP_EOL);

            return $results;
        } finally {
            touch($directory.'/release');
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    public function test_deactivation_and_claim_are_serialized_in_both_orders(): void
    {
        foreach (['deactivate_claim', 'claim_deactivate'] as $mode) {
            $admin = User::factory()->create(['role' => 'admin']);
            $reviewer = User::factory()->create(['role' => 'verifikator']);
            $app = $this->loan(overrides: ['status' => 'pending_verification']);
            $snapshot = $this->submission($app);
            DB::table('loan_applications')->where('id', $app)->update(['current_submission_id' => $snapshot]);
            $results = $this->race($mode, $mode === 'deactivate_claim' ? $admin->id : $reviewer->id, $app, $reviewer->id, $mode === 'deactivate_claim' ? $reviewer->id : $admin->id);
            $this->assertSame($mode === 'deactivate_claim' ? ['changed', 'revoked'] : ['created', 'changed'], array_column($results, 'result'));
            $this->assertDatabaseHas('users', ['id' => $reviewer->id, 'is_active' => false]);
            $this->assertSame(0, DB::table('assignments')->where('application_id', $app)->whereNull('ended_at')->count());
            $this->assertDatabaseHas('loan_applications', ['id' => $app, 'status' => 'pending_verification']);
        }
    }

    public function test_concurrent_admin_creations_with_same_email_create_one_user_and_audit(): void
    {
        $a = User::factory()->create(['role' => 'admin']);
        $results = $this->race('accounts_http', $a->id);
        $this->assertSame(['created', 'email_conflict'], array_column($results, 'result'));
        $logs = DB::table('activity_logs')->where('actor_id', $a->id)->where('action', 'internal_user.created')->get();
        $this->assertCount(1, $logs);
        $this->assertDatabaseHas('users', ['id' => $logs[0]->target_id, 'role' => 'verifikator']);
    }

    public function test_concurrent_approval_at_each_level_commits_only_one_decision(): void
    {
        foreach ([1, 2] as $level) {
            $actor = User::factory()->create(['role' => 'penyetuju_'.$level]);
            $app = $this->loan(overrides: ['status' => 'pending_approval_'.$level]);
            $id = $this->submission($app);
            DB::table('loan_applications')->where('id', $app)->update(['current_submission_id' => $id]);
            $this->assignment($app, $id, ['assigned_to' => $actor->id, 'stage' => 'approval_'.$level]);
            $check = $this->check($id);
            if ($level === 2) {
                $prior = $this->assignment($app, $id, ['stage' => 'approval_1', 'ended_at' => now(), 'end_type' => 'completed']);
                $this->decision($id, $prior, $check);
            }
            $results = $this->race('approval_http', $actor->id, $app, $id);
            $this->assertSame(['created', 'out_of_scope'], array_column($results, 'result'));
            $this->assertSame(1, DB::table('approval_decisions')->where('submission_id', $id)->where('level', $level)->count());
            $this->assertSame(1, DB::table('application_status_histories')->where('application_id', $app)->count());
            $this->assertSame(1, DB::table('activity_logs')->where('actor_id', $actor->id)->where('action', 'approval.approved')->count());
        }
    }

    public function test_concurrent_retry_creates_one_attempt_and_audit(): void
    {
        $actor = User::factory()->create(['role' => 'verifikator']);
        $application = $this->loan(overrides: ['status' => 'pending_verification']);
        $submission = $this->submission($application);
        DB::table('loan_applications')->where('id', $application)->update(['current_submission_id' => $submission]);
        $this->assignment($application, $submission, ['assigned_to' => $actor->id]);
        $this->artisan('ml:register-stub');
        $model = DB::table('model_versions')->where('version_code', 'stub-v0')->value('id');
        $this->check($submission, 'failed', overrides: ['model_version_id' => $model]);
        $results = $this->race('retry_http', $actor->id, $application, $submission);
        $this->assertSame(['created', 'retry_conflict'], array_column($results, 'result'));
        $this->assertSame(2, DB::table('anomaly_checks')->where('submission_id', $submission)->count());
        $this->assertSame(1, DB::table('activity_logs')->where('actor_id', $actor->id)->where('action', 'anomaly.retry_requested')->count());
    }

    public function test_concurrent_verification_commits_one_terminal_result_history_and_audit(): void
    {
        $actor = User::factory()->create(['role' => 'verifikator']);
        $application = $this->loan(overrides: ['status' => 'pending_verification']);
        $submission = $this->submission($application);
        DB::table('loan_applications')->where('id', $application)->update(['current_submission_id' => $submission]);
        $assignment = $this->assignment($application, $submission, ['assigned_to' => $actor->id]);
        $results = $this->race('verification_http', $actor->id, $application, $submission);
        $this->assertSame(['created', 'out_of_scope'], array_column($results, 'result'));
        $this->assertSame(1, DB::table('verification_results')->where('submission_id', $submission)->count());
        $this->assertSame(1, DB::table('application_status_histories')->where('application_id', $application)->count());
        $this->assertSame(1, DB::table('activity_logs')->where('actor_id', $actor->id)->where('action', 'verification.request_revision')->count());
        $this->assertDatabaseHas('assignments', ['id' => $assignment, 'end_type' => 'completed']);
    }

    public function test_concurrent_draft_creation_commits_one_application_and_one_creation_log(): void
    {
        $customer = User::factory()->create();
        $results = $this->race('draft', $customer->id);
        $this->assertSame(['created', 'ACTIVE_APPLICATION_EXISTS'], array_column($results, 'result'));
        $this->assertSame(1, DB::table('loan_applications')->where('customer_id', $customer->id)->count());
        $this->assertSame(1, DB::table('activity_logs')->where('actor_id', $customer->id)->where('action', 'loan_application.created')->count());
    }

    public function test_concurrent_assignment_inserts_commit_only_one_active_assignment(): void
    {
        $application = $this->loan();
        $submission = $this->submission($application);
        $verifier = User::factory()->create(['role' => 'verifikator']);
        $results = $this->race('assignment', $verifier->id, $application, $submission);
        $this->assertSame(['created', 'unique_conflict'], array_column($results, 'result'));
        $this->assertSame(1062, $results[1]['mysql_code']);
        $this->assertSame(1, DB::table('assignments')->where('application_id', $application)->whereNull('ended_at')->count());
    }

    public function test_concurrent_document_uploads_allocate_distinct_versions_without_overwriting_files(): void
    {
        $customer = User::factory()->create();
        $application = $this->loan($customer->id);
        $type = DB::table('document_types')->insertGetId(['code' => 'T'.Str::upper(Str::random(8)), 'name' => 'Berkas Fiktif', 'is_required' => false, 'is_active' => true]);
        $results = $this->race('document', $customer->id, $application, $type);
        $this->assertSame(['created', 'created'], array_column($results, 'result'));
        $rows = DB::table('document_versions')->where('application_id', $application)->orderBy('version_number')->get();
        $this->assertSame([1, 2], $rows->pluck('version_number')->all());
        $this->assertCount(2, $rows->pluck('storage_path')->unique());
        foreach ($rows as $row) {
            $this->assertSame($row->file_hash, hash('sha256', Storage::disk('local')->get($row->storage_path)));
        }
        $this->assertSame(2, DB::table('activity_logs')->where('actor_id', $customer->id)->where('action', 'document_version.created')->count());
    }

    public function test_concurrent_submissions_commit_one_snapshot_check_history_and_submission_log(): void
    {
        $customer = User::factory()->create();
        $application = $this->completeApplication($customer);
        $results = $this->race('submission', $customer->id, $application);
        $this->assertSame(['created', 'SUBMISSION_VERSION_CONFLICT'], array_column($results, 'result'));
        $submission = DB::table('application_submissions')->where('application_id', $application)->sole();
        $this->assertSame(1, $submission->version_number);
        $this->assertSame(1, DB::table('anomaly_checks')->where('submission_id', $submission->id)->count());
        $this->assertSame(1, DB::table('application_status_histories')->where('application_id', $application)->count());
        $this->assertSame(1, DB::table('activity_logs')->where('actor_id', $customer->id)->where('action', 'application_submission.created')->count());
    }

    public function test_concurrent_claim_routes_commit_one_owner_and_one_claim_history_log(): void
    {
        $application = $this->loan(overrides: ['status' => 'pending_verification']);
        $submission = $this->submission($application);
        DB::table('loan_applications')->where('id', $application)->update(['current_submission_id' => $submission]);
        $first = User::factory()->create(['role' => 'verifikator']);
        $second = User::factory()->create(['role' => 'verifikator']);
        $results = $this->race('claim_http', $first->id, $application, $submission, $second->id);
        $this->assertSame([201, 409], array_column($results, 'http_status'));
        $this->assertSame(['created', 'ASSIGNMENT_ALREADY_CLAIMED'], array_column($results, 'result'));
        $assignment = DB::table('assignments')->where('application_id', $application)->sole();
        $this->assertSame($first->id, $assignment->assigned_to);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'assignment.claimed')->where('target_id', $assignment->id)->count());
        $this->assertSame(0, DB::table('application_status_histories')->where('application_id', $application)->count());
    }
}
