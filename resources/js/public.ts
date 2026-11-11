// Entry for server-rendered public pages (landing, blog). No Vue/PrimeVue: just Plausible goals.
import { landingVariant, track } from '@/utils/analytics'

const variant = document.querySelector<HTMLMetaElement>('meta[name="landing-variant"]')?.content || landingVariant() || 'none'

if (document.querySelector('meta[name="landing-variant"]')) {
    track('Landing Impression', { variant })
}

document.addEventListener('click', (event) => {
    const target = (event.target as Element | null)?.closest<HTMLElement>('[data-cta]')
    if (target) {
        track('CTA Click', { variant, location: target.dataset.cta ?? 'unknown', path: window.location.pathname })
    }
})
