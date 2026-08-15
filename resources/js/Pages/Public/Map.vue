<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import RenderedContent from '@/Components/RenderedContent.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    campaign: Object,
    sections: Array,
    viewer: { type: Object, default: () => ({}) },
    map: Object,
});

/* ---- read-only pan & zoom ---- */
const viewport = ref(null);
const view = reactive({ scale: 1, tx: 0, ty: 0 });
const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

const fitToViewport = (event) => {
    const el = viewport.value;
    if (!el) return;
    view.scale = el.clientWidth / event.target.naturalWidth || 1;
    view.tx = 0;
    view.ty = 0;
};
const onWheel = (event) => {
    const rect = viewport.value.getBoundingClientRect();
    const mx = event.clientX - rect.left;
    const my = event.clientY - rect.top;
    const next = clamp(view.scale * (1 - event.deltaY * 0.0015), 0.15, 10);
    view.tx = mx - (mx - view.tx) * (next / view.scale);
    view.ty = my - (my - view.ty) * (next / view.scale);
    view.scale = next;
};
let drag = null;
const onPointerDown = (event) => {
    drag = { x: event.clientX, y: event.clientY, tx: view.tx, ty: view.ty };
    viewport.value.setPointerCapture(event.pointerId);
};
const onPointerMove = (event) => {
    if (!drag) return;
    view.tx = drag.tx + (event.clientX - drag.x);
    view.ty = drag.ty + (event.clientY - drag.y);
};
const onPointerUp = () => { drag = null; };

/* ---- location info panel + info-pin popups ---- */
const expanded = ref(false);
const activePopup = ref(null);
const openPin = (pin) => { if (pin.action === 'info') activePopup.value = pin.popup; };

/* ---- realtime: refresh when the GM changes this map ---- */
let reloadTimer;
let channelName;
const onMapChanged = () => {
    clearTimeout(reloadTimer);
    reloadTimer = setTimeout(() => router.reload({ only: ['map'], preserveScroll: true, preserveState: true }), 250);
};
onMounted(() => {
    if (window.Echo) {
        channelName = `maps.${props.map.id}`;
        window.Echo.channel(channelName).listen('.MapChanged', onMapChanged);
    }
});
onBeforeUnmount(() => {
    clearTimeout(reloadTimer);
    if (window.Echo && channelName) window.Echo.leave(channelName);
});
</script>

<template>
    <Head :title="`${map.name} — ${campaign.name}`" />

    <PublicLayout :campaign="campaign" :sections="sections" :viewer="viewer" active="maps">
        <div class="mx-auto max-w-6xl px-6 py-8">
            <div class="mb-4 flex items-center gap-3">
                <Link :href="`/w/${campaign.slug}/maps`" class="text-sm text-muted hover:text-teal">← Maps</Link>
                <h1 class="font-display text-[28px] text-bright">{{ map.name }}</h1>
            </div>

            <div
                ref="viewport"
                class="relative h-[calc(100vh-14rem)] cursor-grab overflow-hidden rounded-lg border border-edge2 bg-[#0b0d10]"
                @wheel.prevent="onWheel"
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
            >
                <div v-if="map.image_url" class="absolute left-0 top-0 origin-top-left" :style="{ transform: `translate(${view.tx}px, ${view.ty}px) scale(${view.scale})` }">
                    <img :src="map.image_url" :alt="map.name" class="block max-w-none select-none" draggable="false" @load="fitToViewport" />

                    <component
                        :is="p.href ? 'a' : (p.action === 'info' ? 'button' : 'span')"
                        v-for="p in map.pins" :key="p.id"
                        :href="p.href || undefined"
                        :title="p.action === 'travel' ? `Travel to ${p.label}` : (p.action === 'article' ? `Open ${p.label}` : p.label)"
                        class="group absolute -translate-x-1/2 -translate-y-full"
                        :class="(p.href || p.action === 'info') ? 'cursor-pointer' : 'cursor-default'"
                        :style="{ left: p.x + '%', top: p.y + '%' }"
                        @pointerdown.stop
                        @click="p.action === 'info' ? openPin(p) : null"
                    >
                        <span class="flex flex-col items-center" :style="{ transform: `scale(${1 / view.scale})`, transformOrigin: 'bottom center' }">
                            <span v-if="p.label" class="whitespace-nowrap rounded bg-black/70 px-1.5 py-0.5 text-[11px] text-ink" :class="(p.href || p.action === 'info') ? 'group-hover:underline' : ''">{{ p.label }}</span>
                            <span
                                class="mt-0.5 h-3.5 w-3.5 rounded-full border-2 border-white shadow transition group-hover:scale-125"
                                :class="(p.action === 'travel' || p.action === 'article') ? 'bg-teal' : (p.action === 'info' ? 'bg-amber' : 'bg-white/50')"
                            ></span>
                        </span>
                    </component>
                </div>
                <div v-else class="absolute inset-0 flex items-center justify-center text-faint">This map has no image yet.</div>

                <!-- Expand-to-article toggle (only when this map depicts a location) -->
                <button
                    v-if="map.location"
                    class="absolute right-3 top-3 z-20 rounded-md border border-edge3 bg-surface/90 px-3 py-1.5 text-sm text-muted shadow backdrop-blur hover:text-ink"
                    @pointerdown.stop @click="expanded = !expanded"
                >{{ expanded ? 'Hide info' : 'ⓘ Location info' }}</button>

                <!-- Location article panel -->
                <div
                    v-if="expanded && map.location"
                    class="absolute inset-y-0 left-0 z-20 w-[min(360px,85%)] overflow-y-auto border-r border-edge2 bg-surface/95 p-6 shadow-2xl backdrop-blur"
                    @pointerdown.stop @wheel.stop
                >
                    <div class="mb-3 flex items-start justify-between gap-2">
                        <div>
                            <div class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-teal">{{ map.location.kindLabel }}</div>
                            <h2 class="font-display text-[26px] leading-tight text-bright">{{ map.location.title }}</h2>
                            <a :href="map.location.href" class="text-sm text-teal hover:underline">Open full article →</a>
                        </div>
                        <button class="text-faint hover:text-ink" @click="expanded = false">✕</button>
                    </div>
                    <RenderedContent :content="map.location.content" :gm="viewer.gmView" single-column />
                </div>

                <!-- Info-pin popup -->
                <div
                    v-if="activePopup"
                    class="absolute bottom-4 left-4 z-20 w-72 rounded-lg border border-edge2 bg-surface p-4 shadow-2xl"
                    @pointerdown.stop
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="font-display text-lg text-bright">{{ activePopup.title }}</div>
                        <button class="text-faint hover:text-ink" @click="activePopup = null">✕</button>
                    </div>
                    <p v-if="activePopup.summary" class="mt-1 text-sm text-muted">{{ activePopup.summary }}</p>
                    <a v-if="activePopup.href" :href="activePopup.href" class="mt-2 inline-block text-sm text-teal hover:underline">Open article →</a>
                </div>
            </div>
            <p class="mt-2 font-mono text-[11px] text-faint">Scroll to zoom · drag to pan · teal marker = open · amber marker = info.</p>
        </div>
    </PublicLayout>
</template>
