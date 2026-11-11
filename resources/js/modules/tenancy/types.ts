export type TenantRole = 'owner' | 'admin' | 'member'

export interface Workspace {
    id: number
    name: string
    slug: string
    role: TenantRole
    is_current: boolean
}

export interface Member {
    id: number
    name: string
    email: string
    role: TenantRole
    joined_at: string | null
}

export interface Invitation {
    id: number
    email: string
    role: Exclude<TenantRole, 'owner'>
    expires_at: string
    created_at: string | null
}
