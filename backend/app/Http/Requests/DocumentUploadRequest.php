<?php

namespace App\Http\Requests;

use App\Exceptions\DomainError;
use App\Models\LoanApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class DocumentUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = LoanApplication::where('customer_id', $this->user()->id)->whereKey($this->route('application'))->firstOrFail();
        if (! $application->status->editable()) {
            throw new DomainError('APPLICATION_NOT_EDITABLE', 'Dokumen hanya dapat diunggah saat draf atau diminta revisi.');
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],
            'file' => ['required', 'file', 'max:5120', 'extensions:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png',
                function ($attribute, $file, $fail) {
                    if (! $file instanceof UploadedFile || ! $file->isValid()) {
                        return;
                    }
                    $name = $file->getClientOriginalName();
                    if (mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
                        $fail('Nama berkas tidak valid atau melebihi 255 karakter.');
                    }
                    $extension = strtolower($file->getClientOriginalExtension());
                    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
                    $expected = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
                    if (($expected[$extension] ?? null) !== $mime) {
                        $fail('Ekstensi harus sesuai isi PDF, JPG, JPEG, atau PNG.');
                    } elseif ($mime === 'application/pdf') {
                        $handle = fopen($file->getRealPath(), 'rb');
                        $signature = fread($handle, 5);
                        fclose($handle);
                        if ($signature !== '%PDF-') {
                            $fail('Isi PDF tidak valid.');
                        }
                    } elseif (@getimagesize($file->getRealPath()) === false) {
                        $fail('Isi gambar tidak valid.');
                    }
                },
            ],
            'application_id' => ['prohibited'], 'version_number' => ['prohibited'],
            'storage_path' => ['prohibited'], 'uploaded_by' => ['prohibited'], 'file_hash' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'Ukuran berkas maksimal 5 MB.',
            'file.extensions' => 'Pilih berkas PDF, JPG, JPEG, atau PNG.',
            'file.mimetypes' => 'Isi berkas harus PDF, JPG, JPEG, atau PNG.',
            'document_type_id.exists' => 'Jenis dokumen tidak tersedia untuk unggahan.',
        ];
    }
}
