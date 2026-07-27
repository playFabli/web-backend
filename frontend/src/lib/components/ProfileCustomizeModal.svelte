<script lang="ts">
	import { config } from '$lib/config.js';
	import { invalidateAll } from '$app/navigation';

	let { open = $bindable(false), token, userId, onUpdated }: {
		open: boolean;
		token: string;
		userId: number;
		onUpdated?: () => void;
	} = $props();

	let profileTheme = $state<string>('default');
	let profileBanner = $state<string>('none');
	let avatarFrame = $state<string>('none');

	let themes = $state<Array<{id: string, name: string, preview: string | null}>>([]);
	let banners = $state<Array<{id: string, name: string, preview: string | null}>>([]);
	let frames = $state<Array<{id: string, name: string, preview: string | null}>>([]);

	let loading = $state(true);
	let saving = $state(false);

	async function loadCustomization() {
		try {
			const res = await fetch(`${config.api}/marketplace/profile-customization`, {
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					Authorization: `Bearer ${token}`
				}
			});

			const json = await res.json();
			if (!res.ok) {
				console.error(json?.message || 'Failed to load profile customization.');
				return;
			}

			const data = json.data;
			profileTheme = data.profile_theme || 'default';
			profileBanner = data.profile_banner || 'none';
			avatarFrame = data.avatar_frame || 'none';
			themes = data.available_themes || [];
			banners = data.available_banners || [];
			frames = data.available_frames || [];
			loading = false;
		} catch (err) {
			console.error('Failed to load profile customization.', err);
			loading = false;
		}
	}

	function openModal() {
		open = true;
		loadCustomization();
	}

	function closeModal() {
		open = false;
	}

	async function saveCustomization() {
		try {
			saving = true;
			const res = await fetch(`${config.api}/marketplace/profile-customization`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					Authorization: `Bearer ${token}`
				},
				body: JSON.stringify({
					profile_theme: profileTheme,
					profile_banner: profileBanner,
					avatar_frame: avatarFrame
				})
			});

			const json = await res.json();
			if (!res.ok) {
				console.error(json?.message || 'Failed to save profile customization.');
				return;
			}

			invalidateAll();
			if (onUpdated) onUpdated();
			closeModal();
		} catch (err) {
			console.error('Failed to save profile customization.', err);
		} finally {
			saving = false;
		}
	}

	async function selectTheme(themeId: string) {
		profileTheme = themeId;
	}

	async function selectBanner(bannerId: string) {
		profileBanner = bannerId;
	}

	async function selectFrame(frameId: string) {
		avatarFrame = frameId;
	}
</script>

{#if open}
	<div class="modal-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 100;">
		<div class="modal" style="background: white; border: 1px solid #e5e7eb; border-radius: 4px; box-shadow: 0 8px 24px rgba(0,0,0,0.12), 0 2px 6px rgba(0,0,0,0.08); width: 90%; max-width: 560px;">
			<div class="modal-header" style="padding: 0.75rem 1rem; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between;">
				<h2 class="text-base font-semibold text-gray-900">Customize Profile</h2>
				<button onclick={closeModal} class="close-btn" style="background: none; border: none; font-size: 1.25rem; color: #6b7280; cursor: pointer; line-height: 1; padding: 0 0.25rem;">&times;</button>
			</div>
			<div class="modal-body" style="padding: 1rem;">
				{#if loading}
					<p class="text-neutral-500 text-sm">Loading customization options...</p>
				{:else}
					<div class="mb-4">
						<h3 class="text-sm font-semibold text-gray-900 mb-2">Profile Theme</h3>
						<div class="grid grid-cols-3 gap-2">
							{#each themes as theme}
								<button type="button" onclick={() => selectTheme(theme.id)} class="border rounded p-2 text-center text-xs hover:border-[#A2574F] {profileTheme === theme.id ? 'border-[#A2574F] ring-1 ring-[#A2574F]' : 'border-gray-200'}">
									<div class="w-full h-16 bg-gray-100 rounded mb-1 flex items-center justify-center">
										{#if theme.preview}
											<img src="{theme.preview}" alt="{theme.name}" class="max-h-full max-w-full rounded" />
										{:else}
											<span class="text-gray-400 text-[10px]">Preview</span>
										{/if}
									</div>
									<div class="text-gray-700">{theme.name}</div>
								</button>
							{/each}
						</div>
					</div>

					<div class="mb-4">
						<h3 class="text-sm font-semibold text-gray-900 mb-2">Profile Banner</h3>
						<div class="grid grid-cols-3 gap-2">
							{#each banners as banner}
								<button type="button" onclick={() => selectBanner(banner.id)} class="border rounded p-2 text-center text-xs hover:border-[#A2574F] {profileBanner === banner.id ? 'border-[#A2574F] ring-1 ring-[#A2574F]' : 'border-gray-200'}">
									<div class="w-full h-16 bg-gray-100 rounded mb-1 flex items-center justify-center">
										{#if banner.preview}
											<img src="{banner.preview}" alt="{banner.name}" class="max-h-full max-w-full rounded" />
										{:else}
											<span class="text-gray-400 text-[10px]">None</span>
										{/if}
									</div>
									<div class="text-gray-700">{banner.name}</div>
								</button>
							{/each}
						</div>
					</div>

					<div class="mb-2">
						<h3 class="text-sm font-semibold text-gray-900 mb-2">Avatar Frame</h3>
						<div class="grid grid-cols-3 gap-2">
							{#each frames as frame}
								<button type="button" onclick={() => selectFrame(frame.id)} class="border rounded p-2 text-center text-xs hover:border-[#A2574F] {avatarFrame === frame.id ? 'border-[#A2574F] ring-1 ring-[#A2574F]' : 'border-gray-200'}">
									<div class="w-full h-16 bg-gray-100 rounded mb-1 flex items-center justify-center">
										{#if frame.preview}
											<img src="{frame.preview}" alt="{frame.name}" class="max-h-full max-w-full rounded" />
										{:else}
											<span class="text-gray-400 text-[10px]">None</span>
										{/if}
									</div>
									<div class="text-gray-700">{frame.name}</div>
								</button>
							{/each}
						</div>
					</div>
				{/if}
			</div>
			{#if !loading}
			<div style="padding: 0.75rem 1rem; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 0.5rem;">
				<button onclick={closeModal} class="btn-secondary px-3 py-1.5 text-sm">Cancel</button>
				<button onclick={saveCustomization} disabled={saving} class="btn-glossy px-3 py-1.5 text-sm">{saving ? 'Saving...' : 'Save'}</button>
			</div>
			{/if}
		</div>
	</div>
{/if}
</script>

<style>
.modal-overlay {
	font-family: inherit;
}
.close-btn:hover {
	color: #111827;
}
</style>