<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait SubmissionFixtures
{
    use DomainFixtures;

    protected function completeApplication(?User $customer = null): int
    {
        $customer ??= User::factory()->create();
        $this->assertSame(0, Artisan::call('ml:register-stub', ['--activate' => true]));
        DB::table('customer_profiles')->insert(['user_id' => $customer->id,
            'identity_number' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'birth_date' => '1995-01-15', 'address' => 'Alamat Fiktif', 'phone' => '+62000000000',
            'occupation_type' => 'Karyawan fiktif', 'workplace_name' => 'Perusahaan Fiktif', 'work_duration_months' => 12,
            'created_at' => now(), 'updated_at' => now()]);
        $application = $this->loan($customer->id, ['loan_amount' => '20000000.01', 'tenor_months' => 12, 'purpose' => 'Demonstrasi',
            'monthly_income' => '8000000.00', 'monthly_expenses' => '3000000.00', 'existing_installments' => '0.00']);
        foreach (DB::table('document_types')->where('is_active', true)->where('is_required', true)->get() as $type) {
            $this->storedDocument($application, $type->id);
        }

        return $application;
    }

    protected function storedDocument(int $application, int $type, int $version = 1): int
    {
        $path = 'loan-documents/'.$application.'/'.Str::uuid().'.pdf';
        $contents = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
        Storage::disk('local')->put($path, $contents);

        return DB::table('document_versions')->insertGetId([
            'application_id' => $application, 'document_type_id' => $type, 'version_number' => $version,
            'original_filename' => 'fiktif-v'.$version.'.pdf', 'storage_path' => $path, 'mime_type' => 'application/pdf',
            'file_size_bytes' => strlen($contents), 'file_hash' => hash('sha256', $contents),
            'uploaded_by' => DB::table('loan_applications')->where('id', $application)->value('customer_id'), 'uploaded_at' => now(),
        ]);
    }
}
