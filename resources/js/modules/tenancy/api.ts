import api from '@/utils/api'
import type { Invitation, Member, TenantRole, Workspace } from './types'

export const tenancyApi = {
    workspaces: () => api.get<Workspace[]>('/api/tenants'),
    create: (name: string) => api.post<Workspace>('/api/tenants', { name }),
    switch: (id: number) => api.post<Workspace>(`/api/tenants/${id}/switch`),
    acceptInvitation: (token: string) => api.post<Workspace>('/api/invitations/accept', { token }),

    members: () => api.get<Member[]>('/api/tenant/members'),
    changeRole: (userId: number, role: TenantRole) => api.patch<Member>(`/api/tenant/members/${userId}`, { role }),
    removeMember: (userId: number) => api.delete(`/api/tenant/members/${userId}`),
    leave: () => api.post('/api/tenant/leave'),

    invitations: () => api.get<Invitation[]>('/api/tenant/invitations'),
    invite: (email: string, role: Invitation['role']) => api.post<Invitation>('/api/tenant/invitations', { email, role }),
    revokeInvitation: (id: number) => api.delete(`/api/tenant/invitations/${id}`),
}
