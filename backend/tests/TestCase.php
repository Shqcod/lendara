<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        if (! $app->environment('testing') || config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'kkp_pinjaman_test') {
            throw new \RuntimeException('Pengujian wajib memakai MySQL kkp_pinjaman_test. Isi .env.testing lokal dahulu.');
        }

        // This runs before DatabaseTransactions and checks the actual PDO connection.
        $effective = DB::connection()->selectOne('SELECT DATABASE() AS database_name');
        if ($effective->database_name !== 'kkp_pinjaman_test') {
            throw new \RuntimeException('Koneksi efektif PHPUnit bukan kkp_pinjaman_test; pengujian dibatalkan sebelum transaksi.');
        }

        return $app;
    }
}
