# Audit berkas privat tanpa referensi

`php artisan documents:audit-orphans` memindai prefix `loan-documents/` pada disk local privat dan membandingkan setiap path dengan `document_versions.storage_path`. Tautan snapshot mengacu pada document_versions melalui FK; semua versi yang masih memiliki metadata dipertahankan, termasuk versi lama yang tidak lagi tampil sebagai versi terkini.

Command hanya membaca. Tidak menghapus/memindahkan berkas, menulis metadata, atau menjadwalkan job cleanup. Output hanya jumlah: scanned, referenced, within_grace dan unreferenced_observations, tanpa nama/path/isi berkas atau identitas nasabah. Kegagalan DB/disk mengembalikan exit1 tanpa laporan parsial. Opsi grace tidak valid menghasilkan exit2.

Masa tenggang default/minimum 24 jam, maksimum 8760 jam. Contoh memperpanjangnya:

```powershell
cd backend
php artisan documents:audit-orphans --grace-hours=48
```

Berkas tepat pada batas tenggang tetap dilindungi. Kandidat lama diperiksa referensinya kembali sebelum dihitung. Ini bukan snapshot atomik filesystem/database: transaksi belum commit, salinan backup yang sedang dipulihkan, dan perubahan sesudah audit masih mungkin. Jumlah kandidat **bukan persetujuan penghapusan**. Audit belum memeriksa metadata yang kehilangan berkas atau verifikasi hash isi.

Tidak ada opsi delete/apply, endpoint publik, atau jadwal otomatis. Jalankan sebagai operator CLI dengan akses baca DB/disk privat. Pemindaian memuat daftar berkas ke memori dan melakukan query per berkas; gunakan di luar jam sibuk pada prototipe, ukur sebelum memakai untuk volume besar. Symlink tidak perlu diikuti; adapter local harus tetap menolak symlink sesuai konfigurasi bawaan. Kegagalan enumerasi/metadata berkas menghentikan laporan.

Pengujian memakai MySQL kkp_pinjaman_test dan disk fiktif: versi direferensikan tetap, kandidat tua dan berkas baru tetap, berkas di luar prefix tidak dihitung, batas tenggang dilindungi, opsi invalid ditolak, DB gagal tidak disimpulkan sebagai orphan. Jumlah job dan metadata tidak berubah.

Bukti interupsi write/commit dan visibilitas koneksi berbeda tersedia di bawah. Tahap berikutnya tetap crash pada kompensasi/enqueue cleanup serta rancangan pemindaian berulang, penghentian writer, pengecekan ulang seluruh referensi dan prosedur operator sebelum menambahkan kemampuan hapus. Jangan menggunakan daftar audit lama sebagai input penghapusan atau menyimpulkan rollback hanya dari putusnya koneksi.

## Bukti interupsi upload dan transaksi

UploadCrashAuditTest menjalankan CustomerDocuments asli di proses PHP anak dengan MySQL kkp_pinjaman_test dan direktori disk fiktif khusus UUID. Observer test pada creating DocumentVersion dan listener INSERT menjadi barrier; tidak menambah hook crash pada kode produksi. Parent memastikan connection ID berbeda dan level transaksi sesuai, menjalankan audit selagi anak tertahan, lalu menghentikan hanya anak milik run dengan Process::stop(0).

| Kondisi | Metadata setelah interupsi | Berkas/audit |
|---|---|---|
| File sudah ditulis, metadata belum INSERT | Tidak ada | File tetap; file baru dalam tenggang, file dituakan menjadi observasi |
| INSERT sudah terjadi, belum commit, lalu proses dihentikan | Tidak terlihat pada observer | File tetap; tanpa job kompensasi karena proses tidak melewati catch |
| INSERT belum commit, lalu dilepas hingga commit sebelum dihentikan | Ada | Sebelum commit dapat terlihat tidak direferensikan; setelah commit menjadi referenced |
| Upload selesai commit, proses dihentikan sebelum barrier akhir dilepas | Ada | Tetap referenced, file tidak dibersihkan |

File dituakan dua hari dengan mtime pada disk fiktif untuk mensimulasikan transaksi sangat lama/restorasi backup; bukan menunggu dua hari atau mengubah file pengguna. Setiap mode membuktikan file tetap dan jumlah job tidak bertambah. Fixture MySQL dan file fiktif sengaja dipertahankan. Hasil awal:1 tes/57 assertions lulus; bukti akhir lihat PROGRESS dan validation/upload-crash-audit-2026-11-15.txt.

