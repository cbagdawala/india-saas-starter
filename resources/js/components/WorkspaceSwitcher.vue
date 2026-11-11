<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import { useTenantStore } from '@/modules/tenancy/stores/tenant'
import { ApiResponseError } from '@/utils/api'

const tenant = useTenantStore()
const toast = useToast()

const showCreate = ref(false)
const name = ref('')
const saving = ref(false)
const error = ref('')

async function onChange(id: number | null) {
    if (id === null || id === tenant.current?.id) return
    try {
        await tenant.switchTo(id)
    } catch (e) {
        toast.add({ severity: 'error', summary: e instanceof Error ? e.message : 'Could not switch workspace', life: 4000 })
    }
}

async function create() {
    error.value = ''
    saving.value = true
    try {
        await tenant.create(name.value)
        showCreate.value = false
        name.value = ''
    } catch (e) {
        error.value = e instanceof ApiResponseError && e.errors?.name ? e.errors.name[0] : e instanceof Error ? e.message : 'Could not create workspace'
    } finally {
        saving.value = false
    }
}

onMounted(() => tenant.load().catch(() => undefined))
</script>

<template>
    <div class="flex items-center gap-1 px-3 pb-3">
        <Select
            :model-value="tenant.current?.id ?? null"
            :options="tenant.workspaces"
            option-label="name"
            option-value="id"
            placeholder="Select workspace"
            aria-label="Workspace"
            class="min-w-0 flex-1"
            @update:model-value="onChange"
        />
        <Button icon="pi pi-plus" severity="secondary" text rounded aria-label="New workspace" @click="showCreate = true" />
    </div>

    <Dialog v-model:visible="showCreate" modal header="New workspace" :style="{ width: '24rem' }">
        <form novalidate @submit.prevent="create">
            <div class="mb-4 flex flex-col gap-1">
                <label for="workspace-name" class="text-sm font-medium">Name</label>
                <InputText id="workspace-name" v-model="name" maxlength="120" :invalid="!!error" fluid autofocus />
                <small v-if="error" class="text-red-500">{{ error }}</small>
            </div>
            <div class="flex justify-end gap-2">
                <Button type="button" label="Cancel" severity="secondary" text @click="showCreate = false" />
                <Button type="submit" label="Create" :loading="saving" />
            </div>
        </form>
    </Dialog>
</template>
