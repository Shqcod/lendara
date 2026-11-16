import adapter from '@sveltejs/adapter-auto';
import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vite';

export default defineConfig({
	plugins: [sveltekit({ adapter: adapter(), outDir: '.svelte-kit-restore' })],
	cacheDir: 'node_modules/.vite-restore',
	server: { host: '127.0.0.1', port: 5174, strictPort: true }
});
