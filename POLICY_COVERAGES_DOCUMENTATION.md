# Policy Coverages — BE & FE Implementation Guide

> Covers the `policy_coverages` child table and `/api/v1/policies/{policy_id}/coverages` endpoints.
> Use this alongside the main `API_DOCUMENTATION.md`.

---

## 1. Background & Problem

The spreadsheet (MEI-2026) tracks **multiple uang pertanggungan categories** per policy:

| Spreadsheet column | `coverage_type` value |
|---|---|
| BANGUNAN | `bangunan` |
| STOK 1, STOK 2 | `stok` |
| INVEN/ISI | `invenisi` |
| MESIN | `mesin` |
| DLL | `dll` |

The spreadsheet RATE column can be a **compound expression** like `2.28+0.5+0.1`. Each component applies to a separate coverage category. The total premi is the **sum of all category premiums**.

**Formula per category:**
```
premium_amount = ROUND(sum_insured × rate_permille / 1000)
```

Rate is in **per-mille (‰)**, NOT percent (%).
Example: `rate_permille = 0.328` on `sum_insured = 500,000,000` → premi = **Rp 164,000**

---

## 2. Database

### 2.1 Migration

Run this SQL on `agentra_dev` before using the endpoints:

```sql
CREATE TABLE IF NOT EXISTS `policy_coverages` (
  `coverage_id`     varchar(50)   NOT NULL,
  `policy_id`       varchar(50)   NOT NULL,
  `coverage_type`   varchar(50)   NOT NULL COMMENT 'bangunan | stok | invenisi | mesin | dll',
  `coverage_label`  varchar(100)  DEFAULT NULL COMMENT 'Free label e.g. Stok 1, Stok 2',
  `sum_insured`     bigint        NOT NULL DEFAULT 0 COMMENT 'Uang pertanggungan in IDR',
  `rate_permille`   decimal(8,4)  NOT NULL DEFAULT 0 COMMENT 'Per-mille rate e.g. 2.28 means 2.28‰',
  `premium_amount`  bigint        NOT NULL DEFAULT 0 COMMENT 'sum_insured × rate_permille / 1000',
  `created_by`      varchar(50)   NOT NULL,
  `created_at`      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by`      varchar(50)   DEFAULT NULL,
  `updated_at`      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`coverage_id`),
  KEY `idx_cov_policy` (`policy_id`),
  CONSTRAINT `fk_cov_policy`
    FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.2 How totals stay in sync

Every time a coverage item is **added, updated, or deleted**, the API automatically recalculates:

```
policies.sum_insured    = SUM(policy_coverages.sum_insured)    WHERE policy_id = ?
policies.premium_amount = SUM(policy_coverages.premium_amount) WHERE policy_id = ?
```

You never need to send `sum_insured` or `premium_amount` to the policies endpoints directly — they are derived automatically from the coverage breakdown.

---

## 3. Backend Endpoints

**Base path:** `/api/v1/policies/{policy_id}/coverages`

All endpoints require `Authorization: Bearer <access_token>`.

---

### 3.1 GET — List all coverage items

