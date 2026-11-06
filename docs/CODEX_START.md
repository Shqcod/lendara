# Prompt awal untuk Codex di VS Code

Salin prompt berikut ke Codex setelah membuka root proyek:

Identitas produk: **Lendara**, folder utama `lendara`. Gunakan nama Lendara pada konfigurasi, judul halaman, dan metadata aplikasi. Pertahankan judul akademik di README/AGENTS serta nama database yang telah dikonfigurasi; jangan mengganti atau menghapus database otomatis.

> Baca AGENTS.md, README.md, dan semua docs yang diperlukan, terutama PROGRESS.md. Ini prototipe KKP pengajuan pinjaman; spesifikasi v1.2 dan kontrak API v1.1 sudah menjadi acuan. Periksa isi folder dan lingkungan Windows saya sebelum mengubahnya. Mulai dari tahap setup: scaffold Laravel 12 dan SvelteKit TypeScript jika belum ada, verifikasi stub FastAPI, lalu implementasikan smoke test autentikasi Sanctum cookie SPA (CSRF → login → /me → logout) dengan session database. Gunakan MySQL Laragon, bukan SQLite untuk pembuktian constraint. Kerjakan frontend bersama backend. Jangan bangun semua fitur sekaligus, jangan menimpa .env atau menjalankan migrate:fresh pada DB pengembangan. Catat hasil pengujian yang benar-benar dijalankan, kendala, serta pekerjaan berikutnya di docs/PROGRESS.md. Jangan menyebut setup/auth selesai sebelum uji browser dan pengujian relevan lulus. Jika membutuhkan kredensial, arahkan saya mengisi .env lokal tanpa meminta saya menempel rahasianya ke chat.

Sesudah setup selesai, prompt lanjutan:

> Lanjutkan tahap berikutnya sesuai PROGRESS.md dan TEST_PLAN.md. Periksa keputusan terbaru; pertahankan kontrak, otorisasi, privasi respons, dan aturan snapshot. Selesaikan satu irisan fitur beserta UI dan pengujiannya, kemudian perbarui progres dan keputusan.

