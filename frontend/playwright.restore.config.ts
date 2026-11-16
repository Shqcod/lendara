import { defineConfig } from '@playwright/test';

if (process.env.RESTORE_BROWSER_TEST !== '1') {
	throw new Error('Gunakan scripts/restore-browser-smoke.py untuk menyalakan layanan restore milik runner.');
}

export default defineConfig({
	testDir: './tests', testMatch: 'restore-browser.spec.ts',
	workers: 1, retries: 0, timeout: 120000, reporter: 'list',
	expect: { timeout: 15000 },
	use: {
		baseURL: 'http://127.0.0.1:5174', browserName: 'chromium', channel: 'chrome',
		viewport: { width: 1366, height: 900 }, trace: 'off', screenshot: 'off', video: 'off'
	}
});
