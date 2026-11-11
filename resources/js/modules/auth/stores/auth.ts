import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/utils/api'
import type { RegisterPayload, User } from '../types'

export const useAuthStore = defineStore('auth', () => {
    const user = ref<User | null>(null)
    const loading = ref(false)
    const isAuthenticated = computed(() => user.value !== null)

    async function fetchUser(): Promise<void> {
        try {
            user.value = await api.get<User>('/api/auth/me')
        } catch {
            user.value = null
        }
    }

    async function login(email: string, password: string, remember = false): Promise<void> {
        loading.value = true
        try {
            await api.csrf()
            user.value = await api.post<User>('/api/auth/login', { email, password, remember })
        } finally {
            loading.value = false
        }
    }

    async function register(payload: RegisterPayload): Promise<void> {
        loading.value = true
        try {
            await api.csrf()
            user.value = await api.post<User>('/api/auth/register', payload)
        } finally {
            loading.value = false
        }
    }

    async function logout(): Promise<void> {
        try {
            await api.post('/api/auth/logout')
        } finally {
            user.value = null
        }
    }

    return { user, loading, isAuthenticated, fetchUser, login, register, logout }
})
