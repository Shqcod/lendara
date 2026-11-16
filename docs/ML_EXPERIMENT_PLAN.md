# Rancangan eksperimen ML v1 — revisi 2

Status **DISETUJUI DAN DIKUNCI — 15 November 2026**. Persetujuan pengguna mencakup penyempurnaan hipotesis, ekspektasi borderline dan ambang support rendah di bawah; implementasi serta hasil aktual dicatat terpisah di PROGRESS. Dokumen ini merinci penerapan SPECIFICATION, ML_SERVICE dan keputusan D12–D13; angka di bawah adalah usulan eksperimen, bukan kebijakan kelayakan pinjaman. Belum ada model terlatih atau hasil evaluasi.

Revisi final mengikuti persetujuan bersyarat pengguna dan memasukkan seluruh syarat sebelum hash dihitung. Preview `synthetic-finance-v1-8f98e8f34eaa1d10` hanya bukti historis draf pertama dan tidak berlaku sebagai dataset eksperimen ini. Hash protokol dihitung dari blob Git yang telah di-commit; status implementasi generator, dependency dan pipeline mengikuti PROGRESS, bukan perubahan pada dokumen beku ini.

## Dataset yang dapat ditinjau

Implementasi offline: `ml-service/experiments/generate.py`. Konfigurasi: `ml-service/experiments/generator-v1.draft.json`. Hanya pustaka standar Python; tidak memakai database, identitas, dokumen, jaringan atau `.env`. Generator tidak diimpor oleh endpoint prediksi. Kontrak lima kolom dan kompatibilitas setiap baris dengan validator FastAPI diperiksa oleh tes.

| Parameter | Usulan v1 |
|---|---|
| Kelompok pelanggan sintetis | 5.000 |
| Variasi per kelompok | 2, total 10.000 baris |
| Split kelompok | Train 70%, validation 15%, test 15% |
| Seed data / split / injeksi | 20261115 / 20261116 / 20261117 |
| Kelompok berlabel anomali | 10% pada masing-masing split; kedua variasi berlabel sama |
| Profil/NIK/dokumen | Tidak dibuat; ID kelompok `syn-{seed}-{urutan}` hanya penanda sintetis |

Kelompok diacak terlebih dahulu dan dibagi ke tiga split. Kedua variasi pelanggan selalu mengikuti kelompoknya. RNG per kelompok terpisah dari RNG split/injeksi; perubahan seed dapat ditelusuri tanpa memakai status pinjaman. Setelah split, urutan kelompok injeksi diacak independen pada tiap split. Empat jenis injeksi dibagi bergiliran sehingga jumlahnya seimbang sejauh pembulatan jumlah kelompok memungkinkan. Baris dalam setiap split diacak lagi; ID dan label bukan fitur model.

| Split | Kelompok | Baris | Normal | Anomali |
|---|---:|---:|---:|---:|
| Train | 3.500 | 7.000 | 6.300 | 700 |
| Validation | 750 | 1.500 | 1.350 | 150 |
| Test | 750 | 1.500 | 1.350 | 150 |

Jumlah baris tiap jenis anomali pada train: 176/176/174/174 sesuai urutan jenis di bawah; validation dan test masing-masing 38/38/38/36. Komposisi ini ditetapkan generator, bukan estimasi prevalensi dunia nyata dan bukan parameter contamination yang otomatis dipaksakan kepada model.

## Distribusi dan korelasi

Semua rentang berikut merupakan asumsi simulasi. Belum dikalibrasi terhadap data perusahaan atau populasi Indonesia; istilah normal berarti tidak diinjeksi oleh generator, bukan layak kredit.

