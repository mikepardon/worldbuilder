<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import mjml2html from 'mjml-browser';
import { computed, ref } from 'vue';

const props = defineProps({
    template: { type: Object, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

// Editor state
const tab = ref('source'); // 'source' | 'preview'
const previewHtml = ref('');
const previewError = ref('');

const form = useForm({
    display_name: props.template.display_name,
    subject: props.template.subject,
    description: props.template.description ?? '',
    mjml: props.template.mjml ?? '',
    html: props.template.html ?? '',
    from_name: props.template.from_name ?? '',
    from_mailbox: props.template.from_mailbox ?? '',
    reply_to_name: props.template.reply_to_name ?? '',
    reply_to_mailbox: props.template.reply_to_mailbox ?? '',
    reference: props.template.reference,
});

const compileAndPreview = () => {
    previewError.value = '';
    try {
        const result = mjml2html(form.mjml, { validationLevel: 'soft' });
        if (result.errors.length) {
            previewError.value = result.errors.map((e) => e.formattedMessage).join('\n');
        }
        previewHtml.value = result.html;
        form.html = result.html;
        tab.value = 'preview';
    } catch (err) {
        previewError.value = String(err);
    }
};

const save = () => {
    // Compile before saving so html is always up-to-date with the current mjml
    if (form.mjml) {
        try {
            const result = mjml2html(form.mjml, { validationLevel: 'soft' });
            form.html = result.html;
        } catch {
            // Let the server save whatever HTML was last compiled; don't block saving
        }
    }
    form.put(route('admin.mail.templates.update', props.template.id), {
        preserveScroll: true,
    });
};

// Test email
const testForm = useForm({ address: '', subject: '', html: '' });
const showTestPanel = ref(false);

const sendTest = () => {
    testForm.subject = form.subject;
    testForm.html = form.html;
    testForm.post(route('admin.mail.templates.test', props.template.id), {
        preserveScroll: true,
        onSuccess: () => (showTestPanel.value = false),
    });
};

// AI generation
const showAiPanel = ref(false);
const aiBrief = ref('');
const aiLoading = ref(false);
const aiError = ref('');

const generateWithAi = async () => {
    aiError.value = '';
    aiLoading.value = true;
    try {
        const response = await fetch(route('admin.mail.templates.generate', props.template.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                Accept: 'application/json',
            },
            body: JSON.stringify({ brief: aiBrief.value }),
        });
        const data = await response.json();
        if (!response.ok) {
            aiError.value = data.message ?? 'Generation failed.';
            return;
        }
        form.mjml = data.mjml;
        form.subject = data.subject;
        showAiPanel.value = false;
        aiBrief.value = '';
    } catch (err) {
        aiError.value = String(err);
    } finally {
        aiLoading.value = false;
    }
};

// Merge field insertion into subject
const mergeFields = ['name', 'email', 'unsubscribe_url'];
const mergeTag = (field) => '{{ ' + field + ' }}';
const insertMerge = (field) => {
    form.subject += mergeTag(field);
};
</script>

<template>
    <Head :title="`${template.display_name} · Email templates · Admin`" />

    <AdminLayout>
        <div class="flex items-center justify-between gap-4">
            <div>
                <Link :href="route('admin.mail.templates.index')" class="eyebrow-muted hover:text-ink">← Templates</Link>
                <h1 class="font-display text-2xl text-bright">{{ template.display_name }}</h1>
            </div>
            <div class="flex gap-2">
                <button class="btn-secondary text-sm" @click="showTestPanel = !showTestPanel">Send test</button>
                <button class="btn-primary text-sm" :disabled="form.processing" @click="save">Save</button>
            </div>
        </div>

        <div v-if="flash.success" class="rounded-lg bg-emerald-900/30 px-4 py-3 text-sm text-emerald-400">
            {{ flash.success }}
        </div>
        <div v-if="Object.keys(form.errors).length" class="rounded-lg bg-red-900/30 px-4 py-3 text-sm text-red-400">
            <div v-for="(error, field) in form.errors" :key="field">{{ error }}</div>
        </div>

        <!-- Test email panel -->
        <div v-if="showTestPanel" class="panel p-4">
            <p class="eyebrow-muted mb-3">Send test email</p>
            <div class="flex flex-wrap items-end gap-3">
                <label class="flex-1">
                    <span class="text-xs text-muted">Recipient address</span>
                    <input v-model="testForm.address" type="email" class="field mt-1" placeholder="you@example.com" />
                </label>
                <button class="btn-primary text-sm" :disabled="testForm.processing" @click="sendTest">Send</button>
            </div>
            <p class="mt-2 text-xs text-faint">Uses the currently saved HTML and sample merge variables.</p>
        </div>

        <!-- AI generation panel -->
        <div v-if="showAiPanel" class="panel p-4">
            <p class="eyebrow-muted mb-3">Generate with AI</p>
            <textarea v-model="aiBrief" class="field min-h-[80px] w-full" placeholder="Describe the email: audience, goal, tone, key details…" />
            <p v-if="aiError" class="mt-2 text-xs text-red-400">{{ aiError }}</p>
            <div class="mt-3 flex gap-2">
                <button class="btn-primary text-sm" :disabled="aiLoading || !aiBrief.trim()" @click="generateWithAi">
                    {{ aiLoading ? 'Generating…' : 'Generate' }}
                </button>
                <button class="btn-secondary text-sm" @click="showAiPanel = false">Cancel</button>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
            <!-- Left: MJML editor -->
            <div class="flex flex-col gap-4">
                <div class="panel overflow-hidden">
                    <div class="flex items-center justify-between border-b border-edge2 px-4 py-2">
                        <div class="flex gap-1">
                            <button
                                class="rounded px-3 py-1 text-sm"
                                :class="tab === 'source' ? 'bg-[#1e222a] text-bright' : 'text-muted hover:text-ink'"
                                @click="tab = 'source'"
                            >
                                MJML
                            </button>
                            <button
                                class="rounded px-3 py-1 text-sm"
                                :class="tab === 'preview' ? 'bg-[#1e222a] text-bright' : 'text-muted hover:text-ink'"
                                @click="compileAndPreview"
                            >
                                Preview
                            </button>
                        </div>
                        <button class="text-xs text-faint hover:text-amber" @click="showAiPanel = !showAiPanel">✦ Generate</button>
                    </div>

                    <div v-if="tab === 'source'" class="p-0">
                        <textarea
                            v-model="form.mjml"
                            class="min-h-[480px] w-full resize-y bg-transparent p-4 font-mono text-[13px] text-ink outline-none"
                            placeholder="<mjml>…</mjml>"
                            spellcheck="false"
                        />
                        <p v-if="previewError" class="border-t border-edge2 px-4 py-2 font-mono text-xs text-red-400">{{ previewError }}</p>
                    </div>

                    <div v-if="tab === 'preview'" class="p-0">
                        <iframe
                            v-if="previewHtml"
                            :srcdoc="previewHtml"
                            class="h-[600px] w-full border-0 bg-white"
                            sandbox="allow-same-origin"
                        />
                        <p v-else class="p-4 text-sm text-faint">No preview — click Preview to compile.</p>
                    </div>
                </div>
            </div>

            <!-- Right: metadata -->
            <div class="flex flex-col gap-4">
                <div class="panel flex flex-col gap-4 p-4">
                    <p class="eyebrow-muted">Details</p>

                    <label>
                        <span class="text-xs text-muted">Display name</span>
                        <input v-model="form.display_name" class="field mt-1 w-full" />
                    </label>

                    <label>
                        <span class="text-xs text-muted">Subject line</span>
                        <input v-model="form.subject" class="field mt-1 w-full" />
                        <div class="mt-1 flex flex-wrap gap-1">
                            <button
                                v-for="field in mergeFields"
                                :key="field"
                                class="rounded bg-[#1e222a] px-1.5 py-0.5 font-mono text-[10px] text-faint hover:text-ink"
                                @click="insertMerge(field)"
                            >
                                {{ mergeTag(field) }}
                            </button>
                        </div>
                    </label>

                    <label>
                        <span class="text-xs text-muted">Description</span>
                        <textarea v-model="form.description" class="field mt-1 w-full" rows="2" />
                    </label>

                    <label>
                        <span class="text-xs text-muted">Reference slug</span>
                        <input v-model="form.reference" class="field mt-1 w-full font-mono text-sm" />
                    </label>
                </div>

                <div class="panel flex flex-col gap-4 p-4">
                    <p class="eyebrow-muted">Sender</p>

                    <label>
                        <span class="text-xs text-muted">From name</span>
                        <input v-model="form.from_name" class="field mt-1 w-full" placeholder="Worldbuilder" />
                    </label>
                    <label>
                        <span class="text-xs text-muted">From email</span>
                        <input v-model="form.from_mailbox" type="email" class="field mt-1 w-full" placeholder="noreply@worldbuilder.pro" />
                    </label>
                    <label>
                        <span class="text-xs text-muted">Reply-to name</span>
                        <input v-model="form.reply_to_name" class="field mt-1 w-full" />
                    </label>
                    <label>
                        <span class="text-xs text-muted">Reply-to email</span>
                        <input v-model="form.reply_to_mailbox" type="email" class="field mt-1 w-full" />
                    </label>
                </div>

                <button class="btn-primary w-full" :disabled="form.processing" @click="save">Save template</button>
            </div>
        </div>
    </AdminLayout>
</template>
