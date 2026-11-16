# Pengujian per tahap

Renderer dokumentasi memverifikasi versi Mermaid terisolasi, parse/render tiga sumber di Chrome dan menghasilkan PNG/SVG yang ditinjau visual. Browser menolak permintaan origin luar, aset hanya dari server loopback milik runner; tidak menyentuh DB/aplikasi. Runtime live diuji terpisah melalui guard/Accounts/Integrity dan Check model; akun belum tersedia adalah exit1 yang diharapkan, bukan bukti login operator.

Paket serah terima memetakan hasil tersimpan ke lingkungan yang benar. Pemeriksaan dokumentasi mencakup tautan lokal, fence, pin registry dan entitas diagram terhadap schema; bukan pengganti rendering Mermaid atau pengujian aplikasi. Penerimaan operator baru mengikuti HANDOVER. Bila MySQL berhenti, guard runtime gagal tertutup dan bukti lama tidak diklaim sebagai pemeriksaan live yang baru.

DatabaseContentDigestTest memakai MySQL test untuk membuktikan deteksi perubahan isi berukuran sama, byte NUL, beda NULL/string kosong, pembacaan melewati500 baris dan penolakan nama tabel invalid/tanpa primary key. Exporter restore-workspace dan mode recovery-drill tetap menolak konteks DB test biasa. Digest semua tabel pada import aktual dibandingkan dengan manifest privat dari sumber yang writer-nya dihentikan; bukti ini dipisahkan dari browser.

Skenario Chrome restore dijalankan hanya melalui scripts/restore-browser-smoke.py pada target restore yang disetujui. Runner menolak port layanan yang terpakai, memulai profil guarded dan menghentikan hanya proses miliknya. Uji membuat akun/pengajuan nasabah serta tiga peninjau fiktif baru, memeriksa cookie/CSRF/CORS, sesi/DECIMAL setelah reload, upload tiga dokumen wajib via UI, unduh byte sama, submit202, worker model pada tepat satu job fixture, forward dan persetujuan1/2 hingga approved, kemudian logout. Hash snapshot/tautan/dokumen sebelum-sesudah keputusan serta ukuran/hash file privat diperiksa oleh probe guarded. Probe menolak DB test biasa sebelum membuat akun. Fixture dipertahankan; bukan verifikasi pemulihan dataset pengajuan lengkap. Trace/video/screenshot otomatis off dan screenshot manual hanya sesudah logout/isian kosong. Suite umum mengabaikan restore-browser.spec.ts.

RestoreWorkspaceTest menolak UUID/DB/environment salah, marker belum tervalidasi dan bootstrap DB restore tanpa ID folder; guard runtime/workspace menolak konteks test biasa. Profil aktual terpisah membuktikan grant/workspace, model fixture serta HTTP health/CSRF/anonymous dan header cookie/CORS. HTTP memakai DB restore yang disetujui; feature suite tetap DB MySQL test. Ini belum pengujian browser atau workflow restore dengan data representatif.

PrivateBackupTest menguji file bersarang/biner, hash/ukuran/sumber tetap, penolakan overwrite/tujuan rekursif, guard maintenance/DB test, pin manifest wajib serta tamper ukuran sama. Restore menolak DB test dan flag/pin yang tidak lengkap sebelum membuat target. Ekspor/restore aktual memakai target baru yang dipilih pengguna; bukan overwrite DB test. Counts/constraint/index/integritas/hash diperiksa terpisah dari browser.

DocumentIntegrityAuditTest memeriksa SHA256/ukuran dengan stream file besar pada disk fiktif, perubahan berukuran sama/truncation, file unavailable, referensi traversal dan disk gagal tanpa laporan parsial/path. Metadata/job/file tidak diperbaiki. Baseline metadata test lama dipertahankan dan deltas fixture baru diverifikasi; bukan bukti restore atau konsistensi snapshot DB/filesystem.

RuntimeDatabaseGuardTest menolak operator dan production sebagai runtime. Provision -Testing membuktikan private pipe, random password server, DPAPI round-trip tanpa plaintext file dan guard privilege pada akun test sementara; akun dibersihkan. Provision lokal adalah operasi akun demo, bukan fitur diuji pada data pengguna. Profil wajib credential terpisah tanpa fallback. Browser sebelumnya memakai matriks grant sama pada akun sementara; bukan bukti UI operator dengan credential DPAPI baru.

LocalDemoAccountsTest membuktikan lima peran aktif terlapor tanpa identitas/mutasi, admin nonaktif tidak dihitung dan production ditolak. Regresi gabungan bootstrap/admin memakai MySQL test rollback. Operasi Accounts development hanya baca dan mengembalikan1 saat peran belum lengkap; secret prompt akun dilakukan operator di terminal, tidak menggunakan kredensial bawaan/argv/chat.

