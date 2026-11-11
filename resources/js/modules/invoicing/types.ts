export interface InvoiceLine {
    position: number
    description: string
    sac: string
    quantity: number
    unit_price: number
    discount: number
    taxable_value: number
    rate_bp: number
    cgst: number
    sgst: number
    igst: number
    total: number
}

export interface Party {
    legal_name: string
    address: string
    gstin: string | null
    state_code: string | null
    state_name: string | null
    country?: string
}

export interface Invoice {
    id: number
    type: 'invoice' | 'credit_note'
    number: string
    issued_at: string
    paid_at: string | null
    status: 'paid' | 'unpaid' | 'credited'
    payment_method: 'gateway' | 'manual'
    supply_type: 'intra' | 'inter' | 'export'
    place_of_supply: string
    taxable_value: number
    cgst: number
    sgst: number
    igst: number
    total_tax: number
    total: number
    original_invoice_id: number | null
    // detail only
    total_in_words?: string
    tax_note?: string | null
    credit_reason?: string | null
    reverse_charge?: boolean
    seller?: Party
    buyer?: Party
    lines?: InvoiceLine[]
    upi?: { link: string; qr: string; note: string } | null
}

export interface BillingProfile {
    legal_name: string | null
    address_line1: string | null
    address_line2: string | null
    city: string | null
    pincode: string | null
    country: string
    state_code: string | null
    gstin: string | null
    complete: boolean
}
