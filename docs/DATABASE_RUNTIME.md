# Izin MySQL runtime

Target: MySQL 8.0.22+ (lingkungan lokal teruji 8.0.30). Akun migration/operator terpisah dari akun web, queue worker, dan scheduler. Rencana grant, probe DB test, serta lima skenario Chrome/worker/scheduler dengan akun terbatas sudah diuji. **Runtime lokal Windows sudah dipasang melalui profil DPAPI; deployment publik belum dipasang.**

## Profil credential lokal Windows

Provisioning sekali dari root, dengan akun operator lokal yang dapat membuat user/grant:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/provision-local-runtime.ps1
```

Skrip mewajibkan Windows sebenarnya, local/MySQL/kkp_pinjaman efektif dan port3306. Broker PHP hanya menerima pipe privat; membuat identitas unik baru (tidak menimpa akun lama), meminta random password dari MySQL dan menerapkan matriks tabel/kolom di bawah. Tidak ada password SQL literal, argv, file plaintext atau .env yang dibuat. Password dipindah melalui pipe privat, menjadi PSCredential dan diekspor ke `.tools/local-runtime.credential.xml` dengan NoClobber. Round-trip dekripsi dan ketiadaan password plaintext pada file diverifikasi sebelum mengakui persistensi.

Pada Windows, Export-Clixml memakai DPAPI sehingga credential hanya dapat didekripsi akun Windows yang sama pada komputer yang sama. Ini bukan format portabel untuk migrasi/backup credential. [Dokumentasi Microsoft](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.utility/export-clixml?view=powershell-5.1#example-3-encrypt-an-exported-credential-object).

Sebelum acknowledgement, kegagalan/EOF menghapus hanya akun baru milik broker. Sesudah persist berhasil, akun runtime dipertahankan. Jika ada crash pada celah acknowledgement, file atau akun dapat tersisa/tidak cocok: skrip menolak overwrite dan profil gagal tertutup, tidak beralih ke operator. Operator perlu mengidentifikasi akun/file milik run sebelum pemulihan/rotasi; tidak ada bulk delete/revoke atau perbaikan otomatis. Test fixture credential terenkripsi di .tools boleh tertinggal, tetapi akun `-Testing` dibersihkan.

Semua aksi profil local-demo.ps1 selain RegisterModel memuat credential DPAPI dan menjalankan system:assert-runtime-database. RegisterModel tetap jalur operator eksplisit. Guard mewajibkan username hasil provisioning, DB efektif, CURRENT_ROLE NONE, menolak role/grant global/database-wide/DDL/grant option dan memeriksa operasi immutable yang dipilih lewat WHERE1=0. Guard bukan auditor lengkap semua kombinasi privilege; jangan menambah grant manual pada akun runtime. Import/dekripsi/koneksi gagal berarti berhenti, tidak memakai kredensial .env sebagai fallback. Gunakan skrip via proses powershell -File seperti contoh, bukan dot-source pada terminal operator.

File credential diabaikan Git melalui .tools/ serta pola *.credential.xml; jangan menyalin atau force-add ke repo. Lindungi akun Windows dan workspace. Key ML tetap menggunakan jalur lokal terdahulu dan tidak dicampur dengan credential DB.

`-Testing` menjalankan jalur pipe/DPAPI/guard yang sama pada kkp_pinjaman_test dengan akun sementara, lalu cleanup akun. Pada sesi16 November jalur test lulus, provisioning lokal lulus, overwrite ditolak dan profil Accounts/Check memakai runtime terverifikasi. Check terhubung model asli; akun aplikasi masih belum dibuat. Ini belum bukti browser operator/backup atau server publik.

## SQL grant untuk tinjauan operator

Dari `backend`:

```powershell
php artisan db:runtime-grants kkp_pinjaman lendara_runtime 127.0.0.1
```

Perintah hanya mencetak SQL, tanpa koneksi DB, CREATE USER, password, eksekusi GRANT, atau perubahan `.env`. Nama database/akun/host divalidasi; wildcard host ditolak. Nama database harus sesuai lingkungan yang dituju, bukan otomatis diganti oleh nama produk.

Operator menyiapkan akun **baru tanpa grant/role lain** melalui jalur provisioning rahasia, meninjau keluaran, lalu menerapkan SQL dengan akun operator. GRANT menambah hak; menjalankannya pada akun yang sudah memiliki hak luas tidak mengurangi hak lama. Jangan memakai root, user migration, akun bersama, role warisan, grant global/database-wide, atau GRANT OPTION untuk runtime. Tinjau `SHOW GRANTS` dan role aktif bersama DBA sebelum mengganti kredensial runtime. Password tidak masuk argumen CLI, skrip repo, log, atau Git.

| Tabel | Hak runtime |
|---|---|
| users, customer_profiles | SELECT, INSERT, UPDATE |
| document_types | SELECT |
| loan_applications | SELECT, INSERT, UPDATE, DELETE |
| document_versions | SELECT, INSERT, DELETE |
| application_submissions, submission_documents, verification_results, approval_decisions, application_status_histories, activity_logs | SELECT, INSERT |
| anomaly_checks | SELECT, INSERT; UPDATE hanya status, score, is_anomaly, finished_at, failure_reason |
| assignments | SELECT, INSERT; UPDATE hanya ended_at, end_type, end_reason, ended_by |
| model_versions | SELECT, INSERT; UPDATE hanya is_active |
| sessions, jobs, job_batches, failed_jobs, cache, cache_locks | SELECT, INSERT, UPDATE, DELETE |
| migrations, password_reset_tokens | Tidak diberikan; migration milik operator, reset password belum tersedia |

DDL, pengelolaan akun/grant, dan akses database lain tidak diberikan. Akun runtime masih bisa menulis baris baru; aturan peran, status, penutupan tugas, transisi terminal check, delete khusus draf, dan aktivasi model tetap wajib dijaga service. Grant tidak menggantikan otorisasi aplikasi atau membatasi operator yang memiliki hak lebih tinggi.

## Locking read dan append-only

MySQL mensyaratkan SELECT ditambah UPDATE, DELETE, atau LOCK TABLES untuk `FOR UPDATE`. Pada MySQL 8.0.22+, shared locking read hanya memerlukan SELECT. [Dokumentasi MySQL](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html).

Pembacaan jenis dokumen, snapshot terakhir saat submit, dan riwayat keputusan kini memakai `sharedLock()` (`LOCK IN SHARE MODE`). Baris user dan pengajuan tetap `FOR UPDATE` sebelum pengalokasian versi atau keputusan, sehingga permintaan untuk pengajuan yang sama tetap diserialisasi. Shared read tetap membaca versi terbaru setelah menunggu transaksi lain. Tidak memberi UPDATE/DELETE pada riwayat hanya untuk memenuhi kebutuhan lock. Lock versi dokumen tetap exclusive karena grant DELETE diperlukan untuk penghapusan draf; model/assignment/check tetap exclusive dengan UPDATE kolom yang diizinkan.

## Probe nyata yang terisolasi

Jalankan hanya setelah konfigurasi test lokal tersedia dan migrasi test sudah terpasang:

```powershell
cd backend
$env:APP_ENV = 'testing'
php tests/Support/runtime-grants.php --probe
Remove-Item Env:APP_ENV
```

Guard memeriksa CLI, flag eksplisit, environment testing, konfigurasi MySQL, dan `SELECT DATABASE()` harus `kkp_pinjaman_test`. Akun operator test memerlukan CREATE USER dan kemampuan memberi grant yang direncanakan. Probe membuat satu identitas unik `lendara_probe_<acak>` pada host 127.0.0.1 dengan password acak dari server, menerapkan grant hanya pada tabel DB test, lalu membuka PDO independen. Password hanya di memori; tidak ditulis ke environment, argv, berkas atau output.

Probe memeriksa izin INSERT/SELECT riwayat, penolakan UPDATE/DELETE riwayat dan perubahan kolom immutable, locking read, operasi tabel pendukung, serta penolakan akses migrations/reset token/mysql.user. Pemeriksaan izin memakai query tanpa baris (`WHERE 1=0`); bila izin larangan keliru terbuka, tidak ada baris pengguna yang berubah. INSERT audit dan CRUD cache nyata memakai fixture di dalam transaksi yang di-rollback. Tidak mencoba DDL pada tabel aplikasi, DROP/TRUNCATE tabel, atau menghapus file. Akun sementara milik pemanggilan sendiri dihapus dalam finally; akun yang sudah ada tidak diberi grant/revoke/drop.

Jika proses dihentikan paksa, finally mungkin tidak berjalan. Operator harus mengidentifikasi akun probe yang benar milik run tersebut sebelum menonaktifkan/menghapusnya; jangan menghapus semua akun berdasarkan prefix. Tidak ada pembersihan massal otomatis.

Hasil 15 November 2026: **79 pemeriksaan lulus pada MySQL 8.0.30**, transaksi rollback, akun sementara dibersihkan. Bukti ringkas: `validation/runtime-grants-2026-11-15.txt`. Probe ini sendiri membuktikan matriks izin; regresi aplikasi terpisah dijelaskan di bawah.

## Regresi aplikasi dengan akun terbatas

Dari root, memakai venv model yang sudah dipin:

```powershell
.tools\ml-training-venv\Scripts\python.exe ml-service/tests/Support/model_browser_run.py --runtime-grants
```

Runner menolak reuse port backend/frontend, memulai FastAPI model asli, menyiapkan/handover DB test lewat operator, lalu meminta broker internal membuat akun unik `lendara_browser_<acak>`. Password server hanya melewati pipe privat ke memori runner dan environment proses anak; tidak dicetak, ditulis ke berkas, atau dimasukkan argv. Broker bukan perintah provisioning untuk dipanggil manual atau diarahkan ke log. Tidak mengubah `.env`.

Konfigurasi `playwright.runtime.config.ts` memakai wrapper backend test yang mengalihkan kredensial hanya pada proses backend. Fixture browser tetap operator test, termasuk persiapan model dan pengalihan job milik run ke queue unik; tidak melebarkan grant runtime untuk kebutuhan fixture. Worker model memakai kredensial terbatas secara eksplisit. Guard memverifikasi `CURRENT_USER()`, `CURRENT_ROLE() = NONE`, penolakan UPDATE snapshot tanpa baris, dan penggunaan database untuk cache/session/queue tanpa koneksi pendukung lain.

Lima skenario Chrome mencakup dua auth cookie/CSRF/jaringan, pembuatan akun admin, perubahan akses/cabut sesi/lepas tugas, serta submit/gagal koneksi/retry/revisi/kirim ulang/persetujuan L1/L2 pada model asli. Fixture lama untuk skenario admin berisi hasil stub sintetis; bukan bukti inferensi pada skenario admin. Bukti model asli berasal dari worker/pengajuan baru di model-flow. Screenshot dan JSON aman memakai prefix `runtime-` agar bukti sebelumnya dipertahankan; report/log penuh tetap lokal `.tools`.

Setelah browser, helper scheduler memakai akun terbatas yang sama, membuat fixture fiktif baru dengan percobaan processing yang sudah kedaluwarsa (tanpa job, simulasi enqueue hilang), lalu menjalankan `schedule:run` nyata. Ia memeriksa heartbeat cache DB, hasil failed/deadline_expired, serta snapshot dan status bisnis tetap. Fixture scheduler menggunakan INSERT/UPDATE yang memang diizinkan; tidak mengubah deadline data lain atau menambah hak operator pada runtime. Fixture test dipertahankan; tidak ada reset/penghapusan massal.

Finally memulihkan model aktif test sebelumnya dan meminta broker menghapus hanya akun sementara milik run. Runtime development/deployment tetap. Ini regresi skenario terpilih pada Windows, bukan seluruh cabang/konkurensi suite PHPUnit dengan akun terbatas, deployment HTTPS/proxy, atau bukti terminasi worker PCNTL.

Hasil 15 November 2026: **5 skenario Chrome lulus**, 0 skip/gagal/flaky, 521528.386 ms (8,7 menit). Worker memakai akun terbatas pada tiga percobaan; dua sukses model asli. Scheduler memulihkan fixture check1263 menjadi failed/deadline_expired dan mempertahankan snapshot/status. Model test dipulihkan. Runner awal exit1 karena pesan cleanup CRLF Windows dibandingkan literal LF; akun sebenarnya sudah terhapus, inventory diperiksa kosong. Perbandingan diperbaiki dan uji siklus akun nyata `--runtime-account-smoke` lulus exit0. Browser tidak diulang karena perubahan berikutnya hanya penanganan pesan cleanup. Bukti: `validation/runtime-browser-regression-2026-11-15.json` dan `runtime-model-flow-browser-evidence.json`.

## Penerapan operator

Regresi test di atas tidak memasang akun deployment. Operator perlu menyiapkan kredensial runtime lingkungan tujuan, memverifikasi grant efektif dan tidak ada role/hak warisan, menjadwalkan perubahan konfigurasi/restart secara terkontrol, lalu menguji konfigurasi deployment sebenarnya. Fixture/seeder/migration tetap memakai akun operator, bukan menambah izin runtime agar fixture lolos. Jangan menyalin password antar lingkungan atau mengubah konfigurasi development otomatis.
