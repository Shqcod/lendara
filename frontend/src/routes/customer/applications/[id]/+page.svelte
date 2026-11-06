<script lang="ts">
	import SubmissionWorkspace from '#lib/SubmissionWorkspace.svelte';
	let { data }: { data: { id: string } } = $props();
</script>

<svelte:head><title>Pengiriman Pengajuan | Lendara</title></svelte:head>
{#key data.id}<SubmissionWorkspace applicationId={data.id} />{/key}
