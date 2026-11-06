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
    private function race(string $mode, int $actor, int $application = 0, int $submission = 0): array
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
                (string) $actor, (string) $application, (string) $submission, $directory, $worker,
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
}
