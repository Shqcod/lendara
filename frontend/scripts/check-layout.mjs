// Visual QA without authentication; this does not prove cookie/CSRF integration.
import { chromium } from '@playwright/test';
import { mkdir } from 'node:fs/promises';

const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
    await page.goto('http://localhost:5173');
    await page.getByRole('heading', { name: 'Masuk ke akun Anda' }).waitFor();
    await page.getByRole('alert').waitFor();
    if (await page.title() !== 'Masuk | Lendara') throw new Error('Judul tidak sesuai.');
    await mkdir('../docs/validation', { recursive: true });
    await page.screenshot({ path: '../docs/validation/setup-preview-desktop.png', fullPage: true });
    await page.setViewportSize({ width: 320, height: 780 });
    if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)) throw new Error('Overflow horizontal pada 320px.');
    await page.getByLabel('Email', { exact: true }).focus();
    await page.keyboard.press('Tab');
    if (!await page.getByLabel('Kata sandi', { exact: true }).evaluate((element) => element === document.activeElement)) throw new Error('Navigasi keyboard tidak sesuai.');
    await page.screenshot({ path: '../docs/validation/setup-preview-mobile-320.png', fullPage: true });
    console.log('PASS: Chrome, judul Lendara, tampilan desktop/320px, fokus keyboard, dan pesan layanan tidak terhubung. Autentikasi belum dibuktikan.');
} finally {
    await browser.close();
}
