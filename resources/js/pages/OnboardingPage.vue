<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import { useAuthStore } from '@/modules/auth/stores/auth'
import { onboardingApi } from '@/modules/onboarding/api'
import type { OnboardingStep } from '@/modules/onboarding/types'

const auth = useAuthStore()
const router = useRouter()

const steps = ref<OnboardingStep[]>([])
const loading = ref(true)
const busy = ref(false)
const error = ref('')

onMounted(async () => {
    try {
        steps.value = (await onboardingApi.state()).steps
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Could not load onboarding.'
    } finally {
        loading.value = false
    }
})

/** Marks onboarding done (idempotent) and follows the step's link. */
async function go(to: string) {
    busy.value = true
    try {
        await onboardingApi.complete()
    } catch {
        // completion is best effort; never block the user on it
    } finally {
        busy.value = false
    }
    await router.push(to)
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center p-4">
        <div class="w-full max-w-2xl rounded-xl bg-surface-0 p-8 shadow dark:bg-surface-900">
            <div class="mb-8 text-center">
                <i class="pi pi-sparkles text-3xl text-primary" />
                <h1 class="mt-2 text-2xl font-semibold">Welcome, {{ auth.user?.name }}</h1>
                <p class="mt-2 text-muted-color">Three quick steps to get the most out of your account.</p>
            </div>

            <Message v-if="error" severity="error" class="mb-4" :closable="false">{{ error }}</Message>

            <div v-if="loading" class="flex flex-col gap-4">
                <Skeleton v-for="i in 3" :key="i" height="5rem" />
            </div>
            <ol v-else class="flex flex-col gap-4">
                <li
                    v-for="(step, index) in steps"
                    :key="step.key"
                    class="flex items-start gap-4 rounded-lg border border-surface-200 p-4 dark:border-surface-700"
                >
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary font-semibold text-primary-contrast">
                        {{ index + 1 }}
                    </span>
                    <div class="flex-1">
                        <h2 class="font-semibold">{{ step.title }}</h2>
                        <p class="mt-1 text-sm text-muted-color">{{ step.body }}</p>
                    </div>
                    <Button :label="step.cta_label" size="small" severity="secondary" :disabled="busy" @click="go(step.to)" />
                </li>
            </ol>

            <div class="mt-8 flex justify-end">
                <Button label="Go to dashboard" icon="pi pi-arrow-right" icon-pos="right" :loading="busy" @click="go('/')" />
            </div>
        </div>
    </div>
</template>
