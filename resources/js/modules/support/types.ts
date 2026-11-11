export interface SupportTicket {
    id: number
    subject: string
    body_text: string
    status: 'open' | 'pending' | 'closed'
    source: 'email' | 'form'
    received_at: string | null
    created_at: string | null
}

export interface SupportTicketPayload {
    subject: string
    body: string
}

export interface PaginatedTickets {
    data: SupportTicket[]
    meta: { current_page: number; last_page: number; per_page: number; total: number }
}
