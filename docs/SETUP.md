# Menjalankan dan menguji Lendara

Status aktual ada di PROGRESS.md. Setup, schema, irisan nasabah/job prediksi, serta antrean/detail/claim/release internal lulus MySQL nyata dan Chrome. Akses dokumen reviewer mengikuti tahap dan tautan snapshot. Aksi verifikasi/persetujuan/retry manual, admin, model asli dan browser revisi/kirim ulang nyata masih tahap berikutnya.

## Runtime

- PHP 8.3.36 dengan pdo_mysql, curl, zip, dan dukungan Argon2id; Laravel 12.71.0 dan Sanctum 4.3.3 dikunci di `backend/composer.lock`.
- SvelteKit 3.1.1, Svelte 5.57.2, TypeScript 6.0.3, Vite 8.3.6, plugin Svelte 7.3.1; versi dikunci di `frontend/package-lock.json`. Frontend memerlukan Node seri 22 minimal 22.17. Node sistem 22.13.1 tidak memenuhi engine SvelteKit 3.
- Node 22.23.4 portable telah dipasang ke `.tools/` dari nodejs.org dan diperiksa SHA256, tanpa mengubah instalasi/PATH sistem. `scripts/frontend.ps1` memilih Node lokal hanya selama proses perintah.
- Untuk menyiapkan runtime lokal di checkout lain: `powershell -ExecutionPolicy Bypass -File .\scripts\install-node-local.ps1`. Skrip mengunduh patch terbaru seri 22 dari sumber resmi dan memverifikasi checksum sebelum ekstraksi; folder runtime yang sudah ada dipertahankan.
- Python 3.12.4 tersedia melalui `python` dan venv. Launcher `py -3.12` gagal pada sesi ini; `check-environment.ps1` kini memakai venv atau executable `python`.

