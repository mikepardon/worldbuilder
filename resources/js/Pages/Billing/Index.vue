<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import { computed, onMounted, ref } from "vue";

const props = defineProps({
    currentPlan: { type: String, default: "free" },
    usage: { type: Object, default: () => ({}) },
    plans: { type: Array, default: () => [] },
    topUps: { type: Array, default: () => [] },
    billingEnabled: { type: Boolean, default: false },
    hasBilling: { type: Boolean, default: false },
    checkoutStatus: { type: String, default: null },
    pending: { type: Object, default: null },
});

// The checkout success/cancel note is a one-time message: show it on arrival, then strip the query
// params so a refresh or later visit doesn't keep showing it, and fade it out after a few seconds.
const showStatus = ref(Boolean(props.checkoutStatus));
onMounted(() => {
    if (!props.checkoutStatus) return;
    window.history.replaceState({}, "", window.location.pathname);
    window.setTimeout(() => (showStatus.value = false), 6000);
});

const current = computed(() =>
    props.plans.find((p) => p.key === props.currentPlan),
);
const dailyLeft = computed(() =>
    Math.max(0, (props.usage.daily_allowance || 0) - (props.usage.ai_used_today || 0)),
);

const currentIndex = computed(() => props.plans.findIndex((p) => p.is_current));
const isDowngrade = (plan) => props.plans.findIndex((p) => p.key === plan.key) < currentIndex.value;
const actionLabel = (plan) => (isDowngrade(plan) ? `Downgrade to ${plan.name}` : `Upgrade to ${plan.name}`);

const choosing = ref(null);
const choose = (plan) => {
    // Downgrades keep the paid tier until the period ends — make that explicit before committing.
    if (isDowngrade(plan)) {
        const message = plan.is_free
            ? "Downgrade to Free? You'll keep your current plan until the end of your billing period, then move to Free. You won't lose anything you've already paid for."
            : `Downgrade to ${plan.name}? You'll keep your current plan until the end of your billing period, then move to ${plan.name}. You won't lose anything you've already paid for.`;
        if (!window.confirm(message)) return;
    }

    choosing.value = plan.key;
    router.post(
        route("billing.checkout"),
        { plan: plan.key },
        { preserveScroll: true, onFinish: () => (choosing.value = null) },
    );
};

const buyingTopUp = ref(null);
const buyTopUp = (bundleKey) => {
    buyingTopUp.value = bundleKey;
    router.post(
        route("billing.topup"),
        { bundle: bundleKey },
        { preserveScroll: true, onFinish: () => (buyingTopUp.value = null) },
    );
};

const cancelling = ref(false);
const cancelDowngrade = () => {
    cancelling.value = true;
    router.post(
        route("billing.cancel-downgrade"),
        {},
        { preserveScroll: true, onFinish: () => (cancelling.value = false) },
    );
};

const openingPortal = ref(false);
const managePortal = () => {
    openingPortal.value = true;
    router.post(
        route("billing.portal"),
        {},
        { preserveScroll: true, onFinish: () => (openingPortal.value = false) },
    );
};

const features = (plan) => [
    `${plan.worlds} ${plan.worlds === 1 ? "world" : "worlds"}`,
    `${plan.daily_credits} free AI credits / day`,
    ...(plan.monthly_credits > 0
        ? [`+ ${plan.monthly_credits} AI credits / month`]
        : []),
    `${plan.storage_display} storage`,
    plan.custom_domain ? "Custom domain" : "Player-facing site",
];
</script>

