<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { useAuthStore } from '@/modules/auth/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ok = computed(() => route.query.status === 'success')

async function continueToApp() {
    await auth.fetchUser()
    await router.push({ name: auth.isAuthenticated ? 'dashboard' : 'login' })
}
</script>

<template>
    <div class="mx-auto flex min-h-screen max-w-md flex-col items-center justify-center gap-4 p-6 text-center">
        <Message v-if="ok" severity="success" :closable="false">Your email address is verified.</Message>
        <Message v-else severity="error" :closable="false">This verification link is invalid or has expired. Sign in and request a new one.</Message>
        <Button :label="auth.isAuthenticated ? 'Continue' : 'Sign in'" @click="continueToApp" />
    </div>
</template>
