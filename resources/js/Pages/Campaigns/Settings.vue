<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import RecapGuidance from "@/Components/RecapGuidance.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { ref } from "vue";

const props = defineProps({
    world: Object,
    campaign: Object,
    players: { type: Array, default: () => [] },
    playersUrl: String,
});

const form = useForm({
    name: props.campaign.name,
    description: props.campaign.description ?? "",
    visibility: props.campaign.visibility,
    game_system: props.campaign.game_system ?? "",
});

const save = () =>
    form.put(route("campaigns.update", props.campaign.id), {
        preserveScroll: true,
    });

// Discord lives in its own form so saving the main settings never wipes a connected webhook, and
// blanking the field is an explicit "clear" (notifications then fall back to the world's webhook).
const discordForm = useForm({ discord_webhook: "" });
const saveDiscord = () =>
    discordForm.put(route("campaigns.update", props.campaign.id), {
        preserveScroll: true,
        onSuccess: () => discordForm.reset(),
    });

const facts = ref([...props.campaign.recap_facts]);
const instructions = ref([...props.campaign.recap_instructions]);
const onGuidanceUpdated = (data) => {
    facts.value = data.recap_facts ?? [];
    instructions.value = data.recap_instructions ?? [];
};

const copyPublicLink = () =>
    navigator.clipboard?.writeText(props.campaign.public_url);
const copyCode = () => navigator.clipboard?.writeText(props.campaign.code);

const destroy = () => {
    if (
        confirm(
            `Delete campaign “${props.campaign.name}”? This removes its sessions and rooms.`,
        )
    ) {
        router.delete(route("campaigns.destroy", props.campaign.id));
    }
};
</script>

