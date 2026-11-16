# Validasi generator preview — 15 November 2026

Awal repo bersih pada `1ec1d4a`, satu commit lokal lebih maju dari origin. Cakupan hanya generator offline, konfigurasi draft, pengujian dan dokumentasi. Kode runtime stub, backend/frontend, `.env`, DB, dependency dan lockfile tidak berubah.

## Hasil

- Dari `ml-service`: `.venv\Scripts\python.exe -m unittest discover -s tests -v`: **21 tes lulus dalam5.261s, exit0**. Delapan tes generator dan13 tes kontrak stub. Semua 10.000 baris preview divalidasi menggunakan schema masukan FastAPI. Group leakage, duplicate financial vectors, seed/config, injeksi, label/provenance, uang/tenor dan larangan overwrite diuji. Tidak ada kegagalan tes pada run ini.
- Warning StarletteDeprecationWarning: penggunaan httpx pada starlette.testclient deprecated, menyarankan httpx2. Warning dilaporkan; tidak mengganti dependency di irisan generator.
- Generator CLI dengan venv dan Python sistem, masing-masing Python3.12.4, membuat dua output baru di `ml-service/data/generated/generator-v1-preview-20261115-a` dan `-b`, keduanya exit0. Tidak memerlukan dependency tambahan, DB atau kunci internal.
- Ketiga CSV, config dan manifest **identik byte demi byte**; hash file manifest cocok. Versi preview `synthetic-finance-v1-8f98e8f34eaa1d10`. Ringkasan/hash/provenance aman di [generator-preview-2026-11-15.json](generator-preview-2026-11-15.json); baris CSV tidak dimasukkan Git.
- 5.000 kelompok, dua variasi: train7.000/validation1.500/test1.500, anomali700/150/150. Tidak ada kelompok lintas split atau vektor keuangan identik menurut audit. Ini jumlah yang dirancang, bukan prevalensi dunia nyata.
- Pemindaian13 kandidat berkas terhadap lima nilai sensitif lokal serta pola token/private key: tidak ada kecocokan, tanpa mencetak nilai. `git check-ignore` mengonfirmasi kedua direktori hasil generated dikecualikan. Pemeriksaan whitespace diff dilakukan sebelum commit.

## Batas

Distribusi, label injeksi dan protokol eksperimen masih **draft**, belum disetujui/dikunci. Belum fitting Isolation Forest, pin dependency training, seleksi model, hasil precision/recall/F1, metrik test, artefak model, registrasi/aktivasi DB atau readiness demo. Menghasilkan test.csv dan mengaudit hash/jumlah tidak mengevaluasi model pada test.

Tidak menjalankan ulang PHPUnit/MySQL, check/build frontend, Chrome atau smoke layanan HTTP karena runtime aplikasi tidak berubah. Bukti sebelumnya tetap milik sesinya. UI tidak berubah sehingga screenshot baru tidak diperlukan. Pengamanan grant/runtime, orphan crash, deployment/proxy dan timeout POSIX tetap pekerjaan terpisah.
