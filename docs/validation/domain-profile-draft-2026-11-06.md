# Bukti schema domain dan profil/draf — 6 November 2026

Windows, PHP 8.3.36, MySQL 8.0.30, Node portable 22.23.4, Chrome/Playwright 1.65.0. Pengujian memakai database terpisah `kkp_pinjaman_test`; guard PHPUnit membaca `SELECT DATABASE()` efektif sebelum transaksi. Tidak memakai SQLite, fresh/refresh, DROP, atau TRUNCATE.

| Perintah/pemeriksaan | Hasil |
|---|---|
| Migration domain pada DB test, lalu dev | Lulus; users tidak dibuat ulang, 14 tabel domain tersedia |
| Perbandingan hitungan delapan tabel dev lama | Sama sebelum/sesudah migration; isi baris/kredensial tidak dicetak |
| DocumentTypeSeeder dev/test | Lulus; D01–D04, idempoten, konflik tidak ditimpa |
| `php artisan test --no-ansi` | 52 passed, 433 assertions, exit 0 |
| PHPUnit effective connection | mysql / kkp_pinjaman_test / 8.0.30 |
| `php vendor/bin/pint --test` | passed |
| `scripts/frontend.ps1 run check` | 0 error, 0 warning |
| `scripts/frontend.ps1 run build` | Lulus, exit 0; target deployment adapter-auto belum dipilih |
| `scripts/frontend.ps1 run test:e2e` | 3 passed (41.2s), exit 0, Chrome nyata |

Constraint fisik diuji dengan bypass validasi API dan memeriksa kode error MySQL: unique 1062, CHECK 3819, NOT NULL 1048, FK 1452/1451. Meliputi marker satu aktif, model/check/assignment/terminal verification, nomor versi/attempt/level, scope FK komposit, NIK/email, batas angka, dan FK RESTRICT.

Dua transaksi nyata disinkronkan dengan barrier: satu menahan insert yang belum commit, satu sudah berada dalam transaksi dan mencoba mutasi; commit dilepas setelah keduanya teramati. CONNECTION_ID berbeda. Pada run penuh:

```text
MySQL concurrent draft: connections 215/216, results created/ACTIVE_APPLICATION_EXISTS
MySQL concurrent assignment: connections 218/219, results created/unique_conflict
Tests: 52 passed (433 assertions)
```

Create draf menghasilkan tepat satu aplikasi dan satu activity log. Assignment menghasilkan satu row aktif; endpoint claim/riwayat internal belum diimplementasikan sehingga bukti ini tidak mengklaim alur tersebut selesai.

Cleanup: rollback mempertahankan metadata/file dan membatalkan job. Setelah commit, koneksi observer MySQL melihat row queue; payload dieksekusi dua kali pada disk uji dan tetap idempoten. Error penghapusan diteruskan, path traversal ditolak. File pengguna tidak dipakai pada pengujian.

Browser membuktikan cookie/CSRF/CORS, profil parsial, uang sebagai string, penyimpanan/reload draf, nominal/tenor invalid, respons penyimpanan yang terputus setelah server commit tanpa auto-replay, filter yang tetap menahan pembuatan pengajuan kedua, serta dialog batal/hapus dan detail 404 setelah penghapusan.

Screenshot aman: [desktop](customer-draft-desktop.png), [320px](customer-draft-mobile-320.png). Hanya identitas/keuangan fiktif, tanpa form password/token, sudah diperiksa visual. Tabel scroll lokal; halaman tidak overflow pada 320px.

Percobaan awal saat MySQL mati dan tiga kesalahan harness (alias Kernel/lookup payload JSON) dicatat di PROGRESS dan diperbaiki sebelum hasil akhir di atas. Dependensi/ML tidak berubah; audit keamanan dan smoke ML dari tahap setup tidak diklaim sebagai pengujian baru sesi ini.

Belum tersedia: endpoint dokumen, submit/resend, workflow snapshot/check/approval, job/deadline prediksi, runtime grants/append-only SQL, generator/model asli/evaluasi. Tidak ada transisi workflow atau keputusan otomatis yang ditambahkan pada irisan ini.
