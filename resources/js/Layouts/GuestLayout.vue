<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-night px-6 py-12">
        <Link href="/" class="mb-8 text-center">
            <div class="eyebrow">Worldbuilder</div>
            <div class="mt-1 font-display text-3xl text-bright">Enter the archive</div>
        </Link>

        <div class="w-full overflow-hidden rounded-lg border border-edge2 bg-card px-6 py-6 shadow-xl sm:max-w-md">
            <slot />
        </div>
    </div>
</template>
