<script>
	import { invalidateAll } from '$app/navigation';
	import { page } from '$app/state';
	import ItemCard from '$lib/components/marketplace/ItemCard.svelte';
	import { config } from '$lib/config.js';
	import { timeSince } from '$lib/timeAgo';
	import ProfileCustomizeModal from '$components/global/ProfileCustomizeModal.svelte';
	let { data } = $props();

	function formatJoined(dateStr) {
		if (!dateStr) return '';
		const d = new Date(dateStr);
		if (isNaN(d)) return dateStr;
		return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
	}

	let user = page.data.globalUser;

	// Check if profile is hidden (profile_visible is false and viewer is not owner or friend)
	// This is computed inline in the template

	async function fetchWall(page = 1) {
		const res = await fetch(`${config.api}/user/wall/${data.user.id}?page=${page}`, {
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				Authorization: `Bearer ${data.token}`
			}
		});

		const json = await res.json();
		if (!res.ok) {
			console.error(json?.message || 'Failed to fetch wall.');
			return [];
		}

		return json || [];
	}
	let wallPromise = $state(fetchWall());

	let content = $state();
	let loading = $state(false);
	let error = $state('');

	async function deleteWallPost(postId) {
		if (!confirm('Are you sure you want to delete this wall post?')) return;

		try {
			const res = await fetch(`${config.api}/user/wall/post/${postId}`, {
				method: 'DELETE',
				headers: {
					Accept: 'application/json',
					Authorization: `Bearer ${data.token}`
				}
			});

			if (!res.ok) {
				const json = await res.json();
				console.error(json?.message || 'Failed to delete post.');
				return;
			}

			wallPromise = fetchWall();
		} catch (err) {
			console.error('Failed to delete post.', err);
		}
	}
	async function postToWall() {
		try {
			loading = true;
			const res = await fetch(`${config.api}/user/wall/${data.user.id}/post`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					Authorization: `Bearer ${data.token}`
				},
				body: JSON.stringify({ content: content })
			});
			const json = await res.json();
			if (!res.ok) {
				error = json?.message || 'Failed to post to wall.';
				return;
			}

			content = '';
			wallPromise = fetchWall();
			error = '';
		} catch (err) {
			console.error(err);
			error = 'An error occurred';
		} finally {
			loading = false;
		}
	}

	async function fetchInventory() {
		const res = await fetch(`${config.api}/user/inventory/${data.user.id}?limit=5&pagination=0`, {
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				Authorization: `Bearer ${data.token}`
			}
		});

		const json = await res.json();
		if (!res.ok) {
			console.error(json?.message || 'Failed to fetch inventory.');
			return [];
		}

		return json || [];
	}
	let inventoryPromise = $derived(fetchInventory());

	async function fetchCreations() {
		const res = await fetch(`${config.api}/user/creations/${data.user.id}?limit=10`, {
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				Authorization: `Bearer ${data.token}`
			}
		});

		const json = await res.json();
		if (!res.ok) {
			console.error(json?.message || 'Failed to fetch creations.');
			return [];
		}

		return json || [];
	}
	let creationsPromise = $derived(fetchCreations());

	let friendLoading = $state(false);
	let customizeModalOpen = $state(false);

	async function sendFriendRequest() {
		try {
			friendLoading = true;
			const res = await fetch(`${config.api}/user/friend/${data.user.id}`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					Authorization: `Bearer ${data.token}`
				}
			});
			const json = await res.json();
			if (!res.ok) {
				console.error(json?.message || 'Failed to send friend request.');
				return;
			}

			invalidateAll();
		} catch (err) {
			console.error(err);
		} finally {
			friendLoading = false;
		}
	}

	const format = new Intl.NumberFormat('en-US', {
		minimumFractionDigits: 0,
		maximumFractionDigits: 0
	});

	async function unfriend() {
		try {
			friendLoading = true;
			const res = await fetch(`${config.api}/user/unfriend/${data.user.id}`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					Authorization: `Bearer ${data.token}`
				}
			});
			const json = await res.json();
			if (!res.ok) {
				console.error(json?.message || 'Failed to unfriend.');
				return;
			}

			invalidateAll();
		} catch (err) {
			console.error(err);
		} finally {
			friendLoading = false;
		}
	}

	let tab = $state(0);
	let totalCollected = $state(0);
	let totalItems = $state(0);
	let overallPercentage = $state(0);

	async function fetchCollections() {
		const res = await fetch(`${config.api}/user/collections/${data.user.id}`, {
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				Authorization: `Bearer ${data.token}`
			}
		});

		const json = await res.json();
		if (!res.ok) {
			console.error(json?.message || 'Failed to fetch collections.');
			return [];
		}

		const collections = json?.data || [];
		totalCollected = collections.reduce((sum, c) => sum + c.collected_items, 0);
		totalItems = collections.reduce((sum, c) => sum + c.total_items, 0);
		overallPercentage = totalItems > 0 ? Math.round((totalCollected / totalItems) * 100) : 0;

		return collections;
	}
	let collectionsPromise = $derived(fetchCollections());

	// Determine if this is a visited profile (not the logged-in user's own profile)
	let isOwnProfile = $derived(data.user.id === user?.id);
	let profileTheme = $derived(data.profileCustomization?.profile_theme || 'default');
