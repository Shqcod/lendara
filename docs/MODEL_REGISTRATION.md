# Registrasi model Laravel

`php artisan ml:register-model {metadata} --sha256={hash}` mengimpor model terlatih dari metadata operator tepercaya. Mode efektif Laravel harus model. Hash metadata berasal dari LOCK selected/hasil review, bukan mengambil hash dari pengirim berkas tidak terpercaya. Registrasi tidak mengeksekusi joblib dan tidak melatih model.

Command memvalidasi schema, source/model/trained, urutan fitur A/B, presisi contamination DECIMAL(6,5), threshold0, path artefak dalam root proyek serta SHA256 artefaknya. Nilai model_versions diisi otomatis dari metadata; parameters juga memuat source/trained, protocol hash dan dependency provenance. Backend memeriksa integritas file; pencocokan parameter terhadap estimator saat deserialisasi tetap dilakukan FastAPI, bukan PHP.

Versi/config immutable: pemanggilan identik idempoten, versi sama dengan metadata berbeda ditolak. Semua insert model/audit atomik. GET_LOCK lendara:model-activation sama dengan command stub, unique index version/active_marker tetap pengaman. Tidak ada migration atau overwrite model lama.

## Aktivasi eksplisit

Tanpa --activate, model didaftarkan inactive dan tidak melakukan HTTP. Dengan --activate, command mengecek /ready **sebelum transaksi/lock milik command**: mode model, ready=true, contract1 serta versi/hash cocok di loaded_models. Lalu aktivasi/audit atomik hanya jika tidak ada model aktif lain. Request yang berlomba tetap dilindungi named lock dan constraint. Tes MySQL membuktikan idempotensi/rollback/constraint, bukan observasi dua proses registrasi secara konkuren baru.

**Model aktif lain dipertahankan.** Command ini tidak diam-diam mematikan stub/model lama. Handover dari model aktif lama beserta perlindungan pekerjaan processing masih perlu prosedur/perintah terpisah sebelum demo/deployment; jangan menonaktifkan model atau reset DB hanya untuk melewati penolakan. Readiness sebelum transaksi adalah snapshot, bukan jaminan layanan tidak berubah sesudahnya; kegagalan runtime selanjutnya tetap ditangani job/deadline yang ada.

Dari backend, untuk meninjau argumen metadata yang tidak rahasia:

```powershell
$modelLock = Get-Content -Raw ../ml-service/experiments/LOCK.json | ConvertFrom-Json
php artisan ml:register-model ("../" + $modelLock.selection.metadata_path) ("--sha256=" + $modelLock.selection.metadata_sha256)
```

Jalankan hanya sesudah mode ML, environment dan DB target efektif diperiksa. Tambahkan --activate hanya bila diinginkan, runtime siap dan tidak ada model aktif lain. Tidak ada password/token pada argv; kunci ML tetap lokal. Tidak mengubah .env secara otomatis. Di sesi pengembangan ini command hanya diuji pada kkp_pinjaman_test, tidak mendaftarkan/mengaktifkan model pada DB pengembangan.

## Bukti yang digunakan

ModelRegistrationTest memakai transaksi MySQL test terpisah: lima tes/30 assertions lulus (39.84s). Aktivasi diuji dengan readiness HTTP fake; fixture outer transaction PHPUnit dipertahankan, sehingga ini bukan HTTP readiness server nyata pada saat aktivasi.

Smoke tests/Support/model-queue-smoke.php dan ml-service/tests/Support/laravel_model_smoke.py dirancang untuk DB testing saja. Guard memeriksa APP_ENV, MySQL dan SELECT DATABASE() sebelum fixture. Registrasi model riil tetap inactive, lalu snapshot/check fiktif memin ID model tersebut, job disimpan ke database queue unik, worker satu job memanggil Uvicorn mode model. Tidak mengganti active model global, tidak menguras queue lain, tidak membuat keputusan pinjaman, tidak memakai holdout, dan tidak menguji submit nasabah/browser baru.

Python smoke memakai kunci acak di environment proses, tidak pada argv/file/log; proses Uvicorn miliknya ditutup. Fixture/riwayat test tetap dipertahankan, tanpa penghapusan massal. Hasil aktual suite penuh dan smoke dicatat di PROGRESS. Worker Windows --timeout=0 bukan pembuktian kill/timeout POSIX.

Hasil akhir sesi: regresi penuh135 tes/1448 assertions122.81s exit0; sesudah hardening baca metadata satu kali, target ulang5/30 assertions1.84s dan Pint --test lulus. Smoke worker nyata exit0, check1114 succeeded dan aplikasi2428 tetap pending_verification; model terdaftar inactive. Bukti aman: validation/laravel-model-queue-smoke-2026-11-15.json dan validation/model-registration-2026-11-15.json.
