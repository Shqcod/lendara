<script lang="ts">
	import favicon from '#lib/assets/lendara.svg';
	import type { LayoutProps } from './$types';
	import '../app.css';

	let { children }: LayoutProps = $props();
</script>

<svelte:head>
	<link rel="icon" href={favicon} />
	<meta name="description" content="Lendara adalah prototipe akademik pengajuan pinjaman dengan deteksi anomali dan persetujuan berjenjang." />
	<meta property="og:site_name" content="Lendara" />
</svelte:head>

{@render children()}
