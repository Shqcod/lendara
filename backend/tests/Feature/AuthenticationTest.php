<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Nasabah Fiktif', 'email' => 'registration@example.test',
            'password' => 'testing-only-password', 'password_confirmation' => 'testing-only-password',
        ], $overrides);
    }

    public function test_register_creates_only_a_customer_without_logging_in(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration())
            ->assertCreated()->assertJsonPath('data.role', 'nasabah')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
        $this->assertGuest('web');
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $user = User::where('email', 'registration@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('testing-only-password', $user->password));
    }

    public function test_register_rejects_privileged_fields_and_invalid_password_confirmation(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration(['role' => 'admin', 'is_active' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors(['role', 'is_active']);
        $this->postJson('/api/v1/auth/register', $this->registration(['password_confirmation' => 'wrong']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'registration@example.test']);
    }

    public function test_duplicate_email_has_field_validation_and_database_constraint(): void
    {
        User::factory()->create(['email' => 'registration@example.test']);
        $this->postJson('/api/v1/auth/register', $this->registration())
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors('email');
        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['email' => 'registration@example.test']);
    }

    public static function roles(): array
    {
        return array_map(fn (Role $role) => [$role], Role::cases());
    }

    #[DataProvider('roles')]
    public function test_each_agreed_role_can_login_read_safe_identity_and_logout(Role $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.role', $role->value)
            ->assertExactJson(['data' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'role' => $role->value, 'is_active' => true,
            ], 'message' => 'Berhasil masuk.']);
        $this->getJson('/api/v1/me')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest('web');
        // Laravel feature tests reuse the container; HTTP requests get fresh guards.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_wrong_password_and_inactive_account_cannot_login(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'incorrect'])
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $this->assertGuest('web');
    }

    public function test_inactive_user_loses_access_with_existing_session(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user, 'web')->getJson('/api/v1/me')
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $this->assertGuest('web');
    }

    public function test_bearer_tokens_cannot_replace_a_browser_session(): void
    {
        $this->withHeader('Authorization', 'Bearer 1|testing-only-token')
            ->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_login_and_registration_are_throttled(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'absent@example.test', 'password' => 'invalid'])
                ->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'absent@example.test', 'password' => 'invalid'])
            ->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS')->assertHeader('Retry-After');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/v1/auth/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/register', [])->assertStatus(429);
    }

    public function test_only_exact_frontend_origin_receives_credentialed_cors_headers(): void
    {
        $this->options('/api/v1/auth/login', [], [
            'Origin' => 'http://localhost:5173', 'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $response = $this->options('/api/v1/auth/login', [], [
            'Origin' => 'http://untrusted.example.test', 'Access-Control-Request-Method' => 'POST',
        ]);
        // A fixed single allow-origin header is safe: browsers require an exact match.
        $this->assertNotSame('http://untrusted.example.test', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_mysql_rejects_an_unknown_role_even_when_bypassing_the_model(): void
    {
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $user->id)->update(['role' => 'superadmin']);
    }
}
