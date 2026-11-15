<script lang="ts">
	import VerificationPanel from '#lib/VerificationPanel.svelte';
	import { onMount } from 'svelte';
	import { api, ApiError, csrf, currentUser, downloadDocument, type User } from '#lib/api.ts';
	import { roleLabels, stageLabels, reviewerRoles, type ReviewRow, type ReviewDetail, type ReviewSnapshot, type Summary } from '#lib/internal.ts';
	import type { Paginated } from '#lib/submissions.ts';
	import type { DocumentVersion } from '#lib/documents.ts';
	import { financeFields, profileFields, statusLabels, rupiah, timeWib } from '#lib/workspace.ts';
	let user = $state<User | null>(null);
	let rows = $state<Paginated<ReviewRow> | null>(null);
	let summary = $state<Summary | null>(null);
	let detail = $state<ReviewDetail | null>(null);
	let snapshot = $state<ReviewSnapshot | null>(null);
	let filter = $state('unassigned');
	let page = $state(1);
	let number = $state('');
	let busy = $state(false);
	let ready = $state(false);
	let uncertain = $state(false);
	let authenticated = $state(true);
	let message = $state('');
	let failed = $state(false);
	let errors = $state<Record<string, string[]>>({});
	let reason = $state('');
	let releaseId = $state<number | null>(null);
	let releaseDialog = $state<HTMLDialogElement>();
	const allowed = $derived(user && reviewerRoles.some((role) => role === user?.role));
	const blocked = $derived(busy || !ready || uncertain || !authenticated || !allowed);
	const own = $derived(detail?.current_assignment?.assigned_to === user?.id);
	const currentVersion = $derived(snapshot?.id === detail?.current_submission_id);

	function clearData() { rows = null; summary = null; detail = null; snapshot = null; ready = false; releaseDialog?.close(); reason = ''; }
	function showError(error: unknown, mutation = false) {
		failed = true; message = error instanceof Error ? error.message : 'Permintaan gagal diproses.';
		errors = error instanceof ApiError ? error.fields : {};
		if (mutation && error instanceof ApiError && (error.status === 0 || error.status === 409 || error.status >= 500)) {
			uncertain = true; message += ' Periksa data terbaru sebelum mencoba tindakan kembali.';
		}
		if (error instanceof ApiError && [401, 403, 419].includes(error.status)) authenticated = false;
	}
	async function load(id: number | null = detail?.id ?? null, pages = '') {
		busy = true;
		try {
			await csrf(); const identity = (await currentUser()).data;
			if (user && (identity.id !== user.id || identity.role !== user.role)) { clearData(); id = null; }
			user = identity; authenticated = true;
			if (id === null) { detail = null; snapshot = null; }
			if (!reviewerRoles.some((role) => role === identity.role)) { clearData(); return false; }
			const [list, counts] = await Promise.all([api<Paginated<ReviewRow>>(`/api/v1/internal/applications?assignment=${filter}&page=${page}&per_page=10${number.trim() ? `&application_number=${encodeURIComponent(number.trim())}` : ''}`), api<{ data: Summary }>('/api/v1/internal/application-summary')]);
			rows = list; summary = counts.data;
			if (id !== null) {
				try { detail = (await api<{ data: ReviewDetail }>(`/api/v1/internal/applications/${id}${pages}`)).data; snapshot = detail.current_submission; }
				catch (error) { if (error instanceof ApiError && error.status === 404) { detail = null; snapshot = null; } else throw error; }
			}
			ready = true; uncertain = false; errors = {}; failed = false; message = 'Antrean dan kepemilikan tugas terbaru telah dimuat.';
			return true;
		} catch (error) { showError(error); return false; }
		finally { busy = false; }
	}
	onMount(() => { void load(); });
	async function sameAccount() {
		const identity = (await currentUser()).data;
		if (identity.id !== user?.id || identity.role !== user?.role) { await load(); return false; }
		return true;
	}
	async function claim(row: ReviewRow) {
		if (blocked || row.current_assignment) return;
		busy = true; errors = {};
		try {
			if (!await sameAccount()) return;
			await api(`/api/v1/internal/applications/${row.id}/assignments`, { expected_current_submission_id: row.current_submission_id });
			uncertain = true;
			if (await load(row.id)) message = 'Tugas berhasil diambil. Status pengajuan tetap.';
		} catch (error) { showError(error, true); }
		finally { busy = false; }
	}
	function openRelease() {
		if (blocked || !own || !detail?.current_assignment || !currentVersion) return;
		releaseId = detail.current_assignment.id; reason = ''; errors = {}; releaseDialog?.showModal();
	}
	async function release(event: SubmitEvent) {
		event.preventDefault();
		if (blocked || releaseId === null) return;
		busy = true;
		try {
			if (!await sameAccount()) { releaseDialog?.close(); return; }
			await api(`/api/v1/internal/assignments/${releaseId}/release`, { reason });
			releaseDialog?.close(); uncertain = true;
			if (await load()) message = 'Tugas dilepas ke antrean. Riwayat dan status pengajuan dipertahankan.';
		} catch (error) { showError(error, true); if (!(error instanceof ApiError && error.status === 422)) releaseDialog?.close(); }
		finally { busy = false; }
	}
	async function selectSnapshot(id: number, verificationPage = 1) {
		if (blocked || !detail) return;
		busy = true;
		try { if (await sameAccount()) snapshot = (await api<{ data: ReviewSnapshot }>(`/api/v1/internal/applications/${detail.id}/submissions/${id}?verification_page=${verificationPage}`)).data; }
		catch (error) { showError(error); }
		finally { busy = false; }
	}
	async function verify(type: string, notes: string, checklist: Record<string, boolean>) {
		if (blocked || !own || !currentVersion || !snapshot) return;
		busy = true; errors = {};
		try {
			if (!await sameAccount()) return;
			await api(`/api/v1/internal/submissions/${snapshot.id}/verification-results`, { result_type: type, notes: notes || null, document_checklist: checklist });
			uncertain = true;
			if (await load()) message = 'Hasil verifikasi berhasil disimpan.';
		} catch (error) { showError(error, true); }
		finally { busy = false; }
	}
	async function download(file: DocumentVersion) {
		if (blocked) return;
		busy = true;
		try {
			if (!await sameAccount()) return;
			const blob = await downloadDocument(file.id); const url = URL.createObjectURL(blob); const link = document.createElement('a');
			link.href = url; link.download = file.original_filename; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
		} catch (error) { showError(error); }
		finally { busy = false; }
	}
	async function changeFilter(value: string) { if (busy) return; filter = value; page = 1; await load(); }
