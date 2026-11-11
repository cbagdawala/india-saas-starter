<script setup lang="ts">
import { useAuthStore } from '@/modules/auth/stores/auth'

const auth = useAuthStore()
</script>

<template>
    <div class="rounded-xl bg-surface-0 p-6 shadow dark:bg-surface-900">
        <h2 class="text-xl font-semibold">Welcome, {{ auth.user?.name }}</h2>
        <p class="mt-2 text-muted-color">
            This is the dashboard placeholder. Replace it with your product's core feature; auth, billing, health and error reporting are already wired.
        </p>
        <RouterLink to="/billing" class="mt-4 inline-block text-primary">Manage your plan</RouterLink>
    </div>
</template>
