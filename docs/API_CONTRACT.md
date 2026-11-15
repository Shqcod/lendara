# Kontrak API v1.1 — target implementasi

Endpoint nasabah, job prediksi/deadline, serta antrean/detail/snapshot/claim/release internal tersedia; pembuktian mengikuti PROGRESS.md. Unduhan internal sudah mengikuti scope tahap dan tautan snapshot. Aksi verifikator note/revisi/forward tersedia; retry manual tersedia; persetujuan tingkat 1/2 tersedia; admin belum diimplementasikan. Prefix Laravel /api/v1. Cookie Sanctum, Accept application/json, credentials dan X-XSRF-TOKEN pada mutasi. Publik: CSRF/register/login; selebihnya auth + is_active + policy. Jangan menerima customer_id/aktor/role/assignment_id/status tujuan dari klien yang seharusnya ditentukan server.

## Respons

Berhasil: `{ "data": { }, "message": "Pesan Indonesia" }`. 204 tanpa body. Gagal: `{ "message": "Data belum valid.", "code": "VALIDATION_ERROR", "errors": { "monthly_income": ["Pendapatan harus lebih dari nol."] } }`. Respons native validasi Laravel perlu dinormalisasi ke kontrak ini. Errors kosong jika bukan kesalahan field. Respons frontend harus menampilkan kesalahan per isian. Semua uang pada API publik string desimal; FastAPI menerima angka finite setelah konversi terkontrol.

Pagination: `{ "data": [], "meta": {"current_page":1,"per_page":20,"total":0,"last_page":1}, "links": {"first":"/api/v1/applications?page=1","last":"/api/v1/applications?page=1","prev":null,"next":null} }`; maksimal per_page 100. Filter/sort allowlist, tidak menginterpolasi nama kolom dari input.

HTTP: 200 baca/update; 201 create; 202 submission/pemeriksaan diterima; 204 logout/delete; 401 belum login; 403 peran/nonaktif; 404 objek tidak ada atau milik nasabah lain; 409 konflik; 413 terlalu besar; 419 CSRF; 422 validasi; 429 throttle; 503 layanan belum siap.

## Auth/profil

| Metode | Path | Isi / hasil |
|---|---|---|
| GET | /sanctum/csrf-cookie (tanpa prefix API) | Cookie CSRF, 204 |
| POST | /auth/register | name,email,password,password_confirmation; hanya nasabah, 201, tidak langsung login |
| POST | /auth/login | email,password; cek is_active, regenerasi sesi; 200 data user aman |
| POST | /auth/logout | invalidate sesi sekarang dan regenerasi CSRF; 204 |
| GET | /me | id,name,email,role,is_active; tidak ada hash/token |
| GET | /customer/profile | Profil sendiri |
| PATCH | /customer/profile | name,identity_number,birth_date,address,phone,occupation_type,workplace_name,work_duration_months |

Semua path tabel kecuali CSRF berawalan /api/v1. Password minimal 8, confirmation cocok. Login throttle awal 5/menit per email-IP; register 3/menit per IP. NIK nullable ketika profil belum lengkap, 16 digit dan unik ketika diisi. Backend memvalidasi tanggal/angka; tidak mengubah snapshot lama.

## Nasabah

| Metode | Path | Aturan |
|---|---|---|
| GET | /applications | Pengajuan sendiri; filter status, pagination |
| POST | /applications | Buat draft; 409 jika sudah ada aktif |
| GET | /applications/{id} | Detail kerja + current_submission_id + status |
| PATCH | /applications/{id} | Hanya draft/revision_required; field keuangan/tujuan |
| DELETE | /applications/{id} | Hanya draft belum pernah dikirim; 204 setelah commit |
| GET | /document-types | Kode/nama/wajib/aktif |
| GET | /applications/{id}/documents | Dokumen kerja terkini per jenis, tanpa storage_path |
| POST | /applications/{id}/documents | multipart: document_type_id,file; draft/revision_required; versi baru 201 |
| POST | /applications/{id}/submissions | expected_current_submission_id:null pertama atau ID lama untuk kirim ulang; confirmed:true; 202 |
| GET | /applications/{id}/submissions | Daftar snapshot milik pengajuan |
| GET | /applications/{id}/submissions/{submission_id} | Snapshot/dokumen versi, transformer nasabah |
| GET | /applications/{id}/status-histories | Transisi/waktu, revision_reason terkontrol |
| GET | /document-versions/{id}/download | Stream berkas privat setelah policy; tidak ada URL publik |

