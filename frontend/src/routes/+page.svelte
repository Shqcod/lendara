<script lang="ts">
	import { onMount } from 'svelte';
	import { api, ApiError, csrf, currentUser, type User } from '#lib/api.ts';

	let user = $state<User | null>(null);
	let mode = $state<'login' | 'register'>('login');
	let name = $state('');
	let email = $state('');
	let password = $state('');
	let confirmation = $state('');
	let busy = $state(false);
	let checking = $state(true);
	let message = $state('');
	let failed = $state(false);
	let uncertain = $state(false);
	let errors = $state<Record<string, string[]>>({});

	function showError(error: unknown) {
		failed = true;
		message = error instanceof Error ? error.message : 'Permintaan gagal. Silakan coba lagi.';
		errors = error instanceof ApiError ? error.fields : {};
		uncertain = error instanceof ApiError && error.status === 0;
		if (error instanceof ApiError && [401, 403, 419].includes(error.status)) user = null;
	}

	async function checkSession() {
		busy = true;
		try {
			await csrf();
			user = (await currentUser()).data;
			failed = false;
			message = 'Sesi Anda aktif.';
			uncertain = false;
		} catch (error) {
			if (error instanceof ApiError && error.status === 401) {
				user = null;
				failed = false;
				message = 'Belum masuk. Silakan gunakan akun Anda.';
				uncertain = false;
			} else showError(error);
		} finally { busy = false; checking = false; }
	}

	onMount(() => { void checkSession(); });

	async function submit(event: SubmitEvent) {
		event.preventDefault();
		if (busy || uncertain) return;
		busy = true;
		errors = {};
		message = '';
		try {
			await csrf();
			if (mode === 'register') {
				await api('/api/v1/auth/register', { name, email, password, password_confirmation: confirmation });
				mode = 'login';
				message = 'Pendaftaran berhasil. Silakan masuk.';
			} else {
				user = (await api<{ data: User }>('/api/v1/auth/login', { email, password })).data;
				message = 'Berhasil masuk. Selamat datang di Lendara.';
			}
			failed = false;
		} catch (error) { showError(error); }
		finally { password = ''; confirmation = ''; busy = false; }
	}

	async function logout() {
		if (busy || uncertain) return;
		busy = true;
		try {
			await api('/api/v1/auth/logout', {});
			user = null;
			failed = false;
			message = 'Anda sudah keluar dengan aman.';
		} catch (error) { showError(error); }
		finally { busy = false; }
	}

	function switchMode() {
		mode = mode === 'login' ? 'register' : 'login';
		message = ''; errors = {}; password = ''; confirmation = '';
	}
</script>

<svelte:head><title>{user ? 'Beranda' : mode === 'login' ? 'Masuk' : 'Daftar'} | Lendara</title></svelte:head>

