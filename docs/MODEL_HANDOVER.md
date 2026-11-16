# Handover model aktif terkontrol

`php artisan ml:handover-model {version} --expected-active={versi-lama}` memindahkan penunjuk aktif ke model terlatih yang sudah terdaftar. Nilai `none` berarti operator mengharapkan tidak ada model aktif. Perintah menolak expected-active yang tidak sesuai keadaan terkini; tidak ada force, overwrite metadata, repin snapshot atau penghapusan pekerjaan.

## Prasyarat operasional

Ini prosedur maintenance, **bukan hot switch otomatis seluruh sistem**. Command tidak dapat mengubah konfigurasi proses Laravel lain atau menghentikan writer/worker secara global. Sebelum pergantian mode/runtime, operator harus:

1. Hentikan sementara penerimaan submit/resubmit/retry dan proses writer lain; hentikan worker/scheduler dengan prosedur operasional yang sesuai setelah pekerjaan berjalan selesai. Periksa queue dan processing, bukan sekadar tombol UI.
2. Untuk stub→model, drain pekerjaan stub memakai runtime stub yang sesuai dahulu; bila melewati deadline, jalankan pemulihan expiry yang sudah tersedia dan periksa status terminal. Jangan menghapus job/check atau mengubah status manual untuk melewati guard. Processing yang versinya tidak tersedia akan menolak handover, termasuk yang lewat deadline sampai scheduler menanganinya.
3. Siapkan registry/model runtime tepercaya. Seluruh versi **model asli yang terdaftar**, bukan hanya model tujuan, harus termuat dengan hash cocok untuk menjaga retry versi lama. Kebijakan konservatif ini belum menyediakan retirement versi; pengurangan registry memerlukan prosedur terpisah. Stub failed lama tidak menjadi model asli; retry stub setelah mode=model tetap ditolak oleh aturan mode lama, bukan dimigrasi/retrain otomatis.
4. Sinkronkan konfigurasi efektif mode=model pada proses Laravel yang akan dipakai, kunci internal dan URL runtime secara lokal. Restart proses lama agar tidak ada writer/worker dengan konfigurasi stale. Command tidak menulis .env atau mengganti konfigurasi lintas proses.
5. Jalankan handover dengan versi aktif yang sudah ditinjau. Nyalakan kembali writer/worker hanya setelah pemeriksaan hasil/readiness dan konfigurasi selesai. Jika prasyarat maintenance tidak dapat dipenuhi, jangan menjalankan prosedur ini sebagai pergantian live yang dianggap aman.

Bukti awal command handover berasal dari feature test MySQL transaksi rollback dan kemudian smoke DB test. Aktivasi pertama model development dilakukan melalui registrasi eksplisit pada sesi berikutnya; status terkini mengikuti PROGRESS. Prosedur maintenance lintas proses di atas belum dijalankan/dibuktikan pada deployment.

## Pengamanan implementasi

Readiness HTTP diambil sebelum transaksi/lock milik command, memerlukan ready=true, mode=model dan contract1. Named lock aktivasi sama dengan registrasi/stub, lalu seluruh row model dikunci berurutan ID, expected-active diperiksa ulang, target wajib trained IsolationForest dan artefak lokal/hash masih cocok. Semua model asli dan setiap versi yang dirujuk check processing harus ada pada readiness dengan hash sama. Snapshot readiness bukan jaminan layanan tidak berubah kemudian; konfigurasi runtime harus dijaga operator dan error tetap ditangani job/deadline.

Perubahan is_active lama→false, tujuan→true dan audit model.handover atomik, dengan retry deadlock maksimal3. Unique active_marker tetap. Hasil terminal, snapshot, attempt/model pin dan metadata lama tidak ditulis ulang. Pemanggilan dengan expected-active sama dengan target yang sudah aktif tidak menambah audit, sepanjang pengamanan readiness/processing tetap terpenuhi. Kegagalan audit mengembalikan seluruh perubahan.

## Bukti uji

ModelHandoverTest memeriksa atomic/idempotent switch, hasil lama tetap pinned, stale expected, readiness/hash/mode/artefak salah, processing tidak tersedia ditolak tanpa diubah, processing versi model yang termuat boleh berlanjut, dan rollback audit. Fixture mengisolasi status lama hanya di outer transaction DB test yang selalu rollback; bukan script pembersihan produksi. Target akhir4 tes/18 assertions lulus2.11s. Target awal3 lulus/1 gagal karena HTTP fake bersifat kumulatif dalam harness, diperbaiki dengan factory fake baru.

Readiness pada feature test adalah HTTP fake; ini bukan handover nyata ke Uvicorn, observasi dua proses handover yang berlomba, atau bukti submit browser dengan model aktif. Smoke queue→Uvicorn model nyata dari sesi sebelumnya tetap bukti terpisah. Hasil regresi penuh/Pint mengikuti PROGRESS. Protokol/LOCK selected dan holdout tidak berubah; tidak ada tuning atau evaluasi ulang.