```
GET /api/v1/policies/{policy_id}/coverages
```

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Coverages found",
  "data": {
    "items": [
      {
        "coverage_id":    "cov_6849abc123",
        "coverage_type":  "bangunan",
        "coverage_label": "Bangunan",
        "sum_insured":    500000000,
        "rate_permille":  "0.3280",
        "premium_amount": 164000,
        "created_at":     "2026-06-10 09:00:00",
        "updated_at":     "2026-06-10 09:00:00"
      },
      {
        "coverage_id":    "cov_6849abc456",
        "coverage_type":  "stok",
        "coverage_label": "Stok 1",
        "sum_insured":    200000000,
        "rate_permille":  "2.2800",
        "premium_amount": 456000,
        "created_at":     "2026-06-10 09:00:00",
        "updated_at":     "2026-06-10 09:00:00"
      }
    ],
    "total_sum_insured": 700000000,
    "total_premium":     620000
  }
}
```

| Field | Type | Description |
|---|---|---|
| `items` | array | All coverage rows, ordered by type then label |
| `total_sum_insured` | int | Sum of all `sum_insured` values |
| `total_premium` | int | Sum of all `premium_amount` values |

---

### 3.2 POST — Add a coverage item

```
POST /api/v1/policies/{policy_id}/coverages
```

**Request Body:**
```json
{
  "coverage_type":  "bangunan",
  "coverage_label": "Bangunan Utama",
  "sum_insured":    500000000,
  "rate_permille":  0.328
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `coverage_type` | string | Yes | `bangunan`, `stok`, `invenisi`, `mesin`, or `dll` |
| `coverage_label` | string | No | Custom label, e.g. "Stok 1", "Stok 2" |
| `sum_insured` | int | Yes | Uang pertanggungan in IDR (full value, e.g. `500000000` for 500 JT) |
| `rate_permille` | decimal | Yes | Rate in ‰ (e.g. `2.28` not `0.00228`) |

**Response `201`:**
```json
{
  "status_code": 201,
  "status_message": "Coverage item added",
  "data": {
    "coverage_id":    "cov_6849abc123",
    "premium_amount": 164000
  }
}
```

---

### 3.3 PUT — Update a coverage item

```
PUT /api/v1/policies/{policy_id}/coverages/{coverage_id}
```

All fields are optional — send only what changes.

**Request Body:**
```json
{
  "sum_insured":    600000000,
  "rate_permille":  0.443
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `coverage_type` | string | No | Change the category |
| `coverage_label` | string | No | Change the label |
| `sum_insured` | int | No | New sum insured |
| `rate_permille` | decimal | No | New rate in ‰ |

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Coverage item updated",
  "data": {
    "premium_amount": 265800
  }
}
```

---

### 3.4 DELETE — Remove a coverage item

```
DELETE /api/v1/policies/{policy_id}/coverages/{coverage_id}
```

No body needed.

**Response `200`:**
```json
{
  "status_code": 200,
  "status_message": "Coverage item deleted",
  "data": []
}
```

---

### 3.5 Error responses

| Code | Message | Cause |
|---|---|---|
| `400` | `coverage_type is required` | Missing required field |
| `400` | `coverage_type must be one of: bangunan, stok, invenisi, mesin, dll` | Invalid type value |
| `400` | `rate_permille must be a non-negative number` | Negative or non-numeric rate |
| `400` | `No fields provided for update` | PUT body was empty |
| `401` | `Unauthorized` | Missing or expired JWT |
| `404` | `Policy not found` | `policy_id` doesn't belong to company |
| `404` | `Coverage item not found` | `coverage_id` not under this policy |
| `405` | `Method Not Allowed` | Wrong HTTP method |

---

## 4. Spreadsheet → API mapping

How to translate one spreadsheet row into API calls.

**Spreadsheet row example (row 8):**
```
BANGUNAN: —    STOK 1: plastik+200JT    RATE: 2.28+0.5    PREMI: 556,000
```

This means:
- Stok category with rate `2.28‰` for one sum insured component
- Another category with rate `0.5‰`

**You send two POST requests:**

```json
POST /api/v1/policies/pol_xxx/coverages
{
  "coverage_type":  "stok",
  "coverage_label": "Stok Plastik",
  "sum_insured":    200000000,
  "rate_permille":  2.28
}
```

```json
POST /api/v1/policies/pol_xxx/coverages
{
  "coverage_type":  "dll",
  "coverage_label": "Lainnya",
  "sum_insured":    100000000,
  "rate_permille":  0.5
}
```

The API adds both items and recalculates the policy totals automatically.

---

## 5. Frontend Implementation Guide

### 5.1 UI layout — Coverage breakdown table

Render a table inside the policy detail/create form with an **Add Row** button:

```
┌─────────────────────────────────────────────────────────────────────────┐
│  UANG PERTANGGUNGAN & RATE                              [+ Tambah Item]  │
├──────────────┬───────────────────┬──────────────────┬───────────────────┤
│  Kategori    │  Label (opsional) │  Uang Pertanggungan │  Rate (‰)  │  Premi       │  Aksi  │
├──────────────┼───────────────────┼─────────────────────┼────────────┼──────────────┼────────┤
│  Bangunan    │  Bangunan Utama   │        500.000.000  │      0.328 │      164.000 │ ✎ 🗑  │
│  Stok        │  Stok 1           │        200.000.000  │      2.280 │      456.000 │ ✎ 🗑  │
│  Mesin       │  Mesin Produksi   │        100.000.000  │      0.500 │       50.000 │ ✎ 🗑  │
├──────────────┴───────────────────┴─────────────────────┴────────────┼──────────────┤
│                                                           TOTAL PREMI │      670.000 │
└─────────────────────────────────────────────────────────────────────────────────────┘
```

### 5.2 Loading the table

Fetch on policy detail page load:

```js
// GET /api/v1/policies/{policy_id}/coverages
async function fetchCoverages(policyId) {
  const res = await fetch(`/api/v1/policies/${policyId}/coverages`, {
    headers: { Authorization: `Bearer ${token}` }
  });
  const json = await res.json();
  // json.data.items  → array of coverage rows
  // json.data.total_sum_insured → total UP
  // json.data.total_premium     → total premi
  return json.data;
}
```

### 5.3 Add a new row

Show a modal or inline form with these inputs:

| Input | Type | Notes |
|---|---|---|
| Kategori | `<select>` | Options: Bangunan, Stok, Invenisi, Mesin, DLL |
| Label | `<input text>` | Optional — useful for "Stok 1", "Stok 2" |
| Uang Pertanggungan | `<input number>` | Full IDR value — format as currency in display only |
| Rate (‰) | `<input number>` | Decimal e.g. `2.28`. Show **preview** of premi = UP × rate / 1000 |

**Live premium preview (client-side):**
```js
function previewPremium(sumInsured, ratePermille) {
  return Math.round(sumInsured * ratePermille / 1000);
}
// Update on every keypress in either field
```

**Submit:**
```js
async function addCoverage(policyId, payload) {
  const res = await fetch(`/api/v1/policies/${policyId}/coverages`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      coverage_type:  payload.coverageType,   // 'bangunan' | 'stok' | ...
      coverage_label: payload.coverageLabel,  // optional
      sum_insured:    payload.sumInsured,      // integer, no formatting
      rate_permille:  payload.ratePermille     // decimal number
    })
  });
  return res.json();
}
```

After success: re-fetch the coverage list and update the total premi display.

### 5.4 Edit a row

Pre-fill the form with existing values. On submit:

```js
async function updateCoverage(policyId, coverageId, changes) {
  const res = await fetch(`/api/v1/policies/${policyId}/coverages/${coverageId}`, {
    method: 'PUT',
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(changes)  // only the changed fields
  });
  return res.json();
}
```

### 5.5 Delete a row

Show a confirmation dialog, then:

```js
async function deleteCoverage(policyId, coverageId) {
  const res = await fetch(`/api/v1/policies/${policyId}/coverages/${coverageId}`, {
    method: 'DELETE',
    headers: { Authorization: `Bearer ${token}` }
  });
  return res.json();
}
```

### 5.6 Display the compound rate (spreadsheet-style)

To reproduce the `2.28+0.5` notation in the UI, derive it from the items array:

```js
function formatCompoundRate(items) {
  return items
    .map(item => parseFloat(item.rate_permille))
    .join('+');
  // → "2.28+0.5"
}
```

### 5.7 Number formatting (IDR)

```js
// Format integer to "Rp 500.000.000"
const formatIDR = (val) =>
  'Rp ' + Number(val).toLocaleString('id-ID');

// Format permille for display
const formatRate = (val) =>
  parseFloat(val).toString() + '‰';
```

### 5.8 Recommended state shape (React example)

```js
const [coverages, setCoverages] = useState([]);
const [totals, setTotals] = useState({ total_sum_insured: 0, total_premium: 0 });

async function reloadCoverages() {
  const data = await fetchCoverages(policyId);
  setCoverages(data.items);
  setTotals({
    total_sum_insured: data.total_sum_insured,
    total_premium:     data.total_premium
  });
}
// Call reloadCoverages() after every add / update / delete
```

---

## 6. Valid `coverage_type` values

| Value | Indonesian label |
|---|---|
| `bangunan` | Bangunan |
| `stok` | Stok / Persediaan |
| `invenisi` | Inventaris / Isi |
| `mesin` | Mesin |
| `dll` | Lain-lain (DLL) |

---

## 7. Key rules to remember

1. **Rate is per-mille (‰)**, not percent. `0.328` means 0.328‰, not 0.328%.
2. **`premium_amount` is always computed server-side** — never send it in POST/PUT.
3. **`policies.sum_insured` and `policies.premium_amount` are auto-synced** after every coverage change — no need to update the parent policy separately.
4. **Multiple rows of the same `coverage_type` are allowed** — use `coverage_label` to distinguish them (e.g. two `stok` rows labeled "Stok 1" and "Stok 2").
5. **Deleting all coverage items** sets the policy totals to 0, it does not delete the policy itself.
6. **Deleting a policy** (`ON DELETE CASCADE`) automatically removes all its coverage items.
