# Jalur ML terkunci

Protokol beku ML_EXPERIMENT_PLAN.md disetujui pada tag lokal `ml-protocol-v1-locked` (b78f603). Hash berasal dari blob committed UTF-8/LF dan dicatat pada `ml-service/experiments/LOCK.json`. Status implementasi ada di PROGRESS, tanpa mengubah dokumen beku.

## Lingkungan dan perintah

Python3.12.4 Windows x64; venv training terisolasi di `.tools/ml-training-venv`. `requirements-model.lock.txt` adalah snapshot penuh24 paket train/serve yang berhasil dipasang dan lolos pip check: termasuk scikit-learn1.9.1, numpy2.5.4, scipy1.18.1, joblib1.6.0. Requirements/constraints runtime stub lama dipertahankan. Linux belum diuji; jangan mengklaim reproduksi lintas OS sudah terbukti. Dasar kompatibilitas: [panduan instalasi resmi](https://scikit-learn.org/stable/install.html), [metadata PyPI resmi](https://pypi.org/pypi/scikit-learn/json) serta pemasangan wheel aktual di Windows.

Dari root, untuk lingkungan baru; jangan menimpa venv operator yang ada:

```powershell
python -m venv .tools/ml-training-venv
& .\.tools\ml-training-venv\Scripts\python.exe -m pip install --only-binary=:all: -r ml-service/requirements-model.lock.txt
& .\.tools\ml-training-venv\Scripts\python.exe -m pip check
```

Dari `ml-service`:

```powershell
& ..\.tools\ml-training-venv\Scripts\python.exe -m unittest discover -s tests -v
& ..\.tools\ml-training-venv\Scripts\python.exe -m experiments.generate_locked --output data/generated/locked-v1-r2-baru
```

Output harus direktori baru. Generator membaca generator-v1.locked.json dan memeriksa hash protokol terhadap commit serta working tree. Manifest mengikat protokol/config/kode/data. CSV memakai newline kosong/lineterminator LF dan UTF-8; JSON ditulis byte UTF-8/LF. Git mengunci LF untuk Markdown/Python/config/LOCK/lockfile paket. Manifest memakai hash config canonical JSON yang identik dengan byte config locked yang disimpan. Format ulang config mengubah hash dan perlu peninjauan lock sebelum digunakan.

Audit global memeriksa kelompok/ID/vektor/komposisi utama+borderline. Batas minimum expense80% dan installment30% dibulatkan ke atas ke sen hanya jika floor akan melewati batas bawah; tes memeriksa jarak pada uang final, serta batas atas. Loan minimal19× sudah tepat integer sen. Tidak ada overwrite/deduplikasi diam-diam.

## Status implementasi

Loader/evaluator experiments/evaluate.py memverifikasi hash/environment dan committed source. Validation36 fit selesai; kedua varian memilih100 estimator dan contamination0.15, primary B. Commit b15c328/tag ml-config-v1-selected mengunci keputusan sebelum pembukaan holdout satu kali. Hasil lengkap dan keterbatasan ada di [laporan eksperimen](validation/ml-experiment-v1-results.md).

Test dan borderline telah dievaluasi sekali dengan model/threshold yang sama, tanpa fit ulang. Marker HOLDOUT_OPENED.json pada dataset mencegah run kedua; jangan menghapusnya atau mengubah config untuk menyesuaikan hasil. LOCK pada tag selected dipertahankan identik. Evaluator melaporkan AP/AUC hanya pelaporan, recall/support per jenis/pita/jenis x pita dan mean/std tiga seed; std bukan ketidakpastian sampel.

Enam artefak joblib hanya lokal di .tools/ml-selection-v1; B seed17 menjadi artefak demo yang sudah ditetapkan sebelum holdout. Metadata otomatis dan hash tercatat di docs/validation/ml-model-v1-metadata.json. FastAPI mode model memverifikasi hash sebelum load; readiness, registrasi metadata DB, queue Laravel dan handover tersedia. Workflow Chrome restore dengan model asli dan backup/restore pengajuan lengkap telah lulus; lihat PROGRESS dan HANDOVER. Gunakan metadata terpilih, jangan mengetik ulang atau melatih dari test.

Snapshot dependency bukan audit kerentanan. Warning Starlette/httpx deprecated tetap dilaporkan. Linux belum diuji. Git mengatur LF laporan JSON untuk menjaga hash blob/working tree; protokol beku tidak diubah.
