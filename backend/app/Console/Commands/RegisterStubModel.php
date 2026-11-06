<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RegisterStubModel extends Command
{
    protected $signature = 'ml:register-stub {--activate : Aktifkan hanya jika tidak ada model aktif lain}';

    protected $description = 'Daftarkan metadata kode stub untuk local/testing, bukan model terlatih';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing') || config('services.ml.mode') !== 'stub') {
            $this->error('Registrasi stub hanya diizinkan pada mode stub local/testing.');

            return 1;
        }
        $path = base_path('../ml-service/app/main.py');
        if (! is_file($path)) {
            $this->error('Kode stub lokal tidak ditemukan.');

            return 1;
        }
        $locked = DB::selectOne("SELECT GET_LOCK('lendara:model-activation', 10) AS acquired")->acquired;
        if ((int) $locked !== 1) {
            $this->error('Aktivasi model sedang digunakan proses lain.');

            return 1;
        }
        try {
            DB::transaction(function () use ($path) {
                $existing = DB::table('model_versions')->where('version_code', 'stub-v0')->lockForUpdate()->first();
                if ($existing && ($existing->artifact_hash !== hash_file('sha256', $path) || $existing->algorithm !== 'stub')) {
                    throw new RuntimeException('Metadata stub-v0 berbeda dari kode. Periksa tanpa menimpa versi immutable.');
                }
                if ($this->option('activate') && DB::table('model_versions')->where('is_active', true)->where('version_code', '<>', 'stub-v0')->exists()) {
                    throw new RuntimeException('Model aktif lain dipertahankan. Tidak mengganti model otomatis.');
                }
                if (! $existing) {
                    $raw = ['monthly_income', 'monthly_expenses', 'existing_installments', 'loan_amount', 'tenor_months'];
                    DB::table('model_versions')->insert([
                        'version_code' => 'stub-v0', 'algorithm' => 'stub', 'feature_names' => json_encode($raw),
                        'parameters' => json_encode(['source' => 'stub', 'raw_input_names' => $raw, 'feature_schema' => 'loan-finance-v1', 'trained' => false]),
                        'contamination' => '0.10000', 'decision_threshold' => 0.0, 'score_method' => 'decision_function',
                        'artifact_path' => 'code://ml-service/app/main.py', 'artifact_hash' => hash_file('sha256', $path),
                        'training_data_version' => 'stub-no-training', 'is_active' => false, 'created_at' => now(),
                    ]);
                }
                if ($this->option('activate')) {
                    DB::table('model_versions')->where('version_code', 'stub-v0')->update(['is_active' => true]);
                }
            });
            $this->info('Metadata stub-v0 tersedia'.($this->option('activate') ? ' dan aktif' : '').'. Bukan model terlatih atau demo akhir.');

            return 0;
        } catch (Throwable $error) {
            $this->error($error instanceof RuntimeException && ! $error instanceof QueryException ? $error->getMessage() : 'Registrasi stub gagal; periksa konfigurasi lokal.');

            return 1;
        } finally {
            DB::select("SELECT RELEASE_LOCK('lendara:model-activation')");
        }
    }
}