- Pendapatan dasar kelompok: campuran bobot 20% rentang Rp3–6 juta, 70% Rp6–20 juta, dan 10% Rp20–50 juta, uniform diskret dalam ribuan rupiah pada tiap rentang. Pemilihan band dilakukan probabilistik, bukan kuota pasti. Variasi pendapatan ±5%, dibatasi Rp3–50 juta.
- Rasio dasar pengeluaran 35–75% pendapatan, cicilan yang sudah ada 0–25% pendapatan, jumlah pinjaman 2–18 kali pendapatan bulanan. Rasio dipilih uniform diskret dalam basis point, kemudian setiap variasi diberi perubahan ±5% dan dibatasi ke rentang normal tersebut. Pengeluaran tidak mencakup cicilan. Uang dihitung integer sen, dibulatkan ke bawah saat perkalian, lalu ditulis string dua desimal.
- Tenor berkorelasi dengan rasio pinjaman/pendapatan: rasio >12 memakai pilihan 36/48/60 bulan; >6 sampai12 memakai18/24/36; sisanya6/12/18, dipilih uniform. Ini korelasi generator, bukan aturan endpoint atau kebijakan perusahaan.
- Sebagian pendapatan tinggi/rendah tetap berlabel normal. Tidak ada fitur identitas, angka penentu fraud, status persetujuan, atau label yang digunakan sebagai masukan.

| Jenis injeksi | Perubahan pada sampel dasar |
|---|---|
| `expense_pressure` | Pengeluaran 140–240% pendapatan |
| `installment_pressure` | Cicilan yang sudah ada 90–180% pendapatan |
| `loan_mismatch` | Pinjaman 60–100 kali pendapatan bulanan |
| `low_expense_large_loan` | Pengeluaran 1–5% pendapatan sekaligus pinjaman 24–40 kali pendapatan |

Injeksi diterapkan pada kedua variasi kelompok dengan besaran acak masing-masing; tenor mengikuti rasio pinjaman sesudah injeksi. Seluruh sampel tetap valid secara teknis: pendapatan/pinjaman positif, pengeluaran/cicilan nonnegatif, tenor integer positif. Data tidak valid (nol pendapatan, NaN, tipe salah) diuji sebagai validasi kontrak, bukan dicampur menjadi anomali pelatihan.

Jenis injeksi dibuat cukup jelas untuk pembuktian prototipe. Metrik dapat terlihat tinggi karena model diuji terhadap distribusi/aturan sintetis yang sama; ini tidak membuktikan kemampuan mendeteksi penipuan, generalisasi pada data nyata atau semua jenis anomali. Perbandingan varian B tidak diasumsikan selalu lebih baik.

## Ekspektasi sebelum hasil diketahui

Generator mendefinisikan injeksi melalui rasio yang secara eksplisit diberikan kepada B. Perbandingan ini menguji representasi fitur untuk desain sintetis ini, bukan keunggulan umum pada data perusahaan. Hipotesis sebelum hasil: pada expense_pressure, installment_pressure dan loan_mismatch, recall A diperkirakan naik dari pita pendapatan rendah ke tinggi, sedangkan recall B relatif lebih datar antar pita. Selisih recall B minus A diperkirakan paling besar pada pita rendah. Nilai nominal injeksi pada pendapatan rendah dapat bertumpang tindih dengan normal berpendapatan tinggi; pada pita tinggi nilai nominalnya sendiri sudah ekstrem sehingga A dapat terbantu. Laporkan recall dan selisih tiap pita untuk menilai pola ini secara deskriptif, tanpa uji signifikansi tambahan setelah hasil diketahui. Hipotesis boleh gagal; jangan menjamin kemenangan B atau mengganti ekspektasi setelah hasil keluar.

Contamination terpilih dapat mencerminkan prevalensi sintetis 10%; nilai0.10 bukan bukti rekomendasi untuk data nyata dan tidak dijamin menang. Ulangi pada laporan bahwa normal hanya berarti tidak diinjeksi: pengeluaran75% ditambah cicilan25% bisa menghabiskan pendapatan sebelum pinjaman baru.

## Evaluasi borderline terpisah

Set tambahan ini **hanya untuk pelaporan**, bukan train, validation, pemilihan model/seed/threshold, atau pengganti test utama. Tidak memakai opsi menahan satu jenis injeksi dari train pada eksperimen ini.

| Parameter | Ketetapan rancangan revisi 2 |
|---|---|
| Kelompok baru | 1.000, namespace ID `borderline-{seed}-{urutan}` |
| Variasi per kelompok | 2; total2.000 baris |
| Normal / borderline | 900/100 kelompok; 1.800/200 baris, prevalensi10% |
| Seed data / injeksi / pengacakan baris | 20261118 / 20261119 / 20261120 |
| Kelompok lintas dataset | Wajib disjoint dari train/validation/test, termasuk seluruh variasinya |
| Distribusi normal | Sama dengan normal utama: campuran pendapatan20/70/10 dan korelasi/variasi yang sudah dijelaskan |
| Pembagian jenis borderline | 34 kelompok expense,33 installment,33 loan; masing-masing68/66/66 baris |

