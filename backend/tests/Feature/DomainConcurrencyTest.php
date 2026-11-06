<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class DomainConcurrencyTest extends TestCase
{
    use DomainFixtures;

    // Intentionally no DatabaseTransactions: both connections need committed fixtures.
    // Unique synthetic records stay in the test database; no user data is cleaned up.
    private function race(string $mode, int $actor, int $application = 0, int $submission = 0): array
    {
        $directory = storage_path('framework/testing/concurrency/'.Str::uuid());
        mkdir($directory, 0777, true);
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
}