</script>

<svelte:head><title>Antrean Internal | Lendara</title></svelte:head>
<div class="shell internal-shell">
	<header><a class="brand" href="/">Lendara<span class="brand-dot">.</span></a><a href="/">Akun &amp; keluar</a></header>
	<main class="board" aria-busy={busy}>
		<aside><p class="eyebrow">RUANG PENINJAUAN</p><h1>{user ? roleLabels[user.role] || 'Akses internal' : 'Antrean internal'}</h1><nav aria-label="Navigasi peninjauan"><button class:chosen={filter === 'unassigned'} onclick={() => changeFilter('unassigned')} disabled={busy}>Antrean pengajuan</button><button class:chosen={filter === 'mine'} onclick={() => changeFilter('mine')} disabled={busy}>Tugas saya</button><button class:chosen={filter === 'all'} onclick={() => changeFilter('all')} disabled={busy}>Semua pada tahap ini</button></nav><p class="muted">{user?.name}</p></aside>
		<div class="content">
			<div class="heading"><div><p class="eyebrow">PENINJAUAN BERTAHAP</p><h2>Antrean pengajuan</h2><p class="muted">{summary ? statusLabels[summary.status] : 'Pilih pengajuan dalam tahap Anda.'}</p></div><button class="secondary refresh" onclick={() => load()} disabled={busy}>Periksa data terbaru</button></div>
			{#if message}<div class="notice" class:error={failed} role={failed ? 'alert' : 'status'}>{message}</div>{/if}
			{#if !authenticated}<p><a href="/" target="_blank" rel="noreferrer">Masuk kembali di tab baru</a>, lalu periksa data terbaru.</p>{/if}
			{#if user && !allowed}<section class="panel"><h2>Halaman khusus peninjau</h2><p>Gunakan akun verifikator atau penyetuju sesuai tahap. Akun admin tidak mendapat akses berkas nasabah otomatis.</p></section>
			{:else if !ready}<p role="status">{busy ? 'Memuat antrean…' : 'Data belum tersedia. Periksa data terbaru untuk mencoba kembali.'}</p>
			{:else}
				{#if summary}<div class="stats"><div><span>Dalam tahap Anda</span><strong>{summary.total}</strong></div><div><span>Belum diambil</span><strong>{summary.unassigned}</strong></div><div><span>Tugas Anda</span><strong>{summary.mine}</strong></div><div><span>Peninjau lain</span><strong>{summary.assigned_to_others}</strong></div></div>{/if}
				<section class="panel" aria-labelledby="queue-title"><div class="heading"><h2 id="queue-title">{filter === 'mine' ? 'Tugas saya' : filter === 'unassigned' ? 'Belum diambil' : 'Semua pada tahap ini'}</h2><div><label for="assignment-filter">Penanggung jawab</label><select id="assignment-filter" bind:value={filter} onchange={() => changeFilter(filter)} disabled={busy}><option value="unassigned">Belum diambil</option><option value="mine">Tugas saya</option><option value="all">Semua pada tahap ini</option></select></div></div>
					<form class="search" onsubmit={(event) => { event.preventDefault(); page = 1; void load(null); }}><label for="application-number">Nomor pengajuan</label><input id="application-number" bind:value={number} maxlength="40" placeholder="Nomor lengkap pengajuan" /><button disabled={busy}>Cari pengajuan</button></form>
					<div class="table-scroll"><table><thead><tr><th>Pengajuan / nasabah</th><th>Pinjaman</th><th>Versi / pemilik</th><th>Tindakan</th></tr></thead><tbody>{#each rows?.data || [] as row}<tr><td><strong class="reference">{row.application_number}</strong><br />{row.customer_name}</td><td>{rupiah(row.loan_amount)}</td><td>Versi {row.version_number}<br />{row.current_assignment?.assignee.name || 'Belum diambil'}</td><td><button onclick={() => load(row.id)} disabled={busy}>Lihat {row.application_number}</button>{#if !row.current_assignment}<button class="claim" onclick={() => claim(row)} disabled={blocked}>Ambil {row.application_number}</button>{/if}</td></tr>{/each}</tbody></table></div>
					{#if !rows?.data.length}<p class="muted">Tidak ada pengajuan pada filter ini.</p>{/if}
					{#if rows}<div class="pagination"><button onclick={() => { page--; void load(); }} disabled={busy || page <= 1}>Sebelumnya</button><span>Halaman {rows.meta.current_page}/{rows.meta.last_page} · {rows.meta.total} pengajuan</span><button onclick={() => { page++; void load(); }} disabled={busy || page >= rows.meta.last_page}>Berikutnya</button></div>{/if}
				</section>
				{#if detail && snapshot}<section class="panel" aria-labelledby="detail-title"><p class="eyebrow">PENGAJUAN DALAM TAHAP ANDA</p><h2 id="detail-title">{detail.application_number}</h2><p class="muted">{statusLabels[detail.status]} · Pemilik: {detail.current_assignment?.assignee.name || 'Belum diambil'}</p>
					<div class="actions">{#if !detail.current_assignment}<button class="primary" onclick={() => claim(detail!)} disabled={blocked || !currentVersion}>Ambil tugas ini</button>{:else if own}<button onclick={openRelease} disabled={blocked || !currentVersion}>Lepas tugas</button>{:else}<p>Tugas dipegang peninjau lain. Tindakan pemrosesan hanya untuk pemilik tugas aktif.</p>{/if}</div>
					<h3>Versi pengiriman</h3><div class="actions">{#each detail.submissions.data as version}<button class:chosen={snapshot.id === version.id} onclick={() => selectSnapshot(version.id)} disabled={blocked}>Lihat versi {version.version_number}</button>{/each}<button onclick={() => selectSnapshot(detail!.current_submission_id)} disabled={blocked || currentVersion}>Versi terkini</button></div>
					<div class="pagination"><button onclick={() => load(detail!.id, `?submission_page=${detail!.submissions.meta.current_page - 1}`)} disabled={busy || detail.submissions.meta.current_page <= 1}>Versi sebelumnya</button><span>Halaman {detail.submissions.meta.current_page}/{detail.submissions.meta.last_page}</span><button onclick={() => load(detail!.id, `?submission_page=${detail!.submissions.meta.current_page + 1}`)} disabled={busy || detail.submissions.meta.current_page >= detail.submissions.meta.last_page}>Versi berikutnya</button></div>
					<article aria-labelledby="snapshot-title"><h3 id="snapshot-title">Pengiriman versi {snapshot.version_number}{currentVersion ? ' · terkini' : ' · arsip, hanya baca'}</h3><p class="muted">Dikirim {timeWib(snapshot.submitted_at)}. Data di bawah berasal dari versi pengiriman tersimpan.</p><div class="snapshot-grid"><dl>{#each profileFields as field}<dt>{field.label}</dt><dd>{snapshot.customer_snapshot[field.key] ?? 'Belum diisi'}</dd>{/each}<dt>Alamat fiktif</dt><dd>{snapshot.customer_snapshot.address}</dd></dl><dl>{#each financeFields as field}<dt>{field.label}</dt><dd>{rupiah(snapshot[field.key])}</dd>{/each}<dt>Tenor</dt><dd>{snapshot.tenor_months} bulan</dd><dt>Tujuan</dt><dd>{snapshot.purpose}</dd></dl></div>
						<h3>Dokumen saat dikirim</h3>{#if !snapshot.documents.length}<p class="muted">Tidak ada dokumen pada versi ini.</p>{/if}{#each snapshot.documents as file}<div class="file-row"><span>{file.original_filename} · versi berkas {file.version_number}</span><button onclick={() => download(file)} disabled={blocked}>Unduh {file.original_filename}</button></div>{/each}
						<h3>Pemeriksaan anomali</h3><p class="muted">Hasil ini menjadi bahan peninjauan manusia. Pengambilan atau pelepasan tugas tidak memutuskan pinjaman.</p>{#if !snapshot.anomaly_checks.length}<p>Belum ada pemeriksaan.</p>{/if}{#each snapshot.anomaly_checks as check}<div class="check"><strong>Percobaan {check.attempt_number} · {check.status === 'succeeded' ? 'Selesai' : check.status === 'failed' ? 'Gagal' : 'Diproses'}</strong><p class="muted">Model: {check.model_version_code} · Mulai {timeWib(check.started_at)}</p>{#if check.status === 'succeeded'}<p>Skor: {check.score?.toLocaleString('id-ID', { maximumFractionDigits: 6 })} · {check.is_anomaly ? 'Perlu peninjauan anomali' : 'Tidak ditandai anomali'}</p>{:else if check.status === 'failed'}<p>Pemeriksaan belum berhasil. Retry manual tersedia pada tahap verifikasi berikutnya.</p>{/if}</div>{/each}
					</article>
					<h3>Riwayat penugasan</h3><ol>{#each detail.assignments.data as assignment}<li><strong>{assignment.assignee.name}</strong> · {stageLabels[assignment.stage] || assignment.stage}<br />Diambil {timeWib(assignment.claimed_at)}{#if assignment.ended_at}<br />{assignment.end_type === 'released' ? 'Dilepas' : 'Selesai'} {timeWib(assignment.ended_at)}{#if assignment.end_reason}<p>Alasan: {assignment.end_reason}</p>{/if}{:else}<br />Aktif{/if}</li>{/each}</ol>
					<div class="pagination"><button onclick={() => load(detail!.id, `?assignment_page=${detail!.assignments.meta.current_page - 1}`)} disabled={busy || detail.assignments.meta.current_page <= 1}>Penugasan sebelumnya</button><span>Halaman {detail.assignments.meta.current_page}/{detail.assignments.meta.last_page}</span><button onclick={() => load(detail!.id, `?assignment_page=${detail!.assignments.meta.current_page + 1}`)} disabled={busy || detail.assignments.meta.current_page >= detail.assignments.meta.last_page}>Penugasan berikutnya</button></div>
					<h3>Riwayat status</h3><ol>{#each detail.status_histories.data as history}<li>{statusLabels[history.from_status]} → {statusLabels[history.to_status]}<br />{timeWib(history.changed_at)}</li>{/each}</ol><div class="pagination"><button onclick={() => load(detail!.id, `?history_page=${detail!.status_histories.meta.current_page - 1}`)} disabled={busy || detail.status_histories.meta.current_page <= 1}>Status sebelumnya</button><span>Halaman {detail.status_histories.meta.current_page}/{detail.status_histories.meta.last_page}</span><button onclick={() => load(detail!.id, `?history_page=${detail!.status_histories.meta.current_page + 1}`)} disabled={busy || detail.status_histories.meta.current_page >= detail.status_histories.meta.last_page}>Status berikutnya</button></div>
					{#each Object.entries(errors) as [field, messages]}<p class="field-error" role="alert">{field}: {messages[0]}</p>{/each}
					{#key snapshot.id}<VerificationPanel {snapshot} disabled={blocked} locked={!own || !currentVersion || user?.role !== 'verifikator'} submit={verify} page={(value) => selectSnapshot(snapshot!.id, value)} />{/key}
					<p class="muted">Retry manual dan keputusan persetujuan tersedia pada irisan berikutnya.</p>
				</section>{/if}
			{/if}
		</div>
	</main><footer>© 2026 Lendara · Data dan berkas demonstrasi bersifat fiktif.</footer>
</div>
<dialog bind:this={releaseDialog} aria-labelledby="release-title" oncancel={(event) => { if (busy) event.preventDefault(); }}><form onsubmit={release}><h2 id="release-title">Lepas tugas ke antrean?</h2><p>Status pengajuan tetap. Peninjau lain pada tahap yang sama dapat mengambil tugas ini.</p><label for="release-reason">Alasan pelepasan</label><textarea id="release-reason" bind:value={reason} required maxlength="16000" disabled={busy} aria-invalid={!!errors.reason}></textarea>{#if errors.reason}<p class="field-error">{errors.reason[0]}</p>{/if}<div class="actions"><button type="button" onclick={() => releaseDialog?.close()} disabled={busy}>Batal</button><button type="submit" class="primary" disabled={blocked}>Ya, lepas tugas</button></div></form></dialog>

<style>
	.board { display: grid; grid-template-columns: 210px minmax(0, 1fr); align-items: start; gap: 24px; padding: 24px 0 40px; } aside { position: sticky; top: 20px; } h1 { font-size: 23px; line-height: 1.5; } h2 { font-size: 22px; margin-top: 5px; overflow-wrap: anywhere; } h3 { font-size: 15px; margin-top: 26px; } .content { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
	.panel { background: white; border: 1px solid #e2e7dc; border-radius: 14px; padding: 24px; min-width: 0; } nav { display: flex; flex-direction: column; gap: 8px; } button { background: white; color: #315841; border: 1px solid #dce2d8; border-radius: 7px; padding: 10px 12px; font-size: 12px; min-height: 44px; } .chosen, .claim { background: #edf4e6; border-color: #6d8b59; } .primary { color: white; background: #214f40; } .refresh { width: auto; }
	.heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; } .stats { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 12px; } .stats div { background: #f2f6eb; border: 1px solid #dfe8d6; padding: 16px; border-radius: 9px; } .stats span { display: block; font-size: 11px; color: #63745a; } .stats strong { display: block; font-size: 27px; margin-top: 10px; }
	label { display: block; margin: 0 0 7px; } select, textarea { width: 100%; font: inherit; font-size: 13px; background: #fbfcf9; border: 1px solid #dce2d8; border-radius: 7px; padding: 10px; color: #193c32; } textarea { min-height: 100px; margin-bottom: 15px; } .search { margin: 18px 0; } .search input { margin-bottom: 10px; } .table-scroll { max-width: 100%; overflow-x: auto; } table { width: 100%; border-collapse: collapse; font-size: 12px; text-align: left; } th, td { padding: 13px 8px; border-bottom: 1px solid #e7ebdf; } td { min-width: 130px; line-height: 1.9; } td button { display: block; margin: 6px 0; overflow-wrap: anywhere; max-width: 200px; } .reference { font-family: Consolas, monospace; font-size: 11px; overflow-wrap: anywhere; }
	.pagination, .actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 15px; } .pagination { font-size: 11px; } .actions .primary { width: auto; } .snapshot-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; } dl { min-width: 0; } dt { font-size: 11px; color: #697c63; margin-top: 12px; } dd { margin: 5px 0; font-size: 13px; line-height: 1.8; overflow-wrap: anywhere; } .file-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 12px 0; font-size: 12px; overflow-wrap: anywhere; } .file-row button { max-width: 100%; overflow-wrap: anywhere; } .check { border-left: 3px solid #94b57b; padding: 8px 15px; margin: 15px 0; font-size: 13px; } li { font-size: 12px; line-height: 1.9; overflow-wrap: anywhere; margin-bottom: 12px; }
	dialog { width: calc(100% - 32px); max-width: 460px; border: 1px solid #dce2d8; border-radius: 14px; padding: 24px; color: #193c32; } dialog p { font-size: 13px; line-height: 1.8; } dialog::backdrop { background: #193c3270; } dialog .actions button { width: auto; }
	@media(max-width: 950px) { .board { grid-template-columns: 1fr; } aside { position: static; } nav { flex-direction: row; flex-wrap: wrap; } .stats { grid-template-columns: repeat(2,minmax(0,1fr)); } } @media(max-width: 550px) { .heading, .file-row { flex-direction: column; align-items: stretch; } .snapshot-grid { grid-template-columns: 1fr; } .panel { padding: 20px 15px; } .refresh { width: 100%; } }
</style>
