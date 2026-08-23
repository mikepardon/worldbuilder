<script setup>
import CharacterSheet from "@/Components/CharacterSheet.vue";
import StatblockCard from "@/Components/StatblockCard.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import {
    computed,
    markRaw,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    shallowRef,
    watch,
} from "vue";

const props = defineProps({
    campaign: Object,
    isGm: Boolean,
    me: Object,
    room: Object,
    // Split out of `room` so each can be reloaded independently on a scoped realtime update.
    tokens: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    drawings: { type: Array, default: () => [] },
    messages: { type: Array, default: () => [] },
    monsters: { type: Array, default: () => [] },
    playerMonsters: { type: Array, default: () => [] },
    characters: { type: Array, default: () => [] },
    journal: { type: Array, default: () => [] },
    handouts: { type: Array, default: () => [] },
    iceServers: {
        type: Array,
        default: () => [{ urls: "stun:stun.l.google.com:19302" }],
    },
});

/* ---- fullscreen layout: collapsible side panel (persisted) ---- */
const panelCollapsed = ref(
    typeof localStorage !== "undefined" &&
        localStorage.getItem("room:panelCollapsed") === "1",
);
watch(panelCollapsed, (collapsed) => {
    if (typeof localStorage !== "undefined") {
        localStorage.setItem("room:panelCollapsed", collapsed ? "1" : "0");
    }
    // The board width changed — re-fit the map to fill it (after the DOM reflows).
    nextTick(refit);
});
const boardGridStyle = computed(() => ({
    gridTemplateColumns: panelCollapsed.value
        ? "minmax(0, 1fr)"
        : "minmax(0, 1fr) 320px",
    height: "100vh",
}));

/* ---- side-panel tabs ---- */
const panelTab = ref("chat");
const panelTabs = computed(() => {
    const canSeeTracker = props.isGm || props.room.players_see_tracker;
    return [
        { key: "chat", label: "Chat" },
        ...(canSeeTracker ? [{ key: "tracker", label: "Tracker" }] : []),
        ...(props.isGm ? [{ key: "gm", label: "GM" }] : []),
        { key: "handouts", label: "Handouts" },
        { key: "settings", label: "Settings" },
        { key: "journal", label: "Journal" },
    ];
});
watch(panelTabs, (tabs) => {
    if (!tabs.some((t) => t.key === panelTab.value)) panelTab.value = "chat";
});

// A stable colour per person for their chat nameplate (fallback when the server sends none).
const userColor = (key) => {
    const s = String(key ?? "");
    let hash = 0;
    for (let i = 0; i < s.length; i++)
        hash = (hash * 31 + s.charCodeAt(i)) % 360;
    return `hsl(${hash} 68% 68%)`;
};
// A roll can be attributed to a creature (a monster's name/portrait) rather than the roller.
const msgName = (m) => m.roll?.as?.name || m.user;
const msgImage = (m) =>
    m.roll?.as && "image" in m.roll.as ? m.roll.as.image : m.user_image;

/* ---- user settings (local prefs) ---- */
const CAM_SIZES = {
    small: "h-20 w-28",
    medium: "h-28 w-40",
    large: "h-36 w-52",
};
const camSize = ref(localStorage.getItem("wb.camSize") || "medium");
watch(camSize, (v) => localStorage.setItem("wb.camSize", v));
const camTileClass = computed(
    () => CAM_SIZES[camSize.value] || CAM_SIZES.medium,
);

/* ---- journal (private session notes) ---- */
const journalForm = useForm({ body: "" });
const addJournal = () => {
    if (!journalForm.body.trim()) return;
    journalForm.post(route("rooms.journal.store", props.room.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => journalForm.reset(),
    });
};
const removeJournal = (id) =>
    router.delete(route("rooms.journal.destroy", id), {
        preserveScroll: true,
        preserveState: true,
    });

/* ---- handouts (GM shares an image / note with the table) ---- */
const handoutForm = useForm({ title: "", body: "", image_media_id: null });
const handoutImageUrl = ref(""); // preview of the chosen image in the compose row
const showHandoutPicker = ref(false);
const handoutImage = ref(null); // lightbox: the URL of a handout image being viewed large
const openHandoutPicker = () => {
    showHandoutPicker.value = true;
    mapPicker.media = [];
    mapPicker.locations = [];
    loadMapPage(1);
};
const chooseHandoutImage = (media) => {
    handoutForm.image_media_id = media.id;
    handoutImageUrl.value = media.url;
    showHandoutPicker.value = false;
};
const clearHandoutImage = () => {
    handoutForm.image_media_id = null;
    handoutImageUrl.value = "";
};
const addHandout = () => {
    if (!handoutForm.body.trim() && !handoutForm.image_media_id) return;
    handoutForm.post(route("rooms.handouts.store", props.room.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            handoutForm.reset();
            handoutImageUrl.value = "";
        },
    });
};
const removeHandout = (id) =>
    router.delete(route("rooms.handouts.destroy", id), {
        preserveScroll: true,
        preserveState: true,
    });

// The conditions, toggled in the character editor and badged on tokens. Ten have icons in
// /public/conditions; the rest fall back to a text chip until their art is added.
const CONDITIONS = [
    "Unconscious",
    "Stunned",
    "Restrained",
    "Prone",
    "Poisoned",
    "Petrified",
    "Paralyzed",
    "Invisible",
    "Incapacitated",
    "Grappled",
    "Blinded",
    "Charmed",
    "Deafened",
    "Frightened",
];
const ICON_CONDITIONS = new Set([
    "unconscious",
    "stunned",
    "restrained",
    "prone",
    "poisoned",
    "petrified",
    "paralyzed",
    "invisible",
    "incapacitated",
    "grappled",
]);
const condKey = (name) => name.toLowerCase();
const condLabel = (key) => key.charAt(0).toUpperCase() + key.slice(1);
const condIcon = (key) => `/conditions/${key}.png`;
const condHasIcon = (key) => ICON_CONDITIONS.has(key);
const condAbbr = (key) => key.slice(0, 2).toUpperCase();

/* ---- image upload (GM) ---- */
const imageForm = useForm({ file: null });
const onFile = (event) => {
    const input = event.target;
    imageForm.file = input.files[0];
    if (!imageForm.file) return;
    imageForm
        .transform((data) => ({ ...data, scene: props.room.viewing_scene_id }))
        .post(route("rooms.image", props.room.id), {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
            onSuccess: () => {
                showMapPicker.value = false;
            },
            onFinish: () => {
                input.value = "";
            },
        });
};

/* ---- replace map: upload, the world's media, or a location's map ---- */
const showMapPicker = ref(false);
const mapSearch = ref("");
const mapPicker = reactive({
    media: [],
    locations: [],
    loading: false,
    page: 1,
    hasMore: false,
});
const loadMapPage = async (page) => {
    mapPicker.loading = true;
    try {
        const { data } = await window.axios.get(
            route("rooms.map-options", props.room.id),
            { params: { page, search: mapSearch.value } },
        );
        mapPicker.media =
            page === 1 ? data.media : [...mapPicker.media, ...data.media];
        if (page === 1) mapPicker.locations = data.locations;
        mapPicker.page = data.page;
        mapPicker.hasMore = data.has_more;
    } finally {
        mapPicker.loading = false;
    }
};
const openMapPicker = () => {
    showMapPicker.value = true;
    mapSearch.value = "";
    mapPicker.media = [];
    mapPicker.locations = [];
    loadMapPage(1);
};
// Debounced search of the media library + location maps by name.
let mapSearchTimer;
watch(mapSearch, () => {
    clearTimeout(mapSearchTimer);
    mapSearchTimer = setTimeout(() => loadMapPage(1), 250);
});
const chooseRoomImage = (mediaId) => {
    saveRoom({ image_media_id: mediaId });
    showMapPicker.value = false;
};

/* ---- pan & zoom ---- */
const viewport = ref(null);
const view = reactive({ scale: 1, tx: 0, ty: 0 });
const imgSize = reactive({ w: 0, h: 0 });
const clamp = (v, min, max) => Math.min(max, Math.max(min, v));

// Scale + centre the map to fill the current board width, resetting pan.
const refit = () => {
    const el = viewport.value;
    if (!el || !imgSize.w) return;
    view.scale = el.clientWidth / imgSize.w || 1;
    view.tx = 0;
    view.ty = 0;
};
const fitToViewport = (event) => {
    imgSize.w = event.target.naturalWidth;
    imgSize.h = event.target.naturalHeight;
    refit();
};
let resizeTimer;
const onResize = () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(refit, 150);
};
onMounted(() => window.addEventListener("resize", onResize));
onBeforeUnmount(() => {
    clearTimeout(resizeTimer);
    window.removeEventListener("resize", onResize);
});
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
    if (props.isGm && drawTool.value) {
        const point = percentFromEvent(event);
        drawDraft.active = true;
        drawDraft.kind = drawTool.value;
        drawDraft.points =
            drawTool.value === "freehand" ? [point] : [point, point];
        viewport.value.setPointerCapture(event.pointerId);
        return;
    }
    if (aoeTool.active) {
        const point = percentFromEvent(event);
        aoeDraft.active = true;
        aoeDraft.ox = aoeDraft.px = point.x;
        aoeDraft.oy = aoeDraft.py = point.y;
        viewport.value.setPointerCapture(event.pointerId);
        return;
    }
    if (measureTool.value) {
        measure.mode = measureTool.value;
        measure.a = measure.b = percentFromEvent(event);
        measure.active = true;
        viewport.value.setPointerCapture(event.pointerId);
        return;
    }
    if (props.isGm && fogTool.value) {
        fogPainting = true;
        paintFog(event);
        viewport.value.setPointerCapture(event.pointerId);
        return;
    }
    drag = { x: event.clientX, y: event.clientY, tx: view.tx, ty: view.ty };
    viewport.value.setPointerCapture(event.pointerId);
};
const onPointerMove = (event) => {
    sendCursor(event); // share my pointer with the table (throttled)
    if (drawDraft.active) {
        const point = percentFromEvent(event);
        if (drawDraft.kind === "freehand") {
            const last = drawDraft.points[drawDraft.points.length - 1];
            // Sample by distance so a stroke isn't thousands of points.
            if (!last || Math.hypot(point.x - last.x, point.y - last.y) > 0.3) {
                drawDraft.points.push(point);
            }
        } else {
            drawDraft.points = [drawDraft.points[0], point];
        }
        broadcastDraw(); // let the table watch the stroke form live
        return;
    }
    if (aoeDraft.active) {
        const point = percentFromEvent(event);
        aoeDraft.px = point.x;
        aoeDraft.py = point.y;
        broadcastAoe(); // let the table watch the template take shape live
        return;
    }
    if (measure.active) {
        measure.b = percentFromEvent(event);
        broadcastMeasure(); // the table watches the measurement live
        return;
    }
    if (fogPainting) {
        paintFog(event);
        return;
    }
    if (!drag) return;
    view.tx = drag.tx + (event.clientX - drag.x);
    view.ty = drag.ty + (event.clientY - drag.y);
};
const onPointerUp = () => {
    if (drawDraft.active) {
        commitDraw();
        return;
    }
    if (aoeDraft.active) {
        aoeDraft.active = false;
        if (presenceChannel) presenceChannel.whisper("aoe-end", {}); // clear the live preview elsewhere
        commitAoe();
        return;
    }
    if (measure.active) {
        measure.active = false;
        broadcastMeasure(true); // keep the finished measurement on everyone's board
        return;
    }
    if (fogPainting) {
        fogPainting = false;
        saveFog();
        return;
    }
    drag = null;
};

const cellPx = computed(() =>
    imgSize.w ? imgSize.w / (props.room.grid_size || 20) : 0,
);
const gridStyle = computed(() => {
    if (!props.room.grid_visible || !imgSize.w) return {};
    const line = Math.max(1, 1 / view.scale);
    return {
        backgroundImage: `linear-gradient(to right, rgba(255,255,255,.3) ${line}px, transparent ${line}px), linear-gradient(to bottom, rgba(255,255,255,.3) ${line}px, transparent ${line}px)`,
        backgroundSize: `${cellPx.value}px ${cellPx.value}px`,
    };
});

const percentFromEvent = (event) => {
    const image = viewport.value.querySelector("img");
    const rect = image.getBoundingClientRect();
    return {
        x: clamp(((event.clientX - rect.left) / rect.width) * 100, 0, 100),
        y: clamp(((event.clientY - rect.top) / rect.height) * 100, 0, 100),
    };
};

/* ---- fog of war (GM paints; players see it opaque) ---- */
const fogSet = ref(new Set(props.room.fog ?? []));
const fogTool = ref(null); // null | reveal | cover
let fogPainting = false;
let fogTimer;
const cols = computed(() => props.room.grid_size || 20);
const rows = computed(() =>
    cellPx.value ? Math.max(1, Math.round(imgSize.h / cellPx.value)) : 0,
);
const revealed = computed(() =>
    props.isGm ? fogSet.value : new Set(props.room.fog ?? []),
);
const coveredCells = computed(() => {
    if (
        !props.room.fog_enabled ||
        !cellPx.value ||
        cols.value * rows.value > 6000
    )
        return [];
    const rev = revealed.value;
    const out = [];
    for (let c = 0; c < cols.value; c++) {
        for (let r = 0; r < rows.value; r++) {
            if (!rev.has(`${c},${r}`))
                out.push({
                    key: `${c},${r}`,
                    left: c * cellPx.value,
                    top: r * cellPx.value,
                });
        }
    }
    return out;
});
const paintFog = (event) => {
    const { x, y } = percentFromEvent(event);
    const c = Math.min(
        cols.value - 1,
        Math.max(0, Math.floor((x / 100) * cols.value)),
    );
    const r = Math.min(
        rows.value - 1,
        Math.max(0, Math.floor(((y / 100) * imgSize.h) / cellPx.value)),
    );
    const next = new Set(fogSet.value);
    if (fogTool.value === "reveal") next.add(`${c},${r}`);
    else next.delete(`${c},${r}`);
    fogSet.value = next;
};
const saveFog = () => {
    clearTimeout(fogTimer);
    fogTimer = setTimeout(() => saveRoom({ fog: [...fogSet.value] }), 400);
};
const revealAll = () => {
    const next = new Set();
    for (let c = 0; c < cols.value; c++)
        for (let r = 0; r < rows.value; r++) next.add(`${c},${r}`);
    fogSet.value = next;
    saveFog();
};
const coverAll = () => {
    fogSet.value = new Set();
    saveFog();
};

/* ---- measurement: ruler + radius (each grid cell = room.unit_size ft) ---- */
const measureTool = ref(null); // 'ruler' | 'radius' | null
const measure = reactive({ active: false, mode: null, a: null, b: null });
const toggleMeasure = (tool) => {
    if (measureTool.value === tool) {
        measureTool.value = null;
        measure.a = measure.b = null;
        clearMeasureBroadcast(); // remove my measurement from everyone's board
    } else {
        measureTool.value = tool;
    }
};
const unitSize = computed(() => props.room.unit_size || 5);
const unitLabel = computed(() => props.room.unit || "ft");
const elevLabel = (n) =>
    `${n > 0 ? "↑" : "↓"} ${Math.abs(n)} ${unitLabel.value}`;
const toPx = (p) => ({
    x: (p.x / 100) * imgSize.w,
    y: (p.y / 100) * imgSize.h,
});
// Distance between the two measured points, in grid cells (per axis).
const measureCells = computed(() => {
    if (!measure.a || !measure.b) return null;
    const cwPct = 100 / (props.room.grid_size || 20);
    const chPct = imgSize.h ? (cellPx.value / imgSize.h) * 100 : cwPct;
    return {
        dx: Math.abs(measure.b.x - measure.a.x) / cwPct,
        dy: Math.abs(measure.b.y - measure.a.y) / chPct,
    };
});
// 5e "every square is 5 ft" — the number of squares is the larger axis (Chebyshev).
const rulerFeet = computed(() =>
    measureCells.value
        ? Math.round(Math.max(measureCells.value.dx, measureCells.value.dy)) *
          unitSize.value
        : 0,
);
const radiusFeet = computed(() =>
    measureCells.value
        ? Math.round(Math.hypot(measureCells.value.dx, measureCells.value.dy)) *
          unitSize.value
        : 0,
);
const radiusPx = computed(() => {
    if (!measure.a || !measure.b) return 0;
    const a = toPx(measure.a);
    const b = toPx(measure.b);
    return Math.hypot(b.x - a.x, b.y - a.y);
});
// Radius (px) of another user's measurement, from its percentage points.
const measureRadiusPx = (m) => {
    const a = toPx(m.a);
    const b = toPx(m.b);
    return Math.hypot(b.x - a.x, b.y - a.y);
};

/* ---- AoE templates: place persistent spell shapes (circle/cone/line/square) ---- */
const AOE_KINDS = ["circle", "cone", "line", "square"];
const AOE_GLYPH = { circle: "◯", cone: "△", line: "▬", square: "▢" };
const showAoe = ref(false);
const aoeTool = reactive({ active: false, kind: "circle", color: "#d8a94a" });
const aoeDraft = reactive({ active: false, ox: 0, oy: 0, px: 0, py: 0 });
const toggleAoe = (kind) => {
    if (aoeTool.active && aoeTool.kind === kind) {
        aoeTool.active = false;
        return;
    }
    aoeTool.active = true;
    aoeTool.kind = kind;
    // Placing a template, measuring and painting fog are mutually exclusive board modes.
    measureTool.value = null;
    measure.a = measure.b = null;
    fogTool.value = null;
};
// Closing the templates panel also stops placing, so the board leaves template mode.
const toggleAoePanel = () => {
    showAoe.value = !showAoe.value;
    if (!showAoe.value) aoeTool.active = false;
};
const pxPerFoot = computed(() =>
    cellPx.value && unitSize.value ? cellPx.value / unitSize.value : 0,
);
const feetToPx = (feet) => feet * pxPerFoot.value;
// Snap a raw feet distance to the grid's unit increments, with a one-cell floor.
const snapFeet = (feet) =>
    Math.max(
        unitSize.value,
        Math.round(feet / unitSize.value) * unitSize.value,
    );
// Derive a template { kind, x, y, length, angle, color } from the current drag (origin → pointer).
const draftFromDrag = () => {
    const o = toPx({ x: aoeDraft.ox, y: aoeDraft.oy });
    const p = toPx({ x: aoeDraft.px, y: aoeDraft.py });
    const distFeet = pxPerFoot.value
        ? Math.hypot(p.x - o.x, p.y - o.y) / pxPerFoot.value
        : 0;
    const angle = (Math.atan2(p.y - o.y, p.x - o.x) * 180) / Math.PI;
    // Circle drags to its radius; a square drags centre-to-edge, so its side is twice the distance.
    const length = snapFeet(
        aoeTool.kind === "square" ? distFeet * 2 : distFeet,
    );
    return {
        kind: aoeTool.kind,
        x: aoeDraft.ox,
        y: aoeDraft.oy,
        length,
        angle,
        color: aoeTool.color,
    };
};
const aoeDraftTemplate = computed(() =>
    aoeDraft.active ? draftFromDrag() : null,
);
const commitAoe = () =>
    router.post(
        route("rooms.templates.store", props.room.id),
        {
            ...draftFromDrag(),
            layer: activeLayer.value,
            scene: props.room.viewing_scene_id,
        },
        {
            preserveScroll: true,
            preserveState: true,
        },
    );
const removeTemplate = (id) =>
    router.delete(route("rooms.templates.destroy", id), {
        preserveScroll: true,
        preserveState: true,
    });
const revealTemplate = (id) =>
    router.put(
        route("rooms.templates.update", id),
        { layer: "token" },
        { preserveScroll: true, preserveState: true },
    );
const clearTemplates = () =>
    router.delete(route("rooms.templates.clear", props.room.id), {
        preserveScroll: true,
        preserveState: true,
    });

// SVG geometry for a template (committed or draft), in map-image pixel space.
const tplCircle = (t) => {
    const o = toPx({ x: t.x, y: t.y });
    return { cx: o.x, cy: o.y, r: feetToPx(t.length) };
};
const tplSquare = (t) => {
    const o = toPx({ x: t.x, y: t.y });
    const side = feetToPx(t.length);
    return { x: o.x - side / 2, y: o.y - side / 2, side };
};
const tplPolygon = (t) => {
    const o = toPx({ x: t.x, y: t.y });
    const th = ((t.angle || 0) * Math.PI) / 180;
    const dir = { x: Math.cos(th), y: Math.sin(th) };
    const perp = { x: -Math.sin(th), y: Math.cos(th) };
    const len = feetToPx(t.length);
    if (t.kind === "cone") {
        // 5e cone: as wide at the far end as it is long.
        const far = { x: o.x + dir.x * len, y: o.y + dir.y * len };
        const half = len / 2;
        return [
            [o.x, o.y],
            [far.x + perp.x * half, far.y + perp.y * half],
            [far.x - perp.x * half, far.y - perp.y * half],
        ]
            .map((point) => point.join(","))
            .join(" ");
    }
    // Line: a fixed 5 ft wide rectangle along the aim.
    const halfWidth = feetToPx(5) / 2;
    const end = { x: o.x + dir.x * len, y: o.y + dir.y * len };
    return [
        [o.x + perp.x * halfWidth, o.y + perp.y * halfWidth],
        [o.x - perp.x * halfWidth, o.y - perp.y * halfWidth],
        [end.x - perp.x * halfWidth, end.y - perp.y * halfWidth],
        [end.x + perp.x * halfWidth, end.y + perp.y * halfWidth],
    ]
        .map((point) => point.join(","))
        .join(" ");
};

