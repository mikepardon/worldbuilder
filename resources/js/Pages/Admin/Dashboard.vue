<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    stats: Object,
    alerts: Object,
    newestUsers: Array,
    recentWorlds: Array,
    // The default seed world (or undefined if it hasn't been seeded), editable by admins in the GM workspace.
    seedWorld: { type: Object, default: undefined },
});
</script>

<template>
    <Head title="Admin" />

    <AdminLayout>
        <div class="font-display text-[32px] leading-[1.05] text-bright">Overview</div>

        <!-- Stats -->
        <div class="grid gap-4 sm:grid-cols-4">
            <div v-for="(value, label) in stats" :key="label" class="panel p-5">
                <div class="font-display text-3xl text-bright">{{ value }}</div>
                <div class="mt-1 font-mono text-xs uppercase tracking-wider text-faint">{{ label }}</div>
            </div>
        </div>

        <!-- Seed world — admins edit the default demo directly in the GM workspace. -->
        <div
            v-if="seedWorld"
            class="panel flex flex-wrap items-center justify-between gap-4 p-5"
        >
            <div>
                <div class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-amber">
                    Seed world
                </div>
                <div class="mt-1 font-display text-lg text-bright">{{ seedWorld.name }}</div>
                <div class="mt-0.5 text-sm text-muted">
                    The default demo every new GM can explore and clone — {{ seedWorld.entries }} entries.
                    Edits here change what everyone copies.
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a
                    :href="`/w/${seedWorld.slug}`"
                    target="_blank"
                    class="text-sm text-muted hover:text-amber"
                    >View as reader ↗</a
                >
                <Link :href="route('worlds.show', seedWorld.id)" class="btn-primary">
                    Edit the seed world
                </Link>
            </div>
        </div>

        <!-- Needs a look -->
        <div class="panel flex flex-wrap gap-6 p-5">
            <div class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-amber">Needs a look</div>
            <div class="flex flex-wrap gap-6 text-sm">
                <div><span class="font-display text-lg text-bright">{{ alerts.unimportedSources }}</span> <span class="text-muted">sources not yet imported</span></div>
                <div><span class="font-display text-lg text-bright">{{ alerts.usersNoCampaigns }}</span> <span class="text-muted">users with no world</span></div>
                <div><span class="font-display text-lg text-bright">{{ alerts.publicWorlds }}</span> <span class="text-muted">public worlds</span></div>
            </div>
        </div>

        <div class="grid gap-8 lg:grid-cols-2">
            <!-- Newest users -->
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint">Newest sign-ups</h3>
                    <Link :href="route('admin.users.index')" class="text-xs text-muted hover:text-amber">All users →</Link>
                </div>
                <div class="panel overflow-hidden">
                    <table class="wb-table">
                        <thead><tr><th>Name</th><th>Email</th><th>Worlds</th><th>Role</th></tr></thead>
                        <tbody>
                            <tr v-for="u in newestUsers" :key="u.id">
                                <td class="text-ink">{{ u.name }}</td>
                                <td class="text-muted">{{ u.email }}</td>
                                <td class="text-muted">{{ u.worlds_count }}</td>
                                <td><span v-if="u.is_admin" class="badge-gm">admin</span><span v-else class="text-faint">user</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent worlds -->
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint">Recent worlds</h3>
                    <Link :href="route('admin.worlds.index')" class="text-xs text-muted hover:text-amber">All worlds →</Link>
                </div>
                <div class="panel overflow-hidden">
                    <table class="wb-table">
                        <thead><tr><th>World</th><th>Owner</th><th>Entries</th><th>Visibility</th></tr></thead>
                        <tbody>
                            <tr v-for="c in recentWorlds" :key="c.id">
                                <td><a :href="`/w/${c.slug}`" class="text-ink hover:text-amber">{{ c.name }}</a></td>
                                <td class="text-muted">{{ c.owner }}</td>
                                <td class="text-muted">{{ c.documents_count }}</td>
                                <td class="capitalize text-muted">{{ c.visibility }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
