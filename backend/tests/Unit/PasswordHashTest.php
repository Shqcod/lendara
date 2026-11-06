<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordHashTest extends TestCase
{
    public function test_passwords_differing_after_72_bytes_do_not_authenticate_as_the_same_password(): void
    {
        $prefix = str_repeat('a', 72);
        $hash = Hash::make($prefix.'one');
        $this->assertTrue(Hash::check($prefix.'one', $hash));
        $this->assertFalse(Hash::check($prefix.'two', $hash));
    }
}