</script>
{#if data.user && !data.user.privacy?.profile_visible && data.user.id !== user?.id && data.user.friend_status !== 'friends'}
	<div class="max-w-container mx-auto px-4 py-6">
		<div class="max-w-md mx-auto border border-gray-200 rounded p-6 bg-white text-center">
			<p class="text-4xl mb-3">
				<svg
					xmlns="http://www.w3.org/2000/svg"
					width="24"
					height="24"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					stroke-width="3"
					stroke-linecap="round"
					stroke-linejoin="round"
					class="size-10 inline mb-1 text-gray-400"
					><rect width="18" height="11" x="3" y="11" rx="2" ry="2" /><path
						d="M7 11V7a5 5 0 0 1 10 0v4"
					/></svg
				>
			</p>
			<h1 class="text-xl font-bold text-gray-900 mb-2">Profile Hidden</h1>
			<p class="text-sm text-gray-600">
				This user's profile is hidden. You must be friends with them to view their profile.
			</p>
		</div>
	</div>
{:else}
	<main class="py-6">
		<!-- Apply profile theme via data attribute on the main container when viewing someone else's profile -->
		<div class="max-w-container mx-auto px-4" data-profile-theme={!isOwnProfile ? profileTheme : ''}>
			<div class="border border-gray-200 rounded p-3 mb-3">
				<div class="grid grid-cols-12 gap-4">
					<div class="col-span-2">
						<img
							class="w-lg h-lg border border-gray-200 p-2"
							src={`${config.headshotStorage}/${data.user.id}.png`}
							alt=""
						/>
					</div>
					<div class="col-span-9">
						<h2 class="text-xl font-semibold">{data.user.username}</h2>
						<p class="text-sm text-gray-600/70 mb-3">"{data.user.bubble}"</p>
						<div class="flex items-center gap-2 mb-3">
							{#if data.user.id === user?.id}
								<div>
									<button onclick={() => customizeModalOpen = true} class="btn-glossy px-4 py-1 text-sm">Customize</button>
								</div>
							{:else}
								<div>
									<button class="btn-glossy px-4 py-1 text-sm">Friend</button>
								</div>
								<div>
									<button class="btn-glossy px-4 py-1 text-sm">Trade</button>
								</div>
							{/if}
						</div>
						<div class="flex items-center justify-around w-full min-w-full">
							<div class="text-center flex-1">
								<h3 class="text-lg text-primary font-semibold">10</h3>
								<p class="text-sm text-gray-600/70">Friends</p>
							</div>
							<div class="text-center flex-1">
								<h3 class="text-lg text-primary font-semibold">10</h3>
								<p class="text-sm text-gray-600/70">Items</p>
							</div>
							<div class="text-center flex-1">
								<h3 class="text-lg text-primary font-semibold">10</h3>
								<p class="text-sm text-gray-600/70">Posts</p>
							</div>
							<div class="text-center flex-1">
								<h3 class="text-lg text-primary font-semibold">1,000</h3>
								<p class="text-sm text-gray-600/70">VAL</p>
							</div>
							<div class="text-center flex-1">
								<h3 class="text-lg text-primary font-semibold">10</h3>
								<p class="text-sm text-gray-600/70">Level</p>
							</div>
						</div>
					</div>
				</div>
			</div>
			<div class="grid grid-cols-10 gap-4 mb-3">
				<div class="col-span-2">
					<button 
						class="btn-glossy px-4 py-1 text-sm w-full {tab === 0 ? 'bg-primary text-white' : ''}"
						onclick={() => tab = 0}
					>Overview</button>
				</div>
				<div class="col-span-2">
					<button 
						class="btn-glossy px-4 py-1 text-sm w-full {tab === 1 ? 'bg-primary text-white' : ''}"
						onclick={() => tab = 1}
					>Creations</button>
				</div>
				<div class="col-span-2">
					<button 
						class="btn-glossy px-4 py-1 text-sm w-full {tab === 2 ? 'bg-primary text-white' : ''}"
						onclick={() => tab = 2}
					>Inventory</button>
				</div>
				<div class="col-span-2">
					<button 
						class="btn-glossy px-4 py-1 text-sm w-full {tab === 3 ? 'bg-primary text-white' : ''}"
						onclick={() => tab = 3}
					>Collections</button>
				</div>
				<div class="col-span-2">
					<button class="btn-glossy px-4 py-1 text-sm w-full">Friends</button>
				</div>
			</div>
			
			<!-- Tab Content -->			
			{#if tab === 0}
				<!-- Overview Tab -->
				<div class="grid grid-cols-12 gap-4">
					<div class="col-span-4">
						<div class="border border-gray-200 rounded p-3">
							<h5 class="text-sm font-semibold mb-3">Avatar</h5>
							<div class="text-center mb-3">
								<img
									class="inline h-64 w-64"
									src={`${config.avatarStorage}/${data.user.id}.png`}
									alt=""
								/>
							</div>
						</div>
					</div>
					<div class="col-span-8">
						<div class="border border-gray-200 rounded p-3 mb-5">
							<h5 class="text-sm font-semibold mb-3">About</h5>
							<p class="mb-3">
								{data.user.description}
							</p>
						</div>
						<div class="border border-gray-200 rounded p-3">
							<h5 class="text-sm font-semibold mb-3">Item Wall</h5>
							<div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
								<div class="cursor-pointer item-card card-shadow">
									<div class="relative">
										<img loading="lazy" src="http://127.0.0.1:8000/storage/items/24.png" />
										<!---->
										<!---->
									</div>
									<div class="p-2">
										<p class="text-sm font-medium text-gray-900 truncate">Dark Beanie</p>
										<span class="text-xs text-gray-400">Hats</span>
									</div>
								</div>
								<div class="cursor-pointer item-card card-shadow">
									<div class="relative">
										<img loading="lazy" src="http://127.0.0.1:8000/storage/items/24.png" />
										<!---->
										<!---->
									</div>
									<div class="p-2">
										<p class="text-sm font-medium text-gray-900 truncate">Dark Beanie</p>
										<span class="text-xs text-gray-400">Hats</span>
									</div>
								</div>
								<div class="cursor-pointer item-card card-shadow">
									<div class="relative">
										<img loading="lazy" src="http://127.0.0.1:8000/storage/items/24.png" />
										<!---->
										<!---->
									</div>
									<div class="p-2">
										<p class="text-sm font-medium text-gray-900 truncate">Dark Beanie</p>
										<span class="text-xs text-gray-400">Hats</span>
									</div>
								</div>
								<div class="cursor-pointer item-card card-shadow">
									<div class="relative">
										<img loading="lazy" src="http://127.0.0.1:8000/storage/items/24.png" />
										<!---->
										<!---->
									</div>
									<div class="p-2">
										<p class="text-sm font-medium text-gray-900 truncate">Dark Beanie</p>
										<span class="text-xs text-gray-400">Hats</span>
									</div>
								</div>
								<div class="cursor-pointer item-card card-shadow">
									<div class="relative">
										<img loading="lazy" src="http://127.0.0.1:8000/storage/items/24.png" />
										<!---->
										<!---->
									</div>
									<div class="p-2">
										<p class="text-sm font-medium text-gray-900 truncate">Dark Beanie</p>
										<span class="text-xs text-gray-400">Hats</span>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			{:else if tab === 1}
				<!-- Creations Tab -->
				<div class="border border-gray-200 rounded p-3">
					<h3 class="text-sm font-semibold mb-4">Creations</h3>
					{#await creationsPromise}
						<p class="text-center text-sm text-gray-500 py-8">Loading creations...</p>
					{:then creations}
						{#if creations.length === 0}
							<p class="text-center text-sm text-gray-500 py-8">No creations yet.</p>
						{:else}
							<div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-3">
								{#each creations as creation}
									<ItemCard 
										item={creation}
										formatter={format}
									/>
								{/each}
							</div>
						{/if}
					{:catch error}
						<p class="text-center text-red-500 py-8">Failed to load creations.</p>
					{/await}
				</div>
			{:else if tab === 2}
				<!-- Inventory Tab -->
				<div class="border border-gray-200 rounded p-3">
					<h3 class="text-sm font-semibold mb-4">Inventory</h3>
					{#await inventoryPromise}
						<p class="text-center text-sm text-gray-500 py-8">Loading inventory...</p>
					{:then inventory}
						{#if !inventory || inventory.length === 0}
							<p class="text-center text-sm text-gray-500 py-8">No items in inventory.</p>
						{:else}
							<div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-3">
								{#each inventory as item}
									<ItemCard 
										item={item.item}
										serial={item.serial}
										formatter={format}
									/>
								{/each}
							</div>
						{/if}
					{:catch error}
						<p class="text-center text-red-500 py-8">Failed to load inventory.</p>
					{/await}
				</div>
			{:else if tab === 3}
				<!-- Collections Tab -->
				{#await collectionsPromise}
					<p class="text-center text-sm text-gray-500 py-8">Loading collections...</p>
				{:then collections}
					{#if collections.length === 0}
						<div class="border border-gray-200 rounded p-8 text-center">
							<p class="text-gray-500">No collections available.</p>
						</div>
					{:else}
						<div class="grid grid-cols-2 gap-4">
							<div class="border border-gray-200 rounded p-3">
								<h3 class="text-sm font-semibold mb-3">Collections</h3>
								<div class="max-h-80 overflow-y-auto space-y-0">
								{#each collections as collection}
									<div class="flex items-center justify-between py-2 px-2 border-b border-gray-100 last:border-b-0">
										<span class="text-sm font-medium" style="color: {collection.status === 'completed' ? '#22c55e' : collection.status === 'in_progress' ? '#eab308' : '#6b7280'}">{collection.name}</span>
										{#if collection.status !== 'not_started'}
											<span class="text-xs text-gray-500">{collection.percentage}%</span>
										{/if}
									</div>
								{/each}
								</div>
								<!-- {@const totalCollected = collections.reduce((sum, c) => sum + c.collected_items, 0)}
								{@const totalItems = collections.reduce((sum, c) => sum + c.total_items, 0)}
								{@const overallPercentage = totalItems > 0 ? Math.round((totalCollected / totalItems) * 100) : 0} -->
								<div class="mt-3 pt-3 border-t border-gray-200 text-sm text-gray-600 text-center">
									Collected items: {totalCollected} / {totalItems} ({overallPercentage}%)
								</div>
							</div>
							<div class="border border-gray-200 rounded p-3">
								<!-- Second column - empty for now -->
							</div>
						</div>
					{/if}
				{:catch error}
					<p class="text-center text-red-500 py-8">Failed to load collections.</p>
				{/await}
			{:else}
				<!-- Other tabs placeholder -->
				<div class="border border-gray-200 rounded p-8 text-center">
					<p class="text-gray-500">Coming soon...</p>
				</div>
			{/if}
		</div>
	</main>
{/if}

<ProfileCustomizeModal bind:open={customizeModalOpen} token={data.token} userId={data.user.id} />

</script>

<style>
  /* Profile theme applied only when viewing another user's profile */
  div[data-profile-theme="dark"] {
    background-color: #1a1a1a;
    color: #e0e0e0;
  }
  div[data-profile-theme="dark"] .border-gray-200 {
    border-color: #333;
  }
  div[data-profile-theme="dark"] h2,
  div[data-profile-theme="dark"] h3,
  div[data-profile-theme="dark"] .text-gray-900 {
    color: #e0e0e0;
  }
  div[data-profile-theme="dark"] .text-gray-600,
  div[data-profile-theme="dark"] .text-gray-600\/70 {
    color: #b0b0b0;
  }

  div[data-profile-theme="light"] {
    background-color: #f5f5f5;
  }
</style>
</write_to_file>