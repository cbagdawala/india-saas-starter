export interface User {
    id: number
    name: string
    email: string
    current_tenant_id: number | null
    email_verified: boolean
    onboarded_at: string | null
}

export interface RegisterPayload {
    name: string
    email: string
    password: string
    password_confirmation: string
}
