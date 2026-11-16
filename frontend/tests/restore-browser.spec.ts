import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { readFile, writeFile } from 'node:fs/promises';

test('restore: dokumen privat, submit, worker model dan persetujuan berjenjang', async ({ page, context, browser }) => {
	test.setTimeout(360000);
	expect(process.env.RESTORE_BROWSER_TEST).toBe('1');
	const run = randomUUID();
	const restoreId = process.env.RESTORE_WORKSPACE_ID;
	expect(restoreId).toMatch(/^[a-f0-9-]{36}$/);
	const profile = ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', '../scripts/restore-demo.ps1', '-RestoreId', restoreId!];
	function probe(op: string, input: object = {}) {
		const result = spawnSync('powershell', [...profile, '-Action', 'BrowserProbe'], {
			env: process.env, input: JSON.stringify({ op, run_id: run, ...input }), encoding: 'utf8', timeout: 60000
		});
		if (result.status !== 0) throw new Error('Restore probe failed; no credentials or raw output included.');
		return JSON.parse(result.stdout.trim().split(/\r?\n/).at(-1)!);
	}
	const api = 'http://127.0.0.1:8800';
	const password = randomUUID();
	const email = `restore-${randomUUID()}@example.test`;
	const apiTargets = new Set<string>();
	page.on('request', (request) => {
		if (request.url().includes('/api/v1/') || request.url().includes('/sanctum/')) apiTargets.add(new URL(request.url()).origin);
	});
	await page.goto('/');
	await expect(page.getByText('Belum masuk. Silakan gunakan akun Anda.')).toBeVisible();
	const cookies = await context.cookies(api);
	expect(cookies.some(c => c.name === 'lendara_restore_session' && c.httpOnly && c.domain === '127.0.0.1')).toBe(true);
	expect(cookies.some(c => c.name === 'XSRF-TOKEN' && !c.httpOnly && c.sameSite === 'Lax')).toBe(true);
	expect((await context.cookies('http://localhost:8000')).length).toBe(0);
	const noCsrf = await page.evaluate(async (base) => (await fetch(base + '/api/v1/auth/login', {
		method: 'POST', credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: '{}'
	})).status, api);
	expect(noCsrf).toBe(419);
	await page.getByRole('button', { name: 'Buat akun', exact: true }).click();
	await page.getByLabel('Nama lengkap').fill('Nasabah Restore Fiktif');
	await page.getByLabel('Email', { exact: true }).fill(email);
	await page.getByLabel('Kata sandi', { exact: true }).fill(password);
	await page.getByLabel('Konfirmasi kata sandi').fill(password);
	const register = page.waitForResponse(r => r.url().endsWith('/auth/register') && r.request().method() === 'POST');
	await page.getByRole('button', { name: 'Daftar akun', exact: true }).click();
	expect((await register).status()).toBe(201);
	await expect(page.getByText('Pendaftaran berhasil. Silakan masuk.')).toBeVisible();
	const me = () => page.evaluate(async (base) => (await fetch(base + '/api/v1/me', { credentials: 'include', headers: { Accept: 'application/json' } })).status, api);
	expect(await me()).toBe(401);
	await page.getByLabel('Kata sandi', { exact: true }).fill(password);
	const login = page.waitForResponse(r => r.url().endsWith('/auth/login') && r.request().method() === 'POST');
	await page.getByRole('button', { name: 'Masuk', exact: true }).click();
	const response = await login;
	expect(response.status()).toBe(200);
	expect(response.headers()['access-control-allow-origin']).toBe('http://127.0.0.1:5174');
	expect(response.headers()['access-control-allow-credentials']).toBe('true');
	await page.getByRole('link', { name: 'Buka ruang nasabah' }).click();
	await expect(page.getByRole('button', { name: 'Buat draf pengajuan' })).toBeEnabled();
	const created = page.waitForResponse(r => r.url().endsWith('/applications') && r.request().method() === 'POST');
	await page.getByRole('button', { name: 'Buat draf pengajuan' }).click();
	const draft = await created;
	expect(draft.status()).toBe(201);
	const id: number = (await draft.json()).data.id;
	await page.getByLabel('Jumlah pinjaman (Rp)', { exact: true }).fill('20000000.01');
	await page.getByLabel('Pendapatan per bulan (Rp)', { exact: true }).fill('8000000');
	await page.getByLabel('Pengeluaran di luar cicilan (Rp)', { exact: true }).fill('3000000');
	await page.getByLabel('Cicilan berjalan per bulan (Rp)', { exact: true }).fill('0');
	await page.getByLabel('Tenor (bulan)', { exact: true }).fill('12');
	await page.getByLabel('Tujuan pinjaman', { exact: true }).fill('Latihan runtime restore fiktif');
	const saved = page.waitForResponse(r => r.url().endsWith(`/applications/${id}`) && r.request().method() === 'PATCH');
	await page.getByRole('button', { name: 'Simpan draf', exact: true }).click();
	const savedResponse = await saved;
	expect(savedResponse.status()).toBe(200);
	const body = await savedResponse.json();
	expect(body.data.loan_amount).toBe('20000000.01');
	expect(JSON.stringify(body)).not.toMatch(/is_anomaly|"score"|artifact_hash|anomaly_checks/);
	await page.reload();
	await expect(page.getByLabel('Jumlah pinjaman (Rp)', { exact: true })).toHaveValue('20000000.01');
	expect(await me()).toBe(200);
	for (const [label, value] of Object.entries({
		'NIK fiktif': '000' + Date.now(), 'Tanggal lahir': '1995-01-15', 'Nomor kontak fiktif': '+62000000003',
		'Jenis pekerjaan': 'Karyawan fiktif', 'Tempat bekerja': 'Perusahaan Fiktif',
		'Lama bekerja (bulan)': '24', 'Alamat fiktif': 'Alamat latihan restore fiktif'
	})) await page.getByLabel(label, { exact: true }).fill(value);
	await page.getByRole('button', { name: 'Simpan profil', exact: true }).click();
	await expect(page.getByText('Profil tersimpan. Data pengiriman sebelumnya tetap dipertahankan.')).toBeVisible();
	const panel = page.getByRole('region', { name: 'Dokumen pengajuan', exact: true });
	const bytes = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n');
	for (const code of ['D01', 'D02', 'D03']) {
		const option = panel.getByLabel('Jenis dokumen', { exact: true }).locator('option').filter({ hasText: code });
		await panel.getByLabel('Jenis dokumen', { exact: true }).selectOption((await option.getAttribute('value'))!);
		await panel.getByLabel('Berkas fiktif (PDF, JPG, JPEG, PNG)', { exact: true }).setInputFiles({ name: `restore-fiktif-${code}.pdf`, mimeType: 'application/pdf', buffer: bytes });
		const upload = page.waitForResponse(r => r.url().endsWith(`/applications/${id}/documents`) && r.request().method() === 'POST');
		await panel.getByRole('button', { name: 'Unggah versi baru' }).click();
		expect((await upload).status()).toBe(201);
		await expect(panel.getByRole('button', { name: `Unduh ${code}`, exact: true })).toBeEnabled();
	}
	const downloaded = page.waitForEvent('download');
	await panel.getByRole('button', { name: 'Unduh D01', exact: true }).click();
	const download = await downloaded;
	expect(await readFile((await download.path())!)).toEqual(bytes);
	await page.getByRole('link', { name: 'Tinjau dan lihat pengiriman', exact: true }).click();
	await page.getByRole('button', { name: 'Tinjau dan kirim', exact: true }).click();
	await page.getByRole('checkbox').check();
	const submitted = page.waitForResponse(r => r.url().endsWith('/submissions') && r.request().method() === 'POST');
	await page.getByRole('button', { name: 'Ya, kirim pengajuan', exact: true }).click();
	const submission = await submitted;
	expect(submission.status()).toBe(202);
	expect(JSON.stringify(await submission.json())).not.toMatch(/is_anomaly|"score"|artifact_hash|anomaly_checks/);
	const owned = { application_id: id, customer_email: email };
	const queue = probe('route-job', owned).queue;
	const worker = spawnSync('powershell', [...profile, '-Action', 'BrowserWorker', '-QueueName', queue], { env: process.env, encoding: 'utf8', timeout: 90000 });
	if (worker.status !== 0) throw new Error('Scoped restore worker failed; inspect local logs.');
	const before = probe('inspect', owned);
	expect(before.status).toBe('pending_verification');
	expect(before.submission_count).toBe(1);
	expect(before.document_count).toBe(3);
	expect(before.document_link_count).toBe(3);
	expect(before.private_integrity_passed).toBe(true);
	expect(before.checks).toEqual([{ status: 'succeeded', model: 'if-v1-b-seed17', score_flag_consistent: true }]);
	const reviewers = probe('reviewers', { password });
	const reviewerContexts = [];
	const applicationNumber = body.data.application_number as string;
	async function act(reviewer: Page, button: string, confirmation: string) {
		await reviewer.getByRole('button', { name: button, exact: true }).click();
		await reviewer.getByRole('button', { name: confirmation, exact: true }).click();
		await expect(reviewer.getByRole('heading', { name: applicationNumber, exact: true })).toHaveCount(0);
	}
	try {
		for (const key of ['verifier', 'one', 'two']) {
			const reviewerContext = await browser.newContext();
			reviewerContexts.push(reviewerContext);
			const reviewer = await reviewerContext.newPage();
			await reviewer.goto('/');
			await expect(reviewer.getByText('Belum masuk. Silakan gunakan akun Anda.')).toBeVisible();
			await reviewer.getByLabel('Email', { exact: true }).fill(reviewers[key]);
			await reviewer.getByLabel('Kata sandi', { exact: true }).fill(password);
			await reviewer.getByRole('button', { name: 'Masuk', exact: true }).click();
			await reviewer.getByRole('link', { name: 'Buka antrean internal' }).click();
			await expect(reviewer.getByRole('button', { name: 'Cari pengajuan', exact: true })).toBeEnabled();
			await reviewer.getByLabel('Nomor pengajuan', { exact: true }).fill(applicationNumber);
			await reviewer.getByRole('button', { name: 'Cari pengajuan', exact: true }).click();
			await reviewer.getByRole('button', { name: `Lihat ${applicationNumber}`, exact: true }).click();
			await reviewer.getByRole('button', { name: 'Ambil tugas ini', exact: true }).click();
			await expect(reviewer.getByRole('button', { name: 'Lepas tugas', exact: true })).toBeEnabled();
			if (key === 'verifier') {
				await expect(reviewer.getByText('Percobaan 1 · Selesai', { exact: true })).toBeVisible();
				await reviewer.getByLabel('Catatan / alasan revisi', { exact: true }).fill('Dokumen fiktif dan hasil model telah ditinjau manusia.');
				await act(reviewer, 'Teruskan ke penyetuju tingkat 1', 'Konfirmasi tindakan');
			} else {
				await act(reviewer, key === 'one' ? 'Setuju dan teruskan ke tingkat 2' : 'Setujui pengajuan final', 'Konfirmasi keputusan');
			}
		}
	} finally { await Promise.all(reviewerContexts.map(c => c.close())); }
	const after = probe('inspect', owned);
	expect(after.status).toBe('approved');
	expect(after.history).toEqual(['pending_verification', 'pending_approval_1', 'pending_approval_2', 'approved']);
	expect(after.approved_levels).toEqual([1, 2]);
	expect(after.active_assignments).toBe(0);
	expect(after.immutable_sha256).toBe(before.immutable_sha256);
	expect(after.private_integrity_passed).toBe(true);
	await page.reload();
	await expect(page.getByText('Disetujui', { exact: true }).first()).toBeVisible();
	await page.goto('/');
	await expect(page.getByRole('button', { name: 'Keluar', exact: true })).toBeVisible();
	await page.getByRole('button', { name: 'Keluar', exact: true }).click();
	await expect(page.getByText('Anda sudah keluar dengan aman.')).toBeVisible();
	expect(await me()).toBe(401);
	expect([...apiTargets]).toEqual([api]);
	await page.getByLabel('Email', { exact: true }).fill('');
	await expect(page.getByLabel('Kata sandi', { exact: true })).toHaveValue('');
	await page.setViewportSize({ width: 320, height: 780 });
	expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
	await page.screenshot({ path: '../docs/validation/restore-auth-mobile-320.png', fullPage: true });
	await writeFile('../docs/validation/restore-browser-2026-11-16.json', JSON.stringify({
		browser: 'Chrome', csrf_without_token: 419, registration: 201, login: 200,
		cookie_host_isolated: true, cors_credentials_verified: true, draft_decimal_persisted_after_reload: true,
		session_reload_verified: true, logout_anonymous: 401, source_api_called: false, mobile_320_overflow: false,
		fixture_accounts_and_application_retained: true, restored_populated_dataset_verified: false,
		ui_uploaded_required_documents: 3, private_download_matches: true, submission_status: 202,
		worker_scoped_to_owned_job: true, model: 'if-v1-b-seed17', prediction_succeeded: true,
		immutable_snapshot_document_fingerprint_unchanged: true, private_integrity_passed: true,
		approval_levels: [1, 2], final_status: 'approved', submission_worker_approval_verified: true
	}, null, 2) + '\n');
});
