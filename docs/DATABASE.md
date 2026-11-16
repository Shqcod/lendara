# Kamus data dan constraint — v1.2

Schema 14 tabel domain sudah tersedia melalui migration users dan `2026_11_06_000002_create_loan_domain_tables`, terpasang pada database dev/test tanpa reset. Constraint diuji pada MySQL 8.0.30; alur nasabah/job prediksi dan antrean/claim/release internal tersedia. Aksi verifikator note/revisi/forward tersedia; retry manual dan persetujuan tingkat 1/2 tersedia; admin daftar/buat akun internal dan log tersedia; edit role/nonaktif-aktif akun internal dan pencabutan sesi tersedia. InnoDB, utf8mb4, semua id BIGINT UNSIGNED; uang DECIMAL(18,2); waktu DATETIME UTC. Gunakan VARCHAR + CHECK untuk enum domain. Semua FK ON DELETE RESTRICT. Nullability eksplisit pada migration, terutama draf yang belum lengkap.

## 14 tabel domain

1. **users**: id PK, name VARCHAR(150), email VARCHAR(255) UQ, password hash, role VARCHAR(30), is_active BOOLEAN, remember_token nullable, created_at/updated_at. CHECK role sesuai SPECIFICATION. Tidak ada DELETE runtime.
2. **customer_profiles**: id PK, user_id FK users UQ, identity_number VARCHAR(16) nullable UQ, birth_date/address/phone/occupation_type/workplace_name/work_duration_months nullable sampai lengkap, timestamps. User 0..1 profil; backend membatasi nasabah. NIK string menjaga nol awal, generator wajib unik.
3. **loan_applications**: id PK, application_number VARCHAR(40) UQ, customer_id FK users, status VARCHAR(40), current_submission_id nullable, loan_amount/tenor_months/purpose/monthly_income/monthly_expenses/existing_installments nullable untuk draf, active_customer_id generated UQ, timestamps. Backend membatasi customer_id ke nasabah. CHECK status dan angka bila terisi. Satu aktif termasuk draf.
4. **application_submissions**: id PK, application_id FK, version_number INT UNSIGNED, customer_snapshot JSON, loan_amount, tenor_months INT UNSIGNED, purpose TEXT, monthly_income, monthly_expenses, existing_installments, submitted_by FK users, submitted_at. Semua konten wajib lengkap. UQ(application_id,version_number), UQ(application_id,id). Immutable.
5. **document_types**: id PK, code VARCHAR(10) UQ, name VARCHAR(100), is_required, is_active, timestamps. D01–D04 dari spesifikasi.
6. **document_versions**: id PK, application_id FK, document_type_id FK, version_number INT UNSIGNED, original_filename, storage_path UQ, mime_type, file_size_bytes BIGINT UNSIGNED, file_hash CHAR(64), uploaded_by FK users, uploaded_at. UQ(application_id,document_type_id,version_number). Tidak update isi versi; DELETE hanya jalur draf belum dikirim.
7. **submission_documents**: id PK, submission_id FK, document_type_id FK, document_version_id FK, created_at. UQ(submission_id,document_type_id). Backend memvalidasi pengajuan dan jenis versi dokumen cocok, dalam transaksi pengiriman. Tidak ada endpoint mengubah baris ini.
8. **model_versions**: id PK, version_code UQ, algorithm, feature_names JSON berurutan (fitur estimator), parameters JSON (termasuk raw_input_names/feature_schema, seed dan metadata pipeline), contamination DECIMAL(6,5) dengan 0 < nilai <= 0.5, decision_threshold DOUBLE (0.0 pada decision_function), score_method, artifact_path, artifact_hash CHAR(64), training_data_version, is_active, active_marker generated UQ, created_at. Config/artefak immutable; hanya is_active dapat diubah. Stub version_code berawalan stub-, metadata parameter menjelaskan source stub; artifact_path berupa penunjuk kode stub lokal yang di-hash, bukan artefak model pura-pura.
9. **anomaly_checks**: id PK, submission_id FK, model_version_id FK, attempt_number INT UNSIGNED, status VARCHAR(20): processing/succeeded/failed, input_features JSON, score DOUBLE nullable, is_anomaly BOOLEAN nullable, started_at, deadline_at, finished_at nullable, failure_reason nullable, requested_by FK users nullable, running_submission_id generated UQ, succeeded_submission_id generated UQ. UQ(submission_id,attempt_number), UQ(submission_id,id). Update dari processing ke terminal; terminal immutable. score/result hanya terisi pada succeeded; started_at adalah awal percobaan termasuk antre, bukan bukti HTTP sudah dimulai.
10. **assignments**: id PK, application_id FK, submission_id, assigned_to FK users, stage verification/approval_1/approval_2, claimed_at, ended_at nullable, end_type completed/released nullable, end_reason nullable, ended_by FK users nullable, active_application_id generated UQ. FK komposit(application_id,submission_id) → submissions(application_id,id); UQ(id,submission_id). Simpan riwayat, jangan mengosongkan/mengganti assigned_to baris lama.
11. **verification_results**: id PK, submission_id FK, assignment_id, verifier_id FK users, result_type note/request_revision/forward, notes TEXT nullable ketika tidak diwajibkan oleh tindakan/hasil anomali, document_checklist JSON, anomaly_check_id nullable, terminal_submission_id generated UQ, created_at. FK(assignment_id,submission_id) → assignments(id,submission_id). FK(submission_id,anomaly_check_id) → anomaly_checks(submission_id,id). Forward wajib merujuk succeeded; revision wajib notes; anomaly forward wajib notes. note/revision bisa tanpa anomaly_check_id. Nullability notes mengikuti aturan bersyarat API_CONTRACT; ini tidak membuat alasan revisi/tinjauan anomali opsional. Konten append-only.
12. **approval_decisions**: id PK, submission_id FK, assignment_id, approver_id FK users, level INT (1 atau 2), decision approved/rejected, notes TEXT nullable kecuali tolak, anomaly_check_id wajib, decided_at. UQ(submission_id,level); FK assignment dan anomaly komposit seperti verification_results. Backend menentukan tingkat/aktor, memeriksa urutan dan hasil succeeded. Append-only.
13. **application_status_histories**: id PK, application_id FK, submission_id nullable, from_status wajib, to_status wajib, changed_by FK users nullable, notes nullable, changed_at. FK(application_id,submission_id) → submissions(application_id,id) jika submission tidak null. Pencatatan pertama saat kirim, from_status=draft. Append-only. notes bukan field publik otomatis.
14. **activity_logs**: id PK, actor_id FK users nullable, action, target_type, target_id nullable, description, metadata JSON, created_at. Target generik tidak memiliki FK ke objek; log draf tetap ada setelah draf dihapus. Jangan log kata sandi, token, kunci, isi dokumen. Append-only.

