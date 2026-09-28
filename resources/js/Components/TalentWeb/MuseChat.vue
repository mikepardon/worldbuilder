<script setup>
import { router } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const props = defineProps({
    web: { type: Object, required: true },
});

const messages = ref([]);
const proposals = ref([]);
const input = ref('');
const loading = ref(false);
const error = ref('');
const credits = ref(null);
const scroller = ref(null);

const scrollDown = () => nextTick(() => scroller.value?.scrollTo({ top: scroller.value.scrollHeight }));

const send = async () => {
    const prompt = input.value.trim();
    if (!prompt || loading.value) {
        return;
    }
    error.value = '';
    messages.value.push({ role: 'user', content: prompt });
    input.value = '';
    loading.value = true;
    scrollDown();

    try {
        const history = messages.value.slice(0, -1).slice(-20);
        const { data } = await window.axios.post(route('talent-ai.chat', props.web.id), { prompt, history });
        messages.value.push({ role: 'assistant', content: data.reply || '(no reply)' });
        proposals.value = data.nodes ?? [];
        credits.value = data.creditsRemaining ?? credits.value;
    } catch (exception) {
        error.value = exception.response?.data?.message ?? 'Something went wrong talking to Muse.';
    } finally {
        loading.value = false;
        scrollDown();
    }
};

const apply = () => {
    router.post(
        route('talent-ai.apply', props.web.id),
        { nodes: proposals.value },
        { preserveScroll: true, preserveState: true, onSuccess: () => (proposals.value = []) },
    );
};
</script>

<template>
    <div class="panel flex h-full flex-col p-3">
        <div class="mb-2 flex items-center justify-between">
            <p class="eyebrow-muted">✨ Muse — node assistant</p>
            <span v-if="credits !== null" class="font-mono text-[10px] text-faint">{{ credits }} credits</span>
        </div>

        <div ref="scroller" class="flex-1 space-y-2 overflow-auto pr-1">
            <p v-if="!messages.length" class="text-xs text-faint">
                Ask Muse to design part of this web — e.g. “add a 4-node fire mage branch off the origin”. It costs one AI credit per message.
            </p>
            <div
                v-for="(message, index) in messages"
                :key="index"
                class="rounded p-2 text-sm"
                :class="message.role === 'user' ? 'bg-raised text-ink' : 'bg-amber/10 text-ink'"
            >
                <span class="mb-0.5 block font-mono text-[9px] uppercase tracking-widest text-faint">
                    {{ message.role === 'user' ? 'You' : 'Muse' }}
                </span>
                {{ message.content }}
            </div>
            <p v-if="loading" class="text-xs text-faint">Muse is thinking…</p>
        </div>

        <!-- Proposed nodes -->
        <div v-if="proposals.length" class="mt-2 rounded border border-amber/40 bg-amber/5 p-2">
            <p class="mb-1 font-mono text-[10px] uppercase tracking-widest text-amber">
                {{ proposals.length }} proposed node{{ proposals.length === 1 ? '' : 's' }}
            </p>
            <ul class="mb-2 max-h-28 space-y-0.5 overflow-auto">
                <li v-for="(node, index) in proposals" :key="index" class="text-xs text-muted">
                    <span class="text-ink">{{ node.name }}</span>
                    <span class="text-faint"> · {{ node.kind }} · {{ node.cost }} pt</span>
                </li>
            </ul>
            <div class="flex gap-2">
                <button class="btn-primary flex-1 !py-1.5 text-xs" @click="apply">Add to web</button>
                <button class="btn-ghost !py-1.5 text-xs" @click="proposals = []">Dismiss</button>
            </div>
        </div>

        <p v-if="error" class="mt-2 rounded border border-blood/50 bg-blood/10 p-2 text-xs text-ink">{{ error }}</p>

        <form class="mt-2 flex gap-2" @submit.prevent="send">
            <input v-model="input" class="field !py-1.5 text-sm" placeholder="Ask Muse…" :disabled="loading" />
            <button class="btn-teal !py-1.5 text-xs" type="submit" :disabled="loading || !input.trim()">Send</button>
        </form>
    </div>
</template>
