<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class ModelRegistrationTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    private string $path;

    private array $meta;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ml.mode' => 'model', 'services.ml.key' => 'fixture-model-key']);
        $this->meta = json_decode(file_get_contents(base_path('../docs/validation/ml-model-v1-metadata.json')), true);
        $this->meta['version_code'] = 'if-test-'.Str::ulid();
        $this->path = sys_get_temp_dir().'/lendara-model-'.Str::ulid().'.json';
        $this->save();
    }

    private function save(): void
    {
        file_put_contents($this->path, json_encode($this->meta));
    }

    protected function tearDown(): void
    {
        if (isset($this->path) && is_file($this->path)) {
            unlink($this->path); // Only this test's uniquely named metadata fixture.
        }
        parent::tearDown();
    }

    private function args(bool $active = false): array
    {
        return ['metadata' => $this->path, '--sha256' => hash_file('sha256', $this->path), '--activate' => $active];
    }

    private function ready(): void
    {
        Http::fake(function () {
            $this->assertSame(1, DB::transactionLevel()); // Only PHPUnit's outer fixture transaction.

            return Http::response(['ready' => true, 'mode' => 'model', 'contract_version' => '1',
                'loaded_models' => [['model_version_code' => $this->meta['version_code'], 'artifact_hash' => $this->meta['artifact_hash']]]]);
        });
    }

    public function test_register_idempotent_inactive_and_metadata_from_manifest(): void
    {
        Http::preventStrayRequests();
        $this->artisan('ml:register-model', $this->args())->assertExitCode(0);
        $this->artisan('ml:register-model', $this->args())->assertExitCode(0);
        $row = DB::table('model_versions')->where('version_code', $this->meta['version_code'])->sole();
        $this->assertSame(0, $row->is_active);
        $this->assertSame('0.15000', $row->contamination);
        $this->assertSame($this->meta['feature_names'], json_decode($row->feature_names, true));
        $this->assertTrue(json_decode($row->parameters, true)['trained']);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'model.registered')->where('target_id', $row->id)->count());
        Http::assertNothingSent();
    }

    public function test_explicit_activation_requires_ready_hash_and_is_idempotent(): void
    {
        DB::table('model_versions')->where('is_active', true)->update(['is_active' => false]);
        $this->ready();
        $this->artisan('ml:register-model', $this->args(true))->assertExitCode(0);
        $this->artisan('ml:register-model', $this->args(true))->assertExitCode(0);
        $row = DB::table('model_versions')->where('version_code', $this->meta['version_code'])->sole();
        $this->assertSame(1, $row->is_active);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'model.activated')->where('target_id', $row->id)->count());
    }

    public function test_existing_active_model_and_immutable_metadata_are_preserved(): void
    {
        DB::table('model_versions')->where('is_active', true)->update(['is_active' => false]);
        $other = $this->modelVersion(['is_active' => true]);
        $this->ready();
        $this->artisan('ml:register-model', $this->args(true))->assertExitCode(1);
        $this->assertDatabaseHas('model_versions', ['id' => $other, 'is_active' => true]);
        $this->assertDatabaseMissing('model_versions', ['version_code' => $this->meta['version_code']]);
        $this->artisan('ml:register-model', $this->args())->assertExitCode(0);
        $this->meta['parameters']['seed'] = 29;
        $this->save();
        $this->artisan('ml:register-model', $this->args())->assertExitCode(1);
        $this->assertSame(17, json_decode(DB::table('model_versions')->where('version_code', $this->meta['version_code'])->value('parameters'), true)['seed']);
    }

    public function test_bad_metadata_hash_artifact_features_mode_and_readiness_rejected(): void
    {
        $this->artisan('ml:register-model', [...$this->args(), '--sha256' => str_repeat('0', 64)])->assertExitCode(1);
        $original = $this->meta;
        foreach ([['artifact_hash', str_repeat('0', 64)], ['version_code', 'stub-forbidden'], ['feature_names', ['wrong']]] as [$field, $value]) {
            $this->meta = array_replace($original, [$field => $value]);
            $this->save();
            $this->artisan('ml:register-model', $this->args())->assertExitCode(1);
        }
        $this->meta = $original;
        $this->save();
        config(['services.ml.mode' => 'stub']);
        $this->artisan('ml:register-model', $this->args())->assertExitCode(1);
        config(['services.ml.mode' => 'model']);
        Http::fake(['*' => Http::response(['ready' => true, 'mode' => 'model', 'contract_version' => '1', 'loaded_models' => []])]);
        $this->artisan('ml:register-model', $this->args(true))->assertExitCode(1);
        $this->assertDatabaseMissing('model_versions', ['version_code' => $this->meta['version_code']]);
    }

    public function test_audit_failure_rolls_back_registration(): void
    {
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into `activity_logs`') && in_array('model.registered', $query->bindings, true)) {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->artisan('ml:register-model', $this->args())->assertExitCode(1);
        $this->assertDatabaseMissing('model_versions', ['version_code' => $this->meta['version_code']]);
    }
}
