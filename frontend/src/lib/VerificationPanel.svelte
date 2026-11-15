<script lang="ts">
	import type { ReviewSnapshot } from '#lib/internal.ts';
	import { timeWib } from '#lib/workspace.ts';
	let { snapshot, disabled, locked, submit, page }: { snapshot: ReviewSnapshot; disabled: boolean; locked: boolean; submit: (type: string, notes: string, checklist: Record<string, boolean>) => Promise<void>; page: (value: number) => void } = $props();
	let notes = $state('');
	let checklist = $state<Record<string, boolean>>({});
	let action = $state('');
	let dialog = $state<HTMLDialogElement>();
	const succeeded = $derived(snapshot.anomaly_checks.some((check) => check.status === 'succeeded'));
	const labels: Record<string, string> = { note: 'Simpan catatan', request_revision: 'Minta revisi', forward: 'Teruskan ke penyetuju tingkat 1' };
	async function confirm(event: SubmitEvent) {
		event.preventDefault();
		dialog?.close();
		await submit(action, notes, checklist);
	}
</script>
<h3>Catatan dan tindakan verifikasi</h3>
<p class="muted">Checklist mencatat dokumen yang ditinjau. Alasan revisi akan terlihat oleh nasabah; catatan lainnya hanya untuk peninjau internal.</p>
<fieldset disabled={disabled || locked}>
	<legend>Dokumen yang telah ditinjau</legend>
	{#each snapshot.documents as file}<label><input type="checkbox" checked={checklist[String(file.document_type_id)] ?? false} onchange={(event) => checklist = { ...checklist, [String(file.document_type_id)]: event.currentTarget.checked }} /> {file.original_filename}</label>{/each}
	<label for="verification-notes">Catatan / alasan revisi</label>
	<textarea id="verification-notes" bind:value={notes} maxlength="16000"></textarea>
	<div class="actions">{#each ['note', 'request_revision', 'forward'] as type}<button type="button" disabled={disabled || locked || (type === 'forward' && !succeeded)} onclick={() => { action = type; dialog?.showModal(); }}>{labels[type]}</button>{/each}</div>
</fieldset>
{#if !succeeded}<p>Teruskan tersedia setelah pemeriksaan anomali berhasil.</p>{/if}
<h3>Riwayat verifikasi versi ini</h3>
<ol>{#each snapshot.verification_results.data as result}<li>{labels[result.result_type]} · {timeWib(result.created_at)}<p>{result.notes || 'Tanpa catatan tambahan'}</p><ul>{#each Object.entries(result.document_checklist) as [type, checked]}<li>{snapshot.documents.find((file) => String(file.document_type_id) === type)?.original_filename || type}: {checked ? 'Ditinjau' : 'Belum ditinjau'}</li>{/each}</ul></li>{/each}</ol>
<div class="actions"><button onclick={() => page(snapshot.verification_results.meta.current_page - 1)} disabled={disabled || snapshot.verification_results.meta.current_page <= 1}>Catatan sebelumnya</button><span>Halaman {snapshot.verification_results.meta.current_page}/{snapshot.verification_results.meta.last_page}</span><button onclick={() => page(snapshot.verification_results.meta.current_page + 1)} disabled={disabled || snapshot.verification_results.meta.current_page >= snapshot.verification_results.meta.last_page}>Catatan berikutnya</button></div>
<dialog bind:this={dialog} aria-labelledby="verification-confirm-title"><form onsubmit={confirm}><h2 id="verification-confirm-title">{labels[action]}?</h2><p>{action === 'request_revision' ? 'Alasan revisi terlihat oleh nasabah dan tugas verifikasi selesai.' : action === 'forward' ? 'Pengajuan masuk ke antrean penyetuju tingkat 1 dan tugas verifikasi selesai.' : 'Catatan tersimpan pada versi ini. Tugas tetap aktif.'}</p><p>{notes || 'Tanpa catatan tambahan'}</p><div class="actions"><button type="button" onclick={() => dialog?.close()}>Batal</button><button type="submit" disabled={disabled || locked}>Konfirmasi tindakan</button></div></form></dialog>
<style>
	fieldset { border: 1px solid #dce2d8; border-radius: 8px; padding: 14px; min-width: 0; }
	label { display: block; margin: 12px 0; overflow-wrap: anywhere; } input { width: auto; }
	textarea { width: 100%; min-height: 100px; font: inherit; } button { min-height: 44px; padding: 10px; border: 1px solid #dce2d8; border-radius: 7px; background: #edf4e6; color: #193c32; }
	.actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; align-items: center; } p, li { overflow-wrap: anywhere; }
	dialog { width: calc(100% - 32px); max-width: 460px; border: 1px solid #dce2d8; border-radius: 14px; padding: 24px; color: #193c32; } dialog::backdrop { background: #193c3270; }
</style>
