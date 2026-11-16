# Serah terima Lendara — 16 November 2026

Lendara adalah prototipe akademik **Rancang Bangun Sistem Pengajuan Pinjaman Berbasis Web dengan Deteksi Anomali dan Persetujuan Berjenjang**. Kode, hasil pengujian dan prosedur lokal tersedia untuk ditinjau penerima. Skenario utama demo operator lokal sudah selesai sampai Disetujui; pemeriksaan metadata/berkas oleh agent dan konfirmasi tampilan nasabah oleh pengguna dicatat terpisah. Penerimaan formal perusahaan masih perlu dicatat.

## Status dan lingkungan

| Lingkungan | Yang sudah terbukti | Yang perlu dilakukan |
|---|---|---|
| `kkp_pinjaman` | Lima peran aktif, model B seed17, alur operator sampai approved, dokumen3/3 valid, tugas/job gagal0 | Layanan masih berjalan; tinjauan dan penerimaan formal bersama penerima |
| `kkp_pinjaman_test` | Regresi MySQL dan Chrome, termasuk role/constraint/transisi; suite backend terakhir 171 tes/1722 assertions lulus | Gunakan hanya untuk pengujian; jangan reset data lama |
| `kkp_pinjaman_restore_test` | Chrome: login, upload D01/D02/D03, unduh identik, submit, worker model, forward dan approval1/2 hingga approved | Akun fixture berpassword acak tidak menjadi akun operator; runner membuat fixture baru setiap run |
| `kkp_pinjaman_recovery_drill` | Paket pengajuan lengkap pulih, hash isi22 tabel/schema/file/model cocok | Runtime/browser/worker khusus database kedua belum disediakan atau diuji |

Pemeriksaan akhir16 November: MySQL dan lima layanan demo berjalan. Accounts/guard/preflight model/heartbeat scheduler lulus; audit dokumen3/3 cocok. Pengajuan operator approved melalui keputusan1/2 berurutan, assignment aktif/jobs/failed0. Pengguna mengonfirmasi tampilan nasabah; agent tidak mengakses kredensial akun atau sesi pengguna. Bukti [operator-acceptance](validation/operator-acceptance-2026-11-16.json) membedakan laporan UI pengguna dari verifikasi database read-only. Riwayat setup dan pemeriksaan awal tersedia di PROGRESS.

## Isi serah terima

| Bahan | Lokasi dan perlakuan |
|---|---|
| Kode aplikasi dan lockfile | Root repository, `backend/`, `frontend/`, `ml-service/`, `scripts/`; gunakan commit yang disepakati saat penyerahan |
| Acuan bisnis, API dan schema | [SPECIFICATION](SPECIFICATION.md), [API_CONTRACT](API_CONTRACT.md), [DATABASE](DATABASE.md), [UI_SPEC](UI_SPEC.md) |
| Diagram implementasi | [SYSTEM_OVERVIEW](SYSTEM_OVERVIEW.md), sumber Mermaid beserta PNG/SVG yang telah dirender dan ditinjau visual |
| Konfigurasi tanpa rahasia | [CONFIGURATION_REFERENCE](CONFIGURATION_REFERENCE.md); seluruh `.env` dan contoh environment tetap diabaikan Git |
| Instalasi dan demo Windows | [SETUP](SETUP.md), [LOCAL_WINDOWS_DEMO](LOCAL_WINDOWS_DEMO.md), [RESTORE_WINDOWS_DEMO](RESTORE_WINDOWS_DEMO.md) |
| Model terpilih dan protokol | [ML_RUNBOOK](ML_RUNBOOK.md), [MODEL_HANDOVER](MODEL_HANDOVER.md), registry/metadata dan laporan di `docs/validation/` |
| Backup data/model privat | Paket di `.tools/local-backups/` dan folder restore; pindahkan melalui penyimpanan privat yang ditetapkan operator, dengan pin manifest tepercaya terpisah |
| Artefak `.joblib` | Tidak berada di Git; berikan salinan tepercaya sesuai path/hash registry. Jangan melatih ulang dari holdout untuk menggantikannya |
| Credential dan konfigurasi rahasia | Operator menyiapkan secara privat. DPAPI terikat akun/komputer Windows; provision ulang pada komputer penerima, tanpa menyalin plaintext ke Git/chat/log |

Checkout Git saja tidak mencakup runtime portable, venv, dependency terpasang, artefak model, dokumen privat, database atau rahasia. Jangan menjalankan scaffold ulang pada checkout yang sudah berisi aplikasi. Paket SQL memuat password hash/sesi/data fiktif dan tetap privat. Gitignore mencegah tracking baru; ia tidak menghapus isi commit historis.

## Urutan penerimaan lokal

