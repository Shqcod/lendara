import { defineConfig } from '@playwright/test';
import model from './playwright.model.config';

export default defineConfig({ ...model,
 testMatch: ['model-flow.spec.ts', 'auth.spec.ts', 'admin.spec.ts', 'account-access.spec.ts'],
 webServer: [
  { command:'powershell -ExecutionPolicy Bypass -File ../scripts/start-runtime-test-backend.ps1', url:'http://localhost:8000/up', reuseExistingServer:false, timeout:60000 },
  { command:'npm run dev', url:'http://localhost:5173', reuseExistingServer:false, timeout:120000 }
 ]
});