<div class="shell">
	<header>
		<a class="brand" href="/" aria-label="Lendara beranda"><span class="brand-mark" aria-hidden="true">L</span>Lendara<span class="brand-dot">.</span></a>
		<span class="prototype">Prototipe akademik</span>
	</header>
	<main>
		<section class="intro" aria-labelledby="intro-title">
			<p class="eyebrow"><span></span> LANGKAH AWAL, LEBIH TERARAH</p>
			<h1 id="intro-title">Rencana besar<br /> dimulai dari<br /> <em>langkah kecil.</em></h1>
			<p class="lead">Ruang untuk mengajukan pinjaman dan mengikuti setiap tahap peninjauannya dengan lebih jelas.</p>
			<div class="journey" aria-label="Tahapan pengajuan">
				<div><span>01</span><strong>Ajukan</strong><small>Lengkapi data dan dokumen</small></div>
				<div><span>02</span><strong>Ditinjau</strong><small>Verifikasi dan persetujuan berjenjang</small></div>
				<div><span>03</span><strong>Pantau</strong><small>Ikuti perkembangan pengajuan</small></div>
			</div>
			<p class="intro-note">Seluruh data dan dokumen dalam demonstrasi ini bersifat fiktif.</p>
		</section>
		<section class="auth-card" aria-labelledby="auth-title" aria-busy={busy}>
			<p class="eyebrow">SELAMAT DATANG DI LENDARA</p>
			<h2 id="auth-title">{user ? `Halo, ${user.name}.` : mode === 'login' ? 'Masuk ke akun Anda' : 'Mulai dengan akun baru'}</h2>
			<p class="muted">{user ? 'Anda sudah masuk. Sesi akun Anda tersimpan dengan aman.' : mode === 'login' ? 'Lanjutkan langkah Anda bersama Lendara.' : 'Daftar sebagai nasabah untuk memulai.'}</p>
			{#if checking}<p role="status">Memeriksa sesi…</p>{/if}
			{#if message}<div class:error={failed} class="notice" role={failed ? 'alert' : 'status'}>{message}</div>{/if}
			{#if user}
				<dl class="account"><div><dt>Email</dt><dd>{user.email}</dd></div><div><dt>Peran</dt><dd>{user.role}</dd></div></dl>
				{#if user.role === 'nasabah'}<a class="primary workspace-link" href="/customer">Buka ruang nasabah <span aria-hidden="true">↗</span></a>
				{:else if ['verifikator', 'penyetuju_1', 'penyetuju_2'].includes(user.role)}<a class="primary workspace-link" href="/internal">Buka antrean internal <span aria-hidden="true">↗</span></a>
				{:else}<a class="primary workspace-link" href="/admin">Buka pengelolaan admin <span aria-hidden="true">↗</span></a>{/if}
				<button class="primary" onclick={checkSession} disabled={busy}>Periksa sesi</button>
				<button class="secondary" onclick={logout} disabled={busy || uncertain}>Keluar</button>
			{:else}
				<form onsubmit={submit}>
					{#if mode === 'register'}
						<label for="name">Nama lengkap</label>
						<input id="name" name="name" bind:value={name} autocomplete="name" required maxlength="150" aria-invalid={!!errors.name} aria-describedby={errors.name ? 'name-error' : undefined} />
						{#if errors.name}<p id="name-error" class="field-error">{errors.name[0]}</p>{/if}
					{/if}
					<label for="email">Email</label>
					<input id="email" name="email" type="email" bind:value={email} autocomplete="username" placeholder="nama@contoh.test" required maxlength="255" aria-invalid={!!errors.email} aria-describedby={errors.email ? 'email-error' : undefined} />
					{#if errors.email}<p id="email-error" class="field-error">{errors.email[0]}</p>{/if}
					<label for="password">Kata sandi</label>
					<input id="password" name="password" type="password" bind:value={password} autocomplete={mode === 'login' ? 'current-password' : 'new-password'} required minlength={mode === 'register' ? 8 : undefined} maxlength="128" aria-invalid={!!errors.password} aria-describedby={errors.password ? 'password-error' : undefined} />
					{#if errors.password}<p id="password-error" class="field-error">{errors.password[0]}</p>{/if}
					{#if mode === 'register'}
						<label for="confirmation">Konfirmasi kata sandi</label>
						<input id="confirmation" name="password_confirmation" type="password" bind:value={confirmation} autocomplete="new-password" required minlength="8" maxlength="128" />
						<p class="hint">Gunakan minimal 8 karakter. Gunakan identitas fiktif untuk demonstrasi.</p>
					{/if}
					<button class="primary" type="submit" disabled={busy || uncertain}>{busy ? 'Memproses…' : mode === 'login' ? 'Masuk' : 'Daftar akun'}<span aria-hidden="true">↗</span></button>
				</form>
				<p class="switch">{mode === 'login' ? 'Belum punya akun?' : 'Sudah punya akun?'} <button onclick={switchMode} disabled={busy}>{mode === 'login' ? 'Buat akun' : 'Masuk di sini'}</button></p>
				<button class="secondary" onclick={checkSession} disabled={busy}>Periksa koneksi dan sesi</button>
			{/if}
			<div class="card-footer"><span aria-hidden="true">◇</span> Akses privat. Keputusan melalui peninjauan manusia.</div>
		</section>
	</main>
	<footer><span>© 2026 Lendara</span><span>Pengajuan yang jelas. Peninjauan yang bertahap.</span></footer>
</div>
