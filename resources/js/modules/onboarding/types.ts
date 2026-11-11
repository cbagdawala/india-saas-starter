export interface OnboardingStep {
    key: string
    title: string
    body: string
    cta_label: string
    to: string
}

export interface OnboardingState {
    steps: OnboardingStep[]
    completed: boolean
    onboarded_at: string | null
}
