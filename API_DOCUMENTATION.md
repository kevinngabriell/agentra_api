# Agentra API Documentation

## Overview

- **Base URL:** `http://localhost/agentra_api`
- **API Prefix:** `/api/v1`
- **Auth Method:** JWT Bearer Token (`Authorization: Bearer <access_token>`)
- **Content-Type:** `application/json`
- **Response Format:**
```json
{
  "status_code": 200,
  "status_message": "Success",
  "data": { ... }
}
```

---

## Recommended Testing Order in Postman

Follow this sequence to avoid dependency errors:

```
1.  Register          → POST  /auth/register
2.  Login             → POST  /auth/login                   ← save access_token + refresh_token
3.  Get Plans         → GET   /plans?app_id=...             (no auth needed)
4.  Create Insurer    → POST  /insurers
5.  Create Customer   → POST  /customers
6.  Create Policy     → POST  /policies                     ← auto-creates commission record
7.  Add Coverages     → POST  /policies/{id}/coverages      ← optional, syncs premium & commission
8.  Follow-up         → POST  /policies/{id}/follow-ups
9.  Update Statuses   → PATCH /policies/{id}/payment-status
                        PATCH /policies/{id}/renewal-status
10. View Commission   → GET   /policies/{id}/commission     ← agent sees expected commission
11. View History      → GET   /policies/{id}/logs           ← full event timeline
12. Commission List   → GET   /commissions                  ← all commission records
13. Commission Stats  → GET   /commissions/summary          ← pending/received/discrepancy totals
14. Mark Received     → PATCH /commissions/{id}/mark-received ← record actual payment from insurer
```

> **Tip:** Set a Postman environment variable `{{access_token}}` after login, then use `Authorization: Bearer {{access_token}}` on all protected endpoints.

---

## 1. Authentication

### 1.1 Register

**POST** `/api/v1/auth/register`

No auth required.

**Request Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "secret123",
  "password_confirmation": "secret123",
  "phone": "081234567890",
  "business_name": "PT Asuransi Maju",
  "city": "Jakarta",
  "plan": "<plan_id>",
  "app_id": "<app_id>",
  "app_role_id": "<app_role_id>"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| name | string | Yes | Full name |
| email | string | Yes | User email |
| password | string | Yes | Min 8 characters |
| password_confirmation | string | Yes | Must match password |
| phone | string | Yes | Phone number |
| business_name | string | Yes | Company/agency name |
| city | string | Yes | City |
| plan | string | Yes | Plan ID from `/plans` |
| app_id | string | No | Application ID |
| app_role_id | string | No | Role ID |

**Response `201`:**
```json
{
  "status_code": 201,
  "status_message": "Account created successfully",
  "data": {
    "user_id": "usr_xxx",
    "company_id": "cmp_xxx",
    "subscription_id": "sub_xxx",
    "account_status": "trial",
    "trial_expires_at": "2025-07-05"
  }
}
```

---

### 1.2 Login

**POST** `/api/v1/auth/login`

No auth required.

