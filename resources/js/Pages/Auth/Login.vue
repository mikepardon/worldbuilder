<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), { onFinish: () => form.reset('password') });
};
</script>

<template>
    <Head title="Sign in" />

    <div class="grid min-h-screen bg-night lg:grid-cols-[1fr_520px]">
        <!-- Splash / marketing -->
        <div class="relative hidden items-end p-14 lg:flex" style="background: repeating-linear-gradient(48deg, #1c1f27 0 11px, #191c23 11px 22px)">
            <div class="absolute inset-0" style="background: linear-gradient(120deg, rgba(14,16,20,.35), rgba(14,16,20,.9))"></div>
            <div class="relative flex max-w-[520px] flex-col gap-4" style="animation: rise .6s ease both">
                <div class="font-mono text-[10px] uppercase tracking-[0.22em] text-amber">Keep the whole world in one place</div>
                <div class="font-display text-[52px] leading-[1.04] text-bright">Every campaign has a memory. Yours should be searchable.</div>
                <div class="text-[18px] font-light italic leading-[1.55] text-[#b8bcc4]">
                    Write once, reveal on your schedule. Players see the world they've earned — and keep their own notes beside it.
                </div>
            </div>
        </div>

        <!-- Sign-in -->
        <div class="flex flex-col justify-center gap-7 border-l border-edge2 bg-card px-13 py-14" style="padding-left: 52px; padding-right: 52px">
            <div class="flex items-center gap-3">
                <div class="h-[26px] w-[26px] rounded-[5px]" style="background: linear-gradient(140deg, #e0a33e, #8a5a1c)"></div>
                <div class="font-mono text-[12px] tracking-[0.24em] text-ink">WORLDBUILDER</div>
            </div>

            <div class="flex flex-col gap-1.5">
                <div class="font-display text-[32px] text-bright">Sign in</div>
                <div class="text-[15.5px] font-light leading-[1.55] text-muted">Welcome back. The archive kept your place.</div>
            </div>

            <div v-if="status" class="text-sm font-medium text-teal">{{ status }}</div>

            <form class="flex flex-col gap-3.5" @submit.prevent="submit">
                <label class="flex flex-col gap-1.5">
                    <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-faint">Email</span>
                    <input v-model="form.email" type="email" required autofocus placeholder="you@table.com" class="rounded-[7px] border border-edge3 bg-[#1a1d24] px-3.5 py-3 text-[15.5px] text-ink focus:border-teal focus:ring-0" />
                    <span v-if="form.errors.email" class="text-sm text-red-400">{{ form.errors.email }}</span>
                </label>
                <label class="flex flex-col gap-1.5">
                    <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-faint">Password</span>
                    <input v-model="form.password" type="password" required placeholder="••••••••" class="rounded-[7px] border border-edge3 bg-[#1a1d24] px-3.5 py-3 text-[15.5px] text-ink focus:border-teal focus:ring-0" />
                    <span v-if="form.errors.password" class="text-sm text-red-400">{{ form.errors.password }}</span>
                </label>

                <button type="submit" :disabled="form.processing" class="mt-0.5 rounded-[7px] bg-amber py-3 text-center font-display text-[16px] text-night transition hover:bg-[#eab255] disabled:opacity-50">
                    Sign in
                </button>

                <div class="flex justify-between text-sm text-faint">
                    <Link v-if="canResetPassword" :href="route('password.request')" class="hover:text-teal">Forgot password</Link>
                    <Link :href="route('register')" class="hover:text-teal">Create an account</Link>
                </div>
            </form>
        </div>
    </div>
</template>
