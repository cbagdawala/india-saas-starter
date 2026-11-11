<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import { INVITE_TOKEN_KEY } from '@/router'
import { useAuthStore } from '@/modules/auth/stores/auth'
import { tenancyApi } from '@/modules/tenancy/api'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const error = ref('')
const joined = ref('')

onMounted(async () => {
    // The router guard already moved the token from the URL into sessionStorage; use it once and forget it.
    const token = sessionStorage.getItem(INVITE_TOKEN_KEY) ?? ''
    sessionStorage.removeItem(INVITE_TOKEN_KEY)
    if (!token) {
        error.value = 'This invitation link is incomplete.'
        return
    }
    try {
        const workspace = await tenancyApi.acceptInvitation(token)
        joined.value = workspace.name
        await auth.fetchUser()
        setTimeout(() => router.push({ name: 'dashboard' }), 1200)
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Could not accept the invitation.'
    }
})
</script>

<template>
    <div class="mx-auto flex min-h-screen max-w-md flex-col items-center justify-center gap-4 p-6 text-center">
        <ProgressSpinner v-if="!error && !joined" style="width: 40px; height: 40px" />
        <Message v-if="joined" severity="success">You joined {{ joined }}. Taking you there...</Message>
        <template v-if="error">
            <Message severity="error">{{ error }}</Message>
            <Button label="Back to the app" severity="secondary" @click="router.push({ name: 'dashboard' })" />
        </template>
    </div>
</template>
