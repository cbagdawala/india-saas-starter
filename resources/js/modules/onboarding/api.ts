import api from '@/utils/api'
import type { OnboardingState } from './types'

export const onboardingApi = {
    state: () => api.get<OnboardingState>('/api/onboarding'),
    complete: () => api.post<OnboardingState>('/api/onboarding/complete'),
}
