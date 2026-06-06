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
1. Register          → POST /auth/register
2. Login             → POST /auth/login        ← save access_token + refresh_token
3. Get Plans         → GET  /plans?app_id=...  (no auth needed)
4. Create Insurer    → POST /insurers
5. Create Customer   → POST /customers
6. Create Policy     → POST /policies          ← needs insurer_id + customer_id
7. Follow-up / Status updates as needed
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
| product_type | `fire`, `motorcycle`, `car`, `travel`, `cargo`, `other` |
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
| product_type | string | Yes | `fire`, `motorcycle`, `car`, `travel`, `cargo`, `other` |
| coverage_start | string | Yes | YYYY-MM-DD |
| coverage_end | string | Yes | YYYY-MM-DD |
| sum_insured | integer | Yes | Coverage amount (IDR) |
| premium_amount | integer | Yes | Premium (IDR) |
| commission_rate | float | Yes | Commission % (e.g. `10.5`) |
| policy_year | integer | No | Default: 1 |
| object_insured | string | No | Description of insured object |
| coverage_notes | string | No | Coverage details |
| notes | string | No | Internal notes |
| previous_policy_id | string | No | For renewals |

**Response `201`:**
```json
{ "data": { "policy_id": "pol_xxx" } }
```

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
  "follow_up_date": "2025-06-05",
  "action_type": "whatsapp",
  "notes": "Sent renewal reminder to customer",
  "outcome": "Customer confirmed renewal interest"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| follow_up_date | string | No | YYYY-MM-DD, default: today |
| action_type | string | No | Type of action taken |
| notes | string | No | What was done |
| outcome | string | No | Result |

**Response `201`:**
```json
{ "data": { "follow_up_id": "flw_xxx" } }
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

## 7. Not Yet Implemented

These routes return `501 Not Implemented`:

| Endpoint | Description |
|---|---|
| `/api/v1/renewals` | Renewal management |
| `/api/v1/services` | Services/products catalog |
| `/api/v1/comissions` | Commission management |

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
