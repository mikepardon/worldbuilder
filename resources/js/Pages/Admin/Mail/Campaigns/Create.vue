<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    templates: { type: Array, default: () => [] },
    subscriberCount: { type: Number, default: 0 },
});

const form = useForm({
    email_template_id: '',
    subject: '',
    scheduled_at: '',
    send_now: true,
});

const selectedTemplate = computed(() =>
    props.templates.find((t) => String(t.id) === String(form.email_template_id)) ?? null,
);

watch(selectedTemplate, (template) => {
    if (template && !form.subject) {
        form.subject = template.subject;
    }
});

const send = () =>
    form.post(route('admin.mail.campaigns.store'));
</script>

<template>
    <Head title="New campaign · Admin" />

    <AdminLayout>
        <div class="flex items-center justify-between gap-4">
            <div>
                <Link :href="route('admin.mail.campaigns.index')" class="eyebrow-muted hover:text-ink">← Campaigns</Link>
                <h1 class="font-display text-2xl text-bright">New campaign</h1>
            </div>
        </div>

        <div class="mx-auto max-w-lg">
            <div class="panel flex flex-col gap-5 p-6">
                <div v-if="Object.keys(form.errors).length" class="rounded-lg bg-red-900/30 px-4 py-3 text-sm text-red-400">
                    <div v-for="(error, field) in form.errors" :key="field">{{ error }}</div>
                </div>

                <label>
                    <span class="eyebrow-muted">Template</span>
                    <select v-model="form.email_template_id" class="field mt-1 w-full" required>
                        <option value="" disabled>Select a template…</option>
                        <option
                            v-for="template in templates"
                            :key="template.id"
                            :value="template.id"
                            :disabled="!template.has_html"
                        >
                            {{ template.display_name }}{{ !template.has_html ? ' (no HTML — save in editor first)' : '' }}
                        </option>
                    </select>
                </label>

                <label>
                    <span class="eyebrow-muted">Subject line</span>
                    <input v-model="form.subject" class="field mt-1 w-full" required placeholder="Your subject line" />
                </label>

                <div class="rounded-lg bg-[#1e222a] px-4 py-3">
                    <p class="text-sm text-muted">Audience: <span class="text-bright">{{ subscriberCount }} subscribed</span></p>
                </div>

                <fieldset class="flex flex-col gap-2">
                    <legend class="eyebrow-muted mb-1">When to send</legend>
                    <label class="flex items-center gap-2 text-sm text-muted">
                        <input v-model="form.send_now" type="radio" :value="true" class="accent-amber-400" />
                        Send immediately
                    </label>
                    <label class="flex items-center gap-2 text-sm text-muted">
                        <input v-model="form.send_now" type="radio" :value="false" class="accent-amber-400" />
                        Schedule for later
                    </label>
                </fieldset>

                <label v-if="!form.send_now">
                    <span class="eyebrow-muted">Scheduled time</span>
                    <input v-model="form.scheduled_at" type="datetime-local" class="field mt-1 w-full" />
                </label>

                <div class="flex gap-3 pt-2">
                    <button class="btn-primary flex-1" :disabled="form.processing || !form.email_template_id || !form.subject" @click="send">
                        {{ form.send_now ? 'Send now' : 'Schedule' }}
                    </button>
                    <Link :href="route('admin.mail.campaigns.index')" class="btn-secondary">Cancel</Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
