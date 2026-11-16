<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DatabaseContentDigest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DatabaseContentDigestTest extends TestCase
{
    public function test_detects_same_length_content_changes_and_restores_digest_without_mutating_rows(): void
    {
        $key = 'digest-'.Str::uuid();
        DB::table('cache')->insert(['key' => $key, 'value' => "ab\0cd", 'expiration' => 1]);
        $digest = app(DatabaseContentDigest::class);
        $before = $digest->table('cache');
        $this->assertSame($before, $digest->table('cache'));
        $this->assertSame("ab\0cd", DB::table('cache')->where('key', $key)->value('value'));
        DB::table('cache')->where('key', $key)->update(['value' => "ab\0ce"]);
        $this->assertNotSame($before, $digest->table('cache'));
        DB::table('cache')->where('key', $key)->update(['value' => "ab\0cd"]);
        $this->assertSame($before, $digest->table('cache'));
    }

    public function test_rejects_identifiers_and_missing_primary_key_before_reading_content(): void
    {
        foreach (['users;SELECT', '../users', 'missing_table'] as $table) {
            try {
                app(DatabaseContentDigest::class)->table($table);
                $this->fail('Invalid digest input accepted');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_hashes_rows_beyond_first_page_and_distinguishes_null_from_empty(): void
    {
        $prefix = 'digest-page-'.Str::uuid();
        $rows = [];
        for ($index = 0; $index < 501; $index++) {
            $rows[] = ['key' => $prefix.'-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'value' => 'row', 'expiration' => 1];
        }
        DB::table('cache')->insert($rows);
        $digest = app(DatabaseContentDigest::class);
        $before = $digest->table('cache');
        DB::table('cache')->where('key', $prefix.'-500')->update(['value' => 'new']);
        $this->assertNotSame($before, $digest->table('cache'));

        $user = User::factory()->create(['remember_token' => null]);
        $before = $digest->table('users');
        DB::table('users')->where('id', $user->id)->update(['remember_token' => '']);
        $this->assertNotSame($before, $digest->table('users'));
    }
}