## Generated columns unik

MySQL mengizinkan banyak NULL pada unique index. Kolom ini bukan FK dan tidak diisi klien.

| Kolom | Ekspresi logis |
|---|---|
| loan_applications.active_customer_id | CASE WHEN status NOT IN ('approved','rejected') THEN customer_id ELSE NULL END |
| assignments.active_application_id | CASE WHEN ended_at IS NULL THEN application_id ELSE NULL END |
| verification_results.terminal_submission_id | CASE WHEN result_type IN ('forward','request_revision') THEN submission_id ELSE NULL END |
| anomaly_checks.running_submission_id | CASE WHEN status='processing' THEN submission_id ELSE NULL END |
| anomaly_checks.succeeded_submission_id | CASE WHEN status='succeeded' THEN submission_id ELSE NULL END |
| model_versions.active_marker | CASE WHEN is_active=1 THEN 1 ELSE NULL END |

Selain itu: FK(loan_applications.id, current_submission_id) → application_submissions(application_id,id). Tambahkan setelah kedua tabel dibuat untuk mengatasi siklus. Menjamin kepemilikan versi, bukan otomatis menjamin versi maksimum; penunjuk diperbarui backend atomik.

Indeks antrean: applications(status), assignments(assigned_to,ended_at), anomaly_checks(status,deadline_at). version_number/attempt_number dialokasikan dengan lock parent FOR UPDATE + unique index. Lock urutan konsisten untuk mengurangi deadlock. Aktivasi model diserialkan (misalnya named lock MySQL khusus aktivasi), nonaktifkan A dahulu lalu aktifkan B dalam satu transaksi; unik hanya menjamin maksimal satu, backend memeriksa tersedia sebelum pemeriksaan baru.

## Izin runtime