LocalDemoDatabaseGuardTest membuktikan profil demo menolak environment testing tanpa mutasi. Guard positif dicek baca-saja pada DB development. Aktivasi awal via profil adalah operasi persiapan development kosong, bukan pengujian pada data pengguna; regresi fitur tetap hanya MySQL test. Check profil lulus ke runtime model asli yang kemudian dihentikan.

LocalDemoReadinessTest menguji command baca-saja dengan MySQL rollback/HTTP fake: sukses GET ready tanpa prediksi/mutasi, penolakan mode stub/URL eksternal/cache selain database dan model/processing tidak tersedia. Smoke runner --local-preflight memakai Uvicorn asli, handover DB test sementara dan pemulihan. local_launcher_smoke.py membuktikan launcher PowerShell terpin, mode model, anonymous401 dan penghentian proses milik smoke. Ini bukan kesiapan konfigurasi development atau bukti grant/worker/browser baru.

CleanupCrashTest membuktikan kompensasi upload sebelum/sesudah INSERT jobs dan delete draf sebelum/sesudah commit dengan proses/koneksi MySQL berbeda. Job sebelum commit tidak terlihat; crash me-rollback draf/job. Job durable setelah commit diproses worker database nyata pada queue UUID dengan disk fiktif, hanya fixture run yang dibersihkan. Tidak membuktikan crash multi-file/ack worker atau rekonsiliasi orphan otomatis.

UploadCrashAuditTest memakai proses upload CustomerDocuments asli, barrier sebelum INSERT/setelah INSERT belum commit/setelah commit, koneksi MySQL independen dan disk UUID fiktif. Empat mode termasuk transaksi yang akhirnya commit membuktikan kandidat sebelum commit dapat menjadi referenced dan semua file tetap sesudah proses anak dihentikan. Ini bukan bukti delete orphan, crash enqueue kompensasi, jaringan COMMIT atau timeout PCNTL.

OrphanDocumentAuditTest memakai MySQL test dan disk fiktif untuk membuktikan audit tanpa mutasi: referensi seluruh versi, tenggang/batasnya, luar prefix, opsi invalid, kegagalan DB, serta file/metadata/job tetap. Ini belum bukti crash recovery atau keamanan penghapusan orphan. Lihat ORPHAN_DOCUMENT_AUDIT.

Regresi runtime terbatas tersedia lewat model_browser_run.py --runtime-grants: lima skenario Chrome (auth dua, admin dua, model-flow satu), worker model nyata, serta schedule:run dengan fixture expiry khusus. Hasil 15 November: Chrome5 lulus, scheduler snapshot/status tetap, model aktif test dipulihkan. Error pesan cleanup CRLF pada runner awal diperbaiki; uji lifecycle akun ulang exit0 dan inventory kosong. Bukti/runtime runbook: DATABASE_RUNTIME. Ini bukan semua cabang PHPUnit diuji ulang dengan akun terbatas.

Pengamanan grant runtime: `php artisan db:runtime-grants` hanya mencetak rencana; unit test memeriksa batas hak dan menolak identifier/host tidak aman. Probe eksplisit `tests/Support/runtime-grants.php --probe` memakai akun sementara hanya pada MySQL `kkp_pinjaman_test`, menguji izin positif/negatif, rollback fixture dan cleanup akun. Lihat DATABASE_RUNTIME untuk guard, hasil dan batas bukti. Suite fitur tetap memakai akun operator test untuk menyiapkan fixture; lulusnya suite tersebut belum membuktikan seluruh aplikasi memakai akun runtime terbatas.

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

GeneratorTests memeriksa jumlah 10.000 baris dan proporsi kelas, pemisahan kelompok, duplikat lintas dataset, kompatibilitas setiap baris dengan validator FastAPI, semantik injeksi/korelasi normal, strict config/seed/split, byte determinism/hash manifest, CLI serta penolakan overwrite. Dua preview penuh juga dibandingkan byte demi byte pada tiga CSV, config dan manifest. Ini bukti integritas generator, bukan kualitas model. Bukti generator draf historis dipisahkan dari eksperimen terkunci; lihat bagian evaluator di bawah.

LockedGeneratorTests membuktikan komposisi utama+borderline, jarak minimum pada rasio uang final, pembulatan sen, penolakan duplikat/kelompok global, config/protocol mismatch, UTF-8/LF dan dua output identik tanpa overwrite. ModelPipelineTests memeriksa urutan/rasio A/B, label/input invalid, grid dan fit deterministik pada fixture, tanpa memakai CSV eksperimen. Evaluator validation/holdout dan hash-before-joblib-load kini tersedia; readiness versi+hash dan registrasi DB masih berikutnya. Bukti awal30 tes tetap historis; suite terbaru36 tes.

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

