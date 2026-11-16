# Menjalankan hasil restore di Windows

Profil ini khusus hasil restore yang sudah tervalidasi pada database **kkp_pinjaman_restore_test**. Database dan folder sumber tetap dipertahankan. Jangan mengimpor ulang ke target yang sudah ada. Prosedur ekspor/restore ada di [BACKUP_RESTORE](BACKUP_RESTORE.md).

Dari terminal PowerShell baru di root proyek, provision sekali menggunakan ID folder hasil restore:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/provision-local-runtime.ps1 -Restore -RestoreId <UUID_restore>
```

Provisioning memerlukan koneksi operator lokal yang sudah tersedia. Akun MySQL baru hanya memiliki grant runtime pada target restore. Credential DPAPI terpisah tersimpan di `.tools/local-restore-runtime.credential.xml`; file yang sudah ada ditolak, bukan ditimpa. Credential development tidak dipakai sebagai fallback. DPAPI terikat pengguna dan komputer Windows; bukan format backup portabel. Semua berkas environment tetap lokal dan tidak ditulis ulang.

Jalankan model, backend dan frontend pada tiga terminal terpisah. Model memakai artefak salinan restore serta pin registry yang sama:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/restore-demo.ps1 -RestoreId <UUID_restore> -Action Model
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/restore-demo.ps1 -RestoreId <UUID_restore> -Action Backend
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/restore-demo.ps1 -RestoreId <UUID_restore> -Action Frontend
```

Aksi tambahan menggunakan bentuk perintah sama, dengan `-Action Check`, `Integrity`, `Accounts`, `Worker`, atau `Scheduler`. Check memerlukan model berjalan; Integrity dan Accounts tidak memerlukan model. Worker/Scheduler hanya dijalankan ketika ingin memproses pekerjaan pada target restore. Accounts melaporkan boolean lima peran; exit1 berarti akun belum lengkap. Profil ini tidak membuat akun atau kata sandi bawaan.

| Komponen | Profil restore |
|---|---|
| API | http://127.0.0.1:8800 |
| Model internal | http://127.0.0.1:8801 |
| Origin frontend yang diizinkan | http://127.0.0.1:5174 |
| Cookie sesi | lendara_restore_session, host-only |
| Disk privat | `.tools/local-restores/<UUID>/private` |
| Artefak model | `.tools/local-restores/<UUID>/artifact-root` |

Gunakan host 127.0.0.1 untuk profil restore; demo sumber memakai localhost. Port saja tidak mengisolasi cookie XSRF. Aksi Frontend menetapkan URL API hanya pada environment proses, memakai konfigurasi Vite khusus dengan strictPort5174 serta direktori keluaran/cache terpisah. Credential backend dibersihkan sebelum Node dimulai. Jangan membuka frontend sumber dan menganggapnya terhubung ke target restore.

Pengaman memeriksa DB efektif, grant, marker dan hasil validasi folder, disk privat, URL, CORS, Sanctum serta cookie sebelum layanan dimulai. DB restore tanpa ID workspace gagal saat bootstrap, sehingga tidak kembali ke disk sumber. Penanda validasi merekam pemeriksaan awal restore; ia tidak menjamin jumlah baris tetap sama setelah aplikasi berjalan. Pemeriksaan hash model tetap dilakukan saat model dimuat. Jangan menggunakan config cache dari profil lain.

## Bukti dan pekerjaan berikutnya

Pada 16 November 2026, provisioning target, guard runtime/workspace, preflight model, prediksi fixture dengan model B seed17, HTTP health200, CSRF204 dan anonymous401 berhasil. Host cookie dan header CORS juga cocok. Pemeriksaan HTTP menulis sesi anonim hanya pada DB restore, lalu proses model/backend milik pemeriksaan dihentikan. Bukti HTTP awal ini belum membuktikan login browser; pengujian Chrome lanjutan dijelaskan di bawah. Dataset sumber masih tanpa pengajuan dan metadata dokumen, sehingga perlu latihan restore berikutnya dengan data fiktif representatif. Marker `DATA_RESTORED_BROWSER_NOT_VERIFIED` tetap dipertahankan.

## Uji Chrome khusus restore

Hentikan layanan restore milik Anda terlebih dahulu; runner menolak port8800/8801/5174 yang sudah terpakai dan tidak menggunakan ulang layanan lama. Dari root:

