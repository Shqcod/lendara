<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    private function setupAccounts(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'verifikator']);
        $this->actingAs($admin, 'web')->withHeader('Origin', 'http://localhost:5173');

        return [$admin, $target];
    }

    private function send(User $target, string $action, array $extra = [])
    {
        return $this->postJson('/api/v1/admin/users/'.$target->id.'/'.$action, array_replace(['expected_role' => $target->role->value, 'expected_is_active' => $target->is_active, 'reason' => 'Demonstrasi fiktif'], $extra));
    }

    private function sessionRow(User $target): string
    {
        $id = Str::random(40);
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $target->id, 'payload' => base64_encode('fixture'), 'last_activity' => time()]);

        return $id;
    }

    public function test_deactivate_atomically_releases_tasks_revokes_sessions_without_business_transition_and_activate_does_not_restore(): void
    {
        [$admin,$target] = $this->setupAccounts();
        $session = $this->sessionRow($target);
        $app = $this->loan(overrides: ['status' => 'pending_verification']);
        $snapshot = $this->submission($app);
        DB::table('loan_applications')->where('id', $app)->update(['current_submission_id' => $snapshot]);
        $task = $this->assignment($app, $snapshot, ['assigned_to' => $target->id]);
        $this->send($target, 'deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseMissing('sessions', ['id' => $session]);
        $this->assertDatabaseHas('assignments', ['id' => $task, 'assigned_to' => $target->id, 'end_type' => 'released', 'ended_by' => $admin->id]);
        $this->assertDatabaseHas('loan_applications', ['id' => $app, 'status' => 'pending_verification', 'current_submission_id' => $snapshot]);
        $this->assertDatabaseMissing('application_status_histories', ['application_id' => $app]);
        $this->send($target, 'deactivate')->assertConflict();
        $target->refresh();
        $this->send($target, 'activate')->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('assignments', ['id' => $task, 'end_type' => 'released']);
        $this->assertDatabaseMissing('sessions', ['id' => $session]);
    }

    public function test_role_change_requires_no_active_tasks_and_revokes_sessions(): void
    {
        [$admin,$target] = $this->setupAccounts();
        $session = $this->sessionRow($target);
        $app = $this->loan();
        $snapshot = $this->submission($app);
        $task = $this->assignment($app, $snapshot, ['assigned_to' => $target->id]);
        $this->send($target, 'role', ['role' => 'penyetuju_1'])->assertConflict()->assertJsonPath('code', 'ACCOUNT_HAS_ACTIVE_ASSIGNMENTS');
        $this->assertDatabaseHas('sessions', ['id' => $session]);
        DB::table('assignments')->where('id', $task)->update(['ended_at' => now(), 'end_type' => 'released']);
        $this->send($target, 'role', ['role' => 'penyetuju_1'])->assertOk()->assertJsonPath('data.role', 'penyetuju_1');
        $this->assertDatabaseMissing('sessions', ['id' => $session]);
        $this->send($target, 'role', ['role' => 'penyetuju_2'])->assertConflict();
    }

    public function test_self_last_admin_roles_expected_fields_and_targets_are_protected(): void
    {
        [$admin,$target] = $this->setupAccounts();
        $this->send($admin, 'deactivate')->assertConflict()->assertJsonPath('code', 'SELF_ACCOUNT_CHANGE_FORBIDDEN');
        $this->send($admin, 'role', ['role' => 'verifikator'])->assertConflict();
        $this->send($target, 'role', ['role' => 'nasabah'])->assertUnprocessable();
        $this->send($target, 'deactivate', ['expected_is_active' => 'true'])->assertUnprocessable();
        $customer = User::factory()->create();
        $this->send($customer, 'deactivate')->assertUnprocessable();
        $this->send($customer, 'deactivate', ['expected_role' => 'verifikator'])->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_active' => true, 'role' => 'admin']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($target, 'web');
        $this->send($admin, 'deactivate')->assertForbidden();
    }

    public function test_audit_failure_rolls_back_status_assignments_and_sessions(): void
    {
        [$admin,$target] = $this->setupAccounts();
        $session = $this->sessionRow($target);
        $app = $this->loan();
        $id = $this->submission($app);
        $task = $this->assignment($app, $id, ['assigned_to' => $target->id]);
        DB::listen(function ($q) {
            if (str_starts_with($q->sql, 'insert into `activity_logs`') && in_array('user.access_changed', $q->bindings, true)) {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->send($target, 'deactivate')->assertStatus(500);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
        $this->assertDatabaseHas('sessions', ['id' => $session]);
        $this->assertDatabaseHas('assignments', ['id' => $task, 'ended_at' => null]);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'user.access_changed', 'target_type' => 'user', 'target_id' => $target->id]);
    }

    public function test_resurrected_old_session_is_rejected_after_reactivation_but_fresh_login_works(): void
    {
        [$admin,$target] = $this->setupAccounts();
        $this->send($target, 'deactivate')->assertOk();
        $target->refresh();
        $this->send($target, 'activate')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($target->fresh(), 'web')->withSession(['access_generation' => 0]);
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'SESSION_REVOKED');
        $password = Str::random(32);
        $target->password = $password;
        $target->save();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $target->email, 'password' => $password])->assertOk();
        $this->getJson('/api/v1/me')->assertOk();
    }
}