**Request Body:**
```json
{
  "email": "john@example.com",
  "password": "secret123"
}
```

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Login successful",
  "data": {
    "access_token": "eyJ...",
    "refresh_token": "eyJ...",
    "token_type": "Bearer",
    "expires_in": 3600
  }
}
```

> Save `access_token` and `refresh_token` as Postman environment variables.

---

### 1.3 Refresh Token

**POST** `/api/v1/auth/refresh`

No auth required.

**Request Body:**
```json
{
  "refresh_token": "eyJ..."
}
```

**Response `200`:**
```json
{
  "data": {
    "access_token": "eyJ...",
    "refresh_token": "eyJ...",
    "token_type": "Bearer",
    "expires_in": 3600
  }
}
```

---

### 1.4 Logout

**POST** `/api/v1/auth/logout`

Auth optional (stateless — client discards tokens).

**Response `200`:**
```json
{ "status_message": "Logged out successfully" }
```

---

### 1.5 Forgot Password

**POST** `/api/v1/auth/forgot-password`

No auth required.

**Request Body:**
```json
{
  "email": "john@example.com"
}
```

**Response `200`:** Always succeeds (privacy-safe).

---

### 1.6 Reset Password

**POST** `/api/v1/auth/reset-password`

No auth required.

**Request Body:**
```json
{
  "token": "<reset_token_from_email>",
  "password": "newpassword123",
  "password_confirmation": "newpassword123"
}
```

---

## 2. Plans & Subscription

### 2.1 List Plans

**GET** `/api/v1/plans?app_id=<app_id>`

No auth required.

**Query Parameters:**
| Param | Required | Description |
|---|---|---|
| app_id | Yes | Application ID |

**Response `200`:**
```json
{
  "data": [
    {
      "plan_id": "svc_xxx",
      "name": "Starter",
      "tagline": "For small agencies",
      "price_idr": 299000,
      "billing_cycle": "monthly",
      "is_available": true,
      "features": ["Up to 100 policies", "Email support"]
    }
  ]
}
```

---

### 2.2 Get Plan Detail

**GET** `/api/v1/plans/{plan_id}`

No auth required.

---

### 2.3 Get Current Subscription

**GET** `/api/v1/subscription/current`

Auth required.

**Response `200`:**
```json
{
  "data": {
    "subscription_id": "sub_xxx",
    "plan_name": "Starter",
    "billing_cycle": "monthly",
    "status": "active",
    "next_billing_date": "2025-07-05",
    "usage": { "policies": 42, "max_policies": 100 }
  }
}
```

---

### 2.4 Change Plan

**PUT** `/api/v1/subscription/change-plan`

Auth required.

**Request Body:**
```json
{
  "new_plan_id": "svc_yyy",
  "billing_cycle": "monthly"
}
```

| Field | Type | Required | Values |
|---|---|---|---|
| new_plan_id | string | Yes | Plan ID from `/plans` |
| billing_cycle | string | Yes | `monthly` or `yearly` |

---

## 3. User Profile

### 3.1 Get My Profile

**GET** `/api/v1/users/me`

Auth required.

**Response `200`:**
```json
{
  "data": {
    "user_id": "usr_xxx",
    "username": "johndoe",
    "first_name": "John",
    "email": "john@example.com",
    "phone_number": "081234567890",
    "language": "id",
    "app_role_id": "role_xxx",
    "account_status": "active"
  }
}
```

---

### 3.2 Update My Profile

**PUT** `/api/v1/users/me`

Auth required.

**Request Body (all optional):**
```json
{
  "first_name": "John",
  "phone_number": "081234567890",
  "language": "id"
}
```

---

### 3.3 Change Password

**PUT** `/api/v1/users/me/password`

Auth required.

**Request Body:**
```json
{
  "current_password": "oldpassword",
  "new_password": "newpassword123"
}
```

---

### 3.4 Update Notification Settings

**PUT** `/api/v1/users/me/notification-settings`

Auth required.

**Request Body:**
```json
{
  "daily_digest_enabled": true,
  "daily_digest_time": "08:00",
  "daily_days_of_week": "1,2,3,4,5",
  "monthly_digest_enabled": true,
  "monthly_digest_day": 1,
  "monthly_digest_time": "09:00",
  "whatsapp_target_number": "628123456789"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| whatsapp_target_number | string | Yes (first time) | WhatsApp number with country code |
| daily_digest_enabled | boolean | No | Enable daily digest |
| daily_digest_time | string | No | HH:MM format |
| daily_days_of_week | string | No | Comma-separated 1–7 (Mon–Sun) |
| monthly_digest_enabled | boolean | No | Enable monthly digest |
| monthly_digest_day | integer | No | Day of month, 1–28 |
| monthly_digest_time | string | No | HH:MM format |

---

## 4. Insurers

### 4.1 List Insurers

**GET** `/api/v1/insurers`

Auth required.

**Query Parameters:**
| Param | Required | Description |
|---|---|---|
| is_active | No | `true` or `false` |

**Response `200`:**
```json
{
  "data": [
    {
      "insurer_id": "ins_xxx",
      "name": "PT Asuransi Sinar Mas",
      "short_name": "SIMAS",
      "agent_code": "AG001",
      "is_primary": true,
      "is_active": true,
      "notes": null
    }
  ]
}
```

---

### 4.2 Create Insurer

**POST** `/api/v1/insurers`

Auth required.

**Request Body:**
```json
{
  "name": "PT Asuransi Sinar Mas",
  "short_name": "SIMAS",
  "agent_code": "AG001",
  "notes": "Primary partner",
  "is_primary": true
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| name | string | Yes | Full insurer name |
| short_name | string | Yes | Short code (auto-uppercased) |
| agent_code | string | No | Your agent code at this insurer |
| notes | string | No | Internal notes |
| is_primary | boolean | No | Mark as primary insurer |

**Response `201`:**
```json
{ "data": { "insurer_id": "ins_xxx" } }
```

---

### 4.3 Get Insurer Detail

**GET** `/api/v1/insurers/{insurer_id}`

Auth required.

---

### 4.4 Update Insurer

**PUT** `/api/v1/insurers/{insurer_id}`

Auth required.

**Request Body (any fields):**
```json
{
  "name": "PT Asuransi Sinar Mas Tbk",
  "agent_code": "AG002",
  "is_primary": false
}
```

---

### 4.5 Delete Insurer

**DELETE** `/api/v1/insurers/{insurer_id}`

Auth required. Soft-deletes (deactivates) the insurer.

---

## 5. Customers

### 5.1 List Customers

**GET** `/api/v1/customers`

Auth required.

**Query Parameters:**
| Param | Required | Description |
|---|---|---|
| params | No | Search text (name, NIK, NPWP) |
| customer_type | No | `individual` or `company` |
| status | No | `active`, `inactive`, `lapsed` |
| page | No | Default: 1 |
| limit | No | Default: 10, max: 100 |

---

### 5.2 Create Customer — Individual

**POST** `/api/v1/customers`

Auth required.

**Request Body:**
```json
{
  "customer_type": "individual",
  "display_name": "Budi Santoso",
  "nik": "3271012501900001",
  "status": "active",
  "source": "direct",
  "notes": "VIP client",
  "date_of_birth": "1990-01-25",
  "personal_phone": "081234567890",
  "personal_whatsapp": "081234567890",
  "personal_email": "budi@example.com",
  "personal_address": "Jl. Sudirman No. 1, Jakarta",
  "npwp_personal": "12.345.678.9-001.000",
  "referred_by_agent_id": null
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| customer_type | string | Yes | Must be `individual` |
| display_name | string | Yes | Full name |
| nik | string | Yes | 16-digit NIK |
| status | string | No | `active`, `inactive`, `lapsed` |
| source | string | No | `direct` or `referral` |

---

### 5.3 Create Customer — Company

**POST** `/api/v1/customers`

Auth required.

**Request Body:**
```json
{
  "customer_type": "company",
  "display_name": "PT Maju Bersama",
  "company_legal_name": "PT Maju Bersama Indonesia",
  "npwp_company": "12.345.678.9-001.000",
  "pic_name": "Siti Rahayu",
  "pic_phone": "082345678901",
  "pic_whatsapp": "082345678901",
  "status": "active",
  "source": "direct",
  "business_type": "Trading",
  "nib": "123456789",
  "operational_address": "Jl. Thamrin No. 5, Jakarta",
  "legal_address": "Jl. Thamrin No. 5, Jakarta",
  "company_phone": "0212345678",
  "company_email": "info@majubersama.co.id",
  "pic_role": "Finance Manager",
  "pic_email": "siti@majubersama.co.id"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| customer_type | string | Yes | Must be `company` |
| display_name | string | Yes | Brand/trade name |
| company_legal_name | string | Yes | Legal entity name |
| npwp_company | string | Yes | NPWP number |
| pic_name | string | Yes | Contact person name |
| pic_phone | string | Yes | Contact phone |
| pic_whatsapp | string | Yes | Contact WhatsApp |

**Response `201`:**
```json
{ "data": { "customer_id": "cst_xxx" } }
```

---

### 5.4 Get Customer Detail

**GET** `/api/v1/customers/{customer_id}`

Auth required. Returns full profile including recent linked policies.

---

### 5.5 Update Customer

**PUT** `/api/v1/customers/{customer_id}`

Auth required.

**Request Body (any updatable fields):**
```json
{
  "display_name": "Budi Santoso Jr.",
  "personal_phone": "089876543210",
  "notes": "Updated contact"
}
```

---

### 5.6 Delete Customer

**DELETE** `/api/v1/customers/{customer_id}`

Auth required.

---

### 5.7 Update Customer Status

**PUT** `/api/v1/customers/{customer_id}/status`

Auth required.

**Request Body:**
```json
{
  "status": "inactive"
}
```

| Value | Description |
|---|---|
| `active` | Active customer |
| `inactive` | Temporarily inactive |
| `lapsed` | No renewal, churned |

---

### 5.8 Get Customer Follow-ups

**GET** `/api/v1/customers/{customer_id}/follow-ups`

Auth required.

**Query Parameters:**
| Param | Default | Max |
|---|---|---|
| page | 1 | — |
| limit | 10 | 100 |

---

### 5.9 Import Customers (CSV)

**POST** `/api/v1/customers/import`

Auth required.

**Request:** `multipart/form-data`

| Field | Type | Required |
|---|---|---|
| file | CSV file | Yes |

**CSV Columns:**
| Column | Required | Description |
|---|---|---|
| customer_type | Yes | `individual` or `company` |
| display_name | Yes | Name |
| status | No | `active`, `inactive`, `lapsed` |
| source | No | `direct` or `referral` |
| nik | Conditional | Required if individual |
| company_legal_name | Conditional | Required if company |
| npwp_company | Conditional | Required if company |
| pic_name | Conditional | Required if company |
| pic_phone | Conditional | Required if company |
| pic_whatsapp | Conditional | Required if company |

**Response `200`:**
```json
{
  "data": {
    "inserted": 45,
    "failed": 3,
    "failures": [
      { "row": 5, "reason": "Missing NIK for individual customer" }
    ]
  }
}
```

---

## 6. Policies

### 6.1 List Policies

**GET** `/api/v1/policies`

Auth required.

**Query Parameters:**
| Param | Description |
|---|---|
| page | Default: 1 |
| limit | Default: 10, max: 100 |
| search | Search by policy number or customer name |
| customer_id | Filter by customer |
| product_type | `fire`, `motorcycle`, `car`, `travel`, `cargo`, `other`, `kecelakaan`, `aep` |
| insurer_id | Filter by insurer |
| renewal_status | `pending`, `renewed`, `lapsed`, `cancelled` |
| expiry_month | Format: `YYYY-MM` |
| agent_id | Filter by issuing agent |

---

### 6.2 Create Policy

**POST** `/api/v1/policies`

Auth required. Requires an existing `customer_id` and `insurer_id`.

**Request Body:**
```json
{
  "insurer_id": "ins_xxx",
  "customer_id": "cst_xxx",
  "policy_number": "POL/SIMAS/2025/00001",
  "product_type": "car",
  "coverage_start": "2025-06-01",
  "coverage_end": "2026-06-01",
  "sum_insured": 300000000,
  "premium_amount": 4500000,
  "commission_rate": 10.5,
  "policy_year": 1,
  "issuing_agent_id": null,
  "previous_policy_id": null,
  "object_insured": "Toyota Avanza 2020 - B 1234 XYZ",
  "coverage_notes": "Comprehensive with flood extension",
  "notes": "Renewal from last year"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| insurer_id | string | Yes | From `/insurers` |
| customer_id | string | Yes | From `/customers` |
| policy_number | string | Yes | Unique per company |
| product_type | string | Yes | `fire`, `motorcycle`, `car`, `travel`, `cargo`, `other`, `kecelakaan`, `aep` |
| coverage_start | string | Yes | YYYY-MM-DD |
| coverage_end | string | Yes | YYYY-MM-DD |
| sum_insured | integer | Yes | Coverage amount (IDR) |
| premium_amount | integer | Yes | Premium (IDR) |
| commission_rate | float | **No** | Commission %. If omitted, auto-resolved from policy number prefix and product type (see [Commission Auto-Resolution](#commission-auto-resolution)). Required only if no rule matches. |
| policy_year | integer | No | Default: 1 |
| object_insured | string | No | Description of insured object |
| coverage_notes | string | No | Coverage details |
| notes | string | No | Internal notes |
| previous_policy_id | string | No | For renewals |

**Response `201`:**
```json
{ "data": { "policy_id": "pol_xxx" } }
```

#### Commission Auto-Resolution

If `commission_rate` is not provided, the API derives it automatically using the following rules (evaluated in order):

| Priority | Condition | Rate |
|---|---|---|
| 1 | `product_type = aep` | 30% |
| 2 | `product_type = kecelakaan` | 20% |
| 3 | Policy number starts with `01`, `08`, `88`, `61`, or `62` | 15% |
| 4 | Policy number starts with `02` | 25% |

If none of the rules match and `commission_rate` is not provided, the API returns `400 commission_rate is required: no rule matched for this policy number prefix or product type`.

You can always pass `commission_rate` explicitly to override auto-resolution.

---

### 6.3 Get Policy Detail

**GET** `/api/v1/policies/{policy_id}`

Auth required. Returns full details including customer and insurer info.

---

### 6.4 Update Policy

**PUT** `/api/v1/policies/{policy_id}`

Auth required. Commission is auto-recalculated if `premium_amount` or `commission_rate` changes.

**Request Body (any updatable fields):**
```json
{
  "sum_insured": 350000000,
  "premium_amount": 5000000,
  "commission_rate": 11.0,
  "object_insured": "Toyota Avanza 2021 - B 1234 XYZ",
  "coverage_notes": "Comprehensive + flood + earthquake",
  "notes": "Updated after endorsement"
}
```

---

### 6.5 Update Renewal Status

**PATCH** `/api/v1/policies/{policy_id}/renewal-status`

Auth required.

**Request Body:**
```json
{
  "renewal_status": "renewed"
}
```

| Value | Description |
|---|---|
| `pending` | Awaiting renewal decision |
| `renewed` | Customer renewed |
| `lapsed` | Policy lapsed, not renewed |
| `cancelled` | Policy cancelled mid-term |

---

### 6.6 Update Payment Status

**PATCH** `/api/v1/policies/{policy_id}/payment-status`

Auth required. **Main Agent only.**

**Request Body:**
```json
{
  "payment_status": "paid"
}
```

| Value | Description |
|---|---|
| `unpaid` | Payment not yet received |
| `paid` | Payment received |
| `confirmed` | Payment confirmed/reconciled |

---

### 6.7 Add Policy Follow-up

**POST** `/api/v1/policies/{policy_id}/follow-ups`

Auth required.

**Request Body:**
```json
{
  "followup_status": "customer_confirmed",
  "channel": "whatsapp",
  "notes": "Customer confirmed renewal interest",
  "follow_up_date": "2025-06-05"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| followup_status | string | No | `not_contacted`, `contacted`, `customer_confirmed`, `customer_declined`, `no_response`. Default: `not_contacted` |
| channel | string | No | `whatsapp`, `phone`, `email`, `in_person` |
| notes | string | No | Free-text notes about the follow-up |
| follow_up_date | string | No | YYYY-MM-DD. Default: today |

**Response `201`:**
```json
{ "data": { "followup_id": "fu_xxx" } }
```

---

### 6.8 Payment Summary

**GET** `/api/v1/policies/payment-summary`

Auth required. **Main Agent only.**

**Query Parameters:**
| Param | Required | Description |
|---|---|---|
| insurer_id | Yes | Filter by insurer |
| month | No | Format: `YYYY-MM` |
| payment_status | No | `all`, `unpaid`, `paid`, `confirmed` |

**Response `200`:**
```json
{
  "data": {
    "insurer": { "insurer_id": "ins_xxx", "name": "PT Asuransi Sinar Mas" },
    "summary": {
      "total_policies": 12,
      "total_premium": 54000000,
      "total_commission": 5670000,
      "unpaid": { "count": 4, "premium": 18000000 },
      "paid": { "count": 6, "premium": 27000000 },
      "confirmed": { "count": 2, "premium": 9000000 }
    },
    "policies": [ { ... } ]
  }
}
```

---

### 6.9 Get Policy Commission

**GET** `/api/v1/policies/{policy_id}/commission`

Auth required. Returns the commission record for this policy so the agent can see the expected amount, received amount, and current status.

> A commission record is **auto-created** when a policy is created (`POST /policies`) and **auto-updated** whenever `premium_amount` or `commission_rate` changes via `PUT /policies/{id}` or any coverage write. There is no manual create/update endpoint — the record is always derived from the policy.

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Commission found",
  "data": {
    "commission_id": "com_6849abc",
    "policy_id": "pol_6849xyz",
    "company_id": "08a0f8a7-...",
    "insurer_id": "ins_xxx",
    "insurer_name": "PT Chubb General Insurance Indonesia",
    "insurer_short_name": "CHUBB",
    "policy_number": "01/08/25/00001",
    "product_type": "kebakaran",
    "coverage_start": "2025-06-01",
    "coverage_end": "2026-06-01",
    "commission_type": "direct",
    "premium_amount": 5000000,
    "commission_rate": "15.00",
    "expected_amount": 750000,
    "received_amount": 0,
    "status": "pending",
    "expected_date": null,
    "received_date": null,
    "reference_number": null,
    "discrepancy_notes": null,
    "marked_by": null,
    "marked_at": null,
    "created_at": "2026-06-16 09:00:00",
    "updated_at": "2026-06-16 09:00:00"
  }
}
```

**Field reference:**

| Field | Description |
|---|---|
| `commission_type` | `direct` = agent's own policy; `override` = from sub-agent policy |
| `premium_amount` | Snapshot of policy premium at last sync (IDR) |
| `commission_rate` | Rate % at last sync |
| `expected_amount` | `premium_amount × commission_rate / 100` (IDR) |
| `received_amount` | Amount actually received from insurer (IDR). `0` until marked received |
| `status` | `pending` → `received` or `discrepancy` (when received ≠ expected). `cancelled` if policy voided |
| `expected_date` | Date agent expects insurer to pay (set manually) |
| `received_date` | Date insurer actually paid |
| `reference_number` | Insurer's payment reference number |
| `discrepancy_notes` | Notes explaining the discrepancy |

**`status` lifecycle:**

```
policy created  →  pending
                      ↓
               mark received
                   /      \
            received     discrepancy
          (matches)     (received ≠ expected)
```

**Response `404`:** Policy not found, or commission record not yet created (only happens for policies created before this feature was deployed).

---

### 6.10 Get Policy Logs

**GET** `/api/v1/policies/{policy_id}/logs`

Auth required. Returns the full chronological event history for a policy — creation, endorsements, payment changes, follow-ups, coverage edits, and renewal status changes — newest first.

> **Tip for FE:** Use this endpoint to render the activity timeline on the policy detail page. Pair with `GET /policies/{id}/commission` (section 6.9) to show the commission card alongside the history.

**Query Parameters:**

| Param | Type | Default | Description |
|---|---|---|---|
| page | int | 1 | Page number |
| limit | int | 20 | Items per page (max 100) |

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Policy logs retrieved",
  "data": {
    "data": [
      {
        "log_id": "plog_abc123",
        "event_type": "payment_status_changed",
        "reference_type": null,
        "reference_id": null,
        "old_value": "unpaid",
        "new_value": "paid",
        "description": "Status pembayaran diubah: unpaid → paid",
        "metadata": null,
        "created_by": "usr_xxx",
        "created_at": "2026-06-16 10:30:00"
      },
      {
        "log_id": "plog_def456",
        "event_type": "followup_logged",
        "reference_type": "follow_up_logs",
        "reference_id": "fu_6849abc",
        "old_value": null,
        "new_value": null,
        "description": "Follow-up dicatat via whatsapp: customer_confirmed",
        "metadata": null,
        "created_by": "usr_xxx",
        "created_at": "2026-06-15 09:00:00"
      },
      {
        "log_id": "plog_ghi789",
        "event_type": "endorsement",
        "reference_type": "policy_coverages",
        "reference_id": "cov_6849xyz",
        "old_value": null,
        "new_value": null,
        "description": "Endorsemen: item pertanggungan ditambahkan — bangunan (Stok 1)",
        "metadata": {
          "coverage_type": "bangunan",
          "sum_insured": 500000000,
          "rate_permille": 2.28,
          "premium_amount": 1140000
        },
        "created_by": "usr_xxx",
        "created_at": "2026-06-14 14:00:00"
      },
      {
        "log_id": "plog_jkl012",
        "event_type": "endorsement",
        "reference_type": null,
        "reference_id": null,
        "old_value": null,
        "new_value": null,
        "description": "Endorsemen: uang pertanggungan, premi",
        "metadata": {
          "before": { "sum_insured": 300000000, "premium_amount": 4500000 },
          "after":  { "sum_insured": 350000000, "premium_amount": 5000000 }
        },
        "created_by": "usr_xxx",
        "created_at": "2026-06-13 11:00:00"
      },
      {
        "log_id": "plog_mno345",
        "event_type": "renewal_status_changed",
        "reference_type": null,
        "reference_id": null,
        "old_value": "pending",
        "new_value": "renewed",
        "description": "Status renewal diubah: pending → renewed",
        "metadata": null,
        "created_by": "usr_xxx",
        "created_at": "2026-06-12 08:00:00"
      },
      {
        "log_id": "plog_pqr678",
        "event_type": "policy_created",
        "reference_type": null,
        "reference_id": null,
        "old_value": null,
        "new_value": null,
        "description": "Polis dibuat",
        "metadata": null,
        "created_by": "usr_xxx",
        "created_at": "2026-06-10 09:00:00"
      }
    ],
    "pagination": {
      "total": 6,
      "page": 1,
      "limit": 20,
      "total_pages": 1
    }
  }
}
```

**`event_type` values:**

| Value | Triggered by | `old_value` / `new_value` |
|---|---|---|
| `policy_created` | `POST /policies` — also auto-creates commission record | — |
| `policy_updated` | `PUT /policies/{id}` (non-financial fields only) | — |
| `endorsement` | `PUT /policies/{id}` (financial/date fields) or any `coverages` write — also auto-syncs commission | — |
| `payment_status_changed` | `PATCH /policies/{id}/payment-status` | e.g. `unpaid` → `paid` |
| `renewal_status_changed` | `PATCH /policies/{id}/renewal-status` | e.g. `pending` → `renewed` |
| `followup_logged` | `POST /policies/{id}/follow-ups` | — |

**`reference_type` values** (when set, `reference_id` is the PK of the linked row):

| Value | Points to |
|---|---|
| `follow_up_logs` | The specific follow-up row |
| `policy_coverages` | The specific coverage item added / updated / deleted |

**`metadata`** is a JSON object present only on events that carry a before/after snapshot or structured detail:
- `endorsement` via `PUT /policies/{id}` → `{ "before": { ... }, "after": { ... } }` (only changed fields)
- `endorsement` via coverage add → `{ "coverage_type", "sum_insured", "rate_permille", "premium_amount" }`
- `endorsement` via coverage update → `{ "before": { "sum_insured", "rate_permille" }, "after": { ... } }`
- `endorsement` via coverage delete → snapshot of the deleted row

---

## 7. Master Products

Manages the product catalog and their default commission rates. `product_code` is what gets stored in `policies.product_type`.

**Base path:** `/api/v1/master-products`

All endpoints require `Authorization: Bearer <access_token>`.

---

### 7.1 List All Master Products

**GET** `/api/v1/master-products`

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Master products found",
  "data": {
    "data": [
      {
        "product_id":      "prod_6849abc123",
        "product_code":    "kebakaran",
        "product_name":    "Kebakaran",
        "commission_rate": "15.00",
        "policy_prefixes": "01,08,88,61,62",
        "is_active":       1,
        "created_at":      "2026-06-13 09:00:00",
        "updated_at":      "2026-06-13 09:00:00"
      },
      {
        "product_id":      "prod_6849abc456",
        "product_code":    "aep",
        "product_name":    "Tanggung Gugat Pihak Ketiga",
        "commission_rate": "30.00",
        "policy_prefixes": null,
        "is_active":       1,
        "created_at":      "2026-06-13 09:00:00",
        "updated_at":      "2026-06-13 09:00:00"
      }
    ]
  }
}
```

---

### 7.2 Create Master Product

**POST** `/api/v1/master-products`

**Request Body:**
```json
{
  "product_code":    "kebakaran",
  "product_name":    "Kebakaran",
  "commission_rate": 15,
  "policy_prefixes": "01,08,88,61,62"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `product_code` | string | Yes | Unique code per company (stored as `product_type` on policies). Lowercase. |
| `product_name` | string | Yes | Human-readable name |
| `commission_rate` | float | Yes | Default commission % (0–100) |
| `policy_prefixes` | string | No | Comma-separated policy number prefixes for auto-detection (e.g. `01,08,88,61,62`) |

**Response `201`:**
```json
{ "data": { "product_id": "prod_xxx" } }
```

---

### 7.3 Get Master Product Detail

**GET** `/api/v1/master-products/{product_id}`

---

### 7.4 Update Master Product

**PUT** `/api/v1/master-products/{product_id}`

All fields optional. Send only what changes.

| Field | Type | Description |
|---|---|---|
| `product_code` | string | Must remain unique within company |
| `product_name` | string | Display name |
| `commission_rate` | float | New default commission % |
| `policy_prefixes` | string | Send empty string `""` to clear prefixes |
| `is_active` | boolean | `false` deactivates the product |

---

### 7.5 Delete Master Product

**DELETE** `/api/v1/master-products/{product_id}`

---

### 7.6 How commission is resolved when creating a policy

When `commission_rate` is omitted in `POST /policies`, the API resolves it automatically:

1. **By `product_type` (exact match)** — finds the master product where `product_code = product_type` and uses its `commission_rate`.
2. **By policy number prefix (fallback)** — extracts the first 2 characters of the policy number and checks if any master product's `policy_prefixes` contains it.

If neither matches, the request returns `400`. Pass `commission_rate` explicitly to override auto-resolution for any product.

### 7.7 Recommended seed data

| `product_code` | `product_name` | `commission_rate` | `policy_prefixes` |
|---|---|---|---|
| `kebakaran` | Kebakaran | 15% | `01,08,88,61,62` |
| `kendaraan` | Kendaraan Bermotor | 25% | `02` |
| `aep` | Tanggung Gugat Pihak Ketiga | 30% | — |
| `kecelakaan` | Kecelakaan Diri | 20% | — |

---

## 8. Not Yet Implemented

These routes return `501 Not Implemented`:

| Endpoint | Description |
|---|---|
| `/api/v1/services` | Services/products catalog |

---

## Common HTTP Status Codes

| Code | Meaning |
|---|---|
| 200 | OK |
| 201 | Created |
| 400 | Bad Request — missing or invalid field |
| 401 | Unauthorized — missing or expired token |
| 403 | Forbidden — insufficient role/permission |
| 404 | Not Found |
| 409 | Conflict — duplicate entry (e.g. policy number already exists) |
| 500 | Internal Server Error |
| 501 | Not Implemented |

---

## Postman Environment Setup

1. Create a new Environment in Postman named **Agentra Local**
2. Add these variables:

| Variable | Initial Value | Description |
|---|---|---|
| `base_url` | `http://localhost/agentra_api` | Base URL |
| `access_token` | *(empty)* | Set after login |
| `refresh_token` | *(empty)* | Set after login |
| `insurer_id` | *(empty)* | Set after creating insurer |
| `customer_id` | *(empty)* | Set after creating customer |
| `policy_id` | *(empty)* | Set after creating policy |

3. In the **Login** request, add this to the **Tests** tab to auto-save tokens:

```javascript
const res = pm.response.json();
pm.environment.set("access_token", res.data.access_token);
pm.environment.set("refresh_token", res.data.refresh_token);
```

4. Set `Authorization` header on all protected requests:
```
Authorization: Bearer {{access_token}}
```

---

## Renewals

All renewal endpoints require a valid Bearer token.

---

### RN-001 — List Renewals

**GET** `/api/v1/renewals`

Returns policies expiring in the given month with full renewal tracking info.

**Query Params:**

| Param | Type | Default | Description |
|---|---|---|---|
| `month` | string | current month | Format `YYYY-MM` |
| `renewal_status` | string | *(all)* | Filter: `pending`, `renewed`, `lapsed`, `cancelled` |
| `page` | int | 1 | Page number |
| `limit` | int | 10 | Items per page (max 100) |

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Renewals found",
  "data": {
    "month": "2025-05",
    "data": [
      {
        "policy_id": "pol_abc",
        "policy_number": "01/08/...",
        "product_type": "fire",
        "coverage_end": "2025-05-02",
        "renewal_status": "pending",
        "payment_status": "unpaid",
        "premium_amount": 5000000,
        "commission_amount": 250000,
        "days_until_expiry": 2,
        "customer_name": "Budi Santoso",
        "customer_whatsapp": "081234567890",
        "insurer_name": "Chubb",
        "last_follow_up_status": "contacted",
        "last_follow_up_date": "2025-04-28"
      }
    ],
    "pagination": {
      "total": 84,
      "page": 1,
      "limit": 10,
      "total_pages": 9
    }
  }
}
```

---

### RN-002 — Renewal Stats (StatusPerpanjangan)

**GET** `/api/v1/renewals/stats`

Returns aggregated renewal stats for the StatusPerpanjangan component.

**Query Params:**

| Param | Type | Default | Description |
|---|---|---|---|
| `month` | string | current month | Format `YYYY-MM` |

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Renewal stats retrieved",
  "data": {
    "month": "2025-05",
    "total": 84,
    "achieved_omzet": 620000000,
    "total_omzet_potential": 850000000,
    "breakdown": {
      "renewed":     { "count": 54, "pct": 64.3 },
      "in_progress": { "count": 17, "pct": 20.2 },
      "lapsed":      { "count": 10, "pct": 11.9 },
      "cancelled":   { "count": 3,  "pct": 3.6  }
    }
  }
}
```

| Field | Description |
|---|---|
| `total` | All policies with `coverage_end` in the given month |
| `achieved_omzet` | Sum of `premium_amount` for `renewed` policies (IDR) |
| `total_omzet_potential` | Sum of `premium_amount` for all policies in the month (IDR) |
| `breakdown.renewed` | Policies successfully renewed |
| `breakdown.in_progress` | Policies still pending renewal (`renewal_status = pending`) |
| `breakdown.lapsed` | Policies that lapsed without renewal |

> **Note:** `total_omzet_potential` serves as the omzet target proxy derived from the actual book of business. A separate company target-setting endpoint can be added later to override this with a manual target.

---

## Dashboard

All dashboard endpoints require a valid Bearer token. Scope: **Main Agent** sees all company data; **Sub-Agent** sees only their own records (role-based filtering to be wired once role IDs are finalised).

---

### DB-001 — Dashboard Stats

**GET** `/api/v1/dashboard/stats`

Returns aggregated stat cards for the top row of the dashboard.

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "OK",
  "data": {
    "total_active_policies": 1024,
    "renewals_this_month": 47,
    "expiring_this_week": 8,
    "pending_commissions_amount": 12400000,
    "renewal_breakdown": {
      "total": 84,
      "renewed": 54,
      "in_progress": 17,
      "lapsed": 10,
      "cancelled": 3,
      "achieved_omzet": 620000000,
      "renewed_pct": 64.3,
      "in_progress_pct": 20.2,
      "lapsed_pct": 11.9
    },
    "trends": {
      "policies_vs_last_month": "+2.1%",
      "renewals_vs_last_month": "+5 polis"
    }
  }
}
```

| Field | Description |
|---|---|
| `total_active_policies` | Policies not lapsed or cancelled |
| `renewals_this_month` | Policies with `coverage_end` in current month and `renewal_status = pending` |
| `expiring_this_week` | Pending policies expiring within the next 7 days |
| `pending_commissions_amount` | Sum of `expected_amount` from commissions with `status = pending` (IDR) |
| `renewal_breakdown.total` | All policies with `coverage_end` in current month |
| `renewal_breakdown.renewed` | Count with `renewal_status = renewed` |
| `renewal_breakdown.in_progress` | Count with `renewal_status = pending` |
| `renewal_breakdown.achieved_omzet` | Sum of `premium_amount` for renewed policies (IDR) |
| `renewal_breakdown.renewed_pct` | Percentage of total that are renewed |
| `trends.policies_vs_last_month` | % change in policies created vs prior month |
| `trends.renewals_vs_last_month` | Absolute change in renewals vs prior month |

---

### DB-002 — Activity Feed

**GET** `/api/v1/dashboard/activity`

Returns the recent activity log for the bottom section of the dashboard.

**Query Params:**

| Param | Type | Default | Description |
|---|---|---|---|
| `limit` | int | 10 | Max items to return (max 50) |

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "OK",
  "data": {
    "items": [
      {
        "id": "log_abc123",
        "type": "policy_updated",
        "description": "Polis Budi Santoso diperbarui",
        "link": "/policies/pol_xyz",
        "actor": { "name": "Muksin" },
        "created_at": "2025-04-30T10:30:00Z"
      }
    ]
  }
}
```

---

### DB-003 — Today's Urgent Actions

**GET** `/api/v1/dashboard/today-actions`

Returns policies expiring within the next 7 days that still have `renewal_status = pending`.

**Query Params:**

| Param | Type | Default | Description |
|---|---|---|---|
| `limit` | int | 10 | Max items to return (max 50) |

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "OK",
  "data": {
    "items": [
      {
        "policy_id": "pol_abc",
        "policy_number": "01/08/...",
        "customer_name": "Budi Santoso",
        "customer_whatsapp": "081234567890",
        "product_type": "fire",
        "insurer_name": "Chubb",
        "coverage_end": "2025-05-02",
        "days_until_expiry": 2,
        "renewal_status": "pending",
        "last_follow_up_status": "contacted"
      }
    ],
    "total_today": 3,
    "total_week": 11
  }
}
```

| Field | Description |
|---|---|
| `items` | Policies sorted by `coverage_end` ascending |
| `last_follow_up_status` | Most recent status from `follow_up_logs` for this policy |
| `total_today` | Count of policies expiring today |
| `total_week` | Count of policies expiring within 7 days |

---

## Commissions Management

> **Base path:** `/api/v1/commissions`
> **Note:** This is separate from `/api/v1/comissions` which manages *insurer commission rate tables*. This module manages actual commission records per policy.

A `commissions` row is auto-created every time a policy is created (see [6.2 Create Policy](#62-create-policy)) and stays in sync when premium/rate changes via endorsement. The lifecycle is:

```
pending  →  received      (when |received_amount − expected_amount| ≤ 1 IDR)
         →  discrepancy   (when difference > 1 IDR)
```

---

### CM-001 — List Commissions

**GET** `/api/v1/commissions`

Returns all commission records for the authenticated company, with policy and customer context.

**Query Params:**

| Param | Type | Default | Description |
|---|---|---|---|
| `insurer_id` | string | — | Filter by insurer |
| `status` | string | — | `pending` \| `received` \| `discrepancy` \| `cancelled` |
| `commission_type` | string | — | `direct` \| `override` |
| `month` | string | — | Format `YYYY-MM` — filters by `coverage_start` month |
| `page` | int | 1 | Page number |
| `limit` | int | 10 | Items per page (max 100) |

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Commissions found",
  "data": {
    "data": [
      {
        "commission_id": "com_abc123",
        "policy_id": "pol_xyz",
        "insurer_id": "ins_001",
        "commission_type": "direct",
        "premium_amount": 5000000,
        "commission_rate": 10,
        "expected_amount": 500000,
        "received_amount": 0,
        "status": "pending",
        "expected_date": null,
        "received_date": null,
        "reference_number": null,
        "discrepancy_notes": null,
        "marked_by": null,
        "marked_at": null,
        "created_at": "2025-06-01 09:00:00",
        "updated_at": "2025-06-01 09:00:00",
        "policy_number": "01/08/FIRE/2025",
        "product_type": "fire",
        "coverage_start": "2025-06-01",
        "coverage_end": "2026-06-01",
        "customer_name": "PT Maju Bersama",
        "insurer_name": "Chubb Insurance",
        "insurer_short_name": "Chubb"
      }
    ],
    "pagination": {
      "total": 42,
      "page": 1,
      "limit": 10,
      "total_pages": 5
    }
  }
}
```

---

### CM-002 — Commission Summary

**GET** `/api/v1/commissions/summary`

Returns aggregated commission totals grouped by status. Useful for the commission overview/dashboard card.

**Query Params:**

| Param | Type | Default | Description |
|---|---|---|---|
| `insurer_id` | string | — | Filter by insurer |
| `month` | string | — | Format `YYYY-MM` — filters by `coverage_start` month |

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Commission summary retrieved",
  "data": {
    "total_count": 42,
    "total_expected": 21000000,
    "pending": {
      "count": 30,
      "amount": 15000000
    },
    "received": {
      "count": 10,
      "amount": 5000000
    },
    "discrepancy": {
      "count": 2,
      "expected": 1000000,
      "received": 850000
    }
  }
}
```

| Field | Description |
|---|---|
| `total_expected` | Sum of `expected_amount` across all statuses |
| `pending.amount` | Sum of `expected_amount` for pending rows |
| `received.amount` | Sum of `received_amount` for received rows |
| `discrepancy.expected` | What was expected for discrepancy rows |
| `discrepancy.received` | What was actually received for discrepancy rows |

---

### CM-003 — Get Commission Detail

**GET** `/api/v1/commissions/{commission_id}`

Returns a single commission record with full policy, customer, and insurer context.

**Response `200`:** Same fields as a single item in CM-001.

**Response `404`:** Commission not found or does not belong to the company.

---

### CM-004 — Mark Commission as Received

**PATCH** `/api/v1/commissions/{commission_id}/mark-received`

Records the actual received amount from the insurer. Automatically sets status to `received` or `discrepancy` based on the difference.

**Request Body:**
```json
{
  "received_amount": 500000,
  "received_date": "2025-06-15",
  "reference_number": "INV/2025/06/001",
  "discrepancy_notes": "Insurer deducted admin fee"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `received_amount` | int | **Yes** | Actual amount received in IDR |
| `received_date` | string | No | Date received `YYYY-MM-DD` (defaults to today) |
| `reference_number` | string | No | Bank transfer / invoice reference |
| `discrepancy_notes` | string | No | Explanation if there is a difference |

**Status Logic:**
```
|received_amount − expected_amount| ≤ 1  →  status = "received"
|received_amount − expected_amount| > 1  →  status = "discrepancy"
```

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Commission marked as received",
  "data": {
    "commission_id": "com_abc123",
    "status": "received",
    "expected_amount": 500000,
    "received_amount": 500000,
    "difference": 0
  }
}
```

**Response `200` (discrepancy):**
```json
{
  "status_code": 200,
  "status_message": "Commission marked as discrepancy",
  "data": {
    "commission_id": "com_abc123",
    "status": "discrepancy",
    "expected_amount": 500000,
    "received_amount": 450000,
    "difference": -50000
  }
}
```

> This action also writes an entry to `policy_logs` with event type `commission_marked_received` or `commission_discrepancy`, visible in `GET /api/v1/policies/{id}/logs`.
