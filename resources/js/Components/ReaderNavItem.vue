<script setup>
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";

// One node of the reader's navigation menu. Renders itself recursively: a node with children becomes a
// hover dropdown, and its children (which may have their own children) are ReaderNavItems one level
// deeper — giving arbitrarily nested fly-out menus. `node` is already resolved (href/label/count/active)
// by PublicLayout; unavailable items were dropped there, so anything reaching us is renderable.
defineOptions({ name: "ReaderNavItem" });

const { node, depth = 0 } = defineProps({
    node: { type: Object, required: true },
    depth: { type: Number, default: 0 },
});

const top = computed(() => depth === 0);
const hasChildren = computed(() => (node.children?.length ?? 0) > 0);
const isExternal = computed(() => !!node.href && node.external);
const isInternal = computed(() => !!node.href && !node.external);

// Distinct group name per depth so each fly-out reacts only to its own hover, not its ancestors'. The
// literal class strings below are what Tailwind scans — keep them spelled out. Depth is capped at 4.
const GROUP = ["group/nav0", "group/nav1", "group/nav2", "group/nav3"];
const SHOW = [
    "group-hover/nav0:block",
    "group-hover/nav1:block",
    "group-hover/nav2:block",
    "group-hover/nav3:block",
];
const groupClass = computed(() => GROUP[Math.min(depth, GROUP.length - 1)]);
const showClass = computed(() => SHOW[Math.min(depth, SHOW.length - 1)]);

// Top-level triggers are inline nav links; nested ones are full-width dropdown rows.
const triggerClass = computed(() => [
    "flex items-center hover:text-teal transition-colors",
    top.value ? "" : "w-full px-3 py-1.5 hover:bg-white/5",
    node.active ? "text-amber" : "text-muted",
]);
</script>

<template>
    <!-- Branch: a node with children (dropdown parent, which may also be a link itself) -->
    <div v-if="hasChildren" :class="['relative', groupClass]">
        <Link v-if="isInternal" :href="node.href" :class="triggerClass">
            <span>{{ node.label }}</span>
            <span v-if="node.count" class="ml-1 text-faint">{{
                node.count
            }}</span>
            <span class="ml-1 text-[0.7em] opacity-70">{{
                top ? "▾" : "▸"
            }}</span>
        </Link>
        <a
            v-else-if="isExternal"
            :href="node.href"
            target="_blank"
            rel="noopener"
            :class="triggerClass"
        >
            <span>{{ node.label }}</span>
            <span class="ml-1 text-[0.7em] opacity-70">{{
                top ? "▾" : "▸"
            }}</span>
        </a>
        <button v-else type="button" :class="triggerClass">
            <span>{{ node.label }}</span>
            <span class="ml-1 text-[0.7em] opacity-70">{{
                top ? "▾" : "▸"
            }}</span>
        </button>

        <!-- The fly-out. Padding on the wrapper bridges the gap so the menu survives the mouse crossing. -->
        <div
            :class="[
                'absolute z-40 hidden',
                showClass,
                top ? 'left-0 top-full pt-2' : 'left-full top-0 pl-1',
            ]"
        >
            <div
                class="min-w-[11rem] rounded-md border border-white/10 bg-[#0e0f13] py-1 text-left shadow-xl"
            >
                <ReaderNavItem
                    v-for="child in node.children"
                    :key="child.key"
                    :node="child"
                    :depth="depth + 1"
                />
            </div>
        </div>
    </div>

    <!-- Branch: a plain leaf link -->
    <Link v-else-if="isInternal" :href="node.href" :class="triggerClass">
        <span>{{ node.label }}</span>
        <span v-if="node.count" class="ml-1 text-faint">{{ node.count }}</span>
    </Link>
    <a
        v-else-if="isExternal"
        :href="node.href"
        target="_blank"
        rel="noopener"
        :class="triggerClass"
    >
        {{ node.label }}
    </a>
</template>
