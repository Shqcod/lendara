# Regresi browser model asli

Harness `ml-service/tests/Support/model_browser_run.py` ditujukan hanya untuk MySQL `kkp_pinjaman_test`, dengan backend/frontend lokal yang belum dipakai proses lain. Jangan menjalankan PHPUnit atau browser suite lain bersamaan pada DB ini.

## Isolasi dan pemulihan

Runner memeriksa port, menyalakan Uvicorn di port loopback sementara dengan kunci acak di environment proses, dan memeriksa readiness. Helper PHP melakukan guard APP_ENV/database efektif/mode serta run UUID. Check lama yang benar-benar melewati deadline ditangani melalui service expiry yang sudah ada; processing yang masih berjalan ditunggu terbatas. Tidak ada DROP/TRUNCATE/migrate:fresh atau penghapusan check/job untuk memaksa handover.

Registrasi model dan handover memakai command/service yang sudah diuji. Penunjuk aktif sebelum tes disimpan pada state lokal unik di .tools (tanpa password/token). Backend browser memakai model mode lewat environment proses, cookie test dan config Playwright terpisah tanpa PrepareStubModel. File .env operator tetap. Setelah run, runner menutup Uvicorn dan memulihkan penunjuk aktif sebelumnya di transaksi test-only dengan named lock; jika model aktif berubah di luar perkiraan, restore menolak menimpa. Ini harness fixture test, bukan prosedur restore model produksi. Dataset/metadata/akun/dokumen fiktif dipertahankan untuk bukti, tanpa pembersihan massal.

## Cakupan skenario

- Auth cookie/CSRF/register/logout dan respons jaringan tidak diulang menggunakan dua skenario auth yang sudah ada.
- Empat akun fiktif dibuat lewat helper: nasabah, verifikator dan dua penyetuju. Nasabah login, membuat draf, mengisi profil/keuangan, mengunggah D01 dan mengirim lewat UI. Required document types tambahan pada DB test disiapkan sebagai PDF fixture sebelum pengiriman; bukan klaim seluruh upload dilakukan lewat browser.
- Database job asli yang baru dibuat untuk check milik pengajuan run ini dipindahkan queue-nya ke nama unik. Payload dan job tetap sama, tidak memanggil handler langsung; worker database hanya mengonsumsi queue itu. Queue lain tidak dikuras. Ini isolasi harness, bukan perubahan routing queue produksi.
- Percobaan pertama memakai worker dengan URL loopback tak tersedia agar terjadi kegagalan koneksi nyata. Verifikator mengklaim tugas dan retry lewat UI; worker kedua memakai URL model asli. Tidak ada X-Stub-Scenario/HTTP fake atau perubahan hasil check untuk membuat succeeded/failed.
- Verifikator meminta revisi lewat UI, nasabah mengirim versi baru, worker memproses snapshot baru dengan model sama. Verifikator meneruskan dan L1/L2 menyetujui melalui UI. Respons nasabah diperiksa tanpa skor/flag internal; bukti akhir melalui status UI serta inspeksi DB pengajuan milik run.
- Screenshot hanya halaman internal berisi model/score fiktif dan halaman nasabah akhir pada320px, bukan form kredensial. Trace/video/screenshot otomatis tetap off.

## Menjalankan

Dari root dengan dependency/artifact lokal yang sudah sesuai lock:

```powershell
& .\.tools\ml-training-venv\Scripts\python.exe ml-service/tests/Support/model_browser_run.py
```

Runner memanggil scripts/frontend.ps1 dengan playwright.model.config.ts. Kunci tidak ditaruh pada argv, output atau berkas. Laporan Playwright lengkap/log dan state pemulihan berada lokal di .tools; bukti aman yang telah ditinjau saja dimasukkan Git. Timeout startup frontend120s dan skenario480s adalah batas harness, bukan perubahan deadline120s model atau timeout HTTP20s aplikasi.

Hasil aktual, kegagalan run serta verifikasi pemulihan dicatat pada PROGRESS setelah selesai. Ini belum uji beban, deployment HTTPS/proxy, seluruh skenario penolakan/peran admin ulang, atau pembuktian timeout/kill POSIX. Protokol eksperimen dan marker holdout tidak disentuh.

## Hasil sesi 15 November 2026

Run awal timeout startup frontend60s sebelum tes, model test dipulihkan. Run kedua dengan startup120s:3 skenario lulus6.3m, exit0, model-flow5.1m dan dua auth. Model aktif test dipulihkan setelah selesai; DB pengembangan/.env tetap. Pengajuan2566 approved, snapshot1691 attempt1failed/attempt2succeeded, snapshot1692attempt1succeeded; semua memakai if-v1-b-seed17. Pint lulus. Screenshot desktop/320px diperiksa dan aman; bukti JSON pada validation/model-browser-regression-2026-11-15.json serta model-flow-browser-evidence.json. Check frontend dan batas pemeriksaan lain mengikuti PROGRESS.

Pemeriksaan akhir: frontend check0 error/0 warning (PowerShell Bypass hanya proses, pemanggilan awal terhalang execution policy), Pint --test lulus. Default config mengecualikan model-flow, config model menghapus ignore tersebut; --list membuktikan9 skenario default dan3 skenario model. Pemanggilan listing awal salah binding argumen PowerShell dikoreksi ke NpmArgs array. Ini listing konfigurasi, bukan hasil eksekusi9 skenario. PHPUnit/Python runtime suite tidak diulang karena runtime tidak berubah.

Build frontend dijalankan sekali sesudah browser dan lulus/exit0. Warning adapter-auto belum memilih deployment serta NO_COLOR/FORCE_COLOR tetap dicatat; ini bukan pembuktian deployment produksi.
