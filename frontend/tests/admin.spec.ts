import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

test('admin: buat akun tanpa replay, login akun baru, filter dan log aman, akses reviewer ditolak', async ({ page, browser }) => {
    test.setTimeout(180000);
    const password = randomUUID();
    const fixture = JSON.parse(execFileSync('php', ['tests/Support/browser-internal-fixtures.php'], {
        cwd: '../backend', env: { ...process.env, APP_ENV: 'testing' }, input: JSON.stringify({ run: randomUUID(), password }), encoding: 'utf8', timeout: 30000
    }));
    await page.goto('/'); await expect(page.getByText('Belum masuk. Silakan gunakan akun Anda.', { exact: true })).toBeVisible();
    await page.getByLabel('Email', { exact: true }).fill(fixture.users.admin.email);
    await page.getByLabel('Kata sandi', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await page.getByRole('link', { name: 'Buka pengelolaan admin', exact: false }).click();
    await expect(page.getByRole('button', { name: 'Buat akun internal', exact: true })).toBeEnabled();
    const email = `created-${randomUUID()}@example.test`; const newPassword = randomUUID();
    await page.getByLabel('Nama internal fiktif', { exact: true }).fill('Peninjau Baru Fiktif');
    await page.getByLabel('Email akun baru', { exact: true }).fill(email);
    await page.getByLabel('Peran akun baru', { exact: true }).selectOption('verifikator');
    await page.getByLabel('Kata sandi akun baru', { exact: true }).fill(newPassword);
    await page.getByLabel('Konfirmasi kata sandi baru', { exact: true }).fill(newPassword);
    let sends = 0;
    await page.route('**/api/v1/admin/users', async (route) => {
        sends++; const response = await route.fetch(); expect(response.status()).toBe(201); const user = (await response.json()).data;
        expect(user.role).toBe('verifikator'); expect(user.password).toBeUndefined(); await route.abort();
    }, { times: 1 });
    await page.getByRole('button', { name: 'Buat akun internal', exact: true }).click();
    await expect(page.getByRole('alert').first()).toContainText('Periksa sesi sebelum mengirim ulang');
    await expect(page.getByRole('button', { name: 'Buat akun internal', exact: true })).toBeDisabled();
    await expect(page.getByLabel('Kata sandi akun baru', { exact: true })).toHaveValue('');
    await expect(page.getByLabel('Konfirmasi kata sandi baru', { exact: true })).toHaveValue('');
    await page.getByRole('button', { name: 'Periksa data terbaru', exact: true }).click();
    await page.getByLabel('Cari email lengkap', { exact: true }).fill(email);
    await page.getByRole('button', { name: 'Cari akun', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Cari akun', exact: true })).toBeEnabled();
    await expect(page.locator('section').filter({ has: page.getByRole('heading', { name: 'Akun internal', exact: true }) }).locator('tbody tr')).toHaveCount(1);
    await expect(page.getByRole('cell', { name: email, exact: true })).toHaveCount(1); expect(sends).toBe(1);
    await page.getByLabel('Kode tindakan lengkap', { exact: true }).fill('internal_user.created');
    await page.getByRole('button', { name: 'Cari log', exact: true }).click();
    await expect(page.getByRole('cell', { name: 'internal_user.created', exact: true }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Cari log', exact: true })).toBeEnabled();
    await page.screenshot({ path: `../docs/validation/${process.env.RUNTIME_GRANTS_TEST==='1'?'runtime-':''}admin-accounts-desktop.png`, fullPage: true });
    await page.setViewportSize({ width: 320, height: 780 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: `../docs/validation/${process.env.RUNTIME_GRANTS_TEST==='1'?'runtime-':''}admin-accounts-mobile-320.png`, fullPage: true });
    const context = await browser.newContext(); const reviewer = await context.newPage();
    try {
        await reviewer.goto('/'); await expect(reviewer.getByText('Belum masuk. Silakan gunakan akun Anda.', { exact: true })).toBeVisible();
        await reviewer.getByLabel('Email', { exact: true }).fill(email); await reviewer.getByLabel('Kata sandi', { exact: true }).fill(newPassword);
        await reviewer.getByRole('button', { name: 'Masuk', exact: true }).click();
        await expect(reviewer.getByRole('link', { name: 'Buka antrean internal', exact: false })).toBeVisible();
        await reviewer.goto('/admin'); await expect(reviewer.getByText('Halaman khusus admin.', { exact: true })).toBeVisible();
        await expect(reviewer.getByRole('heading', { name: 'Akun internal', exact: true })).toHaveCount(0);
    } finally { await context.close(); }
});