<template>
    <Head title="Billing" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl px-4 py-8">
            <div class="mb-8 flex flex-col gap-1.5">
                <div class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber">
                    Account
                </div>
                <div class="font-display text-[32px] leading-[1.05] text-bright">
                    Billing
                </div>
            </div>

            <p
                v-if="showStatus && checkoutStatus === 'success'"
                class="mb-6 rounded-md border border-teal/30 bg-teal/10 px-4 py-3 text-sm text-teal"
            >
                Payment received — thanks! You're on the <span class="font-semibold">{{ current?.name }}</span> plan.
            </p>
            <p
                v-else-if="showStatus && checkoutStatus === 'cancel'"
                class="mb-6 rounded-md border border-edge2 bg-raised/40 px-4 py-3 text-sm text-muted"
            >
                Checkout cancelled — you haven't been charged, and your plan is unchanged.
            </p>

            <!-- A scheduled downgrade the user will move to at period end. -->
            <div
                v-if="pending"
                class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber/30 bg-amber/10 px-4 py-3 text-sm text-amber"
            >
                <span>
                    Scheduled change: you'll move to the <span class="font-semibold">{{ pending.plan_name }}</span>
                    plan on <span class="font-semibold">{{ pending.date }}</span>. You keep your current plan and
                    everything you've paid for until then.
                </span>
                <button
                    type="button"
                    class="shrink-0 rounded-md border border-amber/50 px-3 py-1.5 font-medium text-amber transition hover:bg-amber/10 disabled:opacity-50"
                    :disabled="cancelling"
                    @click="cancelDowngrade"
                >
                    {{ cancelling ? "Cancelling…" : "Keep my current plan" }}
                </button>
            </div>

            <!-- Current plan + usage -->
            <section class="panel mb-8 border-amber/30 p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow-muted mb-1">Your plan</div>
                        <div class="flex items-baseline gap-2">
                            <span class="font-display text-2xl text-bright">{{ current?.name }}</span>
                            <span class="text-sm text-muted">{{ current?.price_display }}<span v-if="!current?.is_free"> / month</span></span>
                        </div>
                        <p v-if="current?.blurb" class="mt-1 max-w-xl text-sm text-muted">{{ current.blurb }}</p>
                    </div>
                    <button
                        v-if="hasBilling"
                        type="button"
                        class="rounded-md border border-edge3 px-3 py-1.5 text-sm text-ink hover:border-teal hover:text-teal disabled:opacity-50"
                        :disabled="openingPortal"
                        @click="managePortal"
                    >
                        {{ openingPortal ? "Opening…" : "Manage billing" }}
                    </button>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-edge2 bg-raised/40 p-4">
                        <div class="font-display text-2xl text-bright">
                            {{ dailyLeft }}<span class="text-base text-faint">/{{ usage.daily_allowance }}</span>
                        </div>
                        <div class="mt-1 font-mono text-[10px] uppercase tracking-wider text-faint">Daily AI credits left</div>
                    </div>
                    <div class="rounded-lg border border-edge2 bg-raised/40 p-4">
                        <div class="font-display text-2xl text-bright">{{ usage.credit_balance }}</div>
                        <div class="mt-1 font-mono text-[10px] uppercase tracking-wider text-faint">Top-up credits</div>
                    </div>
                    <div class="rounded-lg border border-edge2 bg-raised/40 p-4">
                        <div class="font-display text-2xl text-bright">
                            {{ usage.worlds_used }}<span class="text-base text-faint">/{{ usage.world_limit }}</span>
                        </div>
                        <div class="mt-1 font-mono text-[10px] uppercase tracking-wider text-faint">Worlds used</div>
                    </div>
                    <div class="rounded-lg border border-edge2 bg-raised/40 p-4">
                        <div class="font-display text-2xl text-bright">
                            {{ usage.storage_used_display }}<span class="text-base text-faint"> / {{ usage.storage_limit_display }}</span>
                        </div>
                        <div class="mt-1 font-mono text-[10px] uppercase tracking-wider text-faint">Storage used</div>
                    </div>
                </div>
            </section>

            <!-- Change plan -->
            <div class="mb-2 flex items-center justify-between">
                <div class="eyebrow-muted">Change plan</div>
            </div>
            <p v-if="!billingEnabled" class="mb-4 rounded-md border border-edge2 bg-raised/40 px-4 py-2.5 text-sm text-muted">
                Online payments aren't switched on yet — plan changes are coming soon.
            </p>

            <div class="grid gap-4 lg:grid-cols-3">
                <div
                    v-for="plan in plans"
                    :key="plan.key"
                    class="panel flex flex-col p-5"
                    :class="plan.is_current ? 'border-amber/50 ring-1 ring-amber/30' : ''"
                >
                    <div class="flex items-baseline justify-between">
                        <span class="font-display text-xl text-bright">{{ plan.name }}</span>
                        <span
                            v-if="plan.is_current"
                            class="rounded-full bg-amber/15 px-2.5 py-0.5 font-mono text-[9px] uppercase tracking-widest text-amber"
                        >Current</span>
                    </div>

                    <div class="mt-2 flex items-baseline gap-1">
                        <span class="font-display text-3xl text-bright">{{ plan.price_display }}</span>
                        <span v-if="!plan.is_free" class="text-xs text-faint">/ month</span>
                    </div>

                    <ul class="mt-4 flex-1 space-y-1.5 text-sm text-muted">
                        <li v-for="f in features(plan)" :key="f" class="flex items-center gap-2">
                            <span class="text-teal" aria-hidden="true">✓</span>{{ f }}
                        </li>
                    </ul>

                    <div class="mt-5">
                        <span
                            v-if="plan.is_current"
                            class="block rounded-md border border-edge3 px-4 py-2 text-center text-sm text-muted"
                        >Your current plan</span>
                        <button
                            v-else
                            type="button"
                            class="w-full rounded-md bg-amber px-4 py-2 text-sm font-semibold text-night transition hover:bg-amber/90 disabled:opacity-50"
                            :disabled="choosing === plan.key"
                            @click="choose(plan)"
                        >
                            {{ choosing === plan.key ? "…" : actionLabel(plan) }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Top up AI credits -->
            <section class="mt-10">
                <div class="mb-2 eyebrow-muted">Top up AI credits</div>
                <p class="mb-4 text-sm text-muted">
                    Out of daily credits? Buy a one-off bundle — these never expire and stack on top of
                    your daily allowance.
                </p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div
                        v-for="bundle in topUps"
                        :key="bundle.key"
                        class="panel flex items-center justify-between gap-3 p-4"
                    >
                        <div>
                            <div class="font-display text-lg text-bright">{{ bundle.credits }} credits</div>
                            <div class="font-mono text-[11px] text-faint">{{ bundle.price_display }} one-off</div>
                        </div>
                        <button
                            type="button"
                            class="rounded-md bg-teal px-3 py-1.5 text-sm font-semibold text-night transition hover:bg-teal/90 disabled:opacity-50"
                            :disabled="buyingTopUp === bundle.key"
                            @click="buyTopUp(bundle.key)"
                        >
                            {{ buyingTopUp === bundle.key ? "…" : "Buy" }}
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