Body pengajuan: loan_amount,tenor_months,purpose,monthly_income,monthly_expenses,existing_installments. Draf boleh belum lengkap; nilai terisi harus valid. Submit butuh semua lengkap, pendapatan >0, uang lain nonnegatif, pinjaman>0, tenor integer>0. Batas bisnis maksimum belum disepakati: jangan mengarang batas kelayakan kredit; batasi kapasitas tipe data dan dokumentasikan validasi.

### Irisan profil/draf yang sudah tersedia

- `GET /customer/profile` tidak membuat baris otomatis. `data` berisi name,identity_number,birth_date,address,phone,occupation_type,workplace_name,work_duration_months; field profil yang belum diisi bernilai null. PATCH bersifat parsial; field nullable dapat dikosongkan dengan null, sedangkan name wajib tidak kosong bila dikirim. NIK string 16 digit menjaga nol awal. Tanggal berupa YYYY-MM-DD yang valid, tidak melewati hari ini; tidak ada batas umur kelayakan kredit baru.
- `POST /applications` menerima `{}` atau sebagian dari enam field keuangan/tujuan di atas. Server menentukan pemilik, nomor LND + ULID, status draft, dan pointer pengiriman null. Tidak membuat status history atau hasil anomali; activity_logs mencatat pembuatan.
- Uang yang diisi wajib **string** desimal kanonik tanpa pemisah ribuan atau eksponen: maksimal 16 digit sebelum titik dan 2 digit setelah titik sesuai DECIMAL(18,2). JSON number/boolean ditolak agar presisi tidak hilang. Nilai keluar selalu dua desimal, misalnya `"20000000.01"`; field kosong tetap null. Tidak ada batas nominal kelayakan kredit tambahan.
- tenor_months yang terisi adalah integer JSON 1–4294967295; work_duration_months 0–4294967295 sesuai INT UNSIGNED. Text purpose/address maksimal 16000 karakter agar UTF-8 empat byte tetap muat dalam TEXT; string lain mengikuti kapasitas DATABASE. Ini batas penyimpanan, bukan kriteria persetujuan.
- Resource pengajuan berisi id,application_number,status,current_submission_id,enam field keuangan/tujuan,created_at,updated_at (ISO-8601 UTC). Tidak memuat customer_id, marker generated, storage_path, hasil model, checklist, atau notes internal. UI mengubah waktu ke WIB dan menampilkan uang tanpa konversi float.
- Semua route profil/pengajuan hanya untuk nasabah aktif. ID pengajuan milik orang lain menghasilkan 404, termasuk PATCH dengan body tidak valid. List selalu scoped ke pemilik; filter status tidak memperluas scope. CORS mengizinkan PATCH/DELETE untuk origin frontend yang tepat.
- Mutasi mengunci user dan memeriksa ulang is_active/role, lalu mengunci pengajuan bila ada. Create memakai lock user + unique active_customer_id. Delete memeriksa status draft, pointer, serta ketiadaan seluruh submission; cleanup dokumen masuk queue database pada koneksi transaksi yang sama sebelum commit. Detail pada DATABASE.md.

### Irisan dokumen nasabah yang sudah tersedia

