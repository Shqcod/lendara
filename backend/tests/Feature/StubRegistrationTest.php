<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class StubRegistrationTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('ml:register-stub')->assertExitCode(0);
    }

    public function test_registration_is_idempotent_and_only_activates_when_requested(): void
    {
        $existing = DB::table('model_versions')->where('version_code', 'stub-v0')->first();
        if ($existing) {
            DB::table('model_versions')->where('id', $existing->id)->update(['is_active' => false]);
        }
        $this->artisan('ml:register-stub')->assertExitCode(0);
        $row = DB::table('model_versions')->where('version_code', 'stub-v0')->sole();
        $this->assertSame(0, $row->is_active);
        $this->assertSame(hash_file('sha256', base_path('../ml-service/app/main.py')), $row->artifact_hash);
        $this->assertSame('code://ml-service/app/main.py', $row->artifact_path);
        $this->assertFalse(json_decode($row->parameters, true)['trained']);
        $this->artisan('ml:register-stub', ['--activate' => true])->assertExitCode(0);
        $this->artisan('ml:register-stub', ['--activate' => true])->assertExitCode(0);
        $this->assertDatabaseHas('model_versions', ['id' => $row->id, 'is_active' => true]);
        $this->assertSame(1, DB::table('model_versions')->where('version_code', 'stub-v0')->count());
    }

    public function test_registration_never_replaces_another_active_model_or_overwrites_changed_code_metadata(): void
    {
        DB::table('model_versions')->where('version_code', 'stub-v0')->update(['is_active' => false]);
        $other = $this->modelVersion(['is_active' => true]);
        $this->artisan('ml:register-stub', ['--activate' => true])->assertExitCode(1);
        $this->assertDatabaseHas('model_versions', ['id' => $other, 'is_active' => true]);
        DB::table('model_versions')->where('id', $other)->update(['is_active' => false]);
        DB::table('model_versions')->where('version_code', 'stub-v0')->update(['artifact_hash' => str_repeat('f', 64)]);
        $this->artisan('ml:register-stub')->assertExitCode(1);
        $this->assertDatabaseHas('model_versions', ['version_code' => 'stub-v0', 'artifact_hash' => str_repeat('f', 64)]);
    }

    public function test_registration_refuses_non_stub_mode_and_production(): void
    {
        config(['services.ml.mode' => 'model']);
        $this->artisan('ml:register-stub', ['--activate' => true])->assertExitCode(1);
        config(['services.ml.mode' => 'stub']);
        $this->app->instance('env', 'production');
        try {
            $this->artisan('ml:register-stub', ['--activate' => true])->assertExitCode(1);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
