# Validasi perubahan akses akun internal — 15 November 2026

Cakupan: role-change dan nonaktif/aktif empat peran internal, pelepasan tugas dan pencabutan sesi atomik, UI admin. Keputusan pengguna: perubahan akun sendiri dilarang dan admin aktif terakhir dilindungi (D37). Awal commit b139e84; tanpa push.

## Backend dan MySQL

- Guard `php artisan system:assert-test-database --env=testing`: PASS MySQL8.0.30, kkp_pinjaman_test. Server awal mati; instance yang ada dijalankan memakai konfigurasi/data yang ada, tanpa init/migration/reset.
- `php artisan test --no-ansi`: **130 passed, 1406 assertions, 73.49s, exit0**. Log aman [account-access-php-tests-2026-11-15.txt](account-access-php-tests-2026-11-15.txt).
- `php vendor/bin/pint --test`: PASS.
- Target awal 4 lulus/1 gagal karena cached guard dalam harness; forgetGuards sebelum login baru memperbaikinya. Dua tes yang terpicu ketika server belum hidup dihentikan dan bukan bukti kelulusan.
- Deactivate lalu claim: koneksi84/85, 85 menunggu84, changed/revoked. Claim lalu deactivate: koneksi87/86, 86 menunggu87, created/changed. Dua proses/kernel/transaksi dan observasi wait aktual, bukan HTTP serial atau konkurensi browser. Fixture hanya DB test, tanpa pembersihan massal.

- Setelah tes rollback diperketat agar audit terakhir gagal sesudah seluruh perubahan: `php artisan test --no-ansi --filter=AccountManagementTest`: **5 passed, 38 assertions, 3.48s, exit0**. Guard dan Pint terakhir lulus.

## Frontend

- `scripts/frontend.ps1 -NpmArgs @('run','check')`: **0 error/0 warning**.
- `scripts/frontend.ps1 -NpmArgs @('run','build')`: **PASS**, adapter-auto belum memilih deployment.
- Chrome final: 4 lulus (3.8m), exit0; JSON duration228774.066ms, expected4/skipped0/unexpected0/flaky0. Perintah scripts/frontend.ps1 -NpmArgs @('run','test:e2e','--','account-access.spec.ts','auth.spec.ts','admin.spec.ts','--reporter=list,json'). Backend dan browser berjalan berurutan. Skenario perubahan akses: claim nyata, role-change409, cancel, deactivate respons200 diputus tanpa replay, sesi lama invalid, aktif kembali tanpa restore tugas, login baru, role baru setelah login. Regresi create/filter/log admin serta cookie/CSRF/register/logout juga lulus; bukan seluruh suite browser lama. Percobaan sebelumnya mencatat passed pada artefak Playwright tetapi wrapper exit1 akibat pengalihan stderr PowerShell, sehingga diulang tanpa pengalihan dengan laporan JSON. Screenshot account-access dan admin-accounts desktop/320px diperiksa visual, hanya data fiktif/password kosong.

## Batas pembuktian

Marker ID audit menolak payload sesi lama pada request berikutnya walau akun diaktifkan lagi. Feature test mensimulasikan payload lama; bukan penghentian paksa request yang sudah diotorisasi. Mutasi domain tetap memeriksa ulang active/role di bawah lock. Sesi wajib driver database pada koneksi yang sama agar delete sesi dan perubahan akun atomik. Tidak ada schema baru.

Status pinjaman, snapshot, check dan hak dokumen tidak diubah. ML tetap stub, tanpa training/audit dependency/smoke ML baru. Grant runtime, orphan crash, proxy/deployment dan timeout POSIX masih belum dibuktikan. Tidak menimpa .env, mengubah dependency atau memakai database pengembangan untuk pengujian.

## Pemeriksaan sebelum commit

Pemindaian 26 kandidat berkas terhadap lima nilai sensitif lokal serta pola token/private key: tidak ada kecocokan, tanpa mencetak nilai. git check-ignore mengonfirmasi empat .env lokal, .tools dan frontend/test-results dikecualikan. Bukti browser lengkap tetap lokal; ringkasan aman tersimpan pada [account-access-browser-2026-11-15.txt](account-access-browser-2026-11-15.txt).
