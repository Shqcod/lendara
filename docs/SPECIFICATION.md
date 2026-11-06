# Spesifikasi bisnis v1.2

## Tujuan dan batas

Prototipe pengajuan pinjaman web dengan kelengkapan dokumen, persetujuan berjenjang, dan deteksi anomali Isolation Forest. Seluruh identitas, berkas, dan data keuangan fiktif. Bukan sistem perbankan produksi, pencairan uang, credit scoring, atau pendeteksi penipuan yang tervalidasi pada data nyata. Tidak ada OCR pada lingkup ini.

## Peran

| Kode | Kewenangan |
|---|---|
| nasabah | Daftar, login, profil, draf, unggah/ganti dokumen, kirim/kirim ulang, lihat status/riwayat sendiri |
| verifikator | Antrean verifikasi, ambil/lepas tugas, baca snapshot/dokumen/hasil anomali, catat verifikasi, minta revisi, teruskan, retry setelah gagal |
| penyetuju_1 | Antrean tingkat 1, ambil/lepas tugas, tinjau, setuju ke tingkat 2 atau tolak |
| penyetuju_2 | Antrean tingkat 2, ambil/lepas tugas, tinjau, setujui final atau tolak |
| admin | Akun/peran internal, nonaktifkan akun, baca log; tidak otomatis boleh membaca berkas nasabah |

Satu akun satu peran, tidak ada pivot peran. Akun tidak dihapus; nonaktifkan dan cabut sesi, is_active dicek pada semua permintaan autentikasi.

## Status alur

| Dari | Tindakan | Ke |
|---|---|---|
| draft | Nasabah kirim lengkap | pending_verification |
| pending_verification | Verifikator minta revisi | revision_required |
| revision_required | Nasabah kirim ulang | pending_verification |
| pending_verification | Verifikator forward | pending_approval_1 |
| pending_approval_1 | Penyetuju 1 setuju | pending_approval_2 |
| pending_approval_1 | Penyetuju 1 tolak | rejected |
| pending_approval_2 | Penyetuju 2 setuju | approved |
| pending_approval_2 | Penyetuju 2 tolak | rejected |

approved/rejected final. Semua status lain termasuk draft/revision_required aktif. Maksimal satu pengajuan aktif per nasabah. Penyetuju tidak meminta revisi atau mengembalikan pengajuan. Tidak ada penolakan langsung oleh verifikator pada lingkup ini.

## Versi dan pemeriksaan

- Setiap kirim/kirim ulang membuat snapshot immutable identitas/pekerjaan dan keuangan, serta tautan dokumen pada saat itu. Pembaruan profil tidak mengubah versi lama.
- Document upload menghasilkan versi baru; versi lama tetap ada. Dokumen tidak diganti dapat dipakai beberapa pengiriman.
- Dokumen wajib: D01 Identitas, D02 Bukti penghasilan, D03 Rekening koran. D04 Tambahan opsional. Bekukan konfigurasi jenis wajib selama demo/alur aktif.
- Data kerja dapat diubah hanya draft/revision_required. Model, verifikasi, dan keputusan mengambil data resmi dari snapshot.
- Setiap pengiriman atomik membuat percobaan pertama processing. Retry manual hanya setelah failed dan membuat attempt baru pada snapshot yang sama. Maksimal satu processing dan satu succeeded per snapshot.
- Pemrosesan model terpisah dari status alur. Belum ada percobaan berarti belum diperiksa (misalnya sebelum pengiriman); bukan status alur baru.
- Forward memerlukan hasil succeeded. is_anomaly=true tidak memblokir forward jika verifikator memberi catatan pemeriksaan. Model tidak memutuskan penolakan.

## Tugas dan transaksi

Antrean bersama untuk verifikator dan penyetuju tiap tingkat. Ambil atomik: satu penugasan aktif per pengajuan secara global. Tindakan hanya pemilik tugas aktif pada tahap/versi terkini. Pelepasan manual/nonaktif akun tidak mengubah status alur. Simpan alasan dan riwayat penugasan.

Tindakan penting menulis konten, perubahan status, akhir penugasan, dan status history dalam satu transaksi. Penonaktifan akun, pelepasan tugas, serta pencabutan sesi database dilakukan atomik pada koneksi DB yang sama; permintaan yang berlomba memeriksa ulang akun aktif dengan lock sebelum commit tindakan.

Hanya draf yang belum pernah dikirim dapat dihapus. Tidak ada status history saat draf dibuat; transisi pertama draft → pending_verification, from_status non-null. Penciptaan/penghapusan draf dicatat di activity_logs generik. Penghapusan row draf/dokumen dan pembersihan file mengikuti DATABASE.md.

## Privasi dan kualitas

Nasabah hanya menerima status, data sendiri, dokumen sendiri, dan alasan revisi publik. Jangan mengirim score/is_anomaly/checklist/catatan internal atau notes status history mentah. UI validasi per field, indikator proses, pesan jaringan dan coba lagi yang aman, mobile-friendly. Tidak mengulang mutasi otomatis setelah jaringan putus; baca ulang hasil/status dahulu.

## Prioritas

Wajib: alur pengajuan, snapshot, dokumen privat, akses/peran, penugasan, persetujuan berjenjang, pemeriksaan model, riwayat, nonaktif akun. Dapat disederhanakan: dashboard, UI admin tambahan. Dihilangkan dahulu jika mepet: Docker Compose, grafik dashboard dekoratif. Bukti laporan dikumpulkan setiap tahap.