1. Penerima menentukan commit kode serta salinan privat model/backup yang dipakai. Periksa pin registry/manifest dari sumber tepercaya; pertahankan protokol/tag ML dan marker holdout.
2. Siapkan PHP8.3, MySQL8.0.30, Python3.12 dan Node22.17+ seri22 menurut ENVIRONMENT/SETUP. Pasang dependency dari lockfile; jangan mengubah major, mematikan audit/TLS atau mengabaikan kebutuhan platform.
3. Siapkan konfigurasi lokal dan DB terpisah. Inspeksi DB sebelum migration biasa; tidak `fresh`, DROP/TRUNCATE atau overwrite target lama. Akun operator/migration terpisah dari runtime.
4. Provision runtime Windows sesuai DATABASE_RUNTIME pada komputer/akun penerima. Buat akun aplikasi melalui secret prompt lokal dan UI admin; jangan membagikan kata sandi melalui laporan/chat. Pada workspace saat ini, kelima peran development sudah dibuat pengguna dan terverifikasi aktif; lakukan uji penerimaan menggunakan akun tersebut.
5. Jalankan model → Check → backend/frontend → worker/scheduler melalui LOCAL_WINDOWS_DEMO. Kelima peran harus tersedia, lalu periksa login/reload/logout, dokumen privat, submit, hasil model dan persetujuan berurutan. Catat hasil penerimaan baru; keberhasilan runner fixture bukan tanda tangan penerima.
6. Verifikasi pasangan backup DB/disk/model dan lakukan restore hanya pada target baru yang sudah ditetapkan. BACKUP_RESTORE memuat bukti pemulihan pengajuan lengkap serta batas runtime target kedua.
7. Catat commit, lingkungan, tanggal, hasil, kendala dan penanggung jawab penerima. Hentikan proses pada terminal pemilik setelah selesai; simpan bukti yang telah dibersihkan dari kredensial.

## Bukti yang dapat ditinjau

| Cakupan | Bukti dan batas |
|---|---|
| Operator development | [operator-acceptance](validation/operator-acceptance-2026-11-16.json): approved, keputusan1/2, history berurutan, dokumen3/3; status nasabah dikonfirmasi pengguna |
| Backend terakhir | [PROGRESS](PROGRESS.md):171 tes/1722 assertions,60.88detik/Pint lulus pada checkpoint `378c295`; tidak dijalankan ulang saat sesi dokumentasi |
| Chrome restore lengkap | [restore-browser](validation/restore-browser-2026-11-16.json): satu skenario2.4menit, tiga unggahan UI, model asli dan keputusan1/2; [mobile setelah logout](validation/restore-auth-mobile-320.png) |
| Recovery dataset lengkap | [populated-recovery](validation/populated-recovery-2026-11-16.json):22 tabel, approved1/snapshot1/dokumen3/keputusan2/check1, hash logis cocok; browser database kedua belum diuji |
| Grant runtime/browser | [DATABASE_RUNTIME](DATABASE_RUNTIME.md), [runtime regression](validation/runtime-browser-regression-2026-11-15.json), [DPAPI](validation/runtime-dpapi-2026-11-16.txt) |
| Eksperimen ML | [laporan eksperimen](validation/ml-experiment-v1-results.md); data sintetis, AP/AUC hanya pelaporan, holdout dibuka sekali |
| Dependensi | [audit tersimpan](validation/dependency-review-2026-11-16.json): audit Laravel0, Composer internal masih terbuka dan npm4 low; ini hasil audit tersimpan, bukan audit baru sesi ini |

## Hal yang masih terbuka

- Pencatatan penerimaan formal perusahaan; skenario utama operator lokal sudah selesai. Cabang revisi/retry/tolak tidak diuji ulang manual pada sesi operator ini, walau bukti pengujian terpisah tersedia.
- Runtime/browser database recovery kedua bila lingkungan itu akan dipakai sebagai demo, bukan sekadar bukti pemulihan data.
- Tinjauan perbaikan advisory Composer internal/npm dengan tetap mempertahankan kompatibilitas dan lockfile.
- Rekonsiliasi orphan destruktif, crash multi-file/ack worker dan lost COMMIT jaringan belum terbukti menyeluruh; audit tetap baca-saja.
- Hosting/HTTPS/proxy, process supervisor, Linux/PCNTL dan reproduksi lintas OS belum menjadi bukti demo Windows lokal. Build frontend masih memakai adapter-auto.

Model mendeteksi pola sintetis untuk peninjauan manusia; hasilnya tidak membuktikan kelayakan kredit atau deteksi fraud pada data nyata. Dokumen ini tidak mengganti spesifikasi, memperluas hak akses, atau menyatakan penerimaan perusahaan telah selesai.
