<script setup>
import { Head, useForm } from "@inertiajs/vue3";

const props = defineProps({
    world: Object,
});

const form = useForm({ password: "" });
const submit = () =>
    form.post(route("public.world.unlock", props.world.slug), {
        onFinish: () => form.reset("password"),
    });
</script>

<template>
    <Head :title="world.name">
        <meta head-key="robots" name="robots" content="noindex, nofollow" />
    </Head>

    <div
        class="flex min-h-screen items-center justify-center bg-night px-6 font-serif text-ink"
    >
        <div class="w-full max-w-sm">
            <div class="mb-6 text-center">
                <div
                    class="mb-2 font-mono text-[10px] uppercase tracking-[0.24em] text-teal"
                >
                    Protected world
                </div>
                <h1 class="font-display text-2xl text-bright">
                    {{ world.name }}
                </h1>
                <p class="mt-2 text-sm text-muted">
                    This world is password-protected. Enter the password to read
                    on.
                </p>
            </div>

            <form
                class="rounded-lg border border-edge2 bg-surface p-4"
                @submit.prevent="submit"
            >
                <label class="block">
                    <span class="mb-1 block text-xs text-faint">Password</span>
                    <input
                        v-model="form.password"
                        type="password"
                        autofocus
                        class="field"
                    />
                    <span
                        v-if="form.errors.password"
                        class="mt-1 block text-xs text-red-400"
                        >{{ form.errors.password }}</span
                    >
                </label>
                <button
                    type="submit"
                    class="btn-primary mt-3 w-full"
                    :disabled="form.processing || !form.password"
                >
                    Unlock
                </button>
            </form>
        </div>
    </div>
</template>
