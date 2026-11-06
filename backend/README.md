# Lendara — API

Laravel 12 + Sanctum cookie SPA + MySQL. Panduan lingkungan, konfigurasi lokal, migration aman, dan perintah pengujian ada di [SETUP](../docs/SETUP.md); status aktual ada di [PROGRESS](../docs/PROGRESS.md).

Dari root proyek: `powershell -ExecutionPolicy Bypass -File .\scripts\start-backend.ps1` untuk batas upload proses 5M/post 8M tanpa mengubah php.ini/.env. Frontend berada di layanan terpisah, `http://localhost:5173`.

Pengujian: `php artisan test` dan `php vendor/bin/pint --test`. PHPUnit memverifikasi koneksi efektif `kkp_pinjaman_test` sebelum transaksi. Jangan menjalankan migration fresh/refresh atau mengarahkan pengujian ke database pengembangan.

Endpoint tersedia: `/sanctum/csrf-cookie`, `/api/v1/auth/register`, `/api/v1/auth/login`, `/api/v1/me`, `/api/v1/auth/logout`, `/api/v1/customer/profile` (GET/PATCH), dan `/api/v1/applications` (list/create/detail/update/delete draf). Password menggunakan Argon2id, satu akun satu peran, dan registrasi hanya nasabah. Route profil/draf hanya nasabah aktif; data nasabah lain 404. Uang API berbentuk string desimal.

Schema 14 tabel domain tersedia. Jalankan migration biasa setelah inspeksi schema, lalu DocumentTypeSeeder untuk D01–D04. Dokumen nasabah tersedia melalui `/api/v1/document-types`, GET/POST `/api/v1/applications/{id}/documents`, dan `/api/v1/document-versions/{id}/download`. Upload PDF/JPG/JPEG/PNG maksimal 5 MB menambah versi baru di disk privat; download attachment memeriksa kepemilikan. Pengiriman snapshot, akses dokumen internal sesuai tahap, dan persetujuan internal belum tersedia; baca kontrak dan progres sebelum melanjutkan.
