# Pengujian per tahap

Status yang dicentang hanya jika benar-benar dijalankan. Simpan command, environment, hasil, dan keterbatasan di PROGRESS. Endpoint stub punya unit/contract tests dasar dengan unittest; suite auth, profil/draf, constraint dan konkurensi MySQL, cleanup, serta Playwright tersedia. Hasil aktual mengikuti PROGRESS; suite model asli belum ada. Lihat SETUP.md untuk guard database test, perintah, dan batas screenshot.

## Setup

- PHP CLI benar, ekstensi curl/zip/pdo_mysql tersedia; actual SELECT VERSION() terkonfirmasi.
- Stub health/ready/predict dengan kunci; salah/tanpa kunci ditolak.
- Browser frontend: CSRF → login → /me → logout → /me 401. Register tidak langsung login; peran klien diabaikan/ditolak.
- Test cookie origin konsisten localhost; credentials, XSRF, stateful domain, CORS exact origin. SSR bila dipakai diuji terpisah dengan cookie+Origin/Referer dan propagasi Set-Cookie yang benar.
- Queue test job dijalankan; scheduler command tercatat. Tidak mengklaim scheduler siap hanya karena proses schedule:work terbuka tanpa jadwal.

## Constraint pada MySQL, bukan SQLite

1. Dua pengajuan aktif nasabah sama: kedua ditolak; setelah final boleh baru.
2. Dua assignment ended_at null pada pengajuan sama: kedua ditolak.
3. Dua anomaly_checks succeeded pada submission sama: kedua ditolak.
4. Dua model is_active: kedua ditolak.
5. Tambahan: dua processing, duplicate version/attempt/decision level/terminal verify, FK latest version milik pengajuan lain, FK check atau assignment lintas snapshot, NIK/email duplikat, CHECK invalid enum/angka.

Pastikan NOT NULL yang dibutuhkan untuk marker unik tidak dapat dilewati. Uji concurrency claim benar-benar memakai koneksi/transaksi independen (barrier/sinkronisasi), bukan dua panggilan berurutan yang hanya mensimulasikan race. Hanya satu tugas dan efek riwayat yang commit.

Pada irisan schema/profil/draf, `DomainConcurrencyTest` membuktikan dua proses/transaksi bersamaan untuk create draf (satu aplikasi + satu activity log) dan insert assignment aktif (satu row). Fixture konkurensi dipersistenkan secara unik hanya pada DB test agar terlihat oleh kedua koneksi; tidak ada reset DB. Claim melalui endpoint dan riwayat penugasan tetap harus dibuktikan saat API internal diimplementasikan.

## Nasabah/dokumen

CustomerDocumentsTest membuktikan versi/list/resource aman, kepemilikan dan seluruh peran internal ditolak, akun nonaktif, status upload, batas tepat 5 MB dan lebih satu byte, PDF/PNG/JPG/JPEG, ketidakcocokan MIME/ekstensi, tipe nonaktif, field server terlarang, attachment/no-store/nosniff, file hilang, immutability instance, serta rollback metadata/audit dengan cleanup kompensasi. DomainConcurrencyTest menambah dua upload bersamaan ke pengajuan/jenis sama. Semua memakai guard MySQL test; filesystem feature test menggunakan disk fake.

Playwright documents.spec.ts menguji multipart cookie SPA, 422 isi palsu, batas 5 MB, dua versi, download byte identik, versi lama, 413 server nyata, respons 201 yang diputus tanpa replay, reload, desktop dan 320px. Jalankan suite backend dan browser secara berurutan pada DB test yang sama untuk menghindari benturan transaksi fixture. Crash orphan, grant SQL runtime, akses internal dokumen sesuai tahap, dan submit/resend tetap belum dibuktikan.

- Cross-user ID guessing: 404, termasuk download/snapshot/list documents.
- Create draft kedua 409; edit/upload di status terlarang 409; delete setelah pernah kirim 409.
- Draf boleh incomplete, submit incomplete 422 dengan field/document errors; income0 ditolak sebelum Python.
- File 5MB/mime invalid/oversize; server413 dan Laravel422 tertangani UI.
- Snapshot lama tidak berubah setelah profil/keuangan/dokumen diperbarui. File versi lama masih tersedia untuk snapshot lama.
- Customer resource tidak mengandung score,is_anomaly,checklist,model,internal notes pada semua nested payload/error.
- Dua submit dengan expected_current_submission_id sama: satu berhasil. Gangguan jaringan tidak memicu POST berulang tanpa cek status.
- Hapus draf rollback tidak menghapus file. Commit memiliki cleanup job persisten; file absent dianggap sukses; failure job tercatat dan retry aman.

## Pemeriksaan dan tindakan internal

- Snapshot selalu punya check pertama bahkan enqueue gagal. Scheduler memulihkan processing lewat deadline.
- Retry hanya verifikator pemilik tugas, tahap/versi benar, failed; attempt baru, snapshot tetap.
- Stub normal/anomaly/failure/timeout; timeout HTTP dan expired response persistence merupakan dua uji berbeda.
- Terminal check tidak berubah oleh hasil lama/duplikat. Wrong model/check/submission IDs, NaN/inconsistent boolean ditolak dan dicatat aman.
- Forward sebelum succeeded ditolak; anomali tanpa notes 422; revision wajib alasan.
- Approver1/2 tidak melompati stage, tidak membuat keputusan kedua, tidak request revision.
- Release/nonaktif account mengakhiri penugasan tanpa mengubah status; token/sesi aktif dicabut. Akun nonaktif dicek lagi untuk request yang berlomba.
- Role access dan scope daftar/ringkasan tidak bocor. Admin tidak mendapat hak unduh otomatis.

## Model

- Shared contract suite pada stub dan layanan asli; schema/status/error rules sama, nilai score tidak harus sama.
- Input order tetap meski JSON key order berubah. Rasio/train/serve konsisten dengan fixture yang sama.
- Environment/package versions metadata cocok; artifact hash diverifikasi; wrong requested model tidak fallback.
- Train/val/test terpisah; config dipilih val, dikunci sebelum test; label hanya anomali sintetis.
- Demo readiness menolak stub atau active model DB tidak tersedia di service.

## UI dan demo akhir

Per-field validation, pending state, kegagalan jaringan, 320px/mobile + desktop, keyboard; alur revision/resend; L1/L2 setuju/tolak; reset data melalui prosedur aman terpisah. Jalankan manual dari README pada lingkungan demo, catat hasil. Runtime Windows tidak membuktikan worker POSIX timeout; uji Linux/WSL saat dibutuhkan.

