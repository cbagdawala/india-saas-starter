<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import { invoicingApi } from '@/modules/invoicing/api'
import { GST_STATES } from '@/modules/invoicing/states'
import { useTenantStore } from '@/modules/tenancy/stores/tenant'
import { ApiResponseError } from '@/utils/api'

const tenant = useTenantStore()
const route = useRoute()
const router = useRouter()
const needed = ref(route.query.needed === '1')
const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const forbidden = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = reactive({
    legal_name: '' as string | null,
    address_line1: '' as string | null,
    address_line2: '' as string | null,
    city: '' as string | null,
    pincode: '' as string | null,
    country: 'IN',
    state_code: null as string | null,
    gstin: '' as string | null,
})
const india = () => form.country.toUpperCase() === 'IN'

async function load() {
    try {
        const { complete: _complete, ...profile } = await invoicingApi.profile()
        Object.assign(form, profile)
    } catch (e) {
        if (e instanceof ApiResponseError && e.status === 403) forbidden.value = true
        else toast.add({ severity: 'error', summary: 'Could not load the billing profile', life: 4000 })
    } finally {
        loading.value = false
    }
}

async function save() {
    errors.value = {}
    saving.value = true
    try {
        await invoicingApi.saveProfile({ ...form, gstin: form.gstin || null })
        toast.add({ severity: 'success', summary: 'Billing profile saved', detail: 'It applies to invoices issued from now on.', life: 4000 })
        if (needed.value) await router.push({ name: 'billing' })
    } catch (e) {
        if (e instanceof ApiResponseError && e.errors) errors.value = e.errors
        else toast.add({ severity: 'error', summary: e instanceof Error ? e.message : 'Could not save', life: 4000 })
    } finally {
        saving.value = false
    }
}

onMounted(load)
</script>

<template>
    <Message v-if="forbidden" severity="warn" :closable="false">Only owners and admins of {{ tenant.current?.name }} can edit the billing profile.</Message>
    <form v-else class="max-w-2xl rounded-xl bg-surface-0 p-6 shadow dark:bg-surface-900" novalidate @submit.prevent="save">
        <Message v-if="needed" severity="warn" :closable="false" class="mb-4">Complete your billing profile (name, address, state) before subscribing. Your GST invoice cannot be edited after it is issued.</Message>
        <h2 class="mb-1 text-lg font-semibold">Billing profile</h2>
        <p class="mb-4 text-sm text-muted-color">These details appear as the recipient on your GST invoices. Past invoices are not changed.</p>

        <div class="mb-4 flex flex-col gap-1">
            <label for="legal_name" class="text-sm font-medium">Legal / business name</label>
            <InputText id="legal_name" v-model="form.legal_name" maxlength="150" :disabled="loading" :invalid="!!errors.legal_name" fluid />
            <small v-if="errors.legal_name" class="text-red-500">{{ errors.legal_name[0] }}</small>
        </div>
        <div class="mb-4 flex flex-col gap-1">
            <label for="address_line1" class="text-sm font-medium">Address</label>
            <InputText id="address_line1" v-model="form.address_line1" maxlength="150" :disabled="loading" :invalid="!!errors.address_line1" fluid />
            <small v-if="errors.address_line1" class="text-red-500">{{ errors.address_line1[0] }}</small>
            <InputText v-model="form.address_line2" maxlength="150" :disabled="loading" aria-label="Address line 2" fluid />
        </div>
        <div class="mb-4 grid gap-4 sm:grid-cols-3">
            <div class="flex flex-col gap-1">
                <label for="city" class="text-sm font-medium">City</label>
                <InputText id="city" v-model="form.city" maxlength="80" :disabled="loading" :invalid="!!errors.city" fluid />
                <small v-if="errors.city" class="text-red-500">{{ errors.city[0] }}</small>
            </div>
            <div class="flex flex-col gap-1">
                <label for="pincode" class="text-sm font-medium">PIN code</label>
                <InputText id="pincode" v-model="form.pincode" maxlength="10" inputmode="numeric" :disabled="loading" :invalid="!!errors.pincode" fluid />
                <small v-if="errors.pincode" class="text-red-500">{{ errors.pincode[0] }}</small>
            </div>
            <div class="flex flex-col gap-1">
                <label for="country" class="text-sm font-medium">Country (ISO code)</label>
                <InputText id="country" v-model="form.country" maxlength="2" :disabled="loading" :invalid="!!errors.country" fluid />
                <small v-if="errors.country" class="text-red-500">{{ errors.country[0] }}</small>
            </div>
        </div>
        <template v-if="india()">
            <div class="mb-4 flex flex-col gap-1">
                <label for="state_code" class="text-sm font-medium">State (decides CGST + SGST or IGST)</label>
                <Select v-model="form.state_code" input-id="state_code" :options="GST_STATES" option-label="name" option-value="code" filter :invalid="!!errors.state_code" fluid />
                <small v-if="errors.state_code" class="text-red-500">{{ errors.state_code[0] }}</small>
            </div>
            <div class="mb-4 flex flex-col gap-1">
                <label for="gstin" class="text-sm font-medium">GSTIN (optional)</label>
                <InputText id="gstin" v-model="form.gstin" maxlength="15" :disabled="loading" :invalid="!!errors.gstin" fluid />
                <small v-if="errors.gstin" class="text-red-500">{{ errors.gstin[0] }}</small>
            </div>
        </template>
        <Message v-else severity="info" :closable="false" class="mb-4">Invoices to buyers outside India are issued as exports.</Message>
        <Button type="submit" label="Save" icon="pi pi-check" :loading="saving" />
    </form>
</template>
