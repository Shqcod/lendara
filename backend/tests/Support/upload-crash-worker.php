<?php

use App\Models\DocumentVersion;
use App\Services\CustomerDocuments;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $run, $mode, $actor, $application] = $argv;
if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || ! Str::isUuid($run)
    || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'kkp_pinjaman_test'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'kkp_pinjaman_test'
    || ! in_array($mode, ['file', 'pending-kill', 'pending-commit', 'committed'], true)) {
    throw new RuntimeException('Unsafe crash-test context');
}
$directory = storage_path('framework/testing/upload-crash/'.$run);
if (! is_dir($directory)) {
    throw new RuntimeException('Missing owned test directory');
}
config(['filesystems.disks.local.root' => $directory.'/disk']);
Storage::forgetDisk('local');
$barrier = function (string $phase) use ($directory) {
    file_put_contents($directory.'/'.$phase.'.ready', json_encode(['connection' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'transaction_level' => DB::transactionLevel()]));
    $until = microtime(true) + 30;
    while (! is_file($directory.'/'.$phase.'.release')) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Crash-test barrier timeout');
        }
        usleep(10000);
        clearstatcache();
    }
};
if ($mode === 'file') {
    DocumentVersion::creating(fn () => $barrier('file'));
} elseif (str_starts_with($mode, 'pending-')) {
    DB::listen(function ($query) use ($barrier) {
        if (str_starts_with(strtolower($query->sql), 'insert into `document_versions`')) {
            $barrier('pending');
        }
    });
}
$type = DB::table('document_types')->where('code', 'D01')->value('id');
app(CustomerDocuments::class)->upload((int) $actor, (int) $application, (int) $type,
    UploadedFile::fake()->createWithContent('crash-fiktif.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"));
$barrier('committed');
