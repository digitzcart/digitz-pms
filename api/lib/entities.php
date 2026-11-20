<?php
declare(strict_types=1);
// Whitelist of entities and fields that may be synced (§6.3).
// vault_files, attachments, sync_queue and trash are local-only and are NOT listed here.
// Must stay in step with SYNC_FIELDS in index.html.

const COMMON_FIELDS = ['id', 'created_at', 'updated_at', 'deleted_at', 'owner_user_id', 'family_member_id', 'notes'];

const SYNC_ENTITIES = [
    'properties'        => ['name', 'type', 'address', 'community', 'emirate', 'owner_name', 'owner_mobile', 'size_sqft', 'rent_fils', 'status', 'amenities'],
    'rooms'             => ['property_id', 'name', 'size_sqft', 'rent_fils', 'status', 'amenities'],
    'bed_spaces'        => ['property_id', 'room_id', 'name', 'rent_fils', 'status'],
    'tenants'           => ['code', 'name', 'mobile', 'email', 'nationality', 'id_type', 'id_number', 'emergency_name', 'emergency_mobile'],
    'leases'            => ['unit_type', 'unit_id', 'property_id', 'tenant_id', 'start_date', 'end_date', 'annual_rent_fils', 'admin_fee_fils', 'extra_charges', 'deposit_fils', 'deposit_status', 'deposit_refunded_fils', 'schedule_type', 'payment_method', 'status', 'renewed_from'],
    'payment_schedules' => ['lease_id', 'property_id', 'seq', 'due_date', 'amount_fils', 'method', 'status', 'paid_on', 'pdc_id', 'transaction_id'],
    'pdcs'              => ['direction', 'cheque_no', 'bank', 'amount_fils', 'due_date', 'status', 'party', 'lease_id', 'schedule_id', 'property_id', 'replaced_by'],
    'transactions'      => ['doc_no', 'type', 'category', 'categories', 'splits', 'lines', 'amount_fils', 'date', 'property_id', 'lease_id', 'tenant_id', 'method', 'reference'],
    'expenses'          => ['doc_no', 'date', 'amount_fils', 'merchant', 'category', 'property_id', 'source'],
    'bills'             => ['doc_no', 'name', 'provider', 'account_no', 'amount_fils', 'due_date', 'status', 'paid_on', 'property_id'],
    'health_logs'       => ['kind', 'date', 'weight_kg', 'systolic', 'diastolic', 'pulse', 'sugar', 'sugar_unit', 'sugar_type', 'cycle_start', 'cycle_end'],
    'medicines'         => ['name', 'dose', 'times', 'start_date', 'end_date', 'active'],
    'appointments'      => ['doctor', 'clinic', 'date_time', 'reason', 'remind_minutes'],
    'food_timetable'    => ['day', 'time', 'meal', 'items'],
    'family_members'    => ['name', 'relationship', 'dob', 'mobile'],
    'ledger_accounts'   => ['code', 'name', 'type', 'active'],
];

/** Keeps whitelisted fields only; rejects nested objects deeper than simple arrays of scalars/flat objects. */
function filter_payload(string $entity, array $rec): array {
    $allowed = array_flip(array_merge(COMMON_FIELDS, SYNC_ENTITIES[$entity]));
    $out = [];
    foreach ($rec as $k => $v) {
        if (!isset($allowed[$k])) continue;
        if (is_array($v)) {
            foreach ($v as $item) {
                if (is_array($item)) foreach ($item as $x) if (is_array($x)) fail('BAD_REQUEST', 422, ['field' => $k]);
            }
        } elseif (is_string($v) && mb_strlen($v) > 5000) {
            fail('PAYLOAD_TOO_LARGE', 413, ['field' => $k]);
        }
        $out[$k] = $v;
    }
    return $out;
}
