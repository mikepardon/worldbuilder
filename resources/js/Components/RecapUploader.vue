<script setup>
import { useCredits } from "@/composables/useCredits";
import { captureError } from "@/monitoring";
import { onBeforeUnmount, onMounted, ref } from "vue";
import Uppy from "@uppy/core";
import Dashboard from "@uppy/dashboard";
import AwsS3 from "@uppy/aws-s3";
import "@uppy/core/css/style.min.css";
import "@uppy/dashboard/css/style.min.css";

const props = defineProps({
    session: { type: Object, required: true },
});
const emit = defineEmits(["created"]);

const { recapPerHour } = useCredits();

const detail = ref("comprehensive");
const uploading = ref(false);
const error = ref("");
const dashboard = ref(null);

let uppy = null;
let lastUploadError = "";
const keys = {}; // Uppy file.id → S3 object key
const durations = {}; // Uppy file.id → audio length in seconds

// Read a media file's duration in the browser so recaps can be priced by length before any AI runs.
function mediaDuration(blob) {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(blob);
        const el = document.createElement("video"); // reads both audio-only and video containers
        el.preload = "metadata";
        el.muted = true;
        const finish = (seconds) => {
            URL.revokeObjectURL(url);
            resolve(seconds);
        };
        el.onloadedmetadata = () => {
            const d = el.duration;
            finish(Number.isFinite(d) && d > 0 ? Math.round(d) : 0);
        };
        el.onerror = () => finish(0);
        el.src = url;
    });
}

function mountUppy() {
    uppy = new Uppy({
        autoProceed: true,
        restrictions: {
            maxNumberOfFiles: 1,
            allowedFileTypes: [
                "audio/*",
                "video/mp4",
                ".mp3",
                ".m4a",
                ".wav",
                ".ogg",
                ".oga",
                ".opus",
                ".webm",
                ".aac",
                ".flac",
                ".aiff",
                ".mov",
                ".mkv",
            ],
        },
    })
        .use(Dashboard, {
            inline: true,
            target: dashboard.value,
            height: 300,
            width: "100%",
            theme: "dark",
            proudlyDisplayPoweredByUppy: false,
            showProgressDetails: true,
            note: "Audio only. Uploads straight to storage, so multi-gigabyte recordings are fine.",
        })
        .use(AwsS3, {
            shouldUseMultipart: () => true,
            createMultipartUpload: async (file) => {
                const res = await window.axios.post(
                    route("sessions.uploads.create", props.session.id),
                    { filename: file.name, type: file.type, size: file.size },
                );
                keys[file.id] = res.data.key;
                return { uploadId: res.data.uploadId, key: res.data.key };
            },
            signPart: async (file, { uploadId, key, partNumber }) => {
                const res = await window.axios.post(
                    route("sessions.uploads.sign", props.session.id),
                    { key, uploadId, partNumber },
                );
                return { url: res.data.url };
            },
            listParts: async (file, { uploadId, key }) => {
                const res = await window.axios.post(
                    route("sessions.uploads.list", props.session.id),
                    { key, uploadId },
                );
                return res.data;
            },
            completeMultipartUpload: async (file, { uploadId, key, parts }) => {
                const res = await window.axios.post(
                    route("sessions.uploads.complete", props.session.id),
                    { key, uploadId, parts },
                );
                return { location: res.data.location };
            },
            abortMultipartUpload: async (file, { uploadId, key }) => {
                await window.axios.post(
                    route("sessions.uploads.abort", props.session.id),
                    { key, uploadId },
                );
            },
        });

    uppy.on("file-added", async (file) => {
        durations[file.id] = await mediaDuration(file.data);
    });
    uppy.on("upload", () => {
        uploading.value = true;
        error.value = "";
        lastUploadError = "";
    });
    uppy.on("upload-error", (file, err, response) => {
        captureError(err);
        lastUploadError = uploadErrorMessage(err, response);
    });
    uppy.on("complete", (res) => onUploaded(res));
}

function uploadErrorMessage(err, response) {
    const serverMessage =
        response?.body?.message || err?.response?.data?.message;
    if (serverMessage) return serverMessage;
    const raw = (err?.message || "").toLowerCase();
    if (
        !response ||
        raw.includes("network") ||
        raw.includes("failed to fetch") ||
        raw.includes("cors")
    ) {
        return `The upload to storage was blocked (CORS). Add this exact origin — ${window.location.origin} — to the bucket’s CORS AllowedOrigins (matching scheme, host and port), allow PUT/GET, and expose the ETag header.`;
    }
    return `The upload failed: ${err?.message || "unknown error"}`;
}

async function onUploaded(res) {
    uploading.value = false;
    const file = res?.successful?.[0];
    if (!file) {
        error.value =
            lastUploadError || "The upload didn’t complete — please try again.";
        resetUppy();
        return;
    }
    const key = keys[file.id];
    if (!key) {
        error.value = "Upload finished but its storage reference was lost.";
        resetUppy();
        return;
    }

    try {
        const res2 = await window.axios.post(
            route("sessions.recap.store", props.session.id),
            {
                key,
                original_name: file.name,
                duration_seconds: durations[file.id] ?? 0,
                detail_level: detail.value,
            },
            { headers: { Accept: "application/json" } },
        );
        if (!res2?.data?.id) {
            error.value = "The upload was stored but processing didn’t start.";
            resetUppy();
            return;
        }
        emit("created", res2.data);
    } catch (e) {
        captureError(e);
        error.value =
            e?.response?.data?.message || "Could not start processing.";
        resetUppy();
    }
}

function resetUppy() {
    if (uppy) uppy.destroy();
    mountUppy();
}

onMounted(mountUppy);
onBeforeUnmount(() => {
    if (uppy) uppy.destroy();
});
</script>

<template>
    <div class="space-y-4">
        <p class="text-sm text-muted">
            Upload a recording of this session and Muse will transcribe it and
            write up a recap, memorable moments, a scene outline, and the
            people, places and things that appeared. Credits are charged by the
            recording's length<template v-if="recapPerHour">
                — {{ recapPerHour }} credits per hour of audio</template>, checked
            before processing starts.
        </p>
        <label class="block">
            <span class="mb-1 block text-xs text-faint">Detail</span>
            <select
                v-model="detail"
                :disabled="uploading"
                class="w-full max-w-xs rounded border border-edge3 bg-night px-3 py-1.5 text-sm text-ink disabled:opacity-50"
            >
                <option value="comprehensive">
                    Comprehensive — full recap, every scene
                </option>
                <option value="brief">
                    Brief — a tighter recap and the key beats
                </option>
            </select>
        </label>
        <div ref="dashboard"></div>
        <p v-if="error" class="text-sm text-amber">{{ error }}</p>
    </div>
</template>
