<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class DatabaseContentDigest
{
    /** Logical content, ordered by primary key; callers must keep writers stopped. */
    public function table(string $table): string
    {
        if (! preg_match('/\A[a-z_]+\z/D', $table)) {
            throw new RuntimeException('Invalid digest table');
        }
        $keys = DB::select('SELECT COLUMN_NAME AS name FROM information_schema.statistics WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=\'PRIMARY\' ORDER BY SEQ_IN_INDEX', [$table]);
        if ($keys === []) {
            throw new RuntimeException('Digest requires a primary key');
        }
        $query = DB::table($table);
        foreach ($keys as $key) {
            $query->orderBy($key->name);
        }
        $hash = hash_init('sha256');
        $frame = fn (string $value) => strlen($value).':'.$value;
        foreach ($query->lazy(500) as $row) {
            hash_update($hash, 'R');
            foreach ((array) $row as $column => $value) {
                hash_update($hash, $frame($column).($value === null ? 'N' : 'V'.$frame((string) $value)));
            }
        }

        return hash_final($hash);
    }
}
