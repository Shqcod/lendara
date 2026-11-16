# Hasil eksperimen sintetis ML v1

Protokol beku: ml-protocol-v1-locked (b78f603). Evaluator951c041, selection b15c328/tag ml-config-v1-selected dibuat sebelum holdout dibuka. LOCK.json pada tag tetap dipertahankan, tidak diperbarui dari hasil test. Marker HOLDOUT_OPENED.json pada direktori dataset dibuat eksklusif sebelum pembacaan metrik test/borderline. Kedua CLI validation/holdout selesai exit0 tanpa retry.

## Pemilihan dan hasil

36 fit hanya pada7.000 train, selection memakai1.500 validation. Kedua varian memilih100 estimator/contamination0.15; primary B berdasarkan F1/precision/recall validation. AP dan ROC-AUC tidak digunakan untuk selection. Artefak demo B seed17 ditentukan sebelum holdout; metadata dibuat otomatis dari estimator/pipeline/data, belum didaftarkan atau diaktifkan pada DB.

| Set | Varian | Precision | Recall | F1 | AP | ROC-AUC |
|---|---|---:|---:|---:|---:|---:|
| validation | A | 0.4362 +/- 0.0153 | 0.7444 +/- 0.0269 | 0.5501 +/- 0.0191 | 0.4435 +/- 0.0300 | 0.9054 +/- 0.0095 |
| validation | B | 0.6141 +/- 0.0212 | 0.9644 +/- 0.0245 | 0.7503 +/- 0.0232 | 0.7564 +/- 0.0625 | 0.9753 +/- 0.0065 |
| borderline | A | 0.1683 +/- 0.0088 | 0.1817 +/- 0.0024 | 0.1747 +/- 0.0058 | 0.1903 +/- 0.0141 | 0.6763 +/- 0.0078 |
| borderline | B | 0.1967 +/- 0.0141 | 0.1383 +/- 0.0062 | 0.1624 +/- 0.0085 | 0.2617 +/- 0.0163 | 0.7976 +/- 0.0239 |
| test | A | 0.5636 +/- 0.0221 | 0.7489 +/- 0.0350 | 0.6430 +/- 0.0259 | 0.5452 +/- 0.0331 | 0.9318 +/- 0.0085 |
| test | B | 0.7060 +/- 0.0261 | 0.9400 +/- 0.0425 | 0.8063 +/- 0.0326 | 0.8525 +/- 0.0455 | 0.9849 +/- 0.0045 |

Mean dan std populasi tiga seed17/29/43, dataset sama. Std hanya variasi estimator, bukan interval ketidakpastian sampel. Confusion matrix, jumlah flag, recall/support per jenis, pita, jenis x pita dan tiap seed tersimpan di JSON lengkap; sel kosong recall null/N/A dan support <30 diberi label deskriptif.

## Interpretasi yang mempertahankan hasil

- B unggul pada test utama, tetapi borderline tetap sulit: recall B sekitar0.1383 meskipun ROC-AUC0.7976. Ini konsisten dengan keterbatasan threshold0 untuk kasus dekat normal, disertai kualitas ranking yang juga belum sempurna (AP0.2617), bukan alasan untuk retuning pada holdout.
- Pada borderline, B justru mempunyai F1 lebih rendah daripada A (0.1624 vs0.1747), walaupun AP/AUC lebih tinggi. Primary tetap B sesuai keputusan validation; tidak diganti sesudah melihat hasil.
- Contamination0.15 terpilih, bukan0.10. Semua prevalensi tetap sintetis10%; pilihan ini tidak membuktikan nilai yang cocok untuk data nyata.
- Pola recall A pada tiga jenis utama naik menurut pita pendapatan pada run ini. B lebih datar pada installment/loan, tetapi expense masih berubah menurut pita; hipotesis tidak sepenuhnya berlaku seragam. Contoh expense rendah: A0.2083/B0.7083, tinggi A/B1.0. Support rendah hanya8 baris/4 kelompok pada pita rendah dan1 baris/1 kelompok pada pita tinggi, sehingga ini deskriptif saja. Semua sel jenis x pita untuk ketiga jenis ini pada test utama berada di bawah30 baris anomali.
- Desain rasio memberi keuntungan representasi kepada B; normal berarti tidak diinjeksi, bukan sehat finansial. Hasil bukan akurasi fraud atau validasi kredit nyata. Test memiliki75 kelompok anomali, bukan150 pengamatan independen. Bootstrap tidak dilakukan.

## Bukti dan batas implementasi

- Suite Python penuh:36 tes lulus dalam18.724s, exit0. Tes baru memeriksa hash sebelum deserialisasi, arah skor/threshold, N/A, support29/30, batas pita, tie-break tanpa AP/AUC, larangan parse holdout pada training dan penolakan claim kedua.
- Laporan mentah aman: ml-validation-v1.json, ml-holdout-v1.json dan ml-model-v1-metadata.json. Artefak joblib enam model dipertahankan lokal di .tools/ml-selection-v1 dan diabaikan Git; diperlukan untuk integrasi berikutnya. Hash dipercaya melalui laporan/LOCK committed; jangan menerima joblib dari sumber tidak terpercaya.
- Tidak ada perubahan threshold/config/model sesudah tag selection. Protokol beku, data, dependency dan pipeline tidak diubah. Aturan LF untuk laporan JSON ditambahkan untuk menjaga hash saat checkout Windows; bukan perubahan isi laporan atau metrik.
- Runtime FastAPI masih stub. Model asli belum disajikan melalui API, readiness versi+hash dan registrasi DB belum diimplementasikan. Pengujian PHP/MySQL/frontend/Chrome tidak diulang karena kode web tidak berubah. Linux/PCNTL dan audit dependency tetap belum dibuktikan.
- Langkah berikutnya integrasi artefak B seed17 terpercaya dengan verifikasi hash, metadata immutable, mode model/non-stub dan smoke end-to-end; tidak membuka ulang holdout untuk tuning.

Dasar metrik: [average precision resmi](https://scikit-learn.org/stable/modules/generated/sklearn.metrics.average_precision_score.html), [ROC-AUC resmi](https://scikit-learn.org/stable/modules/generated/sklearn.metrics.roc_auc_score.html), [batas keamanan persistensi](https://scikit-learn.org/stable/model_persistence.html).
