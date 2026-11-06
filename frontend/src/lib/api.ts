import { PUBLIC_API_BASE_URL } from '$app/env/public';

export type User = {
	id: number;
	name: string;
	email: string;
	role: 'nasabah' | 'verifikator' | 'penyetuju_1' | 'penyetuju_2' | 'admin';
	is_active: boolean;
};

export class ApiError extends Error {
	constructor(
		public status: number,
		message: string,
		public fields: Record<string, string[]> = {}
	) { super(message); }
}

const base = () => PUBLIC_API_BASE_URL;

export async function api<T>(path: string, body?: Record<string, unknown>, method: 'GET' | 'POST' | 'PATCH' | 'DELETE' = body ? 'POST' : 'GET'): Promise<T> {
	const mutating = method !== 'GET';
	const headers: Record<string, string> = { Accept: 'application/json' };
	if (body) {
		headers['Content-Type'] = 'application/json';
	}
	if (mutating) {
		const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));
		if (cookie) headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
	}
	let response: Response;
	try {
		response = await fetch(base() + path, {
			method, credentials: 'include', headers,
			body: body ? JSON.stringify(body) : undefined
		});
	} catch {
		throw new ApiError(0, mutating
			? 'Koneksi terputus. Periksa sesi sebelum mengirim ulang; permintaan mungkin sudah diproses.'
			: 'Layanan belum dapat dihubungi. Periksa koneksi lalu coba lagi.');
	}
	const data = response.status === 204 ? null : await response.json().catch(() => null);
	if (!response.ok) throw new ApiError(response.status, data?.message || 'Permintaan gagal diproses.', data?.errors || {});
	return data as T;
}

export const csrf = () => api<null>('/sanctum/csrf-cookie');
export const currentUser = () => api<{ data: User }>('/api/v1/me');
