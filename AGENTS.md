# Instruksi Codex — Lendara

Proyek: **Rancang Bangun Sistem Pengajuan Pinjaman Berbasis Web dengan Deteksi Anomali dan Persetujuan Berjenjang**. Prototipe akademik dengan data/dokumen fiktif untuk demonstrasi dan penyerahan ke perusahaan. Bahasa UI dan penjelasan: Indonesia. Nama kode/tabel/endpoint: Inggris.

Nama produk: **Lendara**; folder utama: `lendara`. Gunakan nama ini pada konfigurasi, judul halaman, dan metadata aplikasi. Pertahankan judul akademik di atas serta nama database yang sudah dikonfigurasi; perubahan nama produk tidak menjalankan penggantian atau penghapusan database.

## Baca sebelum bekerja

1. `docs/PROGRESS.md` untuk status nyata dan tugas berikutnya.
2. `docs/ENVIRONMENT.md` dan `README.md` untuk lingkungan serta perintah.
3. `docs/SPECIFICATION.md` untuk aturan bisnis v1.2.
4. `docs/API_CONTRACT.md`, `docs/DATABASE.md`, `docs/ML_SERVICE.md` sesuai bagian yang diubah.
5. `docs/UI_SPEC.md`, `docs/TEST_PLAN.md`, `docs/DECISIONS.md` sebelum menyelesaikan fitur.

Dokumen ini adalah acuan proyek; jangan bergantung pada riwayat chat. Bila kode dan kontrak berbeda, periksa penyebab dan laporkan konflik, bukan diam-diam mengubah aturan bisnis. Koreksi implementasi rutin boleh dilakukan dalam cakupan tugas. Perubahan ruang lingkup, peran, status, hak akses, atau evaluasi ML harus dicatat dan dibahas dengan pengguna.

## Struktur dan stack

- `frontend/`: SvelteKit 3 + TypeScript 6, scaffold dan UI autentikasi tersedia; gunakan Node 22.17+ seri 22 melalui scripts/frontend.ps1. Lockfile wajib dipertahankan.
- `backend/`: Laravel 12 API + Sanctum cookie SPA + MySQL; scaffold/kode autentikasi tersedia, pembuktian MySQL/browser mengikuti PROGRESS.
- `ml-service/`: FastAPI internal, stub dan runtime Isolation Forest terpilih tersedia; registrasi metadata dan smoke queue Laravel ke model tersedia, handover model aktif/demo masih berikutnya.
- `config/`: contoh konfigurasi; `scripts/`: bantuan PowerShell Windows; `docs/`: acuan.
- Satu Git repository di root. Jangan membuat repository Git bersarang.
- Pertahankan versi major yang disepakati. Simpan lockfile npm/Composer; pin dependensi Python. Jangan memakai `--ignore-platform-reqs`, mematikan TLS/audit, atau memaksa dependency yang tidak kompatibel.

## Ketentuan wajib

- Lima peran, satu per akun: `nasabah`, `verifikator`, `penyetuju_1`, `penyetuju_2`, `admin`.
- Anomali adalah bahan peninjauan; bukan keputusan otomatis. Hasil anomali/catatan internal tidak masuk respons nasabah.
- Snapshot dan dokumen pengiriman immutable. Retry membuat percobaan baru, bukan snapshot baru.
- Verifikator meminta revisi; penyetuju hanya setuju/tolak. Tidak boleh melompati tingkat.
- Semua transaksi penting mengunci baris yang tepat; unique index tetap wajib. Jangan mengandalkan tombol disabled sebagai pengamanan.
- Jangan memanggil FastAPI di dalam transaksi dengan lock database. Snapshot + percobaan `processing` dibuat atomik sebelum job dijalankan.
- Dokumen privat; endpoint unduh berizin; jangan memakai public storage link untuk dokumen.
- Data DB UTC; tampilan WIB. Uang DB DECIMAL, API publik string desimal.
- Gunakan session dan queue database. `pcntl` tidak tersedia pada PHP Windows: jangan menganggap timeout worker POSIX terbukti di Windows.
- Demo akhir menolak mode stub dan model aktif `stub-*`.
- `.env`, token, kunci internal, berkas unggahan, dan identitas nyata tidak masuk Git/log. Contoh konfigurasi tidak berisi rahasia.
- Atas instruksi pengguna, semua berkas environment termasuk `.env.example`, `.env.testing.example`, `.env_example` dan `*.env.example` diabaikan Git. Jangan force-add atau mengembalikan tracking contoh ini; berkas lokal tetap dipertahankan. Gitignore tidak menghapus isi commit historis.
- Jangan menjalankan `migrate:fresh`, DROP/TRUNCATE, menghapus data/berkas pengguna, atau menimpa `.env` tanpa kebutuhan dan otorisasi yang jelas. Pengujian hanya pada DB pengujian terpisah.

