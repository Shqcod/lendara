# Validasi dokumen privat nasabah — 6 November 2026

Windows/PowerShell, PHP 8.3.36, MySQL 8.0.30 Laragon, Node portable 22.23.4, Chrome melalui Playwright 1.65.0. Major/dependensi dan lockfile tetap. Schema tidak berubah, database pengembangan tidak dimutasi. Tidak ada reset DB atau perubahan .env/php.ini global.

## Backend

- `php artisan system:assert-test-database --env=testing`: lulus.
- `php artisan test --filter=DatabaseIsolationTest --no-ansi`: 1 lulus/8 assertions; PDO efektif `mysql / kkp_pinjaman_test / 8.0.30`, bukan hanya konfigurasi Artisan.
- `php artisan test --filter=CustomerDocumentsTest --no-ansi`: akhir 12 lulus/125 assertions. PDF/PNG/JPG/JPEG nyata, batas tepat 5.242.880 byte dan lebih satu byte, isi/ekstensi, kepemilikan, scope peran/status, nonaktif, attachment/cache/nosniff, versi/snapshot lama, resource, rollback dan compensation.
- `php artisan test --no-ansi`: akhir **65 lulus/568 assertions**, exit 0. Output aman di `documents-php-tests-2026-11-06.txt`.
- `php vendor/bin/pint --test`: lulus, exit 0.
- Konkurensi memakai worker PHP/koneksi terpisah, barrier dan transaksi overlap. Dokumen: hasil created/created, versi 1/2, dua path/hash/audit; draf dan assignment tetap lulus. Fixture unik tersimpan pada DB test; disk fake untuk feature test biasa, disk privat lingkungan test untuk worker/browser.

Kegagalan awal 2/12 berasal dari fixture UploadedFile yang menebak MIME dari ekstensi serta helper JSON yang mengganti Content-Length. Implementasi memakai fileinfo isi langsung; uji 413 memakai Content-Length 4G karena php.ini lokal 2G. Tidak mengendurkan batas produk. Kegagalan audit sintetis hanya disuntikkan dalam test untuk membuktikan rollback + cleanup versi baru.

## Frontend dan runtime

- `powershell -ExecutionPolicy Bypass -File .\scripts\frontend.ps1 run check`: 0 error/0 warning setelah koreksi anotasi props/ref komponen (awal 2 error/1 warning).
- `powershell -ExecutionPolicy Bypass -File .\scripts\frontend.ps1 run build`: lulus. Adapter-auto belum memilih target deployment; warning NO_COLOR/FORCE_COLOR tetap tampil.
- Parser seluruh scripts/*.ps1: lulus.
- Helper backend menggunakan router resmi Laravel pada PHP built-in server: proses upload 5M, post 8M, display_errors=0; tidak mengubah php.ini global/.env. Guard testing dan cookie test dipertahankan. PHP log error tetap aktif; webserver/proxy deployment belum dibuktikan.

## Chrome

- Run penuh awal: 3 lulus/1 gagal. Regresi auth dan profil/draf lulus; upload kedua skenario dokumen mendapat deadlock MySQL saat PHPUnit berjalan bersamaan pada DB test. Kedua jenis suite sekarang dijalankan berurutan.
- Pengulangan hanya documents.spec.ts melalui array -NpmArgs: **1 passed (32.9s)**, exit 0.
- Run penuh akhir sesudah PHPUnit selesai: `powershell -ExecutionPolicy Bypass -File .\scripts\frontend.ps1 run test:e2e`: **4 passed (1.1m)**, exit 0. Auth 7.6s, kegagalan jaringan auth 2.3s, profil/draf 27.6s, dokumen 26.3s. Tidak menjalankan suite DB lain bersamaan. Raw output lokal berada di `.tools/documents-browser-final.log` (diabaikan Git); ringkasan aman ini menjadi bukti repository.
- Dokumen membuktikan: multipart cookie SPA/CSRF/CORS, penolakan UI file >5 MB, 422 isi palsu, upload tepat 5 MB, penggantian versi, download byte identik/nama attachment, versi lama masih 5 MB, POST server 9 MB menjadi 413 JSON yang ditangani UI, respons 201 diputus setelah commit, tidak ada replay POST, baca ulang menunjukkan versi 3, reload, desktop dan 320px tanpa overflow halaman.
- Screenshot panel `documents-desktop.png`, `documents-mobile-320.png`, serta screenshot ruang nasabah yang diperbarui sudah diperiksa visual. Tidak merekam kredensial, token, path privat atau identitas nyata. Jenis dokumen fixture tambahan pada DB test dapat terlihat; tidak ditiru ke dev.

## Batas

Hanya irisan nasabah. Internal/admin tetap 403; scope dokumen tahap internal menunggu workflow peninjauan. Snapshot pada unit/feature test adalah fixture, bukan endpoint submit/resend. Tidak ada pengiriman, prediksi persisten, claim/approval/admin, model asli/generator/evaluasi, runtime grants, proof timeout POSIX, antivirus/full PDF parsing, atau rekonsiliasi crash orphan yang diklaim selesai. ML/dependensi tidak berubah dan pengujian/auditnya tidak diulang.
