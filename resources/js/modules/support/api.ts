import api from '@/utils/api'
import type { PaginatedTickets, SupportTicket, SupportTicketPayload } from './types'

export const supportApi = {
    list: (page = 1) => api.get<PaginatedTickets>('/api/support/tickets', { page }),
    create: (payload: SupportTicketPayload) => api.post<SupportTicket>('/api/support/tickets', payload),
}
