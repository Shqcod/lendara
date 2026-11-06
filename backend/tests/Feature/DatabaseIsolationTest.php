<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    public function test_phpunit_effectively_uses_the_separate_mysql_database(): void
    {
        $row = DB::connection()->selectOne('SELECT DATABASE() AS database_name, VERSION() AS server_version');
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('kkp_pinjaman_test', $row->database_name);
        $this->assertStringNotContainsString('MariaDB', $row->server_version);
        $columns = DB::select("SELECT DATA_TYPE AS data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name IN ('created_at', 'updated_at')");
        $this->assertCount(2, $columns);
        foreach ($columns as $column) {
            $this->assertSame('datetime', $column->data_type);
        }
        $this->assertSame('+00:00', DB::selectOne('SELECT @@session.time_zone AS timezone')->timezone);
        fwrite(STDOUT, 'PHPUnit effective connection: mysql / '.$row->database_name.' / '.$row->server_version.PHP_EOL);
    }
}
