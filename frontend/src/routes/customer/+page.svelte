<script lang="ts">
	import { onMount } from 'svelte';
	import { api, ApiError, csrf, currentUser, downloadDocument, type User } from '#lib/api.ts';
	import DocumentPanel from '#lib/DocumentPanel.svelte';
	import type { DocumentType, DocumentVersion } from '#lib/documents.ts';
	import { blankDraft, blankProfile, draftForm, profileForm, profileFields, financeFields, integerInput, rupiah, timeWib, statusLabels, type Application, type ApplicationList, type Profile } from '#lib/workspace.ts';

	let user = $state<User | null>(null);
	let active = $state<Application | null>(null);
	let history = $state<ApplicationList | null>(null);
	let documentTypes = $state<DocumentType[]>([]);
	let documents = $state<DocumentVersion[]>([]);
	let profile = $state(blankProfile());
	let savedProfile = $state(blankProfile());
	let draft = $state(blankDraft());
	let savedDraft = $state(blankDraft());
	let ready = $state(false);
	let busy = $state(false);
	let uncertain = $state(false);
	let authenticated = $state(true);
	let message = $state('');
	let failed = $state(false);
	let errors = $state<Record<string, string[]>>({});
	let errorArea = $state('');
	let filter = $state('');
	let page = $state(1);
	let dialog: HTMLDialogElement;
	const profileDirty = $derived(JSON.stringify(profile) !== JSON.stringify(savedProfile));
	const draftDirty = $derived(JSON.stringify(draft) !== JSON.stringify(savedDraft));
	const editable = $derived(active && ['draft', 'revision_required'].includes(active.status));
	const blocked = $derived(busy || uncertain || !ready || !authenticated);
	const activeStatuses = ['draft', 'revision_required', 'pending_verification', 'pending_approval_1', 'pending_approval_2'];
	const historyUrl = () => `/api/v1/applications?page=${page}&per_page=5${filter ? `&status=${filter}` : ''}`;

	function showError(error: unknown, area = '') {
		failed = true;
		message = error instanceof Error ? error.message : 'Permintaan gagal diproses.';
		errors = error instanceof ApiError ? error.fields : {};
		errorArea = area;
		if (error instanceof ApiError && error.status === 0) uncertain = true;
		if (error instanceof ApiError && [401, 403, 419].includes(error.status)) {
			authenticated = false;
			message += ' Isian tetap ada di halaman ini. Masuk kembali lalu periksa sesi sebelum menyimpan.';
		}
	}

	async function load(keepEdits = true) {
		busy = true;
		try {
			await csrf();
			const identity = (await currentUser()).data;
			const changedAccount = user !== null && user.id !== identity.id;
			if (changedAccount) {
				profile = blankProfile(); savedProfile = blankProfile(); draft = blankDraft(); savedDraft = blankDraft();
				active = null; history = null; documents = []; documentTypes = []; ready = false;
			}
			user = identity;
			authenticated = true;
			if (user.role !== 'nasabah') { ready = false; return false; }
			const [p, rows, ...states] = await Promise.all([
				api<{ data: Profile }>('/api/v1/customer/profile'),
				api<ApplicationList>(historyUrl()),
				...activeStatuses.map((status) => api<ApplicationList>(`/api/v1/applications?status=${status}&per_page=1`))
			]);
			const nextProfile = profileForm(p.data);
			if (!ready || !keepEdits || !profileDirty) profile = { ...nextProfile };
			savedProfile = { ...nextProfile };
			const next = states.flatMap((state) => state.data)[0] || null;
			const [types, files] = await Promise.all([
				api<{ data: DocumentType[] }>('/api/v1/document-types'),
				next ? api<{ data: DocumentVersion[] }>(`/api/v1/applications/${next.id}/documents`) : Promise.resolve({ data: [] })
			]);
			documentTypes = types.data; documents = files.data;
			const nextDraft = next ? draftForm(next) : blankDraft();
			if (!ready || !keepEdits || !draftDirty || active?.id !== next?.id) draft = { ...nextDraft };
			savedDraft = { ...nextDraft };
			active = next; history = rows; ready = true; uncertain = false; errors = {}; failed = false;
			message = changedAccount ? 'Akun berubah. Data akun terbaru telah dimuat.' : 'Data terbaru berhasil dimuat. Isian yang belum disimpan tetap dipertahankan.';
			return true;
		} catch (error) { showError(error); return false; }
		finally { busy = false; }
	}

	onMount(() => { void load(false); });

	async function sameAccount(): Promise<boolean> {
		const identity = (await currentUser()).data;
		if (identity.id !== user?.id) { await load(false); return false; }
		return true;
	}

	async function saveProfile(event: SubmitEvent) {
		event.preventDefault();
		if (blocked) return;
		busy = true; errors = {};
		try {
			if (!await sameAccount()) return;
			const body = Object.fromEntries(Object.entries(profile).map(([key, value]) => [key, key === 'work_duration_months' ? integerInput(value) : value === '' && key !== 'name' ? null : value]));
			const result = await api<{ data: Profile }>('/api/v1/customer/profile', body, 'PATCH');
			profile = profileForm(result.data); savedProfile = { ...profile };
			if (user) user.name = profile.name;
			failed = false; message = 'Profil tersimpan. Data pengiriman sebelumnya tetap dipertahankan.';
		} catch (error) { showError(error, 'profile'); }
		finally { busy = false; }
	}

	async function createDraft() {
		if (blocked || active) return;
		busy = true; errors = {};
		try {
			if (!await sameAccount()) return;
			await api('/api/v1/applications', {});
			if (await load()) message = 'Draf baru dibuat. Anda dapat menyimpan sebagian isian terlebih dahulu.';
		} catch (error) { showError(error, 'draft'); }
		finally { busy = false; }
	}

	async function saveDraft(event: SubmitEvent) {
		event.preventDefault();
		if (blocked || !active || !editable) return;
		busy = true; errors = {};
		try {
			if (!await sameAccount()) return;
			const body = Object.fromEntries(Object.entries(draft).map(([key, value]) => [key, value === '' ? null : key === 'tenor_months' ? integerInput(value) : value]));
			const result = await api<{ data: Application }>(`/api/v1/applications/${active.id}`, body, 'PATCH');
			active = result.data; draft = draftForm(active); savedDraft = { ...draft };
			failed = false; message = 'Draf tersimpan. Pengajuan belum dikirim untuk ditinjau.';
			history = await api<ApplicationList>(historyUrl());
		} catch (error) { showError(error, 'draft'); }
		finally { busy = false; }
	}

	async function deleteDraft() {
		if (blocked || !active || active.status !== 'draft' || active.current_submission_id !== null) return;
		busy = true;
		try {
			if (!await sameAccount()) return;
			await api(`/api/v1/applications/${active.id}`, undefined, 'DELETE');
			dialog.close(); active = null; draft = blankDraft(); savedDraft = blankDraft();
			if (await load()) message = 'Draf yang belum dikirim telah dihapus.';
		} catch (error) { dialog.close(); showError(error, 'draft'); }
		finally { busy = false; }
	}

	async function loadHistory(nextPage = 1) {
		if (busy) return;
		busy = true; page = nextPage;
		try { history = await api<ApplicationList>(historyUrl()); }
		catch (error) { showError(error); }
		finally { busy = false; }
	}

	async function uploadDocument(type: number, file: File): Promise<boolean> {
		if (blocked || !active || !editable) return false;
		busy = true; errors = {};
		try {
			if (!await sameAccount()) return false;
			const form = new FormData(); form.append('document_type_id', String(type)); form.append('file', file);
			await api(`/api/v1/applications/${active.id}/documents`, form);
			documents = (await api<{ data: DocumentVersion[] }>(`/api/v1/applications/${active.id}/documents`)).data;
			failed = false; message = 'Versi dokumen baru tersimpan. Pengajuan belum dikirim untuk ditinjau.';
			return true;
		} catch (error) { showError(error, 'documents'); return false; }
		finally { busy = false; }
	}

	async function saveDownload(document: DocumentVersion) {
		if (blocked) return;
		busy = true;
		try {
			if (!await sameAccount()) return;
			const blob = await downloadDocument(document.id);
			const url = URL.createObjectURL(blob);
			const link = window.document.createElement('a'); link.href = url; link.download = document.original_filename;
			link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
			failed = false; message = 'Unduhan dokumen siap.';
		} catch (error) { showError(error, 'documents'); }
		finally { busy = false; }
	}