Konfigurasi SPA mengikuti [dokumentasi Sanctum Laravel 12](https://laravel.com/docs/12.x/sanctum); kebutuhan runtime dan konfigurasi frontend diperiksa terhadap [migrasi SvelteKit 3](https://svelte.dev/docs/kit/migrating-to-sveltekit-3) serta metadata paket yang dipasang.

## Konfigurasi lokal dan database

`backend/.env` dan `backend/.env.testing` dibuat dari contoh tanpa menimpa berkas yang sudah ada. APP_KEY dibuat secara lokal dan tidak dicetak. Isi `DB_USERNAME` dan `DB_PASSWORD` langsung di masing-masing berkas, tanpa mengirimkannya ke chat. Password database boleh kosong hanya jika memang demikian konfigurasi MySQL lokal Anda.

Pertahankan database terkonfigurasi. Acuan dev `kkp_pinjaman`, test `kkp_pinjaman_test`; perubahan nama produk tidak mengganti database. Nyalakan MySQL Laragon, bukan server MariaDB/XAMPP yang kebetulan ada pada PATH. Periksa `SELECT VERSION()` dan `SHOW TABLES` melalui koneksi lokal sebelum migration. Jika ada tabel lama/berbenturan, bandingkan schema dan berhenti untuk pemeriksaan; jangan reset data.

Tiga migration awal mencakup users sesuai lima peran, sessions, password_reset_tokens, cache/cache_locks, jobs, job_batches, dan failed_jobs. Migration berikutnya menyelaraskan timestamp users ke DATETIME, lalu menambahkan 13 tabel domain tersisa tanpa membuat users kedua. CHECK, generated unique, serta FK komposit/RESTRICT diuji pada MySQL 8.0.30. Tidak ada seeder akun/kata sandi bawaan atau model aktif.

Setelah kredensial dan isi schema diperiksa, dari `backend` jalankan migration biasa pada database yang memang siap, tanpa `fresh`, `refresh`, DROP, atau TRUNCATE:

```powershell
php artisan migrate:status
php artisan migrate
php artisan migrate:status --env=testing
php artisan migrate --env=testing
php artisan system:assert-test-database --env=testing
php artisan db:seed --class=DocumentTypeSeeder
php artisan db:seed --class=DocumentTypeSeeder --env=testing
```

DocumentTypeSeeder menginisialisasi D01–D04 yang belum ada. Seeder idempoten dan berhenti jika konfigurasi yang sudah ada berbeda dari acuan, sehingga tidak mengubah jenis wajib/aktif pada alur yang berjalan. Seeder tidak dipanggil otomatis pada setiap request atau startup.

Pengujian memerlukan `APP_ENV=testing`, koneksi mysql, serta database `kkp_pinjaman_test` yang telah dikonfirmasi pengguna. Guard PHPUnit memeriksa konfigurasi dan hasil `SELECT DATABASE()` dari koneksi PDO efektif **sebelum** transaksi uji. DatabaseIsolationTest juga mencetak bukti `mysql / kkp_pinjaman_test / 8.0.30`. `DatabaseTransactions` membatalkan data feature test biasa, tanpa membuat ulang tabel. Test konkurensi/visibilitas commit memakai fixture unik yang dipersistenkan hanya pada DB test agar terlihat dari koneksi/proses independen; tidak ada pembersihan massal database. Browser membuat akun/profil/draf fiktif dan menghapus hanya draf yang dibuat oleh skenario itu sendiri melalui API. Guard server browser juga memeriksa koneksi efektif dan keberadaan seluruh tabel domain. Jika nama DB test suatu saat perlu diubah, sesuaikan guard secara eksplisit setelah konfirmasi nama baru; jangan mengganti nama database otomatis.

## Menjalankan layanan

Semua browser menggunakan `localhost`; `127.0.0.1` hanya bind backend atau komunikasi internal. CORS tepat `http://localhost:5173`, cookie host-only, SameSite=lax, HTTP lokal. Tidak ada autentikasi SSR; permintaan akun dijalankan browser dengan credentials dan X-XSRF-TOKEN. Data pengguna tidak disimpan pada store global server.

Terminal root untuk frontend:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\frontend.ps1 run dev
```

Terminal root untuk backend: `powershell -ExecutionPolicy Bypass -File .\scripts\start-backend.ps1`. Halaman masuk/daftar dan pemeriksaan sesi ada di `http://localhost:5173/`. Setelah login nasabah, buka `/customer` untuk profil/draf/dokumen, lalu **Tinjau dan lihat pengiriman** menuju `/customer/applications/{id}`. Halaman tinjau memakai data tersimpan; konfirmasi diperlukan sebelum kirim, dan arsip menyimpan identitas/keuangan/berkas per versi. Uang publik tetap string desimal.

Helper menjalankan PHP built-in server pada 127.0.0.1:8000 dari direktori public dengan router bawaan Laravel. Parameter proses upload_max_filesize=5M, post_max_size=8M, display_errors=0; log error PHP tetap aktif. Ini tidak mengubah php.ini global (Laragon saat diperiksa mengizinkan 2G) atau .env. Pada server demo/deployment, samakan batas PHP dan webserver/reverse proxy; pengujian lokal bukan bukti konfigurasi deployment. File tepat 5.242.880 byte diterima. File lebih besar mendapat 422 file; total POST lebih dari 8M mendapat 413 PAYLOAD_TOO_LARGE sebelum parsing field. Validasi isi server tetap wajib walau UI sudah membatasi ukuran/ekstensi.

Terminal root untuk ML:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-ml.ps1
```

Terminal root lain: `powershell -ExecutionPolicy Bypass -File .\scripts\sync-ml-key.ps1`, lalu dari backend `php artisan ml:smoke`. Sinkronisasi hanya mengisi kunci backend yang kosong dari .env ML lokal; kunci yang telah diisi dipertahankan dan nilainya tidak dicetak. Smoke menggunakan fixtures, tidak membuat pengajuan/check database dan tidak membuktikan model terlatih atau deadline job domain.

Untuk pengiriman pada **pengembangan lokal mode stub**, registrasi/aktivasi model dilakukan eksplisit dari backend, bukan saat startup aplikasi:

```powershell
php artisan ml:register-stub
php artisan ml:register-stub --activate
php artisan queue:work --queue=predictions,document-cleanup --tries=1 --sleep=1 --timeout=0
php artisan schedule:work
```

Perintah pertama hanya mendaftarkan metadata kode stub yang nyata dan tidak aktif secara otomatis. --activate menolak model aktif lain, metadata kode yang berbeda, mode model, atau lingkungan selain local/testing. Baris stub memakai trained=false, code://..., dan hash kode, bukan artefak terlatih. Database dev tidak diaktifkan otomatis pada sesi implementasi. Siapkan worker dan scheduler pada terminal berbeda; field key tetap lokal. Pada testing tambahkan --env=testing dan pastikan guard efektif sebelum mutasi. Harness Chrome memakai -PrepareStubModel setelah guard DB test untuk fixture stub-v0; tidak mengganti model aktif lain.

RunPrediction memakai database queue predictions, cache database lock per check, HTTP tanpa retry maksimal min(20,sisa-5), dan deadline 120 detik. Enqueue sesudah commit yang gagal tetap mengembalikan 202/snapshot tersimpan. Scheduler anomalies:expire memulihkan processing lewat deadline sebagai failed, tanpa mengubah status bisnis atau otomatis mengulang inference. Queue/cache tables perlu izin DB runtime yang sesuai. PCNTL/kill worker POSIX tetap belum dibuktikan; --timeout=0 Windows bukan jaminan terminasi, dan timeout job 30 detik hanya efektif pada runtime yang mendukungnya. ML_DEADLINE_SECONDS pada contoh lama belum dibaca runtime: deadline domain tetap 120 sesuai kontrak.

## Akun dan UI peninjau lokal

Gunakan akun verifikator, penyetuju_1, atau penyetuju_2 yang sudah ada. Untuk menambah akun baru secara eksplisit di local/testing, dari backend jalankan `php artisan users:create-reviewer`. Nama/email/peran dipilih di terminal; password/konfirmasi memakai secret prompt, tidak lewat argv, file, chat, atau log. Tidak ada password bawaan, tidak mengubah akun lama, dan tidak berlaku pada production. Command diuji hanya pada DB test; sesi ini tidak membuat akun di DB pengembangan. Admin API/provisioning deployment belum tersedia.

Setelah login pilih **Buka antrean internal**. Verifikator hanya pending_verification, penyetuju 1/2 masing-masing pending_approval_1/2. Halaman menampilkan summary seluruh tahap peran, filter/pencarian nomor/pagination, snapshot terkini/arsip, hasil internal dan riwayat. Ambil tugas menulis penugasan, lepas memerlukan alasan; status pinjaman tetap. Reviewer lain dapat membaca dalam tahapnya tetapi tidak melepas tugas orang lain. Dokumen kerja yang belum ditautkan ke snapshot tetap tertutup. Aksi catatan/revisi/forward tersedia untuk verifikator pemilik tugas aktif; persetujuan dan retry manual tersedia pada tahap berikutnya.

Chrome internal.spec.ts membuat fixture reviewer/snapshot tahap via helper CLI khusus testing dengan pemeriksaan konfigurasi dan SELECT DATABASE() efektif. Password acak hanya melalui stdin dan memori test. Fixture approval adalah data uji scope, bukan klaim workflow tingkat sebelumnya telah diimplementasikan. Uji dua claim bersamaan melalui HTTP kernel juga membaca performance_schema.data_lock_waits/threads untuk membuktikan wait aktual; user DB pengujian memerlukan izin baca diagnostik itu. Jangan memperluas grant user runtime produksi demi pengujian.

## Verifikasi

Dari backend:

```powershell
php artisan test
php vendor/bin/pint --test
composer validate --strict
composer audit
```

Dari root:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\frontend.ps1 run check
powershell -ExecutionPolicy Bypass -File .\scripts\frontend.ps1 run build
powershell -ExecutionPolicy Bypass -File .\scripts\frontend.ps1 audit
```

Hentikan dev server frontend/backend yang memakai 5173/8000 sebelum `powershell -ExecutionPolicy Bypass -File .\scripts\frontend.ps1 run test:e2e`. Playwright menjalankan server sendiri, menolak reuse server, dan memeriksa isolasi database test terlebih dahulu. Chrome harus sudah terpasang. Alur yang diuji: CSRF, penolakan tanpa XSRF, daftar tanpa login otomatis, login salah/benar, CORS credentials, `/me`, reload/logout, profil, draf, validasi nominal/integer, respons penyimpanan terputus tanpa replay, filter yang tetap menghormati satu aktif, konfirmasi batal/hapus, serta 320px.

Trace, video, dan screenshot otomatis dimatikan untuk menghindari penyimpanan kredensial. Screenshot terarah hanya diambil setelah keluar dan input dibersihkan. `frontend/scripts/check-layout.mjs` adalah QA visual saat backend tidak terhubung, **bukan** bukti autentikasi cookie.

Screenshot ruang nasabah diambil setelah form login tidak lagi terlihat dan hanya memuat identitas/keuangan fiktif yang ditandai pada UI. Hasil di `docs/validation/customer-draft-desktop.png` dan `customer-draft-mobile-320.png`. Tidak mengambil screenshot password, token, atau identitas nyata.

Worker pembersihan dokumen draf menggunakan `php artisan queue:work --queue=document-cleanup --tries=3 --timeout=0`; untuk pembuktian/pengujian gunakan `--env=testing`. Row job dan penghapusan metadata berada dalam transaksi DB sama. Jangan membersihkan file secara manual sebelum commit. Upload/unduh memakai disk local privat dengan prefix server `loan-documents/`; jangan menjalankan storage:link untuk dokumen. Cleanup juga menangani file upload yang transaksinya rollback setelah pengecekan referensi. Crash orphan/DB tidak dapat diperiksa masih perlu rekonsiliasi terpisah, belum ada pembersihan massal otomatis.

Jalankan PHPUnit dan browser berurutan, karena keduanya memakai DB test yang sama; transaksi fixture suite dapat memicu deadlock bila dijalankan bersamaan. Skenario documents.spec.ts menambah upload 5 MB, validasi isi, penggantian versi, byte unduhan, akses versi lama, 413 server nyata, respons upload terputus tanpa replay, serta screenshot panel desktop/320px. Hanya skenario dokumen: `powershell -ExecutionPolicy Bypass -Command "& .\scripts\frontend.ps1 -NpmArgs @('run', 'test:e2e', '--', 'documents.spec.ts')"`.

Screenshot panel dokumen `docs/validation/documents-desktop.png` dan `documents-mobile-320.png` hanya memuat nama berkas fiktif/metadata, tanpa kredensial atau path privat. Database test yang dipertahankan dapat berisi jenis dokumen fixture tambahan dari pengujian commit/konkurensi; UI menampilkan data konfigurasi server apa adanya. Database pengembangan tidak diubah agar menyerupai fixture tersebut.

Dari ml-service: `.venv\Scripts\python.exe -m unittest discover -s tests -v`.

Queue, pengujian pada DB terpisah: kirim `php artisan system:queue-probe --env=testing`, simpan ID yang tercetak, jalankan `php artisan queue:work --env=testing --once --tries=1 --timeout=0`, lalu `php artisan system:queue-probe <ID> --env=testing` harus `completed`. Ini job diagnostik tanpa data pengguna.

Scheduler: `php artisan schedule:run --env=testing` menjalankan heartbeat dan anomalies:expire; subprocess expiry meneruskan --env secara eksplisit dan sudah diuji memakai check expired pada DB test. system:scheduler-status --env=testing membaca heartbeat UTC. schedule:work dipakai untuk proses lokal berkelanjutan. PCNTL timeout worker belum terbukti pada Windows. Dalam sandbox Windows, penutupan pohon proses Playwright memerlukan izin proses di luar sandbox; pengulangan dengan izin tersebut selesai dengan exit code 0. Ini bukan penonaktifan TLS/CSRF atau pengamanan aplikasi.

## Audit yang masih terbuka

Audit aplikasi Laravel bersih pada sesi 6 November 2026, sedangkan `composer diagnose` masih melaporkan advisory internal Composer pada symfony/filesystem (GHSA-6prc-h956-hxjq). Keduanya pemeriksaan berbeda; Composer tidak diperbarui secara global pada sesi ini.

Audit npm versi final melaporkan empat paket terdampak tingkat rendah dari deepmerge 4.3.1, diteruskan melalui plugin Svelte → SvelteKit → adapter. Tidak ada perbaikan kompatibel yang ditawarkan audit saat sesi ini. Vite telah dipatch ke 8.3.6 untuk menutup temuan tingkat tinggi dari versi template. Jangan menggunakan `npm audit fix --force`, mematikan TLS/audit, atau menurunkan framework ke versi kuno yang disarankan resolver audit. Tinjau rilis perbaikan sebelum demo/deployment.