- Empat route dokumen pada tabel di atas hanya untuk nasabah aktif. Jenis dokumen mengembalikan id,code,name,is_required,is_active (boolean); tidak ada endpoint pengubahan konfigurasi jenis. GET dokumen mengembalikan array versi terkini per jenis. Jenis nonaktif tetap dapat dibaca, tetapi tidak menerima unggahan baru.
- Resource versi berupa id,document_type_id,version_number,original_filename,mime_type,file_size_bytes,uploaded_at (UTC); tidak mengirim storage_path,file_hash,uploaded_by atau hasil/catatan internal. Tidak ada URL berkas publik. ID versi lama tetap dapat diunduh pemilik, termasuk saat pengajuan tidak dapat diedit.
- POST multipart memvalidasi kepemilikan/status sebelum field; milik nasabah lain 404, status terlarang 409 APPLICATION_NOT_EDITABLE. File maksimal 5.242.880 byte; PDF/JPG/JPEG/PNG harus cocok ekstensi dan MIME isi dari fileinfo; PDF diperiksa header, gambar diperiksa metadata gambar. Ini bukan pemeriksaan antivirus atau parsing seluruh isi PDF. Nama asli maksimal 255 karakter tanpa karakter kontrol; path penyimpanan dibuat server.
- Mutasi mengunci user → aplikasi → jenis → versi terbaru. Nomor versi dialokasikan dalam transaksi dengan unique index tetap aktif; audit dibuat atomik. File lama tidak diubah. Kegagalan transaksi setelah file ditulis menjadwalkan cleanup file baru hanya setelah memastikan tidak ada referensi metadata yang commit. Detail batas rekonsiliasi pada DATABASE.
- Unduhan melalui policy kepemilikan/scope internal, attachment, MIME server, Cache-Control no-store/private, dan X-Content-Type-Options nosniff. File hilang 404. Admin tetap 403. Reviewer hanya dapat mengunduh versi yang ditautkan ke snapshot pengajuan dalam tahapnya; versi kerja yang belum ditautkan dan pengajuan di luar scope 404.
- PHP bawaan Laragon teramati mengizinkan 2G; helper start-backend membatasi proses lokal menjadi upload_max_filesize=5M dan post_max_size=8M. Post melewati batas server menghasilkan 413 PAYLOAD_TOO_LARGE; file melewati batas upload PHP/validasi menghasilkan 422 pada field file. UI menangani keduanya tanpa mengulang unggahan otomatis.

Bagian internal mencakup antrean/baca/claim/release dan aksi verifikator; persetujuan tingkat 1/2 dan retry manual juga tersedia. Keberadaan tabel tidak membuktikan seluruh workflow selesai.

Upload satu file/request; PDF/JPG/JPEG/PNG max 5 MB, validasi MIME/isi + ekstensi. Nama storage dibuat server. Config PHP/webserver diselaraskan, 413 dinormalisasi jika memungkinkan. File versi lama dipertahankan. Download nasabah hanya miliknya; internal hanya verifikator/penyetuju pada tahap yang diizinkan. Admin tidak otomatis mendapat akses. Proteksi berlaku juga untuk dokumen lama yang terkait versi pengajuan tersebut.

Customer resource allowlist: data sendiri, status, waktu transisi, revision_reason. **Tidak ada** score,is_anomaly,anomaly_checks,document_checklist,catatan pemeriksaan/penyetuju/model atau status_history.notes mentah, termasuk nested resource. Alasan revisi berasal dari verification_results request_revision, bukan log umum. Status rejected ditampilkan tanpa membocorkan notes internal.

## Pengiriman atomik

