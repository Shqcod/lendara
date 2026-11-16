<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class ModelHandover
{
    public function switch(string $version, string $expected): void
    {
        if (config('services.ml.mode') !== 'model' || $expected === '') {
            throw new RuntimeException('Explicit expected active and model mode required');
        }
        // Never call the runtime while holding the command's database locks.
        $ready = app(PredictionClient::class)->ready();
        if (($ready['ready'] ?? null) !== true || ($ready['mode'] ?? null) !== 'model' || ($ready['contract_version'] ?? null) !== '1') {
            throw new RuntimeException('Model runtime not ready');
        }
        $loaded = [];
        foreach ($ready['loaded_models'] ?? [] as $model) {
            if (isset($loaded[$model['model_version_code']])) {
                throw new RuntimeException('Duplicate loaded version');
            }
            $loaded[$model['model_version_code']] = $model['artifact_hash'];
        }
        if ((int) DB::selectOne("SELECT GET_LOCK('lendara:model-activation', 10) AS acquired")->acquired !== 1) {
            throw new RuntimeException('Activation busy');
        }
        try {
            DB::transaction(function () use ($version, $expected, $loaded) {
                $models = DB::table('model_versions')->orderBy('id')->lockForUpdate()->get();
                $active = $models->firstWhere('is_active', true);
                if ($expected === 'none' ? $active !== null : ! $active || $active->version_code !== $expected) {
                    throw new RuntimeException('Active version changed');
                }
                $target = $models->firstWhere('version_code', $version);
                $params = $target ? json_decode($target->parameters, true) : [];
                if (! $target || str_starts_with($version, 'stub-') || $target->algorithm !== 'IsolationForest'
                    || ($params['source'] ?? null) !== 'model' || ($params['trained'] ?? null) !== true) {
                    throw new RuntimeException('Target is not a registered trained model');
                }
                $root = realpath(base_path('..')).DIRECTORY_SEPARATOR;
                $artifact = realpath($root.$target->artifact_path);
                if (! $artifact || ! str_starts_with(strtolower($artifact), strtolower($root)) || ! is_file($artifact)
                    || ! hash_equals($target->artifact_hash, hash_file('sha256', $artifact))) {
                    throw new RuntimeException('Target artifact changed');
                }
                // Retain every registered real version so future retries stay pinned.
                // Retirement is deliberately a separate operator procedure.
                foreach ($models as $model) {
                    if (! str_starts_with($model->version_code, 'stub-') && ($loaded[$model->version_code] ?? null) !== $model->artifact_hash) {
                        throw new RuntimeException('Registered real version unavailable');
                    }
                }
                $processing = DB::table('anomaly_checks')->where('status', 'processing')->distinct()->pluck('model_version_id');
                foreach ($processing as $id) {
                    $model = $models->firstWhere('id', $id);
                    if (! $model || ($loaded[$model->version_code] ?? null) !== $model->artifact_hash) {
                        throw new RuntimeException('Processing pinned version unavailable; drain/expire first');
                    }
                }
                if ($active?->id === $target->id) {
                    return;
                }
                if ($active) {
                    DB::table('model_versions')->where('id', $active->id)->update(['is_active' => false]);
                }
                DB::table('model_versions')->where('id', $target->id)->update(['is_active' => true]);
                DB::table('activity_logs')->insert(['actor_id' => null, 'action' => 'model.handover', 'target_type' => 'model_version', 'target_id' => $target->id,
                    'metadata' => json_encode(['from' => $active?->version_code, 'to' => $version, 'artifact_hash' => $target->artifact_hash]), 'created_at' => now()]);
            }, 3);
        } finally {
            DB::select("SELECT RELEASE_LOCK('lendara:model-activation')");
        }
    }
}
