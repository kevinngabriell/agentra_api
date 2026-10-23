# Movira SSO (this app)

This app accepts single sign-on from Movira Core in addition to its normal password login.

* Endpoint: `auth/sso.php` (route `sso` in `index.php`) — `POST {"assertion": "<from Movira Core /auth/sso/assertion>"}`; issues the same token as the password login.
* Library: `sso/MoviraSso.php` — a byte-identical copy of `movira_api/docs/sibling_sso/MoviraSso.php`. Do not edit it here; change the canonical copy and re-copy.
* `.env` keys:
  - `MOVIRA_SSO_PUBLIC_KEY_PATH` — path to Movira's SSO public key (RS256)
  - `MOVIRA_SSO_ISSUER` — issuer claim (default: `movira-core`)
  - `CORE_SCHEMA` — schema holding `app_user` / `auth_sso_jti`
  - `MOVIRA_CORE_SCHEMA` — schema holding the shared `log_request` table (same as movira_api)
* The password login is **unchanged**. It is to be removed at cutover, once this app's frontend uses "Login with Movira".
* SSO errors use the standard error envelope (`{success, data, meta, error: {code, message, http_status, details, request_id}}`) aligned with movira_api. Error codes: `ERR_SSO_001`–`ERR_SSO_006`, `ERR_AUTH_014`.
* Every request (including SSO) is logged to `MOVIRA_CORE_SCHEMA.log_request` with a server-generated `request_id` echoed in the `X-Request-ID` header and `error.request_id` field.

Design, rollout order, test results and the list of every sibling change: `movira_api/docs/SSO_Implementation_Notes.md`.
