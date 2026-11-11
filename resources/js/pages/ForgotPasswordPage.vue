<script setup lang="ts">
import { ref } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import { accountApi } from '@/modules/auth/api'
import { ApiResponseError } from '@/utils/api'

const email = ref('')
const sent = ref(false)
const busy = ref(false)
const error = ref('')

async function submit() {
    error.value = ''
    busy.value = true
    try {
        await accountApi.forgotPassword(email.value)
        sent.value = true
    } catch (e) {
        error.value = e instanceof ApiResponseError ? (e.errors?.email?.[0] ?? e.message) : 'Something went wrong. Please try again.'
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center p-4">
        <form class="w-full max-w-sm rounded-xl bg-surface-0 p-8 shadow dark:bg-surface-900" novalidate @submit.prevent="submit">
            <h1 class="mb-1 text-xl font-semibold">Reset your password</h1>
            <p class="mb-4 text-sm text-muted-color">Enter your email and we will send you a reset link.</p>
            <Message v-if="sent" severity="success" :closable="false" class="mb-4">If an account exists for that email, a reset link is on its way. It expires in 60 minutes.</Message>
            <Message v-if="error" severity="error" :closable="false" class="mb-4">{{ error }}</Message>
            <div class="mb-4 flex flex-col gap-1">
                <label for="email" class="text-sm font-medium">Email</label>
                <InputText id="email" v-model="email" type="email" autocomplete="email" fluid />
            </div>
            <Button type="submit" label="Send reset link" class="w-full" :loading="busy" />
            <p class="mt-4 text-center text-sm"><RouterLink to="/login" class="text-primary">Back to sign in</RouterLink></p>
        </form>
    </div>
</template>
