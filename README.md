# Lendara — prototipe pengajuan pinjaman

**Lendara** adalah nama produk dengan folder utama `lendara`. Judul akademik proyek: **Rancang Bangun Sistem Pengajuan Pinjaman Berbasis Web dengan Deteksi Anomali dan Persetujuan Berjenjang**.

Laravel 12 dan SvelteKit TypeScript menyediakan autentikasi cookie Sanctum, profil nasabah, pembuatan/simpan/daftar/hapus draf, serta unggah versi/daftar/unduh dokumen privat nasabah. **Schema 14 tabel domain, profil/draf, dan dokumen nasabah terverifikasi melalui MySQL nyata dan browser Chrome**; rincian pengujian ada di PROGRESS. Pengiriman snapshot, akses dokumen internal sesuai tahap, persetujuan berjenjang, dan model terlatih belum selesai. FastAPI masih stub; jangan menyamakannya dengan evaluasi ML.

Lihat [panduan setup dan pengujian](docs/SETUP.md) serta [status nyata](docs/PROGRESS.md). Node sistem 22.13.1 tetap; frontend memakai Node 22.23.4 portable di `.tools/` melalui `scripts/frontend.ps1` karena scaffold SvelteKit 3 memerlukan Node minimal 22.17. Dependensi dan lockfile disertakan; `.tools/`, vendor, node_modules, dan rahasia lokal diabaikan Git.

## Mulai di Windows

1. Ekstrak folder ini ke lokasi seperti `Q:\lendara`. Boleh memakai direktori lain. Jika sudah ada repo, bandingkan berkas sebelum menyalin; jangan menimpa pekerjaan lama.
2. Buka folder utama di VS Code melalui File > Open Folder. Pastikan AGENTS.md ada pada root yang dibuka.
3. Laragon: jalankan MySQL saja. Apache boleh tetap berhenti. PHP CLI menggunakan `Q:\maragoon\laragon\bin\php\php-8.3.36\php.exe`.
4. Terminal PowerShell root: `git init` bila belum menjadi repository. Jangan inisialisasi Git lagi di subfolder.
5. Jalankan pemeriksaan: `powershell -ExecutionPolicy Bypass -File .\scripts\check-environment.ps1` (Bypass hanya untuk proses ini).
6. Mulai Codex dengan prompt pada `docs/CODEX_START.md`. Ikuti setup bertahap dan catat hasil di PROGRESS.

## Scaffold aplikasi

`powershell -ExecutionPolicy Bypass -File .\scripts\scaffold-apps.ps1`

Skrip membutuhkan internet dan membuat Laravel **12.*** serta SvelteKit TypeScript minimal tanpa hook migration dan tanpa instalasi npm otomatis. Folder backend/frontend yang sudah ada dilewati, tidak dihapus. Bila instalasi parsial, periksa isinya dan lanjutkan secara manual. Checkout ini sudah memiliki scaffold beserta lockfile; gunakan `composer install` dan `scripts/frontend.ps1 ci --engine-strict` untuk memasang versi terkunci. Jika muncul engine/dependency error, evaluasi versi tanpa --force.

Script tidak mengatur DB, memasang Sanctum, atau menjalankan migration. Contoh konfigurasi berada di config/; gabungkan dengan hasil scaffold, jangan menimpa .env tanpa pemeriksaan.

Konfigurasi backend memakai `APP_NAME="Lendara"`. Halaman masuk/daftar, metadata, favicon frontend, dan metadata FastAPI memakai Lendara. Struktur `backend/`, `frontend/`, dan `ml-service/` tetap.

## Stub Python

PowerShell root:

```powershell
python -m venv ml-service/.venv
& .\ml-service\.venv\Scripts\python.exe -m pip install -r .\ml-service\requirements.txt
Push-Location ml-service
& .\.venv\Scripts\python.exe -m unittest discover -s tests -v
Pop-Location
powershell -ExecutionPolicy Bypass -File .\scripts\start-ml.ps1
```

