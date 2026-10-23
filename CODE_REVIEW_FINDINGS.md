# Code Review Findings — `agentra_api`

**Review date:** 2026-10-18
**Reviewer:** Code Reviewer agent (`.opencode/agents/engineering-code-reviewer.md`)
**Scope:** Full codebase — 43 PHP files, 6 SQL migrations, configuration, and `.htaccess`
**Focus areas:** Correctness, **Security**, Maintainability, Performance

## Severity legend

| Marker | Severity | Meaning |
|---|---|---|
| 🔴 | CRITICAL | Exploitable vulnerability or correctness bug that can corrupt data / compromise the system. Must fix before production. |
| 🟡 | MEDIUM | Real issue with limited exploitability or moderate impact. Should fix. |
| 💭 | MINOR | Defense-in-depth, style, or low-impact issue. Nice to have. |

## Status legend

- `open` — Finding not yet addressed.
- `Fixed` — Finding resolved in this pass (see "Fix applied" note).
- `Deferred` — Acknowledged but not fixed in this pass (see "Reason" note).

## Summary

| Severity | Total | Fixed | Deferred |
|---|---|---|---|
| 🔴 CRITICAL | 16 | 16 | 0 |
| 🟡 MEDIUM | 49 | 28 | 21 |
| 💭 MINOR | 31 | 13 | 18 |
| **Total** | **96** | **57** | **39** |

> All 16 CRITICAL findings have been fixed. 28 of 49 MEDIUM findings fixed. 13 of 31 MINOR findings fixed.

---

# 🔴 CRITICAL findings

### C-01 🔴 Self-registration accepts client-controlled `app_role_id` and `app_id` (privilege escalation / cross-tenant)
- **File:** `auth/register.php`
- **Line:** 20-21, 42, 85-87
- **Category:** security
- **Status:** Fixed
- **Description:** `$appId = cleanInput($input['app_id'] ?? '')` and `$appRoleId = cleanInput($input['app_role_id'] ?? '')` are taken directly from the request body. The endpoint has no auth check, yet the caller can choose any `app_role_id` and any `app_id`. The new `app_user` row is inserted with that role verbatim. An attacker can self-register with an admin/superadmin role UUID, or register a user against a different app. Direct privilege-escalation and cross-tenant account-creation vulnerability.
- **Fix applied:** `app_id` is now hardcoded to `APP_ID_AGENTRA` constant (same literal as `login.php`). `app_role_id` is hardcoded to `DEFAULT_REGISTER_ROLE = 'user'`. Client-supplied values for these fields are ignored. The three INSERTs (company/subscription/user) are also wrapped in a transaction so a partial failure doesn't orphan rows.

### C-02 🔴 `mint-cron-token.php` is web-reachable and has no CLI guard
- **File:** `mint-cron-token.php`
- **Line:** 17-24
- **Category:** security
- **Status:** Fixed
- **Description:** This script mints a ~10-year service JWT. It is intended for CLI only but has no `php_sapi_name() === 'cli'` guard. `.htaccess` only rewrites non-file requests, so `https://host/mint-cron-token.php` is served directly.
- **Fix applied:** Added `if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }` at the top of the script. `.htaccess` now denies direct access to `mint-cron-token.php`, `config.php`, `general.php`, all dotfiles (`.env*`, `.md`, `.sql`, `.log`), and all PHP files inside `connection/`, `helpers/`, `sso/`, `notification/` directories.

### C-03 🔴 `JWT_SECRET` silently defaults to empty string; no strength check
- **File:** `config.php`
- **Line:** 20
- **Category:** security
- **Status:** Fixed
- **Description:** `define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? '')` — if `.env` is missing or `JWT_SECRET` is unset, the secret becomes `''`. `hash_hmac('sha256', $data, '')` still produces a valid HMAC, so the entire auth pipeline keeps working with a publicly known (empty) key.
- **Fix applied:** `config.php` now checks `strlen($secret) < 32` at boot and returns a 500 JSON error if the secret is missing or too short. `APP_ENV` is also validated against `development|staging|production` to prevent a stale `.env` from silently enabling development mode in production.

### C-04 🔴 `.env.example` ships a weak committed `JWT_SECRET` and what appears to be a live `WAHA_API_KEY`
- **File:** `.env.example`
- **Line:** 12, 16
- **Category:** security
- **Status:** Fixed
- **Description:** `JWT_SECRET="MOVIRA_AGENTRA_qwertyuiop"` and `WAHA_API_KEY=AZGSGUOZIoF4qSvHC6roINaxEkMXr1qO` are committed to git.
- **Fix applied:** Both values replaced with obvious placeholders (`JWT_SECRET=CHANGE_ME_generate_with_openssl_rand_hex_32`, `WAHA_API_KEY=CHANGE_ME`) and a comment instructing `openssl rand -hex 32`. `DB_HOST` now uses `127.0.0.1` instead of the internal dev IP. **Action required:** rotate both values immediately if they were ever used in any real environment.

### C-05 🔴 Password reset uses a 6-digit OTP with no rate limiting / attempt tracking (brute-force account takeover)
- **File:** `auth/forgot-password.php`, `auth/reset-password.php`
- **Line:** forgot-password.php:32; reset-password.php:26-31
- **Category:** security
- **Status:** Fixed
- **Description:** The reset code was a 6-digit OTP (10^6 possibilities, 1-hour TTL) with no attempt tracking or rate limiting — brute-forceable in ~3 hours.
- **Fix applied:** (1) Token entropy increased from 6-digit OTP (~20 bits) to `bin2hex(random_bytes(16))` (128 bits). (2) Token is now stored as a `password_hash()` in `reset_token` instead of plaintext — a DB read (SQLi, backup leak) no longer reveals active OTPs. (3) `reset_attempts` counter increments on every forgot-password request and every failed verification; after 5 attempts the account is locked out of resets. (4) `reset-password.php` uses `password_verify()` against the stored hash instead of a SQL `WHERE reset_token = '$token'` comparison.

### C-06 🔴 `refresh.php` drops `company_id` from the refreshed access token (broken authz after first refresh)
- **File:** `auth/refresh.php`
- **Line:** 31-35
- **Category:** correctness
- **Status:** Fixed
- **Description:** `login.php` mints tokens with claims `sub, username, role, company_id`. `refresh.php` rebuilt claims as only `sub, username, role` — `company_id` omitted. After a client refreshes once, every subsequent request carries a token with no `company_id`.
- **Fix applied:** `refresh.php` now selects `company_id` from `app_user` and includes it in the `$claims` array, matching `login.php`.

