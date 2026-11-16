<?php

use App\Models\DocumentVersion;
use App\Services\CustomerDocuments;
use App\Services\CustomerWorkspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $run, $mode, $actor, $application] = $argv;
if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || ! Str::isUuid($run)
    || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'kkp_pinjaman_test'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'kkp_pinjaman_test'
    || ! in_array($mode, ['compensation-before', 'compensation-after', 'draft-before', 'draft-after', 'consume'], true)) {
    throw new RuntimeException('Unsafe cleanup-test context');
}
$directory = storage_path('framework/testing/cleanup-crash/'.$run);
if (! is_dir($directory)) {
    throw new RuntimeException('Missing owned fixture directory');
}
config(['filesystems.disks.local.root' => $directory.'/disk', 'queue.default' => 'database', 'queue.connections.database.connection' => 'mysql']);
Storage::forgetDisk('local');
if ($mode === 'consume') {
    exit(Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'cleanup-crash-'.$run, '--stop-when-empty' => true, '--max-jobs' => 1, '--tries' => 1, '--timeout' => 0]));
}
$barrier = function () use ($directory) {
    file_put_contents($directory.'/ready', json_encode(['connection' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'transaction_level' => DB::transactionLevel()]));
    $until = microtime(true) + 30;
    while (! is_file($directory.'/release')) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Cleanup barrier timeout');
        }
        usleep(10000);
        clearstatcache();
    }
};
if ($mode === 'compensation-before') {
    Queue::createPayloadUsing(function () use ($barrier) {
        $barrier();

        return [];
    });
} elseif (in_array($mode, ['compensation-after', 'draft-before'], true)) {
    DB::listen(function ($query) use ($barrier) {
        if (str_starts_with(strtolower($query->sql), 'insert into `jobs`')) {
            $barrier();
        }
    });
}
if (str_starts_with($mode, 'compensation-')) {
    DocumentVersion::creating(fn () => throw new RuntimeException('Synthetic upload metadata failure'));
    try {
        app(CustomerDocuments::class)->upload((int) $actor, (int) $application, (int) DB::table('document_types')->where('code', 'D01')->value('id'),
            UploadedFile::fake()->createWithContent('compensation-fiktif.pdf', "%PDF-1.4\n%%EOF\n"));
    } catch (RuntimeException) {
        // Only reached after the compensation branch; the parent normally interrupts at the barrier.
    }
} else {
    app(CustomerWorkspace::class)->deleteDraft((int) $actor, (int) $application);
    $barrier();
}
