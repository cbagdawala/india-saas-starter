<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import { invoicingApi } from '@/modules/invoicing/api'
import type { Invoice } from '@/modules/invoicing/types'
import { formatPaise } from '@/utils/format'

const route = useRoute()
const toast = useToast()
const invoice = ref<Invoice | null>(null)
const error = ref('')

async function pdf() {
    if (!invoice.value) return
    try {
        await invoicingApi.downloadPdf(invoice.value)
    } catch {
        toast.add({ severity: 'error', summary: 'Could not download the PDF', life: 4000 })
    }
}

onMounted(async () => {
    try {
        invoice.value = await invoicingApi.show(Number(route.params.id))
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Could not load the invoice.'
    }
})
</script>

<template>
    <Message v-if="error" severity="error">{{ error }}</Message>
    <div v-else-if="invoice" class="flex flex-col gap-6">
        <section class="rounded-xl bg-surface-0 p-6 shadow dark:bg-surface-900">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold">{{ invoice.type === 'credit_note' ? 'Credit note' : 'Tax invoice' }} {{ invoice.number }}</h2>
                    <p class="text-sm text-muted-color">
                        {{ new Date(invoice.issued_at).toLocaleDateString('en-IN') }} · Place of supply: {{ invoice.place_of_supply }}
                        <template v-if="invoice.credit_reason"> · Reason: {{ invoice.credit_reason }}</template>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Tag :value="invoice.status" :severity="invoice.status === 'paid' ? 'success' : invoice.status === 'unpaid' ? 'warn' : 'secondary'" />
                    <Button label="Download PDF" icon="pi pi-download" @click="pdf" />
                </div>
            </div>

            <div class="mb-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <h3 class="text-sm font-medium text-muted-color">Supplier</h3>
                    <p class="font-medium">{{ invoice.seller?.legal_name }}</p>
                    <p class="text-sm">{{ invoice.seller?.address }}</p>
                    <p class="text-sm">GSTIN {{ invoice.seller?.gstin }}</p>
                </div>
                <div>
                    <h3 class="text-sm font-medium text-muted-color">Recipient</h3>
                    <p class="font-medium">{{ invoice.buyer?.legal_name }}</p>
                    <p class="text-sm">{{ invoice.buyer?.address }}</p>
                    <p class="text-sm">GSTIN {{ invoice.buyer?.gstin ?? 'Unregistered' }}</p>
                </div>
            </div>

            <DataTable :value="invoice.lines" data-key="position" size="small">
                <Column field="description" header="Description" />
                <Column field="sac" header="SAC" />
                <Column field="quantity" header="Qty" />
                <Column header="Taxable value" class="text-right"><template #body="{ data }">{{ formatPaise(data.taxable_value) }}</template></Column>
                <Column v-if="invoice.supply_type === 'intra'" header="CGST" class="text-right"><template #body="{ data }">{{ formatPaise(data.cgst) }}</template></Column>
                <Column v-if="invoice.supply_type === 'intra'" header="SGST" class="text-right"><template #body="{ data }">{{ formatPaise(data.sgst) }}</template></Column>
                <Column v-else header="IGST" class="text-right"><template #body="{ data }">{{ formatPaise(data.igst) }}</template></Column>
                <Column header="Total" class="text-right"><template #body="{ data }">{{ formatPaise(data.total) }}</template></Column>
            </DataTable>

            <div class="mt-4 flex flex-col items-end gap-1 text-sm">
                <span>Taxable value {{ formatPaise(invoice.taxable_value) }}</span>
                <span>Tax {{ formatPaise(invoice.total_tax) }}</span>
                <span class="text-base font-semibold">Total {{ formatPaise(invoice.total) }}</span>
                <span class="text-muted-color">{{ invoice.total_in_words }}</span>
                <span class="text-muted-color">Reverse charge: No</span>
                <span v-if="invoice.tax_note" class="text-muted-color">{{ invoice.tax_note }}</span>
            </div>
        </section>

        <section v-if="invoice.upi" class="rounded-xl bg-surface-0 p-6 shadow dark:bg-surface-900">
            <h2 class="mb-3 text-lg font-semibold">Pay by UPI</h2>
            <div class="flex flex-wrap items-center gap-6">
                <img :src="invoice.upi.qr" alt="UPI QR code for this invoice" width="180" height="180" />
                <div class="flex max-w-md flex-col gap-3">
                    <a :href="invoice.upi.link"><Button label="Open in UPI app" icon="pi pi-mobile" /></a>
                    <Message severity="warn" :closable="false">{{ invoice.upi.note }}</Message>
                </div>
            </div>
        </section>
    </div>
</template>