Gunakan RNG data per kelompok dan RNG injeksi/pengacakan baris terpisah seperti pola dataset utama. Pilih100 kelompok injeksi melalui pengacakan independen, lalu alokasikan bergiliran dalam urutan expense/installment/loan. Kedua variasi mendapat jenis yang sama dengan besaran acak masing-masing. Jangan memaksakan kuota pita pendapatan setelah melihat hasil; laporkan support aktual. Namespace dan sumber kelompok baru harus dijamin oleh generator serta audit global, bukan hanya mengganti ID baris lama.

| Jenis borderline | Rentang inklusif terhadap pendapatan | Jarak dari batas normal |
|---|---|---|
| `borderline_expense_pressure` | Pengeluaran80–100% | Normal maksimal75%, selisih minimal5 poin persentase |
| `borderline_installment_pressure` | Cicilan30–50% | Normal maksimal25%, selisih minimal5 poin persentase |
| `borderline_loan_mismatch` | Pinjaman19–30 kali | Normal maksimal18 kali, selisih minimal1 kali |

Besaran injeksi diambil uniform diskret dalam basis point; fitur lain mengikuti sampel dasar normal dan tenor dihitung dari rasio pinjaman sesudah injeksi. Pembulatan uang tetap integer sen seperti dataset utama. Rentang ini lebih dekat ke normal daripada injeksi utama, dengan jarak kecil yang ditetapkan sebelum kunci. Kedekatan dan tumpang tindih nominal lintas pendapatan menjadi keterbatasan empiris; tidak mengasumsikan skor sempurna atau menetapkan batas atas metrik secara teoretis.

Set borderline berisi kedua kelas agar precision/AP/ROC-AUC dapat dihitung. Audit duplikat vektor keuangan, kelompok dan ID harus mencakup gabungan seluruh dataset utama dan borderline; gagal audit tidak boleh memicu deduplikasi diam-diam. Buka metrik borderline **bersamaan dengan test utama**, dalam satu run evaluasi yang sudah ditetapkan setelah konfigurasi terkunci. Gunakan model A/B dan tiga seed yang sama, yang hanya fit pada train; tidak ada fit atau tuning menggunakan borderline. Laporkan hasil terpisah, tidak digabung dengan test utama atau dipakai mengubah pemilihan.

Ekspektasi borderline sebelum hasil: recall pada ambang0 diperkirakan jauh lebih rendah daripada test utama untuk kedua varian, karena threshold ditetapkan oleh contamination pada train dan dapat lebih sensitif pada kasus ekstrem. ROC-AUC dapat tetap menunjukkan pemeringkatan yang baik meskipun recall rendah. Jika pola ini muncul, diskusikan ketidaksesuaian ambang dengan kasus borderline, bukan langsung menyimpulkan peringkat skornya buruk; jika AUC juga buruk, masalah tidak cukup dijelaskan oleh ambang saja. Jangan membandingkan besaran recall dan AUC sebagai metrik yang setara atau mengubah threshold dari borderline.

## Pita pendapatan dan interpretasi ketidakpastian

Pita pelaporan memakai pendapatan final setiap baris sesudah variasi, bukan band laten pembangkitan:

| Pita | Batas rupiah |
|---|---|
| Rendah | 3.000.000 <= pendapatan < 6.000.000 |
| Menengah | 6.000.000 <= pendapatan < 20.000.000 |
| Tinggi | 20.000.000 <= pendapatan <= 50.000.000 |

Batas tidak tumpang tindih: tepat6 juta masuk menengah, tepat20 juta masuk tinggi. Pada validation, test utama dan borderline, laporkan recall per jenis injeksi, per pita, serta tabulasi jenis×pita. Sertakan TP/FN, jumlah baris anomali, jumlah kelompok anomali dan jumlah baris total pada setiap sel; jumlah kecil ditampilkan apa adanya, bukan disembunyikan atau digabung setelah hasil diketahui. Jika tidak ada anomali pada sel, recall **N/A** beserta support0, bukan nol atau sempurna.

