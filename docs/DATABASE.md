# Kamus data dan constraint — v1.2

Schema 14 tabel domain sudah tersedia melalui migration users dan `2026_11_06_000002_create_loan_domain_tables`, terpasang pada database dev/test tanpa reset. Constraint diuji pada MySQL 8.0.30; keberadaan tabel tidak berarti alur snapshot, dokumen, pemeriksaan, dan persetujuan sudah diimplementasikan. InnoDB, utf8mb4, semua id BIGINT UNSIGNED; uang DECIMAL(18,2); waktu DATETIME UTC. Gunakan VARCHAR + CHECK untuk enum domain. Semua FK ON DELETE RESTRICT. Nullability eksplisit pada migration, terutama draf yang belum lengkap.

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

Transaksi mengunci pengajuan, memastikan status draft dan belum ada submission, menyimpan daftar path file dalam job pembersihan **database queue pada koneksi transaksi yang sama**, menghapus document_versions lalu loan_applications, dan menulis activity_log. Job pembersihan di-enqueue di dalam transaksi (gunakan beforeCommit jika konfigurasi default after_commit=true) sehingga rollback ikut membatalkan job. Worker baru dapat melihat row job setelah commit. Ini pengecualian sadar dari dispatch prediksi afterCommit: setelah penghapusan tidak ada parent yang bisa dipindai untuk pemulihan.

Job hanya menghapus path yang dibuat server di disk privat. Idempoten: file tidak ada berarti berhasil. Kegagalan tersimpan di failed_jobs, dapat diulang, tidak hilang. Jangan menghapus file fisik sebelum commit dan jangan membangun path dari nama berkas input. Semua FK tetap RESTRICT; delete draf membersihkan child eksplisit sebelum parent. Jangan CASCADE audit.

Implementasi irisan draf memakai `CustomerWorkspace` dan `CleanupDraftDocuments`: urutan lock user → aplikasi → versi dokumen; queue `document-cleanup`, koneksi database yang sama, dispatch beforeCommit. Job hanya menerima path relatif berawalan `loan-documents/` pada disk local privat, menolak traversal, dan melempar kegagalan agar dapat diulang. Prefix ini menjadi acuan penyimpanan server pada tahap upload berikutnya. Worker queue tersebut dijalankan dengan `--queue=document-cleanup`; endpoint upload belum tersedia.

Uji rollback membuktikan metadata/file tetap ada dan job batal. Uji commit membaca row job dari koneksi observer MySQL independen, lalu menjalankan payload cleanup dua kali pada disk uji. Uji konkurensi memakai dua proses PHP, CONNECTION_ID berbeda, transaksi yang tumpang tindih, serta barrier sebelum commit: satu create draf/log dan satu assignment aktif yang commit. Ini belum membuktikan endpoint claim beserta riwayat tahap internal, karena endpoint itu belum tersedia.

`DocumentTypeSeeder` menambahkan D01–D04 yang belum ada, idempoten, dan melaporkan konflik konfigurasi yang sudah ada tanpa menimpanya. Tidak ada seeder model aktif atau snapshot/pengajuan otomatis. Batas field API mengikuti kapasitas kolom; lihat API_CONTRACT untuk presisi string uang dan integer unsigned.

Aturan izin runtime di atas tetap target pemisahan user DB migration/runtime; grant per tabel dan audit immutability SQL tingkat runtime belum dipasang pada sesi ini. Model ApplicationSubmission menolak update/delete instance dan profil/draf tidak mengubah snapshot lama. Jangan menyamakan pemeriksaan FK/unique dengan penegakan seluruh aturan transisi, append-only, atau otorisasi tahap internal.

## Pendukung Laravel

sessions, jobs, failed_jobs, job_batches bila dipakai, password_reset_tokens, cache/cache_locks dan personal_access_tokens bila benar-benar diperlukan tidak dihitung dalam 14 domain tables/ERD. Cookie SPA tidak membutuhkan penerbitan bearer token. Sesuaikan migration default users dengan schema domain; jangan membuat users kedua.

