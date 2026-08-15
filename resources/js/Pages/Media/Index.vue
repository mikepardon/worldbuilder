<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import UppyUploader from "@/Components/UppyUploader.vue";
import { Head, router } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
    world: Object,
    campaign: Object,
    items: Array,
    limits: Object,
});

const uppyAccept = computed(() => props.limits.accept.map((e) => "." + e));
const maxMb = computed(() => Math.round(props.limits.maxSizeKb / 1024));

// Refresh the grid once a batch finishes uploading.
const onComplete = () =>
    router.reload({ only: ["items"], preserveScroll: true });

const copy = (url) => navigator.clipboard?.writeText(url);

const remove = (item) => {
    if (confirm(`Delete “${item.filename}”? This removes the file for good.`)) {
        router.delete(route("media.destroy", item.id), {
            preserveScroll: true,
        });
    }
};

const prettySize = (bytes) => {
    if (!bytes) return "";
    const kb = bytes / 1024;
    return kb < 1024 ? kb.toFixed(0) + " KB" : (kb / 1024).toFixed(1) + " MB";
};

const isImage = (mime) => (mime || "").startsWith("image/");
</script>

<template>
    <Head title="Media library" />

    <WorldLayout :world="world">
        <div class="flex items-end justify-between gap-5">
            <div class="flex flex-col gap-1.5">
                <div
                    class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber"
                >
                    {{ campaign.name }}
                </div>
                <div
                    class="font-display text-[32px] leading-[1.05] text-bright"
                >
                    Media library
                </div>
            </div>
            <div class="font-mono text-[11px] text-faint">
                {{ items.length }} file{{ items.length === 1 ? "" : "s" }}
            </div>
        </div>

        <p class="max-w-2xl text-sm text-muted">
            Art and reference images for this world. Serving from
            <span class="font-mono text-faint">{{ limits.disk }}</span
            >.
        </p>

        <!-- Upload (Uppy — drag many files at once) -->
        <UppyUploader
            :endpoint="route('media.store', campaign.id)"
            :allowed-file-types="uppyAccept"
            :max-file-size-mb="maxMb"
            :max-number-of-files="50"
            :note="`${limits.accept.join(', ')} · up to ${maxMb} MB`"
            @complete="onComplete"
        />

        <!-- Grid -->
        <div
            v-if="items.length"
            class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4"
        >
            <div
                v-for="item in items"
                :key="item.id"
                class="group flex flex-col overflow-hidden rounded-[10px] border border-edge2 bg-[#161920]"
            >
                <div class="relative aspect-[4/3] bg-[#0f1116]">
                    <img
                        v-if="isImage(item.mime)"
                        :src="item.url"
                        :alt="item.alt || item.filename"
                        class="h-full w-full object-cover"
                    />
                    <div
                        v-else
                        class="flex h-full items-center justify-center font-mono text-[11px] text-faint"
                    >
                        {{ item.mime || "file" }}
                    </div>

                    <!-- hover actions -->
                    <div
                        class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 bg-black/70 opacity-0 transition group-hover:opacity-100"
                    >
                        <button
                            class="rounded-md border border-[#3d4250] bg-surface px-3 py-1 font-mono text-[10px] uppercase tracking-[0.1em] text-ink hover:border-teal hover:text-teal"
                            @click="copy(item.url)"
                        >
                            Copy URL
                        </button>
                        <button
                            class="rounded-md border border-blood/40 bg-blood/10 px-3 py-1 font-mono text-[10px] uppercase tracking-[0.1em] text-red-300 hover:border-blood"
                            @click="remove(item)"
                        >
                            Delete
                        </button>
                    </div>
                </div>
                <div class="flex flex-col gap-0.5 px-3 py-2">
                    <div
                        class="truncate text-[13px] text-ink"
                        :title="item.filename"
                    >
                        {{ item.filename }}
                    </div>
                    <div class="font-mono text-[10px] text-faint">
                        {{ prettySize(item.size) }}
                    </div>
                </div>
            </div>
        </div>

        <div
            v-else
            class="rounded-[10px] border border-dashed border-edge3 px-6 py-10 text-center text-[15px] font-light text-faint"
        >
            Nothing here yet. Drop an image above to start the library.
        </div>
    </WorldLayout>
</template>
