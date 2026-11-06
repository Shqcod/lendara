<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach ([['D01', 'Identitas', true], ['D02', 'Bukti penghasilan', true], ['D03', 'Rekening koran', true], ['D04', 'Tambahan', false]] as [$code, $name, $required]) {
                $existing = DB::table('document_types')->where('code', $code)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->name !== $name || (bool) $existing->is_required !== $required || ! $existing->is_active) {
                        throw new RuntimeException('Konfigurasi jenis dokumen '.$code.' berbeda dari acuan. Periksa tanpa menimpa alur yang aktif.');
                    }

                    continue;
                }
                DB::table('document_types')->insert([
                    'code' => $code, 'name' => $name, 'is_required' => $required, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}
