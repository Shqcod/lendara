<?php

namespace Tests\Feature;

use App\Jobs\CleanupDraftDocuments;
use App\Models\User;
use App\Services\CustomerWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class DraftCleanupTest extends TestCase
{
    use DomainFixtures;

    public function test_committed_delete_leaves_a_visible_persistent_cleanup_job_and_idempotent_file_cleanup(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $application = $this->loan($user->id);
        $document = $this->document($application);
        $path = DB::table('document_versions')->where('id', $document)->value('storage_path');
        Storage::disk('local')->put($path, 'dokumen fiktif');
        app(CustomerWorkspace::class)->deleteDraft($user->id, $application);
        $this->assertDatabaseMissing('loan_applications', ['id' => $application]);
        Storage::disk('local')->assertExists($path);

        config(['database.connections.observer' => config('database.connections.mysql')]);
        $observer = DB::connection('observer');
        try {
            $this->assertSame('kkp_pinjaman_test', $observer->selectOne('SELECT DATABASE() AS name')->name);
            $created = DB::table('jobs')->where('queue', 'document-cleanup')->orderByDesc('id')->first();
            $this->assertNotNull($created);
            $this->assertStringContainsString($path, json_decode($created->payload, true)['data']['command']);
            $job = $observer->table('jobs')->where('id', $created->id)->first();
            $this->assertNotNull($job, 'Queue row must be visible from another connection after commit.');
            $command = unserialize(json_decode($job->payload, true)['data']['command'], ['allowed_classes' => [CleanupDraftDocuments::class]]);
            $this->assertInstanceOf(CleanupDraftDocuments::class, $command);
            $command->handle();
            $command->handle();
            Storage::disk('local')->assertMissing($path);
        } finally {
            DB::disconnect('observer');
        }
    }

    public function test_cleanup_failure_is_not_silently_treated_as_success(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->once()->andReturn(true);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->expectException(RuntimeException::class);
        (new CleanupDraftDocuments(['loan-documents/test/dummy.pdf']))->handle();
    }

    public function test_cleanup_cannot_escape_the_private_document_prefix(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('outside.txt', 'keep');
        try {
            (new CleanupDraftDocuments(['loan-documents/../outside.txt']))->handle();
            $this->fail('Traversal must be rejected.');
        } catch (RuntimeException $error) {
            Storage::disk('local')->assertExists('outside.txt');
        }
    }
}
