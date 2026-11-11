<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import { billingApi } from '@/modules/billing/api'
import type { Checkout, Plan, Subscription } from '@/modules/billing/types'
import { ApiResponseError } from '@/utils/api'
import { formatMoney } from '@/utils/format'

const router = useRouter()
const plans = ref<Plan[]>([])
const subscription = ref<Subscription | null>(null)
const loading = ref(true)
const busy = ref<string | null>(null)
const error = ref('')
const info = ref('')

const isLive = computed(() => subscription.value?.status === 'active' || subscription.value?.status === 'past_due')

async function load() {
    loading.value = true
    try {
        ;[plans.value, subscription.value] = await Promise.all([billingApi.plans(), billingApi.subscription()])
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Could not load billing.'
    } finally {
        loading.value = false
    }
}

function loadRazorpayScript(): Promise<void> {
    if ((window as unknown as { Razorpay?: unknown }).Razorpay) return Promise.resolve()
    return new Promise((resolve, reject) => {
        const script = document.createElement('script')
        script.src = 'https://checkout.razorpay.com/v1/checkout.js'
        script.onload = () => resolve()
        script.onerror = () => reject(new Error('Could not load Razorpay checkout.'))
        document.head.appendChild(script)
    })
}

async function confirm(payload: { razorpay_subscription_id: string; razorpay_payment_id: string; razorpay_signature: string }) {
    subscription.value = await billingApi.confirm(payload)
    info.value = 'Payment confirmed. Your subscription is active.'
}

async function openCheckout(checkout: Checkout) {
    if (checkout.driver === 'fake') {
        // Test-only driver: complete the "payment" immediately with the fake signature.
        await confirm({
            razorpay_subscription_id: checkout.reference,
            razorpay_payment_id: `pay_fake_${Date.now()}`,
            razorpay_signature: checkout.signature ?? '',
        })
        return
    }
    await loadRazorpayScript()
    const Razorpay = (window as unknown as { Razorpay: new (o: object) => { open: () => void } }).Razorpay
    new Razorpay({
        key: checkout.key_id,
        subscription_id: checkout.subscription_id,
        name: 'India SaaS Starter Kit',
        handler: (res: { razorpay_payment_id: string; razorpay_subscription_id: string; razorpay_signature: string }) => void confirm(res),
    }).open()
}

async function subscribe(plan: Plan) {
    error.value = info.value = ''
    busy.value = plan.key
    try {
        await openCheckout(await billingApi.checkout(plan.key))
        subscription.value = await billingApi.subscription()
    } catch (e) {
        // The invoice for a payment cannot be edited later, so the billing profile has to be complete first.
        if (e instanceof ApiResponseError && e.errors?.billing_profile) {
            await router.push({ name: 'billing-profile', query: { needed: '1' } })
            return
        }
        error.value = e instanceof ApiResponseError ? e.message : 'Checkout failed. Please try again.'
    } finally {
        busy.value = null
    }
}

async function cancel() {
    error.value = info.value = ''
    busy.value = 'cancel'
    try {
        subscription.value = await billingApi.cancel()
        info.value = 'Subscription cancelled.'
    } catch (e) {
        error.value = e instanceof ApiResponseError ? e.message : 'Could not cancel.'
    } finally {
        busy.value = null
    }
}

onMounted(load)
</script>

<template>
    <div class="max-w-4xl">
        <Message v-if="error" severity="error" class="mb-4" :closable="false">{{ error }}</Message>
        <Message v-if="info" severity="success" class="mb-4" :closable="false">{{ info }}</Message>

        <div v-if="subscription" class="mb-6 flex items-center justify-between rounded-xl bg-surface-0 p-4 shadow dark:bg-surface-900">
            <div>
                <div class="font-medium">Current plan: <span class="capitalize">{{ subscription.plan }}</span></div>
                <div class="text-sm text-muted-color">
                    {{ formatMoney(subscription.amount, subscription.currency) }} / month
                    <template v-if="subscription.current_period_end"> · renews {{ new Date(subscription.current_period_end).toLocaleDateString() }}</template>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <Tag :value="subscription.status" :severity="isLive ? 'success' : 'secondary'" />
                <Button v-if="isLive" label="Cancel" severity="danger" size="small" outlined :loading="busy === 'cancel'" @click="cancel" />
            </div>
        </div>

        <p v-if="loading" class="text-muted-color">Loading plans...</p>
        <div v-else class="grid gap-4 sm:grid-cols-2">
            <div v-for="plan in plans" :key="plan.key" class="flex flex-col rounded-xl bg-surface-0 p-6 shadow dark:bg-surface-900">
                <h3 class="text-lg font-semibold">{{ plan.name }}</h3>
                <div class="my-2 text-3xl font-bold">{{ formatMoney(plan.amount, plan.currency) }}<span class="text-sm font-normal text-muted-color"> / {{ plan.interval }}</span></div>
                <ul class="mb-4 flex-1 text-sm text-muted-color">
                    <li v-for="f in plan.features" :key="f"><i class="pi pi-check mr-2 text-primary" />{{ f }}</li>
                </ul>
                <Button :label="`Choose ${plan.name}`" :disabled="isLive" :loading="busy === plan.key" @click="subscribe(plan)" />
            </div>
        </div>
    </div>
</template>
