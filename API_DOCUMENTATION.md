# Agentra API Documentation

---

## ⚠️ What's New — v1.3.0 (FE Team: Action Required)

> No SQL migration on the Agentra schema — this reads reference tables that are already populated in the shared core schema (`movira_core_dev`). Nothing to run before deploying.

### Master Wilayah — cascading region lookup (Province → City → District → Village)

New read-only endpoints for Indonesia's administrative regions, meant to replace free-text typing for `risk_province` / `risk_city` / `risk_district` / `risk_village` (added in v1.2.0, see below) with a real cascading picker.

**Base path:** `/api/v1/master-wilayah` — full reference: [Master Wilayah — Region Lookup](#master-wilayah--region-lookup)

| Endpoint | Purpose |
|---|---|
| `GET /master-wilayah/provinces` | All provinces (38) |
| `GET /master-wilayah/cities?province_code=` | Cities/regencies in a province |
| `GET /master-wilayah/districts?city_code=` | Districts (kecamatan) in a city |
| `GET /master-wilayah/villages?district_code=` | Villages (kelurahan/desa) in a district |

**What we expect from the FE integration:**
1. **Treat it as one cascading picker, not four independent dropdowns.** Cities/districts/villages each require their parent's `*_code` as a query param — don't fire that call until the user has picked the level above, and reset/disable every downstream select whenever an upstream one changes.
2. **Chain on `*_code`, but save `*_name`.** `policies.risk_province/risk_city/risk_district/risk_village` are still plain free-text columns (v1.2.0 was schema-only, no FK to these tables yet). When the user picks a row, use its `*_code` to fetch the next level, but write its `*_name` string into the corresponding `risk_*` field on `POST`/`PUT` `/policies` — exactly as if they'd typed it themselves.
3. **Data coverage is partial today, by design of the ongoing sync — not a bug.** Only Sumatera's provinces + DKI Jakarta have cities/districts/villages loaded right now; every other province currently returns `404 "No cities found"` from `/cities`. Please don't treat that 404 as an error state — fall back to a manual text input for that field ("region not listed yet? type it") rather than blocking the form. This fills in automatically as the upstream sync completes; no FE change needed when it does.
4. Same auth as everything else: `Authorization: Bearer <access_token>` required on all four endpoints.

---

## ⚠️ What's New — v1.2.0 (FE Team: Action Required)

> **SQL migration required before deploying this version:**
> - `migration_policy_risk_location.sql` — adds `policies.risk_address`, `risk_village`, `risk_district`, `risk_city`, `risk_province`, `risk_postal_code`, `risk_latitude`, `risk_longitude`
>
> Additive/nullable-default, safe to run on a live DB. No backfill needed.

### Policy Risk Location — structured address + coordinates for the insured property

Today the insured property's location lives only inside the free-text `object_insured` field (e.g. `"Gudang, Jl. Industri No. 5"`) — the same field the policy export labels "LOKASI PERTANGGUNGAN". This adds eight optional structured fields on `policies` to capture that location properly, laying groundwork for a future "find insured properties near a fire" map feature. **Not built in this release** — no geocoding, no map UI here; `risk_latitude`/`risk_longitude` are plain manual-entry fields for now.

**Policy-level, like `construction_class`** — one customer can hold multiple fire policies for different physical properties, so risk location lives on the policy, not the customer.

| Field | Type | Description |
|---|---|---|
| `risk_address` | string | Street address of the insured property |
| `risk_village` | string | Kelurahan/Desa |
| `risk_district` | string | Kecamatan |
| `risk_city` | string | Kota/Kabupaten |
| `risk_province` | string | Provinsi |
| `risk_postal_code` | string | 5-digit postal code, e.g. `"17530"` |
| `risk_latitude` | float | -90 to 90. Must be sent together with `risk_longitude`. |
| `risk_longitude` | float | -180 to 180. Must be sent together with `risk_latitude`. |

FE: `object_insured` is unchanged and keeps describing *what* is insured (e.g. "Gudang" / "Ruko 2 lantai") — these new fields describe *where* it is. All eight are optional. `risk_latitude`/`risk_longitude` have no automatic geocoding yet — if you only have a text address, leave them blank; consider a single "pin on map" input control that fills both together, since the API rejects sending just one of the pair. Available on `POST /policies`, `PUT /policies/{id}`, `PATCH /policies/{id}`, and `POST /policies/{id}/renew` (carried forward from the source policy, individually overridable). Returned (null until set) on `GET /policies/{id}`.

---

## ⚠️ What's New — v1.1.0 (FE Team: Action Required)

> **SQL migrations required before deploying this version:**
> - `migration_policy_renewal_fields.sql` — adds `policies.insured_name`, `policies.renewal_reminder_sent_at`
> - `migration_renewal_notification_settings.sql` — adds `notification_settings.renewal_reminder_days`, `notification_settings.renewal_wa_message_template`, and extends `whatsapp_digest_logs.digest_type`
>
> Both are additive/nullable-default and safe to run on a live DB.

### Product ask vs. what was built

The v1.1.0 scope came from three feature requests. Quoted here alongside the assumptions made while turning each into an API, so PM/FE can flag anything that doesn't match intent before this ships.

**1. Customer**
> "If we open the customer detail, we can show up the policy that has been own by it customer" / "The export button in customer must be run in customer list, it must call API and export the customer in excel"
- Built exactly as asked: [5.10 Get Customer Policies](#510-get-customer-policies) for the detail-page policy list, [5.11 Export Customers to Excel](#511-export-customers-to-excel) for the list-page export button.
- No open questions here.

**2. Renewal**
> "If renewal reminder in 30 days, please send the list policy to the agent by whatsapp what must be followup"
- Built as an internal digest **to the agent** (not the customer) — [`POST /services/renewal-reminder`](#services-renewal-reminder-cron), one message per agent listing all their policies entering the window, sent once per policy (tracked via `renewal_reminder_sent_at` so it doesn't repeat daily). 30 days is the *default*, made configurable per company (`renewal_reminder_days`) since that's a one-column cost for real flexibility.
- **Assumption to validate:** nothing in this repo runs a daily cron today — I built the endpoint and a token-minting script, but *scheduling* the daily call (crontab / hosting scheduler / Cloud Scheduler) is outside this codebase and still needs to be set up.

> "The agent must can set the default message for sending message while click whatsapp and the message can be set while click sending whatsapp"
- Read this as a **separate, customer-facing** action from the digest above — the manual "Send WhatsApp" button on a renewal row. Built as [RN-003](#rn-003--send-renewal-whatsapp) with a saved default template (`renewal_wa_message_template`, editable via [3.4](#34-update-notification-settings)) that's still overridable per send.
- **Assumption to validate:** the template is saved **per company**, not per individual agent — `notification_settings` has always been a one-row-per-company table (even though it has a `user_id` column), so a second sub-agent would see/use the same template the main agent set. Flag if sub-agents need their own independent templates — that would need a schema change (template moved to `agent_profiles`).

> "If renewal, some of fields must be update like nomor polis, periode, rate, item pertanggungan, nama tertanggung (opsional). For the expired date if the customer are agree auto +365 days"
- Built as [6.12 Renew Policy](#612-renew-policy): new `policy_number` required, `coverage_start`/`coverage_end` (periode), `commission_rate`/`commission_tax_rate` (rate), and coverages (item pertanggungan) are all carried forward from the source policy and overridable. `coverage_end` auto-computes as `coverage_start + 365 days` whenever it's left out of the request — that's the "customer agrees" default; passing it explicitly overrides it.
- **New field:** `nama tertanggung` didn't map to any existing column, so `policies.insured_name` was added (optional, falls back to the customer's name) — confirm this is the right home for it rather than, say, a per-renewal note.

**3. Revenue**
> "We must generate report how much production in that month and week so the user can count the commission manually"
- Built as [`GET /revenue/summary`](#revenue) — monthly totals plus a week-by-week breakdown (calendar Mon–Sun, clipped to the month) and a per-agent breakdown, all keyed off `coverage_start` (the same "production date" convention already used by the policy export's `month` filter).
- **Assumption to validate:** "production" here means gross premium written (`premium_amount`), not commission received — the report shows both raw premium and computed commission side by side so it can be checked against whatever the insurer actually pays, but doesn't attempt to reconcile against `commissions.received_amount` itself. Say the word if you want received-vs-expected reconciliation folded into this report too.

### 1. Customer — policies owned by a customer + Excel export

- **New:** `GET /api/v1/customers/{customer_id}/policies` — paginated list of every policy owned by a customer (replaces relying on the 5-item `policies_summary.recent` preview on `GET /customers/{id}`). See [5.10](#510-get-customer-policies).
- **New:** `GET /api/v1/customers/export` — downloads the customer list as `.xlsx`, same filters as `GET /customers`. See [5.11](#511-export-customers-to-excel).

### 2. Renewal — renew action, insured name override, WhatsApp follow-up

- **New:** `POST /api/v1/policies/{policy_id}/renew` — creates the next `policy_year` record from an expiring policy: new `policy_number`, periode, rate, and item pertanggungan (coverages) are all carried forward from the source policy and individually overridable. **If `coverage_end` is omitted, it auto-calculates as `coverage_start + 365 days`** — i.e. the "customer agrees to renew as-is" default. See [6.12](#612-renew-policy).
- **New field `insured_name`** on policies — optional "nama tertanggung" override for policy documents, falls back to the customer's `display_name` when not set. Available on create/update/renew and returned on `GET /policies/{id}`.
- **New:** `POST /api/v1/renewals/{policy_id}/send-whatsapp` — sends (and logs as a follow-up) a WhatsApp message to the customer about their upcoming renewal. Uses the company's saved default template unless a `message` override is passed in the request body. See [RN-003](#rn-003--send-renewal-whatsapp).
- **New:** `GET /api/v1/users/me/notification-settings` — was previously write-only (`PUT` only); FE can now fetch current settings to prefill the settings form. See [3.4b](#34b-get-notification-settings).
- **New settings fields** on `PUT /api/v1/users/me/notification-settings`: `renewal_reminder_days` (default 30) and `renewal_wa_message_template` (the agent-editable default WhatsApp message, supports `{customer_name} {policy_number} {product_type} {insurer_name} {coverage_end} {days_until_expiry}` placeholders). See [3.4](#34-update-notification-settings).
- **New (system/cron):** `POST /api/v1/services/renewal-reminder` — sends each issuing agent a WhatsApp digest of their policies entering the renewal window. Intended to be called once daily by an external scheduler using a long-lived service token (see [`mint-cron-token.php`](#services-renewal-reminder-cron)); a logged-in user's normal token also works and scopes the run to their own company. See [Services](#services-renewal-reminder-cron).

### 3. Revenue — production report by week/month

- **New:** `GET /api/v1/revenue/summary?month=YYYY-MM` — monthly production totals (premium, commission, net commission), broken down by calendar week and by issuing agent, for manually reconciling commission against insurer statements. See [Revenue](#revenue).

---

## ⚠️ What's New — v1.4

### Export Policies to Excel
`
New endpoint to download the policy list as a `.xlsx` file in the standard monthly reporting format (matches the existing **MEI 2026.xlsx** template):

```
GET /api/v1/policies/export
```

- Accepts the same filter params as `GET /api/v1/policies` plus a new `month` param (filters by `coverage_start` month and sets the filename).
- Policies are grouped by issuing agent — a bold sub-header row is inserted before each agent group.
- Returns a binary XLSX download. No JSON response.

See [Section 6.11](#611-export-policies-to-excel) for full details.

---

## ⚠️ What's New — v1.3 (FE Team: Action Required)

### 1. `biaya_polis` dan `diskon` — new optional cost/discount fields on policies

Both fields are **optional integers (IDR), default 0**. They affect the customer invoice calculation.

**Updated `customer_premium_amount` formula:**

```
customer_premium_amount = premium_amount + materai_amount + biaya_polis
                          − commission_amount − diskon
```

**New fields on every policy response (`GET /policies`, `GET /policies/{id}`):**

| Field | Type | Description |
|---|---|---|
| `biaya_polis` | int (IDR) | Admin/policy fee charged on top of premium |
| `diskon` | int (IDR) | Discount deducted from customer invoice |

**New optional input fields on `POST /policies`, `PUT /policies/{id}`, and `PATCH /policies/{id}`:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `biaya_polis` | int | No | Defaults to `0`. Must be ≥ 0 |
| `diskon` | int | No | Defaults to `0`. Must be ≥ 0 |

> **SQL migration required:** Run `migration_biaya_polis_diskon.sql` on the database before deploying this version. The migration is safe to run on a live DB — existing rows get `biaya_polis = 0` and `diskon = 0`.

---

### 2. ⚠️ FE Bug: "Update Without Endorsement" blocked after first use

**Bug reported by user:** Cannot update a policy without endorsement (`PATCH /policies/{id}`) more than once.

**Backend status: No restriction exists on the API side.** `PATCH /policies/{id}` (PO-006, `directUpdatePolicy`) can be called any number of times without limit. The bug is in the **frontend** — likely the PATCH button/action is being hidden or disabled after the first successful direct update. Please investigate the FE state management around this action.

---

## ⚠️ What's New — v1.2

### Delete & Direct-Update Policy (no endorsement)

Two new endpoints on `/api/v1/policies/{policy_id}`:

| Method | Endpoint | Description |
|---|---|---|
| `PATCH` | `/api/v1/policies/{id}` | Update any policy field **without** creating an endorsement log. Use for correcting data entry mistakes. |
| `DELETE` | `/api/v1/policies/{id}` | Permanently delete a policy and all related records (commission, coverages, co-assurance, follow-ups, audit logs). |

> **`PUT` vs `PATCH`:** `PUT` records an endorsement when financial/date fields change. `PATCH` always logs as `policy_updated (koreksi)` regardless of which fields are changed.

See [Section 6.4b](#64b-direct-update-policy-without-endorsement) and [Section 6.4c](#64c-delete-policy) for full details.

---

## ⚠️ What's New — v1.1 (FE Team: Action Required)

The following changes are live and require frontend updates. All changes are **additive** (no existing fields were removed or renamed).

### 1. Premium / Commission Breakdown (new fields on `policies` and `commissions`)

The calculation formula is now:

```
commission_amount       = premium_amount × commission_rate / 100                          ← gross commission
commission_tax_amount   = commission_amount × commission_tax_rate                         ← PPh withheld
net_commission_amount   = commission_amount − commission_tax_amount                       ← what agent keeps
customer_premium_amount = premium_amount + materai_amount + biaya_polis − commission_amount − diskon  ← invoice to customer
```

**New fields on every policy response:**

| Field | Type | Description |
|---|---|---|
| `materai_amount` | int (IDR) | Stamp duty — entered per policy |
| `commission_tax_rate` | decimal (0–1) | PPh rate e.g. `0.0250` = 2.5% |
| `commission_tax_amount` | int (IDR) | Tax withheld from commission |
| `net_commission_amount` | int (IDR) | Agent's net commission after tax |
| `customer_premium_amount` | int (IDR) | Amount to bill the customer |
| `is_coassurance` | 0/1 | Flag: show co-assurance badge |

**New input fields on `POST /policies` and `PUT /policies/{id}`:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `materai_amount` | int | No | Defaults to 0 |
| `commission_tax_rate` | decimal 0–1 | No | Defaults to product's `default_tax_rate` |
| `is_coassurance` | boolean | No | Set true only if adding co-insurers after creation |

**New fields on every commission response (`/commissions`, `/commissions/{id}`, `/policies/{id}/commission`):**

| Field | Type | Description |
|---|---|---|
| `commission_tax_rate` | decimal | Snapshot of PPh rate at creation |
| `commission_tax_amount` | int (IDR) | Tax amount withheld |
| `net_expected_amount` | int (IDR) | Commission net of tax |

### 2. Master Products — new `default_tax_rate` field

`GET /master-products` and detail now return `default_tax_rate` (decimal 0–1).
`POST /master-products` and `PUT /master-products/{id}` now accept `default_tax_rate`.
This is the default PPh rate that auto-fills `commission_tax_rate` on a new policy.

### 3. Co-assurance — new sub-resource

New endpoints under `/api/v1/policies/{policy_id}/coassurance`.
When `is_coassurance = 1` on a policy, fetch and display co-insurers from this sub-resource.
See [Section 6.11](#611-co-assurance-participants) for full details.

### 4. Building Construction Class (fire insurance)

New optional field `construction_class` on `policies`. Only relevant for fire (and PAR/PSAKI) product types; set `null` for all other products.

| Value | Kelas | Description |
|---|---|---|
| `"I"` | Kelas I | Hard construction — beton bertulang, bata, rangka besi/baja |
| `"II"` | Kelas II | Semi-hard construction — campuran beton & kayu |
| `"III"` | Kelas III | Light construction — kayu, atap seng/genteng |
| `null` | — | Not applicable (car, motorcycle, travel, etc.) |

**This is a policy-level field, not per coverage item.** All coverage items (Building FLEXAS, RSMD, OTHERS, Contents) under the same policy share the same construction class because they are at the same risk location.

FE: Show a `<select>` (Kelas I / II / III) on the policy form, **only when `product_type` is a fire/property product**. Send `null` or omit for non-fire products. The GET detail response always includes `construction_class` (null when not set).

### 5. Recommended Postman testing order (additions)

```
15. Add Co-insurer        → POST   /policies/{id}/coassurance
16. List Co-insurers      → GET    /policies/{id}/coassurance
17. Update Co-insurer     → PUT    /policies/{id}/coassurance/{ca_id}
18. Remove Co-insurer     → DELETE /policies/{id}/coassurance/{ca_id}
19. Correct Policy (no endorsement) → PATCH  /policies/{id}
20. Delete Policy         → DELETE /policies/{id}
```

---

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
1.  Register          → POST   /auth/register
2.  Login             → POST   /auth/login                   ← save access_token + refresh_token
3.  Get Plans         → GET    /plans?app_id=...             (no auth needed)
4.  Create Insurer    → POST   /insurers
5.  Create Customer   → POST   /customers
6.  Create Policy     → POST   /policies                     ← auto-creates commission record
7.  Add Coverages     → POST   /policies/{id}/coverages      ← optional, syncs premium & commission
8.  Follow-up         → POST   /policies/{id}/follow-ups
9.  Update Statuses   → PATCH  /policies/{id}/payment-status
                        PATCH  /policies/{id}/renewal-status
10. View Commission   → GET    /policies/{id}/commission     ← agent sees expected commission
11. View History      → GET    /policies/{id}/logs           ← full event timeline
12. Commission List   → GET    /commissions                  ← all commission records
13. Commission Stats  → GET    /commissions/summary          ← pending/received/discrepancy totals
14. Mark Received     → PATCH  /commissions/{id}/mark-received ← record actual payment from insurer
15. Correct Policy    → PATCH  /policies/{id}               ← update without endorsement log
16. Delete Policy     → DELETE /policies/{id}               ← irreversible, removes all related data
17. Export to Excel   → GET    /policies/export?month=YYYY-MM ← download .xlsx report
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
| `renewal_reminder_days` | integer | No | **[NEW v1.1]** Days before `coverage_end` to trigger the automatic renewal WA reminder. 1–90, default 30 |
| `renewal_wa_message_template` | string | No | **[NEW v1.1]** Default message for the manual "Send WhatsApp" renewal action. Placeholders: `{customer_name}` `{policy_number}` `{product_type}` `{insurer_name}` `{coverage_end}` `{days_until_expiry}`. Pass an empty string to clear it and fall back to the built-in default. |

---

### 3.4b Get Notification Settings

**GET** `/api/v1/users/me/notification-settings`

Auth required. **[NEW v1.1]** — previously this resource was write-only.

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Notification settings retrieved",
  "data": {
    "setting_id": "notif_abc123",
    "company_id": "comp_001",
    "user_id": "usr_001",
    "daily_digest_enabled": true,
    "daily_digest_time": "08:00:00",
    "daily_days_of_week": "1,2,3,4,5,6",
    "monthly_digest_enabled": true,
    "monthly_digest_day": 1,
    "monthly_digest_time": "08:00:00",
    "whatsapp_target_number": "628123456789",
    "renewal_reminder_days": 30,
    "renewal_wa_message_template": null,
    "updated_by": "usr_001",
    "updated_at": "2026-08-01 09:00:00"
  }
}
```

**Response `404`** — settings have never been saved for this company yet (call `PUT` first).

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

### 5.10 Get Customer Policies

**GET** `/api/v1/customers/{customer_id}/policies`

Auth required. **[NEW v1.1]** Paginated list of every policy owned by this customer — use this instead of the 5-item preview in `policies_summary` on `GET /customers/{id}` when rendering a full "Policies" tab on the customer detail page.

**Query Parameters:**
| Param | Default | Max |
|---|---|---|
| page | 1 | — |
| limit | 10 | 100 |
| renewal_status | — | `pending`, `renewed`, `lapsed`, `cancelled` |

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Customer policies retrieved",
  "data": {
    "data": [
      {
        "policy_id": "pol_abc123",
        "policy_number": "01.08.2026.001",
        "product_type": "fire",
        "policy_year": 1,
        "renewal_status": "pending",
        "payment_status": "unpaid",
        "coverage_start": "2026-08-01",
        "coverage_end": "2027-08-01",
        "sum_insured": 500000000,
        "premium_amount": 2000000,
        "commission_amount": 300000,
        "insurer_id": "ins_001",
        "insurer_short_name": "CHUBB",
        "issuing_agent_id": null,
        "created_at": "2026-08-01 09:00:00"
      }
    ],
    "pagination": { "total": 1, "page": 1, "limit": 10, "total_pages": 1 }
  }
}
```

---

### 5.11 Export Customers to Excel

**GET** `/api/v1/customers/export`

Auth required. **[NEW v1.1]** Downloads the customer list as a `.xlsx` file. Accepts the same filters as `GET /api/v1/customers`.

**Query Parameters:**
| Param | Description |
|---|---|
| `params` | Search by name / legal name / NIK / NPWP |
| `customer_type` | `individual` or `company` |
| `status` | `active`, `inactive`, `lapsed` |

**Response:**
- **Content-Type:** `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`
- **Content-Disposition:** `attachment; filename="DAFTAR CUSTOMER.xlsx"`
- **Body:** Binary XLSX file — columns: NO, NAMA, TIPE, NIK/NPWP, HP, WHATSAPP, EMAIL, PIC, STATUS, SUMBER, TANGGAL DIBUAT

**Response `404`** — no customers match the given filters.

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
  "materai_amount": 10000,
  "biaya_polis": 50000,
  "diskon": 0,
  "commission_rate": 15,
  "commission_tax_rate": 0.025,
  "policy_year": 1,
  "issuing_agent_id": null,
  "previous_policy_id": null,
  "object_insured": "Toyota Avanza 2020 - B 1234 XYZ",
  "coverage_notes": "Comprehensive with flood extension",
  "risk_address": "Jl. Industri No. 5, RT 003/RW 002",
  "risk_village": "Sukamaju",
  "risk_district": "Cibinong",
  "risk_city": "Kabupaten Bogor",
  "risk_province": "Jawa Barat",
  "risk_postal_code": "16916",
  "risk_latitude": -6.481,
  "risk_longitude": 106.854,
  "notes": "Renewal from last year"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| insurer_id | string | Yes | From `/insurers` |
| customer_id | string | Yes | From `/customers` |
| policy_number | string | Yes | Unique per company |
| product_type | string | Yes | Must match an active `product_code` in master products |
| coverage_start | string | Yes | YYYY-MM-DD |
| coverage_end | string | Yes | YYYY-MM-DD |
| sum_insured | integer | Yes | Coverage amount (IDR) |
| premium_amount | integer | Yes | Gross premium from insurer (IDR) |
| materai_amount | integer | No | Stamp duty in IDR. Default: `0`. Varies per policy. |
| biaya_polis | integer | **No** ⭐ NEW | Admin/policy fee in IDR. Default: `0`. Added to customer invoice. |
| diskon | integer | **No** ⭐ NEW | Discount in IDR. Default: `0`. Deducted from customer invoice. |
| commission_rate | float | No | Commission % (0–100). If omitted, auto-resolved from product type or policy number prefix. Required only if no rule matches. |
| commission_tax_rate | decimal | No | PPh rate as decimal 0–1 (e.g. `0.025` = 2.5%). Default: inherited from `master_products.default_tax_rate`. |
| policy_year | integer | No | Default: 1 |
| object_insured | string | No | Description of insured object |
| `insured_name` | string | No | **[NEW v1.1]** Optional "nama tertanggung" override for policy documents. Falls back to the customer's `display_name` when omitted. |
| coverage_notes | string | No | Coverage details |
| construction_class | string | No | Fire insurance only: `"I"`, `"II"`, or `"III"`. Omit or send `null` for non-fire products. |
| `risk_address` | string | No | **[NEW v1.2.0]** Street address of the insured property (risk location). |
| `risk_village` | string | No | **[NEW v1.2.0]** Kelurahan/Desa. |
| `risk_district` | string | No | **[NEW v1.2.0]** Kecamatan. |
| `risk_city` | string | No | **[NEW v1.2.0]** Kota/Kabupaten. |
| `risk_province` | string | No | **[NEW v1.2.0]** Provinsi. |
| `risk_postal_code` | string | No | **[NEW v1.2.0]** 5-digit Indonesian postal code, e.g. `"17530"`. |
| `risk_latitude` | float | No | **[NEW v1.2.0]** -90 to 90. Must be provided together with `risk_longitude`. Manual entry — no automatic geocoding. |
| `risk_longitude` | float | No | **[NEW v1.2.0]** -180 to 180. Must be provided together with `risk_latitude`. |
| notes | string | No | Internal notes |
| previous_policy_id | string | No | For renewals |

> ⭐ **FE note:** Show `materai_amount`, `biaya_polis`, and `diskon` as optional inputs on the policy form (default 0). The `commission_tax_rate` field can be shown as an editable field pre-filled from the master product. Do NOT ask for `commission_tax_amount`, `net_commission_amount`, or `customer_premium_amount` as inputs — those are calculated server-side.

**How commission and customer invoice are calculated server-side:**
```
commission_amount       = premium_amount × commission_rate / 100
commission_tax_amount   = commission_amount × commission_tax_rate
net_commission_amount   = commission_amount − commission_tax_amount
customer_premium_amount = premium_amount + materai_amount + biaya_polis − commission_amount − diskon
```

**Example with premium=5,000,000, rate=15%, tax=2.5%, materai=10,000, biaya_polis=50,000, diskon=100,000:**
```
commission_amount       = 5,000,000 × 15% = 750,000
commission_tax_amount   = 750,000 × 0.025 = 18,750
net_commission_amount   = 750,000 − 18,750 = 731,250
customer_premium_amount = 5,000,000 + 10,000 + 50,000 − 750,000 − 100,000 = 4,210,000
```

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

### 6.4 Update Policy (with Endorsement)

**PUT** `/api/v1/policies/{policy_id}`

Auth required. Commission breakdown is auto-recalculated whenever any financial field changes (`premium_amount`, `commission_rate`, `commission_tax_rate`, `materai_amount`, `biaya_polis`, `diskon`). Changes to financial or date fields are recorded as an **endorsement** in the policy audit log.

**Request Body (any updatable fields):**
```json
{
  "sum_insured": 350000000,
  "premium_amount": 5000000,
  "materai_amount": 10000,
  "biaya_polis": 50000,
  "diskon": 100000,
  "commission_rate": 15.0,
  "commission_tax_rate": 0.025,
  "object_insured": "Toyota Avanza 2021 - B 1234 XYZ",
  "coverage_notes": "Comprehensive + flood + earthquake",
  "construction_class": "I",
  "risk_address": "Jl. Industri No. 5, RT 003/RW 002",
  "risk_latitude": -6.481,
  "risk_longitude": 106.854,
  "notes": "Updated after endorsement"
}
```

| Field | Type | Description |
|---|---|---|
| `object_insured` | string | Insured object description |
| `insured_name` | string\|null | **[NEW v1.1]** "Nama tertanggung" override, or `null` if using the customer's `display_name` |
| `sum_insured` | int | New TSI in IDR |
| `coverage_notes` | string | Coverage clause notes |
| `construction_class` | string\|null | `"I"`, `"II"`, `"III"`, or `null` to clear |
| `risk_address` / `risk_village` / `risk_district` / `risk_city` / `risk_province` | string\|null | **[NEW v1.2.0]** Structured risk-location address fields. Send `null` or `""` to clear. |
| `risk_postal_code` | string\|null | **[NEW v1.2.0]** 5-digit postal code, or `null` to clear. |
| `risk_latitude` / `risk_longitude` | float\|null | **[NEW v1.2.0]** Must be sent together (both set, or both `null`/omitted to leave unchanged). Send both as `null` to remove the pin. |
| `coverage_start` | string | YYYY-MM-DD |
| `coverage_end` | string | YYYY-MM-DD |
| `premium_amount` | int | Gross premium |
| `materai_amount` | int | Stamp duty |
| `biaya_polis` | int | ⭐ NEW — Admin/policy fee in IDR |
| `diskon` | int | ⭐ NEW — Discount in IDR |
| `commission_rate` | float | Commission % |
| `commission_tax_rate` | decimal | PPh rate 0–1 |
| `notes` | string | Internal notes |

> ⭐ **FE note:** All financial fields (`premium_amount`, `materai_amount`, `biaya_polis`, `diskon`, `commission_rate`, `commission_tax_rate`) can be updated independently — the server recalculates `customer_premium_amount` on any change.

**Response `200`:**
```json
{ "status_message": "Policy updated successfully" }
```

---

### 6.4b Direct Update Policy (without Endorsement)

**PATCH** `/api/v1/policies/{policy_id}`

Auth required. Updates policy fields **without** creating an endorsement entry in the audit log. Use this to correct data entry mistakes. Accepts the same fields as `PUT`, including the **[NEW v1.2.0]** `risk_*` fields (see [What's New — v1.2.0](#-whats-new--v120-fe-team-action-required)). Commission breakdown is still recalculated and synced when financial fields are provided.

> **When to use PATCH vs PUT:**
> - Use `PUT` for formal policy changes (mid-term changes, agreed amendments) — creates an endorsement log.
> - Use `PATCH` for correcting input errors (typos, wrong values entered at creation) — logs as `policy_updated` (koreksi), never as `endorsement`.

**Request Body (any updatable fields — same as PUT, including `biaya_polis` and `diskon`):**
```json
{
  "premium_amount": 4500000,
  "biaya_polis": 50000,
  "diskon": 0,
  "commission_rate": 15.0,
  "object_insured": "Toyota Avanza 2020 - B 1234 XYZ"
}
```

**Response `200`:**
```json
{ "status_message": "Policy updated successfully" }
```

---

### 6.4c Delete Policy

**DELETE** `/api/v1/policies/{policy_id}`

Auth required. Permanently deletes the policy and all associated records (commission, coverages, co-assurance participants, follow-up logs, and audit logs). **This action is irreversible.**

> Use this only to remove incorrectly created policies. For policies that are cancelled or lapsed, use `PATCH /policies/{id}/renewal-status` with `"renewal_status": "cancelled"` instead.

**Response `200`:**
```json
{ "status_message": "Policy deleted successfully" }
```

**Response `404`:**
```json
{ "status_message": "Policy not found" }
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
| `expected_amount` | Gross commission = `premium_amount × commission_rate / 100` (IDR) |
| `commission_tax_rate` | ⭐ NEW — PPh rate snapshot (decimal 0–1) |
| `commission_tax_amount` | ⭐ NEW — Tax withheld = `expected_amount × commission_tax_rate` (IDR) |
| `net_expected_amount` | ⭐ NEW — Agent nets this = `expected_amount − commission_tax_amount` (IDR) |
| `received_amount` | Amount actually received from insurer (IDR). `0` until marked received |
| `status` | `pending` → `received` or `discrepancy` (when received ≠ expected). `cancelled` if policy voided |
| `expected_date` | Date agent expects insurer to pay (set manually) |
| `received_date` | Date insurer actually paid |
| `reference_number` | Insurer's payment reference number |
| `discrepancy_notes` | Notes explaining the discrepancy |

> ⭐ **FE note:** Display the commission breakdown as a 3-line card: **Gross** (`expected_amount`) → **Tax / PPh** (`commission_tax_amount`) → **Net** (`net_expected_amount`). The `received_amount` from the insurer should be compared against `net_expected_amount` in the UI (net of tax is what actually arrives).

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

### 6.11 Export Policies to Excel

**GET** `/api/v1/policies/export`

Auth required. Downloads the policy list as a `.xlsx` file in the standard monthly reporting format. Policies are grouped by issuing agent with a bold sub-header row before each agent group, matching the existing manual template.

**Query Parameters:**

| Param | Description |
|---|---|
| `month` | `YYYY-MM` — filter by `coverage_start` month. Determines the filename (e.g. `MEI 2026.xlsx`). Primary param for the monthly export. |
| `expiry_month` | `YYYY-MM` — filter by `coverage_end` month |
| `search` | Search by policy number or customer name |
| `customer_id` | Filter by customer |
| `product_type` | Filter by product type |
| `insurer_id` | Filter by insurer |
| `renewal_status` | `pending`, `renewed`, `lapsed`, `cancelled` |
| `agent_id` | Filter by issuing agent |

> If neither `month` nor `expiry_month` is provided, all company policies matching the other filters are exported and the filename defaults to `DAFTAR POLIS.xlsx`.

**Response:**

- **Content-Type:** `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`
- **Content-Disposition:** `attachment; filename="MEI 2026.xlsx"` (derived from `month` param)
- **Body:** Binary XLSX file

**Excel structure:**

| Row | Content |
|---|---|
| 1 | Title — e.g. `MEI 2026` (bold, column B) |
| 2 | Column headers (bold) |
| 3+ | Agent sub-header row (bold) followed by that agent's policy rows. Repeats for each agent. |

**Column layout:**

| Col | Header | Source field |
|---|---|---|
| A | NO POLIS LAMA | Previous policy's `policy_number` — or `BARU` for new policies with no prior |
| B | NO POLIS | `policies.policy_number` |
| C | *(blank)* | `commission_rate / 100` (e.g. `0.15` for 15%) |
| D | *(blank)* | `commission_tax_rate` (e.g. `0.025`) |
| E | AGEN | `customers.display_name` (tertanggung) |
| F | EMAIL | Customer email (`personal_email` → `company_email` → `pic_email`) |
| G | HP | Customer phone (`personal_phone` → `company_phone` → `pic_phone`) |
| H | LOKASI PERTANGGUNGAN | `policies.object_insured` |
| I | DATE | Day of month from `coverage_start` |
| J | BANGUNAN | TSI text for `bangunan` coverage (e.g. `500 JT`) |
| K | STOK 1 | TSI text for 1st `stok` coverage |
| L | STOK 2 | TSI text for 2nd `stok` coverage (if multiple stok entries) |
| M | INVEN/ISI | TSI text for `invenisi` coverage |
| N | MESIN | TSI text for `mesin` coverage |
| O | DLL | TSI text for `dll` coverage |
| P | RATE | Per-mille rates from `policy_coverages.rate_permille`, joined with `+` (comma as decimal separator, e.g. `0,443+0,1+0,1`) |
| Q | PREMI NETT | `premium_amount` |
| R | PREMI | `premium_amount + biaya_polis + materai_amount` |
| S | KOMISI | `commission_amount` |
| T | PAJAK | `commission_tax_amount` |
| U | KOMISI NETT | `net_commission_amount` |
| V | PREMI YG HRS DISETOR | `customer_premium_amount` |

> **TSI text format:** Values are shown as Indonesian short notation — e.g. `500 JT` (Juta / million) or `1,2 M` (Milyar / billion). If the coverage has a `coverage_label`, it is prepended: `label=500 JT`.

**Example request (Postman):**

```
GET {{base_url}}/api/v1/policies/export?month=2026-05
Authorization: Bearer {{access_token}}
```

Set the response **Save to file** in Postman to download the XLSX.

**Response `404` (no matching policies):**
```json
{
  "status_code": 404,
  "status_message": "No policies found for export",
  "data": []
}
```

---

### 6.12 Renew Policy

**POST** `/api/v1/policies/{policy_id}/renew`

Auth required. **[NEW v1.1]** Creates the next `policy_year` record from an expiring (source) policy — the standard "renewal" action. `customer_id`, `insurer_id`, `product_type`, and `issuing_agent_id` are always copied from the source policy. Everything else is copied forward too, but individually overridable.

**Item pertanggungan (coverages):** if the source policy has `policy_coverages` rows, they are copied onto the new policy and `sum_insured` / `premium_amount` / the full commission breakdown are re-derived from the copy (matching how `POST/PUT/DELETE /policies/{id}/coverages` already keeps totals in sync). If the source policy has no coverage rows, the new policy's `sum_insured` / `premium_amount` fall back to the source's scalar values (overridable via the request body).

**Request Body:**
```json
{
  "policy_number": "01.08.2027.001",
  "coverage_start": "2027-08-01",
  "coverage_end": "2028-08-01",
  "commission_rate": 15,
  "commission_tax_rate": 0.025,
  "insured_name": "PT Contoh Sejahtera",
  "materai_amount": 10000,
  "biaya_polis": 50000,
  "diskon": 0,
  "sum_insured": 500000000,
  "premium_amount": 2000000,
  "object_insured": "Gudang, Jl. Industri No. 5",
  "coverage_notes": null,
  "construction_class": "I",
  "notes": null
}
```

> **[NEW v1.2.0]** The `risk_*` fields (`risk_address`, `risk_village`, `risk_district`, `risk_city`, `risk_province`, `risk_postal_code`, `risk_latitude`, `risk_longitude`) are also carried forward from the source policy — the insured building is essentially always the same on renewal — and are individually overridable in the request body, same as `object_insured`.

| Field | Type | Required | Description |
|---|---|---|---|
| `policy_number` | string | **Yes** | New policy number for the renewed period. Must be unique per company. |
| `coverage_start` | date | No | Defaults to the source policy's `coverage_end + 1 day` (contiguous renewal). |
| `coverage_end` | date | No | **Auto = `coverage_start + 365 days` when omitted** — the "customer agrees to renew as-is" default. Provide explicitly for a non-standard period. |
| `commission_rate` | decimal | No | Defaults to the source policy's rate. |
| `commission_tax_rate` | decimal | No | Defaults to the source policy's tax rate (0–1, e.g. `0.025` = 2.5%). |
| `insured_name` | string | No | "Nama tertanggung" override. Defaults to the source policy's `insured_name` (or empty string to clear it). |
| `sum_insured` / `premium_amount` | integer | No | Only used when the source policy has no `policy_coverages` rows — ignored (recomputed) otherwise. Defaults to source's values. |
| `materai_amount` / `biaya_polis` / `diskon` | integer | No | Default to the source policy's values. |
| `object_insured` / `coverage_notes` / `construction_class` / `notes` | string | No | Default to the source policy's values. |
| `risk_address` / `risk_village` / `risk_district` / `risk_city` / `risk_province` / `risk_postal_code` | string | No | **[NEW v1.2.0]** Default to the source policy's values. |
| `risk_latitude` / `risk_longitude` | float | No | **[NEW v1.2.0]** Default to the source policy's values. Must be provided together if overriding. |

**Behavior:**
- The **source** policy is marked `renewal_status = "renewed"` and logged (`renewal_status_changed`).
- The **new** policy is created with `renewal_status = "pending"`, `payment_status = "unpaid"`, `policy_year = source.policy_year + 1`, `previous_policy_id = source.policy_id`, and logged (`policy_created`).
- A new `commissions` record is auto-created for the new policy, same as `POST /policies`.
- Returns `409` if the source policy is already `renewed` or `cancelled`, or if the new `policy_number` already exists for the company.

**Response `201`:**
```json
{
  "status_code": 201,
  "status_message": "Policy renewed successfully",
  "data": {
    "policy_id": "pol_def456",
    "policy_number": "01.08.2027.001",
    "previous_policy_id": "pol_abc123",
    "coverage_start": "2027-08-01",
    "coverage_end": "2028-08-01"
  }
}
```

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
  "product_code":     "kebakaran",
  "product_name":     "Kebakaran",
  "commission_rate":  15,
  "default_tax_rate": 0.025,
  "policy_prefixes":  "01,08,88,61,62"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `product_code` | string | Yes | Unique code per company (stored as `product_type` on policies). Lowercase. |
| `product_name` | string | Yes | Human-readable name |
| `commission_rate` | float | Yes | Default commission % (0–100) |
| `default_tax_rate` | decimal | **No** ⭐ NEW | Default PPh rate as decimal 0–1 (e.g. `0.025` = 2.5%). Default: `0.025`. Auto-fills `commission_tax_rate` on new policies. |
| `policy_prefixes` | string | No | Comma-separated policy number prefixes for auto-detection (e.g. `01,08,88,61,62`) |

> ⭐ **FE note:** Show `default_tax_rate` as an editable field on the master product form (displayed as a percentage, e.g. convert `0.025` ↔ `2.5%`). When a product's tax rate is updated here, new policies auto-inherit it — existing policies are not retroactively changed.

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
| `default_tax_rate` | decimal | ⭐ NEW — New default PPh rate (0–1). Only affects future policies. |
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

---

## Master Wilayah — Region Lookup

> ⭐ **NEW in v1.3.0**

Cascading Province → City (Regency) → District → Village lookup for Indonesia's administrative regions, mirrored from wilayah.id into the shared core schema. Read-only reference data — there's no create/update/delete here, it exists to drive autocomplete for the `risk_province`/`risk_city`/`risk_district`/`risk_village` fields on policies (see **v1.2.0 — Policy Risk Location** above).

**Base path:** `/api/v1/master-wilayah`

All endpoints are `GET` and require `Authorization: Bearer <access_token>`.

---

### MW-001 — List Provinces

**GET** `/api/v1/master-wilayah/provinces`

No parameters — returns all active provinces.

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Provinces found",
  "data": {
    "data": [
      { "province_id": "prova58fb63d90f4a6f7", "province_code": "11", "province_name": "Aceh" },
      { "province_id": "provfbb7e699f0a966a5", "province_code": "31", "province_name": "DKI Jakarta" },
      { "province_id": "prov0e0e0545f13c2f73", "province_code": "32", "province_name": "Jawa Barat" }
    ]
  }
}
```

---

### MW-002 — List Cities

**GET** `/api/v1/master-wilayah/cities?province_code={province_code}`

| Query param | Required | Description |
|---|---|---|
| `province_code` | **Yes** | `province_code` of a row from MW-001, e.g. `"31"` |

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Cities found",
  "data": {
    "data": [
      { "city_id": "city70b695028f2bff6f", "province_id": "prova58fb63d90f4a6f7", "city_code": "11.71", "city_name": "Kota Banda Aceh" },
      { "city_id": "city960bbd4900bca248", "province_id": "prova58fb63d90f4a6f7", "city_code": "11.05", "city_name": "Kabupaten Aceh Barat" }
    ]
  }
}
```

**Response `400`** — `province_code` missing:
```json
{ "status_code": 400, "status_message": "province_code is required", "data": [] }
```

**Response `404`** — valid province, no cities loaded for it yet (expected for most provinces right now — see coverage note in the v1.3.0 changelog):
```json
{ "status_code": 404, "status_message": "No cities found", "data": [] }
```

---

### MW-003 — List Districts

**GET** `/api/v1/master-wilayah/districts?city_code={city_code}`

| Query param | Required | Description |
|---|---|---|
| `city_code` | **Yes** | `city_code` of a row from MW-002, e.g. `"31.71"` |

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Districts found",
  "data": {
    "data": [
      { "district_id": "distf9d0c6ae79f6b068", "city_id": "city70b695028f2bff6f", "district_code": "31.71.05", "district_name": "Cempaka Putih" }
    ]
  }
}
```

Same `400`/`404` shape as MW-002 for a missing/empty `city_code`.

---

### MW-004 — List Villages

**GET** `/api/v1/master-wilayah/villages?district_code={district_code}`

| Query param | Required | Description |
|---|---|---|
| `district_code` | **Yes** | `district_code` of a row from MW-003, e.g. `"31.71.05"` |

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Villages found",
  "data": {
    "data": [
      { "village_id": "vill11926bce8d8aa635", "district_id": "distf9d0c6ae79f6b068", "village_code": "31.71.05.1002", "village_name": "Cempaka Putih Barat" }
    ]
  }
}
```

Same `400`/`404` shape as MW-002 for a missing/empty `district_code`.

---

> ⭐ **FE implementation notes:**
> 1. Suggested UI: province `<select>` → on change, call MW-002 with its `province_code` and populate the city `<select>` (kept disabled until a province is picked). Repeat the pattern for district (MW-003) and village (MW-004). Clear and disable every downstream select whenever an upstream one changes.
> 2. Use the `*_code` fields to drive the cascade (that's what each next-level call takes as a query param), but write the matching `*_name` into `risk_province`/`risk_city`/`risk_district`/`risk_village` when you submit the policy — those stay free-text columns for now, not FKs to these tables.
> 3. A `404` from MW-002/003/004 means "no data synced for this parent yet," not a broken request — offer a manual text-entry fallback for that field instead of blocking the form. See the coverage note in the v1.3.0 changelog for which provinces are populated today.
> 4. `city_name`/`district_name`/`village_name` already carry their official prefix where relevant (e.g. `"Kabupaten Aceh Barat"`, `"Kota Banda Aceh"`) — display them as returned, no need to prepend your own label.

---

## 6.11 Co-assurance Participants

> ⭐ **NEW in v1.1**

When a policy has `is_coassurance = 1`, one or more other insurers share the risk. This sub-resource manages those participants.

**Base path:** `/api/v1/policies/{policy_id}/coassurance`

All endpoints require auth. The `{policy_id}` must belong to the authenticated company.

---

### CA-001 — List Co-assurance Participants

**GET** `/api/v1/policies/{policy_id}/coassurance`

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Co-assurance participants found",
  "data": {
    "data": [
      {
        "coassurance_id":     "ca_xxx",
        "policy_id":          "pol_xxx",
        "co_insurer_id":      "ins_yyy",
        "co_insurer_name":    "PT Asuransi Wahana Tata",
        "co_insurer_short_name": "WAHANA",
        "is_leader":          1,
        "share_percent":      "60.00",
        "sum_insured_share":  300000000,
        "premium_share":      3000000,
        "commission_rate":    "15.00",
        "commission_amount":  450000,
        "notes":              null,
        "created_by":         "usr_xxx",
        "created_at":         "2026-06-17 09:00:00",
        "updated_at":         "2026-06-17 09:00:00"
      },
      {
        "coassurance_id":     "ca_yyy",
        "co_insurer_id":      null,
        "co_insurer_name":    "PT Asuransi Raya",
        "co_insurer_short_name": null,
        "is_leader":          0,
        "share_percent":      "40.00",
        "sum_insured_share":  200000000,
        "premium_share":      2000000,
        "commission_rate":    "15.00",
        "commission_amount":  300000,
        "notes":              null
      }
    ]
  }
}
```

> ⭐ **FE note:** Rows are sorted: leader first, then by `share_percent` descending. Check `is_leader` to display the leader badge. The sum of `share_percent` across all rows should equal 100 — validate this in the UI before saving.

---

### CA-002 — Add Co-assurance Participant

**POST** `/api/v1/policies/{policy_id}/coassurance`

Automatically sets `policies.is_coassurance = 1` on the parent policy.

**Request Body:**
```json
{
  "co_insurer_name":   "PT Asuransi Wahana Tata",
  "co_insurer_id":     "ins_yyy",
  "is_leader":         true,
  "share_percent":     60,
  "sum_insured_share": 300000000,
  "premium_share":     3000000,
  "commission_rate":   15,
  "notes":             null
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `co_insurer_name` | string | **Yes** | Always required — name of the co-insurer |
| `co_insurer_id` | string | No | FK to `/insurers` if the co-insurer is in your system. Null for external insurers. |
| `is_leader` | boolean | No | `true` = this insurer leads. Default: `false` |
| `share_percent` | float | **Yes** | Their % share of the risk (0.01–100) |
| `sum_insured_share` | int | No | Their portion of total UP (IDR) |
| `premium_share` | int | No | Their portion of premium (IDR) |
| `commission_rate` | float | No | Commission % on their share. Default: 0 |
| `notes` | string | No | Free-text notes |

**Response `201`:**
```json
{ "data": { "coassurance_id": "ca_xxx" } }
```

---

### CA-003 — Update Co-assurance Participant

**PUT** `/api/v1/policies/{policy_id}/coassurance/{coassurance_id}`

All fields optional. Send only what changes.

| Field | Type | Description |
|---|---|---|
| `co_insurer_name` | string | Updated name |
| `co_insurer_id` | string or null | Link/unlink to insurer in system |
| `is_leader` | boolean | Change leader flag |
| `share_percent` | float | New share % |
| `sum_insured_share` | int | New UP portion |
| `premium_share` | int | New premium portion — `commission_amount` is recalculated automatically |
| `commission_rate` | float | New commission rate |
| `notes` | string | Updated notes (send `""` to clear) |

**Response `200`:** `{ "status_message": "Co-assurance participant updated" }`

---

### CA-004 — Remove Co-assurance Participant

**DELETE** `/api/v1/policies/{policy_id}/coassurance/{coassurance_id}`

Removes the participant. If it was the **last** participant, automatically sets `policies.is_coassurance = 0`.

**Response `200`:** `{ "status_message": "Co-assurance participant removed" }`

---

> ⭐ **FE implementation notes for co-assurance:**
> 1. On the **policy form**, add a toggle "Ini polis ko-asuransi". When toggled on, show a multi-row table to enter co-insurer shares. Each row = one `POST /coassurance` call after the policy is created.
> 2. On the **policy detail page**, if `is_coassurance = 1`, show a "Ko-Asuransi" card that fetches `GET /policies/{id}/coassurance` and renders the share table.
> 3. Validate that `sum(share_percent)` = 100 client-side before submitting rows.
> 4. `co_insurer_id` should be a searchable dropdown from `GET /insurers`. If the insurer is not in the system, leave it null and just fill `co_insurer_name`.

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

### RN-003 — Send Renewal WhatsApp

**POST** `/api/v1/renewals/{policy_id}/send-whatsapp`

Auth required. **[NEW v1.1]** Sends a WhatsApp message to the customer about their upcoming renewal (the manual "Send WhatsApp" button on the renewal list). Uses the company's saved `renewal_wa_message_template` (see [3.4](#34-update-notification-settings)) unless `message` is provided, in which case that value is used for this send only — the saved default is untouched. Either way, the final text is run through placeholder substitution before sending.

**Request Body:**
```json
{
  "message": "Halo {customer_name}, polis Anda {policy_number} akan berakhir {days_until_expiry} hari lagi. Konfirmasi perpanjangan ya!"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `message` | string | No | Per-send override. Omit to use the saved company default template (or the built-in default if none is saved). |

**Placeholders** (available in both the saved template and the per-send override): `{customer_name}` `{policy_number}` `{product_type}` `{insurer_name}` `{coverage_end}` `{days_until_expiry}`

**Behavior:**
- Resolves the customer's WhatsApp number (`personal_whatsapp` for individuals, `pic_whatsapp` for companies) — `400` if none on file.
- Sends via the same WAHA integration used elsewhere in the app.
- Logs a `follow_up_logs` entry (`channel = "whatsapp"`, `followup_status = "contacted"`) and a `policy_logs` entry, so the send shows up on the policy's follow-up timeline like a manual entry.

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "WhatsApp message sent",
  "data": {
    "message": "Halo Budi Santoso, polis Anda 01.08.2026.001 akan berakhir 12 hari lagi. Konfirmasi perpanjangan ya!",
    "followup_id": "fu_xyz789"
  }
}
```

**Response `400`** — customer has no WhatsApp number on file, or the number is invalid.
**Response `502`** — the WAHA send itself failed (network/session error); no follow-up log is written in this case.

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

---

## Revenue

**[NEW v1.1]** Production report for reconciling commission manually against insurer statements.

### RV-001 — Revenue Summary

**GET** `/api/v1/revenue/summary`

Auth required.

**Query Parameters:**
| Param | Default | Description |
|---|---|---|
| `month` | current month | `YYYY-MM` — filters by `coverage_start`, same "production date" convention as the policy export's `month` filter |

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Revenue summary retrieved",
  "data": {
    "month": "2026-08",
    "totals": {
      "policies_count": 42,
      "total_premium": 210000000,
      "total_commission_amount": 31500000,
      "total_net_commission_amount": 30712500,
      "total_customer_premium_amount": 195000000
    },
    "weekly": [
      {
        "week_start": "2026-08-01",
        "week_end": "2026-08-02",
        "policies_count": 3,
        "total_premium": 15000000,
        "total_commission_amount": 2250000,
        "total_net_commission_amount": 2193750
      },
      {
        "week_start": "2026-08-03",
        "week_end": "2026-08-09",
        "policies_count": 12,
        "total_premium": 60000000,
        "total_commission_amount": 9000000,
        "total_net_commission_amount": 8775000
      }
    ],
    "by_agent": [
      {
        "agent_id": null,
        "agent_name": "Main Agent",
        "policies_count": 20,
        "total_premium": 100000000,
        "total_commission_amount": 15000000,
        "total_net_commission_amount": 14625000
      },
      {
        "agent_id": "usr_sub001",
        "agent_name": "Siti Aminah",
        "policies_count": 22,
        "total_premium": 110000000,
        "total_commission_amount": 16500000,
        "total_net_commission_amount": 16087500
      }
    ]
  }
}
```

| Field | Description |
|---|---|
| `totals` | Whole-month production and commission totals |
| `weekly[]` | Calendar weeks (Monday–Sunday), clipped to the month's start/end — the first/last entries may be partial weeks |
| `by_agent[]` | Same breakdown grouped by `issuing_agent_id`; `agent_id: null` groups policies issued directly by the main agent |

> `total_premium` is gross premium written (production), not commission received from the insurer — cross-check `total_commission_amount` / `total_net_commission_amount` against what the insurer actually pays using [CM-004 Mark Commission as Received](#cm-004--mark-commission-as-received).

---

## Services (Cron)

**[NEW v1.1]** System-triggered endpoints, not meant for regular FE use.

### SV-001 — Renewal Reminder

**POST** `/api/v1/services/renewal-reminder`

Sends each issuing agent a WhatsApp digest listing their policies entering the renewal window (default 30 days before `coverage_end`, per-company configurable via `renewal_reminder_days` — see [3.4](#34-update-notification-settings)). Each policy is only included once: a successful send stamps `policies.renewal_reminder_sent_at`, so re-running the same day (or retrying) never double-notifies an agent. A failed send leaves it unset so the next run retries it.

**Auth — two ways to call this:**
1. **Service token (for the daily cron).** Generate one once with:
   ```
   php mint-cron-token.php        # prints a ~10-year token to stdout
   ```
   This reuses the existing JWT signing path (`helpers/jwt.php`) — no new secret mechanism. The payload carries `service: "cron"` and no `company_id`, which is how the endpoint knows to run across **every** company that has policies, rather than being scoped to one tenant. Store the printed token wherever the external scheduler (crontab / hosting scheduler / Cloud Scheduler) keeps its secrets, and call this endpoint daily with:
   ```
   Authorization: Bearer <service-token>
   ```
2. **Normal user token.** A logged-in user's regular access token also works — the run is scoped to just that user's `company_id`. Useful for an FE "Kirim reminder sekarang" button or manual testing.

> **Not yet wired up:** this repo has no scheduler of its own — nothing calls this endpoint automatically today. Whoever owns infra needs to point a daily job at it (with the service token above) for the 30-day reminder to actually go out.

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Renewal reminder run complete",
  "data": {
    "companies_processed": 3,
    "agents_notified": 5,
    "policies_included": 14,
    "send_failures": [
      { "company_id": "comp_002", "agent_id": "usr_sub009", "error": "No WhatsApp number on file" }
    ]
  }
}
```

| Field | Description |
|---|---|
| `companies_processed` | Companies checked (service-token calls only; always 1 for a scoped user call) |
| `agents_notified` | Distinct agents who received a WhatsApp message this run |
| `policies_included` | Total policies covered across all sent messages |
| `send_failures[]` | Per-agent failures — no WhatsApp number on file, invalid number, or the WAHA send itself failing. These policies remain un-stamped and are retried on the next run. |

Every send (success or failure) is also logged to `whatsapp_digest_logs` with `digest_type = "renewal_reminder"`, alongside the existing daily/monthly digest audit trail.