**Transaksi belum commit yang sudah tua adalah contoh nyata mengapa audit tidak boleh dipakai sebagai daftar hapus.** Pengujian ini membuktikan pengamatan dan interupsi proses upload di Windows; bukan timeout worker PCNTL, kehilangan acknowledgment COMMIT pada jaringan, crash saat kompensasi/enqueue cleanup, atau pemulihan orphan otomatis.

## Prosedur operator sebelum rekonsiliasi destruktif dibuat

1. Pastikan DB, disk privat, dan backup berasal dari lingkungan yang sama; jangan campur DB test/development/deployment.
2. Jalankan audit baca-saja, simpan ringkasan jumlah tanpa identitas/path. Exit gagal berarti laporan tidak lengkap, bukan nihil referensi.
3. Jika ada kandidat, jangan menjalankan job cleanup berdasarkan jumlah tersebut. Tinjau insiden upload, restore backup dan proses writer yang mungkin masih berjalan.
4. Siapkan jendela maintenance yang menghentikan seluruh writer upload/restore dan pastikan transaksi terkait selesai; dua audit berurutan saja tidak membuktikannya.
5. Rancangan kemampuan hapus harus menampilkan target spesifik secara privat, memeriksa referensi ulang pada lingkungan yang sudah tenang, mempertahankan bukti/backup dan menangani perubahan file. Saat ini belum ada kemampuan tersebut; jangan membuat daftar hapus dari observasi lama.
6. Setelah maintenance baca-saja, buka kembali writer sesuai prosedur operator. Bukti penjadwalan cleanup dijelaskan di bawah; crash saat eksekusi multi-file/ack worker dan bukti lingkungan deployment tetap tugas terpisah.

## Bukti crash penjadwalan cleanup

CleanupCrashTest menjalankan service upload/deleteDraft asli dalam proses anak dan MySQL terpisah, dengan disk fiktif per UUID. Kegagalan metadata upload diinjeksi lewat event model khusus proses test setelah file ditulis; tidak ada hook pada produksi. Barrier payload queue/INSERT jobs/akhir delete memisahkan empat kondisi:

| Titik crash | Hasil pada koneksi observer | Pemulihan |
|---|---|---|
| Kompensasi upload, sebelum INSERT jobs | File tanpa metadata, tidak ada job | Orphan tetap; audit baca-saja, perlu rekonsiliasi operator |
| Kompensasi upload, sesudah INSERT jobs autocommit | Satu job durable, file tetap sebelum worker | Worker database queue nyata memproses job milik run |
| Delete draf, INSERT jobs masih dalam transaksi | Job tidak terlihat; setelah crash draf/metadata tetap | Lock parent dapat diambil ulang; file tetap, tidak ada cleanup |
| Delete draf, setelah commit | Draf/metadata sudah dihapus, satu job durable, file tetap sebelum worker | Worker nyata menyelesaikan cleanup setelah proses pemanggil mati |

Parent memverifikasi connection ID berbeda dan level transaksi0/1, menghentikan hanya proses anak milik run, lalu memeriksa job/data/file. Job durable yang tepat dipindah ke queue unik test tanpa mengubah payload agar worker tidak mengonsumsi pekerjaan test lain. Worker memakai root disk UUID yang sama; hanya berkas fiktif hasil fixture boleh dihapus. Job hilang setelah berhasil diproses, berkas fixture tidak ada lagi. Ini bukan penghapusan orphan massal atau penerapan konfigurasi development.

Hasil awal1 tes/44 assertions/7.18s lulus. Bukti gabungan dan perintah akhir di PROGRESS serta validation/cleanup-crash-2026-11-15.txt. Jendela crash sebelum enqueue kompensasi memang masih menyisakan orphan; pengujian tidak menghilangkan keterbatasan tersebut. Lost COMMIT acknowledgment pada jaringan, crash di tengah delete file multi-path/ack worker, timeout PCNTL dan rekonsiliasi destruktif tetap terpisah/belum terbukti. Bukti Windows ini hanya penghentian proses milik test, bukan worker POSIX.
