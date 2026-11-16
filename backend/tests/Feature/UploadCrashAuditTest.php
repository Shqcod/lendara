<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class UploadCrashAuditTest extends TestCase
{
    use DomainFixtures;

    // Committed fictional fixtures intentionally remain on the separate test DB/disk.
    public function test_real_process_interruption_and_uncommitted_references_never_make_the_audit_delete(): void
    {
        $original = config('filesystems.disks.local.root');
        try {
            foreach (['file', 'pending-kill', 'pending-commit', 'committed'] as $mode) {
                $run = (string) Str::uuid();
                $directory = storage_path('framework/testing/upload-crash/'.$run);
                mkdir($directory.'/disk', 0777, true);
                config(['filesystems.disks.local.root' => $directory.'/disk']);
                Storage::forgetDisk('local');
                $disk = Storage::disk('local');
                $user = User::factory()->create();
                $application = $this->loan($user->id);
                $jobs = DB::table('jobs')->count();
                $process = new Process([PHP_BINARY, base_path('tests/Support/upload-crash-worker.php'), $run, $mode, (string) $user->id, (string) $application], base_path(), ['APP_ENV' => 'testing'], timeout: 40);
                $process->start();
                $wait = function (string $phase) use ($directory, $process) {
                    $until = microtime(true) + 20;
                    while (! is_file($directory.'/'.$phase.'.ready')) {
                        $process->checkTimeout();
                        if (! $process->isRunning() || microtime(true) > $until) {
                            $this->fail('Owned crash worker did not reach barrier.');
                        }
                        usleep(10000);
                        clearstatcache();
                    }

                    return json_decode(file_get_contents($directory.'/'.$phase.'.ready'), true, flags: JSON_THROW_ON_ERROR);
                };
                try {
                    $phase = $mode === 'file' ? 'file' : (str_starts_with($mode, 'pending-') ? 'pending' : 'committed');
                    $state = $wait($phase);
                    $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $state['connection']);
                    $this->assertSame($phase === 'committed' ? 0 : 1, $state['transaction_level']);
                    $paths = $disk->allFiles('loan-documents');
                    $this->assertCount(1, $paths);
                    $this->artisan('documents:audit-orphans')->expectsOutputToContain($phase === 'committed' ? '"referenced":1' : '"within_grace":1')->assertExitCode(0);
                    // Model an unusually old in-flight write: age alone cannot prove safe deletion.
                    touch($disk->path($paths[0]), time() - 172800);
                    if ($phase !== 'committed') {
                        $this->assertSame(0, DB::table('document_versions')->where('application_id', $application)->count());
                        $this->artisan('documents:audit-orphans')->expectsOutputToContain('"unreferenced_observations":1')->assertExitCode(0);
                    }
                    if ($mode === 'pending-commit') {
                        touch($directory.'/pending.release');
                        $wait('committed');
                    }
                    // Stop only this invocation's child; no release/exception-driven compensator runs.
                    $process->stop(0);
                    $this->assertFalse($process->isRunning());
                    $expected = in_array($mode, ['pending-commit', 'committed'], true) ? 1 : 0;
                    $until = microtime(true) + 5;
                    do {
                        $rows = DB::table('document_versions')->where('application_id', $application)->count();
                        if ($rows === $expected) {
                            break;
                        }
                        usleep(20000);
                    } while (microtime(true) < $until);
                    $this->assertSame($expected, $rows);
                    $this->assertSame($jobs, DB::table('jobs')->count());
                    $this->assertTrue($disk->exists($paths[0]));
                    $this->artisan('documents:audit-orphans')->expectsOutputToContain($expected ? '"referenced":1' : '"unreferenced_observations":1')->assertExitCode(0);
                    $this->assertTrue($disk->exists($paths[0]));
                    fwrite(STDOUT, 'Upload crash/audit '.$mode.': distinct connections, child stopped, metadata='.$rows.', file retained'.PHP_EOL);
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
