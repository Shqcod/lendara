<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AccountAccess
{
    public function generation(int $user): int
    {
        return (int) DB::table('activity_logs')->where('target_type', 'user')->where('target_id', $user)->where('action', 'user.access_changed')->max('id');
    }
}
