-- Agentra role column
-- Adds a product-scoped role to app_user so one company (agent/agency) can have
-- multiple logins: the founding user ('owner'), plus invited 'admin' and
-- 'subagent' accounts, all sharing the same company_id.
--
-- IMPORTANT: this lives in the SHARED core schema (movira_core_dev), used by
-- other Movira products besides Agentra. The column name is deliberately
-- prefixed `agentra_` and is additive/nullable-safe (DEFAULT 'owner' preserves
-- current behavior for every existing user) so it does not affect any other
-- product reading app_user.
--
-- Run this against movira_core_dev (CORE_SCHEMA), NOT agentra_dev (APP_SCHEMA).

ALTER TABLE `app_user`
  ADD COLUMN `agentra_role` VARCHAR(20) NOT NULL DEFAULT 'owner'
    COMMENT 'Agentra-only role: owner | admin | subagent. Unrelated to app_role_id.'
    AFTER `app_role_id`;

-- Role meanings:
--   owner    — created the company via /auth/register. Full access. Cannot be
--              removed/demoted through the team management endpoints.
--   admin    — invited by an owner/admin. Full read/write access across the
--              company, including inviting/managing other admins and subagents.
--   subagent — invited by an owner/admin. Can read everything in the company,
--              but can only create/edit/delete policies and customers that are
--              assigned to them (policies.issuing_agent_id /
--              customers.referred_by_agent_id = their own user_id). Cannot
--              manage master data (insurers, master products, commission
--              rates) or other team members.
