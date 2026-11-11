<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import { useAuthStore } from '@/modules/auth/stores/auth'
import { tenancyApi } from '@/modules/tenancy/api'
import { useTenantStore } from '@/modules/tenancy/stores/tenant'
import type { Invitation, Member, TenantRole } from '@/modules/tenancy/types'
import { ApiResponseError } from '@/utils/api'

const auth = useAuthStore()
const tenant = useTenantStore()
const toast = useToast()
const confirm = useConfirm()

const members = ref<Member[]>([])
const invitations = ref<Invitation[]>([])
const loading = ref(true)
const showInvite = ref(false)
const sending = ref(false)
const form = reactive<{ email: string; role: Invitation['role'] }>({ email: '', role: 'member' })
const errors = ref<Record<string, string[]>>({})

const roleSeverity: Record<TenantRole, string> = { owner: 'success', admin: 'info', member: 'secondary' }
const allRoles: { label: string; value: TenantRole }[] = [
    { label: 'Owner', value: 'owner' },
    { label: 'Admin', value: 'admin' },
    { label: 'Member', value: 'member' },
]
// An admin may only invite members; an owner may invite admins or members.
const inviteRoles = computed(() => (tenant.isOwner ? allRoles.filter((r) => r.value !== 'owner') : allRoles.filter((r) => r.value === 'member')))

function fail(e: unknown, fallback: string) {
    toast.add({ severity: 'error', summary: e instanceof Error ? e.message : fallback, life: 5000 })
}

function canRemove(m: Member): boolean {
    if (m.id === auth.user?.id) return false // use "Leave workspace"
    return tenant.isOwner || (tenant.isAdmin && m.role === 'member')
}

async function load() {
    loading.value = true
    try {
        members.value = await tenancyApi.members()
        invitations.value = tenant.isAdmin ? await tenancyApi.invitations() : []
    } catch (e) {
        fail(e, 'Could not load members')
    } finally {
        loading.value = false
    }
}

async function changeRole(m: Member, role: TenantRole) {
    try {
        await tenancyApi.changeRole(m.id, role)
        toast.add({ severity: 'success', summary: 'Role updated', life: 3000 })
        if (m.id === auth.user?.id) await tenant.load()
    } catch (e) {
        fail(e, 'Could not change role')
    }
    await load()
}

function remove(m: Member) {
    confirm.require({
        message: `Remove ${m.name} from this workspace?`,
        header: 'Remove member',
        icon: 'pi pi-exclamation-triangle',
        acceptProps: { label: 'Remove', severity: 'danger' },
        rejectProps: { label: 'Cancel', severity: 'secondary', text: true },
        accept: async () => {
            try {
                await tenancyApi.removeMember(m.id)
            } catch (e) {
                fail(e, 'Could not remove member')
            }
            await load()
        },
    })
}

function leave() {
    confirm.require({
        message: 'Leave this workspace? You will lose access to its data.',
        header: 'Leave workspace',
        icon: 'pi pi-exclamation-triangle',
        acceptProps: { label: 'Leave', severity: 'danger' },
        rejectProps: { label: 'Cancel', severity: 'secondary', text: true },
        accept: async () => {
            try {
                await tenancyApi.leave()
                await Promise.all([tenant.load(), auth.fetchUser()])
                await load()
            } catch (e) {
                fail(e, 'Could not leave workspace')
            }
        },
    })
}

async function invite() {
    errors.value = {}
    sending.value = true
    try {
        await tenancyApi.invite(form.email, form.role)
        toast.add({ severity: 'success', summary: 'Invitation sent', detail: form.email, life: 4000 })
        showInvite.value = false
        form.email = ''
        form.role = 'member'
        await load()
    } catch (e) {
        if (e instanceof ApiResponseError && e.errors) errors.value = e.errors
        else fail(e, 'Could not send the invitation')
    } finally {
        sending.value = false
    }
}

async function revoke(i: Invitation) {
    try {
        await tenancyApi.revokeInvitation(i.id)
        await load()
    } catch (e) {
        fail(e, 'Could not revoke the invitation')
    }
}

