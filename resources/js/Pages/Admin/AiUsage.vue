<script setup>
import AdminLayout from "@/Layouts/AdminLayout.vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
    range: { type: String, default: "30" },
    totals: Object,
    allTimeCost: { type: String, default: "$0.00" },
    group: { type: String, default: "type" },
    groups: { type: Array, default: () => [] },
    breakdown: { type: Array, default: () => [] },
    creditCents: { type: Number, default: 2 },
    creditPresets: { type: Array, default: () => [] },
    creditReview: {
        type: Object,
        default: () => ({
            credit_value: "$0.02",
            schedule: [],
            usage: { credits: 0, revenue: "$0.00", cost: "$0.00", markup: "—" },
        }),
    },
    recapRate: { type: Number, default: 60 },
    recapRatePresets: { type: Array, default: () => [] },
    recapReview: {
        type: Object,
        default: () => ({
            per_hour: 60,
            price_per_hour: "$0.00",
            gm_price_per_hour: "$0.72",
            total_hours: "0.0",
            total_credits: 0,
            total_price: "$0.00",
            examples: [],
        }),
    },
    byWorld: { type: Array, default: () => [] },
    daily: { type: Array, default: () => [] },
    prices: { type: Array, default: () => [] },
    settings: {
        type: Object,
        default: () => ({ default_model: "", assistant_name: "" }),
    },
    availableModels: { type: Array, default: () => [] },
});

const settingsForm = useForm({
    default_model: props.settings.default_model,
    assistant_name: props.settings.assistant_name,
});
const saveSettings = () =>
    settingsForm.put(route("admin.ai-usage.settings.update"), {
        preserveScroll: true,
    });

const ranges = [
    { key: "7", label: "7 days" },
    { key: "30", label: "30 days" },
    { key: "90", label: "90 days" },
    { key: "all", label: "All time" },
];

const number = (value) => (value ?? 0).toLocaleString("en-US");

const groupLabel = computed(
    () => props.groups.find((g) => g.key === props.group)?.label ?? "Type",
);

const maxDailyCost = computed(() =>
    Math.max(1, ...props.daily.map((d) => d.cost_nanos)),
);

const pricingForm = useForm({
    prices: props.prices.map((p) => ({
        id: p.id,
        input_price: p.input_price,
        output_price: p.output_price,
    })),
});
const savePrices = () =>
    pricingForm.put(route("admin.ai-usage.pricing.update"), {
        preserveScroll: true,
    });
</script>

