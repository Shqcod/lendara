# Demo model asli di Windows lokal

Gunakan profil proses berikut untuk demo pada database development kkp_pinjaman. Panduan serah terima dan batas bukti ada di [HANDOVER](HANDOVER.md); konfigurasi tanpa rahasia ada di [CONFIGURATION_REFERENCE](CONFIGURATION_REFERENCE.md). Untuk kkp_pinjaman_restore_test gunakan [RESTORE_WINDOWS_DEMO](RESTORE_WINDOWS_DEMO.md), bukan perintah development di halaman ini.

Status sesi operator terakhir: demo sudah dinyalakan di http://localhost:5173 dan kelima peran aktif. Skenario utama [uji penerimaan operator](OPERATOR_ACCEPTANCE.md) sudah selesai sampai Disetujui; jangan menyalakan ulang launcher pada port yang sama. Urutan startup di bawah dipakai setelah layanan sesi ini dihentikan; lihat catatan layanan di akhir.

## Prasyarat

- MySQL Laragon8.0.30 aktif di127.0.0.1:3306; PHP8.3, Node22.17+ seri22 dan venv Python3.12 beserta dependency locked tersedia. Apache lama tidak diperlukan.
- Database/schema/config lokal sudah diperiksa menurut SETUP; tidak menjalankan scaffold ulang, reset DB atau mengganti nama database.
- Artefak joblib tepercaya tersedia sesuai registry/metadata. Kunci internal backend dan model cocok, tanpa dicetak atau dikirim ke frontend. Dokumen privat tetap di disk privat.
- Credential runtime Windows sudah diprovisioning menurut DATABASE_RUNTIME. File yang sudah ada tidak ditimpa; credential DPAPI hanya dapat dipakai akun Windows yang sama pada komputer yang sama. RegisterModel adalah aksi operator terpisah, bukan fallback runtime.

Pemeriksaan awal serah terima16 November sempat gagal karena MySQL berhenti. Pemeriksaan lanjutan: MySQL3306 aktif, guard runtime/Check ke model asli lulus dan Integrity restore3/3 cocok. Kelima peran development kini terverifikasi aktif melalui Accounts exit0. Model sementara dihentikan sesudah preflight; MySQL milik pengguna tetap berjalan. Periksa ulang kondisi layanan sebelum memakai langkah berikut.

## Akun aplikasi

Dari root proyek pada terminal privat:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/local-demo.ps1 -Action Accounts
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/local-demo.ps1 -Action CreateAdmin
```

Accounts melaporkan boolean lima peran, exit1 bila belum lengkap. Status development terakhir yang berhasil dibaca: admin, verifikator, penyetuju_1, penyetuju_2 dan nasabah seluruhnya aktif. Jika admin sudah ada, jangan membuat ulang; lanjut login pada UI. CreateAdmin meminta nama/email fiktif dan password dua kali melalui secret prompt; tidak memakai password bawaan/argv/chat/transcript. Buat verifikator, penyetuju_1 dan penyetuju_2 melalui UI admin, atau bootstrap interaktif `-Action CreateReviewer`. Nasabah mendaftar melalui UI. Akun fixture browser pada database lain tidak menjadi akun operator development.

## Jalankan layanan

Gunakan terminal baru dari root proyek untuk setiap layanan; perintah server berikut memblokir terminalnya. Terminal model:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/start-local-model.ps1 -RegistrySha256 d0a0de0d02838762ca1bbef501b32e93d56ecd2d067c7f1d0b23926404fe789c
```

Pada terminal lain, jalankan Check dahulu dan lanjutkan hanya jika exit0:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/local-demo.ps1 -Action Check
```

Kemudian masing-masing pada terminal tersendiri:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/local-demo.ps1 -Action Backend
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/frontend.ps1 run dev
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/local-demo.ps1 -Action Worker
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/local-demo.ps1 -Action Scheduler
```

