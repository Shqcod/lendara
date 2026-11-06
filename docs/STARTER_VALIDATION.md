# Validasi paket awal

Tanggal: 6 November 2026. Lingkungan pembuat: Linux, Python 3.12.14, fastapi0.141.1/pydantic2.13.4/uvicorn0.52.1/httpx0.28.1; transitive constraints dicatat. Ini berbeda dari Windows pengguna Python3.12.4.

Perintah dari ml-service:

```text
python -m unittest discover -s tests -v
Ran 13 tests ... OK
```

Mencakup: semua route menolak key salah/tidak ada; key belum dikonfigurasi fail-closed; health/readiness; normal/anomaly tetap tanpa pemicu bisnis; failure503; delay skenario timeout; schema/contract/model pin; numeric/missing/unknown/nonfinite validation; urutan key; skenario tak dikenal; penolakan mode demo/production/model yang belum diimplementasikan.

Catatan: Starlette menampilkan peringatan deprecation penggunaan httpx dengan TestClient. Test masih lulus pada dependency yang dipin; evaluasi migrasi test client saat dependency diperbarui. Jangan menyembunyikan error nyata dengan menonaktifkan warning secara global.

**Belum diuji:** instalasi bersih dependensi pada Windows, skrip PowerShell (pwsh tidak tersedia pada lingkungan pembuat), framework scaffold/download, PHP/Laravel, Sanctum/CORS browser, server MySQL/migration, queue/scheduler/deadline nyata, actual HTTP network timeout, perlombaan job, dan layanan model asli. Delay stub test tidak membuktikan HTTP timeout klien Laravel. Semua ini adalah pekerjaan berikutnya, bukan kegagalan yang disembunyikan.

File ZIP diperiksa agar dapat dibuka dan tidak menyertakan .env, venv, cache Python, atau dependency vendor/node_modules. Tidak ada model terlatih atau data rahasia pengguna dalam paket.

