<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    systemOptions: { type: Array, default: () => [] },
    featureOptions: { type: Object, default: () => ({}) },
});

const form = useForm({
    name: "",
    audience: "solo",
    features: ["campaigns"],
    game_system: "D&D 5e",
    house_rules: "",
    setting: "",
    visibility: "private",
});

// The named steps of the guided tour.
const steps = [
    { key: "name", title: "Name your world" },
    { key: "audience", title: "Who's it for?" },
    { key: "features", title: "What will you use?" },
    { key: "system", title: "Which system?" },
    { key: "rules", title: "House rules" },
    { key: "seed", title: "Kickstart" },
    { key: "review", title: "Review" },
];
const step = ref(0);
const current = computed(() => steps[step.value]);
const isLast = computed(() => step.value === steps.length - 1);

const canAdvance = computed(() => {
    if (current.value.key === "name") {
        return form.name.trim().length > 0;
    }
    return true;
});

const next = () => {
    if (!canAdvance.value) {
        return;
    }
    if (!isLast.value) {
        step.value += 1;
    }
};
const back = () => {
    if (step.value > 0) {
        step.value -= 1;
    }
};

const toggleFeature = (key) => {
    form.features = form.features.includes(key)
        ? form.features.filter((f) => f !== key)
        : [...form.features, key];
};

// A shortlist of systems plus an "Other" free-text option.
const systemChoice = ref(
    props.systemOptions.includes(form.game_system) ? form.game_system : "Other",
);
const chooseSystem = (value) => {
    systemChoice.value = value;
    form.game_system = value === "Other" ? "" : value;
};

// Which step each field belongs to, so a validation error jumps back to where it can be fixed.
const FIELD_STEP = {
    name: 0,
    audience: 1,
    features: 2,
    game_system: 3,
    house_rules: 4,
    setting: 5,
};

const create = () =>
    form
        .transform((data) => ({
            ...data,
            game_system:
                systemChoice.value === "Other"
                    ? data.game_system
                    : systemChoice.value,
        }))
        .post(route("worlds.store"), {
            onError: (errors) => {
                const first = Object.keys(errors)[0]?.split(".")[0];
                step.value = FIELD_STEP[first] ?? step.value;
            },
        });
</script>

