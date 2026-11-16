<?php

namespace App\Console\Commands;

use App\Services\CustomerSubmissions;
use App\Services\PredictionClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LocalDemoReadiness extends Command
{
    protected $signature = 'system:local-demo-readiness';

    protected $description = 'Periksa koneksi/model demo lokal tanpa aktivasi, mutasi, atau prediksi';

    public function handle(PredictionClient $client): int
    {
        $stage = 'mode_environment';
        try {
            if (! app()->environment('local', 'testing') || config('services.ml.mode') !== 'model'
                || config('database.default') !== 'mysql') {
                throw new RuntimeException;
            }
            $stage = 'loopback_urls';
            foreach ([config('app.url'), config('services.ml.url')] as $url) {
                $parts = parse_url((string) $url);
                if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                    || ! in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)
                    || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                    throw new RuntimeException;
                }
            }
            $stage = 'supporting_drivers';
            foreach (['session.driver', 'queue.default', 'cache.default'] as $setting) {
                if (config($setting) !== 'database') {
                    throw new RuntimeException;
                }
            }
            $stage = 'supporting_connections';
            foreach (['session.connection', 'queue.connections.database.connection', 'cache.stores.database.connection', 'cache.stores.database.lock_connection'] as $setting) {
                if (! in_array(config($setting), [null, 'mysql'], true)) {
                    throw new RuntimeException;
                }
            }
            $stage = 'mysql';
            $effective = DB::selectOne('SELECT DATABASE() AS name, VERSION() AS version');
            if ($effective->name !== config('database.connections.mysql.database') || str_contains($effective->version, 'MariaDB')
                || version_compare($effective->version, '8.0.22', '<')) {
                throw new RuntimeException;
            }
            $stage = 'active_model';
            $models = DB::table('model_versions')->get();
            $active = $models->where('is_active', true)->sole();
            $parameters = json_decode($active->parameters, true, flags: JSON_THROW_ON_ERROR);
            if (str_starts_with($active->version_code, 'stub-') || $active->algorithm !== 'IsolationForest'
                || ($parameters['trained'] ?? null) !== true || $active->score_method !== 'decision_function'
                || (float) $active->decision_threshold !== 0.0 || ! app(CustomerSubmissions::class)->modelAllowed($active)) {
                throw new RuntimeException;
            }
            // No transaction or row lock is opened by this read-only command.
            $stage = 'runtime_readiness';
            $ready = $client->ready();
            if (($ready['ready'] ?? null) !== true || ($ready['mode'] ?? null) !== 'model' || ($ready['contract_version'] ?? null) !== '1') {
                throw new RuntimeException;
            }
            $stage = 'loaded_versions';
            $loaded = [];
            foreach ($ready['loaded_models'] ?? [] as $model) {
                $version = $model['model_version_code'] ?? null;
                $hash = $model['artifact_hash'] ?? null;
                if (! is_string($version) || $version === '' || isset($loaded[$version]) || ! is_string($hash) || ! preg_match('/\A[a-f0-9]{64}\z/D', $hash)) {
                    throw new RuntimeException;
                }
                $loaded[$version] = $hash;
            }
            foreach ($models->filter(fn ($model) => ! str_starts_with($model->version_code, 'stub-')) as $model) {
                if (($loaded[$model->version_code] ?? null) !== $model->artifact_hash) {
                    throw new RuntimeException;
                }
            }
            $stage = 'pinned_processing';
            $processing = DB::table('anomaly_checks')->where('status', 'processing')->pluck('model_version_id')->unique();
            foreach ($processing as $id) {
                $model = $models->firstWhere('id', $id);
                if (! $model || ($loaded[$model->version_code] ?? null) !== $model->artifact_hash) {
                    throw new RuntimeException;
                }
            }
            $stage = 'active_recheck';
            $current = DB::table('model_versions')->where('is_active', true)->get();
            if ($current->count() !== 1 || $current->first()->id !== $active->id || $current->first()->artifact_hash !== $active->artifact_hash) {
                throw new RuntimeException;
            }
            $this->line(json_encode(['local_model_ready' => true, 'model_version' => $active->version_code, 'source' => 'model', 'read_only' => true], JSON_THROW_ON_ERROR));
            $this->line('Bukan bukti grant runtime, worker/scheduler hidup, browser, backup atau deployment HTTPS.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Demo lokal belum siap. Tahap: '.$stage.'. Periksa konfigurasi terkait tanpa membagikan rahasia. Tidak ada konfigurasi atau model yang diubah.');

            return self::FAILURE;
        }
    }
}
