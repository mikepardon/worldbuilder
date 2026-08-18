<script setup>
import CreditsPill from "@/Components/CreditsPill.vue";
import Dropdown from "@/Components/Dropdown.vue";
import DropdownLink from "@/Components/DropdownLink.vue";
import NavLink from "@/Components/NavLink.vue";
import ResponsiveNavLink from "@/Components/ResponsiveNavLink.vue";
import { Link, usePage } from "@inertiajs/vue3";
import { ref } from "vue";

// World pages nest their own top nav (WorldLayout) and pass `bare` to hide the redundant outer bar.
const { bare = false } = defineProps({
    bare: { type: Boolean, default: false },
});

const showingNavigationDropdown = ref(false);
const page = usePage();
</script>

<template>
    <div class="min-h-screen bg-night text-ink">
        <nav v-if="!bare" class="border-b border-edge bg-surface">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 justify-between">
                    <div class="flex">
                        <Link
                            :href="route('dashboard')"
                            class="flex shrink-0 items-center"
                        >
                            <span class="font-display text-xl text-amber"
                                >Worldbuilder</span
                            >
                        </Link>

                        <div
                            class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                        >
                            <NavLink
                                :href="route('dashboard')"
                                :active="
                                    route().current('dashboard') ||
                                    route().current('worlds.*')
                                "
                            >
                                Dashboard
                            </NavLink>
                        </div>
                    </div>

                    <div class="hidden sm:ms-6 sm:flex sm:items-center sm:gap-3">
                        <CreditsPill />
                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="inline-flex items-center rounded-md border border-transparent bg-surface px-3 py-2 text-sm font-medium text-muted transition hover:text-ink focus:outline-none"
                                >
                                    <img
                                        v-if="page.props.auth.user.avatar_url"
                                        :src="page.props.auth.user.avatar_url"
                                        alt=""
                                        class="me-2 h-6 w-6 rounded-full object-cover"
                                    />
                                    {{ page.props.auth.user.name }}
                                    <svg
                                        class="-me-0.5 ms-2 h-4 w-4"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </button>
                            </template>
                            <template #content>
                                <DropdownLink :href="route('profile.edit')"
                                    >Profile</DropdownLink
                                >
                                <DropdownLink :href="route('billing.index')"
                                    >Billing</DropdownLink
                                >
                                <DropdownLink :href="route('storage.index')"
                                    >Storage</DropdownLink
                                >
                                <DropdownLink
                                    v-if="page.props.auth.user.is_admin"
                                    :href="route('admin.dashboard')"
                                    >Admin</DropdownLink
                                >
                                <DropdownLink
                                    :href="route('logout')"
                                    method="post"
                                    as="button"
                                    >Log Out</DropdownLink
                                >
                            </template>
                        </Dropdown>
                    </div>

                    <div class="-me-2 flex items-center sm:hidden">
                        <button
                            class="inline-flex items-center justify-center rounded-md p-2 text-muted transition hover:bg-raised hover:text-ink focus:outline-none"
                            @click="
                                showingNavigationDropdown =
                                    !showingNavigationDropdown
                            "
                        >
                            <svg
                                class="h-6 w-6"
                                stroke="currentColor"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    :class="{
                                        hidden: showingNavigationDropdown,
                                        'inline-flex':
                                            !showingNavigationDropdown,
                                    }"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h16"
                                />
                                <path
                                    :class="{
                                        hidden: !showingNavigationDropdown,
                                        'inline-flex':
                                            showingNavigationDropdown,
                                    }"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Responsive menu -->
            <div
                :class="{
                    block: showingNavigationDropdown,
                    hidden: !showingNavigationDropdown,
                }"
                class="sm:hidden"
            >
                <div class="space-y-1 pb-3 pt-2">
                    <ResponsiveNavLink
                        :href="route('dashboard')"
                        :active="
                            route().current('dashboard') ||
                            route().current('worlds.*')
                        "
                        >Dashboard</ResponsiveNavLink
                    >
                </div>
                <div class="border-t border-edge pb-1 pt-4">
                    <div class="px-4">
                        <div class="text-base font-medium text-ink">
                            {{ page.props.auth.user.name }}
                        </div>
                        <div class="text-sm font-medium text-faint">
                            {{ page.props.auth.user.email }}
                        </div>
                        <div class="mt-3">
                            <CreditsPill />
                        </div>
                    </div>
                    <div class="mt-3 space-y-1">
                        <ResponsiveNavLink :href="route('profile.edit')"
                            >Profile</ResponsiveNavLink
                        >
                        <ResponsiveNavLink :href="route('billing.index')"
                            >Billing</ResponsiveNavLink
                        >
                        <ResponsiveNavLink :href="route('storage.index')"
                            >Storage</ResponsiveNavLink
                        >
                        <ResponsiveNavLink
                            v-if="page.props.auth.user.is_admin"
                            :href="route('admin.dashboard')"
                            :active="route().current('admin.*')"
                            >Admin</ResponsiveNavLink
                        >
                        <ResponsiveNavLink
                            :href="route('logout')"
                            method="post"
                            as="button"
                            >Log Out</ResponsiveNavLink
                        >
                    </div>
                </div>
            </div>
        </nav>

        <!-- Page heading -->
        <header v-if="$slots.header" class="border-b border-edge bg-surface">
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <!-- Flash -->
        <div
            v-if="page.props.flash?.success || page.props.flash?.error"
            class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8"
        >
            <div
                class="rounded-md border px-4 py-3 text-sm"
                :class="
                    page.props.flash.error
                        ? 'border-blood/40 bg-blood/10 text-red-300'
                        : 'border-teal/30 bg-teal/10 text-teal'
                "
            >
                {{ page.props.flash.error || page.props.flash.success }}
            </div>
        </div>

        <main>
            <slot />
        </main>
    </div>
</template>
