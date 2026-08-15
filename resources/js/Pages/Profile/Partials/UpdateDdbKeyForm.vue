<script setup>
import InputError from "@/Components/InputError.vue";
import InputLabel from "@/Components/InputLabel.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import TextInput from "@/Components/TextInput.vue";
import { useForm } from "@inertiajs/vue3";

const props = defineProps({
    ddbSaved: { type: Boolean, default: false },
});

const form = useForm({ ddb_cobalt: "" });

const save = () =>
    form.put(route("profile.ddb"), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });

const removeKey = () => {
    form.ddb_cobalt = "";
    form.put(route("profile.ddb"), { preserveScroll: true });
};
</script>

<template>
    <section>
        <header>
            <h2 class="font-display text-lg font-medium text-ink">D&amp;D Beyond</h2>
            <p class="mt-1 text-sm text-muted">
                Paste your personal D&amp;D Beyond
                <code class="rounded bg-raised px-1 py-0.5 text-ink">CobaltSession</code> cookie so your worlds can import
                characters and compendium content. It's encrypted and never
                shown again.
                <span v-if="ddbSaved" class="font-medium text-teal"
                    >A key is saved.</span
                >
            </p>
        </header>

        <details class="mt-4 rounded-md border border-edge2 bg-raised/40 px-4 py-3">
            <summary class="cursor-pointer text-sm font-medium text-teal">
                What is this, and where do I find it?
            </summary>
            <div class="mt-3 space-y-3 text-sm text-muted">
                <p>
                    <code class="rounded bg-raised px-1 py-0.5 text-ink">CobaltSession</code>
                    is the cookie D&amp;D Beyond sets when you log in — it proves the request is
                    yours. Worldbuilder stores it (encrypted) so it can import your characters and
                    purchased compendium content on your behalf. Nothing is imported without it.
                </p>
                <ol class="list-decimal space-y-1.5 pl-5">
                    <li>In a desktop browser, log in at <span class="text-ink">dndbeyond.com</span>.</li>
                    <li>Open developer tools — press <span class="text-ink">F12</span>, or right-click the page and choose <span class="text-ink">Inspect</span>.</li>
                    <li>Go to the <span class="text-ink">Application</span> tab (Chrome/Edge) or <span class="text-ink">Storage</span> tab (Firefox).</li>
                    <li>In the left sidebar, expand <span class="text-ink">Cookies</span> and select <span class="text-ink">https://www.dndbeyond.com</span>.</li>
                    <li>Find the row named <code class="rounded bg-raised px-1 py-0.5 text-ink">CobaltSession</code> and copy its <span class="text-ink">Value</span> (a long string).</li>
                    <li>Paste it below and press Save.</li>
                </ol>
                <p class="text-faint">
                    Treat it like a password. It can expire when you log out of D&amp;D Beyond — if
                    imports stop working, repeat these steps to paste a fresh value.
                </p>
            </div>
        </details>

        <form class="mt-6 space-y-6" @submit.prevent="save">
            <div>
                <InputLabel
                    for="ddb_cobalt"
                    :value="ddbSaved ? 'Replace cobalt key' : 'Cobalt key'"
                />
                <TextInput
                    id="ddb_cobalt"
                    v-model="form.ddb_cobalt"
                    type="password"
                    autocomplete="off"
                    class="mt-1 block w-full"
                    placeholder="CobaltSession cookie value"
                />
                <InputError :message="form.errors.ddb_cobalt" class="mt-2" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing || !form.ddb_cobalt"
                    >Save</PrimaryButton
                >
                <button
                    v-if="ddbSaved"
                    type="button"
                    class="text-sm text-red-400 hover:text-red-300"
                    @click="removeKey"
                >
                    Remove key
                </button>
            </div>
        </form>
    </section>
</template>
