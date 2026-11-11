import api from '@/utils/api'
import type { Checkout, PaymentConfirmation, Plan, Subscription } from './types'

export const billingApi = {
    plans: () => api.get<Plan[]>('/api/billing/plans'),
    subscription: () => api.get<Subscription | null>('/api/billing/subscription'),
    checkout: (plan: string) => api.post<Checkout>('/api/billing/checkout', { plan }),
    confirm: (payload: PaymentConfirmation) => api.post<Subscription>('/api/billing/confirm', payload),
    cancel: () => api.post<Subscription>('/api/billing/cancel'),
}
