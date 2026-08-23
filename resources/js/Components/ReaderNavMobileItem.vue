<script setup>
import { Link } from "@inertiajs/vue3";
import { computed, ref } from "vue";

// One row of the reader nav rendered for small screens: a full-width tap target, with children shown as
// a tap-to-expand accordion (indented) rather than a hover fly-out — so nested menus work on touch.
// Recurses for arbitrary depth. `node` is already resolved (href/label/count/active) by PublicLayout.
defineOptions({ name: "ReaderNavMobileItem" });

const { node, depth = 0 } = defineProps({
    node: { type: Object, required: true },
    depth: { type: Number, default: 0 },
});

const hasChildren = computed(() => (node.children?.length ?? 0) > 0);
const isExternal = computed(() => !!node.href && node.external);
const isInternal = computed(() => !!node.href && !node.external);

const open = ref(false);

const rowClass = computed(() => [
    "flex-1 py-2.5",
    node.active ? "text-amber" : "text-muted",
]);
</script>

<template>
    <div>
        <div class="flex items-center gap-2">
            <Link v-if="isInternal" :href="node.href" :class="rowClass">
                {{ node.label
                }}<span v-if="node.count" class="ml-1 text-faint">{{
                    node.count
                }}</span>
            </Link>
            <a
                v-else-if="isExternal"
                :href="node.href"
                target="_blank"
                rel="noopener"
                :class="rowClass"
            >
                {{ node.label }}
            </a>
            <span v-else :class="rowClass">{{ node.label }}</span>

            <button
                v-if="hasChildren"
                type="button"
                class="flex h-9 w-9 items-center justify-center text-muted hover:text-teal"
                :aria-expanded="open"
                :aria-label="open ? 'Collapse' : 'Expand'"
                @click="open = !open"
            >
                <svg
                    viewBox="0 0 24 24"
                    class="h-4 w-4 transition-transform"
                    :class="open ? 'rotate-180' : ''"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="m6 9 6 6 6-6" />
                </svg>
            </button>
        </div>

        <div
            v-if="hasChildren && open"
            class="ml-3 border-l border-edge2 pl-3"
        >
            <ReaderNavMobileItem
                v-for="child in node.children"
                :key="child.key"
                :node="child"
                :depth="depth + 1"
            />
        </div>
    </div>
</template>