## Admin akun internal — irisan awal

AdminAccountsTest menguji anonim/empat peran/nonaktif ditolak, daftar hanya internal dan filter/pagination, resource/log allowlist tanpa hash/token/metadata/description, create empat role dan hash/audit/validasi/duplikat, role actor diperiksa ulang dan rollback audit, akses dokumen/antrean admin tetap ditolak. Bootstrap users:create-admin memakai prompt password tersembunyi dan menolak production. DomainConcurrencyTest accounts_http dua koneksi/kernel/barrier/admin sama dengan lock wait aktual dan satu create/audit (201/422). Chrome admin.spec.ts: login cookie admin, create berrespons 201 diputus tanpa replay, password form kosong, baca ulang satu akun, filter/log, login akun baru, reviewer tidak melihat /admin, desktop/320px. Pengujian perubahan akses dijelaskan pada bagian berikut.

## Admin perubahan akses

AccountManagementTest menguji release tugas + revoke sessions + status pinjaman tetap, aktivasi tanpa restore, role-change ditolak saat tugas aktif, stale expected, larangan perubahan akun sendiri yang juga mempertahankan admin aktif terakhir, role/noninternal/input, rollback audit (user/session/task), dan marker sesi lama ditolak setelah reaktivasi serta login baru berhasil. DomainConcurrencyTest deactivate_claim/claim_deactivate dua proses/kernel/barrier dengan lock wait MySQL aktual membuktikan kedua urutan. Browser account-access.spec.ts: reviewer claim nyata, admin role-change409, cancel/disable respons200 abort tanpa replay, sesi reviewer invalid, reactivate + login + tugas tetap unassigned, role-change + sesi lama invalid + login role baru, desktop320. Regresi auth.spec.ts membuktikan cookie/CSRF login/logout tetap bekerja. Hasil aktual di PROGRESS.

## Evaluator model terkunci

EvaluationTests memeriksa hash sebelum joblib load, arah skor/threshold0, ranking N/A, support29/30/kosong, batas pita, tie-break tanpa AP/AUC, larangan parse holdout melalui training dan claim sekali. Suite penuh36 tes lulus18.724s. Validation36 fit dan holdout sesudah tag selected exit0; laporan/per-seed/confusion/support tersedia di validation/ml-experiment-v1-results.md. Integrasi FastAPI/readiness/model DB masih belum diuji.

## FastAPI mode model

ModelServiceTests menggunakan artefak terpilih pada fixture fiktif, tanpa data holdout: hash sebelum load, parameter/fitur/dependency, no-fit inference, auth/ID/kontrak, no-fallback versi pinned ganda, header stub, output nonfinite dan file registry berubah sesudah startup. Suite44 tes lulus22.526s. Smoke Uvicorn HTTP nyata exit0 dan parser start-ml lulus; lihat ML_MODEL_RUNTIME.md. Queue Laravel, model_versions/aktivasi DB dan browser model asli belum diuji.

## Registrasi model Laravel dan queue model

ModelRegistrationTest: metadata/hash/fitur/mode, idempotensi inactive, activation readiness fake dan model aktif lain dipertahankan, immutable metadata serta rollback audit. Target awal5/30 assertions lulus; regresi penuh135/1448 assertions lulus122.81s pada MySQL8.0.30 test. Smoke queue unik pada DB test melalui worker nyata ke Uvicorn model succeeded, bukan HTTP fake/handler langsung; status bisnis tetap. Tidak membuktikan submit browser/model aktif atau handover deployment. Hasil target ulang sesudah hardening baca metadata dan Pint dicatat pada PROGRESS.

## Handover model

ModelHandoverTest membuktikan swap/idempotensi, pin terminal tetap, stale expected, readiness/hash/mode/artifact invalid, processing tidak tersedia ditolak tanpa dimutasi dan processing versi tersedia boleh lanjut, serta audit rollback. Target4/18 assertions lulus2.11s; readiness fake dan outer transaction test, bukan handover live/concurrency dua proses atau browser demo. Hasil regresi penuh mengikuti PROGRESS.

## Browser model asli

playwright.model.config.ts menjalankan model-flow dan auth. Run akhir3 lulus6.3m dengan backend/model mode nyata, submit/retry koneksi gagal/revisi/resubmit dan approve L1/L2 sampai approved. Required docs selain D01 adalah fixture; worker mengonsumsi hanya job baru milik run di queue unik. Model test dipulihkan, .env/DB pengembangan tidak diubah. Bukti dan batas di MODEL_BROWSER_REGRESSION.
