<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocalDemoDatabaseGuardTest extends TestCase
{
    public function test_profile_refuses_testing_context_without_mutation(): void
    {
        $this->artisan('system:assert-local-demo-database')->expectsOutputToContain('Tidak ada perubahan')->assertExitCode(1);
    }
}
