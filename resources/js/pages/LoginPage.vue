<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Password from 'primevue/password'
import { useAuthStore } from '@/modules/auth/stores/auth'
import { ApiResponseError } from '@/utils/api'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const form = reactive({ email: '', password: '', remember: false })
const errors = ref<Record<string, string[]>>({})
const formError = ref('')

async function submit() {
    errors.value = {}
    formError.value = ''
    try {
        await auth.login(form.email, form.password, form.remember)
        const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') && !route.query.redirect.startsWith('//') ? route.query.redirect : '/'
        await router.push(redirect)
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
                <h1 class="mt-2 text-2xl font-semibold">India SaaS Starter Kit</h1>
                <p class="text-sm text-muted-color">Sign in to continue</p>
            </div>

            <Message v-if="formError" severity="error" class="mb-4" :closable="false">{{ formError }}</Message>

            <div class="mb-4 flex flex-col gap-1">
                <label for="email" class="text-sm font-medium">Email</label>
                <InputText id="email" v-model="form.email" type="email" autocomplete="username" :invalid="!!errors.email" fluid />
                <small v-if="errors.email" class="text-red-500">{{ errors.email[0] }}</small>
            </div>

            <div class="mb-4 flex flex-col gap-1">
                <label for="password" class="text-sm font-medium">Password</label>
                <Password
                    v-model="form.password"
                    input-id="password"
                    :feedback="false"
                    toggle-mask
                    autocomplete="current-password"
                    :invalid="!!errors.password"
                    fluid
                />
                <small v-if="errors.password" class="text-red-500">{{ errors.password[0] }}</small>
            </div>

            <div class="mb-6 flex items-center gap-2">
                <Checkbox v-model="form.remember" input-id="remember" binary />
                <label for="remember" class="text-sm">Remember me</label>
            </div>

            <Button type="submit" label="Sign in" class="w-full" :loading="auth.loading" />
            <p class="mt-3 text-center text-sm"><RouterLink to="/forgot-password" class="text-primary">Forgot your password?</RouterLink></p>
            <p class="mt-4 text-center text-sm text-muted-color">
                New here? <RouterLink to="/register" class="text-primary">Create an account</RouterLink>
            </p>
        </form>
    </div>
</template>
