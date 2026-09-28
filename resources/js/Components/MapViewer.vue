<script setup>
// Read-only interactive map for the public reader: pan, zoom, clickable pins, and a measure tool that
// reports real distances from the map's width + distance unit. Fills whatever container it's placed in.
import { computed, reactive, ref } from "vue";

const props = defineProps({
    map: { type: Object, required: true },
    campaignSlug: { type: String, required: true },
});

const UNIT_LABELS = { feet: "Feet", yards: "Yards", miles: "Miles", km: "Km" };
// Map distance units → kilometres, so per-day travel speeds (kept in km) work whatever unit the map uses.
const UNIT_TO_KM = { feet: 0.0003048, yards: 0.0009144, miles: 1.609344, km: 1 };

// A day count as a short, readable label: whole days, or hours once it drops below a day.
const formatDuration = (days) => {
    if (days >= 1) {
        const whole = Math.round(days);
        return `${whole.toLocaleString()} ${whole === 1 ? "day" : "days"}`;
    }
    const hours = Math.max(1, Math.round(days * 24));
    return `${hours} ${hours === 1 ? "hr" : "hrs"}`;
};

const viewport = ref(null);
const natW = ref(0);
const natH = ref(0);
const view = reactive({ scale: 1, tx: 0, ty: 0 });
const transform = computed(() => ({
    transform: `translate(${view.tx}px, ${view.ty}px) scale(${view.scale})`,
}));

const clamp = (v, min, max) => Math.min(max, Math.max(min, v));

const fit = () => {
    const el = viewport.value;
    if (!el || !natW.value) return;
    const s = Math.min(
        el.clientWidth / natW.value,
        el.clientHeight / natH.value,
    );
    view.scale = s;
    view.tx = (el.clientWidth - natW.value * s) / 2;
    view.ty = (el.clientHeight - natH.value * s) / 2;
};
const onImgLoad = (event) => {
    natW.value = event.target.naturalWidth;
    natH.value = event.target.naturalHeight;
    fit();
};

// Screen point → image-natural pixel coordinate (inverts the pan/zoom transform).
const toImagePx = (event) => {
    const rect = viewport.value.getBoundingClientRect();
    return {
        px: (event.clientX - rect.left - view.tx) / view.scale,
        py: (event.clientY - rect.top - view.ty) / view.scale,
    };
};

const zoomAt = (cx, cy, factor) => {
    const next = clamp(view.scale * factor, 0.05, 12);
    view.tx = cx - (cx - view.tx) * (next / view.scale);
    view.ty = cy - (cy - view.ty) * (next / view.scale);
    view.scale = next;
};
const onWheel = (event) => {
    const rect = viewport.value.getBoundingClientRect();
    zoomAt(
        event.clientX - rect.left,
        event.clientY - rect.top,
        1 - event.deltaY * 0.0015,
    );
};
const zoomButton = (factor) => {
    const el = viewport.value;
    zoomAt(el.clientWidth / 2, el.clientHeight / 2, factor);
};

/* ---- measure tool: a multi-point route, distance summed segment by segment ---- */
const measuring = ref(false);
const route = ref([]); // ordered [{ px, py }, ...] in image-natural coordinates
let dragIndex = null; // the waypoint currently being dragged, or null
const toggleMeasure = () => {
    measuring.value = !measuring.value;
    if (!measuring.value) route.value = [];
};
const clearRoute = () => {
    route.value = [];
};

const routePoints = computed(() =>
    route.value.map((p) => `${p.px},${p.py}`).join(" "),
);

const routeDistancePx = computed(() => {
    let total = 0;
    for (let i = 1; i < route.value.length; i++) {
        total += Math.hypot(
            route.value[i].px - route.value[i - 1].px,
            route.value[i].py - route.value[i - 1].py,
        );
    }
    return total;
});

// The route length in the map's real-world unit, or undefined when the map has no scale set.
const distanceUnits = computed(() => {
    if (!props.map.real_width || !natW.value) return undefined;
    return routeDistancePx.value * (Number(props.map.real_width) / natW.value);
});

const distanceLabel = computed(() => {
    if (route.value.length < 2) return "";
    if (distanceUnits.value !== undefined) {
        const label = UNIT_LABELS[props.map.distance_unit] ?? "";
        return `${Math.round(distanceUnits.value).toLocaleString()} ${label}`.trim();
    }
    return `${Math.round(routeDistancePx.value).toLocaleString()} px`;
});

