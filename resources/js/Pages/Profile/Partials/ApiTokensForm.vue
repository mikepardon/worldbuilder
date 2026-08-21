<script setup>
import { captureError } from "@/monitoring";
import { ref } from "vue";

const props = defineProps({
    apiTokens: { type: Array, default: () => [] },
    mcpUrl: { type: String, default: "" },
});

const tokens = ref([...props.apiTokens]);
const name = ref("");
const busy = ref(false);
const error = ref("");
// The plain-text token is returned once, on creation, and shown here until the user dismisses it.
const freshToken = ref("");

async function create() {
    if (!name.value.trim()) {
        error.value = "Give the token a name so you can recognise it later.";
        return;
    }
    busy.value = true;
    error.value = "";
    try {
        const res = await window.axios.post(route("profile.api-tokens.store"), {
            name: name.value,
        });
        tokens.value.unshift(res.data.accessToken);
        freshToken.value = res.data.token;
        name.value = "";
    } catch (e) {
        captureError(e);
        error.value =
            e?.response?.data?.message ||
            "Couldn't create the token — please try again.";
    } finally {
        busy.value = false;
    }
}

async function revoke(token) {
    if (!confirm(`Revoke the token “${token.name}”? Any client using it will stop working.`)) {
        return;
    }
    try {
        await window.axios.delete(
            route("profile.api-tokens.destroy", token.id),
        );
        tokens.value = tokens.value.filter((t) => t.id !== token.id);
    } catch (e) {
        captureError(e);
        error.value = "Couldn't revoke the token — please try again.";
    }
}
</script>

<template>
    <section>
        <header>
            <h2 class="font-display text-lg font-medium text-ink">
                MCP access tokens
            </h2>
            <p class="mt-1 text-sm text-muted">
                Personal access tokens let an AI client (e.g. Claude Desktop)
                connect to your worlds through the Model Context Protocol. Point
                the client at
                <code class="rounded bg-night/60 px-1 text-ink">{{ mcpUrl }}</code>
                and authenticate with the token as a Bearer credential. Tokens
                act as you and are scoped to worlds you own or co-author.
            </p>
        </header>

        <!-- The one-time reveal of a freshly created token. -->
        <div
            v-if="freshToken"
            class="mt-4 rounded border border-amber/40 bg-amber/10 p-3"
        >
            <p class="text-xs text-muted">
                Copy this token now — it won't be shown again.
            </p>
            <div class="mt-1 flex items-center gap-2">
                <code class="flex-1 break-all text-sm text-ink">{{ freshToken }}</code>
                <button
                    type="button"
                    class="shrink-0 text-xs text-amber hover:underline"
                    @click="freshToken = ''"
                >
                    Done
                </button>
            </div>
        </div>

        <form class="mt-4 flex flex-wrap items-end gap-2" @submit.prevent="create">
            <input
                v-model="name"
                type="text"
                placeholder="Token name (e.g. “Claude Desktop”)"
                class="field flex-1 !py-2 text-sm"
            />
            <button type="submit" class="btn-primary" :disabled="busy">
                {{ busy ? "Creating…" : "Create token" }}
            </button>
        </form>
        <p v-if="error" class="mt-2 text-sm text-red-400">{{ error }}</p>

        <ul v-if="tokens.length" class="mt-4 space-y-2">
            <li
                v-for="token in tokens"
                :key="token.id"
                class="flex items-center justify-between gap-3 rounded border border-edge2 px-3 py-2 text-sm"
            >
                <div class="min-w-0">
                    <div class="truncate text-ink">{{ token.name }}</div>
                    <div class="text-xs text-faint">
                        Created {{ token.created_at }} ·
                        {{
                            token.last_used_at
                                ? `last used ${token.last_used_at}`
                                : "never used"
                        }}
                    </div>
                </div>
                <button
                    type="button"
                    class="shrink-0 text-xs text-faint hover:text-red-400"
                    @click="revoke(token)"
                >
                    Revoke
                </button>
            </li>
        </ul>
        <p v-else class="mt-4 text-sm text-faint">No tokens yet.</p>
    </section>
</template>