- users SELECT/INSERT/UPDATE, tanpa DELETE; backend membatasi pengubahan role/is_active.
- Snapshot, submission_documents, verification_results, approval_decisions, status_histories, activity_logs: SELECT/INSERT, tanpa UPDATE/DELETE.
- anomaly_checks: SELECT/INSERT/UPDATE; bukan append-only murni. Backend membatasi transisi terminal.
- assignments: SELECT/INSERT/UPDATE untuk penutupan; riwayat tidak dihapus.
- model_versions: SELECT/INSERT dan perubahan is_active terkontrol; config immutable.
- loan_applications/document_versions: izin DELETE diperlukan untuk draf; status/ruang lingkup jalur delete dijaga aplikasi.
- User migration terpisah untuk DDL; tables pendukung jobs/sessions butuh hak operasionalnya sendiri.

## Penghapusan draf dan pembersihan persisten

CleanupCrashTest kini membuktikan empat jendela penjadwalan: kompensasi upload sebelum/sesudah enqueue autocommit, delete draf sebelum/sesudah commit. Job delete belum commit tidak terlihat pada koneksi lain dan crash mempertahankan draf/metadata/file. Setelah commit job durable tetap tersedia dan worker nyata dapat membersihkan fixture pada disk/queue test unik. Crash sebelum enqueue kompensasi tetap menyisakan orphan. Lihat ORPHAN_DOCUMENT_AUDIT untuk batas bukti.

UploadCrashAuditTest kini membuktikan empat titik interupsi/commit dengan CustomerDocuments asli, dua koneksi MySQL dan disk fiktif. File dari INSERT belum commit dapat tampak tanpa referensi pada audit lalu menjadi referenced setelah commit; audit tetap baca-saja. Bukti ini tidak mencakup crash enqueue kompensasi atau keamanan penghapusan otomatis. Matriks/prosedur operator di ORPHAN_DOCUMENT_AUDIT.md.

Audit baca-saja `documents:audit-orphans` kini tersedia; lihat ORPHAN_DOCUMENT_AUDIT.md. Ia melaporkan observasi kandidat dengan tenggang minimal24 jam tanpa menghapus/enqueue. Crash recovery dan penghapusan orphan terkontrol masih belum terbukti; referensi belum commit tetap dapat luput dari audit.

Rencana grant yang dapat dicetak dan probe MySQL terisolasi kini tersedia di [DATABASE_RUNTIME.md](DATABASE_RUNTIME.md). Probe membuktikan matriks hak pada akun sementara DB test; akun development/deployment belum diganti. Shared locking read pada jenis dokumen, snapshot terakhir dan riwayat keputusan menghindari kebutuhan izin UPDATE pada konten immutable; lock exclusive pada user/pengajuan tetap menjaga serialisasi.

Transaksi mengunci pengajuan, memastikan status draft dan belum ada submission, menyimpan daftar path file dalam job pembersihan **database queue pada koneksi transaksi yang sama**, menghapus document_versions lalu loan_applications, dan menulis activity_log. Job pembersihan di-enqueue di dalam transaksi (gunakan beforeCommit jika konfigurasi default after_commit=true) sehingga rollback ikut membatalkan job. Worker baru dapat melihat row job setelah commit. Ini pengecualian sadar dari dispatch prediksi afterCommit: setelah penghapusan tidak ada parent yang bisa dipindai untuk pemulihan.

Job hanya menghapus path yang dibuat server di disk privat. Idempoten: file tidak ada berarti berhasil. Kegagalan tersimpan di failed_jobs, dapat diulang, tidak hilang. Jangan menghapus file fisik sebelum commit dan jangan membangun path dari nama berkas input. Semua FK tetap RESTRICT; delete draf membersihkan child eksplisit sebelum parent. Jangan CASCADE audit.

Implementasi irisan draf memakai `CustomerWorkspace` dan `CleanupDraftDocuments`: urutan lock user → aplikasi → versi dokumen; queue `document-cleanup`, koneksi database yang sama, dispatch beforeCommit. Job hanya menerima path relatif berawalan `loan-documents/` pada disk local privat, menolak traversal, dan melempar kegagalan agar dapat diulang. Upload memakai prefix yang sama. Worker queue tersebut dijalankan dengan `--queue=document-cleanup`.

## Versi dokumen privat

Audit isi baca-saja documents:audit-integrity membandingkan ukuran/hash tersimpan untuk semua versi dengan disk privat, termasuk versi lama. Metadata dibaca chunk100/stream64KiB dan output agregat tanpa identitas/path. Kegagalan/inconsistency tidak memperbaiki metadata/file; audit bukan snapshot atomik atau bukti restore. Lihat BACKUP_RESTORE.md sebelum menyiapkan backup pasangan DB/disk.