<template>
    <Head title="New world" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-2xl px-6 py-10">
            <div class="mb-6">
                <div
                    class="font-mono text-[10px] uppercase tracking-[0.2em] text-amber"
                >
                    New world
                </div>
                <h1 class="mt-1 font-display text-[32px] text-bright">
                    Let's build your world
                </h1>
            </div>

            <!-- Step progress -->
            <div class="mb-6 flex items-center gap-1.5">
                <div
                    v-for="(s, i) in steps"
                    :key="s.key"
                    class="h-1 flex-1 rounded-full transition"
                    :class="i <= step ? 'bg-amber' : 'bg-edge2'"
                />
            </div>

            <form class="panel space-y-5 p-6" @submit.prevent="next">
                <div>
                    <div class="font-mono text-[11px] text-faint">
                        Step {{ step + 1 }} of {{ steps.length }}
                    </div>
                    <h2 class="font-display text-xl text-bright">
                        {{ current.title }}
                    </h2>
                </div>

                <!-- Name -->
                <div v-if="current.key === 'name'" class="space-y-2">
                    <p class="text-sm text-muted">
                        What's the name of your world?
                    </p>
                    <input
                        v-model="form.name"
                        class="field text-lg"
                        placeholder="The Sundered Coast"
                        autofocus
                        @keydown.enter.prevent="next"
                    />
                    <p v-if="form.errors.name" class="text-sm text-red-400">
                        {{ form.errors.name }}
                    </p>
                </div>

                <!-- Audience -->
                <div v-else-if="current.key === 'audience'" class="space-y-2">
                    <p class="text-sm text-muted">
                        Is this just for you, or are you planning to invite
                        players?
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            v-for="opt in [
                                {
                                    key: 'solo',
                                    label: 'Just me',
                                    hint: 'A personal worldbuilding space.',
                                },
                                {
                                    key: 'players',
                                    label: 'With players',
                                    hint: 'Invite a party to play.',
                                },
                            ]"
                            :key="opt.key"
                            type="button"
                            class="rounded-lg border p-4 text-left transition"
                            :class="
                                form.audience === opt.key
                                    ? 'border-amber bg-amber/10'
                                    : 'border-edge3 hover:border-edge2'
                            "
                            @click="form.audience = opt.key"
                        >
                            <div class="text-sm text-ink">{{ opt.label }}</div>
                            <div class="mt-0.5 text-xs text-muted">
                                {{ opt.hint }}
                            </div>
                        </button>
                    </div>
                </div>

                <!-- Features -->
                <div v-else-if="current.key === 'features'" class="space-y-2">
                    <p class="text-sm text-muted">
                        Which features are you planning to use? (You can change
                        this later.)
                    </p>
                    <div class="flex flex-col gap-2">
                        <label
                            v-for="(label, key) in featureOptions"
                            :key="key"
                            class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition"
                            :class="
                                form.features.includes(key)
                                    ? 'border-amber bg-amber/10'
                                    : 'border-edge3 hover:border-edge2'
                            "
                        >
                            <input
                                type="checkbox"
                                class="mt-0.5"
                                :checked="form.features.includes(key)"
                                @change="toggleFeature(key)"
                            />
                            <span class="text-sm text-ink">{{ label }}</span>
                        </label>
                    </div>
                </div>

                <!-- System -->
                <div v-else-if="current.key === 'system'" class="space-y-2">
                    <p class="text-sm text-muted">What system are you using?</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="sys in [...systemOptions, 'Other']"
                            :key="sys"
                            type="button"
                            class="rounded-full border px-3 py-1.5 text-sm capitalize transition"
                            :class="
                                systemChoice === sys
                                    ? 'border-amber bg-amber/10 text-amber'
                                    : 'border-edge3 text-muted hover:text-ink'
                            "
                            @click="chooseSystem(sys)"
                        >
                            {{ sys }}
                        </button>
                    </div>
                    <input
                        v-if="systemChoice === 'Other'"
                        v-model="form.game_system"
                        class="field mt-2 text-sm"
                        placeholder="Name your system (or leave blank for custom)"
                    />
                </div>

                <!-- House rules -->
                <div v-else-if="current.key === 'rules'" class="space-y-2">
                    <p class="text-sm text-muted">
                        Any house rules? For example, how do critical hits work
                        at your table? (Optional.)
                    </p>
                    <textarea
                        v-model="form.house_rules"
                        rows="4"
                        class="field text-sm"
                        placeholder="Crits deal max damage plus a rolled die; inspiration refreshes each session…"
                    />
                </div>

                <!-- Kickstart seed -->
                <div v-else-if="current.key === 'seed'" class="space-y-2">
                    <p class="text-sm text-muted">
                        Want AI to kickstart your world? Tell us about it — key
                        locations, people, factions and tone. We'll save this as
                        your world's seed, and you can generate starter content
                        from it once the world is created. (Optional.)
                    </p>
                    <textarea
                        v-model="form.setting"
                        rows="5"
                        maxlength="8000"
                        class="field text-sm"
                        placeholder="A drowned coast of salt-pans and smuggler ports, ruled from Saltmere by Lady Merrow. The Gilded Net faction runs the black markets…"
                    />
                    <p class="text-right text-[11px] text-faint">
                        {{ form.setting.length }} / 8000
                    </p>
                </div>

                <!-- Review -->
                <div v-else-if="current.key === 'review'" class="space-y-3">
                    <p class="text-sm text-muted">
                        Ready to build
                        <span class="text-ink">{{ form.name || "…" }}</span
                        >?
                    </p>
                    <dl
                        class="grid grid-cols-[120px_1fr] gap-y-1.5 text-sm text-muted"
                    >
                        <dt class="text-faint">Audience</dt>
                        <dd class="text-ink">
                            {{
                                form.audience === "players"
                                    ? "With players"
                                    : "Just me"
                            }}
                        </dd>
                        <dt class="text-faint">System</dt>
                        <dd class="text-ink">
                            {{
                                systemChoice === "Other"
                                    ? form.game_system || "Custom"
                                    : systemChoice
                            }}
                        </dd>
                        <dt class="text-faint">Features</dt>
                        <dd class="text-ink">
                            {{
                                form.features
                                    .map((f) => featureOptions[f])
                                    .join(", ") || "—"
                            }}
                        </dd>
                        <dt class="text-faint">Seed</dt>
                        <dd class="text-ink">
                            {{ form.setting ? "Provided" : "None" }}
                        </dd>
                    </dl>
                </div>

                <!-- Any validation errors, so the wizard never silently bounces -->
                <div
                    v-if="Object.keys(form.errors).length"
                    class="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300"
                >
                    <p v-for="(message, field) in form.errors" :key="field">
                        {{ message }}
                    </p>
                </div>

                <!-- Nav -->
                <div
                    class="flex items-center justify-between border-t border-edge2 pt-4"
                >
                    <button
                        type="button"
                        class="text-sm text-faint hover:text-ink disabled:opacity-40"
                        :disabled="step === 0"
                        @click="back"
                    >
                        ← Back
                    </button>
                    <button
                        v-if="!isLast"
                        type="submit"
                        class="btn-primary"
                        :disabled="!canAdvance"
                    >
                        Continue
                    </button>
                    <button
                        v-else
                        type="button"
                        class="btn-primary"
                        :disabled="form.processing || !form.name.trim()"
                        @click="create"
                    >
                        {{ form.processing ? "Creating…" : "Create world" }}
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
