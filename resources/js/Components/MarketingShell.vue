<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const { canLogin = false, canRegister = false } = defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const user = computed(() => usePage().props.auth?.user);
</script>

<template>
    <div class="min-h-screen bg-[#0e1014] font-serif text-[#e8e3d9]">
        <header class="border-b border-[#1e222a]">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <Link :href="route('home')" class="font-display text-xl text-amber">Worldbuilder</Link>
                <nav class="flex items-center gap-5 text-sm">
                    <Link :href="route('marketing.how')" class="text-[#9aa0ab] hover:text-[#e8e3d9]">How it works</Link>
                    <Link :href="route('marketing.features')" class="text-[#9aa0ab] hover:text-[#e8e3d9]">Features</Link>
                    <Link :href="route('marketing.pricing')" class="text-[#9aa0ab] hover:text-[#e8e3d9]">Pricing</Link>
                    <template v-if="user">
                        <Link
                            :href="route('dashboard')"
                            class="rounded-md bg-amber-600 px-4 py-2 font-medium text-white hover:bg-amber-700"
                        >
                            My dashboard
                        </Link>
                    </template>
                    <template v-else>
                        <Link v-if="canLogin" :href="route('login')" class="text-[#9aa0ab] hover:text-[#e8e3d9]">Log in</Link>
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="rounded-md bg-amber-600 px-4 py-2 font-medium text-white hover:bg-amber-700"
                        >
                            Start building
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main>
            <slot />
        </main>

        <footer class="border-t border-[#1e222a]">
            <div class="mx-auto flex max-w-6xl flex-col gap-3 px-6 py-8 text-sm text-[#6f7580] sm:flex-row sm:items-center sm:justify-between">
                <div class="font-display text-base text-[#9aa0ab]">Worldbuilder</div>
                <nav class="flex flex-wrap gap-5">
                    <Link :href="route('marketing.how')" class="hover:text-[#c8ccd3]">How it works</Link>
                    <Link :href="route('marketing.features')" class="hover:text-[#c8ccd3]">Features</Link>
                    <Link :href="route('marketing.pricing')" class="hover:text-[#c8ccd3]">Pricing</Link>
                    <Link :href="route('marketing.faq')" class="hover:text-[#c8ccd3]">FAQ</Link>
                    <Link v-if="user" :href="route('dashboard')" class="hover:text-[#c8ccd3]">My dashboard</Link>
                    <Link v-else-if="canLogin" :href="route('login')" class="hover:text-[#c8ccd3]">Log in</Link>
                </nav>
            </div>
        </footer>
    </div>
</template>