<template>
    <Head :title="`Settings — ${campaign.name}`" />

    <WorldLayout :world="world">
        <div class="mx-auto w-full max-w-3xl space-y-6">
            <div>
                <Link
                    :href="
                        route('campaigns.show', [
                            campaign.world_id,
                            campaign.id,
                        ])
                    "
                    class="font-mono text-[10px] uppercase tracking-[0.2em] text-amber hover:text-amber/80"
                    >← {{ campaign.name }}</Link
                >
                <h1 class="mt-1 font-display text-[28px] text-bright">
                    Campaign settings
                </h1>
            </div>

            <form class="space-y-6" @submit.prevent="save">
                <!-- Identity -->
                <section class="panel space-y-3 p-4">
                    <h2 class="font-display text-lg text-bright">Identity</h2>
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint">Name</span>
                        <input v-model="form.name" class="field" />
                        <span
                            v-if="form.errors.name"
                            class="mt-1 block text-xs text-red-400"
                            >{{ form.errors.name }}</span
                        >
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Description</span
                        >
                        <textarea
                            v-model="form.description"
                            rows="2"
                            class="field"
                        />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Game system</span
                        >
                        <input
                            v-model="form.game_system"
                            class="field !w-auto"
                            :placeholder="
                                campaign.world_game_system ||
                                'Inherit world default'
                            "
                        />
                    </label>
                </section>

                <!-- Visibility & access -->
                <section class="panel space-y-3 p-4">
                    <h2 class="font-display text-lg text-bright">
                        Visibility &amp; access
                    </h2>
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Reader visibility</span
                        >
                        <select v-model="form.visibility" class="field !w-auto">
                            <option value="private">
                                Private — members only
                            </option>
                            <option value="hidden">
                                Unlisted — anyone with the link
                            </option>
                            <option value="public">
                                Public — listed in the reader
                            </option>
                        </select>
                    </label>
                    <div
                        v-if="form.visibility !== 'private'"
                        class="flex items-center gap-2"
                    >
                        <code
                            class="truncate rounded bg-raised px-2 py-1 font-mono text-[11px] text-muted"
                            >{{ campaign.public_url }}</code
                        >
                        <button
                            type="button"
                            class="rounded border border-edge3 px-2 py-1 text-[11px] text-muted hover:border-teal hover:text-teal"
                            @click="copyPublicLink"
                        >
                            Copy
                        </button>
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <span class="text-faint">Invite code</span>
                        <code
                            class="rounded bg-raised px-2 py-1 font-mono text-[12px] text-amber"
                            >{{ campaign.code }}</code
                        >
                        <button
                            type="button"
                            class="text-[11px] text-faint hover:text-teal"
                            @click="copyCode"
                        >
                            Copy
                        </button>
                    </div>
                </section>

                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? "Saving…" : "Save settings" }}
                    </button>
                    <span
                        v-if="form.recentlySuccessful"
                        class="text-xs text-teal"
                        >Saved.</span
                    >
                </div>
            </form>

            <!-- Discord -->
            <form class="panel space-y-3 p-4" @submit.prevent="saveDiscord">
                <h2 class="font-display text-lg text-bright">Discord</h2>
                <p class="text-xs text-muted">
                    Announce this campaign's scheduled sessions and published
                    recaps to a Discord channel. Leave blank to use the world's
                    webhook<span v-if="!campaign.world_discord_connected">
                        (none set)</span
                    >.
                </p>
                <label class="block">
                    <span class="mb-1 block text-xs text-faint">
                        Discord webhook URL
                        <span
                            v-if="campaign.discord_connected"
                            class="text-teal"
                            >· connected</span
                        >
                    </span>
                    <input
                        v-model="discordForm.discord_webhook"
                        class="field"
                        :placeholder="
                            campaign.discord_connected
                                ? 'Connected — enter a new URL to replace, or leave blank and save to remove'
                                : 'https://discord.com/api/webhooks/…'
                        "
                    />
                    <span
                        v-if="discordForm.errors.discord_webhook"
                        class="mt-1 block text-xs text-red-400"
                        >{{ discordForm.errors.discord_webhook }}</span
                    >
                </label>
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="discordForm.processing"
                    >
                        {{
                            discordForm.processing ? "Saving…" : "Save Discord"
                        }}
                    </button>
                    <span
                        v-if="discordForm.recentlySuccessful"
                        class="text-xs text-teal"
                        >Saved.</span
                    >
                </div>
            </form>

            <!-- Recap guidance -->
            <RecapGuidance
                :campaign-id="campaign.id"
                :facts="facts"
                :instructions="instructions"
                @updated="onGuidanceUpdated"
            />

            <!-- Players -->
            <section class="panel space-y-3 p-4">
                <div class="flex items-center justify-between">
                    <h2 class="font-display text-lg text-bright">Players</h2>
                    <Link
                        :href="playersUrl"
                        class="text-xs text-amber hover:underline"
                        >Manage &amp; invite →</Link
                    >
                </div>
                <div v-if="players.length" class="flex flex-col gap-1.5">
                    <div
                        v-for="p in players"
                        :key="p.id"
                        class="flex items-center justify-between rounded border border-edge2 px-3 py-1.5 text-sm"
                    >
                        <span class="text-ink">{{ p.name }}</span>
                        <span
                            class="font-mono text-[10px] uppercase tracking-wider text-faint"
                            >{{ p.role }}</span
                        >
                    </div>
                </div>
                <p v-else class="text-xs text-faint">No players yet.</p>
            </section>

            <!-- Danger zone -->
            <section
                class="rounded-lg border border-red-500/30 bg-red-500/5 p-4"
            >
                <h2 class="font-display text-lg text-red-300">Danger zone</h2>
                <div class="mt-2 flex items-center justify-between gap-3">
                    <p class="text-xs text-muted">
                        Permanently delete this campaign, its sessions and
                        rooms.
                    </p>
                    <button
                        class="shrink-0 rounded border border-red-500/40 px-3 py-1.5 text-sm text-red-300 hover:bg-red-500/10"
                        @click="destroy"
                    >
                        Delete campaign
                    </button>
                </div>
            </section>
        </div>
    </WorldLayout>
</template>
