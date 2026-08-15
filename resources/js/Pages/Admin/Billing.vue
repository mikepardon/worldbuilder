<script setup>
import AdminLayout from "@/Layouts/AdminLayout.vue";
import { Head, useForm } from "@inertiajs/vue3";

const props = defineProps({
    settings: Object,
    plans: { type: Array, default: () => [] },
    creditsToday: { type: Number, default: 0 },
});

const form = useForm({
    mode: props.settings.mode,
    test_publishable_key: props.settings.test.publishable_key || "",
    test_secret_key: "",
    test_webhook_secret: "",
    test_price_basic: props.settings.test.price_basic || "",
    test_price_pro: props.settings.test.price_pro || "",
    live_publishable_key: props.settings.live.publishable_key || "",
    live_secret_key: "",
    live_webhook_secret: "",
    live_price_basic: props.settings.live.price_basic || "",
    live_price_pro: props.settings.live.price_pro || "",
});

const save = () => form.put(route("admin.billing.update"), { preserveScroll: true });
</script>

<template>
    <Head title="Billing — Admin" />

    <AdminLayout>
        <div class="font-display text-[32px] leading-[1.05] text-bright">Billing</div>

        <!-- Plans overview -->
        <section>
            <div class="mb-3 flex items-center justify-between">
                <h3 class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint">Plans &amp; subscribers</h3>
                <div class="font-mono text-[11px] text-faint">
                    <span class="text-bright">{{ creditsToday }}</span> AI credits used today
                </div>
            </div>
            <div class="panel overflow-hidden">
                <table class="wb-table">
                    <thead>
                        <tr><th>Plan</th><th>Price</th><th>AI credits / day</th><th>Worlds</th><th>Subscribers</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in plans" :key="p.key">
                            <td class="text-ink">{{ p.name }}</td>
                            <td class="text-muted">{{ p.price_display }}</td>
                            <td class="text-muted">{{ p.daily_credits }}</td>
                            <td class="text-muted">{{ p.worlds }}</td>
                            <td class="text-bright">{{ p.subscribers }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Stripe configuration -->
        <form class="flex flex-col gap-6" @submit.prevent="save">
            <section class="panel p-5">
                <div class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-amber">Stripe mode</div>
                <p class="mt-1 text-sm text-muted">
                    Which key set the site uses for real billing. Sandbox is safe for testing; Live
                    charges real cards. Both key sets are stored below — switching mode never loses them.
                </p>
                <div class="mt-3 flex gap-0.5 rounded-full border border-edge3 bg-[#1a1d24] p-[3px] w-max">
                    <button
                        v-for="m in [['sandbox', 'Sandbox'], ['live', 'Live']]"
                        :key="m[0]"
                        type="button"
                        class="rounded-full px-4 py-1.5 font-mono text-[11px] uppercase tracking-[0.08em]"
                        :class="form.mode === m[0]
                            ? (m[0] === 'live' ? 'bg-[#2a1414] text-red-300' : 'bg-raised text-bright')
                            : 'text-muted hover:text-ink'"
                        @click="form.mode = m[0]"
                    >{{ m[1] }}</button>
                </div>
                <p v-if="form.mode === 'live'" class="mt-2 text-xs text-red-300">
                    Live mode is active — real payments will be processed.
                </p>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Sandbox keys -->
                <section class="panel p-5">
                    <div class="mb-3 font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint">Sandbox keys</div>
                    <div class="flex flex-col gap-3">
                        <label class="block">
                            <span class="mb-1 block text-sm text-muted">Publishable key</span>
                            <input v-model="form.test_publishable_key" class="field" placeholder="pk_test_…" autocomplete="off" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm text-muted">Secret key</span>
                            <input
                                v-model="form.test_secret_key"
                                type="password"
                                class="field"
                                autocomplete="off"
                                :placeholder="settings.test.secret_set ? '•••••••• (saved — leave blank to keep)' : 'sk_test_…'"
                            />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm text-muted">Webhook signing secret</span>
                            <input
                                v-model="form.test_webhook_secret"
                                type="password"
                                class="field"
                                autocomplete="off"
                                :placeholder="settings.test.webhook_set ? '•••••••• (saved — leave blank to keep)' : 'whsec_…'"
                            />
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block">
                                <span class="mb-1 block text-sm text-muted">Basic price ID</span>
                                <input v-model="form.test_price_basic" class="field" placeholder="price_…" autocomplete="off" />
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm text-muted">Pro price ID</span>
                                <input v-model="form.test_price_pro" class="field" placeholder="price_…" autocomplete="off" />
                            </label>
                        </div>
                    </div>
                </section>

                <!-- Live keys -->
                <section class="panel p-5">
                    <div class="mb-3 font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint">Live keys</div>
                    <div class="flex flex-col gap-3">
                        <label class="block">
                            <span class="mb-1 block text-sm text-muted">Publishable key</span>
                            <input v-model="form.live_publishable_key" class="field" placeholder="pk_live_…" autocomplete="off" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm text-muted">Secret key</span>
                            <input
                                v-model="form.live_secret_key"
                                type="password"
                                class="field"
                                autocomplete="off"
                                :placeholder="settings.live.secret_set ? '•••••••• (saved — leave blank to keep)' : 'sk_live_…'"
                            />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm text-muted">Webhook signing secret</span>
                            <input
                                v-model="form.live_webhook_secret"
                                type="password"
                                class="field"
                                autocomplete="off"
                                :placeholder="settings.live.webhook_set ? '•••••••• (saved — leave blank to keep)' : 'whsec_…'"
                            />
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block">
                                <span class="mb-1 block text-sm text-muted">Basic price ID</span>
                                <input v-model="form.live_price_basic" class="field" placeholder="price_…" autocomplete="off" />
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm text-muted">Pro price ID</span>
                                <input v-model="form.live_price_pro" class="field" placeholder="price_…" autocomplete="off" />
                            </label>
                        </div>
                    </div>
                </section>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="btn-primary" :disabled="form.processing">Save billing settings</button>
                <span class="text-xs text-faint">Secret keys are encrypted at rest and never shown again.</span>
            </div>
        </form>
    </AdminLayout>
</template>
