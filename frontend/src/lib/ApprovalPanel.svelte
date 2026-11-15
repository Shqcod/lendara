<script lang="ts">
    import type { ReviewSnapshot } from '#lib/internal.ts';
    import { timeWib } from '#lib/workspace.ts';
    let { snapshot, disabled, level, submit }: { snapshot: ReviewSnapshot; disabled: boolean; level: number; submit: (decision: string, notes: string) => Promise<void> } = $props();
    let notes = $state(''); let action = $state(''); let dialog = $state<HTMLDialogElement>();
    const ready = $derived(snapshot.anomaly_checks.some((check) => check.status === 'succeeded') && (level === 1 || snapshot.approval_decisions.some((d) => d.level === 1 && d.decision === 'approved')));
</script>
<h3>Keputusan persetujuan tingkat {level}</h3>
<p>Keputusan dibuat oleh peninjau. Hasil anomali menjadi bahan pertimbangan. Catatan keputusan hanya untuk internal.</p>
<fieldset disabled={disabled || !ready}><legend>Setuju atau tolak pengajuan</legend><label for="approval-notes">Catatan keputusan / alasan penolakan</label><textarea id="approval-notes" bind:value={notes} maxlength="16000"></textarea><div class="actions"><button onclick={() => { action = 'approved'; dialog?.showModal(); }}>{level === 1 ? 'Setuju dan teruskan ke tingkat 2' : 'Setujui pengajuan final'}</button><button onclick={() => { action = 'rejected'; dialog?.showModal(); }}>Tolak pengajuan</button></div></fieldset>
{#if !ready}<p>Keputusan memerlukan pemeriksaan berhasil dan urutan persetujuan yang lengkap.</p>{/if}
<dialog bind:this={dialog} aria-labelledby="approval-title"><form onsubmit={async (event) => { event.preventDefault(); dialog?.close(); await submit(action, notes); }}><h2 id="approval-title">{action === 'rejected' ? 'Tolak pengajuan?' : level === 1 ? 'Teruskan ke tingkat 2?' : 'Setujui pengajuan final?'}</h2><p>{action === 'rejected' ? 'Penolakan bersifat final. Alasan wajib diisi.' : level === 1 ? 'Tugas tingkat 1 selesai dan pengajuan masuk antrean tingkat 2.' : 'Persetujuan bersifat final dan tugas selesai.'}</p><p>{notes || 'Tanpa catatan tambahan'}</p><div class="actions"><button type="button" onclick={() => dialog?.close()}>Batal</button><button type="submit" disabled={disabled || !ready}>Konfirmasi keputusan</button></div></form></dialog>
<style>
fieldset { min-width: 0; border: 1px solid #dce2d8; padding: 14px; border-radius: 8px; } label { display: block; margin: 12px 0; } textarea { width: 100%; min-height: 100px; font: inherit; } button { min-height: 44px; padding: 10px; border: 1px solid #dce2d8; border-radius: 7px; background: #edf4e6; color: #193c32; } .actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; } p { overflow-wrap: anywhere; } dialog { width: calc(100% - 32px); max-width: 460px; border: 1px solid #dce2d8; border-radius: 14px; padding: 24px; color: #193c32; } dialog::backdrop { background: #193c3270; }
</style>
