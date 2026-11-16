<script lang="ts">
import { onMount } from 'svelte';
import { api, ApiError, csrf, currentUser, type User } from '#lib/api.ts';
import type { Paginated } from '#lib/submissions.ts';
import { timeWib } from '#lib/workspace.ts';
let user = $state<User | null>(null); let users = $state<Paginated<User> | null>(null);
type Log = { id: number; actor_id: number | null; action: string; target_type: string; target_id: number | null; created_at: string };
let logs = $state<Paginated<Log> | null>(null);
let name = $state(''); let email = $state(''); let password = $state(''); let confirmation = $state(''); let role = $state('verifikator');
let search = $state(''); let roleFilter = $state(''); let action = $state(''); let page = $state(1); let logPage = $state(1);
let busy = $state(false); let ready = $state(false); let uncertain = $state(false); let failed = $state(false); let message = $state(''); let errors = $state<Record<string, string[]>>({});
const roles: Record<string, string> = { verifikator: 'Verifikator', penyetuju_1: 'Penyetuju tingkat 1', penyetuju_2: 'Penyetuju tingkat 2', admin: 'Admin' };
let selected = $state<User | null>(null); let operation = $state('deactivate'); let nextRole = $state('verifikator'); let reason = $state(''); let dialog = $state<HTMLDialogElement>();
const blocked = $derived(busy || !ready || uncertain || user?.role !== 'admin');
function error(value: unknown, mutation = false) {
    failed = true; message = value instanceof Error ? value.message : 'Permintaan gagal.'; errors = value instanceof ApiError ? value.fields : {};
    if (mutation && value instanceof ApiError && (value.status === 0 || value.status === 409 || value.status >= 500)) uncertain = true;
    if (value instanceof ApiError && [401,403,419].includes(value.status)) { user = null; users = null; logs = null; ready = false; }
}
async function load() {
    busy = true;
    try {
        await csrf(); const identity = (await currentUser()).data;
        if (user && (user.id !== identity.id || user.role !== identity.role)) { users = null; logs = null; selected = null; dialog?.close(); name = ''; email = ''; password = ''; confirmation = ''; }
        user = identity;
        if (user.role !== 'admin') { users = null; logs = null; ready = false; return; }
        const result = await Promise.all([api<Paginated<User>>(`/api/v1/admin/users?page=${page}${search.trim() ? `&email=${encodeURIComponent(search.trim())}` : ''}${roleFilter ? `&role=${roleFilter}` : ''}`), api<Paginated<Log>>(`/api/v1/admin/activity-logs?page=${logPage}${action.trim() ? `&action=${encodeURIComponent(action.trim())}` : ''}`)]);
        [users, logs] = result; uncertain = false; ready = true; failed = false; errors = {}; message = 'Data admin terbaru dimuat.';
    } catch (value) { error(value); } finally { busy = false; }
}
onMount(() => { void load(); });
async function create(event: SubmitEvent) {
    event.preventDefault(); if (blocked) return; busy = true; errors = {};
    try {
        const identity = (await currentUser()).data;
        if (identity.id !== user?.id || identity.role !== user?.role) { await load(); return; }
        await api('/api/v1/admin/users', { name, email, role, password, password_confirmation: confirmation });
        uncertain = true; name = ''; email = ''; page = 1;
        await load(); if (!uncertain) message = 'Akun internal berhasil dibuat.';
    } catch (value) { error(value, true); } finally { password = ''; confirmation = ''; busy = false; }
}
function openChange(row: User, value: string) { if (blocked || row.id === user?.id) return; selected = row; operation = value; nextRole = row.role; reason = ''; errors = {}; dialog?.showModal(); }
async function change(event: SubmitEvent) {
    event.preventDefault(); if (blocked || !selected) return; busy = true;
    try {
        const identity = (await currentUser()).data;
        if (identity.id !== user?.id || identity.role !== user?.role) { dialog?.close(); await load(); return; }
        await api(`/api/v1/admin/users/${selected.id}/${operation}`, { expected_role: selected.role, expected_is_active: selected.is_active, reason, ...(operation === 'role' ? { role: nextRole } : {}) });
        dialog?.close(); uncertain = true;
        await load(); if (!uncertain) message = 'Akses akun diperbarui. Sesi lama dicabut.';
    } catch (value) { error(value, true); if (!(value instanceof ApiError && value.status === 422)) dialog?.close(); }
    finally { busy = false; }
}
</script>
<svelte:head><title>Pengelolaan Admin | Lendara</title></svelte:head>
<div class="shell"><header><a class="brand" href="/">Lendara.</a><a href="/">Akun &amp; keluar</a></header><main aria-busy={busy}><h1>Pengelolaan admin</h1><button onclick={load} disabled={busy}>Periksa data terbaru</button>
{#if message}<p class="notice" class:error={failed} role={failed ? 'alert' : 'status'}>{message}{uncertain ? ' Periksa data terbaru sebelum membuat akun kembali.' : ''}</p>{/if}
{#if user && user.role !== 'admin'}<p>Halaman khusus admin.</p>{:else if !user}<p>Periksa sesi atau <a href="/">masuk kembali</a>.</p>{/if}
{#if user?.role === 'admin'}
<section><h2>Buat akun internal</h2><p>Akun baru aktif dengan satu peran. Gunakan identitas fiktif untuk demonstrasi.</p><form onsubmit={create}><fieldset disabled={blocked}>
<label for="new-name">Nama internal fiktif</label><input id="new-name" bind:value={name} required maxlength="150" />
<label for="new-email">Email akun baru</label><input id="new-email" type="email" bind:value={email} required maxlength="255" />
<label for="new-role">Peran akun baru</label><select id="new-role" bind:value={role}>{#each Object.entries(roles) as [key,label]}<option value={key}>{label}</option>{/each}</select>
<label for="new-password">Kata sandi akun baru</label><input id="new-password" type="password" bind:value={password} autocomplete="new-password" required minlength="8" maxlength="128" />
<label for="new-confirmation">Konfirmasi kata sandi baru</label><input id="new-confirmation" type="password" bind:value={confirmation} autocomplete="new-password" required minlength="8" maxlength="128" />
{#each Object.entries(errors) as [field,messages]}<p class="field-error" role="alert">{field}: {messages[0]}</p>{/each}<button type="submit">Buat akun internal</button></fieldset></form></section>
<section><h2>Akun internal</h2><form onsubmit={(event) => { event.preventDefault(); page = 1; void load(); }}><label for="search-email">Cari email lengkap</label><input id="search-email" bind:value={search} maxlength="255" /><label for="role-filter">Filter peran</label><select id="role-filter" bind:value={roleFilter}><option value="">Semua peran internal</option>{#each Object.entries(roles) as [key,label]}<option value={key}>{label}</option>{/each}</select><button disabled={busy}>Cari akun</button></form>
<div class="scroll"><table><thead><tr><th>Nama</th><th>Email</th><th>Peran</th><th>Status</th><th>Tindakan</th></tr></thead><tbody>{#each users?.data || [] as row}<tr><td>{row.name}</td><td>{row.email}</td><td>{roles[row.role]}</td><td>{row.is_active ? 'Aktif' : 'Nonaktif'}</td><td><button disabled={blocked || row.id === user?.id} onclick={() => openChange(row, 'role')}>Ubah peran {row.name}</button><button disabled={blocked || row.id === user?.id} onclick={() => openChange(row, row.is_active ? 'deactivate' : 'activate')}>{row.is_active ? 'Nonaktifkan' : 'Aktifkan'} {row.name}</button></td></tr>{/each}</tbody></table></div>
{#if users}<div class="actions"><button onclick={() => { page--; void load(); }} disabled={busy || page <= 1}>Akun sebelumnya</button><span>Halaman {users.meta.current_page}/{users.meta.last_page}</span><button onclick={() => { page++; void load(); }} disabled={busy || page >= users.meta.last_page}>Akun berikutnya</button></div>{/if}</section>
<section><h2>Log aktivitas</h2><form onsubmit={(event) => { event.preventDefault(); logPage = 1; void load(); }}><label for="log-action">Kode tindakan lengkap</label><input id="log-action" bind:value={action} maxlength="100" /><button disabled={busy}>Cari log</button></form><p>Riwayat tindakan pengguna dan sistem. Waktu ditampilkan dalam WIB.</p>
<div class="scroll"><table><thead><tr><th>Pelaku (ID)</th><th>Tindakan</th><th>Objek</th><th>Waktu WIB</th></tr></thead><tbody>{#each logs?.data || [] as row}<tr><td>{row.actor_id ?? 'Sistem'}</td><td>{row.action}</td><td>{row.target_type} #{row.target_id}</td><td>{timeWib(row.created_at)}</td></tr>{/each}</tbody></table></div>{#if logs}<div class="actions"><button onclick={() => { logPage--; void load(); }} disabled={busy || logPage <= 1}>Log sebelumnya</button><span>Halaman {logs.meta.current_page}/{logs.meta.last_page}</span><button onclick={() => { logPage++; void load(); }} disabled={busy || logPage >= logs.meta.last_page}>Log berikutnya</button></div>{/if}</section>
<p>Perubahan akun sendiri dilarang. Ubah peran memerlukan tugas aktif diselesaikan atau dilepas terlebih dahulu.</p>{/if}</main><footer>Lendara · Prototipe dengan data fiktif</footer></div>
<dialog bind:this={dialog} aria-labelledby="account-change-title"><form onsubmit={change}><h2 id="account-change-title">{operation === 'role' ? 'Ubah peran' : operation === 'deactivate' ? 'Nonaktifkan akun' : 'Aktifkan akun'}?</h2><p>{selected?.name}</p><p>{operation === 'deactivate' ? 'Semua tugas aktif dilepas dan seluruh sesi akun dicabut. Status pinjaman tetap.' : operation === 'activate' ? 'Akun dapat masuk kembali. Sesi dan tugas lama tidak dipulihkan.' : 'Peran berubah hanya jika tidak ada tugas aktif. Semua sesi lama dicabut.'}</p>{#if operation === 'role'}<label for="changed-role">Peran pengganti</label><select id="changed-role" bind:value={nextRole}>{#each Object.entries(roles) as [key,label]}<option value={key}>{label}</option>{/each}</select>{/if}<label for="change-reason">Alasan perubahan akun</label><textarea id="change-reason" bind:value={reason} required maxlength="16000"></textarea>{#each Object.entries(errors) as [field,messages]}<p class="field-error">{field}: {messages[0]}</p>{/each}<div class="actions"><button type="button" onclick={() => dialog?.close()} disabled={busy}>Batal</button><button type="submit" disabled={blocked}>Konfirmasi perubahan akun</button></div></form></dialog>
<style>dialog { width: calc(100% - 32px); max-width: 500px; border: 1px solid #dce2d8; border-radius: 12px; padding: 20px; } dialog::backdrop { background: #193c3270; } textarea { width: 100%; min-height: 100px; font: inherit; } main { display: block; min-width: 0; padding: 24px 0; } h1 { font-size: 32px; letter-spacing: -1px; } section { min-width: 0; max-width: 100%; border: 1px solid #dce2d8; border-radius: 12px; background: white; padding: 20px; margin: 20px 0; } fieldset { border: 0; padding: 0; min-width: 0; } label { display: block; margin: 12px 0 7px; } select { width: 100%; padding: 10px; } button { min-height: 44px; padding: 10px; margin-top: 12px; border: 1px solid #dce2d8; border-radius: 7px; color: #193c32; background: #edf4e6; } .scroll { max-width: 100%; overflow-x: auto; margin-top: 18px; } table { width: 100%; border-collapse: collapse; text-align: left; } th,td { padding: 10px; border-bottom: 1px solid #dce2d8; overflow-wrap: anywhere; min-width: 120px; } .actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; } p { overflow-wrap: anywhere; }</style>