</script>

<svelte:head><title>Ruang Nasabah | Lendara</title></svelte:head>

<div class="shell workspace">
	<header><a class="brand" href="/" aria-label="Lendara beranda"><span class="brand-mark" aria-hidden="true">L</span>Lendara<span class="brand-dot">.</span></a><a class="account-link" href="/">Akun &amp; keluar</a></header>
	<main class="workspace-main" aria-busy={busy}>
		<div class="page-intro"><div><p class="eyebrow">RUANG NASABAH</p><h1>Langkah Anda, <em>tersimpan.</em></h1><p class="muted">Lengkapi profil fiktif dan siapkan draf pengajuan Anda.</p></div><button class="secondary refresh" onclick={() => load()} disabled={busy}>Periksa sesi dan data terbaru</button></div>
		{#if message}<div class:error={failed} class="notice" role={failed ? 'alert' : 'status'}>{message}</div>{/if}
		{#if !authenticated}<p class="session-note"><a href="/" target="_blank" rel="noreferrer">Masuk kembali di tab baru</a>, kemudian periksa sesi di halaman ini. Isian tidak dikirim ulang otomatis.</p>{/if}
		{#if user && user.role !== 'nasabah'}<section class="panel"><h2>Halaman khusus nasabah</h2><p>Akun internal tidak memiliki akses ke formulir nasabah.</p><a href="/">Kembali ke akun</a></section>
		{:else if !ready}<p role="status">{busy ? 'Memuat data Anda…' : 'Data belum dapat dimuat. Gunakan tombol periksa sesi untuk mencoba kembali.'}</p>
		{:else}
			<section class="panel active-panel" aria-labelledby="active-title">
				<div><p class="eyebrow">PENGAJUAN AKTIF</p><h2 id="active-title">{active ? statusLabels[active.status] : 'Siapkan langkah pertama Anda'}</h2>
				{#if active}<p class="reference">{active.application_number}</p><p class="muted">Terakhir disimpan {timeWib(active.updated_at)} · {rupiah(active.loan_amount)}</p>
				{:else}<p class="muted">Belum ada pengajuan aktif. Draf dapat disimpan sebelum seluruh isian lengkap.</p>{/if}</div>
				{#if !active}<button class="primary new-draft" onclick={createDraft} disabled={blocked}>Buat draf pengajuan <span aria-hidden="true">＋</span></button>{:else}<span class="status-badge">Satu pengajuan aktif</span>{/if}
			</section>
			<div class="editor-grid">
				<section class="panel" aria-labelledby="profile-title">
					<p class="eyebrow">01 · IDENTITAS</p><h2 id="profile-title">Profil Anda</h2><p class="muted">Gunakan identitas dan kontak fiktif. Profil dapat dilengkapi bertahap.</p>
					<form onsubmit={saveProfile}>
						{#each profileFields as field}
							<label for={`profile-${field.key}`}>{field.label}</label>
							<input id={`profile-${field.key}`} name={field.key} type={field.type} bind:value={profile[field.key]} maxlength={field.max} required={field.key === 'name'} inputmode={field.key === 'work_duration_months' ? 'numeric' : undefined} aria-invalid={errorArea === 'profile' && !!errors[field.key]} aria-describedby={errorArea === 'profile' && errors[field.key] ? `profile-${field.key}-error` : undefined} />
							{#if errorArea === 'profile' && errors[field.key]}<p id={`profile-${field.key}-error`} class="field-error">{errors[field.key][0]}</p>{/if}
						{/each}
						<label for="profile-address">Alamat fiktif</label><textarea id="profile-address" bind:value={profile.address} rows="3" maxlength="16000" aria-invalid={errorArea === 'profile' && !!errors.address} aria-describedby={errorArea === 'profile' && errors.address ? 'profile-address-error' : undefined}></textarea>
						{#if errorArea === 'profile' && errors.address}<p id="profile-address-error" class="field-error">{errors.address[0]}</p>{/if}
						<p class="save-indicator">{profileDirty ? 'Ada perubahan profil yang belum disimpan.' : 'Profil sesuai data yang tersimpan.'}</p>
						<button class="primary" type="submit" disabled={blocked}>Simpan profil</button>
					</form>
				</section>
				<section class="panel draft-panel" aria-labelledby="draft-title">
					<p class="eyebrow">02 · PINJAMAN &amp; KEUANGAN</p><h2 id="draft-title">Draf pengajuan</h2>
					{#if active && editable}
						<p class="muted">Kosongkan isian yang belum siap. Angka rupiah memakai titik desimal, tanpa pemisah ribuan.</p>
						<form onsubmit={saveDraft}>
							{#each financeFields as field}
								<label for={`draft-${field.key}`}>{field.label}</label><input id={`draft-${field.key}`} name={field.key} type="text" inputmode="decimal" bind:value={draft[field.key]} placeholder="Contoh: 20000000.00" aria-invalid={errorArea === 'draft' && !!errors[field.key]} aria-describedby={errorArea === 'draft' && errors[field.key] ? `draft-${field.key}-error` : undefined} />
								{#if errorArea === 'draft' && errors[field.key]}<p id={`draft-${field.key}-error`} class="field-error">{errors[field.key][0]}</p>{/if}
							{/each}
							<label for="draft-tenor">Tenor (bulan)</label><input id="draft-tenor" name="tenor_months" type="text" inputmode="numeric" bind:value={draft.tenor_months} aria-invalid={errorArea === 'draft' && !!errors.tenor_months} aria-describedby={errorArea === 'draft' && errors.tenor_months ? 'draft-tenor-error' : undefined} />
							{#if errorArea === 'draft' && errors.tenor_months}<p id="draft-tenor-error" class="field-error">{errors.tenor_months[0]}</p>{/if}
							<label for="draft-purpose">Tujuan pinjaman</label><textarea id="draft-purpose" bind:value={draft.purpose} rows="3" maxlength="16000" aria-invalid={errorArea === 'draft' && !!errors.purpose} aria-describedby={errorArea === 'draft' && errors.purpose ? 'draft-purpose-error' : undefined}></textarea>
							{#if errorArea === 'draft' && errors.purpose}<p id="draft-purpose-error" class="field-error">{errors.purpose[0]}</p>{/if}
							<p class="save-indicator">{draftDirty ? 'Ada perubahan draf yang belum disimpan.' : 'Draf sesuai data yang tersimpan.'}</p>
							<button class="primary" type="submit" disabled={blocked}>Simpan draf</button>
						</form>
						{#if active.status === 'draft' && active.current_submission_id === null}<button class="danger" onclick={() => dialog.showModal()} disabled={blocked}>Hapus draf</button>{/if}
					{:else if active}<p class="muted">Pengajuan sedang ditinjau. Data keuangan hanya dapat diubah saat draf atau diminta revisi.</p>
					{:else}<p class="muted">Buat draf terlebih dahulu untuk mulai mengisi pinjaman dan keuangan.</p>{/if}
					<div class="next-steps"><span>04 · Tinjau &amp; kirim</span><p>Pengiriman untuk peninjauan tersedia pada tahap pengembangan berikutnya.</p></div>
				</section>
			</div>
			{#if active}{#key `${user?.id}-${active.id}`}
				<DocumentPanel types={documentTypes} {documents} editable={!!editable} {blocked} errors={errorArea === 'documents' ? errors : {}} onupload={uploadDocument} ondownload={saveDownload} />
			{/key}{/if}
			<section class="panel history" aria-labelledby="history-title">
				<div class="history-heading"><div><p class="eyebrow">PENGAJUAN ANDA</p><h2 id="history-title">Daftar pengajuan</h2></div><div><label for="status-filter">Filter status</label><select id="status-filter" bind:value={filter} onchange={() => loadHistory()} disabled={busy}><option value="">Semua status</option>{#each Object.entries(statusLabels) as [status, label]}<option value={status}>{label}</option>{/each}</select></div></div>
				{#if history?.data.length}<div class="table-scroll"><table><thead><tr><th>Nomor</th><th>Status</th><th>Jumlah pinjaman</th><th>Disimpan (WIB)</th></tr></thead><tbody>{#each history.data as row}<tr><td class="reference">{row.application_number}</td><td><span class="status-badge">{statusLabels[row.status]}</span></td><td>{rupiah(row.loan_amount)}</td><td>{timeWib(row.updated_at)}</td></tr>{/each}</tbody></table></div>{:else}<p class="muted">Belum ada pengajuan pada status ini.</p>{/if}
				{#if history}<div class="pagination"><span>{history.meta.total} pengajuan · Halaman {history.meta.current_page} dari {history.meta.last_page}</span><div><button onclick={() => loadHistory(page - 1)} disabled={busy || page <= 1}>Sebelumnya</button><button onclick={() => loadHistory(page + 1)} disabled={busy || page >= history.meta.last_page}>Berikutnya</button></div></div>{/if}
			</section>
		{/if}
	</main>
	<footer><span>© 2026 Lendara · Prototipe akademik</span><span>Seluruh data demonstrasi bersifat fiktif.</span></footer>
</div>

<dialog bind:this={dialog} oncancel={(event) => { if (busy) event.preventDefault(); }} aria-labelledby="delete-title"><h2 id="delete-title">Hapus draf ini?</h2><p>Hanya draf yang belum pernah dikirim dapat dihapus. Draf ini akan dihapus setelah Anda mengonfirmasi.</p><div class="dialog-actions"><button class="secondary" onclick={() => dialog.close()} disabled={busy}>Batal</button><button class="danger" onclick={deleteDraft} disabled={blocked}>Ya, hapus draf</button></div></dialog>

<style>
	.workspace-main { display: flex; flex-direction: column; gap: 22px; align-items: stretch; padding-top: 20px; }
	.page-intro, .active-panel, .history-heading { display: flex; align-items: center; justify-content: space-between; gap: 22px; }
	h1 { font-size: 44px; margin: 12px 0; letter-spacing: -1px; }
	.page-intro .muted { margin: 0; }
	.refresh { width: auto; flex-shrink: 0; }
	.panel { background: #fff; border: 1px solid #e2e7dc; border-radius: 14px; padding: 28px; min-width: 0; }
	.editor-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
	.panel h2 { font-size: 22px; }
	.account-link { color: #315841; font-size: 12px; }
	.reference { font-family: Consolas, monospace; font-size: 12px; overflow-wrap: anywhere; }
	.active-panel .muted { margin: 0; }
	.new-draft { width: auto; flex-shrink: 0; }
	.status-badge { display: inline-block; border: 1px solid #dbe4ce; border-radius: 20px; color: #47633f; background: #f3f7ed; padding: 7px 11px; font-size: 11px; }
	textarea, select { font: inherit; font-size: 13px; color: #193c32; width: 100%; border: 1px solid #dce2d8; background: #fbfcf9; border-radius: 7px; padding: 11px 12px; margin-bottom: 19px; }
	textarea { resize: vertical; min-height: 90px; }
	.save-indicator { color: #6e7f62; font-size: 11px; line-height: 1.8; margin-top: 0; }
	.danger { color: #963e32; border: 1px solid #d8ada2; border-radius: 7px; background: white; padding: 12px 16px; margin-top: 17px; font-size: 12px; min-height: 44px; }
	.next-steps { margin-top: 30px; border-top: 1px solid #e7ebdf; padding-top: 22px; font-size: 11px; color: #7d8872; line-height: 1.8; }
	.next-steps span { display: inline-block; margin-right: 20px; font-weight: 600; }
	.history-heading label { display: block; margin-top: 0; }
	.history-heading select { margin: 0; min-width: 190px; }
	.table-scroll { max-width: 100%; overflow-x: auto; margin-top: 20px; }
	table { width: 100%; border-collapse: collapse; font-size: 12px; text-align: left; }
	th { color: #718369; font-size: 11px; font-weight: 600; }
	th, td { padding: 14px 10px; border-bottom: 1px solid #e7ebdf; }
	td { white-space: nowrap; }
	.pagination { display: flex; align-items: center; justify-content: space-between; margin-top: 20px; font-size: 11px; gap: 12px; color: #637257; }
	.pagination button { background: none; color: #315841; border: 1px solid #dce2d8; border-radius: 6px; padding: 9px; margin: 3px; min-height: 40px; font-size: 11px; }
	dialog { max-width: 450px; width: calc(100% - 32px); border: 1px solid #dce2d8; padding: 28px; border-radius: 14px; color: #193c32; }
	dialog p { font-size: 13px; line-height: 1.8; }
	dialog::backdrop { background: #193c3270; }
	.dialog-actions { display: flex; gap: 10px; align-items: end; }
	.dialog-actions .secondary, .dialog-actions .danger { width: 50%; margin-top: 15px; }
	.session-note { font-size: 12px; line-height: 1.8; }
	@media (max-width: 750px) { .page-intro, .active-panel, .history-heading, .pagination { align-items: stretch; flex-direction: column; } .editor-grid { grid-template-columns: 1fr; } h1 { font-size: 36px; } .panel { padding: 22px 18px; } .refresh, .new-draft { width: 100%; } .active-panel .status-badge { align-self: start; } }
</style>
