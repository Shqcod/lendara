<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class ModelRegistration
{
    public function register(string $path, string $expectedHash, bool $activate = false): string
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (config('services.ml.mode') !== 'model' || ! preg_match('/^[a-f0-9]{64}$/D', $expectedHash) || $bytes === false || ! hash_equals($expectedHash, hash('sha256', $bytes))) {
            throw new RuntimeException('Untrusted metadata or runtime mode');
        }
        $meta = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        Validator::make($meta, [
            'version_code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', 'not_regex:/^stub-/'],
            'algorithm' => ['required', 'in:IsolationForest'], 'source' => ['required', 'in:model'], 'trained' => ['accepted'],
            'artifact_path' => ['required', 'string', 'max:255'], 'artifact_hash' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'training_data_version' => ['required', 'string', 'max:100'], 'protocol_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'contamination' => ['required', 'numeric', 'gt:0', 'max:0.5'], 'score_method' => ['required', 'in:decision_function'],
            'decision_threshold' => ['required', 'numeric', 'in:0'], 'feature_names' => ['required', 'array'],
            'parameters' => ['required', 'array'], 'dependencies' => ['required', 'array'],
        ])->validate();
        $raw = ['monthly_income', 'monthly_expenses', 'existing_installments', 'loan_amount', 'tenor_months'];
        $parameters = $meta['parameters'];
        $features = ($parameters['variant'] ?? null) === 'B' ? [...$raw, 'installments_to_income', 'loan_to_income', 'expenses_to_income'] : $raw;
        if (! in_array($parameters['variant'] ?? null, ['A', 'B'], true) || ($parameters['raw_input_names'] ?? null) !== $raw
            || ($parameters['feature_schema'] ?? null) !== 'loan-finance-v1' || $meta['feature_names'] !== $features
            || $meta['trained'] !== true || ! is_numeric($parameters['seed'] ?? null)
            || abs((float) $meta['contamination'] - (float) number_format((float) $meta['contamination'], 5, '.', '')) > 1e-10) {
            throw new RuntimeException('Feature/precision metadata mismatch');
        }
        $root = realpath(base_path('..')).DIRECTORY_SEPARATOR;
        $artifact = realpath($root.$meta['artifact_path']);
        if (! $artifact || ! str_starts_with(strtolower($artifact), strtolower($root)) || ! is_file($artifact)
            || ! hash_equals($meta['artifact_hash'], hash_file('sha256', $artifact))) {
            throw new RuntimeException('Artifact hash/path mismatch');
        }
        // Network readiness is obtained before this command takes any model lock/transaction.
        if ($activate) {
            $ready = app(PredictionClient::class)->ready();
            $matched = collect($ready['loaded_models'] ?? [])->contains(fn ($m) => ($m['model_version_code'] ?? null) === $meta['version_code'] && ($m['artifact_hash'] ?? null) === $meta['artifact_hash']);
            if (($ready['ready'] ?? null) !== true || ($ready['mode'] ?? null) !== 'model' || ($ready['contract_version'] ?? null) !== '1' || ! $matched) {
                throw new RuntimeException('Runtime model/hash not ready');
            }
        }
        if ((int) DB::selectOne("SELECT GET_LOCK('lendara:model-activation', 10) AS acquired")->acquired !== 1) {
            throw new RuntimeException('Model registry busy');
        }
        try {
            DB::transaction(function () use ($meta, $parameters, $activate) {
                $values = [
                    'algorithm' => $meta['algorithm'], 'feature_names' => json_encode($meta['feature_names']),
                    'parameters' => json_encode([...$parameters, 'source' => 'model', 'trained' => true, 'protocol_sha256' => $meta['protocol_sha256'], 'dependencies' => $meta['dependencies']]),
                    'contamination' => number_format((float) $meta['contamination'], 5, '.', ''),
                    'decision_threshold' => 0.0, 'score_method' => 'decision_function', 'artifact_path' => $meta['artifact_path'],
                    'artifact_hash' => $meta['artifact_hash'], 'training_data_version' => $meta['training_data_version'],
                ];
                $existing = DB::table('model_versions')->where('version_code', $meta['version_code'])->lockForUpdate()->first();
                if ($existing) {
                    foreach ($values as $key => $value) {
                        $same = in_array($key, ['parameters', 'feature_names'], true) ? json_decode($existing->$key, true) == json_decode($value, true) : (string) $existing->$key === (string) $value;
                        if (! $same) {
                            throw new RuntimeException('Immutable model conflict');
                        }
                    }
                }
                if ($activate && DB::table('model_versions')->where('is_active', true)->where('version_code', '<>', $meta['version_code'])->lockForUpdate()->exists()) {
                    throw new RuntimeException('Other active model preserved');
                }
                $id = $existing?->id;
                if (! $id) {
                    $id = DB::table('model_versions')->insertGetId([...$values, 'version_code' => $meta['version_code'], 'is_active' => false, 'created_at' => now()]);
                    $this->audit('model.registered', $id, $meta);
                }
                if ($activate && ! ($existing?->is_active ?? false)) {
                    DB::table('model_versions')->where('id', $id)->update(['is_active' => true]);
                    $this->audit('model.activated', $id, $meta);
                }
            });
        } finally {
            DB::select("SELECT RELEASE_LOCK('lendara:model-activation')");
        }

        return $meta['version_code'];
    }

    private function audit(string $action, int $id, array $meta): void
    {
        DB::table('activity_logs')->insert(['actor_id' => null, 'action' => $action, 'target_type' => 'model_version', 'target_id' => $id,
            'metadata' => json_encode(['version_code' => $meta['version_code'], 'artifact_hash' => $meta['artifact_hash']]), 'created_at' => now()]);
    }
}
