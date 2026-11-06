export const statusLabels = {
	draft: 'Draf', revision_required: 'Perlu revisi', pending_verification: 'Menunggu verifikasi',
	pending_approval_1: 'Persetujuan tingkat 1', pending_approval_2: 'Persetujuan tingkat 2',
	approved: 'Disetujui', rejected: 'Ditolak'
} as const;

export type Application = {
	id: number; application_number: string; status: keyof typeof statusLabels;
	current_submission_id: number | null; loan_amount: string | null; tenor_months: number | null;
	purpose: string | null; monthly_income: string | null; monthly_expenses: string | null;
	existing_installments: string | null; created_at: string; updated_at: string;
};
export type ApplicationList = {
	data: Application[];
	meta: { current_page: number; last_page: number; total: number; per_page: number };
};
export type Profile = {
	name: string; identity_number: string | null; birth_date: string | null; address: string | null;
	phone: string | null; occupation_type: string | null; workplace_name: string | null; work_duration_months: number | null;
};
export const profileFields = [
	{ key: 'name', label: 'Nama lengkap', type: 'text', max: 150 },
	{ key: 'identity_number', label: 'NIK fiktif', type: 'text', max: 16 },
	{ key: 'birth_date', label: 'Tanggal lahir', type: 'date', max: undefined },
	{ key: 'phone', label: 'Nomor kontak fiktif', type: 'text', max: 30 },
	{ key: 'occupation_type', label: 'Jenis pekerjaan', type: 'text', max: 100 },
	{ key: 'workplace_name', label: 'Tempat bekerja', type: 'text', max: 150 },
	{ key: 'work_duration_months', label: 'Lama bekerja (bulan)', type: 'text', max: undefined }
] as const;
export const financeFields = [
	{ key: 'loan_amount', label: 'Jumlah pinjaman (Rp)' },
	{ key: 'monthly_income', label: 'Pendapatan per bulan (Rp)' },
	{ key: 'monthly_expenses', label: 'Pengeluaran di luar cicilan (Rp)' },
	{ key: 'existing_installments', label: 'Cicilan berjalan per bulan (Rp)' }
] as const;
export type ProfileForm = { [K in keyof Profile]: string };
export type DraftForm = { [K in typeof financeFields[number]['key'] | 'tenor_months' | 'purpose']: string };
export const blankProfile = (): ProfileForm => ({ name: '', identity_number: '', birth_date: '', address: '', phone: '', occupation_type: '', workplace_name: '', work_duration_months: '' });
export const blankDraft = (): DraftForm => ({ loan_amount: '', monthly_income: '', monthly_expenses: '', existing_installments: '', tenor_months: '', purpose: '' });
export const profileForm = (data: Profile): ProfileForm => Object.fromEntries(Object.keys(blankProfile()).map((key) => [key, String(data[key as keyof Profile] ?? '')])) as ProfileForm;
export const draftForm = (data: Application): DraftForm => Object.fromEntries(Object.keys(blankDraft()).map((key) => [key, String(data[key as keyof Application] ?? '')])) as DraftForm;
export function integerInput(value: string): number | string | null {
	if (value === '') return null;
	const number = Number(value);
	// Keep invalid input as text so JSON never turns NaN/Infinity into a silent null.
	return /^[0-9]+$/.test(value) && Number.isSafeInteger(number) ? number : value;
}
export function rupiah(value: string | null): string {
	if (value === null) return 'Belum diisi';
	const [whole, fraction = '00'] = value.split('.');
	return 'Rp ' + whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + fraction.padEnd(2, '0');
}
export function timeWib(value: string): string {
	return new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) + ' WIB';
}
