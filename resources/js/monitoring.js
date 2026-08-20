import * as Sentry from "@sentry/vue";

/**
 * Report a caught error to Sentry, to be called from a catch handler alongside whatever user-facing message
 * the caller already shows. This is a no-op until Sentry is initialised (i.e. when no browser DSN is
 * configured), so it is always safe to call.
 *
 * @param {unknown} error The caught error.
 * @param {Record<string, unknown>} [context] Optional extra context to attach to the Sentry event.
 */
export function captureError(error, context = undefined) {
    Sentry.captureException(error, context ? { extra: context } : undefined);
}
