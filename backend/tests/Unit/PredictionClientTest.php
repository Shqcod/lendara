<?php

namespace Tests\Unit;

use App\Services\PredictionClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PredictionClientTest extends TestCase
{
    public function test_internal_fixture_uses_private_key_and_validates_response(): void
    {
        config(['services.ml.key' => 'test-key', 'services.ml.mode' => 'stub']);
        $payload = json_decode(file_get_contents(base_path('../fixtures/prediction-request.json')), true);
        $result = json_decode(file_get_contents(base_path('../fixtures/prediction-response.json')), true);
        Http::preventStrayRequests();
        Http::fake(['*/predict' => Http::response($result)]);

        $this->assertEquals($result, app(PredictionClient::class)->predict($payload));
        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Internal-API-Key', 'test-key') && $request->data() === $payload);
        Http::assertSentCount(1);
    }

    public function test_mismatched_identity_or_sign_is_rejected(): void
    {
        config(['services.ml.key' => 'test-key', 'services.ml.mode' => 'stub']);
        $payload = json_decode(file_get_contents(base_path('../fixtures/prediction-request.json')), true);
        $result = json_decode(file_get_contents(base_path('../fixtures/prediction-response.json')), true);
        $result['is_anomaly'] = ! $result['is_anomaly'];
        Http::fake(['*/predict' => Http::response($result)]);

        $this->expectException(RuntimeException::class);
        app(PredictionClient::class)->predict($payload);
    }
}