`CustomerDocuments` mengunci user → aplikasi → jenis dokumen → versi terkini untuk mengalokasikan versi, menyimpan file baru, lalu metadata dan audit dalam transaksi. Unique(application_id,document_type_id,version_number) tetap pengaman SQL. Path server `loan-documents/<application_id>/<uuid>.<extension>` berada pada disk local privat; nama asli hanya metadata/attachment, bukan path. Hash SHA256 dan ukuran disimpan dari file unggahan. Model DocumentVersion menolak update instance; query SQL langsung tetap memerlukan pengamanan grant runtime sesuai target di atas.

File lama dan submission_documents tidak berubah saat upload revisi. Endpoint submit/resend kini membuat tautan snapshot; pengujian kirim ulang membuktikan tautan lama tetap setelah dokumen/profil/keuangan berubah. Dua proses dengan transaksi bertumpang tindih membuktikan dua upload pengajuan/jenis sama commit sebagai versi 1/2 dengan path berbeda dan hash cocok.

Disk dan MySQL bukan satu transaksi atomik. Jika transaksi upload gagal setelah file ditulis, service memeriksa ulang keberadaan referensi metadata sebelum menjadwalkan CleanupDraftDocuments untuk file baru yang tidak direferensikan. Kegagalan penjadwalan dicatat aman tanpa isi/path berkas. Crash proses pada celah write/commit/cleanup atau koneksi DB yang tidak dapat dipastikan dapat menyisakan orphan: rekonsiliasi berkas privat dengan masa tenggang/konfirmasi masih pekerjaan berikutnya, belum diuji dengan penghentian paksa. Jangan menghapus berdasarkan dugaan kegagalan commit atau menjalankan pembersihan massal.

Uji rollback membuktikan metadata/file tetap ada dan job batal. Uji commit membaca row job dari koneksi observer MySQL independen, lalu menjalankan payload cleanup dua kali pada disk uji. Uji konkurensi memakai dua proses PHP, CONNECTION_ID berbeda, transaksi yang tumpang tindih, serta barrier sebelum commit: satu create draf/log dan satu assignment aktif yang commit. Ini belum membuktikan endpoint claim beserta riwayat tahap internal, karena endpoint itu belum tersedia.

`DocumentTypeSeeder` menambahkan D01–D04 yang belum ada, idempoten, dan melaporkan konflik konfigurasi yang sudah ada tanpa menimpanya. Tidak ada seeder model aktif atau snapshot/pengajuan otomatis. Batas field API mengikuti kapasitas kolom; lihat API_CONTRACT untuk presisi string uang dan integer unsigned.

Aturan izin runtime di atas tetap target pemisahan user DB migration/runtime; grant per tabel dan audit immutability SQL tingkat runtime belum dipasang pada sesi ini. Model ApplicationSubmission menolak update/delete instance dan profil/draf tidak mengubah snapshot lama. Jangan menyamakan pemeriksaan FK/unique dengan penegakan seluruh aturan transisi, append-only, atau otorisasi tahap internal.

## Transaksi pengiriman dan hasil prediksi

CustomerSubmissions mengunci user → aplikasi → profil → jenis aktif → versi dokumen → model aktif → versi pengiriman terakhir. Periksa pointer/status dan data tersimpan, alokasikan version_number dengan locking read, buat snapshot/tautan/check processing/history/audit, kemudian update current_submission_id/status dalam transaksi sama. Tidak ada perubahan schema atau migration tambahan pada irisan ini. Unique/FK tetap pengaman SQL. Pembuktian dua proses menghasilkan satu snapshot/check/history/log, permintaan kedua SUBMISSION_VERSION_CONFLICT.

Callback DB afterCommit secara eksplisit membungkus dispatch database queue predictions. Kegagalan enqueue dicatat aman memakai ID check, lalu conditional fail; callback tidak melempar kegagalan enqueue sebagai batalnya pengiriman yang sudah commit. Jika proses/DB tidak tersedia, deadline tetap dapat dipindai scheduler. Queue job persisten dan snapshot/check dibaca dari koneksi observer independen setelah commit pada pengujian.

RunPrediction memakai lock eksekusi per check pada cache database (60 detik), tries=1, timeout job 30 detik. Context dibaca lalu HTTP dijalankan tanpa transaksi; handler menolak bila sudah berada dalam transaksi. Timeout HTTP min(20, konfigurasi timeout, sisa deadline-5); budget tidak cukup langsung failed. Hasil disimpan lewat transaksi pendek dengan lock aplikasi → check, memeriksa status processing, deadline, snapshot terkini, identitas/schema/model/source, skor finite dan konsistensi tanda. Respons lama/duplikat tidak mengubah terminal state dan menghasilkan audit ID/reason aman. Tidak ada perubahan status pengajuan otomatis oleh hasil model.

