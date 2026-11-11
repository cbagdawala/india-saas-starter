<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Password from 'primevue/password'
import { useAuthStore } from '@/modules/auth/stores/auth'
import { landingVariant, track } from '@/utils/analytics'
import { ApiResponseError } from '@/utils/api'

const auth = useAuthStore()
const router = useRouter()

const form = reactive({ name: '', email: '', password: '', password_confirmation: '' })
const errors = ref<Record<string, string[]>>({})
const formError = ref('')

async function submit() {
    errors.value = {}
    formError.value = ''
    try {
        await auth.register({ ...form })
        track('Signup', { variant: landingVariant() ?? 'none' })
        await router.push({ name: 'onboarding' })
    } catch (e) {
        if (e instanceof ApiResponseError) {
            errors.value = e.errors ?? {}
            if (!e.errors) formError.value = e.message
        } else {
            formError.value = 'Something went wrong. Please try again.'
        }
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center p-4">
        <form class="w-full max-w-sm rounded-xl bg-surface-0 p-8 shadow dark:bg-surface-900" novalidate @submit.prevent="submit">
            <div class="mb-6 text-center">
                <i class="pi pi-box text-3xl text-primary" />
                <h1 class="mt-2 text-2xl font-semibold">Create your account</h1>
            </div>

            <Message v-if="formError" severity="error" class="mb-4" :closable="false">{{ formError }}</Message>

            <div class="mb-4 flex flex-col gap-1">
                <label for="name" class="text-sm font-medium">Name</label>
                <InputText id="name" v-model="form.name" autocomplete="name" :invalid="!!errors.name" fluid />
                <small v-if="errors.name" class="text-red-500">{{ errors.name[0] }}</small>
            </div>
            <div class="mb-4 flex flex-col gap-1">
                <label for="email" class="text-sm font-medium">Email</label>
                <InputText id="email" v-model="form.email" type="email" autocomplete="username" :invalid="!!errors.email" fluid />
                <small v-if="errors.email" class="text-red-500">{{ errors.email[0] }}</small>
            </div>
            <div class="mb-4 flex flex-col gap-1">
                <label for="password" class="text-sm font-medium">Password</label>
                <Password v-model="form.password" input-id="password" toggle-mask autocomplete="new-password" :invalid="!!errors.password" fluid />
                <small v-if="errors.password" class="text-red-500">{{ errors.password[0] }}</small>
            </div>
            <div class="mb-6 flex flex-col gap-1">
                <label for="password_confirmation" class="text-sm font-medium">Confirm password</label>
                <Password v-model="form.password_confirmation" input-id="password_confirmation" :feedback="false" autocomplete="new-password" fluid />
            </div>

            <Button type="submit" label="Create account" class="w-full" :loading="auth.loading" />
            <p class="mt-4 text-center text-sm text-muted-color">
                Already registered? <RouterLink to="/login" class="text-primary">Sign in</RouterLink>
            </p>
        </form>
    </div>
</template>
