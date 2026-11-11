export interface Plan {
    key: string
    name: string
    amount: number // paise
    currency: string
    interval: string
    features: string[]
}

export interface Subscription {
    id: number
    plan: string
    status: 'created' | 'active' | 'past_due' | 'cancelled' | 'completed'
    amount: number
    currency: string
    current_period_end: string | null
    cancelled_at: string | null
}

export interface Checkout {
    driver: 'fake' | 'razorpay'
    reference: string
    key_id?: string
    subscription_id?: string
    signature?: string
}

export interface PaymentConfirmation {
    razorpay_subscription_id: string
    razorpay_payment_id: string
    razorpay_signature: string
}
