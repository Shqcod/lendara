# Uji penerimaan demo oleh operator

Status16 November2026: **skenario utama demo lokal selesai**. Operator menjalankan pengiriman, verifikasi/forward dan persetujuan1/2, lalu mengonfirmasi status Disetujui terlihat di nasabah. Pemeriksaan database read-only mengonfirmasi approved, history berurutan, keputusan1/2, satu snapshot/tiga dokumen, model asli succeeded dan tiada tugas aktif/job gagal. Audit tiga file privat lulus. Bukti tampilan nasabah adalah laporan operator, bukan screenshot agent. Kelima peran serta lima layanan tetap aktif di **http://localhost:5173**. Kredensial akun operator tidak dibaca atau dicatat agent.

Panduan berikut dapat digunakan untuk demonstrasi/pengulangan dengan data fiktif baru. Hasil ini bukan penerimaan formal perusahaan atau bukti bahwa seluruh cabang revisi/retry/tolak telah diuji operator pada sesi ini.

## Skenario utama

| Tahap | Tindakan di UI | Hasil yang diharapkan |
|---|---|---|
| Nasabah | Masuk, buka ruang nasabah, isi profil fiktif lengkap dan simpan | Profil tersimpan; jangan memakai NIK, alamat atau telepon nyata |
| Draf | Buat draf, isi keuangan dan tujuan, lalu simpan | Nilai tersimpan tetap setelah reload; pengajuan belum dikirim |
| Dokumen | Pilih D01/D02/D03 satu per satu dan unggah lampiran fiktif yang cocok; coba unduh | Ketiga dokumen wajib tersedia dan unduhan terbaca |
| Pengiriman | Buka **Tinjau dan lihat pengiriman**, pilih **Tinjau dan kirim**, cek konfirmasi lalu kirim | Status **Menunggu verifikasi**; snapshot/dokumen saat kirim menjadi tetap |
| Verifikator | Logout nasabah, login verifikator, cari nomor pengajuan, buka dan **Ambil tugas ini** | Snapshot/dokumen tersedia; pemeriksaan model akhirnya **Selesai** |
| Verifikasi | Tinjau dokumen/hasil model, isi catatan fiktif, lalu **Teruskan ke penyetuju tingkat 1** dan konfirmasi | Status **Persetujuan tingkat 1**; tugas verifikator berakhir |
| Penyetuju1 | Logout, login penyetuju1, cari/buka pengajuan, ambil tugas, pilih **Setuju dan teruskan ke tingkat 2** dan konfirmasi | Status **Persetujuan tingkat 2** |
| Penyetuju2 | Logout, login penyetuju2, cari/buka pengajuan, ambil tugas, pilih **Setujui pengajuan final** dan konfirmasi | Status **Disetujui** |
| Nasabah akhir | Login nasabah kembali, buka pengajuan dan riwayat | Status final terlihat; skor/flag anomali dan catatan internal tidak ditampilkan |

Nomor pengajuan dibuat sistem. Simpan nomor itu untuk pencarian internal; tidak perlu mengirim identitas akun atau password ke chat. Setiap pergantian peran gunakan logout/login atau profil browser terpisah agar sesi tidak tercampur.

Contoh keuangan untuk skenario ini:

| Isian | Nilai tanpa pemisah ribuan |
|---|---|
| Jumlah pinjaman | 20000000.01 |
| Pendapatan per bulan | 8000000 |
| Pengeluaran di luar cicilan | 3000000 |
| Cicilan berjalan | 1000000 |
| Tenor bulan | 12 |
| Tujuan | Demonstrasi akademik fiktif |

Angka tersebut hanya contoh input; jangan menganggap hasil model harus normal atau otomatis menentukan persetujuan. Bila anomali ditandai, verifikator tetap meninjaunya dan memberi catatan. Penyetuju mengambil keputusan manusia sesuai tahapnya.

## Lampiran lokal fiktif

Tiga PDF satu halaman disiapkan pada workspace saat ini di:

```text
Q:\lendara\.tools\operator-demo-documents\output\pdf\
  D01-identitas-fiktif.pdf
  D02-penghasilan-fiktif.pdf
  D03-rekening-fiktif.pdf
```

Seluruhnya bertanda **FIKTIF - BUKAN DOKUMEN ASLI**, tidak memakai identitas nyata, logo bank/perusahaan atau nomor rekening asli. File ini hanya contoh untuk uji unggah/unduh dan review, bukan dokumen pendukung pinjaman nyata. Letaknya di .tools yang ignored; tidak masuk Git dan tidak otomatis tersedia pada clone lain. Jangan mengganti dokumen pengguna yang sudah ada atau mengunggah file ini ke pengajuan lain tanpa memilihnya sendiri di UI.

## Bila ada kendala

- Setelah kirim, tunggu worker lalu gunakan tombol periksa data/status terbaru. Jika pemeriksaan gagal, jangan menghapus job/check atau mengubah DB; verifikator memakai retry resmi setelah membaca kegagalannya.
- Bila respons mutasi terputus, periksa sesi/data terbaru sebelum mengirim ulang. Hindari klik berulang pada kirim/forward/setuju.
- Jika sesi tidak sesuai akun, logout dahulu; jangan mengubah role akun sendiri. Admin tidak otomatis dapat melihat dokumen nasabah.
- Peran yang salah, tugas belum diambil atau tahap belum sesuai harus ditolak. Jangan memaksa loncatan tingkat atau memperluas grant untuk melanjutkan.
- Laporkan tahap, pesan yang muncul dan status terakhir. Jangan mengirim password, token, file .env atau screenshot form kredensial.

Hasil aktual skenario utama dan tanggalnya sudah dicatat di [bukti penerimaan lokal](validation/operator-acceptance-2026-11-16.json). Untuk pengulangan, catat hasil baru beserta tanggalnya. Checklist ini belum ditandatangani/diterima oleh perusahaan. Bukti otomatis DB test/restore tersedia di [HANDOVER](HANDOVER.md); hasil itu dipisahkan dari penerimaan dengan akun operator.
