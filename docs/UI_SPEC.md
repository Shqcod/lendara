# Acuan UI yang sudah disetujui

Wireframe berbentuk pratinjau pernah ditinjau pengguna; file visual asli tidak disertakan paket ini. Gunakan susunan berikut sebagai acuan implementasi, lalu cocokkan dengan gambar/wireframe final bila pengguna menambahkannya. Wireframe bukan kode frontend produksi.

## Identitas produk dan metadata

- Nama produk pada shell, halaman login/register, dan identitas aplikasi: **Lendara**.
- Judul halaman: `<Nama halaman> | Lendara`, misalnya `Masuk | Lendara` atau `Pengajuan Saya | Lendara`; judul bawaan aplikasi `Lendara`.
- Metadata deskripsi aplikasi: `Lendara adalah prototipe akademik pengajuan pinjaman dengan deteksi anomali dan persetujuan berjenjang.` Gunakan nama Lendara juga pada metadata berbagi atau manifest jika ditambahkan.
- Judul akademik tetap **Rancang Bangun Sistem Pengajuan Pinjaman Berbasis Web dengan Deteksi Anomali dan Persetujuan Berjenjang** pada laporan dan dokumentasi akademik.
- Frontend menyediakan masuk/daftar, status sesi, beranda akun, dan `/customer` untuk profil/draf nasabah. Profil, simpan draf parsial, daftar/filter/pagination, status aktif, konfirmasi hapus, dan pemulihan koneksi telah tersedia. Dokumen/pengiriman dan peninjauan internal masih tahap berikutnya; hasil uji aktual mengikuti PROGRESS. Tidak ada autentikasi SSR atau store data nasabah global server pada irisan ini.

## Halaman

- Login/register nasabah: nama/email/password/konfirmasi; registrasi tidak memilih peran. Login salah, akun nonaktif, sesi berakhir memiliki pesan jelas. Logout tersedia di shell.
- Nasabah dashboard: pengajuan aktif, status, tindakan berikut, perjalanan. Jika ada aktif jangan tampilkan tombol buat baru yang berfungsi; lanjutkan pengajuan/lihat detail.
- Profil: nama,NIK dummy,tanggal lahir,alamat,kontak,jenis/tempat/lama pekerjaan. Tidak mengubah identitas snapshot lama.
- Draf/pengajuan: empat langkah Identitas → Pinjaman/Keuangan → Dokumen → Tinjau. Simpan draf selama pengisian; draf belum dikirim dapat dihapus dengan konfirmasi. Required docs D01-D03; D04 opsional. Revisi tampilkan alasan, berkas diganti versi baru, kirim ulang membuat snapshot baru.
- Riwayat nasabah: daftar/filter status, detail snapshot, dokumen terkait, timeline, alasan revisi. Final read-only. Tidak ada panel anomali atau catatan internal.
- Internal sidebar: Antrean pengajuan, Tugas saya. Antrean per peran, filter status/penanggung jawab dan jumlah per status. Ambil tugas atomik; jika sudah diambil orang lain, tampilkan pemilik tanpa tindakan pemrosesan.
- Detail internal: nomor/nama/status/versi/pemilik; pilih versi terkini/arsip; data snapshot; dokumen per versi; panel anomali terpisah; catatan verifikasi/keputusan; riwayat status dan tugas. Versi lama read-only.
- Verifikasi: checklist dokumen, note, request revision, forward. Succeeded baru mengaktifkan forward. Flag anomali tetap boleh lanjut dengan notes wajib. Retry hanya failed dan pemilik tugas aktif.
- Anomali: belum diperiksa, processing, failed + alasan teknis aman, succeeded + skor/flag/model. Attempts ditampilkan terpisah dari versi. Tidak ada kata fraud confirmed. Timeout tidak mengubah status workflow.
- Penyetuju1: setuju dan teruskan / tolak. Penyetuju2: persetujuan akhir / tolak. Tidak ada tombol revisi. Notes penolakan wajib.
- Lepas tugas: konfirmasi + alasan; status workflow tetap. Tugas pindah ke antrean.
- Admin: daftar/tambah/ubah akun internal, satu peran; edit role saat tidak ada tugas aktif. Nonaktif konfirmasi + alasan + dampak pelepasan tugas/sesi; aktifkan kembali tidak mengembalikan tugas otomatis. Log pelaku/tindakan/objek/waktu/detail aman.

## Perilaku wajib

- Mobile sampai 320px tanpa kontrol penting terpotong; tabel reflow/scroll terlokalisasi bila perlu.
- Label input nyata, keyboard/fokus dapat digunakan, status tidak hanya warna, pesan error per field.
- Pending state selama simpan/unggah/claim; cegah klik ganda di UI tetapi backend tetap menjaga konflik.
- GET gagal: pesan + coba lagi. Mutasi jaringan tidak pasti: periksa status server sebelum mengulang, terutama submit/decision/claim.
- 401/419 arahkan login atau pemulihan CSRF sesuai kasus; jangan menghapus isian draf diam-diam. Jangan cache data nasabah lintas akun di SSR/store global.
- Backend adalah otoritas untuk semua izin/status, bukan tampilan tombol.