/* ---- drawing / annotation layer (GM): freehand, line, rect, ellipse ---- */
const DRAW_KINDS = ["freehand", "line", "rect", "ellipse"];
const DRAW_GLYPH = { freehand: "✎", line: "╱", rect: "▭", ellipse: "◯" };
const showDraw = ref(false);
const drawTool = ref(null); // 'freehand' | 'line' | 'rect' | 'ellipse' | null
const drawColor = ref("#e0743c");
// Active board layer for new GM objects: 'token' (everyone) or 'gm' (staged, hidden from players).
const activeLayer = ref("token");
// GM editing aid: objects not on the active layer fade back, and the map dims while on the GM layer.
const layerFaded = (layer) =>
    props.isGm && (layer || "token") !== activeLayer.value;
const mapDimmed = computed(() => props.isGm && activeLayer.value === "gm");
const drawDraft = reactive({ active: false, kind: null, points: [] });
const toggleDraw = (kind) => {
    if (drawTool.value === kind) {
        drawTool.value = null;
        return;
    }
    drawTool.value = kind;
    // Drawing is exclusive with the other board modes.
    measureTool.value = null;
    measure.a = measure.b = null;
    aoeTool.active = false;
    fogTool.value = null;
};
// Closing the Draw panel also deactivates the pen, so the board leaves draw mode.
const toggleDrawPanel = () => {
    showDraw.value = !showDraw.value;
    if (!showDraw.value) drawTool.value = null;
};
const commitDraw = () => {
    const points = drawDraft.points;
    drawDraft.active = false;
    if (presenceChannel) presenceChannel.whisper("draw-end", {}); // clear the live preview elsewhere
    if (points.length < 2) {
        drawDraft.points = [];
        return;
    }
    router.post(
        route("rooms.drawings.store", props.room.id),
        {
            kind: drawDraft.kind,
            points,
            color: drawColor.value,
            layer: activeLayer.value,
            scene: props.room.viewing_scene_id,
        },
        { preserveScroll: true, preserveState: true },
    );
    drawDraft.points = [];
};
const clearDrawings = () =>
    router.delete(route("rooms.drawings.clear", props.room.id), {
        preserveScroll: true,
        preserveState: true,
    });

/* ---- scenes (GM): preview/go-live/add/rename/delete maps ---- */
// Preview a scene: the GM's board switches to it (?scene=), players are unaffected until it goes live.
const previewScene = (id) =>
    router.get(
        route("rooms.show", [props.campaign.id, props.room.id]),
        { scene: id },
        { only: ["room"], preserveState: true, preserveScroll: true },
    );
// Go live: make the viewed scene the active one, so players switch to it.
const goLive = (id) =>
    router.post(
        route("rooms.scenes.activate", id),
        {},
        { preserveScroll: true, preserveState: true },
    );
const addScene = () =>
    router.post(
        route("rooms.scenes.store", props.room.id),
        {},
        { preserveScroll: true, preserveState: true },
    );
const renameScene = (scene) => {
    const name = window.prompt("Scene name", scene.name);
    if (name && name.trim()) {
        router.put(
            route("rooms.scenes.update", scene.id),
            { name: name.trim() },
            { preserveScroll: true },
        );
    }
};
const deleteScene = (id) =>
    router.delete(route("rooms.scenes.destroy", id), { preserveScroll: true });
// Copy all tokens (with their stats) from another scene into the one the GM is viewing.
const importFromScene = ref(null);
const importSceneTokens = () => {
    if (!importFromScene.value) return;
    router.post(
        route("rooms.scenes.import-tokens", props.room.viewing_scene_id),
        { from: importFromScene.value },
        {
            preserveScroll: true,
            onSuccess: () => (importFromScene.value = null),
        },
    );
};

// SVG geometry for a drawing (committed or draft), in map-image pixel space.
const drawPolyline = (d) =>
    d.points.map((p) => Object.values(toPx(p)).join(",")).join(" ");
const drawRect = (d) => {
    const a = toPx(d.points[0]);
    const b = toPx(d.points[d.points.length - 1]);
    return {
        x: Math.min(a.x, b.x),
        y: Math.min(a.y, b.y),
        w: Math.abs(b.x - a.x),
        h: Math.abs(b.y - a.y),
    };
};
const drawEllipse = (d) => {
    const a = toPx(d.points[0]);
    const b = toPx(d.points[d.points.length - 1]);
    return {
        cx: (a.x + b.x) / 2,
        cy: (a.y + b.y) / 2,
        rx: Math.abs(b.x - a.x) / 2,
        ry: Math.abs(b.y - a.y) / 2,
    };
};

/* ---- token context menu (GM): delete + quick conditions ---- */
const contextMenu = reactive({ open: false, x: 0, y: 0, token: null });
const openContext = (t, event) => {
    if (!props.isGm) return;
    event.preventDefault();
    contextMenu.token = t;
    contextMenu.x = event.clientX;
    contextMenu.y = event.clientY;
    contextMenu.open = true;
};
const closeContext = () => {
    contextMenu.open = false;
};
const contextToggleCondition = (key) => {
    const t = contextMenu.token;
    if (!t) return;
    const list = [...(t.conditions || [])];
    const i = list.indexOf(key);
    if (i === -1) list.push(key);
    else list.splice(i, 1);
    t.conditions = list; // instant feedback; the realtime reload confirms it
    patchToken(t.id, { conditions: list });
};
const contextAdjustElevation = (delta) => {
    const t = contextMenu.token;
    if (!t) return;
    const next = (Number(t.elevation) || 0) + delta;
    t.elevation = next; // instant feedback; the realtime reload confirms it
    patchToken(t.id, { elevation: next });
};
const contextToggleLayer = () => {
    const t = contextMenu.token;
    if (!t) return;
    const layer = t.layer === "gm" ? "token" : "gm";
    t.layer = layer; // instant feedback; the realtime reload confirms it
    patchToken(t.id, { layer });
};
const contextEdit = () => {
    const t = contextMenu.token;
    closeContext();
    if (t) editToken(t);
};
// Manual concentration flag from the right-click menu (no spell needed).
const contextToggleConcentration = () => {
    const t = contextMenu.token;
    if (!t) return;
    const value = t.concentrating_on ? "" : "Concentrating";
    t.concentrating_on = value || null; // instant feedback
    patchToken(t.id, { concentrating_on: value });
};
const contextDelete = () => {
    const t = contextMenu.token;
    closeContext();
    if (t)
        router.delete(route("room-tokens.destroy", t.id), {
            preserveScroll: true,
            preserveState: true,
        });
};

/* ---- tokens: rendering + seamless drag ---- */
// Optimistic positions keep a just-moved token exactly where it was dropped until the server round-trip
// (and the realtime reload) catches up, so releasing a token never snaps it back.
const localPos = reactive({});
const tokenX = (t) => localPos[t.id]?.x ?? t.x;
const tokenY = (t) => localPos[t.id]?.y ?? t.y;
const initial = (t) => (t.label || "?").trim().charAt(0).toUpperCase() || "?";
// HP bar: fraction remaining (unknown current HP reads as full), and a health-tiered colour.
const hpRatio = (t) =>
    t.max_hp ? Math.max(0, Math.min(1, (t.hp ?? t.max_hp) / t.max_hp)) : 0;
const hpPct = (t) => Math.round(hpRatio(t) * 100);
// A token whose HP we can see and which has dropped to 0 — rendered greyscale with a skull.
const isDowned = (t) =>
    t.hp !== null && t.hp !== undefined && t.hp <= 0 && !!t.max_hp;

const tokenDrag = ref(null);
const startDrag = (t, event) => {
    if (event.button !== 0) return; // ignore right/middle click so the context menu can open cleanly
    if (!t.can_edit) {
        tokenDrag.value = {
            id: t.id,
            moved: false,
            sx: event.clientX,
            sy: event.clientY,
            view: true,
        };
        return;
    }
    event.currentTarget.setPointerCapture(event.pointerId);
    tokenDrag.value = {
        id: t.id,
        x: t.x,
        y: t.y,
        moved: false,
        sx: event.clientX,
        sy: event.clientY,
    };
};
const moveDrag = (event) => {
    const d = tokenDrag.value;
    if (!d || d.view) return;
    if (
        !d.moved &&
        Math.abs(event.clientX - d.sx) < 3 &&
        Math.abs(event.clientY - d.sy) < 3
    )
        return;
    d.moved = true;
    const { x, y } = percentFromEvent(event);
    localPos[d.id] = { x, y };
    broadcastTokenMove(d.id, x, y, d.x, d.y); // let the table watch it move live (with its ruler)
};
// Snap a percentage to the centre of its grid cell (battle rooms are grid-based).
const snapToCell = (pct, divisions) => {
    if (!divisions || divisions < 1) return pct;
    const size = 100 / divisions;
    return Math.min(
        100 - size / 2,
        Math.max(size / 2, (Math.floor(pct / size) + 0.5) * size),
    );
};
const endDrag = (t) => {
    const d = tokenDrag.value;
    tokenDrag.value = null;
    if (!d) return;
    if (!d.moved) {
        openDetail(t);
        return;
    }
    const x = snapToCell(localPos[t.id].x, cols.value);
    const y = snapToCell(localPos[t.id].y, rows.value);
    localPos[t.id] = { x, y };
    // Clear the live ruler on the table now the token has landed.
    if (presenceChannel)
        presenceChannel.whisper("token-move-end", { id: t.id });
    router.put(
        route("room-tokens.update", t.id),
        { x, y },
        { preserveScroll: true, preserveState: true },
    );
};
// A live "ruler" while dragging a token: a dashed line from where it started to the current cell,
// labelled with the distance moved (Chebyshev grid distance × the scene's unit size). Shown to the
// dragger, and — via the token-move whisper carrying the origin — to the whole table.
const feetBetween = (a, b) => {
    const cwPct = 100 / (props.room.grid_size || 20);
    const chPct = imgSize.h ? (cellPx.value / imgSize.h) * 100 : cwPct;
    const dx = Math.abs(b.x - a.x) / cwPct;
    const dy = Math.abs(b.y - a.y) / chPct;
    return Math.round(Math.max(dx, dy)) * unitSize.value;
};
const remoteDragLines = reactive({}); // token id -> { a, b, color }
const dragLine = computed(() => {
    const d = tokenDrag.value;
    if (!d || !d.moved || d.view || d.x == null) return null;
    const pos = localPos[d.id];
    if (!pos) return null;
    const a = { x: d.x, y: d.y };
    return { a, b: { x: pos.x, y: pos.y }, feet: feetBetween(a, pos) };
});
watch(
    () => props.tokens.map((t) => `${t.id}:${t.x},${t.y}`).join("|"),
    () => {
        for (const t of props.tokens) {
            const local = localPos[t.id];
            if (
                local &&
                Math.abs(local.x - t.x) < 0.05 &&
                Math.abs(local.y - t.y) < 0.05
            ) {
                delete localPos[t.id];
                delete remoteDragLines[t.id]; // the move has landed; clear its ruler
            }
        }
    },
);
// Keep the open detail/sheet in sync with the freshly-loaded token (e.g. after a Refresh or a
// realtime reload), so its data updates in place instead of showing the stale object it opened with.
watch(
    () => props.tokens,
    (tokens) => {
        if (!detail.value) return;
        const fresh = tokens.find((t) => t.id === detail.value.id);
        if (fresh) detail.value = fresh;
    },
);

/* ---- add / search ---- */
const showAdd = ref(false);
const search = reactive({
    kind: "monster",
    source: "all",
    q: "",
    min_cr: "",
    max_cr: "",
    results: [],
    loading: false,
});
let searchTimer;
const runSearch = () => {
    if (!props.isGm) return;
    search.loading = true;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(async () => {
        try {
            const { data } = await window.axios.get(
                route("rooms.search", props.room.id),
                {
                    params: {
                        kind: search.kind,
                        source: search.source,
                        q: search.q || undefined,
                        min_cr: search.min_cr || undefined,
                        max_cr: search.max_cr || undefined,
                    },
                },
            );
            search.results = data.results;
        } finally {
            search.loading = false;
        }
    }, 250);
};
watch(
    () => [search.kind, search.source, search.q, search.min_cr, search.max_cr],
    runSearch,
);
const openAdd = () => {
    showAdd.value = true;
    if (props.isGm) runSearch();
};
const addResult = (result) => {
    const payload =
        result.type === "person"
            ? { source: "person", document_id: result.id }
            : { source: "monster", compendium_item_id: result.id };
    // New GM objects land on the active layer + the scene the GM is currently viewing.
    router.post(
        route("room-tokens.store", props.room.id),
        {
            ...payload,
            layer: activeLayer.value,
            scene: props.room.viewing_scene_id,
        },
        { preserveScroll: true, preserveState: true },
    );
};
// Player quick-add of a GM-allowed monster (pets, wildshape).
const addShortlistMonster = (id) =>
    router.post(
        route("room-tokens.store", props.room.id),
        { source: "monster", compendium_item_id: id },
        { preserveScroll: true, preserveState: true },
    );

// GM removes a player from the room (and clears their tokens).
const kick = (userId) =>
    router.delete(route("rooms.kick", [props.room.id, userId]), {
        preserveScroll: true,
        preserveState: true,
    });

// Drop a party-roster character onto the board.
const addCharacter = (id) =>
    router.post(
        route("room-tokens.store", props.room.id),
        {
            source: "character",
            character_id: id,
            layer: activeLayer.value,
            scene: props.room.viewing_scene_id,
        },
        { preserveScroll: true, preserveState: true },
    );

/* ---- D&D Beyond import ---- */
const ddbForm = useForm({ url: "", owner_user_id: null });
const importDdb = () => {
    if (!ddbForm.url.trim()) return;
    ddbForm
        .transform((data) => ({ ...data, scene: props.room.viewing_scene_id }))
        .post(route("room-tokens.ddb", props.room.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => ddbForm.reset(),
        });
};

/* ---- GM: monsters players may add themselves (pets, wildshape) ---- */
const shortlist = ref([...(props.room.player_monster_ids ?? [])]);
const toggleShortlist = (id) => {
    const i = shortlist.value.indexOf(id);
    if (i === -1) shortlist.value.push(id);
    else shortlist.value.splice(i, 1);
    saveRoom({ player_monster_ids: shortlist.value });
};

/* ---- preview (the "eye": read-only stat block / person blurb) ---- */
const preview = ref(null);
const openPreview = (result) => {
    preview.value = result;
};

/* ---- token detail (click-through) ---- */
const detail = ref(null);
const openDetail = (t) => {
    // Players may only inspect tokens they control.
    if (!props.isGm && !t.can_edit) return;
    detail.value = t;
};
const showStatblock = computed(
    () => !!detail.value?.statblock?.block?.abilities,
);
// Rolling from a stat block. The GM's rolls always log to the chat window but are hidden from players
// (a private /gmroll); holding Ctrl makes the GM's roll public. A player must hold Ctrl to share a roll;
// a plain click just uses the local dice popover.
const pushRoll = (command, expr) =>
    router.post(
        route("rooms.chat", props.room.id),
        {
            body: `/${command} ${expr}`,
            as_name: detail.value?.label,
            as_image: detail.value?.image_url,
        },
        { preserveScroll: true, preserveState: true },
    );
const onDetailClick = (event) => {
    const el = event.target.closest?.(".dice-roll");
    const raw = el?.dataset?.roll;
    if (!raw) return;
    const shared = event.ctrlKey || event.metaKey;

    // Shift = advantage, Alt = disadvantage — only meaningful for a leading d20.
    let expr = raw;
    if ((event.shiftKey || event.altKey) && /^\s*\d*d20\b/i.test(raw)) {
        expr = raw.replace(
            /^\s*\d*d20/i,
            event.shiftKey ? "2d20kh1" : "2d20kl1",
        );
    }

    if (props.isGm) {
        event.preventDefault();
        event.stopPropagation();
        pushRoll(shared ? "roll" : "gmroll", expr);
    } else if (shared) {
        event.preventDefault();
        event.stopPropagation();
        pushRoll("roll", expr);
    }
    // Player, no share modifier → fall through to the local dice popover.
};

// Echo the creature's most recent logged roll inside its own window. The GM's stat-block rolls are
// private /gmrolls that only surface in the chat log, so without this they'd have to glance away from
// the sheet to read the result. We mirror the chat message (the authoritative, server-rolled value)
// rather than re-rolling locally, so the number in the window always matches the number in the log.
const lastRoll = ref(null);
const latestRollFor = (label) => {
    if (!label) return null;
    for (let i = props.messages.length - 1; i >= 0; i--) {
        const message = props.messages[i];
        if (message.roll && (message.roll.as?.name || null) === label) {
            return message;
        }
    }
    return null;
};
// Reset when a different token's sheet opens; seed with that creature's most recent roll (if any).
watch(detail, (token) => {
    lastRoll.value = latestRollFor(token?.label);
});
// A new roll for the open creature (ours or a shared one) updates the in-window result live.
watch(
    () => props.messages,
    () => {
        if (!detail.value) return;
        const latest = latestRollFor(detail.value.label);
        if (latest) lastRoll.value = latest;
    },
);

// Live combat state edited from the sheet (HP, temp HP, death saves, spell slots): update the open
// token optimistically, then persist it. can_edit gates this to the owner or GM (also server-side).
const applyCombat = (patch) => {
    if (!detail.value) return;
    Object.assign(detail.value, patch);
    patchToken(detail.value.id, patch);
};
// Hit dice are rolled server-side (authoritative heal + a chat roll card + dice sound for the table).
const spendHitDice = (payload) => {
    if (!detail.value) return;
    router.post(route("room-tokens.hit-dice", detail.value.id), payload, {
        preserveScroll: true,
        preserveState: true,
    });
};
// Roll a pending concentration save from its chat prompt — resolved server-side, in place. Mode is
// "advantage", "disadvantage", or undefined for a flat d20.
const rollConcentration = (message, mode) =>
    router.put(
        route("room-messages.concentration", message.id),
        { mode },
        { preserveScroll: true, preserveState: true },
    );

// Re-pull a roster character from D&D Beyond so the sheet reflects the latest deep extraction.
const refreshingCharacter = ref(false);
const refreshCharacter = (characterId) => {
    refreshingCharacter.value = true;
    router.post(
        route("characters.refresh", characterId),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onRoomChanged(),
            onFinish: () => {
                refreshingCharacter.value = false;
            },
        },
    );
};

/* ---- Edit Character modal (owner/GM) ---- */
const selected = ref(null);
const healAmount = ref(1);
const editToken = (t) => {
    selected.value = { ...t, conditions: [...(t.conditions || [])] };
    healAmount.value = 1;
    detail.value = null;
};
const adjustHp = (direction) => {
    const t = selected.value;
    const amount = Number(healAmount.value) || 0;
    if (!t || !amount) return;
    const ceiling = t.max_hp ?? 9999;
    t.hp = Math.max(
        0,
        Math.min(ceiling, (Number(t.hp) || 0) + direction * amount),
    );
};
const toggleCondition = (key) => {
    const list = selected.value.conditions;
    const i = list.indexOf(key);
    if (i === -1) list.push(key);
    else list.splice(i, 1);
};
const numberOrNull = (value) =>
    value === "" || value === null || value === undefined
        ? null
        : Number(value);
const saveToken = () => {
    const t = selected.value;
    if (!t) return;
    router.put(
        route("room-tokens.update", t.id),
        {
            label: t.label || null,
            color: t.color || null,
            size: Number(t.size) || 1,
            notes: t.notes || null,
            hp: numberOrNull(t.hp),
            max_hp: numberOrNull(t.max_hp),
            ac: numberOrNull(t.ac),
            initiative: numberOrNull(t.initiative),
            elevation: numberOrNull(t.elevation),
            conditions: t.conditions,
            in_tracker: !!t.in_tracker,
            compendium_item_id: t.compendium_item_id || null,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                selected.value = null;
            },
        },
    );
};
const deleteToken = () => {
    const t = selected.value;
    if (t?.id)
        router.delete(route("room-tokens.destroy", t.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                selected.value = null;
            },
        });
};
const portraitForm = useForm({ file: null });
const onPortrait = (event, tokenId) => {
    const input = event.target;
    portraitForm.file = input.files[0];
    if (!portraitForm.file) return;
    portraitForm.post(route("room-tokens.image", tokenId), {
        preserveScroll: true,
        preserveState: true,
        forceFormData: true,
        onFinish: () => {
            input.value = "";
        },
    });
};

/* ---- tracker ---- */
const showTracker = ref(false);
const tracked = computed(() =>
    props.tokens
        .filter((t) => t.in_tracker)
        .sort(
            (a, b) =>
                (b.initiative ?? -999) - (a.initiative ?? -999) || a.id - b.id,
        ),
);
const trackerAction = (action) =>
    router.post(
        route("rooms.tracker", props.room.id),
        { action },
        { preserveScroll: true, preserveState: true },
    );