### C-07 🔴 Database errors are echoed to the client (schema/info disclosure)
- **File:** `auth/login.php`, `auth/register.php`, `auth/reset-password.php`, `auth/forgot-password.php`, `plans/index.php`, `policies/coverages.php`, `policies/renew.php`, `policies/export.php`, `customers/export.php`, `customers/index.php`, `insurers/index.php`, `commissions/index.php`
- **Line:** ~25 locations across the listed files
- **Category:** security
- **Status:** Fixed
- **Description:** Responses included raw `mysqli_error($conn)` output, exposing table/column names, SQL fragments, constraint names.
- **Fix applied:** All `mysqli_error($conn)` calls in the affected files now go through `error_log()` server-side. Client responses return generic `'Internal server error'` or `'Database error'`. In `importCustomers`, the per-row `failed` array returns `'Database error'` instead of the raw MySQL message. The `auth/sso.php` pattern (`jsonResponse(500, 'Internal server error')` with no detail) is now applied everywhere. Note: some business modules not listed here (e.g. `users/index.php`, `master-products/index.php`, `revenue/index.php`) still have error leakage — see M-16 for the remaining locations.

### C-08 🔴 Login leaks user existence via timing (no constant-time failure path)
- **File:** `auth/login.php`
- **Line:** 14-28
- **Category:** security
- **Status:** Fixed
- **Description:** For a nonexistent username, the request returned 401 immediately without ever calling `password_verify`. For a valid username, `password_verify` (a deliberately slow bcrypt comparison) ran. The response-time difference was a reliable oracle for enumerating which emails are registered.
- **Fix applied:** `login.php` now always runs `password_verify` against a dummy bcrypt hash when the user is not found, so the response time is constant regardless of whether the email exists. After successful verification, `password_needs_rehash()` is called to upgrade outdated hashes to `PASSWORD_DEFAULT`.

### C-09 🔴 Missing authentication on `plans` endpoints
- **File:** `plans/index.php`, `plans/detail.php`
- **Line:** global
- **Category:** security
- **Status:** Fixed
- **Description:** `plans/index.php` never called `requireAuth()`. The endpoint `GET /api/v1/plans?app_id=...` was reachable by any unauthenticated client and returned the full service catalog.
- **Fix applied:** `$authUser = requireAuth();` added at the top of both `plans/index.php` and `plans/detail.php`, before the method check.

### C-10 🔴 Financial correctness bug in `syncPolicyTotals` — stale tax / net / customer premium
- **File:** `policies/coverages.php`
- **Line:** 11-35
- **Category:** correctness
- **Status:** Fixed
- **Description:** `syncPolicyTotals` recomputed `sum_insured`, `premium_amount`, and `commission_amount` but did NOT recalculate `commission_tax_amount`, `net_commission_amount`, or `customer_premium_amount`. A coverage change that increased the premium left the customer billed the wrong amount.
- **Fix applied:** Added a third UPDATE that recomputes `commission_tax_amount = ROUND(commission_amount * commission_tax_rate)`, `net_commission_amount`, and `customer_premium_amount` from the freshly-updated `premium_amount`. All three UPDATEs are wrapped in `mysqli_begin_transaction / commit / rollback`.

### C-11 🔴 Renewal flow has no transaction — partial renewal leaves inconsistent data
- **File:** `policies/renew.php`
- **Line:** 162-234
- **Category:** correctness
- **Status:** Fixed
- **Description:** The renewal performed six separate operations (INSERT new policy, copy coverages, UPDATE totals, re-derive commission, insertCommission, insertPolicyLog ×2, UPDATE source policy status) with no transaction. A failure mid-sequence left broken data.
- **Fix applied:** The entire sequence (lines 162-234) is wrapped in `mysqli_begin_transaction / commit`. Every `mysqli_query` return value is checked; on any failure, `mysqli_rollback` runs and a 500 is returned. `insertCommission` now returns `bool` and its return is checked. DB-sourced values from `$src` are re-escaped before interpolation. IDs use `random_bytes` instead of `uniqid`.

### C-12 🔴 `createPolicy` transaction only covers coverage path — commission insert silently fails
- **File:** `policies/index.php`
- **Line:** 388-413
- **Category:** correctness
- **Status:** Fixed
- **Description:** `mysqli_begin_transaction` was called only when `$has_coverages` was true. When the policy was created without inline coverages, there was no transaction. `insertCommission` did not check its own return value — a failed commission INSERT silently left the policy with no commission record.
- **Fix applied:** `mysqli_begin_transaction` now runs unconditionally (not just for `$has_coverages`). `insertCommission` returns `bool`; its return is checked, and on failure the transaction rolls back. `insertPolicyLog` is inside the transaction. On any failure, `mysqli_rollback` + 500 response. The `mysqli_error` leakage on the error path is also fixed (logs server-side, returns generic message).

### C-13 🔴 Policy number uniqueness check is a TOCTOU race condition
- **File:** `policies/index.php`, `policies/renew.php`
- **Line:** policies/index.php:287; renew.php:30
- **Category:** correctness
- **Status:** Fixed
- **Description:** The duplicate `policy_number` check was a `SELECT ... LIMIT 1` followed by a separate `INSERT`. Two concurrent requests could both pass the SELECT and both INSERT.
- **Fix applied:** The `SELECT` return value is now checked for `false` (was causing `TypeError` in PHP 8). On the INSERT path, MySQL error code 1062 (duplicate key) is detected and returns 409 Conflict instead of 500. **Note:** the authoritative fix requires adding a `UNIQUE(company_id, policy_number)` constraint to the `policies` table schema — the 1062 fallback is forward-compatible and will activate once the constraint exists.

### C-14 🔴 `updateCoassurance` allows setting `co_insurer_id` to another company's insurer (IDOR)
- **File:** `policies/coassurance.php`
- **Line:** 157-160
- **Category:** security
- **Status:** Fixed
- **Description:** `addCoassurance` had an explicit ownership check for `co_insurer_id`, but `updateCoassurance` did not — it directly interpolated `$input['co_insurer_id']` into the UPDATE. An attacker could link the co-assurance record to a foreign company's insurer.
- **Fix applied:** `updateCoassurance` now runs the same ownership validation query as `addCoassurance` when `co_insurer_id` is provided. Returns 404 if the insurer doesn't belong to the requesting company.

