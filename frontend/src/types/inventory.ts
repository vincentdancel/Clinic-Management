export interface MedicineCategory {
  id: number
  name: string
  description: string | null
  medicines_count?: number
}

export interface InventorySummary {
  total_medicines: number
  active_medicines: number
  low_stock_count: number
  out_of_stock_count: number
  expiring_soon_count: number
  expired_batches_count: number
  total_inventory_value: number
}

export interface InventoryOptions {
  categories: MedicineCategory[]
  dosage_forms: string[]
  units: string[]
  summary: InventorySummary
}

export interface MedicineBatch {
  id: number
  batch_number: string
  received_at: string
  expires_at: string | null
  quantity_received: number
  quantity_remaining: number
  unit_cost: number
  is_expired: boolean
}

export interface InventoryTransaction {
  id: number
  medicine_id?: number
  medicine_name?: string | null
  medicine_sku?: string | null
  unit?: string | null
  batch_number?: string | null
  type: 'stock_in' | 'dispense' | 'adjustment_in' | 'adjustment_out' | 'expired' | 'damaged' | 'return'
  quantity_change: number
  unit_cost: number
  reference_number: string | null
  notes: string | null
  occurred_at: string | null
  performer_name: string | null
}

export interface MedicineItem {
  id: number
  medicine_category_id: number
  category_name: string | null
  sku: string
  name: string
  generic_name: string | null
  brand: string | null
  dosage_form: string | null
  unit: string
  reorder_level: number
  unit_cost: number
  selling_price: number
  manufacturer: string | null
  requires_prescription: boolean
  is_active: boolean
  stock_quantity: number
  is_low_stock: boolean
  is_out_of_stock: boolean
  active_batches_count: number
  earliest_expiry: string | null
  deleted_at: string | null
  batches?: MedicineBatch[]
  transactions?: InventoryTransaction[]
}

