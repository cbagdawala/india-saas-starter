<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import { invoicingApi } from '@/modules/invoicing/api'
import type { Invoice } from '@/modules/invoicing/types'
import { formatPaise } from '@/utils/format'

const router = useRouter()
const toast = useToast()
const invoices = ref<Invoice[]>([])
const loading = ref(true)

const severity: Record<Invoice['status'], string> = { paid: 'success', unpaid: 'warn', credited: 'secondary' }

async function load() {
    loading.value = true
    try {
        invoices.value = (await invoicingApi.list()).data
    } catch {
        toast.add({ severity: 'error', summary: 'Could not load invoices', life: 4000 })
    } finally {
        loading.value = false
    }
}

async function pdf(invoice: Invoice) {
    try {
        await invoicingApi.downloadPdf(invoice)
    } catch {
        toast.add({ severity: 'error', summary: 'Could not download the PDF', life: 4000 })
    }
}

onMounted(load)
</script>

<template>
    <div class="rounded-xl bg-surface-0 p-6 shadow dark:bg-surface-900">
        <Message severity="info" :closable="false" class="mb-4">
            Invoices are issued automatically when a payment is confirmed. Add your GSTIN and address in
            <RouterLink :to="{ name: 'billing-profile' }" class="underline">Billing profile</RouterLink> first so they appear on the invoice.
        </Message>
        <DataTable :value="invoices" :loading="loading" data-key="id" size="small" selection-mode="single" @row-click="router.push({ name: 'invoice', params: { id: $event.data.id } })">
            <template #empty>
                <p class="py-6 text-center text-muted-color">No invoices yet.</p>
            </template>
            <Column field="number" header="Number" />
            <Column header="Type">
                <template #body="{ data }">{{ data.type === 'credit_note' ? 'Credit note' : 'Tax invoice' }}</template>
            </Column>
            <Column header="Date">
                <template #body="{ data }">{{ new Date(data.issued_at).toLocaleDateString('en-IN') }}</template>
            </Column>
            <Column header="Total" class="text-right">
                <template #body="{ data }">{{ formatPaise(data.total) }}</template>
            </Column>
            <Column header="Status">
                <template #body="{ data }"><Tag :value="data.status" :severity="severity[data.status as Invoice['status']]" /></template>
            </Column>
            <Column header="" class="w-16 text-right">
                <template #body="{ data }">
                    <Button icon="pi pi-download" severity="secondary" text rounded :aria-label="`Download ${data.number}`" @click.stop="pdf(data)" />
                </template>
            </Column>
        </DataTable>
    </div>
</template>