Aturan tetap: setiap sel dengan **kurang dari30 baris anomali** diberi label **support rendah, deskriptif saja**, termasuk sel kosong yang tetap recall N/A. Pada tepat30 baris atau lebih, label ini tidak dipasang; support baris dan kelompok tetap ditampilkan dan ini bukan jaminan independensi atau ketepatan estimasi. Tidak menyembunyikan atau menggabungkan sel kecil setelah hasil diketahui.

Std dari seed17/29/43 pada data tetap hanya menunjukkan variasi estimator, bukan ketidakpastian sampel/populasi. Test utama memiliki75 kelompok anomali, bukan150 observasi anomali independen; variasi satu kelompok berkorelasi. Bootstrap per kelompok tetap opsional dan tidak dimasukkan ke run terkunci dasar ini. Jika ditambahkan, spesifikasinya harus dicatat sebelum pembukaan test, hanya menambah interval pelaporan dan tidak mengubah pemilihan; jangan menentukan caranya sesudah melihat skor test.

## Integritas dan pengulangan

CSV berisi tepat `row_id`, `customer_group_id`, lima fitur baku, `is_anomaly`, `anomaly_kind`. Audit menolak kelompok lintas split, vektor keuangan identik di seluruh dataset, ID variasi duplikat, komposisi label salah dan uang/tenor tidak valid. Tidak ada deduplikasi diam-diam yang mengubah jumlah kelas. Jika audit gagal, perbaiki rancangan/seed sebagai revisi tercatat sebelum kunci, bukan memilih seed berdasarkan skor test.

Target output revisi2 berupa tiga CSV utama, `borderline.csv`, salinan config dan `manifest.json` yang ditulis terakhir; generator sekarang belum mendukung borderline. Manifest baru wajib memuat jumlah/jenis label, SHA256 semua CSV, hash kode generator/config/protokol final, versi Python, urutan fitur, kolom yang wajib dikecualikan dan `training_data_version` berdasarkan provenance tersebut. Tidak memuat waktu absolut/path lokal sehingga dua output pada kode/config/Python sama dapat dibandingkan byte demi byte. Reproduksi lintas versi Python tidak diasumsikan; lingkungan dan hash perlu cocok.

Direktori output wajib baru, tidak ada overwrite atau penghapusan otomatis. Jika penulisan terputus, output tanpa manifest adalah tidak lengkap; gunakan direktori baru. Seluruh dataset lokal berada di `ml-service/data/generated/` yang diabaikan Git. Manifest preview tetap `status=draft`, `trained=false`; tidak mendaftarkan atau mengaktifkan model DB. Ini identitas dataset, bukan bukti bahwa konfigurasi telah disetujui.

## Protokol evaluasi yang diusulkan

Bagian ini belum dijalankan. Versi scikit-learn/numpy/scipy/joblib akan diperiksa kompatibilitas Python3.12 dan dipin untuk train/serve setelah rancangan disetujui. Major stack aplikasi tetap.

