<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SystemProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $probeId) {}

    public function handle(): void
    {
        Cache::put('system.queue.'.$this->probeId, 'completed', 3600);
    }
}