<template>
    <Head title="AI usage — Admin" />

    <AdminLayout>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="font-display text-[32px] leading-[1.05] text-bright">
                AI usage &amp; cost
            </div>
            <div
                class="flex items-center gap-1 rounded-md border border-edge3 p-0.5"
            >
                <Link
                    v-for="r in ranges"
                    :key="r.key"
                    :href="route('admin.ai-usage.index', { range: r.key })"
                    class="rounded px-2.5 py-1 text-sm transition"
                    :class="
                        range === r.key
                            ? 'bg-amber/10 text-amber'
                            : 'text-muted hover:text-ink'
                    "
                >
                    {{ r.label }}
                </Link>
            </div>
        </div>

        <!-- Summary cards -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="panel p-4">
                <div
                    class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint"
                >
                    Cost (period)
                </div>
                <div class="mt-1 font-display text-[26px] text-bright">
                    {{ totals.cost }}
                </div>
                <div class="text-xs text-faint">all-time {{ allTimeCost }}</div>
            </div>
            <div class="panel p-4">
                <div
                    class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint"
                >
                    AI calls
                </div>
                <div class="mt-1 font-display text-[26px] text-bright">
                    {{ number(totals.calls) }}
                </div>
            </div>
            <div class="panel p-4">
                <div
                    class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint"
                >
                    Input tokens
                </div>
                <div class="mt-1 font-display text-[26px] text-bright">
                    {{ number(totals.input_tokens) }}
                </div>
            </div>
            <div class="panel p-4">
                <div
                    class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint"
                >
                    Output tokens
                </div>
                <div class="mt-1 font-display text-[26px] text-bright">
                    {{ number(totals.output_tokens) }}
                </div>
            </div>
        </div>

        <!-- Daily cost chart -->
        <section v-if="daily.length" class="panel p-4">
            <h3
                class="mb-3 font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint"
            >
                Daily cost
            </h3>
            <div class="flex h-32 items-end gap-0.5 overflow-x-auto">
                <div
                    v-for="d in daily"
                    :key="d.day"
                    class="min-w-[6px] flex-1 rounded-t bg-amber/70 transition hover:bg-amber"
                    :style="{
                        height: `${Math.max(2, (d.cost_nanos / maxDailyCost) * 100)}%`,
                    }"
                    :title="`${d.day}: ${d.calls} calls`"
                ></div>
            </div>
        </section>

        <!-- Breakdown (sliceable) -->
        <section>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3
                    class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint"
                >
                    Breakdown
                </h3>
                <div
                    class="flex items-center gap-1 rounded-md border border-edge3 p-0.5"
                >
                    <Link
                        v-for="g in groups"
                        :key="g.key"
                        :href="
                            route('admin.ai-usage.index', {
                                range,
                                group: g.key,
                            })
                        "
                        preserve-scroll
                        class="rounded px-2.5 py-1 text-xs transition"
                        :class="
                            group === g.key
                                ? 'bg-amber/10 text-amber'
                                : 'text-muted hover:text-ink'
                        "
                    >
                        {{ g.label }}
                    </Link>
                </div>
            </div>
            <div class="panel overflow-hidden">
                <table class="wb-table">
                    <thead>
                        <tr>
                            <th>{{ groupLabel }}</th>
                            <th>Calls</th>
                            <th>Input</th>
                            <th>Output</th>
                            <th>Avg / call</th>
                            <th>Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in breakdown" :key="i">
                            <td class="text-ink">{{ row.label }}</td>
                            <td class="text-muted">{{ number(row.calls) }}</td>
                            <td class="text-muted">
                                {{ number(row.input_tokens) }}
                            </td>
                            <td class="text-muted">
                                {{ number(row.output_tokens) }}
                            </td>
                            <td class="text-bright">{{ row.avg_cost }}</td>
                            <td class="text-bright">{{ row.cost }}</td>
                        </tr>
                        <tr v-if="!breakdown.length">
                            <td colspan="6" class="text-faint">
                                No usage in this period.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Credit weighting (review) -->
        <section>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3
                    class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint"
                >
                    Credit weighting — review
                </h3>
                <div class="flex items-center gap-2">
                    <span class="text-[11px] text-faint">Credit price</span>
                    <div
                        class="flex items-center gap-1 rounded-md border border-edge3 p-0.5"
                    >
                        <Link
                            v-for="c in creditPresets"
                            :key="c"
                            :href="
                                route('admin.ai-usage.index', {
                                    range,
                                    group,
                                    credit: c,
                                })
                            "
                            preserve-scroll
                            class="rounded px-2.5 py-1 text-xs transition"
                            :class="
                                creditCents === c
                                    ? 'bg-amber/10 text-amber'
                                    : 'text-muted hover:text-ink'
                            "
                        >
                            {{ c }}&cent;
                        </Link>
                    </div>
                </div>
            </div>

            <p class="mb-3 text-xs text-muted">
                Live credit cost per generation at
                <span class="text-ink">{{ creditReview.credit_value }}</span> /
                credit. This period those weights bill
                <span class="text-bright">{{
                    number(creditReview.usage.credits)
                }}</span>
                credits &approx;
                <span class="text-bright">{{
                    creditReview.usage.revenue
                }}</span>
                against
                <span class="text-bright">{{ creditReview.usage.cost }}</span>
                of AI cost —
                <span class="text-amber"
                    >{{ creditReview.usage.markup }} blended margin</span
                >. These weights are charged to users per action (recaps by
                audio length).
            </p>

            <div class="panel overflow-hidden">
                <table class="wb-table">
                    <thead>
                        <tr>
                            <th>Content type</th>
                            <th>Credits</th>
                            <th>Price each</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in creditReview.schedule"
                            :key="row.type"
                        >
                            <td class="text-ink">{{ row.type }}</td>
                            <td class="text-bright">
                                {{ number(row.credits) }}
                            </td>
                            <td class="text-muted">{{ row.price }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Recap pricing by audio hour -->
        <section>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3
                    class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint"
                >
                    Recap pricing — by audio hour
                </h3>
                <div class="flex items-center gap-2">
                    <span class="text-[11px] text-faint">Credits / hour</span>
                    <div
                        class="flex items-center gap-1 rounded-md border border-edge3 p-0.5"
                    >
                        <Link
                            v-for="r in recapRatePresets"
                            :key="r"
                            :href="
                                route('admin.ai-usage.index', {
                                    range,
                                    group,
                                    credit: creditCents,
                                    recapRate: r,
                                })
                            "
                            preserve-scroll
                            class="rounded px-2.5 py-1 text-xs transition"
                            :class="
                                recapRate === r
                                    ? 'bg-amber/10 text-amber'
                                    : 'text-muted hover:text-ink'
                            "
                        >
                            {{ r }}
                        </Link>
                    </div>
                </div>
            </div>

            <p class="mb-3 text-xs text-muted">
                Recaps scale with audio length. You've uploaded
                <span class="text-ink">{{ recapReview.total_hours }}</span>
                hours so far — at
                <span class="text-ink">{{ recapReview.per_hour }}</span>
                credits/hour ({{ recapReview.price_per_hour }}/hour) that's
                <span class="text-bright">{{
                    number(recapReview.total_credits)
                }}</span>
                credits &approx;
                <span class="text-bright">{{ recapReview.total_price }}</span
                >. GM Assistant charges ~<span class="text-amber">{{
                    recapReview.gm_price_per_hour
                }}</span>
                / hour.
            </p>

            <div class="panel overflow-hidden">
                <table class="wb-table">
                    <thead>
                        <tr>
                            <th>Session length</th>
                            <th>Credits</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="ex in recapReview.examples" :key="ex.label">
                            <td class="text-ink">{{ ex.label }}</td>
                            <td class="text-bright">
                                {{ number(ex.credits) }}
                            </td>
                            <td class="text-muted">{{ ex.price }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- By world -->
        <section>
            <h3
                class="mb-3 font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint"
            >
                By world
            </h3>
            <div class="panel overflow-hidden">
                <table class="wb-table">
                    <thead>
                        <tr>
                            <th>World</th>
                            <th>Calls</th>
                            <th>Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="w in byWorld" :key="w.world_id ?? 'global'">
                            <td class="text-ink">{{ w.name }}</td>
                            <td class="text-muted">{{ number(w.calls) }}</td>
                            <td class="text-bright">{{ w.cost }}</td>
                        </tr>
                        <tr v-if="!byWorld.length">
                            <td colspan="3" class="text-faint">
                                No usage in this period.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Defaults: model + assistant name -->
        <section>
            <h3
                class="mb-3 font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint"
            >
                Defaults
            </h3>
            <form
                class="panel flex flex-wrap items-end gap-4 p-4"
                @submit.prevent="saveSettings"
            >
                <label class="flex flex-col gap-1 text-xs text-muted">
                    Default model
                    <select
                        v-model="settingsForm.default_model"
                        class="field !w-56 !py-1.5 text-sm"
                    >
                        <option
                            v-for="m in availableModels"
                            :key="m"
                            :value="m"
                        >
                            {{ m }}
                        </option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs text-muted">
                    Assistant name (shown to users)
                    <input
                        v-model="settingsForm.assistant_name"
                        class="field !w-48 !py-1.5 text-sm"
                    />
                </label>
                <button
                    type="submit"
                    class="btn-primary !py-1.5"
                    :disabled="settingsForm.processing"
                >
                    Save defaults
                </button>
                <span
                    v-if="settingsForm.recentlySuccessful"
                    class="text-xs text-teal"
                    >Saved</span
                >
                <p class="w-full text-[11px] text-faint">
                    Every world uses the default model unless given its own on
                    the Worlds page. The model is never shown to users.
                </p>
            </form>
        </section>

        <!-- Pricing editor -->
        <section>
            <div class="mb-3 flex items-center justify-between">
                <h3
                    class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint"
                >
                    Model pricing
                </h3>
                <span class="text-[11px] text-faint"
                    >US cents per 1M tokens · e.g. 300 = $3.00/M</span
                >
            </div>
            <form
                class="panel flex flex-col gap-3 p-4"
                @submit.prevent="savePrices"
            >
                <div
                    v-for="(row, index) in pricingForm.prices"
                    :key="row.id"
                    class="flex flex-wrap items-center gap-3"
                >
                    <span class="w-48 font-mono text-sm text-ink">{{
                        prices[index].model
                    }}</span>
                    <label class="flex items-center gap-2 text-xs text-muted">
                        Input
                        <input
                            v-model.number="row.input_price"
                            type="number"
                            min="0"
                            class="field !w-24 !py-1 text-sm"
                        />
                    </label>
                    <label class="flex items-center gap-2 text-xs text-muted">
                        Output
                        <input
                            v-model.number="row.output_price"
                            type="number"
                            min="0"
                            class="field !w-24 !py-1 text-sm"
                        />
                    </label>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        class="btn-primary !py-1.5"
                        :disabled="pricingForm.processing"
                    >
                        Save pricing
                    </button>
                    <span
                        v-if="pricingForm.recentlySuccessful"
                        class="text-xs text-teal"
                        >Saved</span
                    >
                </div>
            </form>
        </section>
    </AdminLayout>
</template>
