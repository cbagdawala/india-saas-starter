import api from '@/utils/api'
import type { PageMeta } from '@/utils/api'
import type { BillingProfile, Invoice } from './types'

export const invoicingApi = {
    list: (page = 1): Promise<{ data: Invoice[]; meta: PageMeta }> => api.list<Invoice>('/api/invoices', { page }),
    show: (id: number) => api.get<Invoice>(`/api/invoices/${id}`),
    downloadPdf: (invoice: Invoice) => api.download(`/api/invoices/${invoice.id}/pdf`, `${invoice.number.replaceAll('/', '-')}.pdf`),
    profile: () => api.get<BillingProfile>('/api/billing-profile'),
    saveProfile: (profile: Omit<BillingProfile, 'complete'>) => api.put<BillingProfile>('/api/billing-profile', profile),
}
