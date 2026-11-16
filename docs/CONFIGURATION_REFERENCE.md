# Acuan konfigurasi lokal tanpa rahasia

Gunakan dokumen ini untuk menyiapkan konfigurasi pada komputer penerima. Tidak ada file environment yang harus diambil dari Git: `.env`, `.env.example`, `.env.testing.example`, `.env_example` dan variasinya diabaikan atas instruksi pengguna. Isi nilai rahasia langsung melalui sarana privat lokal; pertahankan file yang sudah ada.

## Backend operator

| Kunci | Nilai atau tujuan |
|---|---|
| APP_NAME / APP_ENV / APP_DEBUG | Lendara / local / false untuk demo |
| APP_KEY | Rahasia Laravel lokal. Pada instalasi baru buat sekali dengan `php artisan key:generate`; jangan mengganti key instalasi/backup lama sembarangan |
| APP_URL / FRONTEND_URL | `http://localhost:8000` / `http://localhost:5173` untuk development |
| DB_CONNECTION / DB_HOST / DB_PORT | mysql / 127.0.0.1 /3306 |
| DB_DATABASE | kkp_pinjaman; khusus `.env.testing` gunakan kkp_pinjaman_test |
| DB_USERNAME / DB_PASSWORD | Credential operator/migration lokal. Profil runtime memuat credential DPAPI tersendiri |
| DB_URL | Jangan isi URL koneksi yang menimpa pilihan DB/credential profil |
| SESSION_DRIVER / QUEUE_CONNECTION / CACHE_STORE | database / database / database pada runtime demo |
| SESSION_CONNECTION / DB_QUEUE_CONNECTION / DB_CACHE_CONNECTION / DB_CACHE_LOCK_CONNECTION | mysql pada profil runtime |
| SANCTUM_STATEFUL_DOMAINS | `localhost:5173,localhost:8000` |
| SESSION_DOMAIN / SESSION_SECURE_COOKIE | Domain kosong, false hanya untuk HTTP lokal; jangan mencampur host localhost dan127.0.0.1 |
| ML_MODE / ML_SERVICE_URL | model / `http://127.0.0.1:8001` pada profil demo |
| ML_INTERNAL_API_KEY | Rahasia yang sama dengan INTERNAL_API_KEY layanan model; tidak boleh dikirim ke frontend |
| ML_HTTP_TIMEOUT_SECONDS | Maksimal20 detik; deadline job dan sisa waktu dapat memperpendeknya |

Timezone Laravel/DB UTC; UI menampilkan WIB. Profil proses LOCAL_WINDOWS_DEMO menyelaraskan mode/model/driver tanpa menulis ulang `.env` lama. `LENDARA_RESTORE_ID` hanya digunakan profil restore dengan DB tetap yang cocok; jangan menetapkannya di terminal development.

## Frontend dan layanan model

Frontend development memerlukan `PUBLIC_API_BASE_URL=http://localhost:8000`. Hanya URL ini yang bersifat publik; jangan memberi awalan PUBLIC pada key/password. Aksi Frontend profil restore menetapkan URL8800 dan port5174 sendiri. Jalankan profil restore pada proses terminal baru agar tidak mewarisi konfigurasi environment lain.

Layanan model membaca INTERNAL_API_KEY dari environment proses atau konfigurasi lokal yang sudah disiapkan. `start-local-model.ps1` menetapkan ML_ENV=local, ML_MODE=model, registry/hash terpin dan artifact root; script tidak membuat `.env`. Pin registry yang berlaku ada pada LOCAL_WINDOWS_DEMO dan diverifikasi sebelum load. Artefak `.joblib` harus berasal dari sumber tepercaya dengan hash cocok, karena pemuatan joblib mengeksekusi format Python.

Jangan membuat `.env` kosong/berisi placeholder lalu menganggap konfigurasi selesai. Periksa key internal cocok tanpa mencetak nilainya, database efektif benar, artefak tersedia dan preflight lulus. Jangan menyalin credential DPAPI lintas komputer sebagai pengganti provisioning. Panduan pemulihan ada di [BACKUP_RESTORE](BACKUP_RESTORE.md) dan [DATABASE_RUNTIME](DATABASE_RUNTIME.md).