const patchToken = (id, patch) =>
    router.put(route("room-tokens.update", id), patch, {
        preserveScroll: true,
        preserveState: true,
    });
const setStat = (t, field, value) =>
    patchToken(t.id, { [field]: numberOrNull(value) });

/* ---- initiative turn control ---- */
const saveRoom = (patch) =>
    // Scene-owned edits (grid/fog/map) apply to the scene the GM is viewing; room fields ignore it.
    router.put(
        route("rooms.update", props.room.id),
        { ...patch, scene: props.room.viewing_scene_id },
        { preserveScroll: true, preserveState: true },
    );
const nextTurn = () => {
    const ids = tracked.value.map((t) => t.id);
    if (!ids.length) return;
    const i = ids.indexOf(props.room.active_token_id);
    let round = props.room.round;
    let active;
    if (i === -1) active = ids[0];
    else if (i + 1 >= ids.length) {
        active = ids[0];
        round = props.room.round + 1;
    } else active = ids[i + 1];
    saveRoom({ active_token_id: active, round });
};
const resetTurn = () =>
    saveRoom({ active_token_id: tracked.value[0]?.id ?? null, round: 1 });

/* ---- voice/video (WebRTC mesh, signalled over the presence channel) ---- */
const ICE = { iceServers: props.iceServers };
const mediaAvailable = ref(false);
const echoReady = ref(false); // realtime up → peers can actually connect
const inCall = ref(false);
const micOn = ref(false); // media starts off; enabling mic/cam on your tile joins the call
const camOn = ref(false);
const localStream = shallowRef(null);
const peers = reactive({}); // id -> { stream, name }
const peerState = reactive({}); // id -> { micOn, camOn } (signalled)
const pcs = {}; // id -> RTCPeerConnection (non-reactive)
const callMembers = new Set(); // ids seen in the call
let presenceChannel = null;
const peerTiles = computed(() =>
    Object.entries(peers).map(([id, p]) => ({
        id: Number(id),
        ...p,
        micOn: peerState[id]?.micOn !== false,
        camOn: peerState[id]?.camOn !== false,
    })),
);
const initialOf = (name) => (name || "?").trim().charAt(0).toUpperCase() || "?";
// One tile per connected person so the GM sees who is online — with or without camera. Your own tile
// carries the enable-mic/camera controls; a peer's tile shows their video once they turn it on.
const roster = computed(() =>
    present.value.length
        ? present.value
        : [{ id: props.me.id, name: props.me.display }],
);
const tiles = computed(() =>
    roster.value.map((u) => {
        if (u.id === props.me.id) {
            return {
                key: "self",
                id: u.id,
                name: props.me.display || u.name || "You",
                stream: localStream.value,
                camOn: camOn.value && !!localStream.value,
                micOn: micOn.value,
                hasMedia: !!localStream.value,
                self: true,
            };
        }
        const peer = peers[u.id];
        const state = peerState[u.id] || {};
        return {
            key: "p" + u.id,
            id: u.id,
            name: u.name,
            stream: peer?.stream,
            camOn: !!peer?.stream && state.camOn !== false,
            micOn: state.micOn !== false,
            hasMedia: !!peer?.stream,
            self: false,
        };
    }),
);

const nameFor = (id) =>
    present.value.find((u) => u.id === id)?.name ?? "Player";
const signal = (payload) =>
    presenceChannel && presenceChannel.whisper("signal", payload);
const broadcastState = () =>
    signal({
        kind: "media-state",
        from: props.me.id,
        micOn: micOn.value,
        camOn: camOn.value,
    });
const bindVideo = (el, stream) => {
    if (el && stream && el.srcObject !== stream) el.srcObject = stream;
};

const ensurePeer = (id) => {
    if (pcs[id]) return pcs[id];
    const pc = new RTCPeerConnection(ICE);
    if (localStream.value)
        localStream.value
            .getTracks()
            .forEach((t) => pc.addTrack(t, localStream.value));
    pc.ontrack = (event) => {
        peers[id] = { stream: markRaw(event.streams[0]), name: nameFor(id) };
    };
    pc.onicecandidate = (event) => {
        if (event.candidate)
            signal({
                kind: "ice",
                from: props.me.id,
                to: id,
                candidate: event.candidate,
            });
    };
    pcs[id] = pc;
    return pc;
};

// Deterministic offerer (lower id) so two peers never offer at once (glare).
const connect = async (id) => {
    const pc = ensurePeer(id);
    if (props.me.id < id) {
        await pc.setLocalDescription(await pc.createOffer());
        signal({
            kind: "sdp",
            from: props.me.id,
            to: id,
            description: pc.localDescription,
        });
    }
};

const teardownPeer = (id) => {
    if (pcs[id]) {
        try {
            pcs[id].close();
        } catch (e) {
            /* already closed */
        }
        delete pcs[id];
    }
    delete peers[id];
};

const onSignal = async (msg) => {
    if (!msg || !msg.kind || msg.from === props.me.id) return;
    const from = msg.from;

    if (msg.kind === "media-state") {
        peerState[from] = { micOn: msg.micOn, camOn: msg.camOn };
        return;
    }
    if (msg.kind === "call-join") {
        callMembers.add(from);
        if (inCall.value) {
            signal({ kind: "call-here", from: props.me.id, to: from });
            connect(from);
            broadcastState();
        }
        return;
    }
    if (msg.kind === "call-leave") {
        teardownPeer(from);
        callMembers.delete(from);
        delete peerState[from];
        return;
    }
    if (msg.to !== props.me.id) return; // remaining kinds are addressed
    if (msg.kind === "call-here") {
        callMembers.add(from);
        if (inCall.value) connect(from);
        return;
    }
    if (msg.kind === "sdp") {
        const pc = ensurePeer(from);
        await pc.setRemoteDescription(msg.description);
        if (msg.description.type === "offer") {
            await pc.setLocalDescription(await pc.createAnswer());
            signal({
                kind: "sdp",
                from: props.me.id,
                to: from,
                description: pc.localDescription,
            });
        }
        return;
    }
    if (msg.kind === "ice") {
        try {
            await ensurePeer(from).addIceCandidate(msg.candidate);
        } catch (e) {
            /* candidate arrived before remote description */
        }
    }
};

// Acquire mic + camera on first use (enabling either control), then join the call. Tracks start
// enabled per the current micOn/camOn so turning on just the camera doesn't also open the mic.
const ensureMedia = async () => {
    if (localStream.value) return true;
    try {
        localStream.value = await navigator.mediaDevices.getUserMedia({
            audio: true,
            video: true,
        });
    } catch (e) {
        alert("Couldn't access your microphone/camera.");
        return false;
    }
    localStream.value
        .getAudioTracks()
        .forEach((t) => (t.enabled = micOn.value));
    localStream.value
        .getVideoTracks()
        .forEach((t) => (t.enabled = camOn.value));
    inCall.value = true;
    signal({ kind: "call-join", from: props.me.id });
    callMembers.forEach((id) => connect(id));
    broadcastState();
    return true;
};

const leaveCall = () => {
    signal({ kind: "call-leave", from: props.me.id });
    Object.keys(pcs).forEach((id) => teardownPeer(Number(id)));
    if (localStream.value) {
        localStream.value.getTracks().forEach((t) => t.stop());
        localStream.value = null;
    }
    inCall.value = false;
    micOn.value = false;
    camOn.value = false;
    broadcastState();
};

const toggleMic = async () => {
    micOn.value = !micOn.value;
    if (!localStream.value) {
        if (!(await ensureMedia())) micOn.value = false;
        return; // ensureMedia applied the track state and broadcast it
    }
    localStream.value
        .getAudioTracks()
        .forEach((t) => (t.enabled = micOn.value));
    broadcastState();
};
const toggleCam = async () => {
    camOn.value = !camOn.value;
    if (!localStream.value) {
        if (!(await ensureMedia())) camOn.value = false;
        return;
    }
    localStream.value
        .getVideoTracks()
        .forEach((t) => (t.enabled = camOn.value));
    broadcastState();
};

// If the GM hides the voice/video section, drop out of any active call so a now-hidden tile can't keep
// a live mic or camera running.
watch(
    () => props.room.voice_enabled,
    (enabled) => {
        if (!enabled && inCall.value) leaveCall();
    },
);

/* ---- realtime + presence ---- */
let reloadTimer;
let channel;
const present = ref([]); // who is connected to this room right now
const onlineIds = computed(() => new Set(present.value.map((u) => u.id)));
const isOnline = (id) => onlineIds.value.has(id);

/* ---- live player cursors (ephemeral, over the presence channel) ---- */
const remoteCursors = reactive({}); // id -> { x, y, name, at }
// Live whispers are ephemeral and fan out to everyone connected; a viewer only applies one whose
// scene matches the board they're currently looking at (so scene-2 prep never leaks onto scene 1).
const onMyScene = (payload) =>
    payload?.scene === undefined ||
    payload.scene === props.room.viewing_scene_id;
let lastCursorSent = 0;
let cursorPrune;
const sendCursor = (event) => {
    if (!presenceChannel) return;
    const now = Date.now();
    if (now - lastCursorSent < 40) return; // throttle to ~25/sec
    lastCursorSent = now;
    const point = percentFromEvent(event);
    presenceChannel.whisper("cursor", {
        id: props.me.id,
        name: props.me.display,
        scene: props.room.viewing_scene_id,
        x: point.x,
        y: point.y,
    });
};
const onCursor = (payload) => {
    if (!payload || payload.id === props.me.id) return;
    if (!onMyScene(payload)) {
        delete remoteCursors[payload.id];
        return;
    }
    remoteCursors[payload.id] = {
        x: payload.x,
        y: payload.y,
        name: payload.name,
        at: Date.now(),
    };
};

/* ---- live previews: a token being dragged / a drawing in progress (ephemeral) ---- */
let lastTokenMoveSent = 0;
const broadcastTokenMove = (id, x, y, ox, oy) => {
    if (!presenceChannel) return;
    const token = props.tokens.find((t) => t.id === id);
    if (token?.layer === "gm") return; // never leak a hidden token's position
    const now = Date.now();
    if (now - lastTokenMoveSent < 40) return;
    lastTokenMoveSent = now;
    presenceChannel.whisper("token-move", {
        id,
        x,
        y,
        ox, // where the drag started, so the table sees the distance ruler too
        oy,
        scene: props.room.viewing_scene_id,
    });
};
const onTokenDragLive = (payload) => {
    if (!payload || !onMyScene(payload)) return;
    const token = props.tokens.find((t) => t.id === payload.id);
    if (!token) return;
    localPos[payload.id] = { x: payload.x, y: payload.y };
    if (payload.ox != null && payload.oy != null) {
        remoteDragLines[payload.id] = {
            a: { x: payload.ox, y: payload.oy },
            b: { x: payload.x, y: payload.y },
            color: token.color || "#d8a94a",
        };
    }
};
const remoteDrawDraft = ref(null);
let lastDrawSent = 0;
const broadcastDraw = () => {
    if (!presenceChannel || activeLayer.value === "gm") return; // don't leak a GM-layer sketch
    const now = Date.now();
    if (now - lastDrawSent < 60) return;
    lastDrawSent = now;
    presenceChannel.whisper("draw-live", {
        kind: drawDraft.kind,
        points: [...drawDraft.points],
        color: drawColor.value,
        scene: props.room.viewing_scene_id,
    });
};
const remoteAoeDraft = ref(null);
let lastAoeSent = 0;
const broadcastAoe = () => {
    if (!presenceChannel || activeLayer.value === "gm") return; // don't leak a GM-layer template
    const now = Date.now();
    if (now - lastAoeSent < 40) return;
    lastAoeSent = now;
    presenceChannel.whisper("aoe-live", {
        ...draftFromDrag(),
        scene: props.room.viewing_scene_id,
    });
};
// Measurements (ruler/radius) shown to the table on the same scene, during the drag and after release.
const remoteMeasures = reactive({}); // id -> { name, mode, a, b, feet }
let lastMeasureSent = 0;
const broadcastMeasure = (final = false) => {
    if (!presenceChannel || !measure.a || !measure.b) return;
    const now = Date.now();
    if (!final && now - lastMeasureSent < 40) return;
    lastMeasureSent = now;
    presenceChannel.whisper("measure", {
        id: props.me.id,
        name: props.me.display,
        scene: props.room.viewing_scene_id,
        mode: measure.mode,
        a: { ...measure.a },
        b: { ...measure.b },
        feet: measure.mode === "radius" ? radiusFeet.value : rulerFeet.value,
    });
};
const clearMeasureBroadcast = () => {
    if (presenceChannel)
        presenceChannel.whisper("measure-clear", { id: props.me.id });
};
// Diff-based: a token move arrives with its new coords, so we patch it in place — no reload.
const onTokenMoved = (event) => {
    const token = props.tokens.find((t) => t.id === event.tokenId);
    if (!token) return;
    token.x = event.x;
    token.y = event.y;
    delete localPos[token.id]; // let the authoritative position take over
    delete remoteDragLines[token.id]; // and clear its live ruler
};
// The realtime-syncable Inertia props. An unscoped RoomChanged reloads the whole bundle; a scoped one
// (e.g. a chat message) reloads only the props it names, so unrelated data isn't re-fetched.
const RELOADABLE_PROPS = [
    "room",
    "tokens",
    "templates",
    "drawings",
    "messages",
];
let pendingReload = new Set();
const onRoomChanged = (event) => {
    const only = event?.only?.length ? event.only : RELOADABLE_PROPS;
    for (const prop of only) pendingReload.add(prop);
    // A short debounce coalesces a burst of updates (unioning their scopes); keep it tight so sync
    // feels instant.
    clearTimeout(reloadTimer);
    reloadTimer = setTimeout(() => {
        const scope = [...pendingReload];
        pendingReload = new Set();
        router.reload({
            only: scope,
            preserveScroll: true,
            preserveState: true,
        });
    }, 60);
};
onMounted(() => {
    // Mic/camera work on any secure origin (https or localhost), independent of realtime.
    mediaAvailable.value = !!navigator.mediaDevices?.getUserMedia;
    if (!window.Echo) return;
    echoReady.value = true;
    channel = `rooms.${props.room.id}`;
    window.Echo.channel(channel)
        .listen(".RoomChanged", onRoomChanged)
        .listen(".TokenMoved", onTokenMoved);
    presenceChannel = window.Echo.join(channel)
        .here((users) => {
            present.value = users;
        })
        .joining((user) => {
            present.value = [...present.value, user];
        })
        .leaving((user) => {
            present.value = present.value.filter((u) => u.id !== user.id);
            teardownPeer(user.id);
            callMembers.delete(user.id);
            delete remoteCursors[user.id];
            delete remoteMeasures[user.id];
        });
    presenceChannel.listenForWhisper("signal", onSignal);
    presenceChannel.listenForWhisper("cursor", onCursor);
    presenceChannel.listenForWhisper("token-move", onTokenDragLive);
    presenceChannel.listenForWhisper("token-move-end", (payload) => {
        if (payload?.id != null) delete remoteDragLines[payload.id];
    });
    presenceChannel.listenForWhisper(
        "draw-live",
        (p) => (remoteDrawDraft.value = onMyScene(p) ? p : null),
    );
    presenceChannel.listenForWhisper(
        "draw-end",
        () => (remoteDrawDraft.value = null),
    );
    presenceChannel.listenForWhisper(
        "aoe-live",
        (p) => (remoteAoeDraft.value = onMyScene(p) ? p : null),
    );
    presenceChannel.listenForWhisper(
        "aoe-end",
        () => (remoteAoeDraft.value = null),
    );
    presenceChannel.listenForWhisper("measure", (p) => {
        if (!p?.id) return;
        if (onMyScene(p)) remoteMeasures[p.id] = p;
        else delete remoteMeasures[p.id];
    });
    presenceChannel.listenForWhisper("measure-clear", (p) => {
        if (p?.id) delete remoteMeasures[p.id];
    });
    // Drop a cursor that's gone quiet (tab hidden, moved away) so stale pointers don't linger.
    cursorPrune = setInterval(() => {
        const now = Date.now();
        for (const id of Object.keys(remoteCursors)) {
            if (now - remoteCursors[id].at > 5000) delete remoteCursors[id];
        }
    }, 2000);
});
onBeforeUnmount(() => {
    clearTimeout(reloadTimer);
    clearInterval(cursorPrune);
    leaveCall();
    if (window.Echo && channel) window.Echo.leave(channel);
});

/* ---- chat & dice ---- */
const showHelp = ref(false);
const chatInput = ref("");
const chatBox = ref(null);
const chatForm = reactive({ sending: false });
// Chat recipient chosen from the dropdown: "all", "gm", or a member's user id (as a string).
const chatTarget = ref("all");
const chatTargets = computed(() => {
    const list = [{ value: "all", label: "Everyone" }];
    if (!props.isGm) list.push({ value: "gm", label: "Game Master" });
    for (const member of props.room.members) {
        if (member.id !== props.me.id) {
            list.push({ value: String(member.id), label: member.name });
        }
    }
    return list;
});
const scrollChat = () => {
    if (chatBox.value) chatBox.value.scrollTop = chatBox.value.scrollHeight;
};
const sendChat = () => {
    const body = chatInput.value.trim();
    if (!body) return;
    chatForm.sending = true;
    router.post(
        route("rooms.chat", props.room.id),
        { body, to: chatTarget.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                chatInput.value = "";
            },
            onFinish: () => {
                chatForm.sending = false;
                nextTick(scrollChat);
            },
        },
    );
};
onMounted(() => nextTick(scrollChat));
watch(
    () => props.messages.length,
    () => nextTick(scrollChat),
);

// Play a dice sound when a roll the viewer can see arrives (public, or shared/whispered to them —
// the server only sends messages they're entitled to). History on first load is skipped.
const lastSeenMessageId = ref(0);
const playDiceSound = () => {
    try {
        const audio = new Audio("/sounds/Dice_02.wav");
        audio.volume = 0.5;
        audio.play().catch(() => {});
    } catch {
        /* audio unavailable */
    }
};
onMounted(() => {
    lastSeenMessageId.value = props.messages.reduce(
        (max, message) => Math.max(max, message.id),
        0,
    );
});
watch(
    () => props.messages,
    (messages) => {
        const fresh = messages.filter((m) => m.id > lastSeenMessageId.value);
        if (!fresh.length) return;
        lastSeenMessageId.value = fresh.reduce(
            (max, message) => Math.max(max, message.id),
            lastSeenMessageId.value,
        );
        // A pending save prompt isn't a roll yet — don't play the dice cue until it's actually rolled.
        if (fresh.some((message) => message.roll && !message.roll.pending))
            playDiceSound();
    },
);
watch(panelTab, (tab) => {
    if (tab === "chat") nextTick(scrollChat);
});

const hasImage = computed(() => !!props.room.image_url);
</script>