1. Fit pada semua baris **train** tanpa label; campuran sintetis 90/10 dipertahankan. Jangan menyaring train berdasarkan `is_anomaly`, melakukan resampling memakai validation/test, atau memasukkan empat kolom metadata ke estimator.
2. Bandingkan A: lima fitur mentah, dan B: lima fitur + cicilan/pendapatan, pinjaman/pendapatan, pengeluaran/pendapatan. Pembentukan fitur train/serve harus satu modul/pipeline yang sama saat model asli dibuat; urutan disimpan pada artefak. Tanpa scaler: pemisahan Isolation Forest per fitur tidak membutuhkan penyamaan skala linear positif; pilihan ini ditetapkan sebelum evaluasi, bukan dipilih dari test. Jangan mengklaim identitas numerik absolut lintas implementasi/pembulatan.
3. Grid untuk masing-masing varian: `n_estimators` 100/200, `max_samples`256, `contamination`0.05/0.10/0.15, `max_features`1.0, `bootstrap=false`. Seed estimator17/29/43, `n_jobs=1`; total36 fit train. Contamination selalu angka, sesuai DECIMAL(6,5), bukan `auto`.
4. Prediksi anomali bila `decision_function < 0`; threshold0 tetap sesuai kontrak. Jangan memilih threshold dari test. Label sintetis1 berarti injeksi; keluaran sklearn -1 dipetakan ke label1.
5. Hitung pada **validation** precision/recall/F1 kelas anomali, confusion matrix, jumlah flag, **average precision (AP)** dan **ROC-AUC**; tampilkan tiap seed serta mean/std. AP memakai `average_precision_score`, bukan luas trapezoidal kurva PR; skor kedua metrik peringkat adalah `-decision_function` dengan label anomali1. AP dan ROC-AUC **hanya pelaporan**, tidak masuk pemilihan, tie-break atau tuning threshold. Gunakan `zero_division=0` untuk precision/recall/F1 agregat dengan catatan jika tidak terdefinisi; rincian recall sel kosong memakai N/A sesuai bagian pita. Jika evaluasi tambahan kehilangan kelas, AP/ROC-AUC dilaporkan N/A dengan support dan penyebab, tidak mengarang skor. Pemilihan konfigurasi per varian tetap mean F1 tertinggi, kemudian mean precision, mean recall; jika tetap sama, estimator lebih sedikit lalu contamination lebih kecil. Pemilihan varian utama memakai urutan metrik yang sama; seri memilih A yang lebih sederhana.
6. Simpan keputusan konfigurasi per varian dan varian utama **sebelum membaca skor test/borderline**. Simpan hash protokol final/config/data/code/dependency sebagai bukti kunci. Jangan refit pada validation; model evaluasi tetap fit hanya train agar batas data jelas.
7. Buka **test dan borderline bersamaan sekali** setelah konfigurasi/pemilihan terkunci. Laporkan kedua varian pada ketiga seed yang sudah ditentukan beserta mean/std, precision/recall/F1, AP, ROC-AUC, confusion matrix, jumlah flag, rincian jenis injeksi dan pita pendapatan beserta support. Pisahkan laporan kedua set. Hasil test/borderline tidak dipakai mengganti konfigurasi atau varian utama. Jika hasil buruk, laporkan; eksperimen revisi berikutnya memerlukan versi/protokol/holdout baru yang dibahas, bukan berulang kali menyesuaikan ke holdout lama.
8. Untuk artefak demo yang dipilih melalui validation, gunakan seed17 yang ditetapkan sebelumnya; bukan memilih seed terbaik berdasarkan test. Simpan pipeline, metadata, hash dan versi lingkungan. Verifikasi SHA256 artefak terhadap manifest terpercaya **sebelum** memuat joblib; ketidakcocokan harus gagal tertutup. `/ready` wajib melaporkan pasangan versi dan hash artefak yang sudah diverifikasi/termuat. Isi `model_versions` melalui metadata/manifest tervalidasi (urutan fitur, contamination, parameter/seed, training_data_version dan artifact_hash), bukan ketik ulang manual. Jangan memuat pickle/joblib tidak terpercaya walaupun memiliki hash yang disediakan pihak yang sama. Registrasi/aktivasi dan readiness non-stub diimplementasikan/diuji pada tahap terpisah. Refit train+validation kelak menjadi versi model baru dengan catatan tersendiri.

Pembuatan test/borderline dan audit jumlah/hash tidak menghitung metrik model. Pemisahan akses metrik dilakukan oleh alur eksperimen; belum ada training/evaluator pada sesi ini. Jangan menafsirkan lolos audit generator sebagai holdout yang telah dievaluasi atau sebagai kualitas model.

## Urutan penguncian revisi 2

