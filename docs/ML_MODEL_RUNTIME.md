# Runtime FastAPI model terpilih

Entry point operasional: `app.serve:app`. Mode stub mendelegasikan kode lama `app.main` tanpa mengubah hash kode stub yang terdaftar. Mode model memuat registry operator dan pipeline terpilih, tanpa fitting atau membaca dataset train/validation/test/borderline. Registrasi/aktivasi model Laravel dan smoke queue dari Laravel masih tahap berikutnya.

## Konfigurasi dan kepercayaan

`experiments.export_registry` mengekspor metadata terpilih hanya jika LOCK identik tag selected serta hash metadata/artefak cocok. Contoh registry nyata aman: `docs/validation/ml-runtime-registry-v1.json`; memuat B seed17 dan hash pipeline. SHA256 registry tersebut `d0a0de0d02838762ca1bbef501b32e93d56ecd2d067c7f1d0b23926404fe789c`. Hash ini bukan token API.

Registry harus berasal dari operator/sumber tepercaya. Hash dari pengirim tidak terpercaya bersama file joblib bukan dasar kepercayaan: deserialisasi joblib dapat mengeksekusi kode. Pemeriksaan hash registry dilakukan sebelum parsing; hash pipeline, snapshot dependency/versi Python+paket serta semua artefak diperiksa sebelum joblib load. Metadata fitur/parameter dibandingkan dengan pipeline/estimator sesudah load. Ketidakcocokan membuat readiness/predict503, tanpa fallback stub atau kebocoran path/error mentah. [Batas keamanan persistensi scikit-learn](https://scikit-learn.org/stable/model_persistence.html).

Model dimuat sekali saat proses aplikasi dibuat. `/ready` melaporkan hash **byte yang telah diverifikasi dan termuat di memori**, bukan mengecek ulang file pada setiap request. Mengganti file di disk tidak mengganti model aktif; restart terkontrol diperlukan. Registry mendukung beberapa versi pinned sekaligus; default harus termasuk versi yang termuat. Versi request tidak tersedia menghasilkan MODEL_VERSION_UNAVAILABLE, tanpa berpindah ke default. Jika salah satu entri registry gagal, seluruh registry ditolak agar operator tidak mendapat readiness parsial.

## Menjalankan lokal

Jangan menimpa `.env` yang ada. Setelah memastikan artefak `.tools/ml-selection-v1/B-17.joblib` dan snapshot paket tersedia, operator mengatur setting lokal:

```dotenv
ML_ENV=local
ML_MODE=model
ML_MODEL_REGISTRY=docs/validation/ml-runtime-registry-v1.json
ML_MODEL_REGISTRY_SHA256=d0a0de0d02838762ca1bbef501b32e93d56ecd2d067c7f1d0b23926404fe789c
ML_ARTIFACT_ROOT=
```

`INTERNAL_API_KEY` tetap rahasia lokal, harus cocok dengan Laravel. Root kosong berarti root proyek; semua artifact_path dan dependency snapshot relatif terhadap root tersebut dan tidak boleh keluar lewat traversal/symlink. Path registry sendiri dapat absolut sebagai konfigurasi operator. Pada paket deployment, salin artefak terpercaya/snapshot/pipeline ke susunan root yang cocok atau ekspor registry baru yang ditinjau dan pin hash baru. Tidak ada unduhan joblib otomatis.

Helper root `scripts/start-ml.ps1` kini memakai `app.serve:app`; mode model otomatis memilih `.tools/ml-training-venv`, atau gunakan `-PythonExecutable` untuk venv lain yang memasang requirements-model.lock.txt. Stub tetap memakai venv lama. Helper tidak memaksa mode model atau mengaktifkan DB. Dalam sesi ini helper hanya diperiksa parser; `.env` lokal tidak diedit dan helper tidak dijalankan terhadap konfigurasi operator.

Dari `ml-service`, ekspor ulang ke **berkas baru** bila perlu:

```powershell
& ..\.tools\ml-training-venv\Scripts\python.exe -m experiments.export_registry --output ../.tools/registry-reviewed.json
```

## Kontrak dan pembuktian

Semua /health,/ready,/predict memerlukan header internal; tidak ada CORS browser atau docs/OpenAPI publik. Mode model menolak X-Stub-Scenario, memvalidasi kontrak/skema/ID positif dan lima fitur, mengembalikan source=model, threshold0 dan flag score<0. Skor nonfinite/exception menjadi503 PREDICTION_UNAVAILABLE. Endpoint sinkron memakai thread pool FastAPI agar inference tidak berjalan di event loop; ini bukan pembuktian kapasitas/timeout POSIX. [Perilaku fungsi sinkron FastAPI](https://fastapi.tiangolo.com/async/).

Suite penuh44 tes lulus22.526s, exit0, setelah run awal43 tes juga lulus. Cakupan model: autentikasi, pipeline asli tanpa fit, urutan key, hash-registry/artifact/pipeline/dependency, metadata salah, versi pinned ganda, no-fallback, ID/input invalid, header stub, skor nonfinite, sanitasi error dan memori model sesudah registry disk berubah. Warning Starlette/httpx deprecated tetap tercatat.

`tests/Support/model_http_smoke.py` menjalankan Uvicorn loopback port sementara dengan kunci acak hanya di memori/proses, menguji HTTP nyata, lalu menutup proses miliknya. Smoke exit0: ready200/hash B17 benar, prediksi source=model score0.08765331053650444 untuk fixture fiktif (bukan nilai skor tetap kontrak model), anonim401/header stub422/versi hilang503. Tidak mengeksekusi evaluator atau membaca holdout. Ringkasan aman di validation/ml-model-runtime-2026-11-15.json.

Protokol, LOCK selected, model_pipeline, app.main dan dependency lock tidak berubah. Tidak menjalankan ulang PHPUnit/MySQL/browser karena backend/frontend tidak berubah. Belum ada registrasi model DB, perubahan ML_MODE Laravel, queue→model nyata, kesiapan deployment penuh, uji Linux/PCNTL atau audit dependency baru.

Lanjutan sesi Laravel: registrasi metadata dan smoke database queue kini terbukti pada DB test; lihat MODEL_REGISTRATION.md. Batas sesi runtime di atas tetap catatan historis, bukan status terbaru registrasi. Handover model aktif dan submit browser model asli belum dijalankan.
