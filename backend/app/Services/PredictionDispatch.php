<?php

namespace App\Services;

use App\Jobs\RunPrediction;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PredictionDispatch
{
    public function afterCommit(int $check): void
    {
        DB::afterCommit(function () use ($check) {
            try {
                Bus::dispatch((new RunPrediction($check))->onConnection('database')->onQueue('predictions'));
            } catch (Throwable) {
                Log::error('Job prediksi gagal dijadwalkan setelah pengiriman commit.', ['anomaly_check_id' => $check]);
                try {
                    app(PredictionResults::class)->fail($check, 'dispatch_failed');
                } catch (Throwable) {
                    Log::error('Status kegagalan dispatch belum tersimpan; scheduler akan memulihkan deadline.', ['anomaly_check_id' => $check]);
                }
            }
        });
    }
}
