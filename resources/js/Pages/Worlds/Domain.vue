<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    world: Object,
    domain: { type: String, default: null },
    verified: { type: Boolean, default: false },
    targetIp: { type: String, default: "" },
    canUseCustomDomain: { type: Boolean, default: false },
});

const form = useForm({ custom_domain: props.domain || "" });
const save = () => form.put(route("worlds.domain.update", props.world.id), { preserveScroll: true });

const verifying = ref(false);
const verify = () => {
    verifying.value = true;
    router.post(
        route("worlds.domain.verify", props.world.id),
        {},
        { preserveScroll: true, onFinish: () => (verifying.value = false) },
    );
};

const remove = () => {
    if (!window.confirm("Remove this custom domain? Your world stays available at its normal address."))
        return;
    router.delete(route("worlds.domain.destroy", props.world.id), { preserveScroll: true });
};

const status = computed(() => {
    if (!props.domain) return { label: "Not set", tone: "faint" };
    if (props.verified) return { label: "Connected", tone: "teal" };
    return { label: "Pending verification", tone: "amber" };
});
</script>

<template>
    <Head title="Custom domain" />

    <WorldLayout :world="world">
        <div class="max-w-2xl">
            <div class="mb-6 flex items-center justify-between">
                <h1 class="font-display text-[26px] text-bright">Custom domain</h1>
                <span
                    class="rounded-full px-3 py-1 font-mono text-[10px] uppercase tracking-widest"
                    :class="{
                        'bg-teal/15 text-teal': status.tone === 'teal',
                        'bg-amber/15 text-amber': status.tone === 'amber',
                        'bg-raised text-faint': status.tone === 'faint',
                    }"
                >
                    {{ status.label }}
                </span>
            </div>

            <!-- Upsell for plans without the entitlement -->
            <div
                v-if="!canUseCustomDomain"
                class="panel border-amber/30 p-5"
            >
                <div class="font-display text-lg text-bright">Available on Basic and Pro</div>
                <p class="mt-1 text-sm text-muted">
                    Publish your world on your own domain — like <code class="rounded bg-raised px-1 text-ink">world.example.com</code> —
                    on a paid plan.
                </p>
                <Link :href="route('billing.index')" class="btn-primary mt-4">See plans</Link>
            </div>

            <template v-else>
                <p class="mb-5 text-sm text-muted">
                    Serve your world's player-facing site from your own domain. Enter it below, point its
                    DNS at us with an A record, then verify.
                </p>

                <!-- 1. Domain -->
                <form class="panel p-5" @submit.prevent="save">
                    <label class="block">
                        <span class="mb-1 block text-sm text-muted">Your domain</span>
                        <input
                            v-model="form.custom_domain"
                            class="field"
                            placeholder="world.example.com"
                            autocomplete="off"
                        />
                    </label>
                    <p v-if="form.errors.custom_domain" class="mt-2 text-sm text-red-400">
                        {{ form.errors.custom_domain }}
                    </p>
                    <div class="mt-4 flex items-center gap-3">
                        <button type="submit" class="btn-primary" :disabled="form.processing || !form.custom_domain">
                            Save domain
                        </button>
                        <button
                            v-if="domain"
                            type="button"
                            class="text-sm text-faint hover:text-red-400"
                            @click="remove"
                        >
                            Remove
                        </button>
                    </div>
                </form>

                <!-- 2. DNS + verify (once a domain is saved) -->
                <div v-if="domain" class="panel mt-4 p-5">
                    <div class="eyebrow-muted mb-3">Point your DNS</div>
                    <p class="text-sm text-muted">
                        At your domain registrar, create this record for
                        <code class="rounded bg-raised px-1 text-ink">{{ domain }}</code>:
                    </p>

                    <div v-if="targetIp" class="mt-3 overflow-x-auto rounded-md border border-edge2">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-edge2 text-left font-mono text-[10px] uppercase tracking-wider text-faint">
                                    <th class="px-3 py-2">Type</th>
                                    <th class="px-3 py-2">Name / Host</th>
                                    <th class="px-3 py-2">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="text-ink">
                                    <td class="px-3 py-2 font-mono">A</td>
                                    <td class="px-3 py-2 font-mono">{{ domain }}</td>
                                    <td class="px-3 py-2 font-mono">{{ targetIp }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="mt-3 rounded-md border border-amber/30 bg-amber/10 px-3 py-2 text-sm text-amber">
                        Custom domains aren't configured on this server yet — ask an administrator to set the target IP.
                    </p>

                    <div class="mt-4 flex items-center gap-3">
                        <button
                            type="button"
                            class="btn-primary"
                            :disabled="verifying || !targetIp"
                            @click="verify"
                        >
                            {{ verifying ? "Checking DNS…" : verified ? "Re-check" : "Verify connection" }}
                        </button>
                        <span v-if="verified" class="text-sm text-teal">✓ Connected — your world is live at this domain.</span>
                        <span v-else class="text-sm text-faint">DNS changes can take up to a few hours to propagate.</span>
                    </div>
                </div>
            </template>
        </div>
    </WorldLayout>
</template>
