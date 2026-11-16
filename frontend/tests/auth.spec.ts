import { expect, test } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { mkdir } from 'node:fs/promises';

test('cookie SPA: CSRF, daftar, masuk, muat ulang, keluar, dan akses anonim', async ({ page, context }) => {
	const email = `browser-${randomUUID()}@example.test`;
	const password = randomUUID();
	await page.goto('/');
	await expect(page.getByText('Belum masuk. Silakan gunakan akun Anda.')).toBeVisible();
	await expect(page).toHaveTitle('Masuk | Lendara');

	const cookies = await context.cookies('http://localhost:8000');
	expect(cookies.some((cookie) => cookie.name === 'XSRF-TOKEN' && !cookie.httpOnly && cookie.sameSite === 'Lax')).toBe(true);
	expect(cookies.some((cookie) => cookie.name === 'lendara_test_session' && cookie.httpOnly && cookie.domain === 'localhost')).toBe(true);

	const noCsrfStatus = await page.evaluate(async () => {
		const response = await fetch('http://localhost:8000/api/v1/auth/login', {
			method: 'POST', credentials: 'include',
			headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
			body: '{}'
		});
		return response.status;
	});
	expect(noCsrfStatus).toBe(419);

	await page.getByRole('button', { name: 'Buat akun', exact: true }).click();
	await page.getByLabel('Nama lengkap').fill('Nasabah Demonstrasi');
	await page.getByLabel('Email', { exact: true }).fill(email);
	await page.getByLabel('Kata sandi', { exact: true }).fill(password);
	await page.getByLabel('Konfirmasi kata sandi').fill(password);
	const registered = page.waitForResponse((response) => response.url().endsWith('/auth/register') && response.request().method() === 'POST');
	await page.getByRole('button', { name: 'Daftar akun', exact: true }).click();
	expect((await registered).status()).toBe(201);
	await expect(page.getByText('Pendaftaran berhasil. Silakan masuk.')).toBeVisible();
	const anonymousStatus = await page.evaluate(async () => (await fetch('http://localhost:8000/api/v1/me', { credentials: 'include', headers: { Accept: 'application/json' } })).status);
	expect(anonymousStatus).toBe(401);

	await page.getByLabel('Kata sandi', { exact: true }).fill('incorrect-password');
	await page.getByRole('button', { name: 'Masuk', exact: true }).click();
	await expect(page.getByText('Email atau kata sandi tidak sesuai.')).toBeVisible();
	await expect(page.getByLabel('Email', { exact: true })).toHaveValue(email);
	await expect(page.getByLabel('Kata sandi', { exact: true })).toHaveValue('');

	await page.getByLabel('Kata sandi', { exact: true }).fill(password);
	const loggedIn = page.waitForResponse((response) => response.url().endsWith('/auth/login') && response.request().method() === 'POST');
	await page.getByRole('button', { name: 'Masuk', exact: true }).click();
	const loginResponse = await loggedIn;
	expect(loginResponse.status()).toBe(200);
	expect(loginResponse.headers()['access-control-allow-origin']).toBe('http://localhost:5173');
	expect(loginResponse.headers()['access-control-allow-credentials']).toBe('true');
	const identity = (await loginResponse.json()).data;
	expect(Object.keys(identity).sort()).toEqual(['email', 'id', 'is_active', 'name', 'role']);
	expect(identity.role).toBe('nasabah');
	await expect(page.getByRole('heading', { name: 'Halo, Nasabah Demonstrasi.' })).toBeVisible();
	await page.reload();
	await expect(page.getByRole('heading', { name: 'Halo, Nasabah Demonstrasi.' })).toBeVisible();
	await expect(page).toHaveTitle('Beranda | Lendara');

	const omittedCookieStatus = await page.evaluate(async () => (await fetch('http://localhost:8000/api/v1/me', { credentials: 'omit', headers: { Accept: 'application/json' } })).status);
	expect(omittedCookieStatus).toBe(401);
	const logoutResponse = page.waitForResponse((response) => response.url().endsWith('/auth/logout') && response.request().method() === 'POST');
	await page.getByRole('button', { name: 'Keluar', exact: true }).click();
	expect((await logoutResponse).status()).toBe(204);
	const afterLogout = await page.evaluate(async () => (await fetch('http://localhost:8000/api/v1/me', { credentials: 'include', headers: { Accept: 'application/json' } })).status);
	expect(afterLogout).toBe(401);
	await page.getByLabel('Email', { exact: true }).fill('');
	await expect(page.getByLabel('Kata sandi', { exact: true })).toHaveValue('');
	await mkdir('../docs/validation', { recursive: true });
	await page.screenshot({ path: `../docs/validation/${process.env.RUNTIME_GRANTS_TEST==='1'?'runtime-':''}auth-desktop.png`, fullPage: true });
	await page.setViewportSize({ width: 320, height: 780 });
	expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
	await expect(page.getByRole('button', { name: 'Masuk', exact: true })).toBeVisible();
	await page.screenshot({ path: `../docs/validation/${process.env.RUNTIME_GRANTS_TEST==='1'?'runtime-':''}auth-mobile-320.png`, fullPage: true });
});

test('kegagalan jaringan tidak mengulang mutasi secara otomatis', async ({ page }) => {
	await page.goto('/');
	await expect(page.getByText('Belum masuk. Silakan gunakan akun Anda.')).toBeVisible();
	let posts = 0;
	await page.route('**/api/v1/auth/login', async (route) => { posts++; await route.abort(); });
	await page.getByLabel('Email', { exact: true }).fill('network@example.test');
	await page.getByLabel('Kata sandi', { exact: true }).fill('testing-only-password');
	await page.getByRole('button', { name: 'Masuk', exact: true }).click();
	await expect(page.getByRole('alert')).toContainText('Periksa sesi sebelum mengirim ulang');
	await expect(page.getByRole('button', { name: 'Masuk', exact: true })).toBeDisabled();
	expect(posts).toBe(1);
	await page.getByRole('button', { name: 'Periksa koneksi dan sesi', exact: true }).click();
	await expect(page.getByRole('button', { name: 'Masuk', exact: true })).toBeEnabled();
	expect(posts).toBe(1);
});
