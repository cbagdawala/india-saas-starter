import api from '@/utils/api'

export const accountApi = {
    forgotPassword: (email: string) => api.post<null>('/api/auth/forgot-password', { email }),
    resetPassword: (payload: { token: string; email: string; password: string; password_confirmation: string }) => api.post<null>('/api/auth/reset-password', payload),
    exportData: () => api.download('/api/account/export', 'my-data.json', 'application/json'),
    deleteAccount: (password: string) => api.delete('/api/account', { password }),
}