1. Revisi dan tinjau dokumen ini; catat persetujuan pengguna atas versi final. Persetujuan ini telah diberikan bersyarat pada penyempurnaan teks yang dimasukkan sebelum commit protokol final.
2. Setelah revisi final disetujui, hitung SHA256 **byte berkas protokol final** dan simpan dalam catatan lock terpisah, bukan di dalam dokumen yang di-hash. Gunakan blob Git dari commit protokol final (UTF-8/LF). .gitattributes menetapkan *.md text eol=lf; jangan hash salinan CRLF hasil editor. CSV memakai newline kosong dan lineterminator LF, JSON/config/manifest ditulis sebagai byte UTF-8 dengan LF eksplisit. Reproduksi Windows/Linux harus dibuktikan di lingkungan masing-masing; normalisasi saja bukan bukti pengujian lintas OS. Perubahan dokumen setelah hash berarti versi protokol baru beserta persetujuan/catatan hash baru, bukan mengganti isi di bawah hash lama.
Catatan lock cukup satu berkas experiments/LOCK.json: SHA256 protokol/config/lockfile dependency/manifest data dan ID commit yang relevan; field tahap yang belum tersedia null, bukan hash rekaan. Commit catatan ini pada setiap tahap. Tag lokal ml-protocol-v1-locked menunjuk commit protokol final; ml-config-v1-selected dibuat hanya setelah pemilihan validation, sebelum holdout. Tag tidak dipindahkan/ditimpa. Catatan commit dan tag merekam urutan lokal, bukan bukti waktu eksternal yang tidak dapat diubah. Hash LOCK.json sendiri tidak dimasukkan ke dalam dirinya.

3. Sesuaikan kode/config generator dengan protokol yang telah disetujui, uji seluruh invariant utama+borderline, hitung hash kode/config baru dan buat output pada direktori baru. Manifest/provenance harus mengikat hash protokol final dan seluruh data baru. Preview draf pertama tidak dipakai ulang untuk fitting/evaluasi revisi2 dan tidak dihapus/ditimpa.
4. Verifikasi kompatibilitas dan pin dependency train/serve, lalu catat hash lockfile/environment. Implementasikan pipeline bersama dan evaluator sesuai protokol. Simpan catatan lock eksperimen lengkap (protokol/config/data/kode/dependency) sebelum pemilihan validation; sesudah pemilihan simpan konfigurasi/model terpilih dan hash artefak sebelum membuka test/borderline. Perubahan yang memengaruhi data/protokol/pemilihan sesudah lock harus melalui versi/catatan baru sebelum holdout dibuka.
5. Jalankan evaluasi holdout satu kali sesuai butir7, lalu integrasi model demo sesuai butir8. Catat kegagalan operasional tanpa menyembunyikan run atau menjadikannya alasan tuning dari hasil holdout.

## Cara meninjau sekarang

Kode/perintah di bawah masih menghasilkan **preview draf pertama saja**, bukan dataset revisi2. Bukti historisnya dipertahankan untuk keterlacakan. Ini referensi historis; implementasi revisi terkunci mengikuti tahap pada PROGRESS, dengan output baru sesudah pembaruan generator.

Dari `ml-service`:

```powershell
& .\.venv\Scripts\python.exe -m unittest discover -s tests -v
& .\.venv\Scripts\python.exe -m experiments.generate --config experiments/generator-v1.draft.json --output data/generated/preview-v1
```

Gunakan nama output baru jika direktori sudah ada. Konfigurasi ini divalidasi ketat; menolak field asing, bool/float pada parameter integer, split yang tidak berjumlah100, pembagian kelompok/kelas tidak bulat, serta split yang kehilangan salah satu jenis injeksi. Rentang teknis kelompok400–20.000 dan variasi2–5 membatasi beban alat offline, bukan batas pinjaman bisnis.

Seluruh ketetapan distribusi/injeksi, split, borderline, pita, grid, metrik dan lock di atas menjadi acuan yang disetujui. Perubahan substantif berikutnya memerlukan versi protokol baru sebelum dipakai untuk eksperimen baru.

## Dasar teknis

- [Dokumentasi IsolationForest](https://scikit-learn.org/stable/modules/generated/sklearn.ensemble.IsolationForest.html): contamination numerik mengatur offset; skor negatif menandai outlier. Rentang, grid dan distribusi finansial di sini merupakan pilihan rancangan proyek, bukan rekomendasi kredit dari dokumentasi.
- [Cross-validation untuk data berkelompok](https://scikit-learn.org/stable/modules/cross_validation.html#cross-validation-iterators-for-grouped-data): kelompok subjek yang sama perlu dipisahkan agar evaluasi tidak bergantung pada kemiripan subjek train. Generator memakai pembagian kelompok deterministik sendiri, bukan mengklaim menjalankan GroupKFold.
- [Precision/recall/F-score](https://scikit-learn.org/stable/modules/generated/sklearn.metrics.precision_recall_fscore_support.html): definisi metrik dan penanganan pembagi nol. Metrik di sini hanya untuk label anomali sintetis.
