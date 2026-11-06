<?php

use App\Jobs\SystemProbe;
use App\Services\PredictionClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

Artisan::command('system:database-status', function () {
    if (! app()->environment('local', 'testing')) {
        $this->error('Pemeriksaan schema hanya untuk local/testing.');

        return 1;
    }
    try {
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        $this->line('Database: '.DB::connection()->getDatabaseName());
        $this->line('Server: '.$version);
        foreach (DB::select('SHOW TABLES') as $row) {
            $this->line('Table: '.array_values((array) $row)[0]);
        }

        return 0;
    } catch (QueryException $error) {
        $this->error('Database belum dapat diakses; periksa konfigurasi lokal.');

        return 1;
    }
})->purpose('Baca versi server dan nama tabel tanpa isi data atau kredensial');

Artisan::command('system:assert-test-database', function () {
    if (! app()->environment('testing') || config('database.default') !== 'mysql'
        || config('database.connections.mysql.database') !== 'kkp_pinjaman_test') {
        $this->error('Gunakan APP_ENV=testing dan database MySQL kkp_pinjaman_test.');

        return 1;
    }
    try {
        $effective = DB::selectOne('SELECT DATABASE() AS database_name, VERSION() AS version');
        if ($effective->database_name !== 'kkp_pinjaman_test') {
            $this->error('Koneksi efektif bukan kkp_pinjaman_test; pengujian dibatalkan.');

            return 1;
        }
        $version = $effective->version;
        $tables = ['users', 'sessions', 'jobs', 'failed_jobs', 'customer_profiles', 'loan_applications', 'application_submissions', 'document_types', 'document_versions', 'submission_documents', 'model_versions', 'anomaly_checks', 'assignments', 'verification_results', 'approval_decisions', 'application_status_histories', 'activity_logs'];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->error('Migration aplikasi belum lengkap pada database test.');

                return 1;
            }
        }
        $this->info('PASS: MySQL '.$version.' dan tabel pendukung tersedia pada database test.');

        return 0;
    } catch (QueryException $error) {
        $this->error('Database test belum dapat diakses. Periksa konfigurasi lokal tanpa membagikan kredensial.');

        return 1;
    }
})->purpose('Periksa isolasi dan kesiapan database sebelum uji browser');

Artisan::command('ml:smoke', function (PredictionClient $client) {
    if (! app()->environment('local', 'testing') || config('services.ml.mode') !== 'stub') {
        $this->error('Smoke test fixture hanya untuk stub local/testing.');

        return 1;
    }
    try {
        $ready = $client->ready();
        if (($ready['ready'] ?? false) !== true || ($ready['mode'] ?? null) !== 'stub'
            || ! in_array('stub-v0', $ready['loaded_model_versions'] ?? [], true)) {
            throw new RuntimeException('Stub belum siap.');
        }
        $payload = json_decode(file_get_contents(base_path('../fixtures/prediction-request.json')), true, flags: JSON_THROW_ON_ERROR);
        $result = $client->predict($payload);
        $this->info('PASS: /ready dan /predict dari Laravel ke FastAPI; source='.$result['source'].'. Ini fixture, bukan pengajuan atau model terlatih.');

        return 0;
    } catch (RuntimeException $error) {
        $this->error($error->getMessage());

        return 1;
    }
})->purpose('Uji koneksi internal menggunakan fixture tanpa transaksi database');

Artisan::command('system:queue-probe {id?}', function () {
    if (! app()->environment('local', 'testing')) {
        $this->error('Probe hanya tersedia pada local/testing.');

        return 1;
    }
    if ($id = $this->argument('id')) {
        if (! Str::isUuid($id)) {
            $this->error('ID probe tidak valid.');

            return 1;
        }
        $this->line((string) Cache::get('system.queue.'.$id, 'not_found'));

        return 0;
    }
    $id = (string) Str::uuid();
    Cache::put('system.queue.'.$id, 'queued', 3600);
    SystemProbe::dispatch($id);
    $this->line($id);

    return 0;
})->purpose('Kirim job diagnostik atau baca hasil menggunakan ID probe');

Artisan::command('system:scheduler-status', function () {
    if (! app()->environment('local', 'testing')) {
        $this->error('Probe hanya tersedia pada local/testing.');

        return 1;
    }
    $this->line((string) Cache::get('system.scheduler.last_run', 'not_run'));

    return 0;
})->purpose('Baca heartbeat scheduler tanpa data pengguna');

if (app()->environment('local', 'testing')) {
    Schedule::call(fn () => Cache::put('system.scheduler.last_run', now()->toIso8601String(), 3600))
        ->name('system-scheduler-probe')->everyMinute();
}
