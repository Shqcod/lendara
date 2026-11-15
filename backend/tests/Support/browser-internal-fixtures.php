<?php

// CLI-only, guarded fixtures for Chrome. Passwords arrive on stdin, never argv/logs/files.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || config('database.default') !== 'mysql'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'kkp_pinjaman_test') {
    throw new RuntimeException('Unsafe fixture database.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/\A[a-f0-9-]{36}\z/', $input['run'] ?? '') || strlen($input['password'] ?? '') < 16) {
    throw new RuntimeException('Invalid fixture input.');
}
$users = [];
foreach (['one' => 'verifikator', 'two' => 'verifikator', 'l1' => 'penyetuju_1', 'l2' => 'penyetuju_2', 'admin' => 'admin'] as $key => $role) {
    $users[$key] = User::factory()->create(['name' => 'Peninjau Fiktif '.$key, 'email' => 'internal-'.$input['run'].'-'.$key.'@example.test', 'role' => $role, 'password' => $input['password']]);
}
$type = DB::table('document_types')->where('code', 'D01')->value('id');
$model = DB::table('model_versions')->where('version_code', 'stub-v0')->value('id');
if (! $type || ! $model) {
    throw new RuntimeException('Canonical test fixtures not ready.');
}
$applications = [];
foreach (['verification' => 'pending_verification', 'l1' => 'pending_approval_1', 'l2' => 'pending_approval_2'] as $key => $status) {
    $customer = User::factory()->create(['name' => 'Nasabah Peninjauan Fiktif', 'email' => 'customer-'.$input['run'].'-'.$key.'@example.test']);
    $number = 'INT-'.Str::ulid();
    $id = DB::table('loan_applications')->insertGetId(['application_number' => $number, 'customer_id' => $customer->id, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    $versions = [];
    foreach ([1, 2] as $version) {
        $snapshot = ['name' => $version === 1 ? 'Nasabah Arsip Fiktif' : 'Nasabah Terkini Fiktif', 'identity_number' => '0000000000000000', 'birth_date' => '1995-01-15',
            'address' => 'Alamat Fiktif', 'phone' => '+62000000000', 'occupation_type' => 'Karyawan fiktif', 'workplace_name' => 'Perusahaan Fiktif', 'work_duration_months' => 24];
        $submission = DB::table('application_submissions')->insertGetId(['application_id' => $id, 'version_number' => $version, 'customer_snapshot' => json_encode($snapshot),
            'loan_amount' => '20000000.01', 'tenor_months' => 12, 'purpose' => 'Demonstrasi peninjauan fiktif', 'monthly_income' => '8000000.00',
            'monthly_expenses' => '3000000.00', 'existing_installments' => '0.00', 'submitted_by' => $customer->id, 'submitted_at' => now()]);
        $contents = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
        $filename = $version === 1 ? 'arsip-fiktif.pdf' : 'terkini-fiktif.pdf';
        $path = 'loan-documents/browser-fixtures-'.$input['run'].'/'.$id.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $contents);
        $document = DB::table('document_versions')->insertGetId(['application_id' => $id, 'document_type_id' => $type, 'version_number' => $version,
            'original_filename' => $filename, 'storage_path' => $path, 'mime_type' => 'application/pdf', 'file_size_bytes' => strlen($contents),
            'file_hash' => hash('sha256', $contents), 'uploaded_by' => $customer->id, 'uploaded_at' => now()]);
        DB::table('submission_documents')->insert(['submission_id' => $submission, 'document_type_id' => $type, 'document_version_id' => $document, 'created_at' => now()]);
        DB::table('anomaly_checks')->insert(['submission_id' => $submission, 'model_version_id' => $model, 'attempt_number' => 1, 'status' => ($input['retry'] ?? false) && $key === 'verification' ? 'failed' : 'succeeded',
            'input_features' => json_encode(['monthly_income' => 8000000, 'monthly_expenses' => 3000000, 'existing_installments' => 0, 'loan_amount' => 20000000.01, 'tenor_months' => 12]),
            'score' => ($input['retry'] ?? false) && $key === 'verification' ? null : -0.12, 'is_anomaly' => ($input['retry'] ?? false) && $key === 'verification' ? null : true, 'failure_reason' => ($input['retry'] ?? false) && $key === 'verification' ? 'fixture_failure' : null, 'started_at' => now(), 'deadline_at' => now()->addSeconds(120), 'finished_at' => now()]);
        $versions[$version] = ['id' => $submission, 'document' => $document];
    }
    DB::table('loan_applications')->where('id', $id)->update(['current_submission_id' => $versions[2]['id']]);
    DB::table('application_status_histories')->insert(['application_id' => $id, 'submission_id' => $versions[2]['id'], 'from_status' => 'draft',
        'to_status' => 'pending_verification', 'changed_by' => $customer->id, 'changed_at' => now()]);
    $applications[$key] = ['id' => $id, 'number' => $number, 'current' => $versions[2]['id'], 'old' => $versions[1]['id']];
}
echo json_encode(['applications' => $applications, 'users' => array_map(fn ($user) => ['id' => $user->id, 'email' => $user->email], $users)], JSON_THROW_ON_ERROR);