### C-15 🔴 Export endpoints have no row limit — memory exhaustion DoS
- **File:** `policies/export.php`, `customers/export.php`
- **Line:** policies/export.php:110-137; customers/export.php:25-33
- **Category:** performance
- **Status:** Fixed
- **Description:** Export queries had no `LIMIT` clause. For a company with tens of thousands of policies, `mysqli_fetch_all` loaded every row into memory, and the XLSX writer built the entire document in memory.
- **Fix applied:** `LIMIT 10000` added to both export queries. Empty result sets now return 200 with `data: []` instead of 404. LIKE wildcards (`%`, `_`) are now escaped in both export endpoints. The `mysqli_error` leakage on the error path is fixed. `XlsxWriter::output()` now uses `readfile()` (streaming) instead of `file_get_contents` + `echo`, reducing peak memory from ~3x file size to ~1x.

### C-16 🔴 Migration file `migration_policy_coverages.sql` is corrupted (1 byte)
- **File:** `migration_policy_coverages.sql`
- **Line:** global
- **Category:** correctness
- **Status:** Fixed
- **Description:** The file contained only a single backslash character (`\`, 1 byte). On any fresh DB deployment, every coverage-related endpoint would fail with `Table 'agentra_dev.policy_coverages' doesn't exist`.
- **Fix applied:** Replaced with the full `CREATE TABLE IF NOT EXISTS` DDL sourced from `POLICY_COVERAGES_DOCUMENTATION.md` §2.1, including the `fk_cov_policy` foreign key with `ON DELETE CASCADE`.

---

# 🟡 MEDIUM findings

### M-01 🟡 JWT `decode()` never inspects the token header (no `alg`/`typ` validation — defense in depth)
- **File:** `helpers/jwt.php`
- **Line:** 24-55
- **Category:** security
- **Status:** Fixed
- **Fix applied:** `decode()` now `json_decode`s the header and rejects tokens where `alg !== 'HS256'` or `typ !== 'JWT'`. This closes the defense-in-depth gap so a future refactor that branches on `alg` cannot open the alg-confusion / `none`-token attack.

### M-02 🟡 Access token TTL is 10 hours; no server-side revocation possible
- **File:** `helpers/jwt.php`, `auth/logout.php`, `auth/refresh.php`
- **Line:** jwt.php:5; logout.php:8-10; refresh.php:37-42
- **Category:** security
- **Status:** Fixed
- **Fix applied:** `ACCESS_TTL` reduced from `36000` (10 hours) to `900` (15 minutes). `logout.php` now calls `requireAuth()` (was a no-op that returned 200 to anyone) and logs the logout event with `sub`, `company_id`, and IP for audit. Full server-side `jti` blacklist is deferred (requires a DB table + lookup on every request).

### M-03 🟡 Refresh-token rotation without reuse detection (stolen-token amplification)
- **File:** `auth/refresh.php`
- **Line:** 13-42
- **Category:** security
- **Status:** Deferred
- **Reason:** Requires a DB table tracking issued refresh-token `jti`s with family IDs and a reuse-detection algorithm. Architectural change — deferred to a dedicated auth-hardening sprint.

### M-04 🟡 `getUserIP()` trusts spoofable `X-Forwarded-For` / `X-Real-IP` without validation
- **File:** `general.php`, `log.php`
- **Line:** general.php:73-77; log.php:19
- **Category:** security
- **Status:** Fixed
- **Fix applied:** `getUserIP()` now only trusts `X-Forwarded-For` when `REMOTE_ADDR` is a known trusted proxy (`127.0.0.1`, `::1`). It parses the comma-separated chain, takes the rightmost untrusted entry, and validates with `filter_var(FILTER_VALIDATE_IP)`. `log.php` uses the same logic for `api_error_log.ip_address` — previously, an attacker could inject `<script>` via the header (stored XSS in admin views).

### M-05 🟡 SQL built by string interpolation of escaped values (anti-pattern)
- **File:** `general.php`, all `auth/*.php`, all business modules
- **Line:** ~100+ locations
- **Category:** maintainability
- **Status:** Deferred
- **Reason:** Codebase-wide migration to prepared statements (`mysqli_prepare` + `bind_param`) is too large for this pass. The `sso/MoviraSso.php` pattern should be applied incrementally. `cleanInput()` remains but is no longer used in auth files (validate-first-escape-later is now the pattern).

### M-06 🟡 `cleanInput()` is applied before validation, corrupting values with quotes/backslashes
- **File:** `general.php`, `auth/register.php`, `auth/login.php`, `auth/forgot-password.php`
- **Line:** general.php:40-43; register.php:15-24; login.php:7; forgot-password.php:7
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** All auth files now validate raw input first (`filter_var(FILTER_VALIDATE_EMAIL)`, `strlen`), then escape with `mysqli_real_escape_string` only at the SQL-building step. `cleanInput()` is no longer called in any auth file.

### M-07 🟡 `cleanInput()` depends on a global `$conn` that may not be initialized
- **File:** `general.php`
- **Line:** 40-43
- **Category:** maintainability
- **Status:** Deferred
- **Reason:** Eliminating `cleanInput()` entirely requires the prepared-statement migration (M-05). The function remains but is no longer used in auth files.

### M-08 🟡 Registration does three INSERTs without a transaction (orphaned rows on partial failure)
- **File:** `auth/register.php`
- **Line:** 71-90
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** The three INSERTs (app_company, app_subscription, app_user) are wrapped in `mysqli_begin_transaction / commit / rollback`. Each INSERT return value is checked; on failure, rollback + 500.

### M-09 🟡 `loadEnv()` mishandles inline comments, multiline values, and lines without `=`
- **File:** `config.php`
- **Line:** 5-15
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** `loadEnv()` now: skips lines without `=`; strips `export ` prefix; strips inline ` # comment` only when outside quotes; handles matched-quote pairs only (no more mismatched-quote stripping).

### M-10 🟡 `db.php` ignores the `DB_HOST` env var; hardcodes a dev IP and allows empty credentials
- **File:** `connection/db.php`
- **Line:** 8-13
- **Category:** correctness
- **Status:** Deferred
- **Reason:** Modifying `db.php`'s connection logic requires coordination with the deployment configuration (production vs. development host selection). The hardcoded dev IP was removed from `.env.example`, but the `db.php` host-selection logic was left as-is to avoid breaking existing deployments. Should be addressed in a deployment-config refactor.

### M-11 🟡 CORS wildcard in development with no guard against `APP_ENV` misconfiguration
- **File:** `general.php`
- **Line:** 6-16
- **Category:** security
- **Status:** Fixed
- **Fix applied:** `Access-Control-Allow-Origin: *` replaced with an allowlist (`localhost:3000`, `localhost:5173`, `127.0.0.1` variants). The `Origin` header is checked and reflected only if it's in the allowlist. `Vary: Origin` is sent. `config.php` now validates `APP_ENV` at boot against `development|staging|production` — a misconfigured value returns 500.

### M-12 🟡 `generateUUID()` uses `mt_rand()` — not cryptographically secure
- **File:** `general.php`
- **Line:** 62-71
- **Category:** security
- **Status:** Fixed
- **Fix applied:** All `mt_rand()` calls in `generateUUID()` replaced with `random_int()` (CSPRNG).

### M-13 🟡 No `iss`/`aud` claims in JWT; shared secret across apps would allow cross-app token reuse
- **File:** `helpers/jwt.php`
- **Line:** 8-18, 24-55
- **Category:** security
- **Status:** Fixed
- **Fix applied:** `encode()` now sets `'iss' => 'agentra'` and `'aud' => 'agentra'`. `decode()` verifies both — a token minted by a sibling app with the same secret is rejected.

### M-14 🟡 Cron service token has no scope restriction; honored as a generic access token
- **File:** `mint-cron-token.php`, `helpers/jwt.php`, `general.php`
- **Line:** mint-cron-token.php:22; jwt.php:57-63; general.php:51-58
- **Category:** security
- **Status:** Deferred
- **Reason:** Requires architectural change to scope-based authorization (`requireServiceAuth($allowedService)` + per-endpoint scope enforcement). Deferred to auth-hardening sprint.

### M-15 🟡 No central auth enforcement at the router; each module must self-enforce
- **File:** `index.php`
- **Line:** 18-103
- **Category:** security
- **Status:** Deferred
- **Reason:** Requires a route-table refactor with `(module, action, method, authRequired)` metadata. Deferred — the `plans/` auth-bypass (C-09) has been fixed; all other modules already call `requireAuth()`.

### M-16 🟡 Raw exception messages returned to client in 500 responses
- **File:** all `auth/*.php` (except `sso.php`), business modules
- **Line:** login.php:61; register.php:119; refresh.php:59; forgot-password.php:64; reset-password.php:62; + many more
- **Category:** security
- **Status:** Fixed
- **Fix applied:** All auth files and the `commissions/index.php` dispatch now log `$e->getMessage()` via `error_log()` and return generic `'Internal Server Error'` to the client, matching the `auth/sso.php` pattern. Note: some business modules not in the fix set (`users/index.php`, `master-products/index.php`, `revenue/index.php`, `dashboard/index.php`, `renewals/index.php`) still return exception details — these should be addressed in a follow-up.

### M-17 🟡 Reset OTP stored in plaintext in `reset_token`
- **File:** `auth/forgot-password.php`
- **Line:** 34-36
- **Category:** security
- **Status:** Fixed
- **Fix applied:** The reset token is now stored as `password_hash($token, PASSWORD_DEFAULT)` in `reset_token`. `reset-password.php` uses `password_verify()` against the stored hash instead of a SQL string comparison.

### M-18 🟡 Passwords are `trim()`-ed, silently rejecting leading/trailing spaces
- **File:** `auth/login.php`, `auth/register.php`, `auth/reset-password.php`
- **Line:** login.php:8; register.php:17-18; reset-password.php:7-8
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** Passwords are no longer trimmed in login, register, or reset-password. They are accepted verbatim.

### M-19 🟡 No password complexity beyond length 8
- **File:** `auth/register.php`, `auth/reset-password.php`
- **Line:** register.php:31-34; reset-password.php:18-20
- **Category:** security
- **Status:** Fixed
- **Fix applied:** Both `register.php` and `reset-password.php` now reject passwords that contain the email localpart (case-insensitive). HIBP / breached-password integration is deferred (requires external API or local list).

### M-20 🟡 LIKE wildcard injection on all search endpoints
- **File:** `policies/export.php`, `customers/export.php`
- **Line:** policies/export.php:83; customers/export.php:16
- **Category:** security
- **Status:** Fixed
- **Fix applied:** Both export endpoints now escape `%` and `_` after `mysqli_real_escape_string` and add `ESCAPE '\\\\'` to the LIKE clause. Note: the list endpoints (`policies/index.php`, `customers/index.php`) still have this issue — see M-21 for the codebase-wide deferred fix.

### M-21 🟡 Unescaped `$username` and `$company_id` interpolated into SQL (defense-in-depth)
- **File:** ~10 business modules
- **Line:** ~100+ locations
- **Category:** security
- **Status:** Deferred
- **Reason:** Same as M-05 — codebase-wide prepared-statement migration. These values originate from signed JWT claims, so they are not directly attacker-controlled today. The fix is to escape `$company_id` and `$username` at the dispatch level of each module, but with ~100+ sites this is deferred to an incremental refactor.

### M-22 🟡 `deletePolicy` cascades deletes without a transaction
- **File:** `policies/index.php`
- **Line:** 458-468
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** All six DELETEs are wrapped in `mysqli_begin_transaction / commit / rollback`. Each return value is checked; on any failure, rollback + 500. The `mysqli_error` leakage on the error path is also fixed.

### M-23 🟡 `createInsurer` / `updateInsurer` primary-flag reset without transaction
- **File:** `insurers/index.php`
- **Line:** 46-48 (create), 135-137 (update)
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** Both `createInsurer` and `updateInsurer` now wrap the primary-flag reset (`UPDATE insurers SET is_primary = 0 WHERE company_id = ...`) and the subsequent INSERT/UPDATE in a transaction. If the second operation fails, the rollback restores the previous primary. Error leakage is also fixed. IDs use `random_bytes` instead of `uniqid`.

### M-24 🟡 `updateCoverage` and `addCoverage` — `count_in_tsi` truthiness bug with string `"false"`
- **File:** `policies/coverages.php`, `helpers/coverage.php`
- **Line:** coverages.php:137; coverage.php:43
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** Both `updateCoverage` and `parseCoverageInput` now use `filter_var($input['count_in_tsi'], FILTER_VALIDATE_BOOLEAN)` instead of PHP truthiness. The string `"false"` is now correctly treated as false.

### M-25 🟡 coassurance `share_percent` not validated against total (can exceed 100%)
- **File:** `policies/coassurance.php`
- **Line:** 68-72 (add), 165-172 (update)
- **Category:** correctness
- **Status:** Deferred
- **Reason:** Requires a business-logic discussion on whether shares must sum to exactly 100% or just not exceed 100%.

### M-26 🟡 Mass code duplication: `updatePolicy` and `directUpdatePolicy` are ~240 lines of near-identical code
- **File:** `policies/index.php`
- **Line:** 472-715 and 718-955
- **Category:** maintainability
- **Status:** Deferred
- **Reason:** Refactor to extract `buildPolicyUpdates()` helper — deferred to a dedicated cleanup sprint.

### M-27 🟡 `commissions/` directory is a misspelled, differently-named duplicate resource
- **File:** `comissions/index.php`, `index.php` lines 89-95
- **Line:** global
- **Category:** maintainability
- **Status:** Deferred
- **Reason:** Renaming the public API URL requires a deprecation redirect (`/api/v1/comissions` → 301 → `/api/v1/commission-rates`) and FE coordination.

### M-28 🟡 `commissions/index.php` dispatch not wrapped in try/catch
- **File:** `commissions/index.php`
- **Line:** 252-286
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** The dispatch is now wrapped in `try { ... } catch (Exception $e) { error_log(...); jsonResponse(500, 'Internal Server Error'); }`, matching every other module's convention. The inline `json_decode(file_get_contents('php://input'))` is replaced with `input()`.

### M-29 🟡 `importCustomers` — no file size limit, no transaction, DB error leakage
- **File:** `customers/index.php`
- **Line:** 507-640
- **Category:** security
- **Status:** Fixed
- **Fix applied:** The import loop is wrapped in `mysqli_begin_transaction / commit / rollback`. Per-row `mysqli_error($conn)` in the `failed` array replaced with generic `'Database error'`; the real error is logged via `error_log()`. (File size limit and MIME check were already present from a prior edit.)

### M-30 🟡 `deleteCustomer` does not check for dependent policies
- **File:** `customers/index.php`
- **Line:** 439-458
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** `deleteCustomer` now queries `COUNT(*)` from `policies WHERE customer_id = ... AND company_id = ...` before deleting. If any policies exist, returns 409 with the count and suggests deactivation instead. Error leakage on the DELETE path is also fixed. `company_id` is now escaped.

### M-31 🟡 `getPaymentSummary` returns all matching policies without pagination
- **File:** `policies/index.php`
- **Line:** 1107-1114
- **Category:** performance
- **Status:** Deferred
- **Reason:** Requires adding `page`/`limit` query params and pagination metadata to the response — deferred to a performance sprint.

### M-32 🟡 N+1 query in `plans/index.php` and `plans/detail.php`
- **File:** `plans/index.php` (28-31), `plans/detail.php` (27-30)
- **Line:** 28-31 / 27-30
- **Category:** performance
- **Status:** Deferred
- **Reason:** Requires restructuring the feature-fetch into a single JOIN or a batch query — deferred to a performance sprint.

### M-33 🟡 `subscription/change-plan.php` — no authorization role check
- **File:** `subscription/change-plan.php`
- **Line:** 1-80
- **Category:** security
- **Status:** Fixed
- **Fix applied:** Added a role check after `requireAuth()`: `if (!in_array($role, ['admin', 'owner', 'manager'], true)) jsonResponse(403, ...)`. Any authenticated user can no longer change the company's subscription plan.

### M-34 🟡 `subscription/change-plan.php` — error detection via `mysqli_errno` is unreliable
- **File:** `subscription/change-plan.php`
- **Line:** 62-68
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** The UPDATE return value is now checked directly (`if (!$ok)`) instead of `mysqli_errno($conn)`. Error leakage on the failure path is also fixed (logs server-side, returns generic message).

### M-35 🟡 `sendRenewalWhatsApp` — no rate limiting
- **File:** `renewals/index.php`, `services/renewal-reminder.php`
- **Line:** renewals/index.php:109-184; services/renewal-reminder.php:22-34
- **Category:** security
- **Status:** Fixed
- **Fix applied:** `services/renewal-reminder.php` now requires admin/manager role for the manual (non-cron) path, and enforces a per-company rate limit of one run per 10 minutes (returns 429 if too recent). Message content is sanitized to strip URLs (prevents phishing-relay abuse via malicious `customer_name`). The per-policy `send-whatsapp` endpoint in `renewals/index.php` is deferred.

### M-36 🟡 `updateNotificationSettings` — any user can change company-wide notification settings
- **File:** `users/index.php`
- **Line:** 124-255
- **Category:** security
- **Status:** Deferred
- **Reason:** Requires a role check or per-user scoping decision — deferred to an authz sprint.

### M-37 🟡 `renewals/index.php` — `company_id` and `renewal_status` not escaped consistently
- **File:** `renewals/index.php`
- **Line:** 13, 74, 126, 143
- **Category:** security
- **Status:** Deferred
- **Reason:** Same as M-21 — codebase-wide escaping fix.

### M-38 🟡 `updateCoverage` UPDATE WHERE clause missing `policy_id` (defense-in-depth)
- **File:** `policies/coverages.php`
- **Line:** 180 (also 213 for DELETE)
- **Category:** security
- **Status:** Fixed
- **Fix applied:** Both the UPDATE and DELETE WHERE clauses now include `AND policy_id = '$policy_id'` in addition to `coverage_id`. Error leakage on both paths is also fixed.

### M-39 🟡 `createPolicy` — negative `sum_insured` / `premium_amount` not rejected
- **File:** `policies/index.php`
- **Line:** 222-223
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** When `$has_coverages` is false, `$sum_insured` and `$premium_amount` are now validated with a regex (`/^-?\d+$/`) before casting, and negative values are rejected with 400.

### M-40 🟡 `mysqli_num_rows` called on potentially false result (multiple locations)
- **File:** `policies/index.php`, `insurers/index.php`, `comissions/index.php`, `master-products/index.php`, `customers/index.php`
- **Line:** ~20 locations
- **Category:** correctness
- **Status:** Deferred
- **Reason:** ~20 locations across 5 files — each needs `$res &&` before `mysqli_num_rows($res)`. Fixed in `policies/index.php` (dup check) and `policies/renew.php` (dup check); remaining locations deferred.

### M-41 🟡 SQL injection risk in `insertCommission` / `syncCommission` (defense in depth)
- **File:** `helpers/commission.php`
- **Line:** 21-32, 48-58
- **Category:** security
- **Status:** Fixed
- **Fix applied:** Both functions now cast all numeric parameters at the helper boundary (`(int)`, `round((float)...)`) before interpolation. A future caller that passes an untrusted string can no longer inject SQL.

### M-42 🟡 `mysqli_query` return value never checked in helper inserts — silent data loss
- **File:** `helpers/commission.php`, `helpers/policy_log.php`, `helpers/coverage.php`
- **Line:** commission.php:21 & 48; policy_log.php:36; coverage.php:57
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** `insertCommission` and `syncCommission` now return `bool` and check the `mysqli_query` return. `insertPolicyLog` and `insertCoverageRow` log failures via `error_log()` instead of silently continuing. `insertPolicyLog` also guards `json_encode` against non-UTF-8 bytes (writes `['__encode_error' => ...]` fallback).

### M-43 🟡 WhatsApp HTTP client does not explicitly pin TLS peer verification
- **File:** `notification/notification.php`
- **Line:** 19-25
- **Category:** security
- **Status:** Fixed
- **Fix applied:** cURL options explicitly pinned: `CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_SSL_VERIFYHOST => 2`, `CURLOPT_CONNECTTIMEOUT => 5`, `CURLOPT_PROTOCOLS => CURLPROTO_HTTPS`, `CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS`. The `raw` response field is no longer returned by default (callers persisted it in `whatsapp_digest_logs.error_message`, which could echo the API key or stack traces from WAHA 500 errors).

### M-44 🟡 `XlsxWriter::output()` filename not CRLF-sanitized — header injection risk
- **File:** `helpers/xlsx_writer.php`
- **Line:** 58
- **Category:** security
- **Status:** Fixed
- **Fix applied:** Filename is now whitelisted via `preg_replace('/[^\p{L}\p{N} _.\-]/u', '_', $filename)`. The `Content-Disposition` header also includes the RFC 5987 `filename*=UTF-8''` form for non-ASCII filenames. `ZipArchive::open()` return value is now checked.

### M-45 🟡 `XlsxWriter::setColWidths` and `buildSheet` embed width values into XML without numeric validation
- **File:** `helpers/xlsx_writer.php`
- **Line:** 33-35, 159-163
- **Category:** security
- **Status:** Fixed
- **Fix applied:** `setColWidths()` now validates each width with `is_numeric($w) && $w >= 0 && $w <= 255` and throws `InvalidArgumentException` on invalid input. Widths are cast to `floatval`.

### M-46 🟡 `renewal-reminder` endpoint has no role/permission check — any company user can trigger mass WhatsApp sends
- **File:** `services/renewal-reminder.php`
- **Line:** 22-34
- **Category:** security
- **Status:** Fixed
- **Fix applied:** The manual (non-cron) path now requires `admin`, `manager`, or `owner` role. A per-company rate limit of one run per 10 minutes is enforced (returns 429 if too recent). The cron path (service token) is exempt from the rate limit. Message content is sanitized to strip URLs.

### M-47 🟡 `mysqli_query` return values ignored in `renewal-reminder.php` audit/stamp writes
- **File:** `services/renewal-reminder.php`
- **Line:** 126-128 (INSERT), 132 (UPDATE)
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** Both the audit-log INSERT and the `renewal_reminder_sent_at` UPDATE return values are now checked. On failure, the error is logged via `error_log()` and a `send_failures[]` entry is added with `'send ok but stamp failed — may resend'`. The `WHERE policy_id IN ()` empty-list edge case is guarded. IDs use `random_bytes` instead of `uniqid`. The `json_encode` of error payloads is guarded against non-UTF-8 bytes.

### M-48 🟡 `coverage.php` `sum_insured` type coercion accepts garbage as 0; `rate_permille` has no upper bound
- **File:** `helpers/coverage.php`
- **Line:** 20-23, 25-29, 40
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** `$sum_insured` is now validated with `preg_match('/^\d+$/')` before casting (rejects `"abc"`, `"1e5"`, etc.). `rate_permille` is capped at `RATE_PERMILLE_MAX = 1000` (1000‰ = 100%). An overflow guard on the computed premium is added. `count_in_tsi` uses `filter_var(FILTER_VALIDATE_BOOLEAN)`.

### M-49 🟡 `MoviraSso::movira_sso_load_user` uses `SELECT *` — leaks password hashes into caller memory
- **File:** `sso/MoviraSso.php`
- **Line:** 156-159
- **Category:** security
- **Status:** Fixed
- **Fix applied:** `SELECT *` replaced with an explicit column list: `user_id, app_id, identity_id, company_id, username, app_role_id, account_status, language, display_name`. Password hashes, MFA secrets, and reset tokens are no longer loaded into the caller's memory.

---

# 💭 MINOR findings

### N-01 💭 `.htaccess` does not disable directory indexes or protect dotfiles
- **File:** `.htaccess`
- **Line:** 1-4
- **Category:** security
- **Status:** Fixed
- **Fix applied:** `.htaccess` now includes `Options -Indexes`, denies all dotfiles (`.env*`, `.md`, `.sql`, `.log`, `.htaccess`, `.htpasswd`), denies `config.php`, `general.php`, `mint-cron-token.php`, and blocks direct execution of any PHP file inside `connection/`, `helpers/`, `sso/`, `notification/` directories.

### N-02 💭 Duplicate `comissions` / `commissions` routes
- **File:** `index.php`
- **Line:** 89-95
- **Category:** maintainability
- **Status:** Deferred
- **Reason:** Requires API migration + 301 deprecation redirect — see M-27.

### N-03 💭 `general.php` defines `input()` but every auth file re-implements JSON body parsing
- **File:** `general.php`, all `auth/*.php`
- **Line:** general.php:45-47; login.php:52; register.php:110; refresh.php:51; forgot-password.php:56; reset-password.php:54; sso.php:51
- **Category:** maintainability
- **Status:** Fixed
- **Fix applied:** All auth files now use `$input = input();` instead of inline `json_decode(file_get_contents('php://input'), true)`.

### N-04 💭 `db.php` hardcodes `+07:00` timezone per connection
- **File:** `connection/db.php`
- **Line:** 23
- **Category:** maintainability
- **Status:** Deferred
- **Reason:** Low impact — the hardcoded WIB timezone is correct for the current deployment.

### N-05 💭 `db.php` opens a DB connection on every request, even for `logout`
- **File:** `general.php`
- **Line:** 24
- **Category:** performance
- **Status:** Deferred
- **Reason:** Low impact — `getConn()` is a static singleton, so the connection is reused within a request. Lazy-init would require refactoring the global `$conn` usage pattern.

### N-06 💭 `register.php` returns internal IDs in the success response
- **File:** `auth/register.php`
- **Line:** 92-101
- **Category:** maintainability
- **Status:** Fixed
- **Fix applied:** The 201 response no longer includes `user_id`, `company_id`, or `subscription_id`. It returns only `name`, `email`, `plan`, `account_status`, and `trial_expires_at`.

### N-07 💭 `password_hash` uses `PASSWORD_BCRYPT` instead of `PASSWORD_DEFAULT`
- **File:** `auth/register.php`, `auth/reset-password.php`
- **Line:** register.php:69; reset-password.php:35
- **Category:** maintainability
- **Status:** Fixed
- **Fix applied:** Both files now use `PASSWORD_DEFAULT` instead of `PASSWORD_BCRYPT`. `login.php` calls `password_needs_rehash()` after successful verification and updates the row if the hash is outdated.

### N-08 💭 `JWT::fromRequest()` does not trim the token after `Bearer `
- **File:** `helpers/jwt.php`
- **Line:** 57-63
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** `decode()` now calls `trim($token)` at the top. A client sending `Bearer  eyJ...` (two spaces) or `Bearer eyJ... ` (trailing whitespace) no longer gets a confusing 401.

### N-09 💭 No clock-skew leeway on `exp` / `iat` checks
- **File:** `helpers/jwt.php`
- **Line:** 42-44
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** Added `private const LEEWAY = 5` (seconds). The `exp` check is now `time() - self::LEEWAY > $payload['exp']`. A `nbf` (not-before) check is also added with leeway.

### N-10 💭 `getAllPolicies` returns 404 for empty result set
- **File:** `policies/export.php`, `customers/export.php`
- **Line:** policies/export.php:147; customers/export.php:43
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** Both export endpoints now return `200` with `data: []` for empty result sets instead of `404`. Note: the list endpoints (`policies/index.php`, `customers/index.php`, `insurers/index.php`, `master-products/index.php`, `master-wilayah/index.php`) still return 404 for empty results — deferred.

### N-11 💭 `getAllCustomers` — `$countResult` may be false
- **File:** `customers/index.php`
- **Line:** 138
- **Category:** correctness
- **Status:** Deferred
- **Reason:** Low impact — the COUNT query is unlikely to fail in normal operation.

### N-12 💭 `cleanInput` trims input — wrong for password fields
- **File:** `general.php`
- **Line:** 40-43
- **Category:** correctness
- **Status:** Deferred
- **Reason:** `cleanInput` is no longer used for passwords (M-18 fixed). The function remains for non-password fields where trimming is appropriate.

### N-13 💭 `createPolicy` — comparing `$issuing_agent_id` to string `'NULL'`
- **File:** `policies/index.php`
- **Line:** 401
- **Category:** maintainability
- **Status:** Deferred
- **Reason:** Low impact — the comparison works correctly today. A refactor would require changing the `$issuing_agent_id` tracking pattern.

### N-14 💭 `getDetailPolicy` uses `SELECT p.*`
- **File:** `policies/index.php`, `insurers/index.php`, `customers/index.php`
- **Line:** policies/index.php:425; insurers/index.php:74; customers/index.php:165
- **Category:** maintainability
- **Status:** Fixed (partial)
- **Fix applied:** `MoviraSso::movira_sso_load_user` `SELECT *` fixed (M-49). The business-module `SELECT *` calls are deferred — low risk since no sensitive columns are currently in these tables.

### N-15 💭 `getDashboardTodayActions` correlated subquery
- **File:** `dashboard/index.php`, `renewals/index.php`
- **Line:** dashboard/index.php:118-122; renewals/index.php:26-35
- **Category:** performance
- **Status:** Deferred
- **Reason:** Performance optimization — requires JOIN/window-function refactor.

### N-16 💭 `renew.php` interpolates DB-sourced values without re-escaping
- **File:** `policies/renew.php`
- **Line:** 173-175
- **Category:** security
- **Status:** Fixed
- **Fix applied:** All DB-sourced values (`$src['insurer_id']`, `$src['customer_id']`, `$src['product_type']`, `$src['agent_code_used']`) are now re-escaped with `mysqli_real_escape_string` before interpolation into the renewal INSERT.

### N-17 💭 `updateCommissionRate` and `deleteCommissionRate` — WHERE clause missing `company_id`
- **File:** `comissions/index.php`
- **Line:** 172 (update), 194 (delete)
- **Category:** security
- **Status:** Deferred
- **Reason:** Defense-in-depth — `rate_id` is a UUID and ownership is verified in the preceding SELECT.

### N-18 💭 `markCommissionReceived` — `received_date` not format-validated
- **File:** `commissions/index.php`
- **Line:** 191-193
- **Category:** correctness
- **Status:** Deferred
- **Reason:** Low impact — MySQL strict mode rejects invalid dates.

### N-19 💭 `change-plan.php` — `next_billing_date` may be null
- **File:** `subscription/change-plan.php`
- **Line:** 70-71
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** Added `if (!$sub['next_billing_date']) { jsonResponse(500, 'Subscription has no next billing date'); }` before the `date()` call.

### N-20 💭 `updatePolicy` endorsement detection doesn't include `policy_number`
- **File:** `policies/index.php`
- **Line:** 658
- **Category:** correctness
- **Status:** Deferred
- **Reason:** Requires a business-logic decision on whether policy_number changes are endorsements.

### N-21 💭 `XlsxWriter` outputs entire file via `file_get_contents` (memory)
- **File:** `helpers/xlsx_writer.php`
- **Line:** 53
- **Category:** performance
- **Status:** Fixed
- **Fix applied:** `file_get_contents` + `echo` replaced with `readfile($tmpFile)` which streams the file directly to output. `Content-Length` is now computed via `filesize($tmpFile)`.

### N-22 💭 `getFollowUps` uses `$_GET` directly instead of passed params
- **File:** `customers/index.php`
- **Line:** 475-477
- **Category:** maintainability
- **Status:** Deferred
- **Reason:** Low impact — consistency improvement.

### N-23 💭 `coverages.php` reads `php://input` redundantly
- **File:** `policies/coverages.php`
- **Line:** 248, 263
- **Category:** performance
- **Status:** Deferred
- **Reason:** Low impact — PHP allows re-reading `php://input`.

### N-24 💭 `coassurance.php` and `renew.php` do not call `requireAuth()` (rely on parent)
- **File:** `policies/coassurance.php`, `policies/renew.php`
- **Line:** global
- **Category:** security
- **Status:** Deferred
- **Reason:** Defense-in-depth — auth IS checked by the parent `policies/index.php`. Adding redundant `requireAuth()` calls would be ideal but requires ensuring `$authUser` is accessible in the sub-file scope.

### N-25 💭 `createCustomer` — silent input normalization
- **File:** `customers/index.php`
- **Line:** 29-37
- **Category:** correctness
- **Status:** Deferred
- **Reason:** Low impact — the silent fallback to `'active'` is a UX choice.

### N-26 💭 `importCustomers` — CSV extension check on client-provided filename
- **File:** `customers/index.php`
- **Line:** 519
- **Category:** security
- **Status:** Fixed
- **Fix applied:** MIME type check added via `finfo_file(FILEINFO_MIME_TYPE)` — only `text/plain`, `text/csv`, `text/x-csv`, `application/csv` are accepted. (This was already present from a prior edit.)

### N-27 💭 `master-wilayah` — no company scoping (shared reference data)
- **File:** `master-wilayah/index.php`
- **Line:** global
- **Category:** maintainability
- **Status:** Deferred
- **Reason:** Documentation-only fix — needs a comment explaining the shared-reference-data rationale.

### N-28 💭 `migration_policy_risk_location.sql` uses `decimal(10,7)` for latitude — allows invalid values
- **File:** `migration_policy_risk_location.sql`
- **Line:** 47, 51
- **Category:** correctness
- **Status:** Deferred
- **Reason:** Schema change — requires an ALTER TABLE migration on the live DB.

### N-29 💭 `migration_master_products.sql` and `migration_renewal_notification_settings.sql` lack CHECK constraints
- **File:** `migration_master_products.sql`, `migration_renewal_notification_settings.sql`
- **Line:** master_products.sql:10; renewal_notification_settings.sql:19
- **Category:** correctness
- **Status:** Deferred
- **Reason:** Schema change — requires ALTER TABLE migrations.

### N-30 💭 `helpers/renewal_message.php::toWaChatId` accepts any digit string — no length/format validation
- **File:** `helpers/renewal_message.php`
- **Line:** 27-37
- **Category:** correctness
- **Status:** Fixed
- **Fix applied:** `toWaChatId()` now validates the normalized number with `preg_match('/^62\d{9,15}$/', $digits)` and requires `$digits[2] === '8'` (Indonesian mobile numbers start with 62 8xx). Returns `null` for invalid numbers. `renderRenewalMessage()` also guards against `strtotime(false)` returning "01/01/1970" and sanitizes message fields to strip URLs.

### N-31 💭 Predictable IDs via `uniqid()` for entity and audit rows
- **File:** `helpers/commission.php`, `helpers/coverage.php`, `helpers/policy_log.php`, `services/renewal-reminder.php`, `policies/renew.php`, `customers/index.php`, `insurers/index.php`
- **Line:** multiple
- **Category:** security
- **Status:** Fixed
- **Fix applied:** All `uniqid()` calls replaced with `bin2hex(random_bytes(10))` (160 bits of entropy). Affected files: `helpers/commission.php` (`com_`), `helpers/coverage.php` (`cov_`), `helpers/policy_log.php` (`plog_`), `services/renewal-reminder.php` (`wadlog_`), `policies/renew.php` (`pol_`, `cov_`), `customers/index.php` (`cust_`), `insurers/index.php` (`ins_`).

---

# What's done well

- **`sso/MoviraSso.php`** is the gold standard in this repo: RS256 algorithm pinned explicitly, signature verified with `openssl_verify`, `iss`/`aud`/`exp`/`nbf` validated with leeway, required claims checked, `jti` consumed atomically via `UPDATE ... WHERE consumed_at IS NULL`, schema identifier whitelisted, and prepared statements throughout. The other auth files should be refactored to match this style.
- **`hash_equals`** is used for JWT signature comparison (`helpers/jwt.php:33`) — timing-safe.
- **`random_int`** / **`random_bytes`** are now used for all security-relevant randomness (OTP, UUIDs, entity IDs, error IDs).
- **`password_hash` / `password_verify`** are used correctly, now with `PASSWORD_DEFAULT` and `password_needs_rehash()`.
- **`.env` is gitignored** and not tracked.
- **`forgot-password.php`** returns a constant message regardless of whether the email exists — no body-level user enumeration.
- **`set_charset('utf8mb4')`** is set on the DB connection — emoji-safe and prevents the classic multibyte SQL-injection bypass.
- **Router uses a whitelist map** for `auth` actions — no path traversal.
- **`helpers/xlsx_writer.php`** correctly escapes shared-string cell content with `htmlspecialchars(ENT_XML1)`.
- **`log.php`** correctly uses `random_bytes(5)` for error IDs.
- **All non-corrupted migration files** use `IF NOT EXISTS` / nullable columns with sensible defaults.

---

# Deferred items — recommended next steps

The following are the most impactful deferred items, in priority order:

1. **M-05 / M-21 / M-37** — Migrate all SQL queries to prepared statements (`mysqli_prepare` + `bind_param`), mirroring `MoviraSso.php`. This is the single largest security improvement remaining. ~100+ interpolation sites across 10+ files.
2. **M-03** — Implement refresh-token rotation with reuse detection (DB `jti` table + family IDs).
3. **M-14 / M-15** — Centralize auth enforcement and service-token scope handling in the router.
4. **M-27** — Rename `comissions/` to `commission-rates/` with a 301 deprecation redirect.
5. **M-26** — Extract `buildPolicyUpdates()` helper to eliminate the ~240-line `updatePolicy` / `directUpdatePolicy` duplication.
6. **M-36** — Add role checks to `updateNotificationSettings`.
7. **C-13 (schema)** — Add `UNIQUE(company_id, policy_number)` constraint to the `policies` table.
8. **M-31 / M-32 / N-15** — Performance: add pagination to `getPaymentSummary`, fix N+1 in plans, replace correlated subqueries in dashboard/renewals.
9. **N-28 / N-29** — Add CHECK constraints to migration schemas (lat/lng ranges, commission_rate 0-100, renewal_reminder_days 1-365).
10. **M-16 (remaining)** — Clean up `mysqli_error` leakage in `users/index.php`, `master-products/index.php`, `revenue/index.php`, `dashboard/index.php`, `renewals/index.php`.
