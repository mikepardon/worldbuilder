<script setup>
import { computed } from "vue";

// One editable row of the reader nav-menu tree, rendered recursively so nested items appear beneath and
// indented from their parent. All mutations are delegated to the `api` callbacks the Settings page owns
// (move/indent/outdent/remove + section-image helpers), addressed by this row's `path` into the tree.
defineOptions({ name: "NavMenuEditorItem" });

const { node, path, depth, isLast, api } = defineProps({
    node: { type: Object, required: true },
    path: { type: Array, required: true },
    depth: { type: Number, required: true },
    isLast: { type: Boolean, default: false },
    api: { type: Object, required: true },
});

const isFirst = computed(() => path[path.length - 1] === 0);
const canIndent = computed(() => !isFirst.value && depth < api.maxDepth - 1);
// The underlying target, shown as the input's placeholder so a blank label clearly falls back to it.
const targetHint = computed(() =>
    node.type === "link"
        ? "Label"
        : (node.target.split(":").pop() ?? "") || node.type,
);
</script>

<template>
    <div>
        <div
            class="flex items-center gap-2 rounded-md border border-edge3 px-2 py-1.5"
            :style="{ marginLeft: `${depth * 18}px` }"
        >
            <div class="flex flex-col leading-none">
                <button
                    type="button"
                    class="text-faint hover:text-teal disabled:opacity-30"
                    :disabled="isFirst"
                    title="Move up"
                    @click="api.move(path, -1)"
                >
                    ▲
                </button>
                <button
                    type="button"
                    class="text-faint hover:text-teal disabled:opacity-30"
                    :disabled="isLast"
                    title="Move down"
                    @click="api.move(path, 1)"
                >
                    ▼
                </button>
            </div>
            <button
                type="button"
                class="text-faint hover:text-teal disabled:opacity-30"
                :disabled="!canIndent"
                title="Indent (nest under the item above)"
                @click="api.indent(path)"
            >
                →
            </button>
            <button
                type="button"
                class="text-faint hover:text-teal disabled:opacity-30"
                :disabled="depth === 0"
                title="Outdent"
                @click="api.outdent(path)"
            >
                ←
            </button>

            <span class="font-mono text-[9px] uppercase tracking-[0.1em] text-teal">{{
                api.typeLabel(node.type)
            }}</span>

            <input
                v-model="node.label"
                class="field !w-40 min-w-0 flex-1 !py-1 text-[13px]"
                :placeholder="targetHint"
            />
            <input
                v-if="node.type === 'link'"
                v-model="node.target"
                type="url"
                class="field w-48 !py-1 text-[13px]"
                placeholder="https://…"
            />

            <!-- Section cover image (the reader's "Start here" card) -->
            <template v-if="node.type === 'section'">
                <div
                    class="h-8 w-12 shrink-0 overflow-hidden rounded border border-edge3 bg-raised bg-cover bg-center"
                    :style="
                        api.sectionImageUrl(node.target)
                            ? {
                                  backgroundImage: `url(${api.sectionImageUrl(
                                      node.target,
                                  )})`,
                              }
                            : {}
                    "
                ></div>
                <label
                    class="cursor-pointer text-xs text-muted hover:text-teal"
                    :title="
                        api.sectionImageUrl(node.target)
                            ? 'Replace image'
                            : 'Upload image'
                    "
                >
                    {{ api.sectionImageUrl(node.target) ? "Replace" : "Image" }}
                    <input
                        type="file"
                        accept="image/*"
                        class="hidden"
                        @change="api.uploadSectionImage(node.target, $event)"
                    />
                </label>
                <button
                    v-if="api.sectionImageUrl(node.target)"
                    type="button"
                    class="text-xs text-faint hover:text-red-400"
                    title="Remove image"
                    @click="api.removeSectionImage(node.target)"
                >
                    ✕
                </button>
            </template>

            <button
                type="button"
                class="shrink-0 text-faint hover:text-red-400"
                title="Remove"
                @click="api.remove(path)"
            >
                ✕
            </button>
        </div>

        <NavMenuEditorItem
            v-for="(child, i) in node.children"
            :key="child.id"
            :node="child"
            :path="[...path, i]"
            :depth="depth + 1"
            :is-last="i === node.children.length - 1"
            :api="api"
        />
    </div>
</template>
