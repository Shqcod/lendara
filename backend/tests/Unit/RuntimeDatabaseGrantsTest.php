<?php

namespace Tests\Unit;

use App\Services\RuntimeDatabaseGrants;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RuntimeDatabaseGrantsTest extends TestCase
{
    public function test_plan_limits_immutable_history_and_operational_updates(): void
    {
        $sql = implode("\n", (new RuntimeDatabaseGrants)->statements('kkp_pinjaman_test', 'runtime_probe', '127.0.0.1'));
        $this->assertStringContainsString('GRANT SELECT, INSERT ON `kkp_pinjaman_test`.`application_submissions`', $sql);
        $this->assertStringContainsString('UPDATE (is_active) ON `kkp_pinjaman_test`.`model_versions`', $sql);
        $this->assertStringContainsString('UPDATE (ended_at, end_type, end_reason, ended_by)', $sql);
        $this->assertStringContainsString('UPDATE (status, score, is_anomaly, finished_at, failure_reason)', $sql);
        $this->assertStringNotContainsString('migrations', $sql);
        $this->assertStringNotContainsString('password_reset_tokens', $sql);
        $this->assertStringNotContainsString('GRANT OPTION', $sql);
        $this->assertStringNotContainsString('.*', $sql);
    }

    public function test_plan_rejects_sql_injection_and_wildcard_hosts(): void
    {
        foreach ([['db`.*', 'runtime', 'localhost'], ['db', "runtime'", 'localhost'], ['db', 'runtime', '%'], ['db', 'runtime', 'local_host%']] as $arguments) {
            try {
                (new RuntimeDatabaseGrants)->statements(...$arguments);
                $this->fail('Unsafe identifier accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