```powershell
.tools/ml-training-venv/Scripts/python.exe scripts/restore-browser-smoke.py --restore-id <UUID_restore>
```

Runner menjalankan profil guarded untuk model, backend dan frontend, kemudian satu skenario Chrome. Ia membuat akun nasabah fiktif dengan password acak dalam memori, menguji CSRF419 tanpa token, registrasi tanpa auto-login, login, CORS/cookie, simpan draf uang presisi sen serta sesi/draf sesudah reload. Semua D01/D02/D03 diunggah melalui UI, D01 diunduh dan byte dibandingkan, lalu pengajuan dikirim. Probe guarded membuat tiga peninjau fiktif baru untuk verifikator, penyetuju1 dan penyetuju2; role lain/admin dan akun lama tidak diubah. Password diteruskan hanya melalui stdin pipe dan tidak disimpan. Akun/pengajuan/dokumen fiktif dipertahankan hanya di target restore. Mengulang runner membuat fixture baru. Suite Playwright biasa mengabaikan skenario restore.

Screenshot diambil hanya setelah logout dan isian kredensial kosong. Trace/video/screenshot otomatis dimatikan. Log layanan berada di .tools yang diabaikan Git. Runner menghentikan hanya pohon proses yang dibuatnya. Bukti ini menguji aplikasi terhadap database hasil restore yang semula kosong dari pengajuan; bukan bukti pemulihan pengajuan/dokumen lengkap dari backup. Marker awal tetap dipertahankan sebagai catatan historis, terpisah dari bukti workflow baru.

Run Chrome16 November 2026 lulus1 skenario/37.2s, termasuk registrasi/login, CSRF/CORS/cookie, draf presisi sen dan sesi setelah reload serta logout. Check frontend0 error/0 warning dan build lulus. Counts sumber tetap, port layanan milik runner dilepas. Bukti aman: validation/restore-browser-2026-11-16.json dan restore-auth-mobile-320.png. Bukti tahap awal ini dilanjutkan oleh workflow lengkap di bawah.

## Batas probe dan worker skenario

Aksi BrowserProbe/BrowserWorker hanya untuk runner dengan RESTORE_BROWSER_TEST=1, bukan bootstrap akun demo development. Probe PHP memverifikasi CLI/local, DB efektif restore, credential runtime, workspace dan UUID run sebelum operasi. Ia menolak database test biasa. Pemeriksaan pengajuan dibatasi akun nasabah bernama fiktif dengan email namespace restore. Tidak mengubah model aktif, snapshot, hasil prediksi atau keputusan secara langsung.

Probe memindahkan tepat satu job RunPrediction yang belum reserved/attempt dan cocok dengan checkId snapshot fixture dari predictions ke antrean acak restore-smoke-<UUID_tanpa_strip>. Transaksi rollback bila jumlahnya bukan satu. BrowserWorker hanya menerima format antrean tersebut, max1 job/45detik/tries1/timeout0 untuk Windows, melalui preflight model yang sama. Job lain dan payload tidak diubah. Ini isolasi runner, bukan perubahan rute antrean aplikasi.

Setelah prediksi succeeded dengan model if-v1-b-seed17 dan relasi tanda skor/flag cocok, browser melakukan claim/forward verifikator, claim/setuju tingkat1, lalu claim/setuju final tingkat2. Observer memeriksa urutan history, keputusan1/2, tiada tugas aktif, hash metadata snapshot/tautan/dokumen sama sebelum-sesudah persetujuan dan SHA256/ukuran file privat cocok. Seluruh tindakan bisnis ini melalui UI/API biasa; observer hanya membaca dan merutekan job fixture. Skenario tidak menguji ulang retry/revisi/penolakan/scheduler pada target restore atau timeout POSIX, dan tidak membuka holdout ML.

Workflow Chrome lanjutan16 November2026 lulus1 skenario/2.4menit: tiga unggahan UI, unduh identik, submit202, worker model B seed17, forward dan persetujuan1/2 sampai approved. Observer memverifikasi immutable hash tetap, file privat valid dan tugas aktif0. Audit Integrity terpisah checked3/verified3 tanpa mismatch, counts sumber tetap dan port layanan dilepas. Backend168 tes/1710 assertions/Pint lulus; frontend check0 error/0 warning. JSON bukti yang sama kini berisi hasil workflow penuh; bukti awal tetap tersedia dalam riwayat Git.
