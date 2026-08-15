<script setup>
import UppyUploader from "@/Components/UppyUploader.vue";
import { router, usePage } from "@inertiajs/vue3";
import { computed } from "vue";

const page = usePage();
const user = computed(() => page.props.auth.user);
const initial = computed(() =>
    (user.value.name || user.value.email || "?").charAt(0).toUpperCase(),
);

// Uppy uploads over XHR, so reload the page to pull the fresh shared auth.user (and its avatar_url).
const onUploaded = () => router.reload({ preserveScroll: true });
const removeAvatar = () =>
    router.delete(route("profile.avatar.destroy"), { preserveScroll: true });
</script>

<template>
    <section>
        <header>
            <h2 class="font-display text-lg font-medium text-ink">
                Profile image
            </h2>
            <p class="mt-1 text-sm text-muted">
                Shown next to your name across Worldbuilder.
            </p>
        </header>

        <div class="mt-6 flex items-start gap-5">
            <div
                class="h-20 w-20 shrink-0 overflow-hidden rounded-full border border-edge3 bg-raised"
            >
                <img
                    v-if="user.avatar_url"
                    :src="user.avatar_url"
                    alt="Your profile image"
                    class="h-full w-full object-cover"
                />
                <div
                    v-else
                    class="flex h-full w-full items-center justify-center font-display text-2xl text-muted"
                >
                    {{ initial }}
                </div>
            </div>

            <div class="min-w-0 flex-1">
                <UppyUploader
                    :endpoint="route('profile.avatar')"
                    :allowed-file-types="['image/*']"
                    :max-file-size-mb="10"
                    :max-number-of-files="1"
                    :height="150"
                    note="PNG/JPG/WebP"
                    @complete="onUploaded"
                />
                <button
                    v-if="user.avatar_url"
                    class="mt-2 text-sm text-faint hover:text-red-400"
                    @click="removeAvatar"
                >
                    Remove image
                </button>
            </div>
        </div>
    </section>
</template>
