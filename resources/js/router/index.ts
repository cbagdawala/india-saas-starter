import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/modules/auth/stores/auth'

declare module 'vue-router' {
    interface RouteMeta {
        requiresAuth?: boolean
        guest?: boolean
        title?: string
    }
}

const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/login',
            name: 'login',
            component: () => import('@/pages/LoginPage.vue'),
            meta: { guest: true, title: 'Sign in' },
        },
        {
            path: '/forgot-password',
            name: 'forgot-password',
            component: () => import('@/pages/ForgotPasswordPage.vue'),
            meta: { guest: true, title: 'Forgot password' },
        },
        {
            path: '/reset-password',
            name: 'reset-password',
            component: () => import('@/pages/ResetPasswordPage.vue'),
            meta: { title: 'Reset password' },
        },
        {
            path: '/register',
            name: 'register',
            component: () => import('@/pages/RegisterPage.vue'),
            meta: { guest: true, title: 'Create account' },
        },
        {
            path: '/welcome',
            name: 'onboarding',
            component: () => import('@/pages/OnboardingPage.vue'),
            meta: { requiresAuth: true, title: 'Welcome' },
        },
        {
            path: '/verify-email',
            name: 'verify-email',
            component: () => import('@/pages/VerifyEmailPage.vue'),
            meta: { title: 'Verify email' },
        },
        {
            path: '/invitations/accept',
            name: 'accept-invitation',
            component: () => import('@/pages/AcceptInvitationPage.vue'),
            meta: { requiresAuth: true, title: 'Join workspace' },
        },
        {
            path: '/',
            component: () => import('@/layouts/AppLayout.vue'),
            meta: { requiresAuth: true },
            children: [
                { path: '', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue'), meta: { title: 'Dashboard' } },
                { path: 'billing', name: 'billing', component: () => import('@/pages/BillingPage.vue'), meta: { title: 'Billing' } },
                { path: 'invoices', name: 'invoices', component: () => import('@/pages/InvoicesPage.vue'), meta: { title: 'Invoices' } },
                { path: 'invoices/:id(\\d+)', name: 'invoice', component: () => import('@/pages/InvoiceDetailPage.vue'), meta: { title: 'Invoice' } },
                { path: 'billing-profile', name: 'billing-profile', component: () => import('@/pages/BillingProfilePage.vue'), meta: { title: 'Billing profile' } },
                { path: 'account', name: 'account', component: () => import('@/pages/AccountPage.vue'), meta: { title: 'Account' } },
                { path: 'members', name: 'members', component: () => import('@/pages/MembersPage.vue'), meta: { title: 'Members' } },
                { path: 'support', name: 'support', component: () => import('@/pages/SupportPage.vue'), meta: { title: 'Support' } },
            ],
        },
        { path: '/:pathMatch(.*)*', redirect: { name: 'dashboard' } },
    ],
})

export const INVITE_TOKEN_KEY = 'pending_invitation_token'

router.beforeEach((to) => {
    // The invitation token is a one-time secret. Move it out of the address bar (and out of any login redirect query,
    // history and referrers) into sessionStorage straight away; the accept page reads it from there.
    if (to.name === 'accept-invitation' && typeof to.query.token === 'string') {
        sessionStorage.setItem(INVITE_TOKEN_KEY, to.query.token)
        return { name: 'accept-invitation', replace: true }
    }

    const auth = useAuthStore()
    if (to.meta.requiresAuth && !auth.isAuthenticated) {
        return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
    }
    if (to.meta.guest && auth.isAuthenticated) {
        return { name: 'dashboard' }
    }
    return true
})

router.afterEach((to) => {
    document.title = to.meta.title ? `${to.meta.title} · India SaaS Starter Kit` : 'India SaaS Starter Kit'
})

export default router