onMounted(load)
</script>

<template>
    <div class="flex flex-col gap-6">
        <section class="rounded-xl bg-surface-0 p-6 shadow dark:bg-surface-900">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">Members of {{ tenant.current?.name }}</h2>
                <div class="flex gap-2">
                    <Button v-if="tenant.isAdmin" label="Invite member" icon="pi pi-user-plus" @click="showInvite = true" />
                    <Button label="Leave workspace" icon="pi pi-sign-out" severity="secondary" outlined @click="leave" />
                </div>
            </div>
            <DataTable :value="members" :loading="loading" data-key="id" size="small">
                <template #empty>
                    <p class="py-6 text-center text-muted-color">No members.</p>
                </template>
                <Column field="name" header="Name" />
                <Column field="email" header="Email" />
                <Column header="Role">
                    <template #body="{ data }">
                        <Select
                            v-if="tenant.isOwner"
                            :model-value="data.role"
                            :options="allRoles"
                            option-label="label"
                            option-value="value"
                            size="small"
                            :aria-label="`Role of ${data.name}`"
                            @update:model-value="(role: TenantRole) => role !== data.role && changeRole(data, role)"
                        />
                        <Tag v-else :value="data.role" :severity="roleSeverity[data.role as TenantRole]" />
                    </template>
                </Column>
                <Column header="" class="w-16 text-right">
                    <template #body="{ data }">
                        <Button v-if="canRemove(data)" icon="pi pi-trash" severity="danger" text rounded :aria-label="`Remove ${data.name}`" @click="remove(data)" />
                    </template>
                </Column>
            </DataTable>
        </section>

        <section v-if="tenant.isAdmin" class="rounded-xl bg-surface-0 p-6 shadow dark:bg-surface-900">
            <h2 class="mb-4 text-lg font-semibold">Pending invitations</h2>
            <DataTable :value="invitations" :loading="loading" data-key="id" size="small">
                <template #empty>
                    <p class="py-6 text-center text-muted-color">No pending invitations.</p>
                </template>
                <Column field="email" header="Email" />
                <Column header="Role">
                    <template #body="{ data }"><Tag :value="data.role" :severity="roleSeverity[data.role as TenantRole]" /></template>
                </Column>
                <Column header="Expires">
                    <template #body="{ data }">{{ new Date(data.expires_at).toLocaleDateString() }}</template>
                </Column>
                <Column header="" class="w-16 text-right">
                    <template #body="{ data }">
                        <Button icon="pi pi-times" severity="secondary" text rounded :aria-label="`Revoke invitation for ${data.email}`" @click="revoke(data)" />
                    </template>
                </Column>
            </DataTable>
        </section>

        <Dialog v-model:visible="showInvite" modal header="Invite a member" :style="{ width: '26rem' }">
            <form novalidate @submit.prevent="invite">
                <div class="mb-4 flex flex-col gap-1">
                    <label for="invite-email" class="text-sm font-medium">Email</label>
                    <InputText id="invite-email" v-model="form.email" type="email" maxlength="254" :invalid="!!errors.email" fluid autofocus />
                    <small v-if="errors.email" class="text-red-500">{{ errors.email[0] }}</small>
                </div>
                <div class="mb-4 flex flex-col gap-1">
                    <label for="invite-role" class="text-sm font-medium">Role</label>
                    <Select v-model="form.role" input-id="invite-role" :options="inviteRoles" option-label="label" option-value="value" :invalid="!!errors.role" fluid />
                    <small v-if="errors.role" class="text-red-500">{{ errors.role[0] }}</small>
                </div>
                <p class="mb-4 text-sm text-muted-color">They get an email with a link that works for the signed-in account using that address, and expires in 7 days.</p>
                <div class="flex justify-end gap-2">
                    <Button type="button" label="Cancel" severity="secondary" text @click="showInvite = false" />
                    <Button type="submit" label="Send invitation" icon="pi pi-send" :loading="sending" />
                </div>
            </form>
        </Dialog>
    </div>
</template>
