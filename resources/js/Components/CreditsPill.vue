<script setup>
import { useCredits } from "@/composables/useCredits";
import { Link } from "@inertiajs/vue3";

// The always-visible AI credits indicator for the GM header. Shows total credits remaining (daily
// free + top-up balance) and links to Billing. The tooltip breaks down the daily-free vs top-up split.
const { credits, remaining, dailyLeft, dailyAllowance, balance } = useCredits();
</script>

<template>
    <Link
        v-if="credits"
        :href="route('billing.index')"
        class="inline-flex items-center gap-1.5 rounded-full border border-edge2 bg-surface px-3 py-1.5 text-muted transition hover:border-amber hover:text-ink"
        :title="`${dailyLeft} of ${dailyAllowance} free daily credits left · ${balance} top-up credits`"
        :aria-label="`${remaining} AI credits remaining`"
    >
        <span class="text-teal" aria-hidden="true">✦</span>
        <span class="font-mono text-xs">{{ remaining }}</span>
        <span class="hidden text-xs text-faint sm:inline">credits</span>
    </Link>
</template>
