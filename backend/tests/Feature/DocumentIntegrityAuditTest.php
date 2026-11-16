<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class DocumentIntegrityAuditTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    private function fixture(string $contents): array
    {
        $id = $this->document($this->loan(), ['file_size_bytes' => strlen($contents), 'file_hash' => hash('sha256', $contents)]);
        $path = DB::table('document_versions')->where('id', $id)->value('storage_path');
        Storage::disk('local')->put($path, $contents);

        return [$id, $path];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function audit(): array
    {
        // Existing test fixtures may be absent from this isolated disk; assert our deltas below.
        $exit = Artisan::call('documents:audit-integrity');
        $output = Artisan::output();
        $this->assertStringNotContainsString('loan-documents/', $output);
        $this->assertStringNotContainsString('@', $output);

        return [$exit, json_decode(explode("\n", trim($output))[0], true, flags: JSON_THROW_ON_ERROR)];
    }

    public function test_hash_and_size_are_checked_without_modifying_files_or_metadata(): void
    {
        $before = $this->audit()[1]['counts'];
        [$id, $path] = $this->fixture(str_repeat('fiktif-', 20000));
        $record = DB::table('document_versions')->where('id', $id)->first();
        $jobs = DB::table('jobs')->count();
        [, $after] = $this->audit();
        $this->assertSame($before['verified'] + 1, $after['counts']['verified']);
        $this->assertSame((array) $record, (array) DB::table('document_versions')->where('id', $id)->first());
        $this->assertSame($jobs, DB::table('jobs')->count());
        $this->assertSame(str_repeat('fiktif-', 20000), Storage::disk('local')->get($path));
    }

    public function test_same_size_corruption_and_truncation_are_detected_and_preserved(): void
    {
        $before = $this->audit()[1]['counts'];
        [, $corrupt] = $this->fixture('abcdef');
        Storage::disk('local')->put($corrupt, 'ghijkl');
        [, $short] = $this->fixture('abcdef');
        Storage::disk('local')->put($short, 'abc');
        [$exit, $after] = $this->audit();
        $this->assertSame(1, $exit);
        $this->assertSame($before['hash_mismatch'] + 2, $after['counts']['hash_mismatch']);
        $this->assertSame($before['size_mismatch'] + 1, $after['counts']['size_mismatch']);
        $this->assertSame('ghijkl', Storage::disk('local')->get($corrupt));
        $this->assertSame('abc', Storage::disk('local')->get($short));
    }

    public function test_missing_file_is_reported_and_unsafe_reference_fails_without_paths(): void
    {
        $before = $this->audit()[1]['counts'];
        [$id, $path] = $this->fixture('fiktif');
        Storage::disk('local')->delete($path); // Only this test's fake fixture.
        [$exit, $after] = $this->audit();
        $this->assertSame(1, $exit);
        $this->assertSame($before['unavailable'] + 1, $after['counts']['unavailable']);
        DB::table('document_versions')->where('id', $id)->update(['storage_path' => '../private-secret']);
        $this->artisan('documents:audit-integrity')->expectsOutputToContain('Audit integritas tidak lengkap')->doesntExpectOutputToContain('private-secret')->assertExitCode(1);
    }

    public function test_storage_failure_never_returns_a_partial_success_report(): void
    {
        Storage::shouldReceive('disk')->once()->with('local')->andThrow(new \RuntimeException('private-stream-secret'));
        $this->artisan('documents:audit-integrity')->expectsOutputToContain('Audit integritas tidak lengkap')
            ->doesntExpectOutputToContain('private-stream-secret')->doesntExpectOutputToContain('"counts"')->assertExitCode(1);
    }
}
