import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import ConfirmationService from 'primevue/confirmationservice'
import Aura from '@primeuix/themes/aura'
import 'primeicons/primeicons.css'
import '../css/app.css'

import App from './App.vue'
import router from './router'
import { useAuthStore } from '@/modules/auth/stores/auth'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(PrimeVue, {
    theme: {
        preset: Aura,
        options: { darkModeSelector: '.app-dark', cssLayer: { name: 'primevue', order: 'theme, base, primevue' } },
    },
})
app.use(ToastService)
app.use(ConfirmationService)

// Resolve the session (GET /api/auth/me) before the first navigation so the guard has a user.
useAuthStore(pinia)
    .fetchUser()
    .finally(() => {
        app.use(router)
        app.mount('#app')
    })