// Per-mode travel time for the current route, empty unless the map has a scale and configured modes.
const travelTimes = computed(() => {
    const modes = props.map.travel_modes;
    const toKm = UNIT_TO_KM[props.map.distance_unit];
    if (
        !Array.isArray(modes) ||
        modes.length === 0 ||
        distanceUnits.value === undefined ||
        !toKm
    ) {
        return [];
    }
    const km = distanceUnits.value * toKm;
    return modes
        .filter((mode) => Number(mode.per_day) > 0)
        .map((mode) => ({
            name: mode.name,
            label: formatDuration(km / Number(mode.per_day)),
        }));
});

/* ---- pan + pointer routing ---- */
let panDrag = null;
const onDown = (event) => {
    if (measuring.value) {
        const p = toImagePx(event);
        // First click seeds two points so a single drag draws a segment; later clicks add a waypoint.
        // Either way you can drag the point you just placed to fine-tune it before releasing.
        if (route.value.length === 0) {
            route.value = [p, { ...p }];
        } else {
            route.value.push(p);
        }
        dragIndex = route.value.length - 1;
        viewport.value.setPointerCapture(event.pointerId);
        return;
    }
    panDrag = { x: event.clientX, y: event.clientY, tx: view.tx, ty: view.ty };
    viewport.value.setPointerCapture(event.pointerId);
};
const onMove = (event) => {
    if (dragIndex !== null) {
        route.value[dragIndex] = toImagePx(event);
        return;
    }
    if (!panDrag) return;
    view.tx = panDrag.tx + (event.clientX - panDrag.x);
    view.ty = panDrag.ty + (event.clientY - panDrag.y);
};
const onUp = () => {
    dragIndex = null;
    panDrag = null;
};

/* ---- pins ---- */
const openPin = ref(null);
const clickPin = (pin) => {
    if (pin.url) {
        window.location.href = pin.url;
        return;
    }
    openPin.value = openPin.value === pin ? null : pin;
};
// Screen position of the open pin's point, so its popup sits right beside it.
const openPinStyle = computed(() => {
    if (!openPin.value) return {};
    const px = (openPin.value.x / 100) * natW.value;
    const py = (openPin.value.y / 100) * natH.value;
    return {
        left: `${view.tx + px * view.scale}px`,
        top: `${view.ty + py * view.scale}px`,
    };
});
</script>

