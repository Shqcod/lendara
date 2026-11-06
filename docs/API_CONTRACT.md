# Kontrak API v1.1 — target implementasi

Endpoint FastAPI stub, auth/register/login/logout/me, profil nasabah, CRUD draf, serta jenis/unggah/daftar/unduh dokumen nasabah Laravel tersedia; pembuktian integrasinya mengikuti PROGRESS.md. Pengiriman snapshot, akses dokumen internal sesuai scope tahap, peninjauan internal, dan admin belum diimplementasikan. Prefix Laravel /api/v1. Cookie Sanctum, Accept application/json, credentials dan X-XSRF-TOKEN pada mutasi. Publik: CSRF/register/login; selebihnya auth + is_active + policy. Jangan menerima customer_id/aktor/role/assignment_id/status tujuan dari klien yang seharusnya ditentukan server.

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
- Unduhan melalui policy kepemilikan, attachment, MIME yang dideteksi server, Cache-Control no-store/private, dan X-Content-Type-Options nosniff. File hilang 404. Peran internal termasuk admin masih 403 pada irisan ini; izin internal sesuai tahap akan diimplementasikan bersama peninjauan, bukan diberikan otomatis.
- PHP bawaan Laragon teramati mengizinkan 2G; helper start-backend membatasi proses lokal menjadi upload_max_filesize=5M dan post_max_size=8M. Post melewati batas server menghasilkan 413 PAYLOAD_TOO_LARGE; file melewati batas upload PHP/validasi menghasilkan 422 pada field file. UI menangani keduanya tanpa mengulang unggahan otomatis.

Bagian pengiriman/internal berikut tetap merupakan kontrak tahap berikutnya. Keberadaan tabelnya tidak berarti endpoint atau alur tersebut sudah selesai.

Upload satu file/request; PDF/JPG/JPEG/PNG max 5 MB, validasi MIME/isi + ekstensi. Nama storage dibuat server. Config PHP/webserver diselaraskan, 413 dinormalisasi jika memungkinkan. File versi lama dipertahankan. Download nasabah hanya miliknya; internal hanya verifikator/penyetuju pada tahap yang diizinkan. Admin tidak otomatis mendapat akses. Proteksi berlaku juga untuk dokumen lama yang terkait versi pengajuan tersebut.

Customer resource allowlist: data sendiri, status, waktu transisi, revision_reason. **Tidak ada** score,is_anomaly,anomaly_checks,document_checklist,catatan pemeriksaan/penyetuju/model atau status_history.notes mentah, termasuk nested resource. Alasan revisi berasal dari verification_results request_revision, bukan log umum. Status rejected ditampilkan tanpa membocorkan notes internal.

## Pengiriman atomik

Lock aplikasi, cocokkan expected_current_submission_id/status/kepemilikan, validasi lengkap, pilih model aktif, buat snapshot + tautan dokumen + anomaly_checks processing dengan deadline + update pointer/status + history dalam transaksi. Commit; dispatch job prediksi afterCommit. Pemanggilan FastAPI di luar transaksi. Jika dispatch gagal diketahui, catat dan set failed kondisional; jika proses mati scheduler memulihkan. Jangan melaporkan snapshot yang sudah commit seolah batal. Tidak ada auto-retry POST oleh browser; baca ulang status jika hasil jaringan tidak diketahui. Dua kirim bersamaan hanya satu commit, lainnya 409. Retry pemeriksaan mengikuti pola transaksi serupa, tidak membuat snapshot.

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

