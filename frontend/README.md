# Lendara — frontend

SvelteKit 3, Svelte 5, TypeScript 6. Halaman awal menyediakan daftar/masuk, pemeriksaan sesi, dan keluar. `/customer` menyediakan profil, simpan draf sebagian, daftar/filter/pagination, konfirmasi hapus, serta unggah versi/daftar/unduh dokumen privat nasabah. Unggah satu berkas fiktif PDF/JPG/JPEG/PNG maksimal 5 MB; versi lama dipertahankan. Pengiriman belum tersedia. API Laravel berada di layanan `backend/`.

Node seri 22 minimal 22.17 diperlukan. Dari root proyek gunakan `powershell -ExecutionPolicy Bypass -File .\scripts\frontend.ps1 run dev` untuk memilih runtime lokal tanpa mengubah Node sistem.

Pemeriksaan dari root: ganti `run dev` dengan `run check`, `run build`, atau `run test:e2e`. Uji browser memakai Chrome dan database test terpisah; baca [SETUP](../docs/SETUP.md) sebelum menjalankan. Lockfile harus dipertahankan.

Konfigurasi publik hanya `PUBLIC_API_BASE_URL`; kunci ML dan kredensial database tidak boleh berada pada frontend. Status nyata dan keterbatasan tercatat di [PROGRESS](../docs/PROGRESS.md).
