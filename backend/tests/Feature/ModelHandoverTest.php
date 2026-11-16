<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class ModelHandoverTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    private int $target;

    private int $old;

    private string $version;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ml.mode' => 'model', 'services.ml.key' => 'handover-fixture-key']);
        // Isolate existing test fixtures inside the rolled-back outer transaction.
        DB::table('anomaly_checks')->where('status', 'processing')->update(['status' => 'failed', 'failure_reason' => 'test-isolation', 'finished_at' => now()]);
        DB::table('model_versions')->where('is_active', true)->update(['is_active' => false]);
        $meta = json_decode(file_get_contents(base_path('../docs/validation/ml-model-v1-metadata.json')), true);
        $this->target = $this->modelVersion(['version_code' => 'if-handover-'.Str::ulid(),
            'algorithm' => 'IsolationForest', 'parameters' => json_encode(['source' => 'model', 'trained' => true]),
            'artifact_path' => $meta['artifact_path'], 'artifact_hash' => $meta['artifact_hash']]);
        $this->version = DB::table('model_versions')->where('id', $this->target)->value('version_code');
        $this->old = $this->modelVersion(['is_active' => true]);
        $this->ready();
    }

    private function ready(bool $includeReal = true): void
    {
        Http::swap(new Factory);
        $models = DB::table('model_versions')->where('version_code', 'not like', 'stub-%')->get()->map(fn ($m) => ['model_version_code' => $m->version_code, 'artifact_hash' => $m->artifact_hash])->all();
        Http::fake(['*' => Http::response(['ready' => true, 'mode' => 'model', 'contract_version' => '1', 'loaded_models' => $includeReal ? $models : []])]);
    }

    private function args(?string $expected = null): array
    {
        return ['version' => $this->version, '--expected-active' => $expected ?? DB::table('model_versions')->where('id', $this->old)->value('version_code')];
    }

    public function test_handover_atomic_idempotent_and_snapshot_pinned(): void
    {
        $app = $this->loan();
        $snapshot = $this->submission($app);
        $check = $this->check($snapshot, 'succeeded', overrides: ['model_version_id' => $this->old]);
        $this->artisan('ml:handover-model', $this->args())->assertExitCode(0);
        $this->assertDatabaseHas('model_versions', ['id' => $this->target, 'is_active' => true]);
        $this->assertDatabaseHas('model_versions', ['id' => $this->old, 'is_active' => false]);
        $this->assertDatabaseHas('anomaly_checks', ['id' => $check, 'model_version_id' => $this->old, 'status' => 'succeeded']);
        $this->artisan('ml:handover-model', $this->args($this->version))->assertExitCode(0);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'model.handover')->where('target_id', $this->target)->count());
    }

    public function test_stale_expected_missing_hash_mode_and_target_rejected(): void
    {
        $this->artisan('ml:handover-model', $this->args('stale'))->assertExitCode(1);
        $this->ready(false);
        $this->artisan('ml:handover-model', $this->args())->assertExitCode(1);
        $this->ready();
        config(['services.ml.mode' => 'stub']);
        $this->artisan('ml:handover-model', $this->args())->assertExitCode(1);
        config(['services.ml.mode' => 'model']);
        DB::table('model_versions')->where('id', $this->target)->update(['artifact_hash' => str_repeat('0', 64)]);
        $this->artisan('ml:handover-model', $this->args())->assertExitCode(1);
        $this->assertDatabaseHas('model_versions', ['id' => $this->old, 'is_active' => true]);
    }

    public function test_processing_missing_version_blocks_but_loaded_real_version_allowed(): void
    {
        $app = $this->loan();
        $snapshot = $this->submission($app);
        $check = $this->check($snapshot, 'processing', overrides: ['model_version_id' => $this->old]);
        $this->artisan('ml:handover-model', $this->args())->assertExitCode(1);
        $this->assertDatabaseHas('anomaly_checks', ['id' => $check, 'status' => 'processing', 'model_version_id' => $this->old]);
        DB::table('anomaly_checks')->where('id', $check)->update(['model_version_id' => $this->target]);
        $this->artisan('ml:handover-model', $this->args())->assertExitCode(0);
        $this->assertDatabaseHas('anomaly_checks', ['id' => $check, 'status' => 'processing']);
    }

    public function test_audit_failure_restores_old_active_model(): void
    {
        DB::listen(function ($q) {
            if (str_starts_with($q->sql, 'insert into `activity_logs`') && in_array('model.handover', $q->bindings, true)) {
                throw new \RuntimeException('Synthetic handover audit failure');
            }
        });
        $this->artisan('ml:handover-model', $this->args())->assertExitCode(1);
        $this->assertDatabaseHas('model_versions', ['id' => $this->old, 'is_active' => true]);
        $this->assertDatabaseHas('model_versions', ['id' => $this->target, 'is_active' => false]);
    }
}
