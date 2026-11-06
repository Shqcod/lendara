<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PredictionClient
{
    private function request(string $method, string $path, ?array $payload = null, ?int $timeout = null): array
    {
        $key = config('services.ml.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Kunci layanan ML belum dikonfigurasi secara lokal.');
        }
        $http = Http::acceptJson()->withHeaders(['X-Internal-API-Key' => $key])
            ->connectTimeout(min(3, $timeout ?? config('services.ml.timeout')))->timeout($timeout ?? config('services.ml.timeout'));
        $url = rtrim(config('services.ml.url'), '/').$path;

        try {
            $response = $method === 'GET' ? $http->get($url) : $http->post($url, $payload);
        } catch (ConnectionException $error) {
            throw new RuntimeException('Layanan ML tidak terhubung atau melewati batas waktu.');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('Layanan ML menolak permintaan atau belum siap.');
        }

        return $response->json();
    }

    public function ready(): array
    {
        return $this->request('GET', '/ready');
    }

    public function predict(array $payload, ?int $timeout = null): array
    {
        $result = $this->request('POST', '/predict', $payload, $timeout);
        foreach (['contract_version', 'feature_schema', 'anomaly_check_id', 'submission_id', 'model_version_code'] as $field) {
            if (($result[$field] ?? null) !== ($payload[$field] ?? null)) {
                throw new RuntimeException('Identitas respons ML tidak sesuai permintaan.');
            }
        }
        $score = $result['score'] ?? null;
        $source = config('services.ml.mode') === 'stub' ? 'stub' : 'model';
        if (! is_int($score) && ! is_float($score) || ! is_finite((float) $score)
            || ($result['source'] ?? null) !== $source
            || ($result['score_method'] ?? null) !== 'decision_function'
            || ($result['decision_threshold'] ?? null) !== 0.0 && ($result['decision_threshold'] ?? null) !== 0
            || ($result['is_anomaly'] ?? null) !== ($score < 0)) {
            throw new RuntimeException('Hasil respons ML tidak sesuai kontrak.');
        }

        return $result;
    }
}
