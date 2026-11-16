# Persiapan backup dan restore Lendara

Backup belum dianggap selesai hanya karena file SQL atau arsip dapat dibuat. DB dan disk dokumen privat harus berasal dari keadaan konsisten yang sama, dan hasilnya harus dibuktikan dapat dipulihkan pada target terisolasi. Jangan overwrite DB development/test yang sudah berisi fixture atau file pengguna untuk mencoba restore.

## Audit sumber baca-saja

Dari root Windows lokal:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/local-demo.ps1 -Action Integrity
```

Profil memakai credential runtime DPAPI, tanpa membutuhkan FastAPI hidup. `documents:audit-integrity` membandingkan file_size_bytes dan SHA256 semua document_versions (termasuk versi lama) terhadap stream disk local privat. Metadata dibaca per100 baris, isi file per64KiB. Output hanya jumlah, tidak ada ID/path/nama/original_filename atau hash isi yang dapat ditautkan pada nasabah.

Exit0 berarti seluruh metadata yang teramati cocok dengan file saat dibaca. Exit1 berarti ada file unavailable, size/hash mismatch, atau audit tidak lengkap. Size/hash mismatch bisa terjadi pada file yang sama sehingga kedua counter tidak harus saling lepas. Kegagalan DB/disk atau path traversal/referensi keluar root menghasilkan pesan umum tanpa laporan parsial. Command tidak memperbaiki, menyalin, menghapus, menjadwalkan cleanup atau memperbarui metadata.

Audit bukan snapshot atomik: writer dapat menambah/mengubah keadaan sesudah pembacaan. Metadata tanpa file dapat juga berasal dari disk/backup yang belum terpasang; jangan menghapus metadata atau mengganti hash agar audit tampak lulus. Audit tidak menghitung file tanpa metadata; gunakan documents:audit-orphans terpisah (tetap bukan izin hapus).

## Paket backup yang perlu disiapkan operator

1. Jadwalkan maintenance dan hentikan seluruh writer terkait, upload, worker/scheduler yang dapat mengubah keadaan, serta restore/sinkronisasi lain. Pastikan transaksi selesai; sekadar menjalankan dua audit tidak membuktikan ini.
2. Verifikasi DB dan root disk berasal dari lingkungan yang dituju. Jalankan audit integritas dan orphan; investigasi mismatch sebelum membekukan salinan.
3. Simpan dump schema/data MySQL dan salinan seluruh disk privat pada tujuan baru yang dilindungi. Pakai akun operator terpisah; runtime tidak diberi DDL/akses migrations hanya agar dapat membuat dump. Credential operator harus melalui jalur privat/native prompt, bukan argv atau file repo.
4. Sertakan metadata/hash artefak model, registry tepercaya, lockfile dependency, versi aplikasi/commit, waktu UTC dan hash file backup pada manifest privat. File joblib perlu disalin dari sumber tepercaya karena tidak tersimpan Git. Jangan melatih ulang atau membuka holdout sebagai bagian restore.
5. Simpan konfigurasi rahasia melalui mekanisme rahasia yang sesuai di luar Git/log. Credential DPAPI lokal tidak dapat dipindahkan begitu saja ke akun/komputer Windows lain. Arsip DB dapat memuat identitas/password hash dan dokumen tetap privat walau demo fiktif; jangan men-stage atau mengirimkannya sebagai bukti publik.
6. Baru buka writer kembali setelah cakupan salinan dan manifest diverifikasi. Kebijakan retensi/enkripsi lokasi backup dan target restore harus ditetapkan operator; belum ada script backup/restore otomatis yang menjalankan langkah destruktif.

## Pembuktian restore berikutnya

Siapkan target kosong terisolasi yang nama/path-nya disetujui, tanpa menimpa kkp_pinjaman atau kkp_pinjaman_test yang sedang dipakai. Jangan menjalankan DROP/TRUNCATE/fresh/overwrite. Pilih akun operator restore tersendiri, pulihkan DB dan disk pasangan yang sama, validasi jumlah/constraint/timestamps/snapshot/tautan dokumen lalu audit integritas. Verifikasi artefak/hash/model pinned serta status queue sebelum worker diizinkan berjalan. Akun runtime target diprovision ulang, tidak memakai credential Windows sumber secara otomatis.

Setelah pemeriksaan data lulus, lakukan pengujian browser auth/CSRF/CORS dan alur model pada target, lalu simpan bukti aman. Tanpa langkah ini belum ada klaim restore terbukti. Hosting/HTTPS dan timeout PCNTL tetap bukan bagian bukti audit lokal.

## Bukti saat ini

## Exporter dan verifier lokal

Gunakan akun operator lokal pada terminal privat, sesudah seluruh writer/worker/scheduler berhenti:

```powershell
cd backend
php artisan system:backup-local-demo --writers-stopped
```

Command hanya menerima local/MySQL/kkp_pinjaman dan bentuk22 tabel InnoDB tanpa views/routines/events/triggers. Ia menolak job/failed_jobs/processing yang belum kosong dan audit integritas gagal. Ia mengambil READ lock hanya tabel database sumber (bukan global FLUSH TABLES), dengan batas tunggu metadata5 detik, sebelum dump/penyalinan. Lock dilepas di finally/penutupan koneksi. Flag maintenance adalah pernyataan operator, bukan deteksi otomatis semua proses/filesystem writer.

mysqldump8.0.30 lokal dipanggil lewat argv terstruktur, password hanya environment child MYSQL_PWD, tanpa file defaults/password argv/log. Command ini tidak boleh dijalankan dalam transcript/debug yang mencetak environment child. Dump tidak berisi CREATE DATABASE/USE atau DROP TABLE dari opsi exporter; tidak mengeksekusi dump terhadap sumber. Seluruh disk privat disalin (termasuk file tanpa metadata), file sumber tidak dihapus. Symlink/escape/rekursi folder ditolak dan tujuan selalu direktori UUID baru di .tools/local-backups, tanpa overwrite.

Paket privat berisi database.sql, private/, model/, release/, manifest.json dan penanda EXPORT_COMPLETE_NOT_RESTORE_VERIFIED. Manifest mencatat jumlah baris, constraint/index, hash/ukuran file, model dan release/dependensi, commit dan flag working tree dirty. Jika gagal, INCOMPLETE dan paket parsial tetap ada; jangan gunakan sebagai restore. Direktori .tools diabaikan Git; arsip tidak dienkripsi otomatis, jadi perlindungan lokasi operator tetap wajib. Tidak menyalin .env/credential operator. Jangan force-add atau menaruh paket pada public storage.

Exporter mengeluarkan backup_id dan manifest_sha256. Simpan pin dari keluaran exporter secara terpisah/tepercaya, lalu:

```powershell
php artisan system:verify-local-backup <backup_id> --manifest-sha256=<pin_exporter>
```

Verifier tidak terkoneksi DB atau menulis file. Ia memeriksa byte file yang terdaftar di manifest dan menolak path keluar paket, paket INCOMPLETE atau pin/hash/ukuran salah. Hash hanya berguna jika pin tepercaya; menghitung ulang pin dari paket yang sudah berubah bukan verifikasi asal-usul. File tambahan yang tidak terdaftar tidak diautentikasi; jangan memasukkannya ke restore. Keluaran hashes_verified bukan bukti SQL/constraint/restore/browser berhasil.

## Restore terisolasi yang disetujui pengguna

Pengguna memilih database baru **kkp_pinjaman_restore_test** dan folder uji baru. Command berikut memerlukan paket dengan source_tree_dirty=false dan inventaris constraint/index:

```powershell
php artisan system:restore-local-backup <backup_id> --manifest-sha256=<pin_exporter> --create-isolated-target
```

Guard memverifikasi sumber local/kkp_pinjaman dan paket/pin sebelum CREATE. Jika nama target sudah ada, proses berhenti tanpa overwrite, DROP/TRUNCATE/fresh. Folder selalu .tools/local-restores/UUID baru. SQL diimpor memakai akun sementara yang hanya punya hak pada target baru, bukan operator; password acak server hanya di environment child. Akun sementara dibersihkan sesudah validasi, database/folder dipertahankan untuk inspeksi. Gagal berarti target/paket parsial dipertahankan, bukan dihapus atau diulang menimpa.

Validasi mencakup jumlah baris semua tabel, constraint termasuk ENFORCED, index, tidak ada FK ke schema lain, audit integritas pada disk restore dan hash artefak model. Path artefak asli dipertahankan di artifact-root tanpa mengubah metadata model. Tidak menjalankan worker, login browser, refit atau holdout. Penanda DATA_RESTORED_BROWSER_NOT_VERIFIED menjelaskan batas tersebut. Akun runtime khusus target dan profil aplikasi terisolasi kini tersedia; ikuti [RESTORE_WINDOWS_DEMO](RESTORE_WINDOWS_DEMO.md). Credential runtime development tidak berlaku pada target; pembuktian browser tetap terpisah.

## Bukti ekspor dan batas saat implementasi

MySQL test dan disk fiktif membuktikan streaming file besar, file berubah dengan ukuran sama, truncation, missing file, path traversal dan kegagalan disk; file/job/metadata tetap. Satu kesalahan harness awal membandingkan identitas objek DB dikoreksi menjadi isi metadata. Audit development memakai runtime DPAPI mengembalikan0 dengan checked0 karena belum ada dokumen; itu bukan bukti backup berisi data atau pemulihan target.

Ekspor awal development berhasil22 tabel/1954 file privat, termasuk berkas tanpa referensi yang tetap dipertahankan; metadata dokumen sumber0. Verifier memeriksa1963 file terdaftar, working tree dirty=true; paket awal tidak dipakai restore. Hasil ekspor dari commit bersih dan restore aktual dicatat pada PROGRESS setelah operasinya selesai.

Hasil aktual berikutnya dari checkpoint9481d51 bersih:22 tabel/2253 file privat diekspor,2263 file manifest diverifikasi, lalu restore pada kkp_pinjaman_restore_test/folder UUID baru lulus counts/constraint/index/integritas/hash model. Akun import sementara dihapus, target dipertahankan; percobaan restore ulang ditolak tanpa overwrite. Probe CHECK/FK/unique dan DECIMAL pada target juga lulus dengan rollback baris fixture (counter auto-increment dapat bergeser). Counts sumber tidak berubah. Bukti aman validation/backup-restore-2026-11-16.json.

Sumber masih pengajuan0/dokumen bermetadata0, sehingga keberhasilan ini terbatas pada data/schema/model yang ada, bukan restore pengajuan lengkap. File tanpa referensi tetap dipertahankan. Runtime credential target, Chrome upload/submit/worker model dan persetujuan1/2 kini terbukti dengan fixture baru; ini belum membuktikan pengajuan lengkap dipulihkan dari backup. Jangan menjalankan profil development terhadap target restore atau menyebutnya deployment siap produksi.

## Paket pengajuan lengkap dari workspace restore

Dataset fiktif pada kkp_pinjaman_restore_test kini memiliki satu pengajuan approved, snapshot, tiga dokumen wajib dan keputusan1/2 dari workflow Chrome. Exporter dapat mencadangkan dataset ini tanpa menyalinnya ke development. Hentikan seluruh writer/worker/scheduler target, lalu gunakan terminal operator lokal baru (konfigurasi asal local/MySQL/kkp_pinjaman), bukan profil credential runtime:

```powershell
cd backend
php artisan system:backup-local-demo --restore-workspace=<UUID_workspace_restore> --writers-stopped
php artisan system:verify-local-backup <backup_id_baru> --manifest-sha256=<pin_exporter_baru>
```

Opsi restore-workspace mewajibkan workspace tervalidasi dan minimal satu pengajuan approved. Koneksi operator hanya dialihkan pada proses ini ke DB restore tetap; disk/artefak/release berasal dari folder workspace yang sama. Konfigurasi dipulihkan di finally. Operator perlu hak backup/READ LOCK pada sumber; runtime tetap tanpa DDL/LOCK/migrations. Seluruh paket tetap privat di .tools/local-backups/UUID baru, tanpa overwrite/.env/credential. Paket dari source tree dirty tetap tidak boleh dipulihkan; commit kode dahulu, lalu ekspor dari working tree bersih.

Manifest baru menyertakan logical_row_hashes per tabel: seluruh kolom/baris diurutkan menurut primary key dan di-stream per500 baris, dengan pembingkaian panjang byte serta penanda NULL. Perbedaan isi berukuran sama dan NULL vs string kosong terdeteksi. Digest tidak mengubah data; nilainya ada pada manifest privat, bukan bukti publik. Hash ini membandingkan isi logis melalui driver/schema yang sama, bukan byte InnoDB atau counter auto-increment; counts/constraint/index dan integritas file tetap diperiksa terpisah.

Restore kedua hanya pada nama tetap **kkp_pinjaman_recovery_drill** dan folder UUID baru, setelah pengguna menyetujui target tersebut. Jangan menjalankan perintah berikut sebelum persetujuan nama target. Existing target ditolak tanpa overwrite/DROP/TRUNCATE; kkp_pinjaman_restore_test dan development tetap dipertahankan:

```powershell
php artisan system:restore-local-backup <backup_id_baru> --manifest-sha256=<pin_exporter_baru> --create-isolated-target --recovery-drill
```

Mode ini menerima hanya paket sumber kkp_pinjaman_restore_test dengan source_restore_id valid, logical_row_hashes, snapshot, minimal tiga tautan dokumen dan dua keputusan. Restore membandingkan digest semua tabel, counts, constraint/index, file privat dan artefak; akun import sementara hanya memiliki hak target baru. Paket lama development tetap memakai alur awal tanpa recovery-drill. Paket lama tanpa digest hanya membuktikan pemeriksaan counts/schema/file yang tersedia saat ekspor, bukan kesamaan seluruh nilai.

Profil runtime/browser restore saat ini tetap khusus kkp_pinjaman_restore_test. Validasi data di recovery_drill belum berarti browser/worker pada database kedua sudah diuji. Marker DATA_RESTORED_BROWSER_NOT_VERIFIED dan browser_verified=false pada target baru harus dipertahankan sampai bukti terpisah tersedia. Jangan mengarahkan credential runtime lama atau mengubah .env agar seolah-olah target baru sudah siap.

## Hasil latihan dataset lengkap16 November2026

Pengguna menyetujui kkp_pinjaman_recovery_drill dan folder baru. Ekspor dari checkpoint378c295 bersih berhasil22 tabel/2256 file privat, verifier2266 file lulus. Restore baru memulihkan approved1/snapshot1/dokumen3/tautan3/keputusan2/check succeeded1, jobs/failed0. Hash seluruh isi22 tabel sumber dan target cocok dengan manifest, termasuk sesudah probe constraint/DECIMAL dengan rollback baris (auto-increment dapat bergeser). Constraint/index, file privat dan artefak model cocok. Akun import sementara0, development tetap, existing target menolak restore ulang. Evidence aman validation/populated-recovery-2026-11-16.json; paket/SQL/manifest privat tetap di .tools.

Ini bukti restore data pengajuan lengkap dan model, bukan aktivasi browser/worker pada database kedua. Profil restore-demo tetap mengarah ke kkp_pinjaman_restore_test; jangan mengganti .env atau memakai credential lama untuk recovery_drill. Marker browser belum diverifikasi tetap dipertahankan.
