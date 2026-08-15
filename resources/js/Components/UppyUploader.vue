<script setup>
// Reusable file uploader built on Uppy. Uploads each file over XHR to a Laravel endpoint (one request
// per file), forwarding the CSRF cookie so the existing routes authenticate it. The endpoint should
// respond with JSON (2xx on success, 4xx with a `message` on failure).
import Uppy from "@uppy/core";
import Dashboard from "@uppy/dashboard";
import XHRUpload from "@uppy/xhr-upload";
import "@uppy/core/css/style.min.css";
import "@uppy/dashboard/css/style.min.css";
import { onBeforeUnmount, onMounted, ref } from "vue";

const props = defineProps({
    endpoint: { type: String, required: true },
    fieldName: { type: String, default: "file" },
    // Uppy file-type filters — extensions (".png") or mime types ("image/*").
    allowedFileTypes: { type: Array, default: () => ["image/*"] },
    maxFileSizeMb: { type: Number, default: 50 },
    maxNumberOfFiles: { type: Number, default: 20 },
    height: { type: Number, default: 300 },
    note: { type: String, default: "" },
    // Render as a modal opened via the exposed open() method, instead of inline.
    modal: { type: Boolean, default: false },
});
const emit = defineEmits(["uploaded", "complete"]);

const mount = ref(null);
let uppy;

// Laravel CSRF: forward the XSRF-TOKEN cookie as the X-XSRF-TOKEN header, exactly like axios does.
const xsrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : "";
};

onMounted(() => {
    uppy = new Uppy({
        autoProceed: true,
        restrictions: {
            maxFileSize: props.maxFileSizeMb * 1024 * 1024,
            maxNumberOfFiles: props.maxNumberOfFiles,
            allowedFileTypes: props.allowedFileTypes,
        },
    })
        .use(Dashboard, {
            inline: !props.modal,
            target: mount.value,
            theme: "dark",
            height: props.height,
            note: props.note,
            proudlyDisplayPoweredByUppy: false,
            closeAfterFinish: true,
        })
        .use(XHRUpload, {
            endpoint: props.endpoint,
            method: "post",
            fieldName: props.fieldName,
            formData: true,
            headers: () => ({
                "X-XSRF-TOKEN": xsrfToken(),
                "X-Requested-With": "XMLHttpRequest",
                Accept: "application/json",
            }),
        });

    uppy.on("upload-success", (_file, response) =>
        emit("uploaded", response.body),
    );
    uppy.on("complete", (result) => emit("complete", result));
});

onBeforeUnmount(() => uppy?.destroy());

// Modal mode: parents open the picker by calling this.
defineExpose({ open: () => uppy?.getPlugin("Dashboard")?.openModal() });
</script>

<template>
    <div ref="mount"></div>
</template>
