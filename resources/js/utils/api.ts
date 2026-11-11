import axios from 'axios'

export class ApiResponseError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string[]> | null = null,
    ) {
        super(message)
    }
}

interface Envelope<T> {
    success: boolean
    message: string
    data: T
    errors?: Record<string, string[]> | null
    meta?: PageMeta
}

export interface PageMeta {
    current_page: number
    last_page: number
    per_page: number
    total: number
}

export interface Paginated<T> {
    data: T[]
    meta: PageMeta
}

const client = axios.create({
    baseURL: import.meta.env.VITE_API_URL ?? '',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

client.interceptors.response.use(
    (response) => response,
    (error) => {
        const res = error.response
        if (res?.data && typeof res.data === 'object') {
            return Promise.reject(new ApiResponseError(res.data.message ?? error.message, res.status, res.data.errors ?? null))
        }
        return Promise.reject(error)
    },
)

/** Typed helpers that unwrap the gg13 `{success, message, data}` envelope. */
export const api = {
    csrf: () => client.get('/sanctum/csrf-cookie'),
    async get<T>(url: string, params?: object): Promise<T> {
        return (await client.get<Envelope<T>>(url, { params })).data.data
    },
    async list<T>(url: string, params?: object): Promise<Paginated<T>> {
        const body = (await client.get<Envelope<T[]>>(url, { params })).data
        const data = body.data ?? []
        return { data, meta: body.meta ?? { current_page: 1, last_page: 1, per_page: data.length, total: data.length } }
    },
    async post<T>(url: string, body?: object): Promise<T> {
        return (await client.post<Envelope<T>>(url, body)).data.data
    },
    async put<T>(url: string, body?: object): Promise<T> {
        return (await client.put<Envelope<T>>(url, body)).data.data
    },
    /** Download a binary response (e.g. a PDF) through the cookie session and hand it to the browser. */
    async download(url: string, filename: string, accept = 'application/pdf'): Promise<void> {
        const res = await client.get<Blob>(url, { responseType: 'blob', headers: { Accept: accept } })
        const href = URL.createObjectURL(res.data)
        const a = document.createElement('a')
        a.href = href
        a.download = filename
        a.click()
        URL.revokeObjectURL(href)
    },
    async patch<T>(url: string, body?: object): Promise<T> {
        return (await client.patch<Envelope<T>>(url, body)).data.data
    },
    async delete(url: string, body?: object): Promise<void> {
        await client.delete(url, { data: body })
    },
}

export default api