start-ml.ps1 membuat .env lokal dengan kunci acak bila belum ada, lalu bind FastAPI ke **127.0.0.1:8001**. Kunci Laravel harus disamakan secara lokal. Jangan kirim kunci ke chat/Git/browser. Skrip tidak mencetak nilainya. .env.example hanya contoh; service membaca environment proses yang diisi skrip.

## Proses pengembangan yang akan berjalan

| Proses | Perintah / lokasi |
|---|---|
| Database | MySQL Laragon, port 3306 |
| Backend (setelah konfigurasi DB) | dari root: `powershell -ExecutionPolicy Bypass -File .\scripts\start-backend.ps1` |
| Frontend | dari root: `powershell -ExecutionPolicy Bypass -File .\scripts\frontend.ps1 run dev` |
| Queue (setelah migration/setup) | di backend: `php artisan queue:work --tries=1 --sleep=1 --timeout=0` pada Windows |
| Scheduler (setelah jadwal ditulis) | di backend: `php artisan schedule:work` |
| FastAPI stub | dari root: skrip start-ml.ps1 |

Backend dapat diakses browser di http://localhost:8000 walau bind 127.0.0.1. Browser frontend/API sama-sama gunakan localhost; jangan mencampur localhost dengan 127.0.0.1 pada origin cookie. Host 127.0.0.1 dipakai untuk komunikasi internal Laravel–FastAPI.

Setelah masuk sebagai nasabah, pilih **Buka ruang nasabah** untuk menuju `/customer`. Profil dan draf boleh disimpan sebagian. Maksimal satu pengajuan aktif; penghapusan hanya untuk draf yang belum pernah dikirim, dengan konfirmasi. Panel dokumen menyediakan unggah satu berkas fiktif PDF/JPG/JPEG/PNG maksimal 5 MB, versi terkini, dan unduh privat. Mengunggah versi baru mempertahankan berkas lama. Pengiriman belum diaktifkan.

Helper backend membatasi upload PHP 5M dan post 8M hanya pada proses server, tanpa mengubah php.ini atau .env. Webserver demo/deployment harus menyelaraskan batas yang sama; rincian 413/422 di SETUP.

Queue Windows: `--timeout=0` bukan pengamanan timeout. Deadline database, HTTP timeout, scheduler, dan pengaman duplikasi harus diimplementasikan. Baca ENVIRONMENT. Runtime Linux dengan PCNTL diperlukan untuk menguji penghentian worker paksa sebagaimana rencana 30 detik.

## Database

Versi terverifikasi pengguna: MySQL 8.0.30. Gunakan nama database yang sudah dikonfigurasi; nama produk Lendara tidak mengharuskan penggantian nama database. Konfigurasi acuan tetap `kkp_pinjaman` (dev) dan `kkp_pinjaman_test` (test). Hanya untuk setup awal jika database tersebut belum ada, buat database kosong melalui HeidiSQL:

```sql
CREATE DATABASE IF NOT EXISTS kkp_pinjaman CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS kkp_pinjaman_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Isi kredensial lokal sendiri. Siapkan user migration untuk DDL dan user runtime dengan izin per tabel saat implementasi siap; jangan memberi user runtime DROP/ALTER. Test hanya DB kkp_pinjaman_test.

## Status dan keterbatasan

- Dokumen merekam spesifikasi bisnis v1.2 dan kontrak API v1.1.
- Scaffold/build, schema 14 tabel, profil/draf, constraint/konkurensi MySQL, dan alur Chrome lulus. PHPUnit memeriksa `SELECT DATABASE()` efektif sebelum transaksi uji; hasilnya `kkp_pinjaman_test`. Rincian hasil aktual di PROGRESS dan docs/validation/.
- HTML wireframe dan diagram yang dibuat sebelumnya tidak dibundel; deskripsi UI tersedia. Gambar final dapat ditambahkan ke docs/reference/ oleh pengguna.
- Audit dependensi aplikasi Laravel bersih pada sesi setup. Composer 2.11.1 masih melaporkan advisory internal Symfony; npm masih melaporkan empat temuan tingkat rendah dari rantai deepmerge. Lihat SETUP untuk batas dan tindak lanjut; audit/TLS tetap aktif.