Buka http://localhost:5173; API browser memakai http://localhost:8000 dan model internal127.0.0.1:8001. Pertahankan host localhost untuk frontend/API development. Profil restore memakai host127.0.0.1 dan port5174/8800/8801 agar cookie tidak bercampur; port berbeda saja tidak memisahkan cookie.

Profil local-demo menetapkan mode=model, URL loopback, session/cache/queue database dan credential runtime hanya pada proses; file .env lama tetap utuh. Gunakan profil ini untuk backend/worker/scheduler. start-backend.ps1 adalah helper tingkat bawah, bukan pengganti profil demo jika konfigurasi dasar masih stub/cache file. Jangan memakai flag Testing untuk menjalankan development.

Check membaca DB dan GET readiness sebelum transaksi/lock, memeriksa model aktif trained non-stub, identitas/hash versi yang diperlukan dan memeriksa ulang pointer aktif. Ia tidak membuat prediksi, mengaktifkan model, membuat akun atau membuktikan worker/scheduler hidup. Tahap kegagalan membantu diagnosis tanpa key/path/SQL/identitas. Jika guard gagal, periksa MySQL, DPAPI, konfigurasi efektif dan artefak; jangan memperluas grant atau beralih ke operator.

Pada Windows worker memakai timeout0 dan HTTP timeout/deadline eksplisit. RunPrediction memiliki tries1, cleanup memakai tries3. Ini tidak membuktikan penghentian worker berbasis PCNTL. Setelah restart model/proses, jalankan Check kembali.

## Model, penerimaan dan berhenti

Model development if-v1-b-seed17 sudah diregistrasi/diaktifkan pada sesi sebelumnya. Instalasi baru yang memang belum memiliki model aktif dapat memakai `-Action RegisterModel` secara eksplisit setelah runtime siap; aksi ini memverifikasi LOCK terhadap tag selection dan menolak mengganti model aktif lain. Pergantian berikutnya memakai [MODEL_HANDOVER](MODEL_HANDOVER.md) dengan maintenance/expected-active, tanpa repin snapshot atau evaluasi holdout ulang.

Uji penerimaan operator mencakup login/reload/logout lima peran, profil/dokumen fiktif, kirim, hasil model sebagai bahan review manusia, verifikator forward dan persetujuan1/2. Catat bukti dari lingkungan yang benar. Chrome restore lengkap dan backup pengajuan lengkap sudah lulus; itu tidak menggantikan penerimaan operator pada development. Batas/audit tersimpan dirangkum HANDOVER/PROGRESS.

Hentikan proses dengan Ctrl+C pada terminal pemiliknya setelah pekerjaan berjalan selesai. Jangan mematikan semua proses berdasarkan nama atau menghapus job/check. Pemeriksaan Integrity baca-saja dapat dijalankan tanpa model dengan `-Action Integrity`; backup dan recovery mengikuti [BACKUP_RESTORE](BACKUP_RESTORE.md). Audit orphan tidak memberi izin hapus otomatis. Hosting/HTTPS/proxy, supervisor dan timeout POSIX memerlukan pembuktian terpisah.

## Layanan operator pada sesi terakhir

Setelah pengguna membuat admin, lima layanan dinyalakan oleh sesi Codex dan tetap berjalan di http://localhost:5173 untuk penerimaan dengan kelima peran yang kini tersedia. Health200, preflight model, cookie/CORS anonim Chrome serta heartbeat scheduler lulus; ketersediaan kelima peran aktif kini terverifikasi, alur utama operator kini selesai sampai approved dan tampilan final nasabah dikonfirmasi pengguna, dengan observasi DB baca-saja terpisah. Jangan menyalakan launcher duplikat pada port yang sama. PID/start time/log milik run disimpan lokal di .tools/operator-demo-current.json dan folder UUID, tanpa rahasia pada manifest. Karena proses sesi ini berjalan tersembunyi, minta sesi Codex menghentikan hanya proses milik manifest setelah writer/job tenang; jangan mematikan PHP/Node/MySQL berdasarkan nama global. Setelah layanan dihentikan, jalankan kembali urutan terminal di atas bila diperlukan.