<template>
    <div
        ref="viewport"
        class="relative h-full w-full overflow-hidden bg-[#0b0d10] touch-none"
        :class="measuring ? 'cursor-crosshair' : 'cursor-grab'"
        @wheel.prevent="onWheel"
        @pointerdown="onDown"
        @pointermove="onMove"
        @pointerup="onUp"
    >
        <div class="absolute left-0 top-0 origin-top-left" :style="transform">
            <img
                :src="map.image_url"
                class="block max-w-none select-none"
                draggable="false"
                alt="Map"
                @load="onImgLoad"
            />

            <!-- Measure route (in image space so it pans/zooms with the map) -->
            <svg
                v-if="route.length"
                class="pointer-events-none absolute left-0 top-0"
                :width="natW"
                :height="natH"
                style="overflow: visible"
            >
                <polyline
                    :points="routePoints"
                    fill="none"
                    stroke="#ef4444"
                    :stroke-width="2 / view.scale"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
                <circle
                    v-for="(p, i) in route"
                    :key="i"
                    :cx="p.px"
                    :cy="p.py"
                    :r="5 / view.scale"
                    fill="#fff"
                    stroke="#ef4444"
                    :stroke-width="2 / view.scale"
                />
            </svg>

            <!-- Pins -->
            <button
                v-for="(p, i) in map.pins"
                :key="i"
                type="button"
                class="absolute"
                :class="[
                    measuring ? 'pointer-events-none' : '',
                    p.style === 'token'
                        ? '-translate-x-1/2 -translate-y-1/2'
                        : '-translate-x-1/2 -translate-y-full',
                ]"
                :style="{ left: p.x + '%', top: p.y + '%' }"
                @pointerdown.stop
                @click.stop="clickPin(p)"
            >
                <span
                    class="flex flex-col items-center"
                    :style="{ transform: `scale(${1 / view.scale})` }"
                >
                    <span
                        v-if="p.label"
                        class="whitespace-nowrap rounded bg-black/70 px-1.5 py-0.5 text-[11px] text-ink"
                        >{{ p.label }}</span
                    >
                    <!-- Token: circular portrait of the linked entry -->
                    <span
                        v-if="p.style === 'token'"
                        class="mt-0.5 flex h-9 w-9 items-center justify-center overflow-hidden rounded-full border-2 border-white bg-black/40 shadow"
                    >
                        <img
                            v-if="p.image_url"
                            :src="p.image_url"
                            alt=""
                            class="h-full w-full object-cover"
                        />
                        <span
                            v-else
                            class="font-display text-[11px] text-ink"
                            >{{
                                (p.label || "?").slice(0, 2).toUpperCase()
                            }}</span
                        >
                    </span>
                    <!-- Marker: a small dot -->
                    <span
                        v-else
                        class="mt-0.5 h-3 w-3 rounded-full border-2 border-white shadow"
                        :class="p.url ? '!bg-teal' : 'bg-amber'"
                    ></span>
                </span>
            </button>
        </div>

        <!-- Route readout: distance, and travel time per mode when the map has a real-world scale -->
        <div
            v-if="distanceLabel"
            class="absolute bottom-3 right-3 z-20 w-48 rounded-lg border border-edge3 bg-surface/95 p-3 shadow-xl"
            @pointerdown.stop
        >
            <div
                class="font-mono text-[9px] uppercase tracking-[0.16em] text-amber"
            >
                Distance
            </div>
            <div class="mt-1 font-display text-xl leading-none text-bright">
                {{ distanceLabel }}
            </div>

            <template v-if="travelTimes.length">
                <div
                    class="mt-3 font-mono text-[9px] uppercase tracking-[0.16em] text-amber"
                >
                    Travel time
                </div>
                <div class="mt-1 font-display text-lg leading-none text-amber">
                    {{ travelTimes[0].label }}
                </div>
                <div
                    class="mt-0.5 font-mono text-[10px] uppercase tracking-wider text-faint"
                >
                    {{ travelTimes[0].name }}
                </div>
                <div class="mt-2 flex flex-col gap-1">
                    <div
                        v-for="mode in travelTimes.slice(1)"
                        :key="mode.name"
                        class="flex items-baseline justify-between gap-2 text-[12px] text-muted"
                    >
                        <span>{{ mode.name }}</span>
                        <b class="font-medium text-ink">{{ mode.label }}</b>
                    </div>
                </div>
            </template>

            <button
                class="mt-3 font-mono text-[10px] uppercase tracking-wider text-faint hover:text-ink"
                @click="clearRoute"
            >
                Clear route
            </button>
        </div>

        <!-- Pin popup (opens beside the marker) -->
        <div
            v-if="openPin"
            class="absolute z-30 w-56 translate-x-3 -translate-y-1/2 rounded-lg border border-edge3 bg-surface p-3 text-sm shadow-xl"
            :style="openPinStyle"
            @pointerdown.stop
        >
            <div class="flex items-start justify-between gap-2">
                <div class="font-display text-bright">
                    {{ openPin.label || "Marker" }}
                </div>
                <button
                    class="text-faint hover:text-ink"
                    @click="openPin = null"
                >
                    ✕
                </button>
            </div>
            <p v-if="openPin.note" class="mt-1 text-muted">
                {{ openPin.note }}
            </p>
        </div>

        <!-- Tools (top-left, beside the sidebar toggle) -->
        <div class="absolute left-14 top-3 z-20 flex gap-1.5" @pointerdown.stop>
            <button
                class="flex h-9 w-9 items-center justify-center rounded-md border shadow"
                :class="
                    measuring
                        ? 'border-red-400 bg-red-500/20 text-red-300'
                        : 'border-edge3 bg-surface text-muted hover:text-ink'
                "
                title="Measure distance"
                @click="toggleMeasure"
            >
                <svg
                    viewBox="0 0 24 24"
                    class="h-4 w-4"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path
                        d="M21.3 8.7 8.7 21.3a1 1 0 0 1-1.4 0l-4.6-4.6a1 1 0 0 1 0-1.4L15.3 2.7a1 1 0 0 1 1.4 0l4.6 4.6a1 1 0 0 1 0 1.4Z"
                    />
                    <path d="m7.5 10.5 2 2M11 7l2 2M14.5 3.5l2 2M4 14l2 2" />
                </svg>
            </button>
            <span
                v-if="measuring"
                class="flex items-center rounded-md border border-edge3 bg-surface/95 px-2 text-[11px] text-muted shadow"
            >
                Click to add points
            </span>
        </div>

        <!-- Zoom (bottom-left) -->
        <div
            class="absolute bottom-3 left-3 z-20 flex overflow-hidden rounded-md border border-edge3 shadow"
            @pointerdown.stop
        >
            <button
                class="flex h-9 w-9 items-center justify-center bg-surface text-lg text-muted hover:text-ink"
                title="Zoom in"
                @click="zoomButton(1.25)"
            >
                +
            </button>
            <button
                class="flex h-9 w-9 items-center justify-center border-l border-edge3 bg-surface text-lg text-muted hover:text-ink"
                title="Zoom out"
                @click="zoomButton(0.8)"
            >
                −
            </button>
            <button
                class="flex h-9 items-center justify-center border-l border-edge3 bg-surface px-3 text-xs text-muted hover:text-ink"
                title="Fit to screen"
                @click="fit"
            >
                Fit
            </button>
        </div>
    </div>
</template>
