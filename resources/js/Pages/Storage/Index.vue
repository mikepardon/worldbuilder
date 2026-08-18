<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    usage: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    recordings: { type: Array, default: () => [] },
    media: { type: Array, default: () => [] },
    avatar: { type: Object, default: () => ({}) },
});

// Each category's share of what's currently used, for the little breakdown bars.
const usedBytes = computed(() => props.usage.used_bytes || 0);
const share = (bytes) => (usedBytes.value > 0 ? Math.round((bytes / usedBytes.value) * 100) : 0);

const barColour = computed(() => (props.usage.over_limit ? "bg-amber" : "bg-teal"));

// Track which row's request is in flight so we can disable just that button.
const busy = ref(null);

const deleteRecording = (item) => {
    if (
        !window.confirm(
            `Delete the audio for "${item.title}"? This frees ${item.size_display} and can't be undone. Your saved recap text is kept.`,
        )
    )
        return;

    busy.value = `recording-${item.id}`;
    router.delete(route("storage.recordings.destroy", item.id), {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
};

const deleteMedia = (item) => {
    if (
        !window.confirm(
            `Delete "${item.filename}"? This removes the file for good and frees ${item.size_display}.`,
        )
    )
        return;

    busy.value = `media-${item.id}`;
    router.delete(route("media.destroy", item.id), {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
};

const removeAvatar = () => {
    if (!window.confirm("Remove your avatar? This frees its storage.")) return;

    busy.value = "avatar";
    router.delete(route("profile.avatar.destroy"), {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
};
</script>

<template>
    <Head title="Storage" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl px-4 py-8">
            <div class="mb-8 flex flex-col gap-1.5">
                <div class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber">
                    Account
                </div>
                <div class="font-display text-[32px] leading-[1.05] text-bright">
                    Storage
                </div>
            </div>

            <!-- Total usage + breakdown -->
            <section class="panel mb-8 border-amber/30 p-5">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <div class="eyebrow-muted mb-1">Storage used</div>
                        <div class="font-display text-2xl text-bright">
                            {{ usage.used_display }}<span class="text-base text-faint"> / {{ usage.limit_display }}</span>
                        </div>
                    </div>
                    <div class="font-mono text-xs text-faint">{{ usage.percent }}% of your plan</div>
                </div>

                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-raised">
                    <div
                        class="h-full rounded-full transition-all"
                        :class="barColour"
                        :style="{ width: Math.min(100, usage.percent) + '%' }"
                    />
                </div>

                <p
                    v-if="usage.over_limit && usage.is_admin"
                    class="mt-3 rounded-md border border-amber/30 bg-amber/10 px-4 py-2.5 text-sm text-amber"
                >
                    You're over the plan limit, but as an admin your account is exempt from storage quotas —
                    uploads aren't blocked for you.
                </p>
                <p
                    v-else-if="usage.over_limit"
                    class="mt-3 rounded-md border border-amber/30 bg-amber/10 px-4 py-2.5 text-sm text-amber"
                >
                    You're over your plan's storage limit. Delete some recordings or media below, or upgrade
                    your plan for more space.
                </p>

                <!-- Per-category breakdown -->
                <div class="mt-5 space-y-3">
                    <div v-for="cat in categories" :key="cat.key">
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="text-muted">{{ cat.label }}</span>
                            <span class="font-mono text-xs text-faint">{{ cat.display }}</span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-raised">
                            <div class="h-full rounded-full bg-edge3" :style="{ width: share(cat.bytes) + '%' }" />
                        </div>
                    </div>
                </div>
            </section>

            <!-- Recordings -->
            <section class="mb-8">
                <div class="mb-2 eyebrow-muted">Recordings</div>
                <p class="mb-4 text-sm text-muted">
                    Session-recording audio. Deleting the audio frees its space but keeps the transcribed recap
                    — you just won't be able to re-transcribe it afterwards.
                </p>

                <div v-if="recordings.length === 0" class="panel p-4 text-sm text-muted">
                    No stored recording audio.
                </div>

                <div v-else class="space-y-2">
                    <div
                        v-for="item in recordings"
                        :key="item.id"
                        class="panel flex flex-wrap items-center justify-between gap-3 p-4"
                    >
                        <div class="min-w-0">
                            <div class="truncate font-medium text-ink">{{ item.title }}</div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-[11px] text-faint">
                                <span v-if="item.campaign">{{ item.campaign }}</span>
                                <span v-if="item.campaign" aria-hidden="true">·</span>
                                <span>{{ item.size_display }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ item.duration }}</span>
                                <span
                                    v-if="item.recap_kept"
                                    class="rounded-full bg-teal/15 px-2 py-0.5 text-[10px] uppercase tracking-wide text-teal"
                                >Recap saved</span>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 rounded-md border border-edge3 px-3 py-1.5 text-sm text-ink transition hover:border-amber hover:text-amber disabled:opacity-50"
                            :disabled="busy === `recording-${item.id}`"
                            @click="deleteRecording(item)"
                        >
                            {{ busy === `recording-${item.id}` ? "Deleting…" : "Delete audio" }}
                        </button>
                    </div>
                </div>
            </section>

            <!-- Media -->
            <section class="mb-8">
                <div class="mb-2 eyebrow-muted">Media &amp; images</div>
                <p class="mb-4 text-sm text-muted">Images and files uploaded to your worlds.</p>

                <div v-if="media.length === 0" class="panel p-4 text-sm text-muted">
                    No uploaded media.
                </div>

                <div v-else class="space-y-2">
                    <div
                        v-for="item in media"
                        :key="item.id"
                        class="panel flex flex-wrap items-center justify-between gap-3 p-4"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <img
                                v-if="item.mime && item.mime.startsWith('image/')"
                                :src="item.url"
                                alt=""
                                class="h-10 w-10 shrink-0 rounded object-cover"
                            />
                            <div class="min-w-0">
                                <div class="truncate font-medium text-ink">{{ item.filename }}</div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 font-mono text-[11px] text-faint">
                                    <span v-if="item.world">{{ item.world }}</span>
                                    <span v-if="item.world" aria-hidden="true">·</span>
                                    <span>{{ item.size_display }}</span>
                                </div>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 rounded-md border border-edge3 px-3 py-1.5 text-sm text-ink transition hover:border-amber hover:text-amber disabled:opacity-50"
                            :disabled="busy === `media-${item.id}`"
                            @click="deleteMedia(item)"
                        >
                            {{ busy === `media-${item.id}` ? "Deleting…" : "Delete" }}
                        </button>
                    </div>
                </div>
            </section>

            <!-- Avatar -->
            <section class="mb-8">
                <div class="mb-2 eyebrow-muted">Avatar</div>
                <div class="panel flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="font-mono text-[11px] text-faint">
                        <span v-if="avatar.has_avatar">{{ avatar.size_display }}</span>
                        <span v-else>No avatar uploaded.</span>
                    </div>
                    <button
                        v-if="avatar.has_avatar"
                        type="button"
                        class="shrink-0 rounded-md border border-edge3 px-3 py-1.5 text-sm text-ink transition hover:border-amber hover:text-amber disabled:opacity-50"
                        :disabled="busy === 'avatar'"
                        @click="removeAvatar"
                    >
                        {{ busy === "avatar" ? "Removing…" : "Remove avatar" }}
                    </button>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
