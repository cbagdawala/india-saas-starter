<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import { ref } from 'vue'
import api from '@/utils/api'
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue'
import { useAuthStore } from '@/modules/auth/stores/auth'
import { useTenantStore } from '@/modules/tenancy/stores/tenant'

const auth = useAuthStore()
const tenant = useTenantStore()
const route = useRoute()
const router = useRouter()

const nav = [
    { label: 'Dashboard', icon: 'pi pi-home', to: '/' },
    { label: 'Billing', icon: 'pi pi-credit-card', to: '/billing' },
    { label: 'Invoices', icon: 'pi pi-file', to: '/invoices' },
    { label: 'Billing profile', icon: 'pi pi-id-card', to: '/billing-profile' },
    { label: 'Members', icon: 'pi pi-users', to: '/members' },
    { label: 'Account', icon: 'pi pi-user', to: '/account' },
    { label: 'Support', icon: 'pi pi-question-circle', to: '/support' },
]

const title = computed(() => (route.meta.title as string) ?? '')

const toast = useToast()
const resending = ref(false)

async function resendVerification() {
    resending.value = true
    try {
        await api.post('/api/auth/email/verification-notification')
        toast.add({ severity: 'success', summary: 'Verification email sent', detail: 'Check your inbox.', life: 4000 })
    } catch (e) {
        toast.add({ severity: 'error', summary: e instanceof Error ? e.message : 'Could not send the email', life: 4000 })
    } finally {
        resending.value = false
    }
}

async function logout() {
    await auth.logout()
    await router.push({ name: 'login' })
}
</script>

<template>
    <div class="flex min-h-screen">
        <aside class="hidden w-60 shrink-0 flex-col border-r border-surface-200 bg-surface-0 md:flex dark:border-surface-800 dark:bg-surface-900">
            <div class="flex items-center gap-2 p-5 text-lg font-semibold"><i class="pi pi-box text-primary" /> India SaaS Starter Kit</div>
            <WorkspaceSwitcher />
            <nav class="flex flex-1 flex-col gap-1 px-3">
                <RouterLink
                    v-for="item in nav"
                    :key="item.label"
                    :to="item.to"
                    class="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-surface-100 dark:hover:bg-surface-800"
                    active-class="bg-surface-100 font-medium dark:bg-surface-800"
                >
                    <i :class="item.icon" /> {{ item.label }}
                </RouterLink>
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex items-center justify-between border-b border-surface-200 bg-surface-0 px-6 py-3 dark:border-surface-800 dark:bg-surface-900">
                <h1 class="text-lg font-semibold">{{ title }}</h1>
                <div class="flex items-center gap-3">
                    <span class="hidden text-sm text-muted-color sm:inline">{{ auth.user?.email }}</span>
                    <Button label="Sign out" icon="pi pi-sign-out" severity="secondary" size="small" text @click="logout" />
                </div>
            </header>
            <!-- keyed by workspace: switching remounts the page so it reloads that workspace's data -->
            <Message v-if="auth.user && !auth.user.email_verified" severity="warn" :closable="false" class="m-6 mb-0">
                <span>Verify your email address to subscribe and to join workspaces. We sent a link to {{ auth.user.email }}.</span>
                <Button label="Resend email" size="small" text :loading="resending" @click="resendVerification" />
            </Message>
            <main class="flex-1 p-6"><RouterView :key="tenant.current?.id ?? 0" /></main>
        </div>
    </div>
</template>
