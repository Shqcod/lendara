<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class LocalDemoReadinessTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    private int $model;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost:8000', 'services.ml.mode' => 'model', 'services.ml.url' => 'http://127.0.0.1:8001', 'services.ml.key' => 'fixture-only-key',
            'session.driver' => 'database', 'queue.default' => 'database', 'cache.default' => 'database']);
        DB::table('model_versions')->where('is_active', true)->update(['is_active' => false]);
        DB::table('anomaly_checks')->where('status', 'processing')->update(['status' => 'failed', 'finished_at' => now(), 'failure_reason' => 'test-isolation']);
        $this->model = $this->modelVersion(['version_code' => 'if-readiness-'.Str::ulid(), 'algorithm' => 'IsolationForest',
            'parameters' => json_encode(['trained' => true, 'feature_schema' => 'loan-finance-v1']), 'is_active' => true]);
    }

    private function ready(array $overrides = []): void
    {
        $loaded = DB::table('model_versions')->get()->filter(fn ($m) => ! str_starts_with($m->version_code, 'stub-'))
            ->map(fn ($m) => ['model_version_code' => $m->version_code, 'artifact_hash' => $m->artifact_hash])->values()->all();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(array_replace(['ready' => true, 'mode' => 'model', 'contract_version' => '1', 'loaded_models' => $loaded], $overrides))]);
    }

    public function test_success_is_read_only_and_sends_no_prediction(): void
    {
        $this->ready();
        $models = DB::table('model_versions')->get()->toJson();
        $logs = DB::table('activity_logs')->count();
        $checks = DB::table('anomaly_checks')->count();
        $this->artisan('system:local-demo-readiness')->expectsOutputToContain('"local_model_ready":true')->assertExitCode(0);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_ends_with($r->url(), '/ready'));
        $this->assertSame($models, DB::table('model_versions')->get()->toJson());
        $this->assertSame($logs, DB::table('activity_logs')->count());
        $this->assertSame($checks, DB::table('anomaly_checks')->count());
    }

    public function test_stub_mode_external_urls_or_non_database_cache_are_rejected_before_http(): void
    {
        foreach (['services.ml.mode' => 'stub', 'services.ml.url' => 'http://remote.example.test', 'cache.default' => 'array'] as $setting => $value) {
            $old = config($setting);
            $this->ready();
            config([$setting => $value]);
            $this->artisan('system:local-demo-readiness')->assertExitCode(1);
            Http::assertNothingSent();
            config([$setting => $old]);
        }
    }

    public function test_wrong_hash_stub_runtime_or_processing_unavailable_are_rejected(): void
    {
        foreach ([['loaded_models' => []], ['mode' => 'stub'], ['ready' => false]] as $overrides) {
            $this->ready($overrides);
            $this->artisan('system:local-demo-readiness')->assertExitCode(1);
        }
        $submission = $this->submission($this->loan());
        $this->check($submission, 'processing');
        $this->ready();
        $this->artisan('system:local-demo-readiness')->assertExitCode(1);
    }

    public function test_active_model_must_also_satisfy_the_submission_feature_contract(): void
    {
        DB::table('model_versions')->where('id', $this->model)->update(['parameters' => json_encode(['trained' => true, 'feature_schema' => 'wrong'])]);
        $this->ready();
        $this->artisan('system:local-demo-readiness')->expectsOutputToContain('active_model')->assertExitCode(1);
        Http::assertNothingSent();
    }
}
