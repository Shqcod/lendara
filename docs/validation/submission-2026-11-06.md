# Validasi pengiriman snapshot — 6 November 2026

Windows/PowerShell, PHP 8.3.36, MySQL 8.0.30 Laragon, Node portable 22.23.4, Chrome/Playwright 1.65.0. Major/dependensi/lockfile tetap. Tidak ada migration/reset/drop/truncate, perubahan .env atau mutasi database pengembangan. Semua pengujian data memakai koneksi test efektif yang diguard sebelum transaksi.

## Backend dan konkurensi

- `php artisan system:assert-test-database --env=testing`: PASS; `php artisan test --filter=DatabaseIsolationTest --no-ansi`: 1 lulus/8 assertions, PDO **mysql / kkp_pinjaman_test / 8.0.30**.
- `php artisan test --no-ansi`: akhir **85 lulus / 752 assertions**, exit 0 (14.67s; run sebelumnya 23.27s). Output aman tanpa rahasia pada `submission-php-tests-2026-11-06.txt`.
- `php vendor/bin/pint --test`: lulus. Parser seluruh skrip PowerShell: lulus.
- CustomerSubmissionsTest: data tersimpan, pointer/konfirmasi literal, validasi lengkap tanpa baris parsial, model tidak siap/mode salah/production menolak stub, rollback audit, kirim ulang dan snapshot/tautan lama, allowlist/resource/role/ownership/nested scope/pagination, alasan revisi hanya request_revision. Revisi berasal dari fixture, bukan endpoint verifikator.
- DomainConcurrencyTest: dua PHP/koneksi/transaksi independen dengan barrier, satu snapshot/check/history/log. Run 80 test menunjukkan CONNECTION_ID 995/996 → created/SUBMISSION_VERSION_CONFLICT; ID koneksi run akhir tersedia pada output tersimpan. Regresi race draf/assignment/upload tetap lulus. Bukan dua request serial yang disebut race.
- SubmissionCommitTest tanpa outer rollback: job database queue dan snapshot/check terlihat oleh observer MySQL independen setelah commit; kegagalan Bus dispatch disuntikkan pada transactionLevel 0, HTTP tetap 202 dan check failed secara kondisional. Dokumen untuk payload 202 sudah dimuat sebelum commit; test melempar jika controller mencoba query ulang document_versions setelah kegagalan enqueue. Suite 85 test diulang sesudah perbaikan ini dan tetap lulus; kontrak payload tidak berubah.
- PredictionJobTest: transaksi nol saat HTTP, model dipin, hasil anomali tidak mengubah pending_verification; timeout koneksi/service failure simulasi, budget habis tanpa HTTP, deadline/hasil terlambat/duplikat/identitas/model/INF/tanda tidak valid, snapshot lama, database execution lock, dan schedule:run subprocess expiry pada testing. StubRegistrationTest: idempoten, opsi aktif eksplisit, tidak mengganti model aktif/metadata, guard production/mode.

Kegagalan awal: production mengaktifkan CSRF sehingga HTTP feature test guard menghasilkan 419 (guard diuji lewat service tanpa mematikan CSRF); helper bernama run menimpa final PHPUnit (diganti executeJob). Seluruh suite akhir lulus. Tidak ada hasil palsu/SQLite/refresh database.

## Pembuktian worker dan stub nyata

FastAPI stub diluncurkan tersembunyi pada 127.0.0.1:8001 dengan ML_ENV=testing, kunci dibaca lokal tanpa dicetak. Skrip lokal `.tools/submission-smoke.php` (diabaikan Git) memeriksa database efektif testing sebelum menambah fixture fiktif, menggunakan service profil/draf/upload/submit yang sama, serta memastikan /ready memuat stub-v0.

Command worker: `php artisan queue:work --env=testing --queue=predictions --stop-when-empty --max-jobs=50 --max-time=60 --tries=1 --timeout=0`.

Hasil: pengiriman test 175, check 134, enam job test selesai (job lama terminal/expired tidak melakukan inference ulang). Verifikasi DB: check **succeeded**, score **0.08**, is_anomaly **false**, aplikasi tetap **pending_verification**. Source/ID/model diperiksa oleh PredictionClient dan conditional persistence; request ke /predict memakai layanan stub nyata. Proses smoke kemudian dihentikan setelah identitas PID/command line diverifikasi. Tidak mengganti .env atau membagikan kunci. Ini bukan model terlatih/evaluasi atau proof penghentian worker POSIX.

## Frontend dan Chrome

- `scripts/frontend.ps1 run check`: 0 error/0 warning setelah prop URL menjadi $derived (awal satu warning capture).
- `scripts/frontend.ps1 run build`: lulus; target deployment adapter-auto belum dipilih, NO_COLOR/FORCE_COLOR tetap terlihat.
- Skenario dokumen diperluas agar memakai akun browser yang sama untuk tinjau/kirim, tanpa melonggarkan throttle registrasi. Confirm/cancel, 422 kelengkapan, respons 202 yang diputus setelah commit, no replay, baca ulang pending_verification/arsip/status, unduh arsip byte identik, profil terbaru tidak mengubah snapshot, reload, desktop/320px terbukti.
- Run pertama gagal pada assertion 5 detik setelah create 201 saat batch request masih berjalan; harness expect 15 detik kemudian run **1 passed (2.5m)**. Run penuh berikutnya **3 passed/1 failed** karena skenario profil/draf mencapai batas total 60 detik sesudah DELETE berhasil 204. Skenario profil/draf diberi batas 120 detik; batas bisnis dan HTTP aplikasi tidak diubah.
- Run penuh akhir `powershell -ExecutionPolicy Bypass -File .\scripts\frontend.ps1 run test:e2e`: **4 passed (3.6m)**, exit 0; auth 17.2s, network auth 4.8s, profil/draf 1.1m, dokumen/pengiriman 2.0m. Raw log lokal `.tools/submission-browser-verified.log` diabaikan Git. PHPUnit dan browser dijalankan berurutan, tanpa menonaktifkan throttle/CSRF/CORS.
- Screenshot `submission-desktop.png`/`submission-mobile-320.png` serta screenshot dokumen yang diperbarui diperiksa visual: identitas/keuangan/berkas fiktif saja, tanpa kredensial/token/path privat. Jenis fixture tambahan pada DB test dapat tampil; database dev tidak diubah. Trace/video/screenshot otomatis tetap off.

## Batas yang belum selesai

Antrean/claim/release dan scope internal, verifikasi/persetujuan/admin, retry manual, browser revisi nyata, generator/model asli/evaluasi, grant DB runtime, orphan crash reconciliation, server deployment/proxy, HTTP timeout nyata serta kill worker Linux/WSL. Tidak ada eksperimen model atau audit dependensi baru pada sesi ini. `ML_DEADLINE_SECONDS` masih belum dibaca runtime; deadline domain tetap 120 detik sesuai kontrak.
