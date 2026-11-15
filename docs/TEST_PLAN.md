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

Pada irisan schema/profil/draf, `DomainConcurrencyTest` membuktikan dua proses/transaksi bersamaan untuk create draf (satu aplikasi + satu activity log) dan insert assignment aktif (satu row). Fixture konkurensi dipersistenkan secara unik hanya pada DB test agar terlihat oleh kedua koneksi; tidak ada reset DB. Irisan 15 November menambah pembuktian route claim dan riwayat sesuai rincian berikut.

Irisan internal menambah InternalQueueTest (scope tiga peran/status/filter/summary, kepemilikan, pointer, release/alasan/riwayat, larangan skip tahap, snapshot/unduh arsip, dokumen kerja belum dikirim tertutup, akun/peran dicek ulang, rollback audit). DomainConcurrencyTest kini menguji dua claim melalui HTTP kernel pada proses/koneksi independen: observasi data_lock_waits mendahului commit A, lalu 201/409 dan satu pemilik/log. Ini terpisah dari pembuktian cookie/CSRF browser. ReviewerBootstrapTest membuktikan tiga role via secret prompt/audit dan penolakan production, tanpa kredensial bawaan.

Chrome internal.spec.ts memakai fixture fiktif dengan guard PDO test dan password acak lewat stdin. Dua sesi melakukan claim stale (409), claim/release yang commit tetapi respons diputus (tanpa replay), baca ulang pemilik, cancel/reason release, claim oleh reviewer kedua, arsip/unduh, tiga scope tahap dan admin ditolak, desktop/320px. Tahap approval dan riwayat pada fixture bukan bukti workflow verifikasi/persetujuan yang belum dibuat. Jalankan PHPUnit dan Chrome berurutan pada DB test yang sama.

## Nasabah/dokumen

CustomerDocumentsTest membuktikan versi/list/resource aman, kepemilikan dan seluruh peran internal ditolak, akun nonaktif, status upload, batas tepat 5 MB dan lebih satu byte, PDF/PNG/JPG/JPEG, ketidakcocokan MIME/ekstensi, tipe nonaktif, field server terlarang, attachment/no-store/nosniff, file hilang, immutability instance, serta rollback metadata/audit dengan cleanup kompensasi. DomainConcurrencyTest menambah dua upload bersamaan ke pengajuan/jenis sama. Semua memakai guard MySQL test; filesystem feature test menggunakan disk fake.

Playwright documents.spec.ts menguji multipart cookie SPA, 422 isi palsu, batas 5 MB, dua versi, download byte identik, versi lama, 413 server nyata, respons 201 yang diputus tanpa replay, lalu melanjutkan tinjau/kirim: konfirmasi/cancel, kelengkapan 422, respons 202 yang diputus setelah commit tanpa replay, baca ulang pending_verification/arsip/history, download arsip, profil terbaru tidak mengubah snapshot, reload, desktop dan 320px. Jalankan suite backend dan browser secara berurutan pada DB test yang sama. Crash orphan, grant runtime, dan scope dokumen internal belum dibuktikan; kirim ulang backend diuji memakai status revisi fixture, alur browser revisi nyata menunggu internal.

CustomerSubmissionsTest/SubmissionCommitTest membuktikan data tersimpan/konfirmasi/pointer, kelengkapan dan model 503, transaksi rollback, immutable snapshot/tautan lama, privasi/read scope/pagination, alasan revisi allowlist, enqueue sesudah commit dengan observer independen, dan dispatch gagal tetap 202. DomainConcurrencyTest menambah dua submit bersamaan: satu snapshot/check/history/log. PredictionJobTest membuktikan HTTP di luar transaksi/model dipin, hasil anomali tidak memutuskan status, failure/timeout simulasi tanpa retry, budget/deadline, respons terlambat/invalid/duplikat, cache DB lock, serta schedule:run subprocess pada testing. StubRegistrationTest memeriksa idempotensi, aktivasi eksplisit, penolakan penggantian model aktif/metadata dan production. Pembuktian HTTP timeout Windows/Linux secara paksa bukan implikasi dari fake ConnectionException.

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


## Irisan aksi verifikator

VerificationActionsTest mencakup note tanpa transisi, immutable snapshot, privasi nasabah, alasan revisi publik, forward succeeded/failed/anomali, kepemilikan/role/versi, checklist snapshot/boolean, field server terlarang, akun diperiksa ulang dan rollback audit. DomainConcurrencyTest menambah revisi bersamaan melalui HTTP kernel pada dua koneksi MySQL dengan lock wait aktual: satu hasil/history/audit. Chrome internal.spec.ts menambah forward anomali dengan notes wajib; documents.spec.ts menambah note privat, cancel/revisi dengan respons terputus tanpa replay, alasan publik dan kirim ulang nasabah versi 2 melalui API/UI nyata. Hasil eksekusi mengikuti PROGRESS.

## Retry manual

PredictionRetryTest membuktikan pemilik/peran/versi/field klien, check lama dan snapshot tetap, model/input dipin, processing/succeeded ditolak, mode salah, privasi resource nasabah, rollback audit dan akun diperiksa ulang. PredictionRetryCommitTest memakai observer MySQL independen untuk visibilitas check processing sebelum dispatch, kegagalan dispatch tetap HTTP 202/failed, serta payload job persisten yang dijalankan dengan HTTP fake menjadi succeeded tanpa transisi bisnis; respons lama tidak menimpa attempt baru. DomainConcurrencyTest retry_http memakai dua koneksi/barrier/lock wait aktual. Chrome retry.spec.ts: cookie login, baca sebelum claim, arsip read-only, cancel, 320px, respons 202 diputus sesudah commit tanpa replay, baca ulang attempt 2 processing dan tombol disabled. Ini bukan uji FastAPI nyata/timeout POSIX baru.

## Keputusan tingkat 1 dan 2

ApprovalActionsTest: setuju L1→L2→approved, reject kedua level dengan alasan wajib, L2 tidak melewati L1/snapshot lain, role/pemilik/versi/stage, field server, larangan revisi, succeeded wajib untuk semua keputusan, rollback audit, cek ulang actor, snapshot immutable, privasi notes nasabah, dan draf baru setelah final. DomainConcurrencyTest approval_http menguji kedua level pada dua proses/koneksi dengan barrier + lock wait aktual; satu decision/history/audit. Chrome approval.spec.ts memakai login cookie tiga peran, forward nyata, L1 approve, L2 approve, respons final diputus tanpa replay, batal, validasi reject, reject L1, alur L1 approve→reject L2, desktop/320px. Hasil mengikuti PROGRESS.
