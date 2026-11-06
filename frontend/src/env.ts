import { defineEnvVars } from '@sveltejs/kit/env';

export const variables = defineEnvVars({
	PUBLIC_API_BASE_URL: {
		public: true,
		schema: (value) => {
			const url = new URL(value || 'http://localhost:8000');
			if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
				throw new Error('PUBLIC_API_BASE_URL harus berupa origin HTTP/HTTPS tanpa kredensial.');
			}
			return url.origin;
		}
	}
});
