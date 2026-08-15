<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    world: Object,
    campaign: Object,
    calendars: { type: Array, default: () => [] },
    selected: { type: Object, default: null },
    grid: { type: Object, default: null },
});

/* ---- create ---- */
const createForm = useForm({ name: "" });
const createCalendar = () =>
    createForm.post(route("calendars.store", props.world.id), { onSuccess: () => createForm.reset() });

const selectCalendar = (id) =>
    router.get(route("calendars.index", props.world.id), { calendar: id }, { preserveState: false });

/* ---- structure editor (rebuilt from `selected` each load, so it resets when you switch calendars) ---- */
const editing = ref(false);

const buildStructure = () => {
    const selected = props.selected;
    if (!selected) return undefined;

    return useForm({
        name: selected.name,
        current_year: selected.current_year,
        months: (selected.months ?? []).map((m) => ({
            name: m.name,
            days: m.days,
            intercalary: Boolean(m.intercalary),
        })),
        weekdays: [...(selected.weekdays ?? [])],
        leap_rules: (selected.leap_rules ?? []).map((r) => ({
            name: r.name ?? "Leap Day",
            month: r.month ?? 0,
            add: r.add ?? 1,
            every: r.every ?? 4,
            offset: r.offset ?? 0,
            except_every: r.except_every ?? 0,
            unless_every: r.unless_every ?? 0,
        })),
        moons: (selected.moons ?? []).map((m) => ({
            name: m.name ?? "Moon",
            cycle: m.cycle ?? 28,
            offset: m.offset ?? 0,
            colour: m.colour ?? "#c9d4ff",
        })),
    });
};

const structure = buildStructure();
const weekdaysText = ref(props.selected ? (props.selected.weekdays ?? []).join(", ") : "");

const addMonth = () => structure.months.push({ name: "New month", days: 30, intercalary: false });
const removeMonth = (index) => structure.months.splice(index, 1);

const addLeapRule = () =>
    structure.leap_rules.push({ name: "Leap Day", month: 0, add: 1, every: 4, offset: 0, except_every: 0, unless_every: 0 });
const removeLeapRule = (index) => structure.leap_rules.splice(index, 1);

const addMoon = () => structure.moons.push({ name: "New Moon", cycle: 28, offset: 0, colour: "#c9d4ff" });
const removeMoon = (index) => structure.moons.splice(index, 1);

const saveStructure = () => {
    structure.weekdays = weekdaysText.value.split(",").map((s) => s.trim()).filter(Boolean);
    structure.put(route("calendars.update", props.selected.id), {
        preserveScroll: true,
        onSuccess: () => (editing.value = false),
    });
};

const deleteCalendar = () => {
    if (window.confirm(`Delete the “${props.selected.name}” calendar and its events?`))
        router.delete(route("calendars.destroy", props.selected.id));
};

/* ---- year navigation ---- */
const goYear = (delta) =>
    router.get(
        route("calendars.index", props.world.id),
        { calendar: props.selected.id, year: (props.grid?.year ?? 1) + delta },
        { preserveScroll: true, preserveState: false },
    );

/* ---- events ---- */
const eventFor = ref(null); // { month, day }
const eventTitle = ref("");
const openDay = (monthIndex, day) => {
    eventFor.value = { month: monthIndex, day };
    eventTitle.value = "";
};
const addEvent = () => {
    router.post(
        route("calendars.events.store", props.selected.id),
        { year: props.grid.year, month: eventFor.value.month, day: eventFor.value.day, title: eventTitle.value },
        { preserveScroll: true, onSuccess: () => (eventFor.value = null) },
    );
};
const removeEvent = (id) => router.delete(route("calendars.events.destroy", id), { preserveScroll: true });

const eventsOn = (month, day) => (month.events ?? []).filter((e) => e.day === day);
const allEvents = computed(() =>
    (props.grid?.months ?? []).flatMap((m) => (m.events ?? []).map((e) => ({ ...e, monthName: m.name }))),
);

const weekdayCount = computed(() => (props.grid?.weekdays ?? []).length);
const moons = computed(() => props.grid?.moons ?? []);

