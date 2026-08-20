import "../css/app.css";
import "@fortawesome/fontawesome-free/css/all.min.css";
// D&D-sourcebook-style webfonts for the brew/article renderers (self-hosted, no external requests).
import "@fontsource/eb-garamond/400.css";
import "@fontsource/eb-garamond/400-italic.css";
import "@fontsource/eb-garamond/700.css";
import "@fontsource/eb-garamond/700-italic.css";
import "@fontsource/cinzel/600.css";
import "@fontsource/cinzel/700.css";
import "@fontsource/cinzel-decorative/700.css";
import "./bootstrap";

import { createInertiaApp } from "@inertiajs/vue3";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import * as Sentry from "@sentry/vue";
import { createApp, h } from "vue";
import { ZiggyVue } from "../../vendor/tightenco/ziggy";
import { initEcho } from "./echo";

const appName = import.meta.env.VITE_APP_NAME || "Laravel";

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob("./Pages/**/*.vue"),
        ),
    setup({ el, App, props, plugin }) {
        // Realtime: config is server-provided via the shared `broadcast` prop (null = disabled).
        initEcho(props.initialPage.props.broadcast);

        const app = createApp({ render: () => h(App, props) });

        // Front-end error monitoring. Config is server-provided via the shared `sentry` prop (never
        // import.meta.env); a null prop (blank browser DSN) leaves the SDK uninitialised, so the
        // captureError() calls in components become no-ops. Once initialised, Sentry also captures
        // otherwise-unhandled errors and promise rejections automatically.
        const sentry = props.initialPage.props.sentry;
        if (sentry) {
            Sentry.init({
                app,
                dsn: sentry.dsn,
                environment: sentry.environment ?? undefined,
                release: sentry.release ?? undefined,
                tracesSampleRate: sentry.tracesSampleRate ?? 0,
            });
        }

        return app.use(plugin).use(ZiggyVue).mount(el);
    },
    progress: {
        color: "#4B5563",
    },
});
