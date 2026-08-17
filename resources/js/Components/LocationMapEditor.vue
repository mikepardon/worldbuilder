<script setup>
import UppyUploader from "@/Components/UppyUploader.vue";
import { router, useForm } from "@inertiajs/vue3";
import { computed, reactive, ref, watch } from "vue";

const props = defineProps({
    campaign: Object,
    document: Object,
    map: { type: Object, default: null },
    entries: { type: Array, default: () => [] },
    compendium: { type: Array, default: () => [] },
});

// Compendium monsters a token can portray.
const monsters = computed(() =>
    props.compendium.filter((c) => c.type === "monster"),
);
// A token's target as a combined "doc:ID" / "mon:ID" value, so one picker covers entries and monsters.
const tokenTarget = computed({
    get() {
        const pin = selected.value;
        if (!pin) {
            return "";
        }
        if (pin.compendium_item_id) {
            return `mon:${pin.compendium_item_id}`;
        }
        return pin.document_id ? `doc:${pin.document_id}` : "";
    },
    set(value) {
        const pin = selected.value;
        if (!pin) {
            return;
        }
        if (value?.startsWith("mon:")) {
            pin.compendium_item_id = Number(value.slice(4));
            pin.document_id = null;
        } else if (value?.startsWith("doc:")) {
            pin.document_id = Number(value.slice(4));
            pin.compendium_item_id = null;
        } else {
            pin.document_id = null;
            pin.compendium_item_id = null;
        }
    },
});

/* ---- create the location's map on demand ---- */
const createForm = useForm({ name: "", document_id: null });
const createMap = () => {
    createForm.name = props.document.title || "Map";
    createForm.document_id = props.document.id;
    createForm.post(route("maps.store", props.campaign.id), {
        preserveScroll: true,
        preserveState: true,
    });
};
const deleteMap = () => {
    if (confirm("Delete this map and all its markers?")) {
        router.delete(
            route("maps.destroy", [props.campaign.id, props.map.id]),
            { preserveScroll: true, preserveState: true },
        );
    }
};

/* ---- image upload (Uppy) ---- */
const imageEndpoint = computed(() =>
    props.map ? route("maps.image", [props.campaign.id, props.map.id]) : "",
);
const onImageUploaded = () => router.reload({ preserveScroll: true });
// A stored image whose file is missing (deleted, or a failed upload) still yields a truthy
// image_url, which would otherwise lock the editor into "has image" and hide the uploader.
// Treat a failed <img> load as "no image" so the upload panel returns. Reset whenever the
// underlying image changes so a freshly uploaded, working image isn't kept in the error state.
const imageError = ref(false);
watch(
    () => props.map?.image_url,
    () => {
        imageError.value = false;
    },
);

/* ---- pan & zoom ---- */
const viewport = ref(null);
const view = reactive({ scale: 1, tx: 0, ty: 0 });
const clamp = (v, min, max) => Math.min(max, Math.max(min, v));
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
const addMode = ref(false);
const addTokenMode = ref(false);
// Two-letter initials for a token that has no portrait image.
const pinInitial = (pin) => {
    const name = pin.label || pin.document_title || "?";
    return name.trim().slice(0, 2).toUpperCase();
};
let drag = null;
const onPointerDown = (event) => {
    drag = {
        x: event.clientX,
        y: event.clientY,
        tx: view.tx,
        ty: view.ty,
        moved: false,
    };
    viewport.value.setPointerCapture(event.pointerId);
};
const onPointerMove = (event) => {
    if (!drag) return;
    if (
        Math.abs(event.clientX - drag.x) > 3 ||
        Math.abs(event.clientY - drag.y) > 3
    )
        drag.moved = true;
    view.tx = drag.tx + (event.clientX - drag.x);
    view.ty = drag.ty + (event.clientY - drag.y);
};
const onPointerUp = (event) => {
    const wasClick = drag && !drag.moved;
    drag = null;
    if (wasClick && addMode.value) placePin(event);
};

/* ---- markers ---- */
const selected = ref(null);
const percentFromEvent = (event) => {
    const image = viewport.value.querySelector("img");
    const rect = image.getBoundingClientRect();
    return {
        x: clamp(((event.clientX - rect.left) / rect.width) * 100, 0, 100),
        y: clamp(((event.clientY - rect.top) / rect.height) * 100, 0, 100),
    };
};
const placePin = (event) => {
    const { x, y } = percentFromEvent(event);
    selected.value = {
        id: null,
        x,
        y,
        label: "",
        note: "",
        document_id: null,
        compendium_item_id: null,
        behavior: "info",
        style: addTokenMode.value ? "token" : "marker",
    };
    addMode.value = false;
    addTokenMode.value = false;
};
const editPin = (pin) => {
    selected.value = { ...pin };
};

