<?php

namespace Tests\Feature;

use Tests\TestCase;

class RuntimeDatabaseGuardTest extends TestCase
{
    public function test_operator_connection_cannot_be_mistaken_for_a_dedicated_runtime(): void
    {
        $this->artisan('system:assert-runtime-database')->expectsOutputToContain('tidak memakai fallback operator')->assertExitCode(1);
    }

    public function test_production_context_is_rejected(): void
    {
        app()->instance('env', 'production');
        $this->artisan('system:assert-runtime-database')->assertExitCode(1);
    }
}
