import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { useAuthStore } from '@/modules/auth/stores/auth'
import { tenancyApi } from '../api'
import type { TenantRole, Workspace } from '../types'

export const useTenantStore = defineStore('tenant', () => {
    const workspaces = ref<Workspace[]>([])
    const current = computed(() => workspaces.value.find((w) => w.is_current) ?? null)
    const role = computed<TenantRole | null>(() => current.value?.role ?? null)
    const isAdmin = computed(() => role.value === 'owner' || role.value === 'admin')
    const isOwner = computed(() => role.value === 'owner')

    async function load(): Promise<void> {
        workspaces.value = await tenancyApi.workspaces()
    }

    /** Switch the server-side current workspace, then refresh the list and the user (current_tenant_id). */
    async function switchTo(id: number): Promise<void> {
        await tenancyApi.switch(id)
        await Promise.all([load(), useAuthStore().fetchUser()])
    }

    async function create(name: string): Promise<void> {
        await tenancyApi.create(name)
        await Promise.all([load(), useAuthStore().fetchUser()])
    }

    return { workspaces, current, role, isAdmin, isOwner, load, switchTo, create }
})
