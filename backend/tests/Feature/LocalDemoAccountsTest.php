<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocalDemoAccountsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_all_active_roles_report_success_without_identities_or_mutation(): void
    {
        foreach (Role::cases() as $role) {
            User::factory()->create(['role' => $role->value, 'is_active' => true]);
        }
        $rows = DB::table('users')->count();
        $logs = DB::table('activity_logs')->count();
        $this->assertSame(0, Artisan::call('system:local-demo-accounts'));
        $output = Artisan::output();
        $report = json_decode(explode("\n", trim($output))[0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['accounts_ready']);
        $this->assertCount(5, $report['active_role_present']);
        $this->assertNotContains(false, $report['active_role_present']);
        $this->assertStringNotContainsString('@', $output);
        $this->assertStringNotContainsString('password', $output);
        $this->assertSame($rows, DB::table('users')->count());
        $this->assertSame($logs, DB::table('activity_logs')->count());
    }

    public function test_inactive_admins_do_not_make_the_demo_accounts_ready(): void
    {
        // Fixture-only alteration is rolled back; this is not an admin operation.
        DB::table('users')->where('role', 'admin')->update(['is_active' => false]);
        $this->artisan('system:local-demo-accounts')->expectsOutputToContain('"admin":false')->assertExitCode(1);
    }

    public function test_bootstrap_summary_is_unavailable_in_production(): void
    {
        app()->instance('env', 'production');
        $this->artisan('system:local-demo-accounts')->expectsOutputToContain('Tidak ada akun yang diubah')->assertExitCode(1);
    }
}