Lock aplikasi, cocokkan expected_current_submission_id/status/kepemilikan, validasi lengkap, pilih model aktif, buat snapshot + tautan dokumen + anomaly_checks processing dengan deadline + update pointer/status + history dalam transaksi. Commit; dispatch job prediksi afterCommit. Pemanggilan FastAPI di luar transaksi. Jika dispatch gagal diketahui, catat dan set failed kondisional; jika proses mati scheduler memulihkan. Jangan melaporkan snapshot yang sudah commit seolah batal. Tidak ada auto-retry POST oleh browser; baca ulang status jika hasil jaringan tidak diketahui. Dua kirim bersamaan hanya satu commit, lainnya 409. Retry pemeriksaan mengikuti pola transaksi serupa, tidak membuat snapshot.

### Irisan pengiriman yang tersedia

- POST submissions hanya memakai **data kerja tersimpan**, bukan field keuangan/profil dari body. `expected_current_submission_id` wajib hadir: null pertama, integer positif ID lama saat kirim ulang. `confirmed` wajib literal JSON true. Milik nasabah lain 404 sebelum validasi field. Pointer berubah 409 SUBMISSION_VERSION_CONFLICT; status selain draft/revision_required 409 INVALID_STATUS_TRANSITION. Nasabah/internal/nonaktif mengikuti pengamanan route dan pemeriksaan ulang user dengan lock.
- Semua field profil (termasuk phone, workplace_name, work_duration_months; nol bulan diperbolehkan), tujuan, dan lima angka wajib lengkap sesuai validasi/kapasitas profil/draf. Jenis aktif/wajib harus memiliki versi terkini dengan berkas yang masih dapat dibaca; dokumen aktif opsional yang tersedia juga ditautkan. Error 422 SUBMISSION_INCOMPLETE memakai key `profile.<field>`, nama field keuangan/tujuan, dan `documents.<code>`.
- Tidak ada model aktif yang sesuai mode/metadata: 503 SERVICE_NOT_READY tanpa detail model pada respons nasabah; snapshot/check/history tidak dibuat. Stub-v0 hanya untuk mode stub local/testing. Demo/production atau mode model menolak model aktif stub-*. Tidak ada pemanggilan FastAPI selama lock transaksi atau fallback model otomatis.
- Berhasil 202 `{data:<snapshot nasabah>,message:...}`. Snapshot berisi id,version_number,customer_snapshot dengan allowlist field profil,enam field pinjaman/keuangan,submitted_at,documents dengan resource versi dokumen yang aman. Uang tetap string dua desimal; konversi float finite hanya untuk lima masukan internal ML, bukan penyimpanan/JSON uang publik. Tidak ada check/model/score/notes internal pada respons.
- GET submissions dipaginasi (default 20, maksimum 100), terbaru dahulu. Detail snapshot harus berada pada pengajuan yang sama; mismatch 404. GET status-histories dipaginasi serupa, berisi id,submission_id,from_status,to_status,changed_at,revision_reason. Alasan revisi **hanya** dari verification_results request_revision yang terkait; notes history/approval mentah tidak disalin.
- Setiap pengiriman membuat check attempt 1 processing dengan deadline 120 detik dan model dipin ke baris check. Callback afterCommit menaruh RunPrediction di database queue predictions. Kegagalan enqueue mempertahankan 202/snapshot commit, menandai check failed kondisional jika DB tersedia, atau membiarkan scheduler memulihkan deadline. Status bisnis tetap pending_verification.

## Internal

| Metode | Path | Fungsi |
|---|---|---|
| GET | /internal/applications | Daftar scoped peran; filter status, assignment=unassigned/mine/all |
| GET | /internal/application-summary | Jumlah per status sesuai scope |
| GET | /internal/applications/{id} | Detail + penugasan/riwayat |
| GET | /internal/applications/{id}/submissions/{submission_id} | Snapshot dan hasil internal |
| POST | /internal/applications/{id}/assignments | expected_current_submission_id; server menentukan stage/assigned_to; 201 |
| POST | /internal/assignments/{id}/release | reason; hanya pemilik tugas aktif; 200 |
| POST | /internal/submissions/{id}/verification-results | result_type note/request_revision/forward,notes,document_checklist; 201 |
| POST | /internal/submissions/{id}/approval-decisions | decision approved/rejected,notes; 201 |
| GET | /internal/submissions/{id}/anomaly-checks | Percobaan per snapshot |
| POST | /internal/submissions/{id}/anomaly-checks | Retry manual, verifikator pemegang tugas aktif/versi terkini/tahap verifikasi, hanya failed; 202 |

