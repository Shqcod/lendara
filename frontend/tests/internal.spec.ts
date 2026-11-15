import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { mkdir, readFile } from 'node:fs/promises';

test('antrean internal: dua peninjau, claim eksklusif, arsip privat, release tanpa replay dan scope tiga tahap', async ({ browser }) => {
	test.setTimeout(240000);
	const password = randomUUID();
	const fixture = JSON.parse(execFileSync('php', ['tests/Support/browser-internal-fixtures.php'], {
		cwd: '../backend', env: { ...process.env, APP_ENV: 'testing' }, input: JSON.stringify({ run: randomUUID(), password }), encoding: 'utf8', timeout: 30000
	}));
	const oneContext = await browser.newContext(); const twoContext = await browser.newContext();
	const one = await oneContext.newPage(); const two = await twoContext.newPage();
	async function login(page: Page, key: string) {
		await page.goto('/'); await expect(page.getByText('Belum masuk. Silakan gunakan akun Anda.', { exact: true })).toBeVisible();
		await page.getByLabel('Email', { exact: true }).fill(fixture.users[key].email);
		await page.getByLabel('Kata sandi', { exact: true }).fill(password);
		await page.getByRole('button', { name: 'Masuk', exact: true }).click();
		await expect(page.getByRole('heading', { name: `Halo, Peninjau Fiktif ${key}.`, exact: true })).toBeVisible();
	}
	async function openQueue(page: Page, number: string) {
		await page.getByRole('link', { name: 'Buka antrean internal' }).click();
		await expect(page.getByRole('button', { name: 'Cari pengajuan', exact: true })).toBeEnabled();
		await page.getByLabel('Nomor pengajuan', { exact: true }).fill(number);
		await page.getByRole('button', { name: 'Cari pengajuan', exact: true }).click();
		await expect(page.getByRole('button', { name: `Lihat ${number}`, exact: true })).toBeVisible();
	}
	try {
		const loan = fixture.applications.verification;
		await login(one, 'one'); await openQueue(one, loan.number);
		await one.getByRole('button', { name: `Lihat ${loan.number}`, exact: true }).click();
		await expect(one.getByRole('heading', { name: 'Pengiriman versi 2 · terkini', exact: true })).toBeVisible();
		await expect(one.getByText('Nasabah Terkini Fiktif', { exact: true })).toBeVisible();
		await login(two, 'two'); await openQueue(two, loan.number);
		let claims = 0;
		await one.route(`**/api/v1/internal/applications/${loan.id}/assignments`, async (route) => {
			claims++; const response = await route.fetch(); expect(response.status()).toBe(201); await route.abort();
		}, { times: 1 });
		await one.getByRole('button', { name: 'Ambil tugas ini', exact: true }).click();
		await expect(one.getByRole('alert')).toContainText('Periksa sesi sebelum mengirim ulang');
		await expect(one.getByRole('button', { name: 'Ambil tugas ini', exact: true })).toBeDisabled();
		await one.getByRole('button', { name: 'Periksa data terbaru', exact: true }).click();
		await expect(one.getByRole('button', { name: 'Lepas tugas', exact: true })).toBeEnabled();
		expect(claims).toBe(1);
		const lost = two.waitForResponse((r) => r.url().endsWith(`/applications/${loan.id}/assignments`) && r.request().method() === 'POST');
		await two.getByRole('button', { name: `Ambil ${loan.number}`, exact: true }).click();
		expect((await lost).status()).toBe(409);
		await expect(two.getByRole('alert')).toContainText('Tugas sudah diambil');
		await two.getByRole('button', { name: 'Periksa data terbaru', exact: true }).click();
		await two.getByRole('button', { name: 'Semua pada tahap ini', exact: true }).click();
		await expect(two.getByRole('button', { name: `Lihat ${loan.number}`, exact: true })).toBeEnabled();
		await two.getByRole('button', { name: `Lihat ${loan.number}`, exact: true }).click();
		await expect(two.getByText('Tugas dipegang peninjau lain.', { exact: false })).toBeVisible();
		await expect(two.getByRole('button', { name: 'Lepas tugas', exact: true })).toHaveCount(0);
		await one.getByRole('button', { name: 'Lihat versi 1', exact: true }).click();
		await expect(one.getByRole('heading', { name: 'Pengiriman versi 1 · arsip, hanya baca', exact: true })).toBeVisible();
		await expect(one.getByRole('button', { name: 'Lepas tugas', exact: true })).toBeDisabled();
		const downloadEvent = one.waitForEvent('download');
		await one.getByRole('button', { name: 'Unduh arsip-fiktif.pdf', exact: true }).click();
		const download = await downloadEvent;
		expect((await readFile((await download.path())!)).toString()).toContain('%PDF-1.4');
		await one.getByRole('button', { name: 'Versi terkini', exact: true }).click();
		await expect(one.getByRole('heading', { name: 'Pengiriman versi 2 · terkini', exact: true })).toBeVisible();
		await expect(one.getByRole('button', { name: 'Lepas tugas', exact: true })).toBeEnabled();
		await expect(one.getByText('Perlu peninjauan anomali', { exact: false })).toBeVisible();
		await mkdir('../docs/validation', { recursive: true });
		await one.evaluate(() => window.scrollTo(0, 0));
		await one.screenshot({ path: '../docs/validation/internal-desktop.png', fullPage: true });
		await one.setViewportSize({ width: 320, height: 780 });
		expect(await one.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
		await one.screenshot({ path: '../docs/validation/internal-mobile-320.png', fullPage: true });
		await one.getByRole('button', { name: 'Lepas tugas', exact: true }).click();
		await one.getByRole('button', { name: 'Batal', exact: true }).click();
		await expect(one.getByRole('dialog')).not.toBeVisible();
		await one.getByRole('button', { name: 'Lepas tugas', exact: true }).click();
		await one.getByLabel('Alasan pelepasan', { exact: true }).fill('Alih tugas fiktif untuk demonstrasi');
		let releases = 0;
		await one.route('**/api/v1/internal/assignments/*/release', async (route) => {
			releases++; const response = await route.fetch(); expect(response.status()).toBe(200); await route.abort();
		}, { times: 1 });
		await one.getByRole('button', { name: 'Ya, lepas tugas', exact: true }).click();
		await expect(one.getByRole('alert')).toContainText('Periksa sesi sebelum mengirim ulang');
		await one.getByRole('button', { name: 'Periksa data terbaru', exact: true }).click();
		await expect(one.getByText('Alasan: Alih tugas fiktif untuk demonstrasi', { exact: true })).toBeVisible();
		expect(releases).toBe(1);
		await two.getByRole('button', { name: 'Periksa data terbaru', exact: true }).click();
		await expect(two.getByRole('button', { name: 'Ambil tugas ini', exact: true })).toBeEnabled();
		await two.getByRole('button', { name: 'Ambil tugas ini', exact: true }).click();
		await expect(two.getByRole('button', { name: 'Lepas tugas', exact: true })).toBeEnabled();
		await one.getByRole('button', { name: 'Periksa data terbaru', exact: true }).click();
		await expect(one.getByRole('button', { name: 'Lepas tugas', exact: true })).toHaveCount(0);
		for (const key of ['l1', 'l2']) {
			const context = await browser.newContext(); const page = await context.newPage();
			try {
				await login(page, key); await openQueue(page, fixture.applications[key].number);
				await page.getByLabel('Nomor pengajuan', { exact: true }).fill(loan.number);
				await page.getByRole('button', { name: 'Cari pengajuan', exact: true }).click();
				await expect(page.getByText('Tidak ada pengajuan pada filter ini.', { exact: true })).toBeVisible();
				await page.getByLabel('Nomor pengajuan', { exact: true }).fill(fixture.applications[key].number);
				await page.getByRole('button', { name: 'Cari pengajuan', exact: true }).click();
				await expect(page.getByRole('button', { name: `Ambil ${fixture.applications[key].number}`, exact: true })).toBeEnabled();
				await page.getByRole('button', { name: `Ambil ${fixture.applications[key].number}`, exact: true }).click();
				await expect(page.getByRole('button', { name: 'Lepas tugas', exact: true })).toBeEnabled();
			} finally { await context.close(); }
		}
		await two.getByRole('button', { name: 'Teruskan ke penyetuju tingkat 1', exact: true }).click();
		await two.getByRole('button', { name: 'Konfirmasi tindakan', exact: true }).click();
		await expect(two.getByRole('alert').first()).toContainText('Catatan peninjauan anomali wajib');
		await two.getByLabel('Catatan / alasan revisi', { exact: true }).fill('Anomali fiktif sudah ditinjau manusia');
		await two.getByRole('button', { name: 'Teruskan ke penyetuju tingkat 1', exact: true }).click();
		await two.getByRole('button', { name: 'Konfirmasi tindakan', exact: true }).click();
		await expect(two.getByRole('heading', { name: loan.number, exact: true })).toHaveCount(0);
		const adminContext = await browser.newContext(); const admin = await adminContext.newPage();
		try { await login(admin, 'admin'); await admin.goto('/internal'); await expect(admin.getByRole('heading', { name: 'Halaman khusus peninjau', exact: true })).toBeVisible(); }
		finally { await adminContext.close(); }
	} finally { await oneContext.close(); await twoContext.close(); }
});
