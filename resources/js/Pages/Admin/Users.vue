<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    users: Array,
});

const page = usePage();
const meId = computed(() => page.props.auth.user.id);

const q = ref('');
const filter = ref('all'); // all | admins | no-worlds
const shown = computed(() =>
    props.users.filter((u) => {
        if (filter.value === 'admins' && !u.is_admin) return false;
        if (filter.value === 'no-worlds' && u.worlds_count > 0) return false;
        if (q.value.trim() && !u.email.toLowerCase().includes(q.value.toLowerCase()) && !(u.name ?? '').toLowerCase().includes(q.value.toLowerCase())) return false;
        return true;
    }),
);

const setRole = (user, isAdmin) =>
    router.put(route('admin.users.role', user.id), { is_admin: isAdmin }, { preserveScroll: true });

const topUpId = ref(null);
const amount = ref(100);
const openTopUp = (user) => {
    topUpId.value = user.id;
    amount.value = 100;
};
const submitTopUp = (user) =>
    router.put(
        route('admin.users.credits', user.id),
        { credits: Number(amount.value) },
        { preserveScroll: true, onSuccess: () => (topUpId.value = null) },
    );
</script>

<template>
    <Head title="Users · Admin" />

    <AdminLayout>
        <div class="flex items-end justify-between gap-4">
            <div class="font-display text-[32px] leading-[1.05] text-bright">Users</div>
            <div class="font-mono text-[11px] text-faint">{{ shown.length }} of {{ users.length }}</div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <input v-model="q" class="field max-w-[320px] flex-1" placeholder="Search by name or email…" />
            <div class="flex gap-1">
                <button v-for="f in [['all','Everyone'],['admins','Admins'],['no-worlds','No worlds']]" :key="f[0]"
                    class="rounded-md px-3 py-1 text-sm" :class="filter === f[0] ? 'bg-raised text-bright' : 'text-muted hover:text-ink'"
                    @click="filter = f[0]">{{ f[1] }}</button>
            </div>
        </div>

        <div class="panel overflow-hidden">
            <table class="wb-table">
                <thead><tr><th>Name</th><th>Email</th><th>Joined</th><th>Worlds</th><th>Credits</th><th>Role</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="u in shown" :key="u.id">
                        <td class="text-ink">{{ u.name }}</td>
                        <td class="text-muted">{{ u.email }}</td>
                        <td class="text-faint">{{ u.created_at }}</td>
                        <td class="text-muted">{{ u.worlds_count }}</td>
                        <td>
                            <div class="flex items-center gap-2">
                                <span class="text-ink">{{ u.credits_remaining }}</span>
                                <span class="text-[11px] text-faint">{{ u.used_today }}/{{ u.daily_allowance }} today<span v-if="u.credit_balance">, +{{ u.credit_balance }} bal</span></span>
                                <form v-if="topUpId === u.id" class="flex items-center gap-1" @submit.prevent="submitTopUp(u)">
                                    <input v-model="amount" type="number" min="1" class="field w-16 py-0.5 text-xs" />
                                    <button type="submit" class="text-xs text-teal hover:underline">Add</button>
                                    <button type="button" class="text-xs text-faint hover:text-ink" @click="topUpId = null">✕</button>
                                </form>
                                <button v-else class="text-xs text-teal hover:underline" @click="openTopUp(u)">Top up</button>
                            </div>
                        </td>
                        <td><span v-if="u.is_admin" class="badge-gm">admin</span><span v-else class="text-faint">user</span></td>
                        <td class="text-right">
                            <button v-if="!u.is_admin" class="text-xs text-teal hover:underline" @click="setRole(u, true)">Make admin</button>
                            <button v-else-if="u.id !== meId" class="text-xs text-muted hover:text-blood" @click="setRole(u, false)">Revoke</button>
                            <span v-else class="text-xs text-faint">you</span>
                        </td>
                    </tr>
                    <tr v-if="!shown.length"><td colspan="7" class="py-10 text-center text-faint">No matching users.</td></tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