Untuk antrean verifikator status pending_verification; penyetuju1 pending_approval_1; penyetuju2 pending_approval_2. Filter tidak boleh memperluas scope peran. Penugasan server dicari berdasarkan pengajuan+versi+stage+pemanggil. Anomaly check server memilih satu succeeded pada snapshot. Simpan ID yang dipakai dalam konten tindakan meski tidak berasal dari body. Forward dan approval memerlukan succeeded. Forward dengan anomali wajib notes. Revision/rejected wajib alasan. Keputusan level2 memerlukan approved level1 pada snapshot yang sama. Note tidak mengakhiri tugas, terminal action mengakhirinya.

### Irisan antrean/claim yang tersedia

- Enam route baca/claim/release pertama pada tabel internal tersedia. Hanya tiga peran reviewer aktif; nasabah/admin 403. Pengajuan yang statusnya di luar tahap peran atau belum memiliki current_submission_id menghasilkan 404, termasuk detail/snapshot/claim. Tahap role tidak dapat diperluas oleh filter, ID, atau body.
- GET applications: assignment=unassigned/mine/all (default all), status opsional hanya status tahap peran, application_number opsional kecocokan nomor lengkap, page/per_page maksimal 100 (default 20). Filter nomor memakai parameter binding dan tidak mengubah scope. Summary menghitung total,unassigned,mine,assigned_to_others,by_status untuk **seluruh tahap peran**, tanpa mengikuti filter daftar; satu agregasi SQL menjaga konsistensi saat claim/release.
- Row berisi id,application_number,status,current_submission_id,version_number,customer_name,loan_amount,submitted_at,current_assignment. Nama/uang berasal dari snapshot terkini, bukan profil/keuangan kerja. Assignment memuat id,application_id,submission_id,assigned_to,assignee{id,name},stage,claimed_at,ended_at,end_type,end_reason. Tidak ada email/hash/session/token atau metadata storage/model mentah.
- Detail memuat current_submission (data snapshot/dokumen + hasil pemeriksaan internal), submissions (ringkasan versi), assignments (riwayat), dan status_histories (transisi/waktu). Ketiga daftar detail dipaginasi 10 item dengan submission_page,assignment_page,history_page. Snapshot detail harus milik pengajuan dalam scope yang sama. Waktu API UTC ISO-8601; uang string desimal.
- Reviewer satu tahap boleh membaca antrean/detail/arsip pada tahapnya sebelum claim, termasuk saat dipegang reviewer lain. Hanya pemilik tugas aktif dapat melakukan tindakan tugas. Unduh internal mensyaratkan versi ditautkan ke suatu snapshot **pengajuan dan jenis dokumen yang sama**; dokumen kerja belum dikirim tidak dapat diunduh. Scope dievaluasi pada status pengajuan saat request, sehingga peran tahap lama kehilangan akses setelah status berubah. Admin tidak memperoleh akses otomatis.
- POST assignments: expected_current_submission_id wajib integer JSON positif; stage dan assigned_to ditentukan server. Pointer berbeda 409 SUBMISSION_VERSION_CONFLICT, tugas sudah diambil 409 ASSIGNMENT_ALREADY_CLAIMED. Satu assignment aktif global tetap dijaga lock parent + unique index. Respons 201 assignment; tidak mengubah status bisnis.
- POST release: reason wajib teks tidak kosong maksimal 16000 karakter. Hanya pemilik, versi/stage masih terkini dan ended_at null; tugas lama/berakhir 409 ASSIGNMENT_NOT_ACTIVE, bukan pemilik 403. Baris lama ditutup released dengan ended_by/reason, tidak ditimpa assigned_to; claim berikutnya membuat baris baru. Respons 200 assignment tertutup. Claim/release menulis activity_logs atomik, sementara riwayat penugasan berada pada assignments; **tidak** membuat transisi status yang sama karena status bisnis tetap.
- Hasil internal pada snapshot memuat attempt/status/score/is_anomaly/model_version_code, waktu/deadline dan failure_reason teknis yang tersimpan. Tidak ada input_features, artifact_path/hash, parameters, atau kunci ML. Hasil tidak memberi keputusan pinjaman otomatis. Seluruh resource nasabah tetap terpisah dan tidak memperoleh hasil internal ini.

