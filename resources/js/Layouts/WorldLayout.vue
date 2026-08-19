<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import CreditsPill from "@/Components/CreditsPill.vue";
import WorldSearchModal from "@/Components/WorldSearchModal.vue";
import { Link, usePage } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";

const props = defineProps({
    // Shared payload from App\Support\WorldNav::for().
    world: Object,
    // Flush: the world nav sits above a full-height, edge-to-edge page (used by the entry editor).
    flush: { type: Boolean, default: false },
});

const page = usePage();

// The current entries section, read from the URL — the nav tabs are real links to worlds.entries.
const currentSection = computed(() => {
    const match = page.url.match(/[?&]section=([^&]+)/);
    return match ? decodeURIComponent(match[1]) : "all";
});

// Home (the dashboard) + the world's populated sections, each a real link.
const sectionTabs = computed(() => [
    {
        slug: "all",
        label: "Home",
        href: route("worlds.show", props.world.id),
        active: route().current("worlds.show"),
    },
    ...props.world.sections.map((s) => ({
        slug: s.slug,
        label: s.label,
        href: route("worlds.entries", {
            world: props.world.id,
            section: s.slug,
        }),
        active:
            route().current("worlds.entries") &&
            currentSection.value === s.slug,
    })),
]);

const primaryLinks = computed(() => [
    {
        label: "Campaigns",
        href: route("campaigns.index", props.world.id),
        active:
            route().current("campaigns.*") ||
            route().current("players.*") ||
            route().current("characters.*") ||
            route().current("sessions.*") ||
            route().current("worlds.sessions") ||
            route().current("rooms.*"),
    },
    {
        label: "Compendium",
        href: route("compendium.index", props.world.id),
        active: route().current("compendium.*"),
    },
]);

const authUser = computed(() => page.props.auth.user);

const toolLinks = computed(() => [
    {
        label: "AI generators",
        href: route("generators.index", props.world.id),
        active: route().current("generators.*"),
    },
    // Admin-granted per-world feature; only surfaced when enabled.
    ...(props.world.knowledge_ingestion_enabled
        ? [
              {
                  label: "Add knowledge",
                  href: route("worlds.ingest", props.world.id),
                  active: route().current("worlds.ingest"),
              },
          ]
        : []),
    {
        label: "Connections web",
        href: route("worlds.web", props.world.id),
        active: route().current("worlds.web"),
    },
    {
        label: "Roll tables",
        href: route("tables.index", props.world.id),
        active: route().current("tables.*"),
    },
    {
        label: "Calendars",
        href: route("calendars.index", props.world.id),
        active: route().current("calendars.*"),
    },
    {
        label: "Schedule",
        href: route("schedule.index", props.world.id),
        active: route().current("schedule.*"),
    },
]);

const settingsLinks = computed(() => [
    ...(props.world.can_configure
        ? [
              {
                  label: "World settings",
                  href: route("worlds.settings", props.world.id),
                  active:
                      route().current("worlds.settings") ||
                      route().current("worlds.members.*") ||
                      route().current("worlds.domain.*"),
              },
              {
                  label: "Fields",
                  href: route("worlds.attributes.index", props.world.id),
                  active: route().current("worlds.attributes.*"),
              },
              {
                  label: "Templates",
                  href: route("worlds.templates.index", props.world.id),
                  active: route().current("worlds.templates.*"),
              },
          ]
        : []),
    {
        label: "Media library",
        href: route("media.index", props.world.id),
        active: route().current("media.*"),
    },
]);

const toolsActive = computed(() => toolLinks.value.some((l) => l.active));
const settingsActive = computed(() =>
    settingsLinks.value.some((l) => l.active),
);

const searchModal = ref(null);
const openSearch = () => searchModal.value?.open();

// One dropdown open at a time; close on any outside click (Links close it by navigating).
const openMenu = ref(null); // 'tools' | 'settings' | null
const toggleMenu = (name) =>
    (openMenu.value = openMenu.value === name ? null : name);
const closeMenus = () => (openMenu.value = null);
onMounted(() => window.addEventListener("click", closeMenus));
onBeforeUnmount(() => window.removeEventListener("click", closeMenus));

const itemClass = (active) =>
    active ? "text-bright" : "text-muted hover:text-ink";
const menuItemClass = (active) =>
    active
        ? "bg-[#242832] text-bright"
        : "text-muted hover:bg-[#242832] hover:text-ink";
</script>