<template>
    <Head :title="room.name" />

    <div class="h-screen w-screen overflow-hidden bg-night text-ink">
        <div class="grid" :style="boardGridStyle">
            <!-- Board -->
            <div
                ref="viewport"
                class="relative min-w-0 overflow-hidden border-r border-edge2 bg-[#0b0d10]"
                :class="
                    fogTool || measureTool || aoeTool.active || drawTool
                        ? 'cursor-crosshair'
                        : 'cursor-grab'
                "
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
                        :src="room.image_url"
                        :alt="room.name"
                        class="block max-w-none select-none transition-[filter]"
                        :style="{
                            filter: mapDimmed ? 'brightness(0.5)' : 'none',
                        }"
                        draggable="false"
                        @load="fitToViewport"
                    />
                    <div
                        v-if="room.grid_visible && imgSize.w"
                        class="pointer-events-none absolute inset-0"
                        :style="gridStyle"
                    ></div>
                    <div
                        v-if="room.fog_enabled"
                        class="pointer-events-none absolute inset-0"
                    >
                        <div
                            v-for="fogCell in coveredCells"
                            :key="fogCell.key"
                            class="absolute"
                            :class="isGm ? 'bg-black/50' : 'bg-[#0b0d10]'"
                            :style="{
                                left: fogCell.left + 'px',
                                top: fogCell.top + 'px',
                                width: cellPx + 'px',
                                height: cellPx + 'px',
                            }"
                        ></div>
                    </div>

                    <div
                        v-for="t in tokens"
                        :key="t.id"
                        class="absolute -translate-x-1/2 -translate-y-1/2 touch-none"
                        :class="[
                            t.can_edit ? 'cursor-grab' : 'cursor-pointer',
                            measureTool || aoeTool.active || drawTool
                                ? 'pointer-events-none'
                                : '',
                            layerFaded(t.layer)
                                ? 'opacity-30 transition-opacity'
                                : 'transition-opacity',
                        ]"
                        :style="{ left: tokenX(t) + '%', top: tokenY(t) + '%' }"
                        @pointerdown.stop="startDrag(t, $event)"
                        @pointermove="moveDrag($event)"
                        @pointerup.stop="endDrag(t)"
                        @contextmenu="openContext(t, $event)"
                    >
                        <div
                            class="relative flex items-center justify-center overflow-hidden rounded-full bg-cover bg-center font-display shadow-lg"
                            :class="[
                                t.id === room.active_token_id
                                    ? 'ring-2 ring-amber'
                                    : t.concentrating_on
                                      ? 'ring-2 ring-teal-400/70'
                                      : '',
                                isDowned(t) ? 'grayscale' : '',
                            ]"
                            :style="{
                                width: t.size * cellPx + 'px',
                                height: t.size * cellPx + 'px',
                                backgroundColor: t.color || '#d8a94a',
                                backgroundImage: t.image_url
                                    ? `url(${t.image_url})`
                                    : 'none',
                                fontSize: 0.5 * t.size * cellPx + 'px',
                            }"
                        >
                            <span v-if="!t.image_url" class="text-black/70">{{
                                initial(t)
                            }}</span>
                            <span
                                v-if="isDowned(t)"
                                class="absolute inset-0 flex items-center justify-center bg-black/40"
                                :style="{
                                    fontSize: 0.6 * t.size * cellPx + 'px',
                                }"
                                title="Down (0 HP)"
                                >💀</span
                            >
                        </div>
                        <div
                            v-if="
                                t.label ||
                                t.max_hp ||
                                t.conditions.length ||
                                t.elevation ||
                                t.concentrating_on
                            "
                            class="absolute left-1/2 top-full flex flex-col items-center gap-0.5"
                            :style="{
                                transform: `translate(-50%, 3px) scale(${1 / view.scale})`,
                                transformOrigin: 'top center',
                            }"
                        >
                            <span
                                v-if="t.label"
                                class="whitespace-nowrap rounded bg-black/75 px-1.5 py-0.5 text-[11px] text-ink"
                                >{{ t.label }}</span
                            >
                            <span
                                v-if="t.concentrating_on"
                                class="whitespace-nowrap rounded bg-teal-900/80 px-1.5 py-0.5 font-mono text-[10px] text-teal-200 ring-1 ring-teal-400/40"
                                :title="`Concentrating: ${t.concentrating_on}`"
                                >◎ {{ t.concentrating_on }}</span
                            >
                            <span
                                v-if="t.elevation"
                                class="rounded bg-sky-900/80 px-1.5 py-0.5 font-mono text-[10px] text-sky-200"
                                >{{ elevLabel(t.elevation) }}</span
                            >
                            <div
                                v-if="t.max_hp"
                                class="flex items-center gap-1 rounded bg-black/75 px-1 py-0.5"
                            >
                                <div
                                    class="h-1.5 w-10 overflow-hidden rounded-full bg-red-800 ring-1 ring-black/40"
                                >
                                    <div
                                        class="h-full rounded-full bg-[#5ea564]"
                                        :style="{ width: hpPct(t) + '%' }"
                                    ></div>
                                </div>
                                <span
                                    v-if="isGm || t.can_edit"
                                    class="font-mono text-[9px] text-[#9dc47a]"
                                    >{{ t.hp ?? "?" }}/{{ t.max_hp }}</span
                                >
                            </div>
                            <div
                                v-if="t.conditions.length"
                                class="flex max-w-[120px] flex-wrap justify-center gap-0.5"
                            >
                                <span
                                    v-for="c in t.conditions"
                                    :key="c"
                                    :title="condLabel(c)"
                                    class="flex h-4 w-4 items-center justify-center rounded-full bg-[#f3efe6] ring-1 ring-black/30"
                                >
                                    <img
                                        v-if="condHasIcon(c)"
                                        :src="condIcon(c)"
                                        :alt="c"
                                        class="h-3 w-3"
                                    />
                                    <span
                                        v-else
                                        class="font-mono text-[7px] text-black/70"
                                        >{{ condAbbr(c) }}</span
                                    >
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Measurement overlay (ruler / radius) -->
                    <template v-if="measure.a && measure.b">
                        <svg
                            class="pointer-events-none absolute left-0 top-0"
                            :width="imgSize.w"
                            :height="imgSize.h"
                            style="overflow: visible"
                        >
                            <template v-if="measure.mode === 'radius'">
                                <circle
                                    :cx="toPx(measure.a).x"
                                    :cy="toPx(measure.a).y"
                                    :r="radiusPx"
                                    fill="rgba(216,169,74,0.15)"
                                    stroke="#d8a94a"
                                    :stroke-width="2 / view.scale"
                                />
                                <circle
                                    :cx="toPx(measure.a).x"
                                    :cy="toPx(measure.a).y"
                                    :r="3 / view.scale"
                                    fill="#d8a94a"
                                />
                            </template>
                            <template v-else>
                                <line
                                    :x1="toPx(measure.a).x"
                                    :y1="toPx(measure.a).y"
                                    :x2="toPx(measure.b).x"
                                    :y2="toPx(measure.b).y"
                                    stroke="#d8a94a"
                                    :stroke-width="2 / view.scale"
                                    :stroke-dasharray="`${6 / view.scale} ${4 / view.scale}`"
                                />
                                <circle
                                    :cx="toPx(measure.a).x"
                                    :cy="toPx(measure.a).y"
                                    :r="3 / view.scale"
                                    fill="#d8a94a"
                                />
                                <circle
                                    :cx="toPx(measure.b).x"
                                    :cy="toPx(measure.b).y"
                                    :r="3 / view.scale"
                                    fill="#d8a94a"
                                />
                            </template>
                        </svg>
                        <div
                            class="pointer-events-none absolute whitespace-nowrap rounded-md bg-black/80 px-2 py-1 text-center font-mono text-[11px] text-amber"
                            :style="{
                                left:
                                    (measure.mode === 'radius'
                                        ? toPx(measure.a).x
                                        : (toPx(measure.a).x +
                                              toPx(measure.b).x) /
                                          2) + 'px',
                                top:
                                    (measure.mode === 'radius'
                                        ? toPx(measure.a).y
                                        : (toPx(measure.a).y +
                                              toPx(measure.b).y) /
                                          2) + 'px',
                                transform: `translate(-50%, -50%) scale(${1 / view.scale})`,
                            }"
                        >
                            <template v-if="measure.mode === 'radius'">
                                <div>
                                    Radius: {{ radiusFeet }} {{ unitLabel }}
                                </div>
                                <div class="text-white/60">
                                    Diameter: {{ radiusFeet * 2 }}
                                    {{ unitLabel }}
                                </div>
                            </template>
                            <template v-else
                                >{{ rulerFeet }} {{ unitLabel }}</template
                            >
                        </div>
                    </template>

                    <!-- Token drag ruler: distance from the token's start cell to where it's headed -->
                    <template v-if="dragLine">
                        <svg
                            class="pointer-events-none absolute left-0 top-0"
                            :width="imgSize.w"
                            :height="imgSize.h"
                            style="overflow: visible"
                        >
                            <line
                                :x1="toPx(dragLine.a).x"
                                :y1="toPx(dragLine.a).y"
                                :x2="toPx(dragLine.b).x"
                                :y2="toPx(dragLine.b).y"
                                stroke="#d8a94a"
                                :stroke-width="2 / view.scale"
                                :stroke-dasharray="`${6 / view.scale} ${4 / view.scale}`"
                            />
                            <circle
                                :cx="toPx(dragLine.a).x"
                                :cy="toPx(dragLine.a).y"
                                :r="3 / view.scale"
                                fill="#d8a94a"
                            />
                        </svg>
                        <div
                            class="pointer-events-none absolute whitespace-nowrap rounded-md bg-black/80 px-2 py-0.5 font-mono text-[11px] text-amber"
                            :style="{
                                left: toPx(dragLine.b).x + 'px',
                                top: toPx(dragLine.b).y + 'px',
                                transform: `translate(-50%, -180%) scale(${1 / view.scale})`,
                            }"
                        >
                            {{ dragLine.feet }} {{ unitLabel }}
                        </div>
                    </template>

                    <!-- Other users' token drags (start → current) shown live to the table -->
                    <template
                        v-for="(m, id) in remoteDragLines"
                        :key="'drag' + id"
                    >
                        <svg
                            class="pointer-events-none absolute left-0 top-0"
                            :width="imgSize.w"
                            :height="imgSize.h"
                            style="overflow: visible"
                        >
                            <line
                                :x1="toPx(m.a).x"
                                :y1="toPx(m.a).y"
                                :x2="toPx(m.b).x"
                                :y2="toPx(m.b).y"
                                :stroke="m.color"
                                :stroke-width="2 / view.scale"
                                :stroke-dasharray="`${6 / view.scale} ${4 / view.scale}`"
                            />
                            <circle
                                :cx="toPx(m.a).x"
                                :cy="toPx(m.a).y"
                                :r="3 / view.scale"
                                :fill="m.color"
                            />
                        </svg>
                        <div
                            class="pointer-events-none absolute whitespace-nowrap rounded-md bg-black/80 px-2 py-0.5 font-mono text-[11px]"
                            :style="{
                                left: toPx(m.b).x + 'px',
                                top: toPx(m.b).y + 'px',
                                transform: `translate(-50%, -180%) scale(${1 / view.scale})`,
                                color: m.color,
                            }"
                        >
                            {{ feetBetween(m.a, m.b) }} {{ unitLabel }}
                        </div>
                    </template>

                    <!-- Other users' measurements (live + persisted) -->
                    <template
                        v-for="(m, id) in remoteMeasures"
                        :key="'meas' + id"
                    >
                        <svg
                            class="pointer-events-none absolute left-0 top-0"
                            :width="imgSize.w"
                            :height="imgSize.h"
                            style="overflow: visible"
                        >
                            <circle
                                v-if="m.mode === 'radius'"
                                :cx="toPx(m.a).x"
                                :cy="toPx(m.a).y"
                                :r="measureRadiusPx(m)"
                                :fill="userColor(Number(id))"
                                fill-opacity="0.15"
                                :stroke="userColor(Number(id))"
                                :stroke-width="2 / view.scale"
                            />
                            <line
                                v-else
                                :x1="toPx(m.a).x"
                                :y1="toPx(m.a).y"
                                :x2="toPx(m.b).x"
                                :y2="toPx(m.b).y"
                                :stroke="userColor(Number(id))"
                                :stroke-width="2 / view.scale"
                                :stroke-dasharray="`${6 / view.scale} ${4 / view.scale}`"
                            />
                            <circle
                                :cx="toPx(m.a).x"
                                :cy="toPx(m.a).y"
                                :r="3 / view.scale"
                                :fill="userColor(Number(id))"
                            />
                            <circle
                                v-if="m.mode !== 'radius'"
                                :cx="toPx(m.b).x"
                                :cy="toPx(m.b).y"
                                :r="3 / view.scale"
                                :fill="userColor(Number(id))"
                            />
                        </svg>
                        <div
                            class="pointer-events-none absolute whitespace-nowrap rounded-md bg-black/80 px-2 py-1 text-center font-mono text-[11px]"
                            :style="{
                                left:
                                    (m.mode === 'radius'
                                        ? toPx(m.a).x
                                        : (toPx(m.a).x + toPx(m.b).x) / 2) +
                                    'px',
                                top:
                                    (m.mode === 'radius'
                                        ? toPx(m.a).y
                                        : (toPx(m.a).y + toPx(m.b).y) / 2) +
                                    'px',
                                transform: `translate(-50%, -50%) scale(${1 / view.scale})`,
                                color: userColor(Number(id)),
                            }"
                        >
                            <div class="text-white/70">{{ m.name }}</div>
                            <template v-if="m.mode === 'radius'">
                                <div>Radius: {{ m.feet }} {{ unitLabel }}</div>
                                <div class="text-white/60">
                                    Diameter: {{ m.feet * 2 }} {{ unitLabel }}
                                </div>
                            </template>
                            <template v-else
                                >{{ m.feet }} {{ unitLabel }}</template
                            >
                        </div>
                    </template>

                    <!-- Drawing layer: GM freehand/shape annotations + live draft -->
                    <svg
                        v-if="
                            drawings.length ||
                            drawDraft.active ||
                            remoteDrawDraft
                        "
                        class="pointer-events-none absolute left-0 top-0"
                        :width="imgSize.w"
                        :height="imgSize.h"
                        style="overflow: visible"
                    >
                        <g
                            v-for="d in drawings"
                            :key="'draw' + d.id"
                            :opacity="layerFaded(d.layer) ? 0.3 : 1"
                        >
                            <polyline
                                v-if="
                                    d.kind === 'freehand' || d.kind === 'line'
                                "
                                :points="drawPolyline(d)"
                                fill="none"
                                :stroke="d.color"
                                :stroke-width="3 / view.scale"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                            <rect
                                v-else-if="d.kind === 'rect'"
                                :x="drawRect(d).x"
                                :y="drawRect(d).y"
                                :width="drawRect(d).w"
                                :height="drawRect(d).h"
                                :fill="d.fill ? d.color + '33' : 'none'"
                                :stroke="d.color"
                                :stroke-width="3 / view.scale"
                            />
                            <ellipse
                                v-else-if="d.kind === 'ellipse'"
                                :cx="drawEllipse(d).cx"
                                :cy="drawEllipse(d).cy"
                                :rx="drawEllipse(d).rx"
                                :ry="drawEllipse(d).ry"
                                :fill="d.fill ? d.color + '33' : 'none'"
                                :stroke="d.color"
                                :stroke-width="3 / view.scale"
                            />
                        </g>
                        <template v-if="drawDraft.active">
                            <polyline
                                v-if="
                                    drawDraft.kind === 'freehand' ||
                                    drawDraft.kind === 'line'
                                "
                                :points="drawPolyline(drawDraft)"
                                fill="none"
                                :stroke="drawColor"
                                :stroke-width="3 / view.scale"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                            <rect
                                v-else-if="drawDraft.kind === 'rect'"
                                :x="drawRect(drawDraft).x"
                                :y="drawRect(drawDraft).y"
                                :width="drawRect(drawDraft).w"
                                :height="drawRect(drawDraft).h"
                                fill="none"
                                :stroke="drawColor"
                                :stroke-width="3 / view.scale"
                            />
                            <ellipse
                                v-else-if="drawDraft.kind === 'ellipse'"
                                :cx="drawEllipse(drawDraft).cx"
                                :cy="drawEllipse(drawDraft).cy"
                                :rx="drawEllipse(drawDraft).rx"
                                :ry="drawEllipse(drawDraft).ry"
                                fill="none"
                                :stroke="drawColor"
                                :stroke-width="3 / view.scale"
                            />
                        </template>
                        <!-- Another user's stroke, live (ephemeral) -->
                        <template v-if="remoteDrawDraft">
                            <polyline
                                v-if="
                                    remoteDrawDraft.kind === 'freehand' ||
                                    remoteDrawDraft.kind === 'line'
                                "
                                :points="drawPolyline(remoteDrawDraft)"
                                fill="none"
                                :stroke="remoteDrawDraft.color"
                                :stroke-width="3 / view.scale"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                            <rect
                                v-else-if="remoteDrawDraft.kind === 'rect'"
                                :x="drawRect(remoteDrawDraft).x"
                                :y="drawRect(remoteDrawDraft).y"
                                :width="drawRect(remoteDrawDraft).w"
                                :height="drawRect(remoteDrawDraft).h"
                                fill="none"
                                :stroke="remoteDrawDraft.color"
                                :stroke-width="3 / view.scale"
                            />
                            <ellipse
                                v-else-if="remoteDrawDraft.kind === 'ellipse'"
                                :cx="drawEllipse(remoteDrawDraft).cx"
                                :cy="drawEllipse(remoteDrawDraft).cy"
                                :rx="drawEllipse(remoteDrawDraft).rx"
                                :ry="drawEllipse(remoteDrawDraft).ry"
                                fill="none"
                                :stroke="remoteDrawDraft.color"
                                :stroke-width="3 / view.scale"
                            />
                        </template>
                    </svg>

                    <!-- Live player cursors -->
                    <div
                        v-for="(cursor, id) in remoteCursors"
                        :key="'cur' + id"
                        class="pointer-events-none absolute z-20 flex items-center gap-1"
                        :style="{
                            left: cursor.x + '%',
                            top: cursor.y + '%',
                            transform: `scale(${1 / view.scale})`,
                            transformOrigin: 'top left',
                        }"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            class="h-4 w-4 drop-shadow"
                            :style="{ color: userColor(Number(id)) }"
                            fill="currentColor"
                        >
                            <path
                                d="M4 2l7 18 2.5-7.5L21 10z"
                                stroke="#000"
                                stroke-width="1.5"
                            />
                        </svg>
                        <span
                            class="whitespace-nowrap rounded px-1 py-0.5 text-[10px] font-medium text-white"
                            :style="{ backgroundColor: userColor(Number(id)) }"
                            >{{ cursor.name }}</span
                        >
                    </div>

                    <!-- AoE templates: placed spell shapes + live placement preview -->
                    <svg
                        v-if="
                            templates.length ||
                            aoeDraftTemplate ||
                            remoteAoeDraft
                        "
                        class="pointer-events-none absolute left-0 top-0"
                        :width="imgSize.w"
                        :height="imgSize.h"
                        style="overflow: visible"
                    >
                        <g
                            v-for="t in templates"
                            :key="t.id"
                            :opacity="layerFaded(t.layer) ? 0.3 : 1"
                        >
                            <circle
                                v-if="t.kind === 'circle'"
                                :cx="tplCircle(t).cx"
                                :cy="tplCircle(t).cy"
                                :r="tplCircle(t).r"
                                :fill="t.color + '26'"
                                :stroke="t.color"
                                :stroke-width="2 / view.scale"
                            />
                            <rect
                                v-else-if="t.kind === 'square'"
                                :x="tplSquare(t).x"
                                :y="tplSquare(t).y"
                                :width="tplSquare(t).side"
                                :height="tplSquare(t).side"
                                :fill="t.color + '26'"
                                :stroke="t.color"
                                :stroke-width="2 / view.scale"
                            />
                            <polygon
                                v-else
                                :points="tplPolygon(t)"
                                :fill="t.color + '26'"
                                :stroke="t.color"
                                :stroke-width="2 / view.scale"
                            />
                        </g>
                        <template v-if="aoeDraftTemplate">
                            <circle
                                v-if="aoeDraftTemplate.kind === 'circle'"
                                :cx="tplCircle(aoeDraftTemplate).cx"
                                :cy="tplCircle(aoeDraftTemplate).cy"
                                :r="tplCircle(aoeDraftTemplate).r"
                                :fill="aoeDraftTemplate.color + '26'"
                                :stroke="aoeDraftTemplate.color"
                                :stroke-width="2 / view.scale"
                                :stroke-dasharray="`${6 / view.scale} ${4 / view.scale}`"
                            />
                            <rect
                                v-else-if="aoeDraftTemplate.kind === 'square'"
                                :x="tplSquare(aoeDraftTemplate).x"
                                :y="tplSquare(aoeDraftTemplate).y"
                                :width="tplSquare(aoeDraftTemplate).side"
                                :height="tplSquare(aoeDraftTemplate).side"
                                :fill="aoeDraftTemplate.color + '26'"
                                :stroke="aoeDraftTemplate.color"
                                :stroke-width="2 / view.scale"
                                :stroke-dasharray="`${6 / view.scale} ${4 / view.scale}`"
                            />
                            <polygon
                                v-else
                                :points="tplPolygon(aoeDraftTemplate)"
                                :fill="aoeDraftTemplate.color + '26'"
                                :stroke="aoeDraftTemplate.color"
                                :stroke-width="2 / view.scale"
                                :stroke-dasharray="`${6 / view.scale} ${4 / view.scale}`"
                            />
                        </template>
                        <!-- Another user's template, live (ephemeral) -->
                        <template v-if="remoteAoeDraft">
                            <circle
                                v-if="remoteAoeDraft.kind === 'circle'"
                                :cx="tplCircle(remoteAoeDraft).cx"
                                :cy="tplCircle(remoteAoeDraft).cy"
                                :r="tplCircle(remoteAoeDraft).r"
                                :fill="remoteAoeDraft.color + '26'"
                                :stroke="remoteAoeDraft.color"
                                :stroke-width="2 / view.scale"
                                :stroke-dasharray="`${6 / view.scale} ${4 / view.scale}`"
                            />
                            <rect
                                v-else-if="remoteAoeDraft.kind === 'square'"
                                :x="tplSquare(remoteAoeDraft).x"
                                :y="tplSquare(remoteAoeDraft).y"
                                :width="tplSquare(remoteAoeDraft).side"
                                :height="tplSquare(remoteAoeDraft).side"
                                :fill="remoteAoeDraft.color + '26'"
                                :stroke="remoteAoeDraft.color"
                                :stroke-width="2 / view.scale"
                                :stroke-dasharray="`${6 / view.scale} ${4 / view.scale}`"
                            />
                            <polygon
                                v-else
                                :points="tplPolygon(remoteAoeDraft)"
                                :fill="remoteAoeDraft.color + '26'"
                                :stroke="remoteAoeDraft.color"
                                :stroke-width="2 / view.scale"
                                :stroke-dasharray="`${6 / view.scale} ${4 / view.scale}`"
                            />
                        </template>
                    </svg>
                    <!-- Live size readout while placing -->
                    <div
                        v-if="aoeDraftTemplate"
                        class="pointer-events-none absolute whitespace-nowrap rounded-md bg-black/80 px-2 py-1 font-mono text-[11px] text-amber"
                        :style="{
                            left: aoeDraftTemplate.x + '%',
                            top: aoeDraftTemplate.y + '%',
                            transform: `translate(-50%, -150%) scale(${1 / view.scale})`,
                        }"
                    >
                        {{ aoeDraftTemplate.length }} {{ unitLabel }}
                    </div>
                    <!-- Dismiss / reveal buttons for placed templates (creator or GM) -->
                    <template v-for="t in templates" :key="'rm-' + t.id">
                        <div
                            v-if="t.can_remove && !aoeTool.active"
                            class="absolute z-10 flex items-center gap-1"
                            :style="{
                                left: t.x + '%',
                                top: t.y + '%',
                                transform: `translate(-50%, -50%) scale(${1 / view.scale})`,
                            }"
                        >
                            <button
                                v-if="isGm && t.layer === 'gm'"
                                class="flex h-5 w-5 items-center justify-center rounded-full bg-black/70 text-[10px] text-amber ring-1 ring-white/40 hover:bg-amber hover:text-black"
                                title="Reveal to players"
                                @pointerdown.stop
                                @click.stop="revealTemplate(t.id)"
                            >
                                👁
                            </button>
                            <button
                                class="flex h-5 w-5 items-center justify-center rounded-full bg-black/70 text-[10px] text-white ring-1 ring-white/40 hover:bg-red-600"
                                title="Remove template"
                                @pointerdown.stop
                                @click.stop="removeTemplate(t.id)"
                            >
                                ✕
                            </button>
                        </div>
                    </template>
                </div>

                <div
                    v-else-if="isGm"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-faint"
                    @pointerdown.stop
                >
                    <span class="text-sm">This scene has no map yet</span>
                    <button class="btn-primary" @click="openMapPicker">
                        Choose a map…
                    </button>
                    <span class="text-[11px] text-faint"
                        >Upload, pick from your media library, or use a
                        location's map.</span
                    >
                </div>
                <div
                    v-else
                    class="absolute inset-0 flex items-center justify-center text-faint"
                >
                    The GM hasn't set a map yet.
                </div>

                <!-- Tool rail (icon buttons) -->
                <div
                    v-if="hasImage"
                    class="absolute left-2 top-2 flex flex-col items-center gap-1.5 rounded-lg border border-edge2 bg-surface/80 p-1 shadow-lg backdrop-blur"
                    @pointerdown.stop
                >
                    <button
                        class="flex h-9 w-9 items-center justify-center rounded-md border border-edge3 bg-surface text-muted shadow hover:text-ink"
                        title="Add token"
                        @click="openAdd"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            class="h-5 w-5"
                        >
                            <line x1="12" y1="5" x2="12" y2="19" />
                            <line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                    </button>
                    <button
                        v-if="isGm || room.players_see_tracker"
                        class="flex h-9 w-9 items-center justify-center rounded-md border border-edge3 bg-surface text-muted shadow hover:text-ink"
                        title="Initiative tracker"
                        @click="showTracker = true"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            class="h-5 w-5"
                        >
                            <line x1="8" y1="6" x2="20" y2="6" />
                            <line x1="8" y1="12" x2="20" y2="12" />
                            <line x1="8" y1="18" x2="20" y2="18" />
                            <circle cx="4" cy="6" r="1" />
                            <circle cx="4" cy="12" r="1" />
                            <circle cx="4" cy="18" r="1" />
                        </svg>
                    </button>
                    <button
                        class="flex h-9 w-9 items-center justify-center rounded-md border bg-surface shadow"
                        :class="
                            measureTool === 'ruler'
                                ? 'border-amber/60 text-amber'
                                : 'border-edge3 text-muted hover:text-ink'
                        "
                        title="Measure distance"
                        @click="toggleMeasure('ruler')"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="h-5 w-5"
                        >
                            <line x1="4" y1="20" x2="20" y2="4" />
                            <line x1="5" y1="14" x2="10" y2="19" />
                            <line x1="14" y1="5" x2="19" y2="10" />
                        </svg>
                    </button>
                    <button
                        class="flex h-9 w-9 items-center justify-center rounded-md border bg-surface shadow"
                        :class="
                            measureTool === 'radius'
                                ? 'border-amber/60 text-amber'
                                : 'border-edge3 text-muted hover:text-ink'
                        "
                        title="Measure a radius (AoE)"
                        @click="toggleMeasure('radius')"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            class="h-5 w-5"
                        >
                            <circle cx="12" cy="12" r="8" />
                            <line x1="12" y1="12" x2="20" y2="12" />
                            <circle
                                cx="12"
                                cy="12"
                                r="1.5"
                                fill="currentColor"
                            />
                        </svg>
                    </button>
                    <div class="relative">
                        <button
                            class="flex h-9 w-9 items-center justify-center rounded-md border bg-surface shadow"
                            :class="
                                aoeTool.active
                                    ? 'border-amber/60 text-amber'
                                    : 'border-edge3 text-muted hover:text-ink'
                            "
                            title="Spell templates (AoE)"
                            @click="toggleAoePanel"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="h-5 w-5"
                            >
                                <path d="M12 3 L21 20 L3 20 Z" />
                            </svg>
                        </button>
                        <div
                            v-if="showAoe"
                            class="absolute left-11 top-0 w-48 rounded-lg border border-edge2 bg-surface p-2 shadow-xl"
                        >
                            <div
                                class="mb-1 font-mono text-[10px] uppercase tracking-[0.08em] text-faint"
                            >
                                Place a template
                            </div>
                            <div class="grid grid-cols-4 gap-1">
                                <button
                                    v-for="k in AOE_KINDS"
                                    :key="k"
                                    :title="k"
                                    class="flex h-9 items-center justify-center rounded border text-lg"
                                    :class="
                                        aoeTool.active && aoeTool.kind === k
                                            ? 'border-amber/60 bg-amber/15 text-amber'
                                            : 'border-edge3 text-muted hover:text-ink'
                                    "
                                    @click="toggleAoe(k)"
                                >
                                    {{ AOE_GLYPH[k] }}
                                </button>
                            </div>
                            <label
                                class="mt-2 flex items-center justify-between text-[11px] text-faint"
                                >Colour
                                <input
                                    v-model="aoeTool.color"
                                    type="color"
                                    class="h-6 w-9 cursor-pointer rounded border border-edge3 bg-transparent"
                                />
                            </label>
                            <p
                                class="mt-1.5 text-[10px] leading-snug text-faint"
                            >
                                Drag on the map: press for the origin, drag to
                                set size &amp; aim, release to place.
                            </p>
                            <button
                                v-if="isGm && templates.length"
                                class="mt-2 w-full rounded px-2 py-1 text-left text-[11px] text-red-400 hover:bg-night"
                                @click="clearTemplates"
                            >
                                Clear all templates
                            </button>
                        </div>
                    </div>
                    <div v-if="isGm" class="relative">
                        <button
                            class="flex h-9 w-9 items-center justify-center rounded-md border bg-surface shadow"
                            :class="
                                drawTool
                                    ? 'border-amber/60 text-amber'
                                    : 'border-edge3 text-muted hover:text-ink'
                            "
                            title="Draw (GM)"
                            @click="toggleDrawPanel"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="h-5 w-5"
                            >
                                <path d="M12 19l7-7 3 3-7 7-3-3z" />
                                <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18z" />
                                <path d="M2 2l7.586 7.586" />
                                <circle cx="11" cy="11" r="2" />
                            </svg>
                        </button>
                        <div
                            v-if="showDraw"
                            class="absolute left-11 top-0 w-44 rounded-lg border border-edge2 bg-surface p-2 shadow-xl"
                        >
                            <div class="eyebrow-muted mb-1">Draw</div>
                            <div class="grid grid-cols-4 gap-1">
                                <button
                                    v-for="k in DRAW_KINDS"
                                    :key="k"
                                    :title="k"
                                    class="flex h-9 items-center justify-center rounded border text-lg"
                                    :class="
                                        drawTool === k
                                            ? 'border-amber/60 bg-amber/15 text-amber'
                                            : 'border-edge3 text-muted hover:text-ink'
                                    "
                                    @click="toggleDraw(k)"
                                >
                                    {{ DRAW_GLYPH[k] }}
                                </button>
                            </div>
                            <label
                                class="mt-2 flex items-center justify-between text-[11px] text-faint"
                                >Colour
                                <input
                                    v-model="drawColor"
                                    type="color"
                                    class="h-6 w-9 cursor-pointer rounded border border-edge3 bg-transparent"
                                />
                            </label>
                            <button
                                v-if="drawings.length"
                                class="mt-2 w-full rounded px-2 py-1 text-left text-[11px] text-red-400 hover:bg-night"
                                @click="clearDrawings"
                            >
                                Clear drawings
                            </button>
                        </div>
                    </div>
                    <button
                        v-if="isGm"
                        class="flex h-9 w-9 items-center justify-center rounded-md border bg-surface shadow"
                        :class="
                            activeLayer === 'gm'
                                ? 'border-amber/60 text-amber'
                                : 'border-edge3 text-muted hover:text-ink'
                        "
                        :title="
                            activeLayer === 'gm'
                                ? 'New objects go on the GM layer (hidden from players)'
                                : 'New objects go on the token layer (everyone sees)'
                        "
                        @click="
                            activeLayer = activeLayer === 'gm' ? 'token' : 'gm'
                        "
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="h-5 w-5"
                        >
                            <polygon points="12 2 2 7 12 12 22 7 12 2" />
                            <polyline points="2 17 12 22 22 17" />
                            <polyline points="2 12 12 17 22 12" />
                        </svg>
                    </button>
                    <template v-if="isGm">
                        <button
                            class="flex h-9 w-9 items-center justify-center rounded-md border border-edge3 bg-surface text-muted shadow hover:text-ink"
                            title="Replace map"
                            @click="openMapPicker"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="h-5 w-5"
                            >
                                <rect
                                    x="3"
                                    y="3"
                                    width="18"
                                    height="18"
                                    rx="2"
                                />
                                <circle cx="8.5" cy="8.5" r="1.5" />
                                <path d="M21 15l-5-5L5 21" />
                            </svg>
                        </button>
                    </template>
                </div>

                <!-- Help + panel toggle -->
                <div
                    class="absolute right-3 top-3 flex items-center gap-2"
                    @pointerdown.stop
                >
                    <button
                        class="flex h-8 w-8 items-center justify-center rounded-full border border-edge3 bg-surface text-muted shadow hover:text-ink"
                        :title="panelCollapsed ? 'Show panel' : 'Hide panel'"
                        @click="panelCollapsed = !panelCollapsed"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="h-4 w-4"
                        >
                            <rect x="3" y="4" width="18" height="16" rx="2" />
                            <line x1="15" y1="4" x2="15" y2="20" />
                        </svg>
                    </button>
                    <button
                        class="flex h-8 w-8 items-center justify-center rounded-full border border-edge3 bg-surface text-sm text-muted shadow hover:text-ink"
                        title="Commands & shortcuts"
                        @click="showHelp = !showHelp"
                    >
                        ?
                    </button>
                    <div
                        v-if="showHelp"
                        class="absolute right-0 top-10 w-72 rounded-lg border border-edge2 bg-surface p-3 text-[13px] shadow-xl"
                    >
                        <div class="mb-1 font-display text-ink">
                            Chat commands
                        </div>
                        <dl class="space-y-1 text-muted">
                            <div>
                                <code class="text-amber">/roll 2d6+3</code> ·
                                <code class="text-amber">/r</code> — a public
                                roll
                            </div>
                            <div>
                                <code class="text-amber">/gmroll 2d6</code> ·
                                <code class="text-amber">/gr</code> — only you &
                                the GM see it
                            </div>
                            <div>
                                <code class="text-amber">/w Aria …</code> ·
                                <code class="text-amber">/w gm …</code> — a
                                private whisper
                            </div>
                            <div>
                                Advantage / disadvantage:
                                <code class="text-amber">2d20kh1</code> /
                                <code class="text-amber">kl1</code>
                            </div>
                        </dl>
                        <div class="mb-1 mt-3 font-display text-ink">Board</div>
                        <ul class="space-y-1 text-muted">
                            <li>Scroll to zoom · drag to pan</li>
                            <li>Drag your token to move it</li>
                            <li>Click a token for its details</li>
                            <template v-if="isGm">
                                <li>
                                    Right-click a token for conditions,
                                    elevation & more
                                </li>
                                <li>
                                    Measure distances and place AoE templates
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>

                <!-- Voice / video + presence (GM can hide the whole section for the room) -->
                <div
                    v-if="tiles.length && room.voice_enabled"
                    class="group absolute bottom-3 left-3 z-20 flex flex-col gap-2"
                    @pointerdown.stop
                    @wheel.stop
                >
                    <!-- Tiles: one per connected person -->
                    <div class="flex flex-wrap gap-2">
                        <div
                            v-for="tile in tiles"
                            :key="tile.key"
                            class="group/tile relative overflow-hidden rounded-xl bg-[#0f1114] shadow-xl ring-1 ring-white/15"
                            :class="camTileClass"
                        >
                            <video
                                v-if="tile.camOn"
                                :ref="(el) => bindVideo(el, tile.stream)"
                                autoplay
                                playsinline
                                :muted="tile.self"
                                class="h-full w-full object-cover"
                            ></video>
                            <div
                                v-else
                                class="flex h-full w-full items-center justify-center bg-[#15181d] font-display text-2xl text-faint"
                            >
                                {{ initialOf(tile.name) }}
                            </div>

                            <!-- status badges (top-right) -->
                            <div class="absolute right-1 top-1 flex gap-1">
                                <span
                                    v-if="!tile.micOn && tile.hasMedia"
                                    title="Muted"
                                    class="flex h-5 w-5 items-center justify-center rounded-full bg-red-600 text-white shadow"
                                >
                                    <svg
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        class="h-3 w-3"
                                    >
                                        <rect
                                            x="9"
                                            y="2"
                                            width="6"
                                            height="11"
                                            rx="3"
                                        />
                                        <path d="M5 10v1a7 7 0 0 0 14 0v-1" />
                                        <line x1="4" y1="3" x2="20" y2="21" />
                                    </svg>
                                </span>
                                <span
                                    v-if="!tile.camOn && tile.hasMedia"
                                    title="Camera off"
                                    class="flex h-5 w-5 items-center justify-center rounded-full bg-red-600 text-white shadow"
                                >
                                    <svg
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        class="h-3 w-3"
                                    >
                                        <rect
                                            x="2"
                                            y="6"
                                            width="13"
                                            height="12"
                                            rx="2"
                                        />
                                        <path d="M22 8l-5 4 5 4V8z" />
                                        <line x1="4" y1="3" x2="20" y2="21" />
                                    </svg>
                                </span>
                            </div>

                            <!-- hover controls (your own tile) -->
                            <div
                                v-if="tile.self && mediaAvailable"
                                class="absolute inset-0 flex items-center justify-center gap-2.5 bg-black/25 opacity-0 transition group-hover/tile:opacity-100"
                            >
                                <button
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-black/55 ring-1 ring-white/25 hover:bg-black/75"
                                    :class="
                                        micOn ? 'text-white' : 'text-red-400'
                                    "
                                    :title="micOn ? 'Mute' : 'Unmute'"
                                    @click.stop="toggleMic"
                                >
                                    <svg
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="h-4 w-4"
                                    >
                                        <rect
                                            x="9"
                                            y="2"
                                            width="6"
                                            height="12"
                                            rx="3"
                                        />
                                        <path d="M5 10v1a7 7 0 0 0 14 0v-1" />
                                        <line x1="12" y1="19" x2="12" y2="22" />
                                        <line
                                            v-if="!micOn"
                                            x1="4"
                                            y1="3"
                                            x2="20"
                                            y2="21"
                                        />
                                    </svg>
                                </button>
                                <button
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-black/55 ring-1 ring-white/25 hover:bg-black/75"
                                    :class="
                                        camOn ? 'text-white' : 'text-red-400'
                                    "
                                    :title="
                                        camOn
                                            ? 'Turn camera off'
                                            : 'Turn camera on'
                                    "
                                    @click.stop="toggleCam"
                                >
                                    <svg
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="h-4 w-4"
                                    >
                                        <rect
                                            x="2"
                                            y="6"
                                            width="13"
                                            height="12"
                                            rx="2"
                                        />
                                        <path d="M22 8l-5 4 5 4V8z" />
                                        <line
                                            v-if="!camOn"
                                            x1="4"
                                            y1="3"
                                            x2="20"
                                            y2="21"
                                        />
                                    </svg>
                                </button>
                                <button
                                    v-if="tile.hasMedia"
                                    class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-black/55 text-red-400 ring-1 ring-white/25 hover:bg-black/75"
                                    title="Leave call"
                                    @click.stop="leaveCall"
                                >
                                    <svg
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        class="h-3.5 w-3.5"
                                    >
                                        <line x1="5" y1="5" x2="19" y2="19" />
                                        <line x1="19" y1="5" x2="5" y2="19" />
                                    </svg>
                                </button>
                            </div>

                            <!-- name bar -->
                            <div
                                class="absolute inset-x-0 bottom-0 bg-black/70 px-2 py-1 text-center text-[12px] font-medium text-white"
                            >
                                {{ tile.name }}
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="inCall && !echoReady"
                        class="max-w-[16rem] rounded-md bg-black/70 px-2 py-1 text-[11px] text-amber"
                    >
                        Realtime is off, so others can't see or hear you — this
                        is a local preview. Enable Reverb for live calls.
                    </div>
                </div>
            </div>

            <!-- Side panel -->
            <div
                v-if="!panelCollapsed"
                class="flex min-h-0 flex-col bg-surface"
            >
                <!-- Tab bar (icons) -->
                <div
                    class="flex shrink-0 items-center gap-1 border-b border-edge2 px-2 py-2"
                >
                    <button
                        v-for="tab in panelTabs"
                        :key="tab.key"
                        :title="tab.label"
                        class="flex h-9 w-9 items-center justify-center rounded-md"
                        :class="
                            panelTab === tab.key
                                ? 'bg-raised text-ink'
                                : 'text-muted hover:text-ink'
                        "
                        @click="panelTab = tab.key"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="h-5 w-5"
                        >
                            <path
                                v-if="tab.key === 'chat'"
                                d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"
                            />
                            <template v-else-if="tab.key === 'tracker'">
                                <line x1="8" y1="6" x2="20" y2="6" />
                                <line x1="8" y1="12" x2="20" y2="12" />
                                <line x1="8" y1="18" x2="20" y2="18" />
                                <circle cx="4" cy="6" r="1" />
                                <circle cx="4" cy="12" r="1" />
                                <circle cx="4" cy="18" r="1" />
                            </template>
                            <path
                                v-else-if="tab.key === 'gm'"
                                d="M12 2l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V5z"
                            />
                            <template v-else-if="tab.key === 'settings'">
                                <line x1="4" y1="21" x2="4" y2="14" />
                                <line x1="4" y1="10" x2="4" y2="3" />
                                <line x1="12" y1="21" x2="12" y2="12" />
                                <line x1="12" y1="8" x2="12" y2="3" />
                                <line x1="20" y1="21" x2="20" y2="16" />
                                <line x1="20" y1="12" x2="20" y2="3" />
                                <line x1="1" y1="14" x2="7" y2="14" />
                                <line x1="9" y1="8" x2="15" y2="8" />
                                <line x1="17" y1="16" x2="23" y2="16" />
                            </template>
                            <template v-else-if="tab.key === 'handouts'">
                                <rect
                                    x="3"
                                    y="3"
                                    width="18"
                                    height="18"
                                    rx="2"
                                />
                                <circle cx="8.5" cy="8.5" r="1.5" />
                                <path d="M21 15l-5-5L5 21" />
                            </template>
                            <template v-else>
                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
                                <path
                                    d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"
                                />
                            </template>
                        </svg>
                    </button>
                </div>

                <!-- Chat / Rolls -->
                <div
                    v-if="panelTab === 'chat'"
                    class="flex min-h-0 flex-1 flex-col"
                >
                    <div
                        ref="chatBox"
                        class="min-h-0 flex-1 space-y-2 overflow-y-auto px-3 py-3"
                    >
                        <div
                            v-for="m in messages"
                            :key="m.id"
                            class="flex items-start gap-2"
                            :class="
                                m.private
                                    ? 'rounded-md border-l-2 border-teal/60 bg-[#15252a]/40 px-2 py-1'
                                    : ''
                            "
                        >
                            <span
                                class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center overflow-hidden rounded-full bg-cover bg-center font-mono text-[11px]"
                                :style="{
                                    backgroundColor:
                                        m.user_color ||
                                        userColor(m.user_id ?? m.user),
                                    backgroundImage: msgImage(m)
                                        ? `url(${msgImage(m)})`
                                        : 'none',
                                }"
                            >
                                <span
                                    v-if="!msgImage(m)"
                                    class="text-black/70"
                                    >{{
                                        (msgName(m) || "?")
                                            .charAt(0)
                                            .toUpperCase()
                                    }}</span
                                >
                            </span>
                            <div
                                class="min-w-0 flex-1 text-[13px] leading-snug"
                            >
                                <div
                                    v-if="m.private"
                                    class="font-mono text-[10px] uppercase tracking-[0.08em] text-teal"
                                >
                                    🔒 whisper{{ m.to ? " → " + m.to : "" }}
                                </div>
                                <!-- Pending concentration-save prompt: shown to the owner + GM with a Roll button. -->
                                <template v-if="m.roll && m.roll.pending">
                                    <div
                                        class="mt-0.5 rounded-md border border-teal/40 bg-[#15252a]/60 px-3 py-2 text-[12px]"
                                    >
                                        <div
                                            class="flex flex-wrap items-center gap-x-2"
                                        >
                                            <span class="text-teal"
                                                >Concentration check</span
                                            >
                                            <span class="text-muted"
                                                >DC {{ m.roll.dc }} to hold
                                                <span class="text-ink">{{
                                                    m.roll.spell
                                                }}</span></span
                                            >
                                        </div>
                                        <div
                                            class="mt-1.5 flex items-center gap-1"
                                        >
                                            <button
                                                type="button"
                                                class="rounded border border-edge3 px-2 py-0.5 text-xs text-muted hover:border-red-400/60 hover:text-red-300"
                                                title="Roll with disadvantage"
                                                @click="
                                                    rollConcentration(
                                                        m,
                                                        'disadvantage',
                                                    )
                                                "
                                            >
                                                Dis
                                            </button>
                                            <button
                                                type="button"
                                                class="flex-1 rounded bg-amber/80 px-2 py-0.5 text-xs text-black hover:bg-amber"
                                                @click="rollConcentration(m)"
                                            >
                                                Roll CON
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded border border-edge3 px-2 py-0.5 text-xs text-muted hover:border-[#5ea564]/60 hover:text-[#9dc47a]"
                                                title="Roll with advantage"
                                                @click="
                                                    rollConcentration(
                                                        m,
                                                        'advantage',
                                                    )
                                                "
                                            >
                                                Adv
                                            </button>
                                        </div>
                                    </div>
                                </template>
                                <template v-else-if="m.roll">
                                    <span
                                        class="font-semibold"
                                        :style="{
                                            color:
                                                m.user_color ||
                                                userColor(m.user_id ?? m.user),
                                        }"
                                        >{{ msgName(m) }}</span
                                    >
                                    <div
                                        class="mt-0.5 rounded-lg border-2 border-white/70 bg-black px-3 py-2.5 text-center shadow-[inset_0_0_0_2px_#000,inset_0_0_0_3px_rgba(255,255,255,0.35)]"
                                    >
                                        <div
                                            class="font-display text-3xl leading-none"
                                            :class="
                                                m.roll.d20 === 20
                                                    ? 'text-[#5ea564]'
                                                    : m.roll.d20 === 1
                                                      ? 'text-red-500'
                                                      : 'text-amber'
                                            "
                                        >
                                            {{ m.roll.total }}
                                        </div>
                                        <div
                                            class="mt-1 text-[13px] font-semibold text-white"
                                        >
                                            {{ m.roll.expr }}
                                        </div>
                                        <div
                                            class="font-mono text-[10px] text-white/45"
                                        >
                                            {{ m.roll.detail }}
                                        </div>
                                        <div
                                            v-if="m.roll.mode"
                                            class="mt-0.5 font-mono text-[10px]"
                                        >
                                            <span
                                                class="uppercase text-amber/80"
                                                >{{ m.roll.mode }}</span
                                            >
                                            <span
                                                v-if="
                                                    m.roll.dropped &&
                                                    m.roll.dropped.length
                                                "
                                                class="ml-1 text-white/40 line-through"
                                                >dropped
                                                {{
                                                    m.roll.dropped.join(", ")
                                                }}</span
                                            >
                                        </div>
                                    </div>
                                </template>
                                <template v-else>
                                    <span
                                        class="font-semibold"
                                        :style="{
                                            color:
                                                m.user_color ||
                                                userColor(m.user_id ?? m.user),
                                        }"
                                        >{{ m.user }}:</span
                                    >
                                    <span class="ml-1 text-muted">{{
                                        m.body
                                    }}</span>
                                </template>
                            </div>
                        </div>
                        <p
                            v-if="!messages.length"
                            class="text-[13px] text-faint"
                        >
                            No messages yet. Try
                            <code class="rounded bg-raised px-1 text-ink"
                                >/roll 1d20+5</code
                            >,
                            <code class="rounded bg-raised px-1 text-ink"
                                >/gmroll 2d6</code
                            >, or
                            <code class="rounded bg-raised px-1 text-ink"
                                >/w gm …</code
                            >.
                        </p>
                    </div>
                    <form
                        class="shrink-0 space-y-1.5 border-t border-edge2 p-2"
                        @submit.prevent="sendChat"
                    >
                        <select
                            v-model="chatTarget"
                            class="field !py-1.5 text-xs"
                            title="Who receives this message"
                        >
                            <option
                                v-for="t in chatTargets"
                                :key="t.value"
                                :value="t.value"
                            >
                                {{
                                    t.value === "all"
                                        ? "Message everyone"
                                        : `Message ${t.label}`
                                }}
                            </option>
                        </select>
                        <input
                            v-model="chatInput"
                            class="field !py-2 text-sm"
                            :placeholder="
                                chatTarget === 'all'
                                    ? 'Message, or /roll 2d6+3…'
                                    : 'Private message…'
                            "
                            :disabled="chatForm.sending"
                        />
                    </form>
                </div>

                <!-- Tracker -->
                <div
                    v-else-if="panelTab === 'tracker'"
                    class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto p-4"
                >
                    <div class="flex items-center justify-between">
                        <div class="font-display text-lg text-bright">
                            Tracker
                        </div>
                        <div v-if="isGm && tracked.length" class="flex gap-1">
                            <button
                                class="rounded border border-edge3 px-2 py-1 text-xs text-muted hover:text-ink"
                                @click="resetTurn"
                            >
                                Reset
                            </button>
                            <button
                                class="btn-primary !px-3 !py-1 !text-xs"
                                @click="nextTurn"
                            >
                                Next ›
                            </button>
                        </div>
                    </div>
                    <p v-if="!tracked.length" class="text-[13px] text-faint">
                        Nobody on the tracker yet.
                        <button
                            v-if="isGm"
                            class="text-amber hover:underline"
                            @click="showTracker = true"
                        >
                            Open the tracker
                        </button>
                    </p>
                    <div
                        v-for="t in tracked"
                        :key="'i' + t.id"
                        class="flex cursor-pointer items-center gap-2 rounded-md border px-2.5 py-1.5"
                        :class="
                            t.id === room.active_token_id
                                ? 'border-amber/60 bg-amber/10'
                                : 'border-edge2 bg-[#1a1d24]'
                        "
                        @click="openDetail(t)"
                    >
                        <span
                            class="w-6 text-center font-mono text-sm text-amber"
                            >{{ t.initiative ?? "–" }}</span
                        >
                        <span
                            class="h-6 w-6 shrink-0 overflow-hidden rounded-full bg-cover bg-center"
                            :style="{
                                backgroundColor: t.color || '#d8a94a',
                                backgroundImage: t.image_url
                                    ? `url(${t.image_url})`
                                    : 'none',
                            }"
                        ></span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-[13.5px] text-ink">
                                {{ t.label || "Token" }}
                            </div>
                            <div
                                v-if="t.conditions.length"
                                class="mt-0.5 flex flex-wrap gap-0.5"
                            >
                                <span
                                    v-for="c in t.conditions"
                                    :key="c"
                                    :title="condLabel(c)"
                                    class="flex h-4 w-4 items-center justify-center rounded-full bg-[#f3efe6] ring-1 ring-black/20"
                                >
                                    <img
                                        v-if="condHasIcon(c)"
                                        :src="condIcon(c)"
                                        :alt="c"
                                        class="h-3 w-3"
                                    />
                                    <span
                                        v-else
                                        class="font-mono text-[7px] text-black/70"
                                        >{{ condAbbr(c) }}</span
                                    >
                                </span>
                            </div>
                        </div>
                        <span
                            v-if="isGm && t.max_hp"
                            class="shrink-0 font-mono text-[11px] text-faint"
                            >{{ t.hp ?? "?" }}/{{ t.max_hp }}</span
                        >
                    </div>
                </div>

                <!-- GM settings (fog / grid / players) -->
                <div
                    v-else-if="panelTab === 'gm' && isGm"
                    class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4"
                >
                    <div
                        class="flex items-center justify-between rounded-md border border-edge2 bg-night px-3 py-2"
                    >
                        <span class="font-mono text-[11px] text-faint"
                            >Join code
                            <b class="text-ink">{{ room.code }}</b></span
                        >
                        <Link
                            :href="route('rooms.index', campaign.id)"
                            class="text-xs text-muted hover:text-amber"
                            >← All rooms</Link
                        >
                    </div>

                    <!-- Scenes -->
                    <div>
                        <div class="mb-1.5 flex items-center justify-between">
                            <span class="eyebrow-muted">Scenes</span>
                            <button
                                type="button"
                                class="rounded border border-edge3 px-2 py-0.5 text-[11px] text-muted hover:text-ink"
                                @click="addScene"
                            >
                                + Add
                            </button>
                        </div>
                        <p class="mb-1.5 text-[11px] leading-snug text-faint">
                            Click a scene to preview &amp; prep it — players
                            stay on the live scene until you press Go live.
                        </p>
                        <div class="space-y-1">
                            <div
                                v-for="scene in room.scenes"
                                :key="scene.id"
                                class="flex items-center gap-1 rounded-md border px-2 py-1"
                                :class="
                                    scene.viewing
                                        ? 'border-amber/60 bg-amber/10'
                                        : 'border-edge2'
                                "
                            >
                                <button
                                    type="button"
                                    class="min-w-0 flex-1 truncate text-left text-[13px]"
                                    :class="
                                        scene.viewing
                                            ? 'text-amber'
                                            : 'text-ink'
                                    "
                                    title="Preview this scene"
                                    @click="previewScene(scene.id)"
                                >
                                    {{ scene.name }}
                                </button>
                                <span
                                    v-if="scene.active"
                                    class="rounded bg-[#5ea564]/20 px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-[0.08em] text-[#7bc47f]"
                                    title="Players see this scene"
                                    >Live</span
                                >
                                <button
                                    v-else
                                    type="button"
                                    class="rounded border border-edge3 px-1.5 py-0.5 text-[10px] text-muted hover:border-amber/60 hover:text-amber"
                                    title="Make this the players' scene"
                                    @click="goLive(scene.id)"
                                >
                                    Go live
                                </button>
                                <button
                                    type="button"
                                    class="text-faint hover:text-ink"
                                    title="Rename"
                                    @click="renameScene(scene)"
                                >
                                    ✎
                                </button>
                                <button
                                    v-if="room.scenes.length > 1"
                                    type="button"
                                    class="text-faint hover:text-red-400"
                                    title="Delete scene"
                                    @click="deleteScene(scene.id)"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>

                        <!-- Import tokens into the viewed scene from another (keeps HP/conditions/etc.) -->
                        <div
                            v-if="room.scenes.length > 1"
                            class="mt-2 flex items-center gap-1"
                        >
                            <select
                                v-model="importFromScene"
                                class="field !w-auto flex-1 !py-1 text-xs"
                            >
                                <option :value="null">
                                    Import tokens from…
                                </option>
                                <option
                                    v-for="scene in room.scenes.filter(
                                        (s) => !s.viewing,
                                    )"
                                    :key="scene.id"
                                    :value="scene.id"
                                >
                                    {{ scene.name }}
                                </option>
                            </select>
                            <button
                                type="button"
                                class="rounded border border-edge3 px-2 py-1 text-xs text-muted hover:text-ink disabled:opacity-40"
                                :disabled="!importFromScene"
                                title="Copy all tokens (with their stats) into this scene"
                                @click="importSceneTokens"
                            >
                                Import
                            </button>
                        </div>
                    </div>

                    <label
                        class="flex items-center justify-between text-sm text-muted"
                    >
                        Grid
                        <button
                            type="button"
                            class="rounded-full px-3 py-1 font-mono text-[10px] uppercase tracking-[0.1em]"
                            :class="
                                room.grid_visible
                                    ? 'border border-amber/60 bg-amber/10 text-amber'
                                    : 'border border-edge3 text-faint'
                            "
                            @click="
                                saveRoom({ grid_visible: !room.grid_visible })
                            "
                        >
                            {{ room.grid_visible ? "On" : "Off" }}
                        </button>
                    </label>

                    <label
                        class="flex items-center justify-between text-sm text-muted"
                    >
                        Players see tracker
                        <button
                            type="button"
                            class="rounded-full px-3 py-1 font-mono text-[10px] uppercase tracking-[0.1em]"
                            :class="
                                room.players_see_tracker
                                    ? 'border border-amber/60 bg-amber/10 text-amber'
                                    : 'border border-edge3 text-faint'
                            "
                            @click="
                                saveRoom({
                                    players_see_tracker:
                                        !room.players_see_tracker,
                                })
                            "
                        >
                            {{ room.players_see_tracker ? "On" : "Off" }}
                        </button>
                    </label>

                    <label
                        class="flex items-center justify-between text-sm text-muted"
                    >
                        Camera / Mic
                        <button
                            type="button"
                            class="rounded-full px-3 py-1 font-mono text-[10px] uppercase tracking-[0.1em]"
                            :class="
                                room.voice_enabled
                                    ? 'border border-amber/60 bg-amber/10 text-amber'
                                    : 'border border-edge3 text-faint'
                            "
                            @click="
                                saveRoom({ voice_enabled: !room.voice_enabled })
                            "
                        >
                            {{ room.voice_enabled ? "On" : "Off" }}
                        </button>
                    </label>

                    <div class="flex flex-col gap-2">
                        <label
                            class="flex items-center justify-between text-sm text-muted"
                        >
                            Fog of war
                            <button
                                type="button"
                                class="rounded-full px-3 py-1 font-mono text-[10px] uppercase tracking-[0.1em]"
                                :class="
                                    room.fog_enabled
                                        ? 'border border-amber/60 bg-amber/10 text-amber'
                                        : 'border border-edge3 text-faint'
                                "
                                @click="
                                    saveRoom({ fog_enabled: !room.fog_enabled })
                                "
                            >
                                {{ room.fog_enabled ? "On" : "Off" }}
                            </button>
                        </label>
                        <div
                            v-if="room.fog_enabled"
                            class="flex flex-col gap-2"
                        >
                            <div class="flex gap-1.5">
                                <button
                                    type="button"
                                    class="flex-1 rounded-md border px-2 py-1.5 text-sm"
                                    :class="
                                        fogTool === 'reveal'
                                            ? 'border-teal bg-teal/10 text-teal'
                                            : 'border-edge3 text-muted hover:text-ink'
                                    "
                                    @click="
                                        fogTool =
                                            fogTool === 'reveal'
                                                ? null
                                                : 'reveal'
                                    "
                                >
                                    Reveal
                                </button>
                                <button
                                    type="button"
                                    class="flex-1 rounded-md border px-2 py-1.5 text-sm"
                                    :class="
                                        fogTool === 'cover'
                                            ? 'border-amber bg-amber/10 text-amber'
                                            : 'border-edge3 text-muted hover:text-ink'
                                    "
                                    @click="
                                        fogTool =
                                            fogTool === 'cover' ? null : 'cover'
                                    "
                                >
                                    Cover
                                </button>
                            </div>
                            <div class="flex gap-1.5">
                                <button
                                    type="button"
                                    class="flex-1 rounded-md border border-edge3 px-2 py-1.5 text-xs text-muted hover:text-ink"
                                    @click="revealAll"
                                >
                                    Reveal all
                                </button>
                                <button
                                    type="button"
                                    class="flex-1 rounded-md border border-edge3 px-2 py-1.5 text-xs text-muted hover:text-ink"
                                    @click="coverAll"
                                >
                                    Cover all
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2 border-t border-edge2 pt-4">
                        <div class="eyebrow-muted">
                            Players ({{ room.members.length }})
                        </div>
                        <div
                            v-for="m in room.members"
                            :key="'pm' + m.id"
                            class="flex items-center gap-2 text-[13px]"
                        >
                            <span
                                class="h-2 w-2 shrink-0 rounded-full"
                                :class="
                                    isOnline(m.id) ? 'bg-green-500' : 'bg-edge3'
                                "
                                :title="isOnline(m.id) ? 'Online' : 'Offline'"
                            ></span>
                            <span class="min-w-0 flex-1 truncate text-ink">{{
                                m.name
                            }}</span>
                            <button
                                class="text-faint hover:text-red-400"
                                title="Remove from room"
                                @click="kick(m.id)"
                            >
                                ✕
                            </button>
                        </div>
                        <p
                            v-if="!room.members.length"
                            class="text-[12px] text-faint"
                        >
                            No players yet. Share the join code
                            <b class="text-ink">{{ room.code }}</b
                            >.
                        </p>
                    </div>
                </div>

                <!-- Player settings -->
                <div
                    v-else-if="panelTab === 'settings'"
                    class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4"
                >
                    <div>
                        <div class="eyebrow-muted mb-1">Playing as</div>
                        <div class="text-sm text-ink">{{ me.display }}</div>
                    </div>

                    <div>
                        <div class="eyebrow-muted mb-1.5">Camera size</div>
                        <div class="flex gap-1.5">
                            <button
                                v-for="size in ['small', 'medium', 'large']"
                                :key="size"
                                class="flex-1 rounded-md border px-2 py-1.5 text-sm capitalize"
                                :class="
                                    camSize === size
                                        ? 'border-amber bg-amber/10 text-amber'
                                        : 'border-edge3 text-muted hover:text-ink'
                                "
                                @click="camSize = size"
                            >
                                {{ size }}
                            </button>
                        </div>
                    </div>

                    <a
                        :href="route('dashboard')"
                        class="w-fit rounded-md border border-edge3 px-3 py-1.5 text-sm text-muted hover:text-ink"
                        >Leave room</a
                    >
                    <p class="text-[12px] text-faint">
                        Tokens snap to the grid when you move them.
                    </p>
                </div>

                <!-- Journal (private session notes) -->
                <div
                    v-else-if="panelTab === 'handouts'"
                    class="flex min-h-0 flex-1 flex-col overflow-hidden"
                >
                    <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
                        <div
                            v-for="h in handouts"
                            :key="h.id"
                            class="overflow-hidden rounded-md border border-edge2 bg-[#1a1d24]"
                        >
                            <button
                                v-if="h.image_url"
                                type="button"
                                class="block w-full"
                                @click="handoutImage = h.image_url"
                            >
                                <img
                                    :src="h.image_url"
                                    :alt="h.title || 'Handout'"
                                    class="max-h-48 w-full object-cover"
                                />
                            </button>
                            <div class="p-2.5">
                                <div
                                    v-if="h.title"
                                    class="font-display text-sm text-bright"
                                >
                                    {{ h.title }}
                                </div>
                                <div
                                    v-if="h.body"
                                    class="mt-0.5 whitespace-pre-line text-[13px] text-ink"
                                >
                                    {{ h.body }}
                                </div>
                                <div
                                    class="mt-1 flex items-center justify-between font-mono text-[10px] text-faint"
                                >
                                    <span>{{ h.at }}</span>
                                    <button
                                        v-if="h.can_manage"
                                        class="hover:text-red-400"
                                        @click="removeHandout(h.id)"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p
                            v-if="!handouts.length"
                            class="text-[13px] text-faint"
                        >
                            {{
                                isGm
                                    ? "Share an image or note with the table — it appears here for every player."
                                    : "The GM hasn't shared any handouts yet."
                            }}
                        </p>
                    </div>
                    <form
                        v-if="isGm"
                        class="shrink-0 space-y-1.5 border-t border-edge2 p-2"
                        @submit.prevent="addHandout"
                    >
                        <input
                            v-model="handoutForm.title"
                            class="field !py-2 text-sm"
                            placeholder="Title (optional)"
                        />
                        <textarea
                            v-model="handoutForm.body"
                            rows="2"
                            class="field !py-2 text-sm"
                            placeholder="Note (optional)"
                        ></textarea>
                        <div class="flex items-center gap-2">
                            <span
                                v-if="handoutImageUrl"
                                class="h-8 w-8 shrink-0 rounded bg-cover bg-center ring-1 ring-edge3"
                                :style="{
                                    backgroundImage: `url(${handoutImageUrl})`,
                                }"
                            ></span>
                            <button
                                type="button"
                                class="rounded border border-edge3 px-2 py-1 text-xs text-muted hover:text-ink"
                                @click="openHandoutPicker"
                            >
                                {{
                                    handoutImageUrl
                                        ? "Change image"
                                        : "Add image"
                                }}
                            </button>
                            <button
                                v-if="handoutImageUrl"
                                type="button"
                                class="text-xs text-faint hover:text-red-400"
                                @click="clearHandoutImage"
                            >
                                Remove
                            </button>
                            <button
                                type="submit"
                                class="btn-primary ml-auto !py-1.5 !text-xs"
                                :disabled="
                                    handoutForm.processing ||
                                    (!handoutForm.body.trim() &&
                                        !handoutForm.image_media_id)
                                "
                            >
                                Share
                            </button>
                        </div>
                    </form>
                </div>

                <div
                    v-else-if="panelTab === 'journal'"
                    class="flex min-h-0 flex-1 flex-col overflow-hidden"
                >
                    <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
                        <div
                            v-for="n in journal"
                            :key="n.id"
                            class="rounded-md border border-edge2 bg-[#1a1d24] p-2.5"
                        >
                            <div
                                class="whitespace-pre-line text-[13px] text-ink"
                            >
                                {{ n.body }}
                            </div>
                            <div
                                class="mt-1 flex items-center justify-between font-mono text-[10px] text-faint"
                            >
                                <span>{{ n.at }}</span>
                                <button
                                    class="hover:text-red-400"
                                    @click="removeJournal(n.id)"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                        <p
                            v-if="!journal.length"
                            class="text-[13px] text-faint"
                        >
                            Your private session journal — only you can see
                            these notes.
                        </p>
                    </div>
                    <form
                        class="shrink-0 border-t border-edge2 p-2"
                        @submit.prevent="addJournal"
                    >
                        <textarea
                            v-model="journalForm.body"
                            rows="2"
                            class="field !py-2 text-sm"
                            placeholder="Add a note…"
                        ></textarea>
                        <div class="mt-1 flex justify-end">
                            <button
                                type="submit"
                                class="btn-primary !py-1.5 !text-xs"
                                :disabled="
                                    journalForm.processing ||
                                    !journalForm.body.trim()
                                "
                            >
                                Add note
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Add token / search modal -->
        <div
            v-if="showAdd"
            class="fixed inset-0 z-40 flex items-start justify-center bg-black/60 p-4 pt-[8vh]"
            @click.self="showAdd = false"
        >
            <div
                class="flex max-h-[84vh] w-full max-w-2xl flex-col rounded-xl border border-edge2 bg-surface shadow-2xl"
            >
                <div
                    class="flex items-center justify-between border-b border-edge2 p-4"
                >
                    <div class="font-display text-lg text-bright">
                        Add token
                    </div>
                    <button
                        class="text-faint hover:text-ink"
                        @click="showAdd = false"
                    >
                        ✕
                    </button>
                </div>

                <!-- Party roster quick-add (GM: everyone; player: their own) -->
                <div v-if="characters.length" class="border-b border-edge2 p-4">
                    <div
                        class="mb-2 font-mono text-[10px] uppercase tracking-[0.08em] text-faint"
                    >
                        Characters
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="c in characters"
                            :key="'ch' + c.id"
                            class="flex items-center gap-2 rounded-full border border-edge3 py-1 pl-1 pr-3 text-sm text-ink hover:border-amber"
                            @click="addCharacter(c.id)"
                        >
                            <span
                                class="h-6 w-6 shrink-0 overflow-hidden rounded-full bg-cover bg-center bg-raised"
                                :style="{
                                    backgroundImage: c.image_url
                                        ? `url(${c.image_url})`
                                        : 'none',
                                }"
                            ></span>
                            {{ c.name }}
                            <span class="text-amber">+</span>
                        </button>
                    </div>
                </div>

                <!-- GM: full compendium search -->
                <template v-if="isGm">
                    <div class="flex flex-col gap-3 border-b border-edge2 p-4">
                        <div
                            v-if="search.kind === 'monster'"
                            class="flex flex-wrap gap-1.5"
                        >
                            <button
                                v-for="s in [
                                    ['all', 'All'],
                                    ['core', '5e Core Rules'],
                                    ['worldbuilder', 'Worldbuilder'],
                                    ['ddb', 'D&D Beyond'],
                                ]"
                                :key="s[0]"
                                class="rounded-full border px-3 py-1 text-sm"
                                :class="
                                    search.source === s[0]
                                        ? 'border-amber bg-amber/10 text-amber'
                                        : 'border-edge3 text-muted hover:text-ink'
                                "
                                @click="search.source = s[0]"
                            >
                                {{ s[1] }}
                            </button>
                        </div>
                        <div
                            v-if="search.kind === 'monster'"
                            class="flex gap-2"
                        >
                            <input
                                v-model="search.min_cr"
                                type="number"
                                step="0.125"
                                class="field !py-2 text-sm"
                                placeholder="Min CR"
                            />
                            <input
                                v-model="search.max_cr"
                                type="number"
                                step="0.125"
                                class="field !py-2 text-sm"
                                placeholder="Max CR"
                            />
                        </div>
                        <label
                            class="flex items-center gap-2 text-sm text-muted"
                        >
                            <input
                                type="checkbox"
                                :checked="search.kind === 'person'"
                                @change="
                                    search.kind =
                                        search.kind === 'person'
                                            ? 'monster'
                                            : 'person'
                                "
                            />
                            People (search your world's characters instead)
                        </label>
                        <input
                            v-model="search.q"
                            class="field !py-2 text-sm"
                            :placeholder="
                                search.kind === 'person'
                                    ? 'Search people…'
                                    : 'Search monsters…'
                            "
                        />
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-2">
                        <p
                            v-if="search.loading"
                            class="p-4 text-center text-[13px] text-faint"
                        >
                            Searching…
                        </p>
                        <p
                            v-else-if="!search.results.length"
                            class="p-4 text-center text-[13px] text-faint"
                        >
                            No matches.
                        </p>
                        <div
                            v-for="r in search.results"
                            :key="r.type + r.id"
                            class="flex items-center gap-3 rounded-md px-2 py-1.5 hover:bg-night"
                        >
                            <span
                                class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-md bg-cover bg-center bg-raised font-display text-ink"
                                :style="{
                                    backgroundImage: r.image_url
                                        ? `url(${r.image_url})`
                                        : 'none',
                                }"
                            >
                                <span v-if="!r.image_url">{{
                                    (r.name || "?").charAt(0)
                                }}</span>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-ink">
                                    {{ r.name }}
                                </div>
                                <div class="font-mono text-[11px] text-faint">
                                    <span v-if="r.type === 'monster'"
                                        >CR {{ r.cr || "—" }} · </span
                                    >{{ r.source }}
                                </div>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <button
                                    class="rounded-md border border-edge3 px-2.5 py-1.5 text-muted hover:text-ink"
                                    title="Preview"
                                    @click="openPreview(r)"
                                >
                                    👁
                                </button>
                                <button
                                    class="rounded-md border border-edge3 px-2.5 py-1.5 text-muted hover:text-ink"
                                    title="Add to board"
                                    @click="addResult(r)"
                                >
                                    +
                                </button>
                            </div>
                        </div>
                    </div>

                    <details class="border-t border-edge2 p-4">
                        <summary class="cursor-pointer text-sm text-muted">
                            Import a D&D Beyond character
                        </summary>
                        <form
                            class="mt-2 flex flex-col gap-2"
                            @submit.prevent="importDdb"
                        >
                            <div class="flex items-center gap-2">
                                <input
                                    v-model="ddbForm.url"
                                    class="field !py-2 text-sm"
                                    placeholder="https://www.dndbeyond.com/characters/…"
                                />
                                <button
                                    class="btn-primary shrink-0"
                                    type="submit"
                                    :disabled="ddbForm.processing"
                                >
                                    Import
                                </button>
                            </div>
                            <label
                                class="flex items-center gap-2 text-xs text-faint"
                                >Link to player
                                <select
                                    v-model="ddbForm.owner_user_id"
                                    class="field !py-1.5 text-sm"
                                >
                                    <option :value="null">
                                        — GM-controlled —
                                    </option>
                                    <option
                                        v-for="p in room.members"
                                        :key="p.id"
                                        :value="p.id"
                                    >
                                        {{ p.name }}
                                    </option>
                                </select>
                            </label>
                        </form>
                        <p
                            v-if="ddbForm.errors.url"
                            class="mt-1 text-sm text-red-400"
                        >
                            {{ ddbForm.errors.url }}
                        </p>
                        <details
                            class="mt-3 rounded-md border border-edge2 p-2"
                        >
                            <summary class="cursor-pointer text-xs text-muted">
                                Let players add monsters (pets, wildshape)
                            </summary>
                            <div
                                class="mt-2 max-h-40 space-y-1 overflow-y-auto"
                            >
                                <label
                                    v-for="m in monsters"
                                    :key="'sl' + m.id"
                                    class="flex items-center gap-2 text-[13px] text-muted"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="shortlist.includes(m.id)"
                                        @change="toggleShortlist(m.id)"
                                    />
                                    {{ m.name }}
                                </label>
                                <p
                                    v-if="!monsters.length"
                                    class="text-[11px] text-faint"
                                >
                                    Import monsters into your compendium first.
                                </p>
                            </div>
                        </details>
                    </details>
                </template>

                <!-- Player: import own character + GM-allowed monsters -->
                <template v-else>
                    <div class="flex flex-col gap-4 p-4">
                        <div>
                            <div class="mb-1 text-sm text-muted">
                                Import your D&D Beyond character
                            </div>
                            <form
                                class="flex items-center gap-2"
                                @submit.prevent="importDdb"
                            >
                                <input
                                    v-model="ddbForm.url"
                                    class="field !py-2 text-sm"
                                    placeholder="https://www.dndbeyond.com/characters/…"
                                />
                                <button
                                    class="btn-primary shrink-0"
                                    type="submit"
                                    :disabled="ddbForm.processing"
                                >
                                    Import
                                </button>
                            </form>
                            <p
                                v-if="ddbForm.errors.url"
                                class="mt-1 text-sm text-red-400"
                            >
                                {{ ddbForm.errors.url }}
                            </p>
                        </div>
                        <div v-if="playerMonsters.length">
                            <div class="mb-1 text-sm text-muted">
                                Add a companion
                            </div>
                            <div class="space-y-1">
                                <button
                                    v-for="m in playerMonsters"
                                    :key="m.id"
                                    class="flex w-full items-center justify-between rounded-md border border-edge3 px-3 py-2 text-left text-[13.5px] text-ink hover:border-amber"
                                    @click="addShortlistMonster(m.id)"
                                >
                                    {{ m.name }}
                                    <span class="text-amber">+</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Preview (eye): a full, framed, two-column stat block -->
        <div
            v-if="preview"
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/70 p-4 py-[5vh]"
            @click.self="preview = null"
        >
            <div class="relative w-full max-w-4xl">
                <button
                    class="absolute -right-3 -top-3 z-10 flex h-9 w-9 items-center justify-center rounded-full border border-edge2 bg-surface text-ink shadow-lg hover:text-amber"
                    @click="preview = null"
                >
                    ✕
                </button>
                <StatblockCard
                    v-if="preview.block && preview.block.abilities"
                    :block="preview.block"
                    :name="preview.name"
                    :image="preview.image_url"
                    wide
                    framed
                />
                <div
                    v-else
                    class="rounded-md border-[3px] border-[#b89b3f] bg-[#fdf1dc] p-6 font-serif text-[#3a2c14] shadow-2xl"
                >
                    <h3 class="font-serif text-2xl text-[#7a200c]">
                        {{ preview.name }}
                    </h3>
                    <p v-if="preview.summary" class="mt-2 whitespace-pre-line">
                        {{ preview.summary }}
                    </p>
                    <p v-else class="mt-2 italic">
                        No stat block for this entry.
                    </p>
                </div>
            </div>
        </div>

        <!-- Token detail -->
        <div
            v-if="detail"
            class="no-scrollbar fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4 py-[5vh]"
            @click.self="detail = null"
        >
            <div
                class="w-full max-w-4xl rounded-xl border border-edge2 bg-surface p-5 shadow-2xl"
                @click.capture="onDetailClick"
            >
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span
                            class="h-12 w-12 shrink-0 overflow-hidden rounded-full bg-cover bg-center"
                            :style="{
                                backgroundColor: detail.color || '#d8a94a',
                                backgroundImage: detail.image_url
                                    ? `url(${detail.image_url})`
                                    : 'none',
                            }"
                        ></span>
                        <div>
                            <div class="font-display text-lg text-bright">
                                {{ detail.label || "Token" }}
                            </div>
                            <div class="font-mono text-[11px] text-faint">
                                <span v-if="detail.hp !== null"
                                    >HP {{ detail.hp ?? "?"
                                    }}<span v-if="detail.max_hp"
                                        >/{{ detail.max_hp }}</span
                                    ></span
                                >
                                <span v-if="detail.ac">
                                    · AC {{ detail.ac }}</span
                                >
                                <span v-if="detail.owner_name">
                                    · {{ detail.owner_name }}</span
                                >
                            </div>
                            <div
                                v-if="detail.conditions.length"
                                class="mt-1.5 flex flex-wrap gap-1"
                            >
                                <span
                                    v-for="c in detail.conditions"
                                    :key="c"
                                    :title="condLabel(c)"
                                    class="flex h-6 w-6 items-center justify-center rounded-full bg-[#f3efe6] ring-1 ring-black/20"
                                >
                                    <img
                                        v-if="condHasIcon(c)"
                                        :src="condIcon(c)"
                                        :alt="c"
                                        class="h-4 w-4"
                                    />
                                    <span
                                        v-else
                                        class="font-mono text-[9px] text-black/70"
                                        >{{ condAbbr(c) }}</span
                                    >
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            v-if="detail.character_id && detail.can_edit"
                            class="rounded border border-edge3 px-2.5 py-1 text-xs text-muted hover:text-ink disabled:opacity-50"
                            :disabled="refreshingCharacter"
                            title="Re-pull this character from D&D Beyond"
                            @click="refreshCharacter(detail.character_id)"
                        >
                            {{
                                refreshingCharacter
                                    ? "Refreshing…"
                                    : "⟳ Refresh"
                            }}
                        </button>
                        <button
                            v-if="detail.can_edit"
                            class="rounded border border-edge3 px-2.5 py-1 text-xs text-muted hover:text-ink"
                            @click="editToken(detail)"
                        >
                            Edit
                        </button>
                        <button
                            class="text-faint hover:text-ink"
                            @click="detail = null"
                        >
                            ✕
                        </button>
                    </div>
                </div>

                <p
                    v-if="showStatblock || detail.character"
                    class="mb-2 font-mono text-[10px] uppercase tracking-[0.08em] text-faint"
                >
                    {{
                        isGm
                            ? "Click a roll to log it privately · Ctrl to share · Shift/Alt = advantage/disadvantage"
                            : "Ctrl-click to send a roll to chat · Shift/Alt = advantage/disadvantage"
                    }}
                </p>
                <!-- Last roll for this creature, echoed in-window (mirrors the chat log) -->
                <div
                    v-if="lastRoll && lastRoll.roll"
                    class="mb-3 flex items-center gap-3 rounded-lg border-2 border-white/70 bg-black px-3 py-2 shadow-[inset_0_0_0_2px_#000,inset_0_0_0_3px_rgba(255,255,255,0.35)]"
                >
                    <div
                        class="font-display text-3xl leading-none"
                        :class="
                            lastRoll.roll.d20 === 20
                                ? 'text-[#5ea564]'
                                : lastRoll.roll.d20 === 1
                                  ? 'text-red-500'
                                  : 'text-amber'
                        "
                    >
                        {{ lastRoll.roll.total }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-[13px] font-semibold text-white">
                            {{ lastRoll.roll.expr }}
                        </div>
                        <div class="truncate font-mono text-[10px] text-white/45">
                            {{ lastRoll.roll.detail }}
                        </div>
                        <div
                            v-if="lastRoll.roll.mode"
                            class="font-mono text-[10px]"
                        >
                            <span class="uppercase text-amber/80">{{
                                lastRoll.roll.mode
                            }}</span>
                            <span
                                v-if="
                                    lastRoll.roll.dropped &&
                                    lastRoll.roll.dropped.length
                                "
                                class="ml-1 text-white/40 line-through"
                                >dropped
                                {{ lastRoll.roll.dropped.join(", ") }}</span
                            >
                        </div>
                    </div>
                    <button
                        class="shrink-0 text-white/40 hover:text-white"
                        title="Clear"
                        @click="lastRoll = null"
                    >
                        ✕
                    </button>
                </div>
                <StatblockCard
                    v-if="showStatblock"
                    :block="detail.statblock.block"
                    :name="detail.statblock.name"
                    :image="detail.statblock.image_url"
                    wide
                    framed
                />
                <CharacterSheet
                    v-else-if="detail.character"
                    :name="detail.label"
                    :image="detail.image_url"
                    :ac="detail.ac"
                    :hp="detail.hp"
                    :max-hp="detail.max_hp"
                    :temp-hp="detail.temp_hp || 0"
                    :death-success="detail.death_success || 0"
                    :death-fail="detail.death_fail || 0"
                    :exhaustion="detail.exhaustion || 0"
                    :concentrating-on="detail.concentrating_on || ''"
                    :slots-used="detail.spell_slots_used || {}"
                    :hit-dice-used="detail.hit_dice_used || {}"
                    :sheet-state="detail.sheet_state || {}"
                    :notes="detail.notes || ''"
                    :can-edit="detail.can_edit"
                    :character="detail.character"
                    @update="applyCombat"
                    @spend-hit-dice="spendHitDice"
                />
                <p
                    v-else-if="detail.person && detail.person.summary"
                    class="rounded-md border border-edge2 bg-night p-3 text-sm text-muted"
                >
                    {{ detail.person.summary }}
                </p>
                <div
                    v-else-if="detail.notes"
                    class="whitespace-pre-line rounded-md border border-edge2 bg-night p-3 text-sm text-muted"
                >
                    {{ detail.notes }}
                </div>
                <p v-else class="text-[13px] text-faint">
                    No stat block or notes for this token.
                </p>
            </div>
        </div>

        <!-- Edit Character modal -->
        <div
            v-if="selected"
            class="fixed inset-0 z-50 flex items-start justify-center bg-black/60 p-4 pt-[6vh]"
            @click.self="selected = null"
        >
            <div
                class="flex max-h-[88vh] w-full max-w-3xl flex-col rounded-xl border border-edge2 bg-surface shadow-2xl"
            >
                <div
                    class="flex items-center justify-between border-b border-edge2 p-4"
                >
                    <div class="font-display text-lg text-bright">
                        Edit character
                    </div>
                    <button
                        class="text-faint hover:text-ink"
                        @click="selected = null"
                    >
                        ✕
                    </button>
                </div>

                <div
                    class="grid min-h-0 flex-1 gap-5 overflow-y-auto p-5 md:grid-cols-[1.2fr_1fr]"
                >
                    <!-- Left: identity + vitals -->
                    <div class="flex flex-col gap-3">
                        <div class="flex gap-3">
                            <label
                                class="flex shrink-0 cursor-pointer flex-col items-center gap-1"
                            >
                                <span
                                    class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-full bg-cover bg-center ring-1 ring-edge3"
                                    :style="{
                                        backgroundColor:
                                            selected.color || '#d8a94a',
                                        backgroundImage: selected.image_url
                                            ? `url(${selected.image_url})`
                                            : 'none',
                                    }"
                                >
                                    <span
                                        v-if="!selected.image_url"
                                        class="font-display text-black/70"
                                        >{{ initial(selected) }}</span
                                    >
                                </span>
                                <span class="text-[10px] text-faint"
                                    >Portrait</span
                                >
                                <input
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="onPortrait($event, selected.id)"
                                />
                            </label>
                            <label
                                class="flex flex-1 flex-col gap-1 text-xs text-faint"
                                >Name
                                <input
                                    v-model="selected.label"
                                    class="field !py-2 text-sm"
                                    placeholder="Name"
                                />
                                <div class="mt-2 flex gap-2">
                                    <span
                                        v-if="!selected.image_url"
                                        class="flex-1"
                                        >Colour<input
                                            v-model="selected.color"
                                            type="color"
                                            class="mt-1 h-9 w-full rounded border border-edge3 bg-night"
                                    /></span>
                                    <span class="flex-1"
                                        >Size<input
                                            v-model.number="selected.size"
                                            type="number"
                                            min="0.5"
                                            step="0.5"
                                            class="field mt-1 !py-2 text-sm"
                                    /></span>
                                </div>
                            </label>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <label
                                class="flex flex-col gap-1 text-xs text-faint"
                                >Initiative
                                <input
                                    v-model="selected.initiative"
                                    type="number"
                                    class="field !py-2 text-sm"
                            /></label>
                            <label
                                class="flex flex-col gap-1 text-xs text-faint"
                                >AC
                                <input
                                    v-model="selected.ac"
                                    type="number"
                                    class="field !py-2 text-sm"
                            /></label>
                            <label
                                class="flex flex-col gap-1 text-xs text-faint"
                                >HP
                                <input
                                    v-model="selected.hp"
                                    type="number"
                                    class="field !py-2 text-sm"
                            /></label>
                            <label
                                class="flex flex-col gap-1 text-xs text-faint"
                                >Max HP
                                <input
                                    v-model="selected.max_hp"
                                    type="number"
                                    class="field !py-2 text-sm"
                            /></label>
                            <label
                                class="col-span-2 flex flex-col gap-1 text-xs text-faint"
                                >Elevation ({{ unitLabel }})
                                <input
                                    v-model="selected.elevation"
                                    type="number"
                                    class="field !py-2 text-sm"
                                    placeholder="0"
                            /></label>
                        </div>

                        <div>
                            <div
                                class="mb-1 text-center font-display text-sm text-muted"
                            >
                                Adjust health
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-md bg-red-800 px-4 py-2 font-display text-lg text-red-100 hover:bg-red-700"
                                    @click="adjustHp(-1)"
                                >
                                    −
                                </button>
                                <input
                                    v-model="healAmount"
                                    type="number"
                                    min="0"
                                    class="field !py-2 text-center text-sm"
                                />
                                <button
                                    type="button"
                                    class="rounded-md bg-green-800 px-4 py-2 font-display text-lg text-green-100 hover:bg-green-700"
                                    @click="adjustHp(1)"
                                >
                                    +
                                </button>
                            </div>
                        </div>

                        <label
                            v-if="isGm"
                            class="flex flex-col gap-1 text-xs text-faint"
                            >Stat block
                            <select
                                v-model="selected.compendium_item_id"
                                class="field !py-2 text-sm"
                            >
                                <option :value="null">— None —</option>
                                <option
                                    v-for="m in monsters"
                                    :key="m.id"
                                    :value="m.id"
                                >
                                    {{ m.name }}
                                </option>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-xs text-faint"
                            >Notes
                            <textarea
                                v-model="selected.notes"
                                rows="2"
                                class="field !py-2 text-sm"
                                placeholder="Player notes…"
                            ></textarea>
                        </label>
                        <label
                            class="flex items-center gap-2 text-sm text-muted"
                            ><input
                                v-model="selected.in_tracker"
                                type="checkbox"
                            />
                            On the tracker</label
                        >
                    </div>

                    <!-- Right: conditions -->
                    <div>
                        <div class="mb-2 font-display text-sm text-muted">
                            Conditions
                        </div>
                        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                            <button
                                v-for="c in CONDITIONS"
                                :key="c"
                                type="button"
                                class="flex flex-col items-center gap-1 rounded-md border p-2 transition"
                                :class="
                                    selected.conditions.includes(condKey(c))
                                        ? 'border-amber bg-amber/10'
                                        : 'border-edge3 hover:border-edge2'
                                "
                                @click="toggleCondition(condKey(c))"
                            >
                                <span
                                    class="flex h-11 w-11 items-center justify-center rounded-full bg-[#f3efe6] ring-1 ring-black/20"
                                    :class="
                                        selected.conditions.includes(condKey(c))
                                            ? ''
                                            : 'opacity-60'
                                    "
                                >
                                    <img
                                        v-if="condHasIcon(condKey(c))"
                                        :src="condIcon(condKey(c))"
                                        :alt="c"
                                        class="h-8 w-8"
                                    />
                                    <span
                                        v-else
                                        class="font-display text-lg text-black/70"
                                        >{{ condAbbr(condKey(c)) }}</span
                                    >
                                </span>
                                <span
                                    class="text-center text-[11px] leading-tight"
                                    :class="
                                        selected.conditions.includes(condKey(c))
                                            ? 'text-amber'
                                            : 'text-muted'
                                    "
                                    >{{ c }}</span
                                >
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    class="flex items-center justify-between border-t border-edge2 p-4"
                >
                    <button
                        class="text-sm text-faint hover:text-red-400"
                        @click="deleteToken"
                    >
                        Delete
                    </button>
                    <div class="flex gap-2">
                        <button
                            class="text-sm text-muted hover:text-ink"
                            @click="selected = null"
                        >
                            Cancel
                        </button>
                        <button class="btn-primary" @click="saveToken">
                            Save
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Replace map modal -->
        <div
            v-if="showMapPicker"
            class="fixed inset-0 z-40 flex items-start justify-center bg-black/60 p-4 pt-[7vh]"
            @click.self="showMapPicker = false"
        >
            <div
                class="flex max-h-[80vh] w-full max-w-3xl flex-col rounded-xl border border-edge2 bg-surface shadow-2xl"
            >
                <div
                    class="flex items-center justify-between border-b border-edge2 p-4"
                >
                    <div class="font-display text-lg text-bright">
                        {{ hasImage ? "Replace map" : "Choose a map" }}
                    </div>
                    <div class="flex items-center gap-2">
                        <label
                            class="cursor-pointer rounded-md border border-edge3 px-3 py-1.5 text-sm text-muted hover:text-ink"
                        >
                            {{
                                imageForm.processing ? "Uploading…" : "Upload…"
                            }}
                            <input
                                type="file"
                                accept="image/*"
                                class="hidden"
                                :disabled="imageForm.processing"
                                @change="onFile"
                            />
                        </label>
                        <button
                            class="text-faint hover:text-ink"
                            @click="showMapPicker = false"
                        >
                            ✕
                        </button>
                    </div>
                </div>

                <div class="border-b border-edge2 p-3">
                    <input
                        v-model="mapSearch"
                        type="search"
                        class="field !py-2 text-sm"
                        placeholder="Search media &amp; locations by name…"
                    />
                    <p
                        v-if="imageForm.errors.file"
                        class="mt-1 text-[12px] text-red-400"
                    >
                        {{ imageForm.errors.file }}
                    </p>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto p-4">
                    <p
                        v-if="mapPicker.loading && mapPicker.page === 1"
                        class="p-4 text-center text-[13px] text-faint"
                    >
                        Loading…
                    </p>
                    <template v-else>
                        <div v-if="mapPicker.locations.length" class="mb-5">
                            <div
                                class="mb-2 font-mono text-[10px] uppercase tracking-[0.08em] text-faint"
                            >
                                Location maps
                            </div>
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                <button
                                    v-for="loc in mapPicker.locations"
                                    :key="'loc' + loc.image_media_id"
                                    class="overflow-hidden rounded-md border border-edge3 text-left hover:border-amber"
                                    @click="chooseRoomImage(loc.image_media_id)"
                                >
                                    <img
                                        :src="loc.image_url"
                                        :alt="loc.name"
                                        class="h-24 w-full object-cover"
                                    />
                                    <div
                                        class="truncate px-2 py-1 text-[13px] text-ink"
                                    >
                                        {{ loc.name }}
                                    </div>
                                </button>
                            </div>
                        </div>

                        <div
                            class="mb-2 font-mono text-[10px] uppercase tracking-[0.08em] text-faint"
                        >
                            Media library
                        </div>
                        <div
                            v-if="mapPicker.media.length"
                            class="grid grid-cols-3 gap-3 sm:grid-cols-4"
                        >
                            <button
                                v-for="m in mapPicker.media"
                                :key="'md' + m.id"
                                class="overflow-hidden rounded-md border border-edge3 hover:border-amber"
                                :title="m.filename"
                                @click="chooseRoomImage(m.id)"
                            >
                                <img
                                    :src="m.url"
                                    :alt="m.filename"
                                    class="h-20 w-full object-cover"
                                />
                            </button>
                        </div>
                        <p
                            v-else-if="!mapPicker.locations.length"
                            class="text-[13px] text-faint"
                        >
                            No images in this world's library yet — upload one
                            above.
                        </p>

                        <div v-if="mapPicker.hasMore" class="mt-4 text-center">
                            <button
                                class="rounded-md border border-edge3 px-4 py-1.5 text-sm text-muted hover:text-ink"
                                :disabled="mapPicker.loading"
                                @click="loadMapPage(mapPicker.page + 1)"
                            >
                                {{
                                    mapPicker.loading ? "Loading…" : "Load more"
                                }}
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Handout image picker (GM) -->
        <div
            v-if="showHandoutPicker"
            class="fixed inset-0 z-40 flex items-start justify-center bg-black/60 p-4 pt-[7vh]"
            @click.self="showHandoutPicker = false"
        >
            <div
                class="flex max-h-[80vh] w-full max-w-3xl flex-col rounded-xl border border-edge2 bg-surface shadow-2xl"
            >
                <div
                    class="flex items-center justify-between border-b border-edge2 p-4"
                >
                    <div class="font-display text-lg text-bright">
                        Choose a handout image
                    </div>
                    <button
                        class="text-faint hover:text-ink"
                        @click="showHandoutPicker = false"
                    >
                        ✕
                    </button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-4">
                    <p
                        v-if="mapPicker.loading && mapPicker.page === 1"
                        class="p-4 text-center text-[13px] text-faint"
                    >
                        Loading…
                    </p>
                    <template v-else>
                        <div
                            v-if="mapPicker.media.length"
                            class="grid grid-cols-3 gap-3 sm:grid-cols-4"
                        >
                            <button
                                v-for="m in mapPicker.media"
                                :key="'ho' + m.id"
                                class="overflow-hidden rounded-md border border-edge3 hover:border-amber"
                                :title="m.filename"
                                @click="chooseHandoutImage(m)"
                            >
                                <img
                                    :src="m.url"
                                    :alt="m.filename"
                                    class="h-20 w-full object-cover"
                                />
                            </button>
                        </div>
                        <p v-else class="text-[13px] text-faint">
                            No images in this world's library yet.
                        </p>
                        <div v-if="mapPicker.hasMore" class="mt-4 text-center">
                            <button
                                class="rounded-md border border-edge3 px-4 py-1.5 text-sm text-muted hover:text-ink"
                                :disabled="mapPicker.loading"
                                @click="loadMapPage(mapPicker.page + 1)"
                            >
                                {{
                                    mapPicker.loading ? "Loading…" : "Load more"
                                }}
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Handout lightbox -->
        <div
            v-if="handoutImage"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-6"
            @click="handoutImage = null"
        >
            <img
                :src="handoutImage"
                alt="Handout"
                class="max-h-full max-w-full rounded-lg shadow-2xl"
            />
        </div>

        <!-- Tracker modal -->
        <div
            v-if="showTracker"
            class="fixed inset-0 z-40 flex items-center justify-center bg-black/60 p-4"
            @click.self="showTracker = false"
        >
            <div
                class="flex max-h-[85vh] w-full max-w-4xl flex-col rounded-xl border border-edge2 bg-surface shadow-2xl"
            >
                <div
                    class="flex items-center justify-between border-b border-edge2 p-4"
                >
                    <div class="flex items-center gap-3">
                        <div class="font-display text-lg text-bright">
                            Initiative tracker
                        </div>
                        <span
                            class="rounded-full border border-amber/50 bg-amber/10 px-3 py-1 font-mono text-[11px] uppercase tracking-[0.1em] text-amber"
                            >Round {{ room.round }}</span
                        >
                    </div>
                    <button
                        class="text-faint hover:text-ink"
                        @click="showTracker = false"
                    >
                        ✕
                    </button>
                </div>

                <div
                    v-if="isGm"
                    class="flex flex-wrap items-center gap-2 border-b border-edge2 p-3"
                >
                    <button
                        class="rounded-md border border-edge3 px-3 py-1.5 text-sm text-muted hover:text-ink"
                        @click="trackerAction('add_all')"
                    >
                        Add all tokens
                    </button>
                    <button
                        class="rounded-md border border-edge3 px-3 py-1.5 text-sm text-muted hover:text-ink"
                        @click="trackerAction('roll_all')"
                    >
                        Roll all
                    </button>
                    <button
                        class="rounded-md border border-edge3 px-3 py-1.5 text-sm text-muted hover:text-ink"
                        @click="trackerAction('clear')"
                    >
                        Clear
                    </button>
                    <div class="ml-auto flex gap-1">
                        <button
                            class="rounded border border-edge3 px-2 py-1 text-xs text-muted hover:text-ink"
                            @click="resetTurn"
                        >
                            Reset
                        </button>
                        <button
                            class="btn-primary !px-3 !py-1 !text-xs"
                            @click="nextTurn"
                        >
                            Next ›
                        </button>
                    </div>
                </div>

                <!-- Player view: a clean, read-only initiative order -->
                <div
                    v-if="!isGm"
                    class="min-h-0 flex-1 space-y-2 overflow-y-auto p-4"
                >
                    <div
                        v-for="t in tracked"
                        :key="'ptk' + t.id"
                        class="flex items-center gap-3 rounded-lg border px-3 py-2.5"
                        :class="
                            t.id === room.active_token_id
                                ? 'border-amber/60 bg-amber/10'
                                : 'border-edge2 bg-[#1a1d24]'
                        "
                    >
                        <span
                            class="w-8 text-center font-display text-xl text-amber"
                            >{{ t.initiative ?? "–" }}</span
                        >
                        <span
                            class="h-9 w-9 shrink-0 overflow-hidden rounded-full bg-cover bg-center ring-1 ring-edge2"
                            :style="{
                                backgroundColor: t.color || '#d8a94a',
                                backgroundImage: t.image_url
                                    ? `url(${t.image_url})`
                                    : 'none',
                            }"
                        ></span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-[14px] text-ink">
                                {{ t.label || "Token" }}
                            </div>
                            <div
                                v-if="t.conditions.length"
                                class="mt-1 flex flex-wrap gap-1"
                            >
                                <span
                                    v-for="c in t.conditions"
                                    :key="c"
                                    :title="condLabel(c)"
                                    class="flex h-5 w-5 items-center justify-center rounded-full bg-[#f3efe6] ring-1 ring-black/20"
                                >
                                    <img
                                        v-if="condHasIcon(c)"
                                        :src="condIcon(c)"
                                        :alt="c"
                                        class="h-3.5 w-3.5"
                                    />
                                    <span
                                        v-else
                                        class="font-mono text-[8px] text-black/70"
                                        >{{ condAbbr(c) }}</span
                                    >
                                </span>
                            </div>
                        </div>
                    </div>
                    <p
                        v-if="!tracked.length"
                        class="py-6 text-center text-[13px] text-faint"
                    >
                        Initiative hasn't been rolled yet.
                    </p>
                </div>

                <!-- GM view: full editable table -->
                <div v-else class="min-h-0 flex-1 overflow-y-auto p-3">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="text-left font-mono text-[10px] uppercase tracking-[0.08em] text-faint"
                            >
                                <th class="w-16 px-1 py-1">Init</th>
                                <th class="px-1 py-1">Name</th>
                                <th class="w-36 px-1 py-1">HP</th>
                                <th class="w-16 px-1 py-1">AC</th>
                                <th class="w-10 px-1 py-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="t in tracked"
                                :key="'tk' + t.id"
                                class="border-t border-edge2"
                                :class="
                                    t.id === room.active_token_id
                                        ? 'bg-amber/10'
                                        : ''
                                "
                            >
                                <td class="px-1 py-1.5">
                                    <input
                                        :value="t.initiative"
                                        type="number"
                                        :disabled="!t.can_edit"
                                        class="field !w-16 !py-1 text-center text-sm"
                                        @change="
                                            setStat(
                                                t,
                                                'initiative',
                                                $event.target.value,
                                            )
                                        "
                                    />
                                </td>
                                <td class="px-1 py-1.5">
                                    <button
                                        class="flex items-center gap-2 text-left text-ink hover:text-amber"
                                        @click="openDetail(t)"
                                    >
                                        <span
                                            class="h-7 w-7 shrink-0 overflow-hidden rounded-full bg-cover bg-center"
                                            :style="{
                                                backgroundColor:
                                                    t.color || '#d8a94a',
                                                backgroundImage: t.image_url
                                                    ? `url(${t.image_url})`
                                                    : 'none',
                                            }"
                                        ></span>
                                        <span class="min-w-0">
                                            <span class="block truncate">{{
                                                t.label || "Token"
                                            }}</span>
                                            <span
                                                v-if="t.conditions.length"
                                                class="mt-0.5 flex flex-wrap gap-0.5"
                                            >
                                                <span
                                                    v-for="c in t.conditions"
                                                    :key="c"
                                                    :title="condLabel(c)"
                                                    class="flex h-4 w-4 items-center justify-center rounded-full bg-[#f3efe6] ring-1 ring-black/20"
                                                >
                                                    <img
                                                        v-if="condHasIcon(c)"
                                                        :src="condIcon(c)"
                                                        :alt="c"
                                                        class="h-3 w-3"
                                                    />
                                                    <span
                                                        v-else
                                                        class="font-mono text-[7px] text-black/70"
                                                        >{{ condAbbr(c) }}</span
                                                    >
                                                </span>
                                            </span>
                                        </span>
                                    </button>
                                </td>
                                <td class="px-1 py-1.5">
                                    <div class="flex items-center gap-1">
                                        <input
                                            :value="t.hp"
                                            type="number"
                                            :disabled="!t.can_edit"
                                            class="field !w-16 !py-1 text-center text-sm"
                                            @change="
                                                setStat(
                                                    t,
                                                    'hp',
                                                    $event.target.value,
                                                )
                                            "
                                        />
                                        <span class="text-faint">/</span>
                                        <input
                                            :value="t.max_hp"
                                            type="number"
                                            :disabled="!t.can_edit"
                                            class="field !w-16 !py-1 text-center text-sm"
                                            @change="
                                                setStat(
                                                    t,
                                                    'max_hp',
                                                    $event.target.value,
                                                )
                                            "
                                        />
                                    </div>
                                </td>
                                <td class="px-1 py-1.5">
                                    <input
                                        :value="t.ac"
                                        type="number"
                                        :disabled="!t.can_edit"
                                        class="field !w-16 !py-1 text-center text-sm"
                                        @change="
                                            setStat(
                                                t,
                                                'ac',
                                                $event.target.value,
                                            )
                                        "
                                    />
                                </td>
                                <td class="px-1 py-1.5 text-right">
                                    <button
                                        v-if="t.can_edit"
                                        class="rounded border border-edge3 px-2 py-1 text-xs text-muted hover:text-ink"
                                        title="Edit character"
                                        @click="editToken(t)"
                                    >
                                        ✎
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p
                        v-if="!tracked.length"
                        class="py-6 text-center text-[13px] text-faint"
                    >
                        The tracker is empty.
                        <span v-if="isGm"
                            >Use “Add all tokens” to pull everyone on the map
                            in.</span
                        >
                    </p>
                </div>
            </div>
        </div>

        <!-- Token context menu (GM): quick conditions + delete -->
        <div
            v-if="contextMenu.open"
            class="fixed inset-0 z-[60]"
            @click="closeContext"
            @contextmenu.prevent="closeContext"
        >
            <div
                class="absolute w-56 rounded-lg border border-edge2 bg-surface p-2 shadow-2xl"
                :style="{
                    left: contextMenu.x + 'px',
                    top: contextMenu.y + 'px',
                }"
                @click.stop
            >
                <div
                    class="mb-1 px-1 font-mono text-[10px] uppercase tracking-[0.08em] text-faint"
                >
                    Conditions
                </div>
                <div class="grid grid-cols-5 gap-1">
                    <button
                        v-for="c in CONDITIONS"
                        :key="c"
                        :title="c"
                        class="flex items-center justify-center rounded p-1"
                        :class="
                            (contextMenu.token?.conditions || []).includes(
                                condKey(c),
                            )
                                ? 'bg-amber/20 ring-1 ring-amber'
                                : 'hover:bg-night'
                        "
                        @click="contextToggleCondition(condKey(c))"
                    >
                        <span
                            class="flex h-6 w-6 items-center justify-center rounded-full bg-[#f3efe6]"
                        >
                            <img
                                v-if="condHasIcon(condKey(c))"
                                :src="condIcon(condKey(c))"
                                :alt="c"
                                class="h-4 w-4"
                            />
                            <span
                                v-else
                                class="font-mono text-[8px] text-black/70"
                                >{{ condAbbr(condKey(c)) }}</span
                            >
                        </span>
                    </button>
                </div>
                <div
                    class="mb-1 mt-2 px-1 font-mono text-[10px] uppercase tracking-[0.08em] text-faint"
                >
                    Elevation
                </div>
                <div class="flex items-center gap-1">
                    <button
                        class="rounded bg-night px-1.5 py-1 font-mono text-xs text-muted hover:text-ink"
                        @click="contextAdjustElevation(-10)"
                    >
                        −10
                    </button>
                    <button
                        class="rounded bg-night px-1.5 py-1 font-mono text-xs text-muted hover:text-ink"
                        @click="contextAdjustElevation(-5)"
                    >
                        −5
                    </button>
                    <span
                        class="flex-1 text-center font-mono text-xs text-ink"
                        >{{
                            contextMenu.token?.elevation
                                ? elevLabel(Number(contextMenu.token.elevation))
                                : `0 ${unitLabel}`
                        }}</span
                    >
                    <button
                        class="rounded bg-night px-1.5 py-1 font-mono text-xs text-muted hover:text-ink"
                        @click="contextAdjustElevation(5)"
                    >
                        +5
                    </button>
                    <button
                        class="rounded bg-night px-1.5 py-1 font-mono text-xs text-muted hover:text-ink"
                        @click="contextAdjustElevation(10)"
                    >
                        +10
                    </button>
                </div>
                <button
                    class="mt-2 flex w-full items-center justify-between rounded px-2 py-1.5 text-left text-sm text-muted hover:bg-night hover:text-ink"
                    @click="contextToggleConcentration"
                >
                    Concentration
                    <span
                        class="font-mono text-[10px]"
                        :class="
                            contextMenu.token?.concentrating_on
                                ? 'text-teal'
                                : 'text-faint'
                        "
                        >{{
                            contextMenu.token?.concentrating_on ? "on" : "off"
                        }}</span
                    >
                </button>
                <button
                    class="w-full rounded px-2 py-1.5 text-left text-sm text-muted hover:bg-night hover:text-ink"
                    @click="contextEdit"
                >
                    Edit token
                </button>
                <button
                    class="w-full rounded px-2 py-1.5 text-left text-sm text-muted hover:bg-night hover:text-ink"
                    @click="contextToggleLayer"
                >
                    {{
                        contextMenu.token?.layer === "gm"
                            ? "Move to Tokens layer (reveal)"
                            : "Move to GM layer (hide)"
                    }}
                </button>
                <button
                    class="w-full rounded px-2 py-1.5 text-left text-sm text-red-400 hover:bg-night"
                    @click="contextDelete"
                >
                    Delete token
                </button>
            </div>
        </div>
    </div>
</template>
