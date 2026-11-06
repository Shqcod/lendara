import { defineConfig } from '@playwright/test';

export default defineConfig({
	testDir: './tests',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: 'list',
	timeout: 60000,
	expect: { timeout: 15000 },
	use: {
		baseURL: 'http://localhost:5173',
		browserName: 'chromium',
		channel: 'chrome',
		viewport: { width: 1366, height: 900 },
		trace: 'off', screenshot: 'off', video: 'off'
	},
	webServer: [
		{
			command: 'powershell -ExecutionPolicy Bypass -File ../scripts/start-test-backend.ps1 -PrepareStubModel',
			url: 'http://localhost:8000/up',
			reuseExistingServer: false,
			timeout: 30000
		},
		{
			command: 'npm run dev',
			url: 'http://localhost:5173',
			reuseExistingServer: false,
			timeout: 30000
		}
	]
});