const pinDrag = ref(null);
const pinX = (pin) => (pinDrag.value?.id === pin.id ? pinDrag.value.x : pin.x);
const pinY = (pin) => (pinDrag.value?.id === pin.id ? pinDrag.value.y : pin.y);
const startPinDrag = (pin, event) => {
    event.currentTarget.setPointerCapture(event.pointerId);
    pinDrag.value = {
        id: pin.id,
        x: pin.x,
        y: pin.y,
        moved: false,
        sx: event.clientX,
        sy: event.clientY,
    };
};
const movePinDrag = (event) => {
    const d = pinDrag.value;
    if (!d) return;
    if (
        !d.moved &&
        Math.abs(event.clientX - d.sx) < 3 &&
        Math.abs(event.clientY - d.sy) < 3
    )
        return;
    d.moved = true;
    const { x, y } = percentFromEvent(event);
    d.x = x;
    d.y = y;
};
const endPinDrag = (pin) => {
    const d = pinDrag.value;
    pinDrag.value = null;
    if (!d) return;
    if (!d.moved) {
        editPin(pin);
        return;
    }
    // Optimistically leave the pin where it was dropped, then persist in the background with a plain
    // XHR — no Inertia visit, so the map doesn't re-render and the marker never snaps back. On failure
    // we reload to resync with the server's stored position.
    pin.x = d.x;
    pin.y = d.y;
    window.axios
        .put(route("map-pins.update", pin.id), { x: d.x, y: d.y })
        .catch(() =>
            router.reload({ preserveScroll: true, preserveState: true }),
        );
};

const pinForm = reactive({ saving: false });
const savePin = () => {
    const pin = selected.value;
    if (!pin) return;
    pinForm.saving = true;
    const payload = {
        x: pin.x,
        y: pin.y,
        label: pin.label || null,
        note: pin.note || null,
        document_id: pin.document_id || null,
        compendium_item_id: pin.compendium_item_id || null,
        behavior: pin.behavior || "info",
        style: pin.style || "marker",
    };
    const done = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            selected.value = null;
        },
        onFinish: () => {
            pinForm.saving = false;
        },
    };
    if (pin.id) router.put(route("map-pins.update", pin.id), payload, done);
    else router.post(route("map-pins.store", props.map.id), payload, done);
};
const deletePin = () => {
    const pin = selected.value;
    if (pin?.id)
        router.delete(route("map-pins.destroy", pin.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                selected.value = null;
            },
        });
    else selected.value = null;
};

const hasImage = computed(() => !!props.map?.image_url && !imageError.value);

/* ---- real-world scale (width + distance unit) ---- */
const scale = reactive({
    real_width: props.map?.real_width ?? "",
    distance_unit: props.map?.distance_unit ?? "miles",
});
const mapPrivate = ref(!!props.map?.is_private);
const setVisible = (visible) => {
    mapPrivate.value = !visible;
    router.put(
        route("maps.update", [props.campaign.id, props.map.id]),
        { is_private: mapPrivate.value },
        { preserveScroll: true, preserveState: true },
    );
};

const savingScale = ref(false);
const saveScale = () => {
    savingScale.value = true;
    router.put(
        route("maps.update", [props.campaign.id, props.map.id]),
        {
            real_width:
                scale.real_width === "" ? null : Number(scale.real_width),
            distance_unit: scale.distance_unit,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                savingScale.value = false;
            },
        },
    );
};
</script>

