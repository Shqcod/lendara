<?php

namespace Tests\Feature;

use App\Jobs\CleanupDraftDocuments;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class CleanupCrashTest extends TestCase
{
    use DomainFixtures;

    public function test_crashes_before_and_after_cleanup_enqueue_preserve_atomicity_and_durable_recovery(): void
    {
        $original = config('filesystems.disks.local.root');
        try {
            foreach (['compensation-before', 'compensation-after', 'draft-before', 'draft-after'] as $mode) {
                $run = (string) Str::uuid();
                $directory = storage_path('framework/testing/cleanup-crash/'.$run);
                mkdir($directory.'/disk', 0777, true);
                config(['filesystems.disks.local.root' => $directory.'/disk']);
                Storage::forgetDisk('local');
                $disk = Storage::disk('local');
                $user = User::factory()->create();
                $application = $this->loan($user->id);
                if (str_starts_with($mode, 'draft-')) {
                    $document = $this->document($application);
                    $disk->put(DB::table('document_versions')->where('id', $document)->value('storage_path'), 'fiktif');
                }
                $process = new Process([PHP_BINARY, base_path('tests/Support/cleanup-crash-worker.php'), $run, $mode, (string) $user->id, (string) $application], base_path(), ['APP_ENV' => 'testing'], timeout: 40);
                $process->start();
                try {
                    $until = microtime(true) + 20;
                    while (! is_file($directory.'/ready')) {
                        $process->checkTimeout();
                        if (! $process->isRunning() || microtime(true) > $until) {
                            $this->fail('Owned cleanup worker did not reach barrier');
                        }
                        usleep(10000);
                        clearstatcache();
                    }
                    $state = json_decode(file_get_contents($directory.'/ready'), true);
                    $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $state['connection']);
                    $this->assertSame($mode === 'draft-before' ? 1 : 0, $state['transaction_level']);
                    $paths = $disk->allFiles('loan-documents');
                    $this->assertCount(1, $paths);
                    $ownedJobs = fn () => DB::table('jobs')->where('queue', 'document-cleanup')->get()->filter(fn ($job) => in_array($paths[0], unserialize(json_decode($job->payload, true)['data']['command'], ['allowed_classes' => [CleanupDraftDocuments::class]])->paths, true));
                    $durable = in_array($mode, ['compensation-after', 'draft-after'], true);
                    $this->assertCount($durable ? 1 : 0, $ownedJobs());
                    $process->stop(0);
                    $this->assertFalse($process->isRunning());
                    // Reacquire the parent row to prove the interrupted transaction released its locks.
                    DB::transaction(fn () => DB::table('loan_applications')->where('id', $application)->lockForUpdate()->first());
                    $this->assertSame($mode === 'draft-after' ? 0 : 1, DB::table('loan_applications')->where('id', $application)->count());
                    $this->assertSame($mode === 'draft-before' ? 1 : 0, DB::table('document_versions')->where('application_id', $application)->count());
                    $this->assertTrue($disk->exists($paths[0]));
                    $this->assertCount($durable ? 1 : 0, $ownedJobs());
                    if ($durable) {
                        $job = $ownedJobs()->sole();
                        $this->assertSame(1, DB::table('jobs')->where('id', $job->id)->whereNull('reserved_at')->update(['queue' => 'cleanup-crash-'.$run]));
                        $worker = new Process([PHP_BINARY, base_path('tests/Support/cleanup-crash-worker.php'), $run, 'consume', (string) $user->id, (string) $application], base_path(), ['APP_ENV' => 'testing'], timeout: 20);
                        $worker->run();
                        $this->assertTrue($worker->isSuccessful());
                        $this->assertFalse($disk->exists($paths[0]));
                        $this->assertFalse(DB::table('jobs')->where('id', $job->id)->exists());
                    }
                    fwrite(STDOUT, 'Cleanup crash '.$mode.': separate connections, durable_job='.($durable ? 'yes' : 'no').', owned fixture verified'.PHP_EOL);
                } finally {
                    $process->stop(0);
                }
            }
        } finally {
            config(['filesystems.disks.local.root' => $original]);
            Storage::forgetDisk('local');
        }
    }
}
