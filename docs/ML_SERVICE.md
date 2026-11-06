# Prediksi, stub, dan model

## Kontrak versi 1

Layanan hanya dapat dijangkau Laravel melalui loopback/jaringan privat + header X-Internal-API-Key. Jangan expose kunci pada frontend. Endpoint GET /health, GET /ready, POST /predict semuanya diperiksa kuncinya. Tidak mengaktifkan CORS browser pada FastAPI. Contoh JSON ada di fixtures/.

Input wajib: contract_version='1', feature_schema='loan-finance-v1', anomaly_check_id dan submission_id integer positif, model_version_code, features berisi tepat lima field. Tolak unknown fields, NaN/infinity, boolean sebagai angka, tenor noninteger, income<=0, loan<=0, expenses/installments<0. Data internal berupa angka finite; uang string publik dikonversi Laravel secara terkontrol.

Urutan masukan baku:

1. monthly_income
2. monthly_expenses (tidak termasuk cicilan)
3. existing_installments (per bulan)
4. loan_amount
5. tenor_months

Python menyusun array dari nama tersebut, bukan urutan properti JSON. Model varian A menggunakan 5 fitur; B menambahkan existing_installments/monthly_income, loan_amount/monthly_income, monthly_expenses/monthly_income dalam urutan itu. Daftar mentah dan fitur estimator disimpan dalam metadata artefak/model_versions; request schema tetap sama. Rasio dan prapemrosesan dihitung satu pipeline/module yang sama untuk train dan serve. Scaler hanya jika dipilih dalam eksperimen, bukan kewajiban Isolation Forest; jangan fit ulang saat inferensi.

Response 200: contract_version,feature_schema,anomaly_check_id,submission_id,model_version_code,source ('stub'/'model'),score_method='decision_function',score numeric finite,decision_threshold=0.0,is_anomaly=(score<0). 0 bukan anomali. Ini bukan probabilitas penipuan. Laravel mengecek semua identitas/skema/model/source dan konsistensi tanda skor. Kegagalan validasi output menjadikan check failed dengan alasan, bukan menyimpan succeeded.

Error bersama: 401 INTERNAL_UNAUTHORIZED, 422 VALIDATION_ERROR, 422 CONTRACT_VERSION_UNSUPPORTED, 422 FEATURE_SCHEMA_UNSUPPORTED, 503 MODEL_VERSION_UNAVAILABLE, 503 PREDICTION_UNAVAILABLE, 503 SERVICE_NOT_READY. model_version_code yang diminta dipin ke baris check; model berganti tidak boleh membuat layanan diam-diam memakai versi lain. Rencana layanan asli menyimpan beberapa versi yang masih dibutuhkan percobaan in-flight; ready melaporkan loaded_model_versions/default_model_version_code. Bila versi belum tersedia, gagal eksplisit.

## Stub yang disediakan

Aktif hanya ML_MODE=stub dan ML_ENV=local/testing, dengan model code stub-v0. X-Stub-Scenario (normal/anomaly/failure/timeout) ditentukan konfigurasi pengujian Laravel, tidak dari endpoint publik atau data bisnis. Tidak ada pemicu berdasarkan NIK/nominal. Semua output source=stub. Tidak perlu file model palsu.

| Skenario | Hasil |
|---|---|
| normal atau tanpa header | 200 score 0.08, false |
| anomaly | 200 score -0.12, true |
| failure | 503 PREDICTION_UNAVAILABLE |
| timeout | Tunggu delay 25 detik (default), lalu output normal; klien Laravel 20 detik seharusnya timeout |

Service belum mengimplementasikan mode model. Jika ML_MODE=model atau lingkungan demo/production, ready/predict 503; jangan fallback ke stub. Implementasi model asli harus menolak X-Stub-Scenario. Deployment demo memeriksa mode=model dan model aktif DB tidak stub-* serta tersedia di loaded_model_versions. Seeder stub hanya untuk local/testing, tidak otomatis aktif di demo.

## Siklus pemeriksaan

Snapshot + check processing + deadline 120 detik dibuat atomik. Job tries=1 dan HTTP tanpa auto retry. Cek status/deadline sebelum HTTP, gunakan min(20,sisa-5) detik; jika tidak cukup gagal tanpa HTTP. DB transaction pendek pertama memuat konteks, commit, lalu HTTP, transaction pendek kedua simpan bila masih processing, belum deadline, dan berlaku pada snapshot terkini. Score yang datang setelah failed/deadline tidak mengubah hasil terminal; activity_logs anomaly.response_ignored mencatat ID/reason tanpa isi sensitif.

Scheduler setiap menit menandai processing lewat deadline sebagai failed. Tombol retry belum aktif sampai failed. Percobaan manual membuat attempt baru dalam transaksi parent-lock. Job lama/duplikat tidak boleh mengubah attempt baru. Pengaman eksekusi terdistribusi per check ID tidak menggantikan conditional write dan unique index. At-most-one effect pada DB, bukan klaim exactly-once inference. Lihat ENVIRONMENT untuk keterbatasan worker Windows.

## Eksperimen model (belum diimplementasikan)

- Mulai sejak tahap 3. Generator deterministik dengan random seed, distribusi realistis yang terdokumentasi, jumlah data, rentang, korelasi, dan daftar anomali injeksi.
- Train/validation/test terpisah; jangan menyalin baris/variasi pelanggan yang sama lintas split. NIK dummy unik jika membuat profil.
- Lima fitur vs lima+tiga rasio. Pilih contamination numerik >0 <=0.5, seed/config lewat validasi; kunci sebelum final test. Jangan gunakan contamination='auto' karena schema DECIMAL.
- Laporkan precision/recall/F1 untuk label anomali sintetis dan jumlah flag, serta keterbatasan simulasi. Bukan akurasi penipuan dunia nyata. Jangan mengarang hasil atau menjamin rasio pasti meningkatkan hasil.
- Persist pipeline terpercaya + metadata/hash/training_data_version serta versi Python/sklearn/numpy/scipy dan dependensi lain. Pin lingkungan train/serve sama. Jangan memuat artefak pickle/joblib tidak terpercaya.
- requirements.txt saat ini hanya runtime stub/test; sklearn/numpy belum dipilih. Tambahkan versi terverifikasi saat eksperimen dimulai dan pisahkan dependency training jika perlu.
- Uji kontrak yang sama pada stub/model nyata, dengan ekspektasi source/version yang sesuai. Skor tetap hanya untuk stub, bukan untuk model nyata.