Command anomalies:expire menandai processing melewati deadline sebagai failed secara kondisional, dijadwalkan setiap menit dengan lingkungan aplikasi diteruskan eksplisit ke subprocess. Worker/scheduler memakai DB runtime yang sama dengan aplikasi; testing dibuktikan pada kkp_pinjaman_test. Job yang hilang dapat dipulihkan sebagai failed, bukan inference otomatis ulang. Ini jaminan efek DB kondisional, bukan exactly-once HTTP atau bukti penghentian worker POSIX pada Windows.

## Penugasan dan scope internal

ReviewAssignments mengunci user reviewer aktif → aplikasi dalam scope tahap → assignment. Claim memeriksa expected current_submission_id, mengalokasikan baris baru dengan stage/assigned_to dari server, dan menulis audit dalam transaksi. Unique active_application_id tetap menjamin satu aktif global. Release hanya pemilik pada tahap/versi terkini; ended_at/end_type=released/end_reason/ended_by dan audit diubah atomik. assigned_to pada riwayat lama tidak diubah. Status loan_applications/current_submission_id dan snapshot tetap; tidak ada row status_histories baru untuk claim/release karena tidak terjadi transisi status.

Uji concurrency menjalankan HTTP kernel route claim pada dua proses PHP/koneksi/transaksi terpisah. Barrier menahan commit A; performance_schema.data_lock_waits membuktikan B benar-benar menunggu lock A sebelum A dilepas. Hasil 201/409, satu assignment/pemilik/log claim. Ini pengujian route melalui kernel dan MySQL nyata, bukan dua request serial; CSRF/cookie jaringan dibuktikan terpisah dengan Chrome.

Scope internal menggunakan status tahap peran saat ini dan current_submission_id non-null. Detail/nama/uang/dokumen berasal dari snapshot, bukan profil kerja. Arsip pada pengajuan yang masih dalam scope dapat dibaca; unduh versi juga memeriksa tautan submission_documents ke pengajuan dan jenis yang sesuai. Admin dan nasabah tidak mendapat route internal. Ringkasan menggunakan satu agregasi SQL atas assignment aktif agar hitungan tidak saling bertentangan saat claim/release berlomba. Tidak ada schema/migration/grant baru pada irisan ini.

## Pendukung Laravel

sessions, jobs, failed_jobs, job_batches bila dipakai, password_reset_tokens, cache/cache_locks dan personal_access_tokens bila benar-benar diperlukan tidak dihitung dalam 14 domain tables/ERD. Cookie SPA tidak membutuhkan penerbitan bearer token. Sesuaikan migration default users dengan schema domain; jangan membuat users kedua.


## Transaksi aksi verifikator

VerificationActions mengunci user aktif berperan verifikator → aplikasi dalam scope → assignment aktif → succeeded check. Snapshot dari URL harus sama dengan pointer terkini, tugas milik aktor dan stage verification. Checklist hanya merujuk jenis pada submission_documents. Konten append-only, referensi check dipilih server; snapshot/tautan dokumen tidak diubah. Note menyimpan hasil/audit saja; request_revision/forward menyimpan hasil, status history, status aplikasi dan penutupan tugas completed/ended_by bersama audit atomik. Unique terminal_submission_id tetap pengaman SQL. Pengujian audit gagal membatalkan semua efek.

DomainConcurrencyTest verification_http memakai dua proses/koneksi, barrier dan observasi lock wait MySQL sebelum commit; satu terminal result/history/audit commit, request kedua 404 setelah tahap berubah. Ini terpisah dari bukti cookie browser. Tidak ada schema/migration/grant baru.

## Transaksi retry manual

PredictionRetries mengunci user → aplikasi → assignment aktif → riwayat check terbaru; memeriksa actor/peran, versi, kepemilikan, semua percobaan failed. Insert attempt berikutnya pada snapshot sama dengan model/input dipin dan deadline baru, serta activity_logs anomaly.retry_requested atomik. Unique processing/succeeded/attempt tetap berlaku. Snapshot, tautan dokumen, check terminal lama, status dan assignment tidak berubah. PredictionDispatch dipakai bersama pengiriman awal/retry untuk enqueue setelah commit dan kompensasi dispatch gagal. Tidak ada schema/migration/grant baru. Concurrency retry_http dua koneksi/kernel dengan lock wait aktual membuktikan 202/409 dan satu attempt baru/audit.