## Cara mengerjakan

- Kerjakan irisan kecil: backend + frontend + pengujian yang relevan. Penuhi kriteria tahap, lalu lanjut.
- Ikuti tahap aktif di PROGRESS. Setup/auth, schema 14 tabel, irisan nasabah, dasar job prediksi/deadline, serta antrean/claim/release dan scope internal sudah terbukti. Aksi verifikator note/revisi/forward sudah tersedia; retry manual juga tersedia; persetujuan tingkat 1/2 juga tersedia; admin daftar/buat akun internal, log ringkas, edit role/nonaktif-aktif akun dan pencabutan sesi atomik tersedia. Perubahan akun sendiri dilarang, admin aktif terakhir dilindungi; role-change menolak tugas aktif dan nonaktif melepasnya. Tahap berikutnya finalisasi rancangan generator/split/evaluasi sebelum model asli serta pengamanan runtime per irisan. Jangan mengaku semua fitur atau model asli selesai karena stub lulus.
- Pelatihan model dimulai sebagai jalur tersendiri setelah schema dan generator dikunci; tidak perlu menunggu UI selesai.
- Protokol `docs/ML_EXPERIMENT_PLAN.md` disetujui/dikunci pada tag lokal ml-protocol-v1-locked; jangan mengubah dokumen beku untuk status implementasi. LOCK.json mencatat hash/commit per tahap; runbook di docs/ML_RUNBOOK.md. Generator/pipeline, evaluator36 fit dan selection sudah selesai; test+borderline telah dibuka sekali. FastAPI app.serve mode model sudah teruji; registrasi metadata model B seed17 dan queue Laravel ke model sudah terbukti pada DB test; command handover maintenance dengan expected-active sudah tersedia/teruji pada DB test; regresi Chrome model aktif pada DB test kini lulus submit/retry/revisi/resubmit/approve L1/L2 dengan model test dipulihkan; berikutnya pengamanan operasional/deployment/serah terima; jangan menghapus marker atau membuka ulang holdout. Tag ml-config-v1-selected hanya sesudah pemilihan nyata; test+borderline dibuka sekali sesudah konfigurasi terkunci. Draf lama hanya historis; implementasi sesuai protokol yang disetujui tidak memerlukan persetujuan ulang.
- Tulis feature test otorisasi, transisi, snapshot, dan constraint pada MySQL nyata. SQLite tidak cukup untuk membuktikan constraint MySQL.
- Setelah perubahan, jalankan pemeriksaan yang tersedia; laporkan yang lulus, gagal, dan belum bisa dijalankan. Jangan mengarang hasil.
- Perbarui PROGRESS setiap sesi: perubahan konkret, perintah uji + hasil, kendala, tugas berikutnya. Tambahkan keputusan baru ke DECISIONS.
- Setiap tugas yang mengubah berkas wajib diakhiri dengan `git commit` lokal setelah pemeriksaan relevan dan pembaruan PROGRESS. Gunakan pesan commit yang menjelaskan perubahan; periksa diff dan stage hanya berkas dalam cakupan tugas, tanpa rahasia atau perubahan lain yang tidak terkait. Jangan mengklaim pemeriksaan lulus bila belum dijalankan. Jika commit gagal, laporkan penyebabnya; jangan mengubah identitas Git tanpa instruksi pengguna. Push ke GitHub dilakukan hanya bila diminta pengguna.
- Simpan bukti uji dan screenshot aman untuk laporan selama pengembangan. Jangan mengambil screenshot kredensial.

## Pemeriksaan

- Suite Python penuh (generator/pipeline/stub): dari `ml-service`, `..\.tools\ml-training-venv\Scripts\python.exe -m unittest discover -s tests -v` setelah memasang requirements-model.lock.txt. Venv stub lama tanpa sklearn hanya menjalankan `python -m unittest discover -s tests -p test_stub.py -v`.
- Backend setelah tersedia: `php artisan test`, `vendor/bin/pint --test`; sesuaikan executable Windows bila perlu.
- Frontend setelah tersedia: `npm run check`, `npm run build`; tambahkan pengujian UI sesuai fitur.
- Uji end-to-end browser wajib untuk login cookie/CSRF/CORS; HTTP client saja tidak membuktikan perilaku browser.