<template>
    <!-- No map yet -->
    <div
        v-if="!map"
        class="flex h-full flex-col items-center justify-center gap-3 text-faint"
    >
        <p class="text-sm">This location has no map yet.</p>
        <button
            class="btn-primary"
            :disabled="createForm.processing"
            @click="createMap"
        >
            {{ createForm.processing ? "Creating…" : "Create a map" }}
        </button>
    </div>

    <!-- Map editor -->
    <div
        v-else
        class="grid h-full min-h-0 gap-3"
        style="grid-template-columns: minmax(0, 1fr) 300px"
    >
        <div
            ref="viewport"
            class="relative min-w-0 overflow-hidden rounded-lg border border-edge2 bg-[#0b0d10]"
            :class="addMode ? 'cursor-crosshair' : 'cursor-grab'"
            @wheel.prevent="onWheel"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
        >
            <div
                v-if="hasImage"
                class="absolute left-0 top-0 origin-top-left"
                :style="{
                    transform: `translate(${view.tx}px, ${view.ty}px) scale(${view.scale})`,
                }"
            >
                <img
                    :src="map.image_url"
                    :alt="document.title"
                    class="block max-w-none select-none"
                    draggable="false"
                    @load="fitToViewport"
                    @error="imageError = true"
                />
                <button
                    v-for="p in map.pins"
                    :key="p.id"
                    type="button"
                    class="absolute touch-none"
                    :class="
                        p.style === 'token'
                            ? '-translate-x-1/2 -translate-y-1/2'
                            : '-translate-x-1/2 -translate-y-full'
                    "
                    :style="{ left: pinX(p) + '%', top: pinY(p) + '%' }"
                    @pointerdown.stop="startPinDrag(p, $event)"
                    @pointermove.stop="movePinDrag($event)"
                    @pointerup.stop="endPinDrag(p)"
                >
                    <span
                        class="flex flex-col items-center"
                        :style="{ transform: `scale(${1 / view.scale})` }"
                    >
                        <span
                            v-if="p.label || p.document_title"
                            class="whitespace-nowrap rounded bg-black/70 px-1.5 py-0.5 text-[11px] text-ink"
                            >{{ p.label || p.document_title }}</span
                        >
                        <!-- Token: a circular portrait of the linked entry -->
                        <span
                            v-if="p.style === 'token'"
                            class="mt-0.5 flex h-9 w-9 items-center justify-center overflow-hidden rounded-full border-2 border-white bg-raised shadow"
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
                                >{{ pinInitial(p) }}</span
                            >
                        </span>
                        <!-- Marker: a small dot -->
                        <span
                            v-else
                            class="mt-0.5 h-3 w-3 rounded-full border-2 border-white shadow"
                            :class="p.document_id ? '!bg-teal' : 'bg-amber'"
                        ></span>
                    </span>
                </button>
            </div>

            <div
                v-if="!hasImage"
                class="absolute inset-0 flex items-center justify-center p-6"
                @pointerdown.stop
                @wheel.stop
            >
                <div class="w-full max-w-md">
                    <UppyUploader
                        :endpoint="imageEndpoint"
                        :allowed-file-types="['image/*']"
                        :max-file-size-mb="50"
                        :max-number-of-files="1"
                        :height="260"
                        note="Upload a map image — PNG/JPG/WebP, up to 50 MB"
                        @complete="onImageUploaded"
                    />
                </div>
            </div>

            <div
                v-if="hasImage"
                class="absolute left-3 top-3 flex items-center gap-1.5"
                @pointerdown.stop
            >
                <template v-if="!addMode">
                    <button
                        class="rounded-md border border-edge3 bg-surface px-3 py-1.5 text-sm text-muted shadow hover:text-ink"
                        @click="
                            addMode = true;
                            addTokenMode = false;
                        "
                    >
                        + Add marker
                    </button>
                    <button
                        class="rounded-md border border-edge3 bg-surface px-3 py-1.5 text-sm text-muted shadow hover:text-ink"
                        @click="
                            addMode = true;
                            addTokenMode = true;
                        "
                    >
                        + Add token
                    </button>
                </template>
                <button
                    v-else
                    class="rounded-md border border-amber bg-amber px-3 py-1.5 text-sm text-night shadow"
                    @click="
                        addMode = false;
                        addTokenMode = false;
                    "
                >
                    Click the map to place
                    {{ addTokenMode ? "a token" : "a marker" }}… (cancel)
                </button>
            </div>
        </div>

        <!-- Side panel -->
        <div class="flex min-h-0 flex-col gap-3 overflow-y-auto">
            <div v-if="selected" class="panel flex flex-col gap-3 p-4">
                <div class="font-display text-lg text-bright">
                    {{
                        (selected.id ? "Edit " : "New ") +
                        (selected.style === "token" ? "token" : "marker")
                    }}
                </div>

                <div
                    class="flex items-center gap-1 rounded-md border border-edge3 p-0.5"
                >
                    <button
                        v-for="s in ['marker', 'token']"
                        :key="s"
                        class="flex-1 rounded px-3 py-1 text-xs capitalize"
                        :class="
                            (selected.style || 'marker') === s
                                ? 'bg-raised text-bright'
                                : 'text-muted hover:text-ink'
                        "
                        @click="selected.style = s"
                    >
                        {{ s }}
                    </button>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-medium text-faint">Label</label>
                    <input
                        v-model="selected.label"
                        class="field !py-2 text-sm"
                        placeholder="Saltmere Docks"
                    />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-medium text-faint">{{
                        selected.style === "token"
                            ? "Who / what (portrait comes from its card image)"
                            : "Target location"
                    }}</label>
                    <select
                        v-if="selected.style === 'token'"
                        v-model="tokenTarget"
                        class="field !py-2 text-sm"
                    >
                        <option value="">— None —</option>
                        <optgroup label="Entries">
                            <option
                                v-for="e in entries"
                                :key="'doc' + e.id"
                                :value="'doc:' + e.id"
                            >
                                {{ e.title }} · {{ e.kindLabel }}
                            </option>
                        </optgroup>
                        <optgroup v-if="monsters.length" label="Monsters">
                            <option
                                v-for="m in monsters"
                                :key="'mon' + m.id"
                                :value="'mon:' + m.id"
                            >
                                {{ m.name }}
                            </option>
                        </optgroup>
                    </select>
                    <select
                        v-else
                        v-model="selected.document_id"
                        class="field !py-2 text-sm"
                    >
                        <option :value="null">— None —</option>
                        <option v-for="e in entries" :key="e.id" :value="e.id">
                            {{ e.title }} · {{ e.kindLabel }}
                        </option>
                    </select>
                </div>
                <div
                    v-if="selected.style !== 'token'"
                    class="flex flex-col gap-1.5"
                >
                    <label class="text-xs font-medium text-faint"
                        >On click</label
                    >
                    <select
                        v-model="selected.behavior"
                        class="field !py-2 text-sm"
                    >
                        <option value="info">Show an info popup</option>
                        <option value="travel">Travel to its map</option>
                        <option value="article">Open the article</option>
                    </select>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-medium text-faint"
                        >Note
                        {{
                            selected.behavior === "info"
                                ? "(shown in the popup)"
                                : ""
                        }}</label
                    >
                    <textarea
                        v-model="selected.note"
                        rows="3"
                        class="field !py-2 text-sm"
                        placeholder="Notes for this marker…"
                    />
                </div>
                <div
                    class="flex items-center justify-between gap-2 border-t border-edge2 pt-3"
                >
                    <button
                        class="text-sm text-faint hover:text-red-400"
                        @click="deletePin"
                    >
                        {{ selected.id ? "Delete" : "Cancel" }}
                    </button>
                    <button
                        class="btn-primary"
                        :disabled="pinForm.saving"
                        @click="savePin"
                    >
                        Save marker
                    </button>
                </div>
            </div>

            <div
                v-else-if="hasImage"
                class="panel flex flex-col gap-2 p-4 text-sm"
            >
                <div class="font-display text-lg text-bright">Map settings</div>
                <label class="flex items-center gap-2 text-sm text-muted">
                    <input
                        type="checkbox"
                        :checked="!mapPrivate"
                        @change="setVisible($event.target.checked)"
                    />
                    Visible to players
                </label>
                <p class="text-[12px] text-faint">
                    Map width sets the real distances shown when measuring.
                </p>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-faint"
                        >Map width</span
                    >
                    <input
                        v-model="scale.real_width"
                        type="number"
                        min="0"
                        class="field !py-2 text-sm"
                        placeholder="e.g. 1300"
                    />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-faint"
                        >Distance unit</span
                    >
                    <select
                        v-model="scale.distance_unit"
                        class="field !py-2 text-sm"
                    >
                        <option value="feet">Feet</option>
                        <option value="yards">Yards</option>
                        <option value="miles">Miles</option>
                        <option value="km">Km</option>
                    </select>
                </label>
                <button
                    class="btn-primary self-start"
                    :disabled="savingScale"
                    @click="saveScale"
                >
                    {{ savingScale ? "Saving…" : "Save scale" }}
                </button>

                <div class="mt-1 border-t border-edge2 pt-3">
                    <div class="mb-2 text-xs font-medium text-faint">
                        Replace map image
                    </div>
                    <UppyUploader
                        :endpoint="imageEndpoint"
                        :allowed-file-types="['image/*']"
                        :max-file-size-mb="50"
                        :max-number-of-files="1"
                        :height="150"
                        @complete="onImageUploaded"
                    />
                </div>
            </div>

            <div
                v-if="!selected"
                class="panel flex flex-col gap-2 p-4 text-sm text-muted"
            >
                <div class="font-display text-lg text-bright">
                    Markers ({{ map.pins.length }})
                </div>
                <p class="text-[13px] text-faint">
                    Use <b class="text-ink">+ Add marker</b>, then click the
                    map. Scroll to zoom, drag to pan. Click a marker to edit it,
                    or drag to move it.
                </p>
                <button
                    v-for="p in map.pins"
                    :key="p.id"
                    class="flex items-center gap-2 rounded-md border border-edge2 bg-[#1a1d24] px-2.5 py-1.5 text-left hover:border-teal/40"
                    @click="editPin(p)"
                >
                    <span
                        class="h-2 w-2 shrink-0 rounded-full"
                        :class="p.document_id ? 'bg-teal' : 'bg-amber'"
                    ></span>
                    <span
                        class="min-w-0 flex-1 truncate text-[13.5px] text-ink"
                        >{{
                            p.label || p.document_title || "Untitled marker"
                        }}</span
                    >
                </button>
            </div>

            <button
                class="mt-auto text-left text-xs text-faint hover:text-red-400"
                @click="deleteMap"
            >
                Delete this map
            </button>
        </div>
    </div>
</template>
