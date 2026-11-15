<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewerBootstrapTest extends TestCase
{
    use DatabaseTransactions;

    public function test_local_bootstrap_creates_three_review_roles_with_hidden_password_prompt_and_audit(): void
    {
        foreach (['verifikator', 'penyetuju_1', 'penyetuju_2'] as $role) {
            $email = Str::uuid().'@example.test';
            $password = Str::random(32);
            $this->artisan('users:create-reviewer')->expectsQuestion('Nama peninjau fiktif', 'Peninjau Lokal Fiktif')
                ->expectsQuestion('Email peninjau lokal', $email)->expectsChoice('Peran peninjau', $role, ['verifikator', 'penyetuju_1', 'penyetuju_2'])
                ->expectsQuestion('Kata sandi baru', $password)->expectsQuestion('Ulangi kata sandi', $password)->assertExitCode(0);
            $user = User::where('email', $email)->sole();
            $this->assertSame($role, $user->role->value);
            $this->assertTrue(Hash::check($password, $user->password));
            $this->assertDatabaseHas('activity_logs', ['action' => 'internal_user.created_cli', 'target_id' => $user->id]);
        }
    }

    public function test_bootstrap_refuses_production_without_prompting_or_writing(): void
    {
        $this->app->instance('env', 'production');
        try {
            $this->artisan('users:create-reviewer')->assertExitCode(1);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