### Irisan aksi verifikator

POST verification-results tersedia untuk verifikator aktif pemilik tugas verifikasi pada snapshot terkini. Versi lama 409 SUBMISSION_VERSION_CONFLICT; bukan pemilik/tanpa tugas 403; di luar tahap 404. Actor/assignment/check dipilih server, field verifier_id/assignment_id/anomaly_check_id/status dari klien dilarang.

Body: result_type note/request_revision/forward, notes nullable maksimal 16000 karakter (trim), document_checklist wajib berupa map ID jenis dokumen snapshot ke boolean JSON (boleh kosong, maksimum 100 entri sebagai batas payload). Checklist mendokumentasikan peninjauan, tidak menambah syarat kelayakan/kelengkapan forward. Jenis di luar snapshot ditolak 422. Revision wajib alasan publik; forward wajib succeeded dan notes jika is_anomaly. Anomali tidak menolak pengajuan otomatis.

201 data berisi id,submission_id,result_type,application_status. Note menambah konten/audit tanpa menutup tugas atau menambah status history. Revision/forward menutup tugas completed dan mengubah status ke revision_required/pending_approval_1 bersama konten/history/audit dalam satu transaksi. Scope verifikator berhenti setelah transisi; baca ulang antrean menghapus detail lama. Tidak replay mutasi setelah respons tidak pasti.

Snapshot internal menambah verification_results berpaginasi 10 item (verification_page positif) termasuk id,verifier_id,result_type,notes,document_checklist,anomaly_check_id,created_at UTC. Resource nasabah tidak berubah: hanya alasan request_revision publik pada status-histories. Endpoint approval-decisions dan retry/read-attempt tersedia.

## Kode domain

| HTTP | code |
|---|---|
| 409 | ACTIVE_APPLICATION_EXISTS |
| 409 | APPLICATION_NOT_EDITABLE |
| 409 | DRAFT_ALREADY_SUBMITTED |
| 409 | SUBMISSION_VERSION_CONFLICT |
| 409 | ASSIGNMENT_ALREADY_CLAIMED |
| 409 | ASSIGNMENT_NOT_ACTIVE |
| 409 | INVALID_STATUS_TRANSITION |
| 409 | ANOMALY_CHECK_NOT_READY |
| 409 | ANOMALY_RETRY_NOT_ALLOWED |
| 422 | SUBMISSION_INCOMPLETE (errors per isian/jenis dokumen) |
| 422 | ANOMALY_REVIEW_NOTE_REQUIRED (errors.notes) |
| 422 | VALIDATION_ERROR |
| 413 | PAYLOAD_TOO_LARGE |
| 403 | ACCOUNT_INACTIVE / FORBIDDEN |

## Layanan prediksi

Kontrak lengkap pada ML_SERVICE.md; schema contoh fixtures/prediction-request.json dan response.json. Tidak ada endpoint callback publik untuk hasil prediksi. Job Laravel menerima respons HTTP, memanggil fungsi penyimpanan hasil internal secara kondisional. Uji hasil terlambat memanggil fungsi ini setelah failed, bukan mengandalkan HTTP timeout.

