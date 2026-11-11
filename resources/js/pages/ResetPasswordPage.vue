<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Password from 'primevue/password'
import { accountApi } from '@/modules/auth/api'
import { ApiResponseError } from '@/utils/api'

const router = useRouter()
// The emailed link carries the token and email in the URL fragment (never sent to the server, so not in access logs or
// Referer headers). Read them once and remove them from the address bar.
const fragment = new URLSearchParams(window.location.hash.replace(/^#/, ''))
const email = fragment.get('email') ?? ''
const token = fragment.get('token') ?? ''
window.history.replaceState(null, '', window.location.pathname)

const form = reactive({ password: '', password_confirmation: '' })
const errors = ref<Record<string, string[]>>({})
const busy = ref(false)
const done = ref(false)

async function submit() {
    errors.value = {}
    busy.value = true
    try {
        await accountApi.resetPassword({ token, email, ...form })
        done.value = true
        setTimeout(() => router.push({ name: 'login' }), 1500)
    } catch (e) {
        errors.value = e instanceof ApiResponseError && e.errors ? e.errors : { email: ['Something went wrong. Please try again.'] }
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center p-4">
        <form class="w-full max-w-sm rounded-xl bg-surface-0 p-8 shadow dark:bg-surface-900" novalidate @submit.prevent="submit">
            <h1 class="mb-4 text-xl font-semibold">Choose a new password</h1>
            <Message v-if="done" severity="success" :closable="false" class="mb-4">Password updated. Taking you to sign in...</Message>
            <Message v-if="errors.email" severity="error" :closable="false" class="mb-4">{{ errors.email[0] }} <RouterLink to="/forgot-password" class="underline">Request a new link</RouterLink></Message>
            <div class="mb-4 flex flex-col gap-1">
                <label for="password" class="text-sm font-medium">New password</label>
                <Password id="password" v-model="form.password" toggle-mask :feedback="false" autocomplete="new-password" :invalid="!!errors.password" fluid />
                <small v-if="errors.password" class="text-red-500">{{ errors.password[0] }}</small>
            </div>
            <div class="mb-4 flex flex-col gap-1">
                <label for="password_confirmation" class="text-sm font-medium">Repeat the password</label>
                <Password id="password_confirmation" v-model="form.password_confirmation" toggle-mask :feedback="false" autocomplete="new-password" fluid />
            </div>
            <Button type="submit" label="Reset password" class="w-full" :loading="busy" :disabled="done" />
        </form>
    </div>
</template>