## Transaksi keputusan persetujuan

ApprovalActions mengunci user aktif/role penyetuju → aplikasi scope → assignment aktif → keputusan snapshot → succeeded check. Level dari role, pemilik/stage/versi diperiksa ulang. L2 memerlukan approved L1 pada snapshot sama. Insert keputusan append-only, status history, update status, penutupan tugas completed/ended_by dan audit atomik; rollback audit membatalkan seluruh efek. Unique(submission_id,level) tetap pengaman. Snapshot, dokumen dan hasil model tetap. Status final melepas marker aktif sehingga nasabah boleh membuat draf baru. Tidak ada schema/migration/grant baru. Dua koneksi/kernel/barrier/lock wait nyata pada masing-masing level membuktikan satu keputusan/history/audit dan request kedua keluar scope.

## Akun internal admin (irisan awal)

InternalAccounts.create mengunci user admin, memeriksa ulang is_active/role, insert akun aktif dengan satu role internal dan hash password dari model User, lalu activity_logs internal_user.created atomik. Unique users.email tetap; race duplicate diterjemahkan ke validasi email, rollback audit membatalkan akun. Tidak mengubah schema/grant/sessions/assignments atau hak unduh. DomainConcurrencyTest accounts_http membuktikan dua koneksi/kernel dari admin yang sama dengan validator sebelum lock user, lock wait aktual dan hasil 201/422; satu akun/audit. Percobaan dua admin berbeda tidak berhasil mengamati pasangan wait yang diharapkan di harness ini dan bukan bukti yang diklaim. Perubahan akses tersedia pada irisan berikut yang dijelaskan di bawah.

## Perubahan akses akun internal

AccountManagement mengunci actor+target users berurutan ID; actor wajib admin aktif, target internal. Self-change ditolak sesuai pilihan pengguna, actor admin lain tetap aktif sehingga tidak menghapus admin terakhir. Lock target sebelum daftar aplikasi/assignment mencegah reviewer claim baru melewati deaktivasi. Aplikasi dikunci urut ID sebelum tugas; role-change menolak tugas aktif. Nonaktif menutup tugas released/ended_by/reason dan audit tugas, user.is_active/role/remember_token, delete sessions dan audit user.access_changed atomik. Activate tidak membuka tugas lama. Status/snapshot/check pinjaman tetap. Sesi database wajib pada koneksi default yang sama.

Tanpa migration/schema baru: ID audit append-only user.access_changed dipakai sebagai penanda revokasi, tersimpan pada sesi saat login di bawah lock user. Middleware membandingkannya termasuk sesudah reaktivasi; request lama yang menyimpan kembali sesi tidak memberikan akses pada permintaan berikutnya. Test marker lama mensimulasikan payload lama dalam feature test. Request yang sudah diotorisasi/berjalan bukan proses yang dipaksa berhenti; semua mutasi domain memeriksa active/role terkunci sebagaimana sebelumnya. Concurrency HTTP kernel dua koneksi dengan lock wait aktual membuktikan deactivate lalu claim ditolak dan claim lalu deactivate menutup tugas yang baru commit.

## Registrasi model asli dari metadata

ModelRegistration memverifikasi SHA256 pada byte metadata yang sama dengan yang diparsing, path/hash artefak dan schema fitur/DECIMAL. Mengisi model_versions+audit atomik di bawah named lock aktivasi yang sama dengan stub; versi identik idempoten, perbedaan immutable ditolak. Aktivasi eksplisit memeriksa readiness/hash sebelum transaksi milik command dan menolak model aktif lain; unique version/active_marker tetap pengaman. Tidak ada migration atau penggantian aktif otomatis. Lihat MODEL_REGISTRATION.md untuk batas uji dan handover.

## Handover aktif maintenance

ModelHandover memakai named lock aktivasi, seluruh row model berurutan ID, expected-active dan unique marker; deaktif/aktif/audit model.handover atomik. Tidak menulis metadata atau repin checks. Versi processing tidak tersedia ditolak; seluruh model asli terdaftar wajib loaded/hash cocok untuk retry mendatang. Operator wajib quiesce writer dan konfigurasi; bukan barrier otomatis lintas proses. Lihat MODEL_HANDOVER.md.
