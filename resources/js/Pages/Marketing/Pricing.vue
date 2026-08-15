<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import MarketingShell from '@/Components/MarketingShell.vue';

const { plans = [] } = defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
    plans: Array,
});

const featured = 'basic';

const user = computed(() => usePage().props.auth?.user);

// Where each plan's button goes. Guests start the register flow (a paid plan is remembered so it can be
// offered again after signup); signed-in users go to their billing page to change plan.
function planCta(plan) {
    if (user.value) {
        return plan.is_free
            ? { href: route('dashboard'), label: 'Go to dashboard' }
            : { href: route('billing.index'), label: `Choose ${plan.name}` };
    }

    return plan.is_free
        ? { href: route('register'), label: 'Get started free' }
        : { href: route('register', { plan: plan.key }), label: 'Get started' };
}

function features(plan) {
    return [
        `${plan.worlds} ${plan.worlds === 1 ? 'world' : 'worlds'}`,
        `${plan.daily_credits} free AI credits / day`,
        ...(plan.monthly_credits > 0 ? [`+ ${plan.monthly_credits} AI credits / month`] : []),
        `${plan.storage_display} storage`,
        plan.custom_domain ? 'Custom domain' : 'Player-facing site',
    ];
}
</script>

<template>
    <Head title="Pricing — Worldbuilder" />

    <MarketingShell :can-login="canLogin" :can-register="canRegister">
        <section class="mx-auto max-w-4xl px-6 py-20 text-center">
            <div class="font-mono text-xs uppercase tracking-[0.3em] text-[#6fbfc4]">Pricing</div>
            <h1 class="mt-4 font-display text-5xl leading-tight text-[#f5f1e8]">
                Start free. Upgrade when your table grows.
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-lg font-light text-[#b8bcc4]">
                Every account gets free AI credits every day. Paid plans add more worlds, more credits,
                more storage and a custom domain.
            </p>
        </section>

        <section class="mx-auto max-w-6xl px-6 pb-8">
            <div class="grid gap-5 lg:grid-cols-3">
                <div
                    v-for="plan in plans"
                    :key="plan.key"
                    class="flex flex-col rounded-xl border bg-[#181b21] p-6"
                    :class="plan.key === featured ? 'border-amber-600 ring-1 ring-amber-600/40' : 'border-[#262a33]'"
                >
                    <div class="flex items-baseline justify-between">
                        <h2 class="font-display text-2xl text-[#f3efe6]">{{ plan.name }}</h2>
                        <span
                            v-if="plan.key === featured"
                            class="rounded-full bg-amber-600/15 px-3 py-1 font-mono text-[10px] uppercase tracking-widest text-amber-400"
                        >
                            Most popular
                        </span>
                    </div>

                    <div class="mt-4 flex items-baseline gap-1">
                        <span class="font-display text-4xl text-[#f5f1e8]">{{ plan.price_display }}</span>
                        <span v-if="!plan.is_free" class="text-sm text-[#6f7580]">/ month</span>
                    </div>

                    <p class="mt-3 text-sm leading-relaxed text-[#9aa0ab]">{{ plan.blurb }}</p>

                    <ul class="mt-6 space-y-2 text-sm text-[#c8ccd3]">
                        <li v-for="feature in features(plan)" :key="feature" class="flex items-center gap-2">
                            <span class="text-[#6fbfc4]" aria-hidden="true">✓</span>
                            <span>{{ feature }}</span>
                        </li>
                    </ul>

                    <div class="mt-8 pt-2">
                        <Link
                            :href="planCta(plan).href"
                            class="block rounded-md px-4 py-2.5 text-center font-medium"
                            :class="plan.is_free || plan.key === featured
                                ? 'bg-amber-600 text-white hover:bg-amber-700'
                                : 'border border-[#2e323c] text-[#c8ccd3] hover:border-[#6fbfc4]'"
                        >
                            {{ planCta(plan).label }}
                        </Link>
                    </div>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-4xl px-6 py-24 text-center">
            <h2 class="font-display text-3xl text-[#f5f1e8]">Build your first world today</h2>
            <p class="mx-auto mt-3 max-w-xl text-[#b8bcc4]">Free to start, no card required.</p>
            <div class="mt-8 flex justify-center gap-3">
                <Link
                    v-if="canRegister"
                    :href="route('register')"
                    class="rounded-md bg-amber-600 px-6 py-3 font-medium text-white hover:bg-amber-700"
                >
                    Create your world
                </Link>
                <Link
                    :href="route('marketing.how')"
                    class="rounded-md border border-[#2e323c] px-6 py-3 text-[#c8ccd3] hover:border-[#6fbfc4]"
                >
                    How it works
                </Link>
            </div>
        </section>
    </MarketingShell>
</template>
