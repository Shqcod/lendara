import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

test('retry: pemilik tugas, arsip baca, konfirmasi dan respons terputus tanpa replay', async ({ page }) => {
    test.setTimeout(150000);
    const password = randomUUID();
    const fixture = JSON.parse(execFileSync('php', ['tests/Support/browser-internal-fixtures.php'], {
        cwd: '../backend', env: { ...process.env, APP_ENV: 'testing' }, input: JSON.stringify({ run: randomUUID(), password, retry: true }), encoding: 'utf8', timeout: 30000
    }));
    const loan = fixture.applications.verification;
    await page.goto('/');
    await expect(page.getByText('Belum masuk. Silakan gunakan akun Anda.', { exact: true })).toBeVisible();
    await page.getByLabel('Email', { exact: true }).fill(fixture.users.one.email);
    await page.getByLabel('Kata sandi', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await page.getByRole('link', { name: 'Buka antrean internal' }).click();
    await expect(page.getByRole('button', { name: 'Cari pengajuan', exact: true })).toBeEnabled();
    await page.getByLabel('Nomor pengajuan', { exact: true }).fill(loan.number);
    await page.getByRole('button', { name: 'Cari pengajuan', exact: true }).click();
    await page.getByRole('button', { name: `Lihat ${loan.number}`, exact: true }).click();
    await expect(page.getByRole('button', { name: 'Ulangi pemeriksaan', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Ambil tugas ini', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Ulangi pemeriksaan', exact: true })).toBeEnabled();
    await page.getByRole('button', { name: 'Lihat versi 1', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Ulangi pemeriksaan', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Versi terkini', exact: true }).click();
    await page.getByRole('button', { name: 'Ulangi pemeriksaan', exact: true }).click();
    await page.getByRole('button', { name: 'Batal', exact: true }).click();
    await page.setViewportSize({ width: 320, height: 780 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: '../docs/validation/retry-mobile-320.png', fullPage: true });
    await page.getByRole('button', { name: 'Ulangi pemeriksaan', exact: true }).click();
    let sends = 0;
    await page.route(`**/internal/submissions/${loan.current}/anomaly-checks`, async (route) => {
        sends++; const response = await route.fetch(); expect(response.status()).toBe(202); await route.abort();
    }, { times: 1 });
    await page.getByRole('button', { name: 'Ya, ulangi pemeriksaan', exact: true }).click();
    await expect(page.getByRole('alert').first()).toContainText('Periksa sesi sebelum mengirim ulang');
    await expect(page.getByRole('button', { name: 'Ulangi pemeriksaan', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Periksa data terbaru', exact: true }).click();
    await expect(page.getByText('Percobaan 2 · Diproses', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Ulangi pemeriksaan', exact: true })).toBeDisabled();
    expect(sends).toBe(1);
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.screenshot({ path: '../docs/validation/retry-desktop.png', fullPage: true });
});
