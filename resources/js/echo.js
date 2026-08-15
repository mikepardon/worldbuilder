import Echo from "laravel-echo";
import Pusher from "pusher-js";

/**
 * Initialise Laravel Echo against a Pusher-protocol server (Reverb or Pusher/soketi). Configuration is
 * passed in from the back-end (the Inertia-shared `broadcast` prop) rather than read from
 * import.meta.env, per project standards. A no-op when realtime is disabled (config is null/undefined)
 * or already initialised.
 *
 * @param {{driver: string, key: string, host: string, port: number, scheme: string, cluster: string|null}|null|undefined} config
 */
export function initEcho(config) {
    if (
        typeof window === "undefined" ||
        window.Echo ||
        !config ||
        !config.key
    ) {
        return;
    }

    window.Pusher = Pusher;
    window.Echo = new Echo({
        broadcaster: config.driver === "reverb" ? "reverb" : "pusher",
        key: config.key,
        cluster: config.cluster || "mt1",
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === "https",
        enabledTransports: ["ws", "wss"],
        disableStats: true,
    });
}
