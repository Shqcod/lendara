<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InternalAccounts;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class AdminAccountsTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    private function login(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->withHeader('Origin', 'http://localhost:5173');
    }

    private function data(string $role = 'verifikator'): array
    {
        $password = Str::random(32);

        return ['name' => 'Akun Internal Fiktif', 'email' => 'admin-create-'.Str::uuid().'@example.test', 'password' => $password, 'password_confirmation' => $password, 'role' => $role];
    }

    public function test_only_active_admin_can_read_or_create(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        foreach (['nasabah', 'verifikator', 'penyetuju_1', 'penyetuju_2'] as $role) {
            $this->login(User::factory()->create(['role' => $role]));
            $this->getJson('/api/v1/admin/users')->assertForbidden();
            $this->getJson('/api/v1/admin/activity-logs')->assertForbidden();
            $this->postJson('/api/v1/admin/users', $this->data())->assertForbidden();
        }
        $this->login(User::factory()->create(['role' => 'admin', 'is_active' => false]));
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_internal_scope_filters_pagination_and_safe_log_allowlist(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->login($admin);
        $customer = User::factory()->create();
        $this->getJson('/api/v1/admin/users?email='.urlencode($customer->email))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/users?email='.urlencode($admin->email))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.remember_token');
        $this->getJson('/api/v1/admin/users?role=nasabah')->assertUnprocessable();
        $this->getJson('/api/v1/admin/users?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/admin/users?email='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
        DB::table('activity_logs')->insert(['actor_id' => $admin->id, 'action' => 'test.safe-log', 'target_type' => 'user', 'target_id' => $admin->id, 'description' => 'Internal fiktif', 'metadata' => json_encode(['internal_notes' => 'Isi internal fiktif']), 'created_at' => now()]);
        $r = $this->getJson('/api/v1/admin/activity-logs?action=test.safe-log&actor_id='.$admin->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.metadata')->assertJsonMissingPath('data.0.description');
        $this->assertStringNotContainsString('Isi internal fiktif', $r->getContent());
        $this->getJson('/api/v1/admin/activity-logs?per_page=101')->assertUnprocessable();
    }

    public function test_creates_each_internal_role_with_hash_and_atomic_safe_audit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->login($admin);
        foreach (InternalAccounts::ROLES as $role) {
            $data = $this->data($role);
            $id = $this->postJson('/api/v1/admin/users', $data)->assertCreated()->assertJsonPath('data.role', $role)->assertJsonPath('data.is_active', true)->assertJsonMissingPath('data.password')->json('data.id');
            $created = User::findOrFail($id);
            $this->assertTrue(Hash::check($data['password'], $created->password));
            $log = DB::table('activity_logs')->where('target_id', $id)->where('action', 'internal_user.created')->sole();
            $this->assertSame($admin->id, $log->actor_id);
            $this->assertSame(['role' => $role], json_decode($log->metadata, true));
            $this->postJson('/api/v1/admin/users', $data)->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->assertSame($admin->id, auth('web')->id());
    }

    public function test_validation_does_not_allow_customer_or_server_fields(): void
    {
        $this->login(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/v1/admin/users', $this->data('nasabah'))->assertUnprocessable();
        foreach (['is_active', 'actor_id', 'id'] as $field) {
            $this->postJson('/api/v1/admin/users', $this->data() + [$field => 1])->assertUnprocessable();
        }
        $data = $this->data();
        $data['password_confirmation'] = 'mismatch';
        $this->postJson('/api/v1/admin/users', $data)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
    }

    public function test_locked_actor_is_rechecked_and_audit_failure_rolls_back_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->login($admin);
        foreach ([['is_active' => false], ['is_active' => true, 'role' => 'verifikator']] as $values) {
            DB::table('users')->where('id', $admin->id)->update($values);
            try {
                app(InternalAccounts::class)->create($admin->id, $this->data());
                $this->fail('Stale admin accepted');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        DB::table('users')->where('id', $admin->id)->update(['role' => 'admin']);
        DB::listen(function ($q) {
            if (str_starts_with($q->sql, 'insert into `activity_logs`')) {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $data = $this->data();
        $this->postJson('/api/v1/admin/users', $data)->assertStatus(500);
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
    }

    public function test_bootstrap_admin_local_uses_secret_prompts_and_rejects_production(): void
    {
        $email = 'bootstrap-admin-'.Str::uuid().'@example.test';
        $password = Str::random(32);
        $this->artisan('users:create-admin')->expectsQuestion('Nama admin fiktif', 'Admin Bootstrap Fiktif')->expectsQuestion('Email admin lokal', $email)
            ->expectsQuestion('Kata sandi baru', $password)->expectsQuestion('Ulangi kata sandi', $password)->assertExitCode(0);
        $created = User::where('email', $email)->sole();
        $this->assertSame('admin', $created->role->value);
        $this->assertTrue(Hash::check($password, $created->password));
        $this->assertDatabaseHas('activity_logs', ['action' => 'internal_user.created_cli', 'target_id' => $created->id]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('users:create-admin')->assertExitCode(1);
    }

    public function test_admin_still_cannot_read_customer_documents_or_review_queue(): void
    {
        $this->login(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/internal/applications')->assertForbidden();
        $document = $this->document($this->loan());
        $this->getJson('/api/v1/document-versions/'.$document.'/download')->assertForbidden();
    }
}
