<script lang="ts">
	import { onMount } from 'svelte';
	import { api, ApiError, csrf, currentUser, downloadDocument, type User } from '#lib/api.ts';
	import { financeFields, profileFields, statusLabels, rupiah, timeWib, type Application, type Profile } from '#lib/workspace.ts';
	import type { DocumentType, DocumentVersion } from '#lib/documents.ts';
	import type { Paginated, StatusHistory, Submission } from '#lib/submissions.ts';
	let { applicationId }: { applicationId: string } = $props();
	let user = $state<User | null>(null);
	let application = $state<Application | null>(null);
	let profile = $state<Profile | null>(null);
	let types = $state<DocumentType[]>([]);
	let documents = $state<DocumentVersion[]>([]);
	let submissions = $state<Paginated<Submission> | null>(null);
	let histories = $state<Paginated<StatusHistory> | null>(null);
	let selected = $state<Submission | null>(null);
	let busy = $state(false);
	let ready = $state(false);
	let uncertain = $state(false);
	let authenticated = $state(true);
	let message = $state('');
	let failed = $state(false);
	let errors = $state<Record<string, string[]>>({});
	let confirmed = $state(false);
	let expected = $state<number | null>(null);
	let dialog = $state<HTMLDialogElement>();
	const root = $derived(`/api/v1/applications/${applicationId}`);
	const editable = $derived(application && ['draft', 'revision_required'].includes(application.status));
	const blocked = $derived(busy || !ready || uncertain || !authenticated);
	const missing = $derived(types.filter((type) => type.is_active && type.is_required && !documents.some((file) => file.document_type_id === type.id)));

	function showError(error: unknown) {
		failed = true; message = error instanceof Error ? error.message : 'Permintaan gagal diproses.';
		errors = error instanceof ApiError ? error.fields : {};
		if (error instanceof ApiError && [0, 409].includes(error.status)) uncertain = true;
		if (error instanceof ApiError && [401, 403, 419].includes(error.status)) authenticated = false;
	}
	async function load(archivePage = 1, historyPage = 1) {
		busy = true;
		try {
			await csrf();
			const identity = (await currentUser()).data;
			if (user && user.id !== identity.id) { application = null; profile = null; documents = []; submissions = null; histories = null; selected = null; ready = false; }
			user = identity;
			const [a, p, t, d, s, h] = await Promise.all([
				api<{ data: Application }>(root), api<{ data: Profile }>('/api/v1/customer/profile'), api<{ data: DocumentType[] }>('/api/v1/document-types'),
				api<{ data: DocumentVersion[] }>(`${root}/documents`), api<Paginated<Submission>>(`${root}/submissions?page=${archivePage}&per_page=5`),
				api<Paginated<StatusHistory>>(`${root}/status-histories?page=${historyPage}&per_page=5`)
			]);
			application = a.data; profile = p.data; types = t.data; documents = d.data; submissions = s; histories = h;
			selected = s.data[0] || null; ready = true; authenticated = true; uncertain = false; failed = false; errors = {};
			message = 'Data tersimpan dan riwayat terbaru telah dimuat.';
			return true;
		} catch (error) { showError(error); return false; }
		finally { busy = false; }
	}
	onMount(() => { void load(); });
	async function sameAccount() {
		const identity = (await currentUser()).data;
		if (identity.id !== user?.id) { await load(); return false; }
		return true;
	}
	function review() {
		if (blocked || !editable || !application) return;
		expected = application.current_submission_id; confirmed = false; dialog?.showModal();
	}
	async function submit(event: SubmitEvent) {
		event.preventDefault();
		if (blocked || !confirmed || !editable) return;
		busy = true; errors = {};
		try {
			if (!await sameAccount()) { dialog?.close(); return; }
			await api(`${root}/submissions`, { expected_current_submission_id: expected, confirmed: true });
			dialog?.close();
			if (await load()) message = 'Pengajuan diterima untuk verifikasi. Data pengiriman tersimpan sebagai versi tetap.';
		} catch (error) { dialog?.close(); showError(error); }
		finally { busy = false; }
	}
	async function selectSubmission(id: number) {
		if (blocked) return;
		busy = true;
		try { if (await sameAccount()) selected = (await api<{ data: Submission }>(`${root}/submissions/${id}`)).data; }
		catch (error) { showError(error); }
		finally { busy = false; }
	}
	async function download(document: DocumentVersion) {
		if (blocked) return;
		busy = true;
		try {
			if (!await sameAccount()) return;
			const blob = await downloadDocument(document.id);
			const url = URL.createObjectURL(blob); const link = window.document.createElement('a');
			link.href = url; link.download = document.original_filename; link.click();
			setTimeout(() => URL.revokeObjectURL(url), 1000);
		} catch (error) { showError(error); }
		finally { busy = false; }
	}
	const fieldLabels: Record<string, string> = { ...Object.fromEntries(profileFields.map((field) => [`profile.${field.key}`, field.label])),
		...Object.fromEntries(financeFields.map((field) => [field.key, field.label])), 'profile.address': 'Alamat fiktif', purpose: 'Tujuan pinjaman', tenor_months: 'Tenor (bulan)' };
