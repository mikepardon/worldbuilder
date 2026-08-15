<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-md border border-edge3 bg-transparent px-4 py-2 text-xs font-semibold uppercase tracking-widest text-ink transition duration-150 ease-in-out hover:border-teal focus:outline-none disabled:opacity-50"
    >
        <slot />
    </button>
</template>
