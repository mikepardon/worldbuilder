<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const links = computed(() => [
    { label: 'Overview', href: route('admin.dashboard'), active: route().current('admin.dashboard') },
    { label: 'Users', href: route('admin.users.index'), active: route().current('admin.users.*') },
    { label: 'Worlds', href: route('admin.worlds.index'), active: route().current('admin.worlds.*') },
    { label: 'Compendium', href: route('admin.compendium.index'), active: route().current('admin.compendium.*') },
    { label: 'Billing', href: route('admin.billing.index'), active: route().current('admin.billing.*') },
    { label: 'AI usage', href: route('admin.ai-usage.index'), active: route().current('admin.ai-usage.*') },
    { label: 'Global attributes', href: route('admin.attributes.index'), active: route().current('admin.attributes.*') },
]);
</script>

<template>
    <AuthenticatedLayout>
        <div class="grid min-h-[calc(100vh-4rem)] grid-cols-1 lg:grid-cols-[230px_minmax(0,1fr)]" style="animation: fade .3s ease both">
            <aside class="flex flex-col gap-5 border-r border-edge2 bg-[#14161b] px-4 py-5">
                <div class="flex flex-col gap-1.5">
                    <div class="font-mono text-[9.5px] uppercase tracking-[0.18em] text-faint">Platform</div>
                    <div class="font-display text-[19px] text-bright">Admin</div>
                </div>

                <div class="flex flex-col gap-0.5">
                    <Link
                        v-for="l in links"
                        :key="l.label"
                        :href="l.href"
                        class="rounded-md px-2.5 py-1.5 text-[14.5px]"
                        :class="l.active ? 'bg-[#1e222a] text-bright' : 'text-muted hover:bg-[#1e222a] hover:text-ink'"
                    >
                        {{ l.label }}
                    </Link>
                </div>

                <Link :href="route('dashboard')" class="mt-auto rounded-md px-2.5 py-1.5 text-[13.5px] text-faint hover:bg-[#1e222a] hover:text-ink">← Exit admin</Link>
            </aside>

            <div class="flex flex-col gap-6 px-8 pb-11 pt-6">
                <slot />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