Administrasi detail disusun tahap 9. Tidak membuat endpoint penghapusan akun. Kebijakan edit role: selesaikan/lepas tugas aktif dahulu; perubahan role jangan mewariskan tugas tahap lama. Nonaktifkan akun, cabut sessions, lepas tugas, dan log dalam transaksi.


## Retry manual yang tersedia

GET /internal/submissions/{id}/anomaly-checks mengembalikan data array percobaan terbaru dahulu dengan allowlist yang sama seperti anomaly_checks pada snapshot internal (tanpa fitur mentah/artefak). Tiga reviewer dapat membaca snapshot terkini/arsip selama aplikasi ada pada tahap perannya; nasabah/admin ditolak.

POST path yang sama menerima body kosong. Hanya verifikator aktif pemilik tugas aktif tahap verification, snapshot URL harus pointer terkini. Versi lama 409 SUBMISSION_VERSION_CONFLICT, tanpa tugas/bukan pemilik 403, di luar scope 404. Semua field klien ditolak 422. Hanya riwayat dengan percobaan terakhir failed dan tanpa processing/succeeded dapat diulang; selain itu 409 ANOMALY_RETRY_NOT_ALLOWED. Deadline processing yang lewat tetap menunggu scheduler mengubahnya ke failed.

Retry memin model_version_id dan input_features percobaan sebelumnya, bukan profil kerja/model aktif terbaru. Model harus sesuai mode runtime (termasuk larangan stub demo/production), jika tidak 503 SERVICE_NOT_READY. Tidak memanggil readiness HTTP dalam transaksi. Ketersediaan model layanan diverifikasi saat job berjalan, tanpa fallback.

202 data: id,submission_id,attempt_number,status=processing, berarti percobaan diterima, bukan hasil final. Audit dan percobaan baru/deadline 120 detik commit bersama. Tidak membuat snapshot/history/status bisnis/penutupan tugas baru. Job predictions didispatch setelah commit. Dispatch gagal tetap 202, status aktual failed/dispatch_failed bila dapat disimpan, atau dipulihkan scheduler; baca ulang status. Tidak ada retry POST/HTTP otomatis.

## Persetujuan berjenjang yang tersedia

POST /internal/submissions/{id}/approval-decisions menerima decision approved/rejected dan notes nullable maksimal 16000 karakter; penolakan wajib alasan tidak kosong. Level/approver_id/assignment_id/anomaly_check_id/status dari klien dilarang. Server menentukan level dari peran, tugas dan check succeeded dari snapshot. Anomali tidak otomatis menolak pengajuan; tidak menambahkan syarat notes anomali baru pada penyetuju.

Hanya penyetuju aktif pemilik tugas aktif sesuai tahap/versi terkini. Peran lain/tanpa tugas/bukan pemilik 403; luar scope tahap 404; arsip 409 SUBMISSION_VERSION_CONFLICT. Tingkat 2 (setuju maupun tolak) wajib mempunyai approved tingkat 1 pada snapshot sama, selain itu 409 INVALID_STATUS_TRANSITION. Tanpa succeeded 409 ANOMALY_CHECK_NOT_READY. Penyetuju tidak meminta revisi. Duplikat yang sudah berpindah tahap 404, keputusan level yang telah ada pada tahap sama 409; unique per snapshot/level tetap aktif.

201 data: id,submission_id,level,decision,application_status. Setuju L1 → pending_approval_2; setuju L2 → approved; tolak L1/L2 → rejected final. Keputusan, status history, update status, completed tugas dan activity log commit atomik. Tidak ada snapshot baru atau perubahan dokumen/check. Scope peninjau tahap lama berhenti setelah transisi. Snapshot internal menambah approval_decisions terurut level (maksimal dua karena unique), berisi id,level,decision,notes,anomaly_check_id,decided_at UTC. Nasabah hanya melihat status/waktu; notes penolakan/keputusan tetap internal dan bukan revision_reason.
