<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import InputText from 'primevue/inputtext'
import Tag from 'primevue/tag'
import Textarea from 'primevue/textarea'
import { useToast } from 'primevue/usetoast'
import { supportApi } from '@/modules/support/api'
import type { SupportTicket } from '@/modules/support/types'
import { ApiResponseError } from '@/utils/api'

const toast = useToast()

const tickets = ref<SupportTicket[]>([])
const loading = ref(true)
const saving = ref(false)
const form = reactive({ subject: '', body: '' })
const errors = ref<Record<string, string[]>>({})

const severity: Record<SupportTicket['status'], string> = { open: 'info', pending: 'warn', closed: 'secondary' }

async function load() {
    loading.value = true
    try {
        tickets.value = (await supportApi.list()).data
    } catch {
        toast.add({ severity: 'error', summary: 'Could not load your tickets', life: 4000 })
    } finally {
        loading.value = false
    }
}

async function submit() {
    errors.value = {}
    saving.value = true
    try {
        const ticket = await supportApi.create({ ...form })
        tickets.value = [ticket, ...tickets.value]
        form.subject = ''
        form.body = ''
        toast.add({ severity: 'success', summary: 'Message sent', detail: 'We will get back to you by email.', life: 4000 })
    } catch (e) {
        if (e instanceof ApiResponseError && e.errors) {
            errors.value = e.errors
        } else {
            toast.add({ severity: 'error', summary: e instanceof Error ? e.message : 'Could not send your message', life: 4000 })
        }
    } finally {
        saving.value = false
    }
}

onMounted(load)
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-2">
        <form class="rounded-xl bg-surface-0 p-6 shadow dark:bg-surface-900" novalidate @submit.prevent="submit">
            <h2 class="mb-4 text-lg font-semibold">Contact support</h2>
            <div class="mb-4 flex flex-col gap-1">
                <label for="subject" class="text-sm font-medium">Subject</label>
                <InputText id="subject" v-model="form.subject" maxlength="200" :invalid="!!errors.subject" fluid />
                <small v-if="errors.subject" class="text-red-500">{{ errors.subject[0] }}</small>
            </div>
            <div class="mb-4 flex flex-col gap-1">
                <label for="body" class="text-sm font-medium">How can we help?</label>
                <Textarea id="body" v-model="form.body" rows="6" maxlength="10000" auto-resize :invalid="!!errors.body" fluid />
                <small v-if="errors.body" class="text-red-500">{{ errors.body[0] }}</small>
            </div>
            <Button type="submit" label="Send" icon="pi pi-send" :loading="saving" />
        </form>

        <div class="rounded-xl bg-surface-0 p-6 shadow dark:bg-surface-900">
            <h2 class="mb-4 text-lg font-semibold">Your requests</h2>
            <DataTable :value="tickets" :loading="loading" data-key="id" size="small">
                <template #empty>
                    <p class="py-6 text-center text-muted-color">No requests yet.</p>
                </template>
                <Column field="subject" header="Subject" />
                <Column header="Status">
                    <template #body="{ data }">
                        <Tag :value="data.status" :severity="severity[data.status as SupportTicket['status']]" />
                    </template>
                </Column>
                <Column header="Sent">
                    <template #body="{ data }">{{ data.created_at ? new Date(data.created_at).toLocaleDateString() : '' }}</template>
                </Column>
            </DataTable>
        </div>
    </div>
</template>
