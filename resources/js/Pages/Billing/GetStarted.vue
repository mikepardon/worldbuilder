<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const { plan, billingEnabled = false } = defineProps({
    plan: { type: Object, required: true },
    billingEnabled: { type: Boolean, default: false },
});

const features = computed(() => [
    `${plan.worlds} ${plan.worlds === 1 ? "world" : "worlds"}`,
    "5 free AI credits / day",
    ...(plan.monthly_credits > 0
        ? [`+ ${plan.monthly_credits} AI credits / month`]
        : []),
    `${plan.storage_display} storage`,
    plan.custom_domain ? "Custom domain" : "Player-facing site",
]);

const submitting = ref(false);
const continueToCheckout = () => {
    submitting.value = true;
    router.post(
        route("billing.checkout"),
        { plan: plan.key },
        { preserveScroll: true, onFinish: () => (submitting.value = false) },
    );
};
</script>

<template>
    <Head :title="`Continue to ${plan.name} — Worldbuilder`" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-lg px-6 py-16">
            <div class="text-center">
                <div class="eyebrow tracking-[0.3em]">Welcome aboard</div>
                <h1 class="mt-3 font-display text-4xl text-bright">
                    Your account is ready
                </h1>
                <p class="mx-auto mt-3 max-w-md text-muted">
                    You picked the {{ plan.name }} plan on the way in. Continue to it now,
                    or start free and upgrade whenever you like.
                </p>
            </div>

            <div class="mt-8 rounded-xl border border-amber/40 bg-card p-6 ring-1 ring-amber/20">
                <div class="flex items-baseline justify-between">
                    <h2 class="font-display text-2xl text-[#f3efe6]">{{ plan.name }}</h2>
                    <div class="flex items-baseline gap-1">
                        <span class="font-display text-3xl text-bright">{{ plan.price_display }}</span>
                        <span class="text-sm text-faint">/ month</span>
                    </div>
                </div>
                <p class="mt-2 text-sm leading-relaxed text-muted">{{ plan.blurb }}</p>

                <ul class="mt-5 space-y-2 text-sm text-[#c8ccd3]">
                    <li
                        v-for="feature in features"
                        :key="feature"
                        class="flex items-center gap-2"
                    >
                        <span class="text-teal" aria-hidden="true">✓</span>
                        <span>{{ feature }}</span>
                    </li>
                </ul>
            </div>

            <div v-if="billingEnabled" class="mt-6 flex flex-col gap-3">
                <form @submit.prevent="continueToCheckout">
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="w-full rounded-md bg-amber-600 px-6 py-3 text-center font-medium text-white transition hover:bg-amber-700 disabled:opacity-50"
                    >
                        {{ submitting ? "Taking you to checkout…" : `Continue to ${plan.name} — ${plan.price_display}/month` }}
                    </button>
                </form>
                <Link
                    :href="route('dashboard')"
                    class="rounded-md border border-edge3 px-6 py-3 text-center text-[#c8ccd3] transition hover:border-teal"
                >
                    Start free for now
                </Link>
            </div>

            <div v-else class="mt-6 flex flex-col gap-3">
                <div class="rounded-md border border-edge2 bg-[#14171d] p-4 text-center text-sm text-muted">
                    Online payments aren’t switched on yet — you’re starting on the free plan.
                    You can upgrade to {{ plan.name }} from Billing once they’re live.
                </div>
                <Link
                    :href="route('dashboard')"
                    class="rounded-md bg-amber-600 px-6 py-3 text-center font-medium text-white hover:bg-amber-700"
                >
                    Go to your dashboard
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
