<script lang="ts">
	import { timeWib } from '#lib/workspace.ts';
	import type { DocumentType, DocumentVersion } from '#lib/documents.ts';
	let { types, documents, editable, blocked, errors, onupload, ondownload }: {
		types: DocumentType[]; documents: DocumentVersion[]; editable: boolean; blocked: boolean;
		errors: Record<string, string[]>;
		onupload: (type: number, file: File) => Promise<boolean>;
		ondownload: (document: DocumentVersion) => Promise<void>;
	} = $props();
	let selectedType = $state('');
	let fileInput = $state<HTMLInputElement>();
	let localError = $state('');
	async function upload(event: SubmitEvent) {
		event.preventDefault();
		if (blocked) return;
		const file = fileInput?.files?.[0];
		localError = '';
		if (!file || !selectedType) { localError = 'Pilih jenis dokumen dan satu berkas.'; return; }
		if (file.size > 5 * 1024 * 1024) { localError = 'Ukuran berkas maksimal 5 MB.'; return; }
		if (await onupload(Number(selectedType), file) && fileInput) fileInput.value = '';
	}
</script>

<section class="panel documents" aria-labelledby="documents-title">
	<p class="eyebrow">03 · DOKUMEN PRIVAT</p><h2 id="documents-title">Dokumen pengajuan</h2>
	<p class="muted">Gunakan berkas fiktif. D01–D03 wajib untuk pengiriman; D04 opsional. Setiap unggahan menyimpan versi baru dan mempertahankan versi sebelumnya.</p>
	<div class="document-grid">
		{#each types as type}
			{@const document = documents.find((row) => row.document_type_id === type.id)}
			<article class="document-card">
				<h3>{type.code} · {type.name}</h3><span class="requirement">{type.is_required ? 'Wajib' : 'Opsional'}{!type.is_active ? ' · Nonaktif' : ''}</span>
				{#if document}
					<p class="filename">{document.original_filename}</p>
					<p class="muted">Versi {document.version_number} · {Math.ceil(document.file_size_bytes / 1024)} KB<br />{timeWib(document.uploaded_at)}</p>
					<button type="button" class="download" onclick={() => ondownload(document)} disabled={blocked}>Unduh {type.code}</button>
				{:else}<p class="muted">Belum diunggah.</p>{/if}
			</article>
		{/each}
	</div>
	{#if editable}
		<form onsubmit={upload}>
			<label for="document-type">Jenis dokumen</label>
			<select id="document-type" bind:value={selectedType} required disabled={blocked} aria-invalid={!!errors.document_type_id} aria-describedby={errors.document_type_id ? 'document-type-error' : undefined}>
				<option value="">Pilih jenis dokumen</option>{#each types.filter((type) => type.is_active) as type}<option value={String(type.id)}>{type.code} · {type.name}</option>{/each}
			</select>
			{#if errors.document_type_id}<p id="document-type-error" class="field-error">{errors.document_type_id[0]}</p>{/if}
			<label for="document-file">Berkas fiktif (PDF, JPG, JPEG, PNG)</label>
			<input id="document-file" type="file" accept=".pdf,.jpg,.jpeg,.png" bind:this={fileInput} required disabled={blocked} aria-invalid={!!errors.file} aria-describedby="document-file-help document-file-error" />
			<p id="document-file-help" class="muted">Satu berkas per unggahan, maksimal 5 MB. Mengunggah dokumen belum mengirim pengajuan untuk ditinjau.</p>
			<p id="document-file-error" class="field-error" aria-live="polite">{errors.file?.[0] || localError}</p>
			<button class="primary" type="submit" disabled={blocked}>Unggah versi baru</button>
		</form>
	{:else}<p class="muted">Dokumen hanya dapat ditambah saat draf atau diminta revisi.</p>{/if}
</section>

<style>
	.panel { background: white; border: 1px solid #e2e7dc; border-radius: 14px; padding: 28px; min-width: 0; }
	h2 { font-size: 22px; }
	.document-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 24px 0; }
	.document-card { border: 1px solid #e2e7dc; border-radius: 9px; padding: 16px; min-width: 0; }
	h3 { font-size: 13px; line-height: 1.7; margin: 0 0 8px; }
	.requirement { font-size: 11px; color: #47633f; }
	.filename { font-size: 12px; overflow-wrap: anywhere; }
	.download { border: 1px solid #dce2d8; background: white; border-radius: 6px; padding: 10px; color: #315841; min-height: 44px; }
	form { max-width: 540px; }
	select { font: inherit; width: 100%; border: 1px solid #dce2d8; border-radius: 7px; padding: 11px; margin-bottom: 18px; color: #193c32; background: #fbfcf9; }
	input[type='file'] { padding: 10px; min-width: 0; max-width: 100%; }
	@media (max-width: 950px) { .document-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
	@media (max-width: 550px) { .document-grid { grid-template-columns: 1fr; } .panel { padding: 22px 18px; } }
</style>