/* ---- moon phases (derived client-side from each day's absolute index) ---- */
// A moon is "new" when its cycle position is 0 and "full" at the cycle's midpoint. We surface only
// those two notable phases per day to keep the grid legible; the tooltip names the moon.
const notableMoons = (absoluteDay) => {
    const out = [];
    for (const moon of moons.value) {
        const cycle = moon.cycle > 0 ? moon.cycle : 1;
        const position = (((absoluteDay - moon.offset) % cycle) + cycle) % cycle;
        if (position === 0) out.push({ name: moon.name, colour: moon.colour, phase: "new" });
        else if (position === Math.round(cycle / 2)) out.push({ name: moon.name, colour: moon.colour, phase: "full" });
    }
    return out;
};
const moonsForDay = (month, day) => notableMoons((month.firstAbsoluteDay ?? 0) + day - 1);

// Intercalary months (and calendars with no weekdays at all) render as a simple flow of day chips.
const usesGrid = (month) => weekdayCount.value > 0 && !month.intercalary;
</script>

<template>
    <Head title="Calendars" />

    <WorldLayout :world="world">
        <div class="flex items-end justify-between gap-5">
            <div class="flex flex-col gap-1.5">
                <div class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber">{{ campaign?.name }}</div>
                <div class="font-display text-[32px] leading-[1.05] text-bright">Calendars</div>
            </div>
        </div>

        <!-- Calendar picker + create -->
        <div class="flex flex-wrap items-center gap-2">
            <button
                v-for="c in calendars"
                :key="c.id"
                class="rounded-full border px-3 py-1 text-sm transition"
                :class="selected && selected.id === c.id ? 'border-amber bg-amber/10 text-amber' : 'border-edge3 text-muted hover:border-amber/60 hover:text-ink'"
                @click="selectCalendar(c.id)"
            >
                {{ c.name }}
            </button>
            <form class="flex items-center gap-2" @submit.prevent="createCalendar">
                <input v-model="createForm.name" class="field !w-40 !py-1.5 text-sm" placeholder="New calendar name" />
                <button type="submit" class="btn-ghost !py-1.5" :disabled="createForm.processing || !createForm.name">Create</button>
            </form>
        </div>

        <p v-if="!selected" class="rounded-lg border border-dashed border-edge3 p-6 text-sm text-muted">
            No calendars yet. Name one above to invent your world's months, weekdays, leap years and moons.
        </p>

        <template v-else-if="grid">
            <div :key="selected.id" class="flex flex-col gap-5">
                <!-- Toolbar: year nav + edit toggle -->
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-1 rounded-md border border-edge3">
                        <button class="px-3 py-1.5 text-muted hover:text-ink" @click="goYear(-1)">‹</button>
                        <span class="min-w-[90px] text-center font-display text-lg text-bright">Year {{ grid.year }}</span>
                        <button class="px-3 py-1.5 text-muted hover:text-ink" @click="goYear(1)">›</button>
                    </div>
                    <span class="font-mono text-[11px] text-faint">{{ grid.yearLength }} days</span>
                    <button class="btn-ghost !py-1.5" @click="editing = !editing">{{ editing ? "Close editor" : "Edit structure" }}</button>
                    <button class="ml-auto text-xs text-faint hover:text-red-400" @click="deleteCalendar">Delete calendar</button>
                </div>

                <!-- Structure editor -->
                <section v-if="editing && structure" class="panel flex flex-col gap-5 p-5">
                    <div class="flex flex-wrap gap-4">
                        <label class="flex-1">
                            <span class="mb-1 block text-sm text-muted">Calendar name</span>
                            <input v-model="structure.name" class="field" />
                        </label>
                        <label>
                            <span class="mb-1 block text-sm text-muted">Current year</span>
                            <input v-model.number="structure.current_year" type="number" class="field !w-32" />
                        </label>
                    </div>
                    <label>
                        <span class="mb-1 block text-sm text-muted">Weekdays (comma-separated, leave blank for none)</span>
                        <input v-model="weekdaysText" class="field" placeholder="Sunday, Monday, …" />
                    </label>

                    <!-- Months -->
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-sm text-muted">Months</span>
                            <button class="btn-ghost !py-1" @click="addMonth">+ Month</button>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <div v-for="(m, i) in structure.months" :key="i" class="flex flex-wrap items-center gap-2">
                                <span class="w-6 text-right font-mono text-[11px] text-faint">{{ i + 1 }}</span>
                                <input v-model="m.name" class="field flex-1 !py-1.5 text-sm" placeholder="Month name" />
                                <input v-model.number="m.days" type="number" min="1" class="field !w-20 !py-1.5 text-sm" />
                                <span class="text-xs text-faint">days</span>
                                <label class="flex items-center gap-1.5 text-xs text-muted" title="Festival days that belong to no week — they never shift the weekday cycle.">
                                    <input v-model="m.intercalary" type="checkbox" class="accent-amber" />
                                    Intercalary
                                </label>
                                <button class="text-faint hover:text-red-400" @click="removeMonth(i)">✕</button>
                            </div>
                        </div>
                    </div>

                    <!-- Leap rules -->
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <div class="flex flex-col">
                                <span class="text-sm text-muted">Leap rules</span>
                                <span class="text-[11px] text-faint">Add days to a month on a cycle — e.g. every 4 years, except every 100, unless every 400.</span>
                            </div>
                            <button class="btn-ghost !py-1" @click="addLeapRule">+ Leap rule</button>
                        </div>
                        <div class="flex flex-col gap-2">
                            <div v-for="(r, i) in structure.leap_rules" :key="i" class="flex flex-wrap items-center gap-2 rounded-md border border-edge2 p-2">
                                <input v-model="r.name" class="field !w-36 !py-1.5 text-sm" placeholder="Rule name" />
                                <span class="text-xs text-faint">add</span>
                                <input v-model.number="r.add" type="number" min="1" class="field !w-16 !py-1.5 text-sm" />
                                <span class="text-xs text-faint">day(s) to</span>
                                <select v-model.number="r.month" class="field !w-auto !py-1.5 text-sm">
                                    <option v-for="(m, mi) in structure.months" :key="mi" :value="mi">{{ m.name || `Month ${mi + 1}` }}</option>
                                </select>
                                <span class="text-xs text-faint">every</span>
                                <input v-model.number="r.every" type="number" min="1" class="field !w-16 !py-1.5 text-sm" />
                                <span class="text-xs text-faint">yrs, except every</span>
                                <input v-model.number="r.except_every" type="number" min="0" class="field !w-16 !py-1.5 text-sm" placeholder="0" />
                                <span class="text-xs text-faint">unless every</span>
                                <input v-model.number="r.unless_every" type="number" min="0" class="field !w-16 !py-1.5 text-sm" placeholder="0" />
                                <button class="text-faint hover:text-red-400" @click="removeLeapRule(i)">✕</button>
                            </div>
                        </div>
                    </div>

                    <!-- Moons -->
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <div class="flex flex-col">
                                <span class="text-sm text-muted">Moons</span>
                                <span class="text-[11px] text-faint">Each moon's full (●) and new (○) days are marked on the calendar.</span>
                            </div>
                            <button class="btn-ghost !py-1" @click="addMoon">+ Moon</button>
                        </div>
                        <div class="flex flex-col gap-2">
                            <div v-for="(m, i) in structure.moons" :key="i" class="flex flex-wrap items-center gap-2 rounded-md border border-edge2 p-2">
                                <input v-model="m.name" class="field !w-40 !py-1.5 text-sm" placeholder="Moon name" />
                                <span class="text-xs text-faint">cycle</span>
                                <input v-model.number="m.cycle" type="number" min="1" class="field !w-20 !py-1.5 text-sm" />
                                <span class="text-xs text-faint">days, offset</span>
                                <input v-model.number="m.offset" type="number" min="0" class="field !w-16 !py-1.5 text-sm" />
                                <input v-model="m.colour" type="color" class="h-8 w-10 cursor-pointer rounded border border-edge3 bg-transparent" />
                                <button class="text-faint hover:text-red-400" @click="removeMoon(i)">✕</button>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button class="btn-primary" :disabled="structure.processing" @click="saveStructure">Save calendar</button>
                        <span v-if="structure.errors && Object.keys(structure.errors).length" class="text-xs text-red-400">
                            Please check the highlighted fields.
                        </span>
                    </div>
                </section>

                <!-- Moon legend -->
                <div v-if="moons.length" class="flex flex-wrap items-center gap-4">
                    <div v-for="moon in moons" :key="moon.name" class="flex items-center gap-1.5 text-xs text-muted">
                        <span class="inline-block h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: moon.colour || '#c9d4ff' }"></span>
                        {{ moon.name }}
                    </div>
                </div>

                <!-- Add-event inline form -->
                <div v-if="eventFor" class="panel flex flex-wrap items-end gap-3 border-amber/30 p-4">
                    <div class="text-sm text-muted">
                        New event on <span class="text-ink">{{ grid.months[eventFor.month]?.name }} {{ eventFor.day }}, Year {{ grid.year }}</span>
                    </div>
                    <input v-model="eventTitle" class="field flex-1 !py-1.5 text-sm" placeholder="Event title" @keyup.enter="addEvent" />
                    <button class="btn-primary !py-1.5" :disabled="!eventTitle" @click="addEvent">Add</button>
                    <button class="text-sm text-faint hover:text-ink" @click="eventFor = null">Cancel</button>
                </div>

                <!-- Month grids -->
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <div v-for="month in grid.months" :key="month.index" class="panel p-4">
                        <div class="mb-2 flex items-center gap-2">
                            <span class="font-display text-[17px] text-bright">{{ month.name }}</span>
                            <span v-if="month.intercalary" class="rounded-full border border-teal/40 px-2 py-0.5 font-mono text-[9px] uppercase tracking-wide text-teal">Festival</span>
                        </div>

                        <!-- Weekday grid -->
                        <div
                            v-if="usesGrid(month)"
                            class="grid gap-1"
                            :style="{ gridTemplateColumns: `repeat(${weekdayCount}, minmax(0, 1fr))` }"
                        >
                            <div
                                v-for="wd in grid.weekdays"
                                :key="wd"
                                class="pb-1 text-center font-mono text-[9px] uppercase tracking-wide text-faint"
                            >
                                {{ wd.slice(0, 2) }}
                            </div>
                            <div v-for="blank in month.firstWeekday" :key="'b' + blank"></div>
                            <button
                                v-for="day in month.days"
                                :key="day"
                                class="relative aspect-square rounded border border-edge2 text-[11px] text-muted transition hover:border-amber hover:text-ink"
                                :class="eventsOn(month, day).length ? 'border-teal/40' : ''"
                                @click="openDay(month.index, day)"
                            >
                                {{ day }}
                                <span
                                    v-if="eventsOn(month, day).length"
                                    class="absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-teal"
                                ></span>
                                <span class="absolute right-0.5 top-0.5 flex gap-0.5">
                                    <span
                                        v-for="mp in moonsForDay(month, day)"
                                        :key="mp.name"
                                        class="inline-block h-1.5 w-1.5 rounded-full"
                                        :style="mp.phase === 'full'
                                            ? { backgroundColor: mp.colour || '#c9d4ff' }
                                            : { boxShadow: `inset 0 0 0 1px ${mp.colour || '#c9d4ff'}` }"
                                        :title="`${mp.phase === 'full' ? 'Full' : 'New'} moon: ${mp.name}`"
                                    ></span>
                                </span>
                            </button>
                        </div>

                        <!-- Chip flow (intercalary or no weekdays) -->
                        <div v-else class="flex flex-wrap gap-1">
                            <button
                                v-for="day in month.days"
                                :key="day"
                                class="relative h-7 w-7 rounded border border-edge2 text-[11px] text-muted transition hover:border-amber hover:text-ink"
                                :class="eventsOn(month, day).length ? 'border-teal/40' : ''"
                                @click="openDay(month.index, day)"
                            >
                                {{ day }}
                                <span
                                    v-if="eventsOn(month, day).length"
                                    class="absolute bottom-0.5 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-teal"
                                ></span>
                                <span class="absolute right-0.5 top-0.5 flex gap-0.5">
                                    <span
                                        v-for="mp in moonsForDay(month, day)"
                                        :key="mp.name"
                                        class="inline-block h-1.5 w-1.5 rounded-full"
                                        :style="mp.phase === 'full'
                                            ? { backgroundColor: mp.colour || '#c9d4ff' }
                                            : { boxShadow: `inset 0 0 0 1px ${mp.colour || '#c9d4ff'}` }"
                                        :title="`${mp.phase === 'full' ? 'Full' : 'New'} moon: ${mp.name}`"
                                    ></span>
                                </span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Events this year -->
                <section>
                    <div class="eyebrow-muted mb-2">Events in Year {{ grid.year }}</div>
                    <div v-if="allEvents.length" class="flex flex-col gap-1.5">
                        <div
                            v-for="e in allEvents"
                            :key="e.id"
                            class="panel flex items-center gap-3 px-4 py-2"
                        >
                            <span class="font-mono text-[11px] text-teal">{{ e.monthName }} {{ e.day }}</span>
                            <span class="min-w-0 flex-1 truncate text-sm text-ink">{{ e.title }}</span>
                            <button class="text-faint hover:text-red-400" title="Remove" @click="removeEvent(e.id)">✕</button>
                        </div>
                    </div>
                    <p v-else class="text-sm text-faint">No events this year — click a day to add one.</p>
                </section>
            </div>
        </template>
    </WorldLayout>
</template>
