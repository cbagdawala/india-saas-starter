/**
 * Plausible custom events (goals). Works under the CSP (no inline script): this module installs the standard
 * queue shim, and the Plausible script (loaded from PLAUSIBLE_SRC) drains the queue when it arrives.
 * Goals to create in Plausible: `Landing Impression`, `CTA Click`, `Signup` (custom property: `variant`).
 */
type PlausibleProps = Record<string, string>
type PlausibleFn = ((event: string, options?: { props?: PlausibleProps }) => void) & { q?: unknown[][] }

declare global {
    interface Window {
        plausible?: PlausibleFn
    }
}

const VARIANT_COOKIE = 'bv_variant'
const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

export function track(event: string, props: PlausibleProps = {}): void {
    if (typeof window === 'undefined') return
    if (!window.plausible) {
        const shim: PlausibleFn = (...args: unknown[]) => {
            ;(shim.q = shim.q || []).push(args)
        }
        window.plausible = shim
    }
    window.plausible(event, { props })
}

/** The landing A/B slug assigned to this visitor (set by GET /), or null. */
export function landingVariant(): string | null {
    const match = document.cookie.split('; ').find((c) => c.startsWith(`${VARIANT_COOKIE}=`))
    const value = match ? decodeURIComponent(match.slice(VARIANT_COOKIE.length + 1)) : ''
    return value.length <= 64 && SLUG.test(value) ? value : null
}
