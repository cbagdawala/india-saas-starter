<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Password from 'primevue/password'
import { useToast } from 'primevue/usetoast'
import { accountApi } from '@/modules/auth/api'
import { useAuthStore } from '@/modules/auth/stores/auth'
import { ApiResponseError } from '@/utils/api'

const auth = useAuthStore()
const router = useRouter()
const toast = useToast()
const password = ref('')
const busy = ref(false)
const blockers = ref<string[]>([])
const error = ref('')

async function download() {
    try {
        await accountApi.exportData()
    } catch {
        toast.add({ severity: 'error', summary: 'Could not export your data (limit: 3 exports per hour)', life: 5000 })
    }
}

async function remove() {
    error.value = ''
    blockers.value = []
    busy.value = true
    try {
        await accountApi.deleteAccount(password.value)
        auth.user = null
        await router.push({ name: 'login' })
    } catch (e) {
        if (e instanceof ApiResponseError && e.status === 409) blockers.value = e.errors?.account ?? [e.message]
        else error.value = e instanceof ApiResponseError ? (e.errors?.password?.[0] ?? e.message) : 'Could not delete the account.'
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="flex max-w-2xl flex-col gap-6">
        <section class="rounded-xl bg-surface-0 p-6 shadow dark:bg-surface-900">
            <h2 class="mb-1 text-lg font-semibold">Your data</h2>
            <p class="mb-4 text-sm text-muted-color">Download everything this product holds about you as a JSON file.</p>
            <Button label="Download my data" icon="pi pi-download" severity="secondary" @click="download" />
        </section>

        <section class="rounded-xl bg-surface-0 p-6 shadow dark:bg-surface-900">
            <h2 class="mb-1 text-lg font-semibold">Delete my account</h2>
            <p class="mb-4 text-sm text-muted-color">
                Deletes {{ auth.user?.email }}, your notes, support tickets and workspaces you alone own. GST invoices that were already issued are kept as legal records, as the law requires.
                This cannot be undone.
            </p>
            <Message v-for="b in blockers" :key="b" severity="warn" :closable="false" class="mb-2">{{ b }}</Message>
            <Message v-if="error" severity="error" :closable="false" class="mb-2">{{ error }}</Message>
            <div class="mb-4 flex flex-col gap-1">
                <label for="confirm-password" class="text-sm font-medium">Confirm with your password</label>
                <Password id="confirm-password" v-model="password" :feedback="false" toggle-mask autocomplete="current-password" fluid />
            </div>
            <Button label="Delete my account" icon="pi pi-trash" severity="danger" :loading="busy" :disabled="!password" @click="remove" />
        </section>
    </div>
</template>
