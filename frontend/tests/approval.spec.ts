import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

test('persetujuan nyata: forward, tingkat 1, final tingkat 2, penolakan tingkat 1 dan 2', async ({ browser }) => {
    test.setTimeout(300000);
    const password = randomUUID();
    const fixture = JSON.parse(execFileSync('php', ['tests/Support/browser-internal-fixtures.php'], {
        cwd: '../backend', env: { ...process.env, APP_ENV: 'testing' }, input: JSON.stringify({ run: randomUUID(), password, approval: true }), encoding: 'utf8', timeout: 30000
    }));
    const contexts = await Promise.all([browser.newContext(), browser.newContext(), browser.newContext()]);
    const pages = await Promise.all(contexts.map((context) => context.newPage()));
    async function login(page: Page, key: string) {
        await page.goto('/'); await expect(page.getByText('Belum masuk. Silakan gunakan akun Anda.', { exact: true })).toBeVisible();
        await page.getByLabel('Email', { exact: true }).fill(fixture.users[key].email);
        await page.getByLabel('Kata sandi', { exact: true }).fill(password);
        await page.getByRole('button', { name: 'Masuk', exact: true }).click();
        await page.getByRole('link', { name: 'Buka antrean internal' }).click();
        await expect(page.getByRole('button', { name: 'Cari pengajuan', exact: true })).toBeEnabled();
    }
    async function claim(page: Page, loan: { number: string }) {
        await page.getByRole('button', { name: 'Antrean pengajuan', exact: true }).click();
        await page.getByLabel('Nomor pengajuan', { exact: true }).fill(loan.number);
        await page.getByRole('button', { name: 'Cari pengajuan', exact: true }).click();
        await page.getByRole('button', { name: `Lihat ${loan.number}`, exact: true }).click();
        await page.getByRole('button', { name: 'Ambil tugas ini', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Lepas tugas', exact: true })).toBeEnabled();
    }
    async function decide(page: Page, button: string) {
        await page.getByRole('button', { name: button, exact: true }).click();
        await page.getByRole('button', { name: 'Konfirmasi keputusan', exact: true }).click();
    }
    try {
        const [verifier, one, two] = pages;
        await login(verifier, 'one'); await login(one, 'l1'); await login(two, 'l2');
        const loan = fixture.applications.verification;
        await claim(verifier, loan);
        await verifier.getByLabel('Catatan / alasan revisi', { exact: true }).fill('Anomali fiktif sudah ditinjau');
        await verifier.getByRole('button', { name: 'Teruskan ke penyetuju tingkat 1', exact: true }).click();
        await verifier.getByRole('button', { name: 'Konfirmasi tindakan', exact: true }).click();
        await expect(verifier.getByRole('heading', { name: loan.number, exact: true })).toHaveCount(0);
        await claim(one, loan);
        await one.getByRole('button', { name: 'Setuju dan teruskan ke tingkat 2', exact: true }).click();
        await one.getByRole('button', { name: 'Batal', exact: true }).click();
        await expect(one.getByRole('button', { name: 'Lepas tugas', exact: true })).toBeEnabled();
        await decide(one, 'Setuju dan teruskan ke tingkat 2');
        await expect(one.getByRole('heading', { name: loan.number, exact: true })).toHaveCount(0);
        await claim(two, loan);
        await two.setViewportSize({ width: 320, height: 780 });
        expect(await two.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await two.screenshot({ path: '../docs/validation/approval-mobile-320.png', fullPage: true });
        await two.setViewportSize({ width: 1366, height: 900 });
        await two.screenshot({ path: '../docs/validation/approval-desktop.png', fullPage: true });
        let decisions = 0;
        await two.route(`**/internal/submissions/${loan.current}/approval-decisions`, async (route) => {
            decisions++; const response = await route.fetch(); expect(response.status()).toBe(201); expect((await response.json()).data.application_status).toBe('approved'); await route.abort();
        }, { times: 1 });
        await decide(two, 'Setujui pengajuan final');
        await expect(two.getByRole('alert').first()).toContainText('Periksa sesi sebelum mengirim ulang');
        await expect(two.getByRole('button', { name: 'Setujui pengajuan final', exact: true })).toBeDisabled();
        await two.getByRole('button', { name: 'Periksa data terbaru', exact: true }).click();
        await expect(two.getByRole('heading', { name: loan.number, exact: true })).toHaveCount(0);
        expect(decisions).toBe(1);
        // Rejection requires notes and is terminal at either level.
        await expect(two.getByRole('button', { name: 'Minta revisi', exact: true })).toHaveCount(0);
        const rejected = fixture.applications.l1;
        await claim(one, rejected);
        await decide(one, 'Tolak pengajuan');
        await expect(one.getByRole('alert').first()).toBeVisible();
        await one.getByLabel('Catatan keputusan / alasan penolakan', { exact: true }).fill('Alasan internal untuk tingkat 1');
        await decide(one, 'Tolak pengajuan');
        await expect(one.getByRole('heading', { name: rejected.number, exact: true })).toHaveCount(0);
        const second = fixture.applications.rejection;
        await claim(one, second); await decide(one, 'Setuju dan teruskan ke tingkat 2');
        await expect(one.getByRole('heading', { name: second.number, exact: true })).toHaveCount(0);
        await claim(two, second);
        await two.getByLabel('Catatan keputusan / alasan penolakan', { exact: true }).fill('Alasan internal tingkat 2');
        const response = two.waitForResponse((r) => r.url().endsWith('/approval-decisions') && r.request().method() === 'POST');
        await decide(two, 'Tolak pengajuan');
        expect((await (await response).json()).data.application_status).toBe('rejected');
        await expect(two.getByRole('heading', { name: second.number, exact: true })).toHaveCount(0);
    } finally { await Promise.all(contexts.map((context) => context.close())); }
});