<template>
    <AuthenticatedLayout bare>
        <div
            style="animation: fade 0.3s ease both"
            :class="flush ? 'flex h-screen flex-col' : ''"
        >
            <!-- World top nav -->
            <nav class="border-b border-edge2 bg-[#14161b]">
                <div
                    class="mx-auto flex max-w-[1500px] flex-wrap items-center gap-x-1 gap-y-2 px-6 py-2.5"
                >
                    <Link
                        :href="route('worlds.show', world.id)"
                        class="mr-2 font-display text-[17px] text-bright hover:text-amber"
                        >{{ world.name }}</Link
                    >

                    <!-- World search (opens an overlay) -->
                    <button
                        class="mr-1 rounded-md p-1.5 text-muted hover:text-ink"
                        title="Search this world"
                        @click="openSearch"
                    >
                        <svg
                            class="h-[18px] w-[18px]"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                    </button>

                    <!-- Sections (real links) -->
                    <Link
                        v-for="t in sectionTabs"
                        :key="t.slug"
                        :href="t.href"
                        class="rounded-md px-2.5 py-1.5 text-[14px]"
                        :class="itemClass(t.active)"
                    >
                        {{ t.label }}
                    </Link>

                    <!-- Primary links -->
                    <Link
                        v-for="l in primaryLinks"
                        :key="l.label"
                        :href="l.href"
                        class="rounded-md px-2.5 py-1.5 text-[14px]"
                        :class="itemClass(l.active)"
                    >
                        {{ l.label }}
                    </Link>

                    <!-- Tools dropdown -->
                    <div class="relative" @click.stop>
                        <button
                            class="rounded-md px-2.5 py-1.5 text-[14px]"
                            :class="
                                itemClass(toolsActive || openMenu === 'tools')
                            "
                            @click="toggleMenu('tools')"
                        >
                            Tools ▾
                        </button>
                        <div
                            v-if="openMenu === 'tools'"
                            class="absolute left-0 z-30 mt-1 min-w-[190px] rounded-md border border-edge2 bg-[#1a1d24] p-1.5 shadow-xl"
                        >
                            <Link
                                v-for="l in toolLinks"
                                :key="l.label"
                                :href="l.href"
                                class="block rounded px-2.5 py-1.5 text-[13.5px]"
                                :class="menuItemClass(l.active)"
                                @click="closeMenus"
                            >
                                {{ l.label }}
                            </Link>
                        </div>
                    </div>

                    <!-- Settings dropdown -->
                    <div class="relative ml-auto mr-1" @click.stop>
                        <button
                            class="rounded-md px-2.5 py-1.5 text-[14px]"
                            :class="
                                itemClass(
                                    settingsActive || openMenu === 'settings',
                                )
                            "
                            @click="toggleMenu('settings')"
                        >
                            Settings ▾
                        </button>
                        <div
                            v-if="openMenu === 'settings'"
                            class="absolute right-0 z-30 mt-1 w-[260px] rounded-md border border-edge2 bg-[#1a1d24] p-1.5 shadow-xl"
                        >
                            <Link
                                v-for="l in settingsLinks"
                                :key="l.label"
                                :href="l.href"
                                class="block rounded px-2.5 py-1.5 text-[13.5px]"
                                :class="menuItemClass(l.active)"
                                @click="closeMenus"
                            >
                                {{ l.label }}
                            </Link>
                            <a
                                :href="world.public_url"
                                target="_blank"
                                class="block rounded px-2.5 py-1.5 text-[13.5px] text-muted hover:bg-[#242832] hover:text-ink"
                                >Read as players ↗</a
                            >
                        </div>
                    </div>

                    <CreditsPill class="hidden md:inline-flex" />

                    <!-- Account dropdown -->
                    <div class="relative" @click.stop>
                        <button
                            class="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-[14px]"
                            :class="itemClass(openMenu === 'account')"
                            @click="toggleMenu('account')"
                        >
                            <img
                                v-if="authUser.avatar_url"
                                :src="authUser.avatar_url"
                                alt=""
                                class="h-5 w-5 rounded-full object-cover"
                            />
                            {{ authUser.name }} ▾
                        </button>
                        <div
                            v-if="openMenu === 'account'"
                            class="absolute right-0 z-30 mt-1 min-w-[190px] rounded-md border border-edge2 bg-[#1a1d24] p-1.5 shadow-xl"
                        >
                            <Link
                                :href="route('profile.edit')"
                                class="block rounded px-2.5 py-1.5 text-[13.5px] text-muted hover:bg-[#242832] hover:text-ink"
                                @click="closeMenus"
                                >Profile</Link
                            >
                            <Link
                                :href="route('billing.index')"
                                class="block rounded px-2.5 py-1.5 text-[13.5px] text-muted hover:bg-[#242832] hover:text-ink"
                                @click="closeMenus"
                                >Billing</Link
                            >
                            <Link
                                v-if="authUser.is_admin"
                                :href="route('admin.dashboard')"
                                class="block rounded px-2.5 py-1.5 text-[13.5px] text-muted hover:bg-[#242832] hover:text-ink"
                                @click="closeMenus"
                                >Admin</Link
                            >

                            <div class="my-1.5 h-px bg-edge2"></div>
                            <Link
                                :href="route('dashboard')"
                                class="block rounded px-2.5 py-1.5 text-[13.5px] text-muted hover:bg-[#242832] hover:text-ink"
                                @click="closeMenus"
                                >Exit world ↩</Link
                            >
                            <Link
                                :href="route('logout')"
                                method="post"
                                as="button"
                                class="block w-full rounded px-2.5 py-1.5 text-left text-[13.5px] text-muted hover:bg-[#242832] hover:text-ink"
                                @click="closeMenus"
                                >Log out</Link
                            >
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Main content -->
            <div v-if="flush" class="min-h-0 flex-1">
                <slot />
            </div>
            <div
                v-else
                class="mx-auto flex min-h-[calc(100vh-8rem)] w-full max-w-[1500px] flex-col gap-5 px-6 pb-11 pt-6"
            >
                <slot />
            </div>

            <WorldSearchModal ref="searchModal" :world-id="world.id" />
        </div>
    </AuthenticatedLayout>
</template>
