import { defineConfig } from '@playwright/test';
import base from './playwright.config';

export default defineConfig({ ...base, testIgnore: [], testMatch: ['model-flow.spec.ts','auth.spec.ts'], timeout: 300000,
 webServer: [
  { command:'powershell -ExecutionPolicy Bypass -File ../scripts/start-test-backend.ps1', url:'http://localhost:8000/up', reuseExistingServer:false, timeout:60000 },
  { command:'npm run dev', url:'http://localhost:5173', reuseExistingServer:false, timeout:120000 }
 ]
});