</script>

<div class="shell">
	<header><a class="brand" href="/">Lendara<span class="brand-dot">.</span></a><a href="/customer">Kembali ke ruang nasabah</a></header>
	<main class="submission-main" aria-busy={busy}>
		<div class="heading"><div><p class="eyebrow">PENGIRIMAN &amp; RIWAYAT</p><h1>Tinjau langkah Anda.</h1></div><button class="secondary" onclick={() => load()} disabled={busy}>Periksa status terbaru</button></div>
		{#if message}<div class="notice" class:error={failed} role={failed ? 'alert' : 'status'}>{message}</div>{/if}
		{#if !authenticated}<p><a href="/" target="_blank" rel="noreferrer">Masuk kembali di tab baru</a>, lalu periksa status terbaru.</p>{/if}
		{#if Object.keys(errors).length}<section class="panel" aria-labelledby="validation-title"><h2 id="validation-title">Periksa kelengkapan</h2><ul>{#each Object.entries(errors) as [field, messages]}<li><strong>{fieldLabels[field] || field.replace('documents.', 'Dokumen ')}:</strong> {messages[0]}</li>{/each}</ul><a href="/customer">Lengkapi dan simpan profil, draf, atau dokumen</a></section>{/if}
		{#if !ready}<p role="status">{busy ? 'Memuat data pengajuan…' : 'Data belum tersedia. Periksa status terbaru untuk mencoba kembali.'}</p>
		{:else if application && profile}
			<section class="panel" aria-labelledby="review-title"><p class="eyebrow">DATA KERJA TERSIMPAN</p><h2 id="review-title">{application.application_number}</h2><p class="badge">{statusLabels[application.status]}</p>
				<p class="muted">Ringkasan ini memakai data yang sudah disimpan. Kembali ke ruang nasabah untuk menyimpan perubahan sebelum mengirim. Setiap pengiriman menyimpan versi tetap.</p>
				<div class="review-grid"><div><h3>Identitas &amp; pekerjaan</h3><dl>{#each profileFields as field}<dt>{field.label}</dt><dd>{profile[field.key] ?? 'Belum diisi'}</dd>{/each}<dt>Alamat fiktif</dt><dd>{profile.address || 'Belum diisi'}</dd></dl></div>
					<div><h3>Pinjaman &amp; keuangan</h3><dl>{#each financeFields as field}<dt>{field.label}</dt><dd>{rupiah(application[field.key])}</dd>{/each}<dt>Tenor</dt><dd>{application.tenor_months ?? 'Belum diisi'} bulan</dd><dt>Tujuan</dt><dd>{application.purpose || 'Belum diisi'}</dd></dl></div></div>
				<h3>Dokumen kerja terkini</h3><ul>{#each types.filter((type) => type.is_active) as type}{@const document = documents.find((file) => file.document_type_id === type.id)}<li>{type.code} · {type.name} ({type.is_required ? 'wajib' : 'opsional'}): {document ? `versi ${document.version_number} · ${document.original_filename}` : 'Belum diunggah'}</li>{/each}</ul>
				{#if editable}<p class="muted">{missing.length ? `${missing.length} dokumen wajib belum tersedia. Backend akan memeriksa seluruh kelengkapan saat kirim.` : 'Periksa identitas, angka, dan dokumen sebelum mengonfirmasi.'}</p><button class="primary" onclick={review} disabled={blocked}>{application.status === 'revision_required' ? 'Tinjau kirim ulang' : 'Tinjau dan kirim'}</button>
				{:else}<p class="muted">Pengajuan sudah dikirim. Data kerja dan dokumen hanya dapat diubah jika diminta revisi.</p>{/if}
			</section>
			<section class="panel" aria-labelledby="archive-title"><p class="eyebrow">VERSI TETAP</p><h2 id="archive-title">Arsip pengiriman</h2>
				{#if submissions?.data.length}<div class="version-buttons">{#each submissions.data as row}<button class:chosen={selected?.id === row.id} onclick={() => selectSubmission(row.id)} disabled={blocked}>Lihat versi {row.version_number}</button>{/each}</div>
					{#if selected}<article aria-labelledby="snapshot-title"><h3 id="snapshot-title">Pengiriman versi {selected.version_number}</h3><p class="muted">Dikirim {timeWib(selected.submitted_at)}. Perubahan profil atau berkas kerja tidak mengubah versi ini.</p>
						<div class="review-grid"><dl>{#each profileFields as field}<dt>{field.label}</dt><dd>{selected.customer_snapshot[field.key] ?? 'Belum diisi'}</dd>{/each}<dt>Alamat fiktif</dt><dd>{selected.customer_snapshot.address}</dd></dl><dl>{#each financeFields as field}<dt>{field.label}</dt><dd>{rupiah(selected[field.key])}</dd>{/each}<dt>Tenor</dt><dd>{selected.tenor_months} bulan</dd><dt>Tujuan</dt><dd>{selected.purpose}</dd></dl></div>
						<h3>Dokumen saat dikirim</h3><ul>{#each selected.documents as file}<li>{types.find((type) => type.id === file.document_type_id)?.code || 'Dokumen'} · {file.original_filename} · versi berkas {file.version_number} <button class="download" onclick={() => download(file)} disabled={blocked}>Unduh {file.original_filename}</button></li>{/each}</ul>
					</article>{/if}
				{:else}<p class="muted">Belum ada pengiriman. Draf dapat dilengkapi bertahap.</p>{/if}
				{#if submissions}<nav aria-label="Halaman arsip"><button onclick={() => load(submissions!.meta.current_page - 1)} disabled={busy || submissions.meta.current_page <= 1}>Arsip sebelumnya</button><span>Halaman {submissions.meta.current_page}/{submissions.meta.last_page}</span><button onclick={() => load(submissions!.meta.current_page + 1)} disabled={busy || submissions.meta.current_page >= submissions.meta.last_page}>Arsip berikutnya</button></nav>{/if}
			</section>
			<section class="panel" aria-labelledby="history-title"><h2 id="history-title">Riwayat status</h2><ol>{#each histories?.data || [] as row}<li><strong>{statusLabels[row.from_status]} → {statusLabels[row.to_status]}</strong><br />{timeWib(row.changed_at)}{#if row.revision_reason}<p>Alasan revisi: {row.revision_reason}</p>{/if}</li>{/each}</ol>{#if !histories?.data.length}<p class="muted">Riwayat dimulai saat pengiriman pertama.</p>{/if}
				{#if histories}<nav aria-label="Halaman riwayat"><button onclick={() => load(submissions?.meta.current_page || 1, histories!.meta.current_page - 1)} disabled={busy || histories.meta.current_page <= 1}>Riwayat sebelumnya</button><span>Halaman {histories.meta.current_page}/{histories.meta.last_page}</span><button onclick={() => load(submissions?.meta.current_page || 1, histories!.meta.current_page + 1)} disabled={busy || histories.meta.current_page >= histories.meta.last_page}>Riwayat berikutnya</button></nav>{/if}
			</section>
		{/if}
	</main><footer>© 2026 Lendara · Seluruh data demonstrasi bersifat fiktif.</footer>
</div>

<dialog bind:this={dialog} aria-labelledby="send-title" oncancel={(event) => { if (busy) event.preventDefault(); }}>
	<form onsubmit={submit}><h2 id="send-title">Kirim pengajuan untuk verifikasi?</h2><p>Identitas, keuangan, dan dokumen yang sudah disimpan akan menjadi versi tetap. Pengajuan belum merupakan persetujuan pinjaman.</p>
		<label class="confirmation"><input type="checkbox" bind:checked={confirmed} required disabled={busy} /> Saya telah meninjau data tersimpan dan mengonfirmasi pengiriman.</label>
		<div class="dialog-actions"><button type="button" onclick={() => dialog?.close()} disabled={busy}>Batal</button><button class="primary" type="submit" disabled={blocked || !confirmed}>Ya, kirim pengajuan</button></div>
	</form>
</dialog>

<style>
	.submission-main { display: flex; flex-direction: column; gap: 22px; align-items: stretch; padding-top: 26px; }
	.heading { display: flex; align-items: center; justify-content: space-between; gap: 20px; } h1 { font-size: 40px; margin: 8px 0; } h2 { font-size: 22px; overflow-wrap: anywhere; } h3 { font-size: 15px; }
	.heading button { width: auto; } .panel { background: white; border: 1px solid #e2e7dc; border-radius: 14px; padding: 28px; min-width: 0; }
	.review-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; } dl { margin: 12px 0; min-width: 0; } dt { font-size: 11px; color: #697c63; margin-top: 14px; } dd { margin: 5px 0; font-size: 13px; line-height: 1.8; overflow-wrap: anywhere; }
	.badge { display: inline-block; background: #eff5e7; border: 1px solid #dbe4ce; border-radius: 20px; font-size: 12px; padding: 8px 12px; }
	li { font-size: 13px; line-height: 1.9; margin-bottom: 10px; overflow-wrap: anywhere; } ul, ol { padding-left: 20px; }
	.version-buttons, nav, .dialog-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; } nav { margin-top: 20px; font-size: 11px; }
	nav button, .version-buttons button, .download, .dialog-actions > button { border: 1px solid #dce2d8; border-radius: 6px; padding: 10px 12px; background: white; color: #315841; min-height: 44px; font-size: 12px; }
	.version-buttons .chosen { background: #eff5e7; border-color: #315841; } .download { display: block; margin: 8px 0 15px; max-width: 100%; overflow-wrap: anywhere; }
	dialog { max-width: 480px; width: calc(100% - 32px); padding: 26px; border: 1px solid #dce2d8; border-radius: 14px; color: #193c32; } dialog::backdrop { background: #193c3270; } dialog p { font-size: 13px; line-height: 1.8; }
	.confirmation { display: flex; gap: 10px; align-items: flex-start; line-height: 1.8; margin: 20px 0; } .confirmation input { width: auto; margin: 5px 0; } .dialog-actions .primary { background: #214f40; color: white; width: auto; }
	@media (max-width: 650px) { .heading { flex-direction: column; align-items: stretch; } .heading button { width: 100%; } h1 { font-size: 32px; } .review-grid { grid-template-columns: 1fr; gap: 8px; } .panel { padding: 22px 18px; } }
</style>
