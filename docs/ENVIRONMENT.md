# Lingkungan dan konfigurasi

Dilaporkan pengguna 6 November 2026:

| Komponen | Versi / keadaan |
|---|---|
| OS / terminal | Windows, PowerShell |
| Node / npm | 22.13.1 / 10.9.2 |
| PHP CLI | 8.3.36 TS x64, Q:\maragoon\laragon\bin\php\php-8.3.36 |
| PHP config | folder PHP di atas, php.ini |
| Ekstensi | curl, zip, fileinfo, mbstring, openssl, pdo_mysql, dom, xml dan lainnya aktif |
| Composer | 2.11.1, memakai PHP Laragon |
| Git | 2.48.1.windows.1 |
| Python / pip | 3.12.4 / 25.3 |
| MySQL server | 8.0.30, dikonfirmasi SELECT VERSION() |
| Apache lama | error entry point dependensi php_curl.dll; tidak dipakai untuk proyek |

Audit Composer sebelumnya mendeteksi GHSA-6prc-h956-hxjq pada dependensi internal symfony/filesystem. Potongan pemeriksaan terbaru hanya menunjukkan Composer version OK, bukan audit bersih. Periksa lagi ketika setup; jangan samakan dengan composer audit aplikasi.

## Target lokal

- Nama produk: **Lendara**; folder utama `lendara` (lokasi saat ini `Q:\lendara`). Backend memakai `APP_NAME="Lendara"`; judul halaman dan metadata frontend sudah mengikuti UI_SPEC.
- Scaffold sesi setup: Laravel 12.71.0 + Sanctum 4.3.3; SvelteKit 3.1.1 + TypeScript 6.0.3 + Vite 8.3.6. Node sistem tetap 22.13.1; Node 22.23.4 portable di `.tools/` dipilih per proses oleh `scripts/frontend.ps1` (minimum engine frontend 22.17). Lihat SETUP.md untuk perintah.
- Python 3.12.4 terverifikasi melalui executable Python/venv; launcher `py -3.12` gagal mendeteksi runtime pada sesi setup. Gunakan `python` atau `.venv\Scripts\python.exe`, bukan menganggap launcher berhasil.
- SvelteKit: http://localhost:5173; Laravel browser: http://localhost:8000.
- FastAPI internal: http://127.0.0.1:8001. DB 127.0.0.1:3306.
- DB dev kkp_pinjaman; DB test kkp_pinjaman_test; session/queue database.
- Kedua koneksi telah terverifikasi pada MySQL 8.0.30 dan migration awal telah dijalankan setelah schema kosong diperiksa. Di PHPUnit, guard membaca `SELECT DATABASE()` sebelum transaksi; suite membuktikan nama efektif `kkp_pinjaman_test`, bukan hanya konfigurasi Artisan.
- Pertahankan nama database yang sudah dikonfigurasi. Penamaan produk tidak mengganti database, membuat salinan, atau menghapus data secara otomatis.
- Sanctum: SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:8000.
- SESSION_DOMAIN kosong untuk host-only localhost; SESSION_SECURE_COOKIE=false hanya HTTP lokal; SameSite=lax. Domain/subdomain HTTPS demo ditentukan terpisah.
- CORS hanya origin frontend yang tepat, supports_credentials=true. API/auth/CSRF paths ikut terkonfigurasi.
- Browser mengirim credentials dan X-XSRF-TOKEN. SSR bila digunakan meneruskan cookie yang relevan dan Origin/Referer tepercaya; Set-Cookie dan mutasi SSR perlu pengujian eksplisit. Jangan meneruskan cookie ke host arbitrer.
- APP_TIMEZONE/DB session UTC; tampilkan Asia/Jakarta.
- PHP upload_max_filesize=5M, post_max_size minimal 8M untuk endpoint satu berkas; samakan server demo dan tangani 413.
- php.ini Laragon saat diperiksa masih 2G/2G. Gunakan scripts/start-backend.ps1 untuk batas proses lokal 5M/8M tanpa menimpa konfigurasi global/.env; runner browser memakai helper sama pada DB test. Deployment/proxy perlu verifikasi tersendiri.

## Timeout dan Windows

PHP Windows tidak menyediakan PCNTL. Timeout worker Laravel 30 detik berbasis sinyal tidak boleh diasumsikan berlaku pada lingkungan ini. Jalankan worker lokal --timeout=0 dengan HTTP timeout eksplisit dan scheduler aktif. retry_after awal 90 detik; proses normal harus selesai jauh sebelumnya. Lock eksekusi per anomaly_check_id mengurangi panggilan bersamaan; hasil final disimpan kondisional. Ini tidak menjamin pemanggilan layanan tepat satu kali.

Deadline 120 detik sejak check dibuat (termasuk antre), HTTP maksimal 20 detik dan min(20, sisa-5); bila tidak cukup, tandai failed. tries=1; tidak memakai HTTP retry otomatis. Pada Linux dengan PCNTL gunakan timeout job 30 < retry_after 90 dan uji penghentian paksa. Uji proses benar-benar menggantung di Linux/WSL sebelum menyatakan skenario tersebut terverifikasi. Tidak perlu memigrasikan seluruh setup pengguna ke WSL sekarang.

## Sumber resmi untuk verifikasi saat implementasi

- https://laravel.com/docs/12.x/sanctum
- https://laravel.com/docs/12.x/queues
- https://laravel.com/docs/12.x/session
- https://www.php.net/manual/en/pcntl.installation.php
- https://svelte.dev/docs/cli/sv-create
- https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html
- https://github.com/symfony/symfony/security/advisories/GHSA-6prc-h956-hxjq

