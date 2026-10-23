-- =============================================================================
-- Agentra v2.0.0 production schema sync  (generated from agentra_dev, see NOTES)
-- =============================================================================
-- Brings the production app schema to what the v2.0.0 code expects. Additive:
-- nothing is dropped, renamed or re-keyed. Re-runnable, every step is guarded.
--
-- HOW TO RUN (plain GUI query editor, connected to the PRODUCTION app schema,
-- with that schema selected as the default database, run the whole file in ONE session)
--   1. Full backup of the production app schema (mysqldump).
--   2. Put the API in maintenance mode (the old v1 code must not write meanwhile).
--   3. Run this file. If ANY statement errors, STOP and read the message.
--      An error on a table named PREFLIGHT_FAILED__<check> means a data check failed
--      and NOTHING was changed. Fix the data and run again.
--   4. Check the result sets at the end: every problems value must be 0.
--   5. Merge the release PR into master. The GitHub Actions workflow deploys on every push to master
--      (rsync --delete into the app directory, then pm2 restart). There is no vendor/ and no composer step.
--      Then leave maintenance mode.
--   6. Re-run the STEP 7 verification block and smoke-test the app.
--
-- Uses DATABASE(), so no schema name is hardcoded. No DELIMITER, no procedures, no triggers.
-- =============================================================================

-- ===== STEP 0: PREFLIGHT (changes nothing) =====

-- mysql_version: needs MySQL 8.0 or newer
SET @s = IF(CAST(SUBSTRING_INDEX(VERSION(), '.', 1) AS UNSIGNED) < 8, 'SELECT 1 FROM PREFLIGHT_FAILED__mysql_version', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- wrong_database: the selected schema must be the Agentra app schema
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('policies','customers','commissions','policy_logs','insurers','master_products','policy_coverages')) <> 7, 'SELECT 1 FROM PREFLIGHT_FAILED__wrong_database', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- fk_column_types: new foreign keys point at these columns and need identical type and collation
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_type = 'varchar(50)' AND collation_name = 'utf8mb4_unicode_ci' AND ((table_name = 'policies' AND column_name = 'policy_id') OR (table_name = 'customers' AND column_name = 'customer_id') OR (table_name = 'commissions' AND column_name = 'commission_id') OR (table_name = 'insurers' AND column_name = 'insurer_id') OR (table_name = 'policy_coverages' AND column_name = 'coverage_id'))) <> 5, 'SELECT 1 FROM PREFLIGHT_FAILED__fk_column_types', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- event_type_invalid: rows holding the invalid enum value '' (event_type + 0 = 0) would stay blank, repair them first
SET @s = IF(CASE WHEN (SELECT data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_logs' AND column_name = 'event_type') = 'enum' THEN (SELECT COUNT(*) FROM policy_logs WHERE event_type + 0 = 0) ELSE 0 END > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__event_type_invalid_enum', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- event_type_null
SET @s = IF(CASE WHEN (SELECT data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_logs' AND column_name = 'event_type') = 'enum' THEN (SELECT COUNT(*) FROM policy_logs WHERE event_type IS NULL) ELSE 0 END > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__event_type_null', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- orphans: for every foreign key still to be added, rows whose parent does not exist
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'claim_events') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'claims') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'claim_events' AND constraint_name = 'fk_cev_claim' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `claim_events` c LEFT JOIN `claims` p ON p.`claim_id` = c.`claim_id` WHERE c.`claim_id` IS NOT NULL AND p.`claim_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_cev_claim', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'claims') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'customers') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'claims' AND constraint_name = 'fk_claim_customer' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `claims` c LEFT JOIN `customers` p ON p.`customer_id` = c.`customer_id` WHERE c.`customer_id` IS NOT NULL AND p.`customer_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_claim_customer', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'claims') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policies') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'claims' AND constraint_name = 'fk_claim_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `claims` c LEFT JOIN `policies` p ON p.`policy_id` = c.`policy_id` WHERE c.`policy_id` IS NOT NULL AND p.`policy_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_claim_policy', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'commission_statement_items') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'commissions') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'commission_statement_items' AND constraint_name = 'fk_csi_commission' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `commission_statement_items` c LEFT JOIN `commissions` p ON p.`commission_id` = c.`commission_id` WHERE c.`commission_id` IS NOT NULL AND p.`commission_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_csi_commission', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'commission_statement_items') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'commission_statements') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'commission_statement_items' AND constraint_name = 'fk_csi_statement' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `commission_statement_items` c LEFT JOIN `commission_statements` p ON p.`statement_id` = c.`statement_id` WHERE c.`statement_id` IS NOT NULL AND p.`statement_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_csi_statement', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'documents') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'claims') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'documents' AND constraint_name = 'fk_document_claim' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `documents` c LEFT JOIN `claims` p ON p.`claim_id` = c.`claim_id` WHERE c.`claim_id` IS NOT NULL AND p.`claim_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_document_claim', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'documents') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'customers') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'documents' AND constraint_name = 'fk_document_customer' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `documents` c LEFT JOIN `customers` p ON p.`customer_id` = c.`customer_id` WHERE c.`customer_id` IS NOT NULL AND p.`customer_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_document_customer', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'documents') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policies') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'documents' AND constraint_name = 'fk_document_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `documents` c LEFT JOIN `policies` p ON p.`policy_id` = c.`policy_id` WHERE c.`policy_id` IS NOT NULL AND p.`policy_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_document_policy', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policy_cargo_declarations') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policies') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'policy_cargo_declarations' AND constraint_name = 'fk_decl_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `policy_cargo_declarations` c LEFT JOIN `policies` p ON p.`policy_id` = c.`policy_id` WHERE c.`policy_id` IS NOT NULL AND p.`policy_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_decl_policy', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policy_installments') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policies') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'policy_installments' AND constraint_name = 'fk_installment_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `policy_installments` c LEFT JOIN `policies` p ON p.`policy_id` = c.`policy_id` WHERE c.`policy_id` IS NOT NULL AND p.`policy_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_installment_policy', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policies') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND constraint_name = 'fk_risk_details_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `policy_risk_details` c LEFT JOIN `policies` p ON p.`policy_id` = c.`policy_id` WHERE c.`policy_id` IS NOT NULL AND p.`policy_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_risk_details_policy', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policy_risk_members') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policies') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'policy_risk_members' AND constraint_name = 'fk_risk_member_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `policy_risk_members` c LEFT JOIN `policies` p ON p.`policy_id` = c.`policy_id` WHERE c.`policy_id` IS NOT NULL AND p.`policy_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_risk_member_policy', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policy_terms') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policy_coverages') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'policy_terms' AND constraint_name = 'fk_terms_coverage' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `policy_terms` c LEFT JOIN `policy_coverages` p ON p.`coverage_id` = c.`coverage_id` WHERE c.`coverage_id` IS NOT NULL AND p.`coverage_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_terms_coverage', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policy_terms') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policies') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'policy_terms' AND constraint_name = 'fk_terms_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `policy_terms` c LEFT JOIN `policies` p ON p.`policy_id` = c.`policy_id` WHERE c.`policy_id` IS NOT NULL AND p.`policy_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_terms_policy', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'quote_options') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'quotes') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'quote_options' AND constraint_name = 'fk_qopt_quote' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `quote_options` c LEFT JOIN `quotes` p ON p.`quote_id` = c.`quote_id` WHERE c.`quote_id` IS NOT NULL AND p.`quote_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_qopt_quote', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'quotes') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'prospects') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'quotes' AND constraint_name = 'fk_quote_prospect' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `quotes` c LEFT JOIN `prospects` p ON p.`prospect_id` = c.`prospect_id` WHERE c.`prospect_id` IS NOT NULL AND p.`prospect_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_quote_prospect', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'renewal_workflow_events') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'renewal_workflows') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'renewal_workflow_events' AND constraint_name = 'fk_renewal_event_workflow' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `renewal_workflow_events` c LEFT JOIN `renewal_workflows` p ON p.`workflow_id` = c.`workflow_id` WHERE c.`workflow_id` IS NOT NULL AND p.`workflow_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_renewal_event_workflow', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'renewal_workflows') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policies') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'renewal_workflows' AND constraint_name = 'fk_renewal_workflow_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `renewal_workflows` c LEFT JOIN `policies` p ON p.`policy_id` = c.`policy_id` WHERE c.`policy_id` IS NOT NULL AND p.`policy_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_renewal_workflow_policy', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sub_agent_insurer_rates') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'insurers') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'sub_agent_insurer_rates' AND constraint_name = 'fk_sair_insurer' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `sub_agent_insurer_rates` c LEFT JOIN `insurers` p ON p.`insurer_id` = c.`insurer_id` WHERE c.`insurer_id` IS NOT NULL AND p.`insurer_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_sair_insurer', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sub_agent_insurer_rates') > 0 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sub_agents') > 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'sub_agent_insurer_rates' AND constraint_name = 'fk_sair_sub_agent' AND constraint_type = 'FOREIGN KEY') = 0, 'SELECT COUNT(*) INTO @orph FROM `sub_agent_insurer_rates` c LEFT JOIN `sub_agents` p ON p.`sub_agent_id` = c.`sub_agent_id` WHERE c.`sub_agent_id` IS NOT NULL AND p.`sub_agent_id` IS NULL', 'SET @orph = 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@orph > 0, 'SELECT 1 FROM PREFLIGHT_FAILED__orphans_fk_sair_sub_agent', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- row counts before, compared again in the final verification
SET @b_activity_logs = (SELECT COUNT(*) FROM `activity_logs`);
SET @b_agent_profiles = (SELECT COUNT(*) FROM `agent_profiles`);
SET @b_commissions = (SELECT COUNT(*) FROM `commissions`);
SET @b_customers = (SELECT COUNT(*) FROM `customers`);
SET @b_follow_up_logs = (SELECT COUNT(*) FROM `follow_up_logs`);
SET @b_import_jobs = (SELECT COUNT(*) FROM `import_jobs`);
SET @b_in_app_notifications = (SELECT COUNT(*) FROM `in_app_notifications`);
SET @b_insurer_commission_rates = (SELECT COUNT(*) FROM `insurer_commission_rates`);
SET @b_insurers = (SELECT COUNT(*) FROM `insurers`);
SET @b_master_products = (SELECT COUNT(*) FROM `master_products`);
SET @b_notification_settings = (SELECT COUNT(*) FROM `notification_settings`);
SET @b_policies = (SELECT COUNT(*) FROM `policies`);
SET @b_policy_coassurance = (SELECT COUNT(*) FROM `policy_coassurance`);
SET @b_policy_coverages = (SELECT COUNT(*) FROM `policy_coverages`);
SET @b_policy_documents = (SELECT COUNT(*) FROM `policy_documents`);
SET @b_policy_logs = (SELECT COUNT(*) FROM `policy_logs`);
SET @b_policy_payment_confirmations = (SELECT COUNT(*) FROM `policy_payment_confirmations`);
SET @b_whatsapp_digest_logs = (SELECT COUNT(*) FROM `whatsapp_digest_logs`);

-- ===== STEP 1: ADD COLUMNS (nullable or defaulted, old code keeps working) =====
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'commissions' AND column_name = 'sub_agent_id') = 0, 'ALTER TABLE `commissions` ADD COLUMN `sub_agent_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Sub-agent who issued the policy, snapshot'' AFTER `updated_at`', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'commissions' AND column_name = 'sub_agent_split_rate') = 0, 'ALTER TABLE `commissions` ADD COLUMN `sub_agent_split_rate` decimal(5,2) DEFAULT NULL COMMENT ''% of expected_amount passed to the sub-agent, snapshot'' AFTER `sub_agent_id`', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'commissions' AND column_name = 'sub_agent_amount') = 0, 'ALTER TABLE `commissions` ADD COLUMN `sub_agent_amount` bigint NOT NULL DEFAULT ''0'' COMMENT ''IDR owed to the sub-agent: expected_amount x split rate'' AFTER `sub_agent_split_rate`', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'group_id') = 0, 'ALTER TABLE `customers` ADD COLUMN `group_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''customer_groups.group_id'' AFTER `referred_by_agent_id`', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'master_products' AND column_name = 'product_type') = 0, 'ALTER TABLE `master_products` ADD COLUMN `product_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Fixed insurance line: fire, earthquake, motor, cargo, travel, personal_accident, liability, engineering, marine_hull, health, bonds, others'' AFTER `product_name`', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'payment_due_date') = 0, 'ALTER TABLE `policies` ADD COLUMN `payment_due_date` date DEFAULT NULL COMMENT ''Premium due date (premium payment warranty). NULL = coverage_start + 30 days.'' AFTER `updated_at`', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'payment_date') = 0, 'ALTER TABLE `policies` ADD COLUMN `payment_date` date DEFAULT NULL COMMENT ''Date the premium was received'' AFTER `payment_due_date`', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'payment_reference') = 0, 'ALTER TABLE `policies` ADD COLUMN `payment_reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Transfer reference number'' AFTER `payment_date`', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ===== STEP 2: ADD INDEXES =====
SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'commissions' AND index_name = 'idx_commission_sub_agent') = 0, 'ALTER TABLE `commissions` ADD KEY `idx_commission_sub_agent` (`sub_agent_id`)', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'customers' AND index_name = 'idx_customer_group') = 0, 'ALTER TABLE `customers` ADD KEY `idx_customer_group` (`group_id`)', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'master_products' AND index_name = 'idx_master_products_type') = 0, 'ALTER TABLE `master_products` ADD KEY `idx_master_products_type` (`company_id`,`product_type`)', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ===== STEP 3: WIDEN policy_logs.event_type (ENUM to VARCHAR(50), every existing value is kept) =====
SET @s = IF((SELECT 1 - COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_logs' AND column_name = 'event_type' AND data_type = 'enum') = 0, 'ALTER TABLE `policy_logs` MODIFY COLUMN `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ===== STEP 4: CREATE NEW TABLES (DDL taken from agentra_dev, foreign keys are added in step 6) =====
CREATE TABLE IF NOT EXISTS `claim_events` (
  `event_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `claim_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` enum('registered','status_changed','followup','note','updated','document_uploaded','document_deleted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `channel` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'followup only: whatsapp | phone | email | in_person | insurer',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `event_date` date NOT NULL COMMENT 'Date the event happened (follow-ups can be back-dated)',
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `idx_cev_claim` (`claim_id`,`event_date`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Claim timeline and follow-up log. Append-only.';
CREATE TABLE IF NOT EXISTS `claims` (
  `claim_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FK -> movira_core.app_company.company_id (tenant scope)',
  `claim_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Internal number, CLM-{year}-{seq}. Unique per company.',
  `insurer_claim_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Reference number issued by the insurer',
  `policy_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FK -> policies.policy_id',
  `customer_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FK -> customers.customer_id. Derived from the policy.',
  `date_of_loss` date NOT NULL COMMENT 'Must fall inside the policy coverage period',
  `reported_date` date NOT NULL COMMENT 'Date the claim was reported to the insurer',
  `cause` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Cause of loss, e.g. Tabrakan, Kebakaran, Pencurian',
  `loss_location` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `estimated_loss` bigint NOT NULL DEFAULT '0' COMMENT 'IDR, the customer''s / agent''s estimate',
  `deductible` bigint NOT NULL DEFAULT '0' COMMENT 'IDR, risiko sendiri borne by the customer',
  `approved_amount` bigint DEFAULT NULL COMMENT 'IDR, set when approved',
  `settlement_amount` bigint DEFAULT NULL COMMENT 'IDR actually paid by the insurer, set when paid',
  `payment_date` date DEFAULT NULL,
  `status` enum('reported','survey','documents_complete','approved','rejected','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'reported',
  `status_changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `surveyor_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Surveyor / loss adjuster',
  `survey_date` date DEFAULT NULL,
  `rejection_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FK -> movira_core.app_user.user_id',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`claim_id`),
  UNIQUE KEY `uq_claim_number_company` (`company_id`,`claim_number`),
  KEY `idx_claim_company_status` (`company_id`,`is_deleted`,`status`),
  KEY `idx_claim_policy` (`policy_id`),
  KEY `idx_claim_customer` (`customer_id`),
  KEY `idx_claim_date_of_loss` (`date_of_loss`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Insurance claims registered against policies.';
CREATE TABLE IF NOT EXISTS `commission_statement_items` (
  `statement_id`     varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `commission_id`    varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `allocated_amount` bigint      NOT NULL COMMENT 'IDR the insurer paid for this commission',
  `created_at`       datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`statement_id`, `commission_id`),
  UNIQUE KEY `uq_statement_item_commission` (`commission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `commission_statements` (
  `statement_id`     varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id`       varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `insurer_id`       varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `statement_date`   date         NOT NULL,
  `amount`           bigint       NOT NULL COMMENT 'IDR paid by the insurer in this statement',
  `reference_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes`            text         COLLATE utf8mb4_unicode_ci,
  `created_by`       varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`statement_id`),
  KEY `idx_statement_company_date` (`company_id`, `statement_date`),
  KEY `idx_statement_insurer` (`insurer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `customer_groups` (
  `group_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`group_id`),
  UNIQUE KEY `uq_customer_group_name` (`company_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `documents` (
  `document_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FK -> movira_core.app_company.company_id (tenant scope)',
  `customer_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FK -> customers.customer_id. Always set, derived from the policy when policy_id is given.',
  `policy_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'FK -> policies.policy_id. NULL = customer-level document (e.g. KYC).',
  `claim_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Reserved for the claims module (no FK yet).',
  `claim_requirement` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Checklist key from helpers/claims.php (e.g. stnk). NULL = additional claim document.',
  `document_type` enum('policy','endorsement','sppa','ktp','npwp','nib','akta_pendirian','survey_photo','claim','payment_proof','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional label, UI falls back to file_name.',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `document_year` smallint NOT NULL COMMENT 'Year used for filtering: policy coverage_start year, else upload year, unless overridden.',
  `file_key` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'S3 object key (not a URL).',
  `file_name` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Original file name as uploaded.',
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'application/pdf | image/jpeg | image/png',
  `file_size` int unsigned NOT NULL COMMENT 'Bytes',
  `status` enum('active','archived') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `archived_at` datetime DEFAULT NULL,
  `archived_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `archive_reason` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Soft delete - rows and objects are never removed.',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FK -> movira_core.app_user.user_id',
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`document_id`),
  KEY `idx_doc_company_status` (`company_id`,`is_deleted`,`status`),
  KEY `idx_doc_customer` (`customer_id`),
  KEY `idx_doc_policy` (`policy_id`),
  KEY `idx_doc_claim` (`claim_id`),
  KEY `idx_doc_type` (`document_type`),
  KEY `idx_doc_year` (`document_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Uploaded documents (policy, KYC, survey, claim). Stored on Cloudeka S3. Soft delete + archive only.';
CREATE TABLE IF NOT EXISTS `message_broadcasts` (
  `broadcast_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `filters` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'JSON: product_type, insurer_id, city, customer_type',
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_count` int NOT NULL DEFAULT '0',
  `sent_count` int NOT NULL DEFAULT '0',
  `failed_count` int NOT NULL DEFAULT '0',
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`broadcast_id`),
  KEY `idx_message_broadcast_company` (`company_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `message_log` (
  `log_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `policy_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `template_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `broadcast_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_type` enum('renewal','invoice','payment_reminder','birthday','holiday','broadcast','custom') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `trigger_source` enum('manual','broadcast','auto') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `recipient` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'WhatsApp chat id',
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('sent','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `error` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sent_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'NULL for the cron',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_message_log_customer` (`company_id`,`customer_id`,`created_at`),
  KEY `idx_message_log_policy` (`policy_id`,`created_at`),
  KEY `idx_message_log_template_customer` (`template_id`,`customer_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `message_templates` (
  `template_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_type` enum('invoice','payment_reminder','birthday','holiday','broadcast','custom') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `send_on_date` date DEFAULT NULL COMMENT 'holiday only: the day it goes out',
  `repeat_yearly` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'holiday only: match month and day every year',
  `auto_send` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'birthday / holiday: sent by the greetings cron',
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`),
  KEY `idx_message_template_company` (`company_id`,`template_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `notifications` (
  `notification_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('expiring_today','expiring_week','policy_lapsed','premium_overdue','commission_received','claim_status') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `link` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'App path of the record, e.g. /agentra/policies/{id}',
  `dedupe_key` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`),
  UNIQUE KEY `uq_notification_dedupe` (`company_id`,`dedupe_key`),
  KEY `idx_notification_feed` (`company_id`,`read_at`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `payment_webhook_event` (
  `id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `event_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `order_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `received_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `policy_cargo_declarations` (
  `declaration_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `policy_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `declaration_date` date NOT NULL,
  `conveyance_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `voyage_from` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `voyage_to` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bl_awb_number` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `departure_date` date DEFAULT NULL,
  `goods_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sum_insured` bigint NOT NULL DEFAULT '0' COMMENT 'IDR, this shipment',
  `premium_amount` bigint NOT NULL DEFAULT '0' COMMENT 'IDR, this shipment',
  `notes` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`declaration_id`),
  KEY `idx_decl_policy_date` (`policy_id`,`declaration_date`),
  KEY `idx_decl_company_bl` (`company_id`,`bl_awb_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `policy_installments` (
  `installment_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `policy_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `seq` smallint NOT NULL COMMENT '1-based order within the policy',
  `due_date` date NOT NULL,
  `amount` bigint NOT NULL COMMENT 'IDR',
  `paid_date` date DEFAULT NULL,
  `payment_reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`installment_id`),
  UNIQUE KEY `uq_installment_seq` (`policy_id`,`seq`),
  KEY `idx_installment_company_due` (`company_id`,`paid_date`,`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `policy_risk_details` (
  `policy_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `plate_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Upper-case as entered, e.g. "B 1234 XYZ"',
  `chassis_number` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'No. rangka (VIN)',
  `engine_number` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'No. mesin',
  `vehicle_make` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vehicle_model` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vehicle_year` smallint DEFAULT NULL,
  `vehicle_color` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vehicle_usage` enum('private','commercial') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `motor_coverage_type` enum('comprehensive','tlo') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Comprehensive = all risks, TLO = total loss only',
  `vehicle_value` bigint DEFAULT NULL COMMENT 'IDR',
  `tariff_region` tinyint DEFAULT NULL COMMENT 'OJK motor tariff region, 1-3',
  `ext_flood` tinyint(1) NOT NULL DEFAULT '0',
  `ext_earthquake` tinyint(1) NOT NULL DEFAULT '0',
  `ext_rsmdcc` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Riot, strike, malicious damage, civil commotion',
  `ext_terrorism` tinyint(1) NOT NULL DEFAULT '0',
  `tpl_limit` bigint DEFAULT NULL COMMENT 'Third-party liability limit',
  `pa_driver_limit` bigint DEFAULT NULL COMMENT 'Personal accident, driver',
  `pa_passenger_limit` bigint DEFAULT NULL COMMENT 'Personal accident, per passenger',
  `pa_passenger_seats` tinyint DEFAULT NULL,
  `conveyance_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Vessel / vehicle / flight',
  `voyage_from` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `voyage_to` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bl_awb_number` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Bill of lading / air waybill',
  `departure_date` date DEFAULT NULL,
  `icc_clause` enum('A','B','C') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Institute Cargo Clauses',
  `goods_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_open_cover` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = master policy with per-shipment declarations',
  `destinations` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Comma-separated, the trip dates are the policy coverage dates',
  `schengen_certificate_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Set = Schengen certificate issued',
  `occupation_code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occupation_description` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `survey_status` enum('not_required','pending','scheduled','done') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `survey_date` date DEFAULT NULL,
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`policy_id`),
  KEY `idx_risk_company_plate` (`company_id`,`plate_number`),
  KEY `idx_risk_company_chassis` (`company_id`,`chassis_number`),
  KEY `idx_risk_company_bl` (`company_id`,`bl_awb_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `policy_risk_members` (
  `member_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `policy_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `member_role` enum('traveller','insured','beneficiary') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_number` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'KTP or passport number',
  `birth_date` date DEFAULT NULL,
  `relationship` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Relation to the policyholder, e.g. spouse, child',
  `benefit_death` bigint DEFAULT NULL COMMENT 'IDR, insured / traveller only',
  `benefit_disability` bigint DEFAULT NULL COMMENT 'IDR, insured / traveller only',
  `benefit_medical` bigint DEFAULT NULL COMMENT 'IDR, insured / traveller only',
  `share_percent` decimal(5,2) DEFAULT NULL COMMENT 'Beneficiary share of the death benefit',
  `sort_order` smallint NOT NULL DEFAULT '0',
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`member_id`),
  KEY `idx_risk_member_policy` (`policy_id`,`member_role`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `policy_terms` (
  `term_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `policy_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `coverage_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `term_type` enum('deductible','clause','extension') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `clause_code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Catalog code when picked from the list, NULL for free text',
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `deductible_type` enum('fixed','percent_claim','percent_sum_insured') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deductible_amount` bigint DEFAULT NULL COMMENT 'IDR: the fixed amount, or the minimum for a percent deductible',
  `deductible_percent` decimal(6,3) DEFAULT NULL,
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`term_id`),
  KEY `idx_terms_policy` (`policy_id`,`term_type`),
  KEY `idx_terms_coverage` (`coverage_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `prospects` (
  `prospect_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `prospect_type` enum('lead','competitor_policy') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'lead',
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Contact person, for companies',
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `whatsapp` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` enum('direct','referral','website','event','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `referred_by_agent_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'sub_agents.sub_agent_id',
  `product_interest` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Product code, same values as policies.product_type',
  `expected_premium` bigint NOT NULL DEFAULT '0' COMMENT 'IDR',
  `stage` enum('new','contacted','quoted','negotiating','won','lost') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `lost_reason` enum('price','competitor','no_longer_needed','no_response','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lost_note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stage_changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `follow_up_date` date DEFAULT NULL,
  `competitor_insurer` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `competitor_policy_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `competitor_expiry` date DEFAULT NULL,
  `competitor_premium` bigint DEFAULT NULL COMMENT 'IDR, their current premium',
  `customer_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Set when converted to (or matched with) a customer',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`prospect_id`),
  KEY `idx_prospect_company_stage` (`company_id`,`is_deleted`,`stage`),
  KEY `idx_prospect_expiry` (`company_id`,`competitor_expiry`),
  KEY `idx_prospect_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `quote_options` (
  `option_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quote_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `insurer_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rate_permille` decimal(8,4) DEFAULT NULL COMMENT 'Per-mille rate, NULL when the premium was typed in directly',
  `premium_amount` bigint NOT NULL DEFAULT '0' COMMENT 'sum_insured * rate_permille / 1000 unless entered directly',
  `biaya_polis` bigint NOT NULL DEFAULT '0',
  `materai_amount` bigint NOT NULL DEFAULT '0',
  `deductible_note` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Risiko sendiri in words',
  `terms_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Clauses, extensions and conditions in words',
  `sort_order` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`option_id`),
  KEY `idx_qopt_quote` (`quote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `quotes` (
  `quote_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quote_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'QT-{year}-{seq}. Unique per company.',
  `prospect_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'What is insured, in words',
  `sum_insured` bigint NOT NULL DEFAULT '0',
  `coverage_start` date DEFAULT NULL,
  `coverage_end` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `status` enum('draft','sent','accepted','declined') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `sent_at` datetime DEFAULT NULL,
  `accepted_option_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `policy_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Set when the accepted quote was issued as a policy',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`quote_id`),
  UNIQUE KEY `uq_quote_number_company` (`company_id`,`quote_number`),
  KEY `idx_quote_prospect` (`prospect_id`),
  KEY `idx_quote_company_status` (`company_id`,`is_deleted`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `renewal_workflow_events` (
  `event_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `workflow_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_stage` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_stage` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `idx_renewal_event_workflow` (`workflow_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `renewal_workflows` (
  `workflow_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `policy_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The expiring policy',
  `stage` enum('not_started','terms_requested','terms_received','offered','accepted','declined','issued') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `stage_changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `insurer_reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Insurer quote / offer number',
  `new_sum_insured` bigint DEFAULT NULL COMMENT 'IDR, proposed for the new term',
  `new_premium` bigint DEFAULT NULL COMMENT 'IDR, proposed for the new term',
  `new_commission_rate` decimal(5,2) DEFAULT NULL COMMENT '%, proposed for the new term',
  `new_terms_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Changes in terms, clauses or deductibles',
  `sum_insured_reviewed` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'The agent has reviewed the sum insured with the customer',
  `review_note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lost_reason` enum('price','competitor','service','no_longer_needed','asset_sold','no_response','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lost_note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `renewed_policy_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'The policy issued for the new term',
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`workflow_id`),
  UNIQUE KEY `uq_renewal_workflow_policy` (`policy_id`),
  KEY `idx_renewal_workflow_company_stage` (`company_id`,`stage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `sub_agent_insurer_rates` (
  `sub_agent_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `insurer_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `split_rate` decimal(5,2) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sub_agent_id`,`insurer_id`),
  KEY `fk_sair_insurer` (`insurer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `sub_agents` (
  `sub_agent_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FK -> app_company.company_id (tenant scope)',
  `agent_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Unique per company',
  `full_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `whatsapp_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `joined_date` date DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `default_split_rate` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT '% of the gross commission paid to the sub-agent',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `user_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Login: movira_core.app_user.user_id',
  `wa_digest_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Renewal digest goes to the sub-agent own WhatsApp',
  PRIMARY KEY (`sub_agent_id`),
  UNIQUE KEY `uq_sub_agent_code` (`company_id`,`agent_code`),
  UNIQUE KEY `uq_sub_agent_user` (`user_id`),
  KEY `idx_sub_agent_company_status` (`company_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `subscription_payment` (
  `id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `company_id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `service_id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `subscription_id` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `order_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `gateway_payment_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payment_link_url` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `amount` bigint NOT NULL,
  `fee` bigint DEFAULT NULL,
  `net_amount` bigint DEFAULT NULL,
  `billing_cycle` enum('monthly','yearly') COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('pending','completed','failed','expired') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `pending_company_key` varchar(50) COLLATE utf8mb4_general_ci GENERATED ALWAYS AS (if((`status` = _utf8mb4'pending'),`company_id`,NULL)) STORED,
  `expires_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `created_by` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subscription_payment_order` (`order_id`),
  UNIQUE KEY `uq_subscription_payment_pending` (`pending_company_key`),
  KEY `idx_company_created` (`company_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `tasks` (
  `task_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `due_date` date NOT NULL,
  `status` enum('open','done') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `completed_at` datetime DEFAULT NULL,
  `customer_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `policy_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claim_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`task_id`),
  KEY `idx_task_company_due` (`company_id`,`status`,`due_date`),
  KEY `idx_task_customer` (`customer_id`),
  KEY `idx_task_policy` (`policy_id`),
  KEY `idx_task_claim` (`claim_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== STEP 5: BACKFILL =====
-- master_products.product_type: classification from the repo mapping (migration_product_types.sql), only rows still NULL
UPDATE `master_products` SET `product_type` = CASE
  WHEN LOWER(product_code) IN ('fire','kebakaran','01','08','61','62','88','881','par 63') OR LOWER(product_name) LIKE '%kebakaran%' OR LOWER(product_name) LIKE 'par %' OR LOWER(product_name) LIKE '%rumah tinggal%' THEN 'fire'
  WHEN LOWER(product_name) LIKE '%gempa%' THEN 'earthquake'
  WHEN LOWER(product_code) IN ('car','motorcycle','motor','mobil','02') OR LOWER(product_name) LIKE '%mobil%' OR LOWER(product_name) LIKE '%kendaraan%' THEN 'motor'
  WHEN LOWER(product_code) IN ('cargo','kargo') OR LOWER(product_name) LIKE '%cargo%' THEN 'cargo'
  WHEN LOWER(product_code) IN ('travel','perjalanan') OR LOWER(product_name) LIKE '%travel%' THEN 'travel'
  WHEN LOWER(product_code) IN ('kecelakaan','personal accident') OR LOWER(product_name) LIKE '%personal accident%' OR LOWER(product_name) LIKE '%kecelakaan%' THEN 'personal_accident'
  WHEN LOWER(product_code) = 'aep' OR LOWER(product_name) LIKE '%tanggung gugat%' THEN 'liability'
  ELSE NULL END
WHERE `product_type` IS NULL;
-- nothing else needs a backfill: new columns are NULL or 0 by design, no table changed shape, no dev rows are copied (see NOTES)

-- ===== STEP 6: FOREIGN KEYS (after the backfill, checks stay ON so MySQL validates every row) =====
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'claim_events' AND constraint_name = 'fk_cev_claim' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `claim_events` ADD CONSTRAINT `fk_cev_claim` FOREIGN KEY (`claim_id`) REFERENCES `claims` (`claim_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'claims' AND constraint_name = 'fk_claim_customer' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `claims` ADD CONSTRAINT `fk_claim_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'claims' AND constraint_name = 'fk_claim_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `claims` ADD CONSTRAINT `fk_claim_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'commission_statement_items' AND constraint_name = 'fk_csi_commission' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `commission_statement_items` ADD CONSTRAINT `fk_csi_commission` FOREIGN KEY (`commission_id`) REFERENCES `commissions` (`commission_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'commission_statement_items' AND constraint_name = 'fk_csi_statement' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `commission_statement_items` ADD CONSTRAINT `fk_csi_statement` FOREIGN KEY (`statement_id`) REFERENCES `commission_statements` (`statement_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'documents' AND constraint_name = 'fk_document_claim' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `documents` ADD CONSTRAINT `fk_document_claim` FOREIGN KEY (`claim_id`) REFERENCES `claims` (`claim_id`) ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'documents' AND constraint_name = 'fk_document_customer' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `documents` ADD CONSTRAINT `fk_document_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'documents' AND constraint_name = 'fk_document_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `documents` ADD CONSTRAINT `fk_document_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'policy_cargo_declarations' AND constraint_name = 'fk_decl_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `policy_cargo_declarations` ADD CONSTRAINT `fk_decl_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'policy_installments' AND constraint_name = 'fk_installment_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `policy_installments` ADD CONSTRAINT `fk_installment_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND constraint_name = 'fk_risk_details_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `policy_risk_details` ADD CONSTRAINT `fk_risk_details_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'policy_risk_members' AND constraint_name = 'fk_risk_member_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `policy_risk_members` ADD CONSTRAINT `fk_risk_member_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'policy_terms' AND constraint_name = 'fk_terms_coverage' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `policy_terms` ADD CONSTRAINT `fk_terms_coverage` FOREIGN KEY (`coverage_id`) REFERENCES `policy_coverages` (`coverage_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'policy_terms' AND constraint_name = 'fk_terms_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `policy_terms` ADD CONSTRAINT `fk_terms_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'quote_options' AND constraint_name = 'fk_qopt_quote' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `quote_options` ADD CONSTRAINT `fk_qopt_quote` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`quote_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'quotes' AND constraint_name = 'fk_quote_prospect' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `quotes` ADD CONSTRAINT `fk_quote_prospect` FOREIGN KEY (`prospect_id`) REFERENCES `prospects` (`prospect_id`) ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'renewal_workflow_events' AND constraint_name = 'fk_renewal_event_workflow' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `renewal_workflow_events` ADD CONSTRAINT `fk_renewal_event_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `renewal_workflows` (`workflow_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'renewal_workflows' AND constraint_name = 'fk_renewal_workflow_policy' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `renewal_workflows` ADD CONSTRAINT `fk_renewal_workflow_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'sub_agent_insurer_rates' AND constraint_name = 'fk_sair_insurer' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `sub_agent_insurer_rates` ADD CONSTRAINT `fk_sair_insurer` FOREIGN KEY (`insurer_id`) REFERENCES `insurers` (`insurer_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'sub_agent_insurer_rates' AND constraint_name = 'fk_sair_sub_agent' AND constraint_type = 'FOREIGN KEY') = 0, 'ALTER TABLE `sub_agent_insurer_rates` ADD CONSTRAINT `fk_sair_sub_agent` FOREIGN KEY (`sub_agent_id`) REFERENCES `sub_agents` (`sub_agent_id`) ON DELETE CASCADE ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ===== STEP 7: VERIFICATION (every problems value must be 0) =====
SELECT 'missing_tables' AS check_name, 25 - COUNT(*) AS problems FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('claim_events','claims','commission_statement_items','commission_statements','customer_groups','documents','message_broadcasts','message_log','message_templates','notifications','payment_webhook_event','policy_cargo_declarations','policy_installments','policy_risk_details','policy_risk_members','policy_terms','prospects','quote_options','quotes','renewal_workflow_events','renewal_workflows','sub_agent_insurer_rates','sub_agents','subscription_payment','tasks');
SELECT 'missing_columns' AS check_name, 8 - COUNT(*) AS problems FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'commissions' AND column_name = 'sub_agent_id') OR (table_name = 'commissions' AND column_name = 'sub_agent_split_rate') OR (table_name = 'commissions' AND column_name = 'sub_agent_amount') OR (table_name = 'customers' AND column_name = 'group_id') OR (table_name = 'master_products' AND column_name = 'product_type') OR (table_name = 'policies' AND column_name = 'payment_due_date') OR (table_name = 'policies' AND column_name = 'payment_date') OR (table_name = 'policies' AND column_name = 'payment_reference'));
SELECT 'missing_indexes' AS check_name, 3 - COUNT(DISTINCT table_name, index_name) AS problems FROM information_schema.statistics WHERE table_schema = DATABASE() AND ((table_name = 'commissions' AND index_name = 'idx_commission_sub_agent') OR (table_name = 'customers' AND index_name = 'idx_customer_group') OR (table_name = 'master_products' AND index_name = 'idx_master_products_type'));
SELECT 'missing_foreign_keys' AS check_name, 20 - COUNT(*) AS problems FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND constraint_type = 'FOREIGN KEY' AND constraint_name IN ('fk_cev_claim','fk_claim_customer','fk_claim_policy','fk_csi_commission','fk_csi_statement','fk_document_claim','fk_document_customer','fk_document_policy','fk_decl_policy','fk_installment_policy','fk_risk_details_policy','fk_risk_member_policy','fk_terms_coverage','fk_terms_policy','fk_qopt_quote','fk_quote_prospect','fk_renewal_event_workflow','fk_renewal_workflow_policy','fk_sair_insurer','fk_sair_sub_agent');
SELECT 'event_type_not_varchar' AS check_name, 1 - COUNT(*) AS problems FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_logs' AND column_name = 'event_type' AND data_type = 'varchar' AND character_maximum_length = 50 AND is_nullable = 'NO';
SELECT 'event_type_blank' AS check_name, COUNT(*) AS problems FROM policy_logs WHERE event_type = '';
SELECT 'lost_rows' AS check_name, (CASE WHEN (SELECT COUNT(*) FROM `activity_logs`) < @b_activity_logs THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `agent_profiles`) < @b_agent_profiles THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `commissions`) < @b_commissions THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `customers`) < @b_customers THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `follow_up_logs`) < @b_follow_up_logs THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `import_jobs`) < @b_import_jobs THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `in_app_notifications`) < @b_in_app_notifications THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `insurer_commission_rates`) < @b_insurer_commission_rates THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `insurers`) < @b_insurers THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `master_products`) < @b_master_products THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `notification_settings`) < @b_notification_settings THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `policies`) < @b_policies THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `policy_coassurance`) < @b_policy_coassurance THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `policy_coverages`) < @b_policy_coverages THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `policy_documents`) < @b_policy_documents THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `policy_logs`) < @b_policy_logs THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `policy_payment_confirmations`) < @b_policy_payment_confirmations THEN 1 ELSE 0 END) + (CASE WHEN (SELECT COUNT(*) FROM `whatsapp_digest_logs`) < @b_whatsapp_digest_logs THEN 1 ELSE 0 END) AS problems;
SELECT 'sub_agent_amount_not_zero' AS check_name, COUNT(*) AS problems FROM commissions WHERE sub_agent_id IS NULL AND sub_agent_amount <> 0;
SELECT 'product_type_unclassified_info' AS check_name, COUNT(*) AS expected_to_review FROM master_products WHERE product_type IS NULL;
-- row counts before and after
SELECT 'activity_logs' AS table_name, @b_activity_logs AS rows_before, (SELECT COUNT(*) FROM `activity_logs`) AS rows_after UNION ALL SELECT 'agent_profiles' AS table_name, @b_agent_profiles AS rows_before, (SELECT COUNT(*) FROM `agent_profiles`) AS rows_after UNION ALL SELECT 'commissions' AS table_name, @b_commissions AS rows_before, (SELECT COUNT(*) FROM `commissions`) AS rows_after UNION ALL SELECT 'customers' AS table_name, @b_customers AS rows_before, (SELECT COUNT(*) FROM `customers`) AS rows_after UNION ALL SELECT 'follow_up_logs' AS table_name, @b_follow_up_logs AS rows_before, (SELECT COUNT(*) FROM `follow_up_logs`) AS rows_after UNION ALL SELECT 'import_jobs' AS table_name, @b_import_jobs AS rows_before, (SELECT COUNT(*) FROM `import_jobs`) AS rows_after UNION ALL SELECT 'in_app_notifications' AS table_name, @b_in_app_notifications AS rows_before, (SELECT COUNT(*) FROM `in_app_notifications`) AS rows_after UNION ALL SELECT 'insurer_commission_rates' AS table_name, @b_insurer_commission_rates AS rows_before, (SELECT COUNT(*) FROM `insurer_commission_rates`) AS rows_after UNION ALL SELECT 'insurers' AS table_name, @b_insurers AS rows_before, (SELECT COUNT(*) FROM `insurers`) AS rows_after UNION ALL SELECT 'master_products' AS table_name, @b_master_products AS rows_before, (SELECT COUNT(*) FROM `master_products`) AS rows_after UNION ALL SELECT 'notification_settings' AS table_name, @b_notification_settings AS rows_before, (SELECT COUNT(*) FROM `notification_settings`) AS rows_after UNION ALL SELECT 'policies' AS table_name, @b_policies AS rows_before, (SELECT COUNT(*) FROM `policies`) AS rows_after UNION ALL SELECT 'policy_coassurance' AS table_name, @b_policy_coassurance AS rows_before, (SELECT COUNT(*) FROM `policy_coassurance`) AS rows_after UNION ALL SELECT 'policy_coverages' AS table_name, @b_policy_coverages AS rows_before, (SELECT COUNT(*) FROM `policy_coverages`) AS rows_after UNION ALL SELECT 'policy_documents' AS table_name, @b_policy_documents AS rows_before, (SELECT COUNT(*) FROM `policy_documents`) AS rows_after UNION ALL SELECT 'policy_logs' AS table_name, @b_policy_logs AS rows_before, (SELECT COUNT(*) FROM `policy_logs`) AS rows_after UNION ALL SELECT 'policy_payment_confirmations' AS table_name, @b_policy_payment_confirmations AS rows_before, (SELECT COUNT(*) FROM `policy_payment_confirmations`) AS rows_after UNION ALL SELECT 'whatsapp_digest_logs' AS table_name, @b_whatsapp_digest_logs AS rows_before, (SELECT COUNT(*) FROM `whatsapp_digest_logs`) AS rows_after;

-- =============================================================================
-- NOTES
-- =============================================================================
-- Decisions
--  * No table changed shape in a way the old and new code cannot share, so nothing is archived by rename.
--    Every change to an existing table is additive, except policy_logs.event_type (ENUM to VARCHAR(50)),
--    which keeps every existing value. Existing production values: policy_created, policy_updated, endorsement.
--  * Existing damage checked in production before writing this: no invalid enum values in any of the 18 enum columns,
--    no NULLs where dev is NOT NULL, indexes and foreign keys on the 17 shared tables are identical to dev,
--    so there are no duplicates or orphans to repair. Preflight re-checks the ones that matter.
--  * New tables are created WITHOUT foreign keys, then the foreign keys are added in step 6.
--    FOREIGN_KEY_CHECKS is never changed, so MySQL validates every row when a key is added.
--  * master_products.product_type is classified with the mapping from migration_product_types.sql (only rows still NULL).
--    The row with code test / name qweqweqweqe matches nothing and stays NULL. The code treats NULL as not classified.
--    NOT NULL is NOT enforced, decide after the frontend always sends product_type.
--  * commissions.sub_agent_amount is NOT NULL DEFAULT 0, so existing commissions get 0, which is right (no sub-agent existed).
--  * policies.payment_due_date, payment_date, payment_reference stay NULL. Every policy is unpaid, and a NULL due date falls
--    back to coverage_start + 30 days in helpers/payments.php.
--  * customers.referred_by_agent_id and policies.issuing_agent_id: no non-NULL values in production, nothing to convert.
--  * Column comments are written in ASCII, so a GUI with a different encoding cannot garble them (arrows and the
--    multiplication sign become -> and x, a semicolon inside a comment becomes a comma). This is the only difference from the dev column definitions.
--  * commission_statements and commission_statement_items do not exist in agentra_dev, but commissions/index.php and
--    commissions/statements.php query them. They come from migration_commission_statements.sql. Dev needs the same tables.
--
-- NOT copied from dev (set yourself in production if needed)
--  * Dev rows: master_products (22 dev rows), claims, claim_events, documents, quotes, prospects, renewal_workflows,
--    policy_risk_details, notifications, policy_logs, commissions, customers and so on. They are test data.
--  * message_templates: not seeded, helpers/messaging.php seeds a company's defaults the first time it is needed.
--  * notification_settings: production keeps its own row. Dev's row (a WhatsApp target number) is not copied.
--  * Bank accounts, SumoPod / WAHA / S3 secrets and hosts live in .env, not in this schema.
--
-- Intentionally skipped
--  * Data hygiene in master_products, a human decision: PAR 62 has prefix ',62' (stray comma), PAR 63 has no prefix,
--    prefix 63 is on 'Gempa bumi' as well, prefix 01 is on three products.
--  * Table AUTO_INCREMENT counters and DEFINER clauses (none exist, no triggers, routines, views, events or partitions).
--
-- Code and repo issues seen while preparing this (not changed here)
--  * .gitignore ignores *.sql, so none of the older migration_*.sql files is tracked. This file is tracked through an exception.
--  * Dev has no commission_statements tables, see above.
--  * storage/logs/request-*.jsonl are tracked on the release branch (not on master). They hold IPs, endpoints and status codes only.
--  * The deploy is rsync --delete from the repo, so a file that exists only on the server is removed on every deploy.
-- =============================================================================

-- =============================================================================
-- ROLLBACK (commented out). The real rollback is restoring the backup from step 1.
-- Manual partial rollback, only safe while the new tables are still empty. Add the ending semicolon to each line.
-- =============================================================================
-- ALTER TABLE `sub_agent_insurer_rates` DROP FOREIGN KEY `fk_sair_sub_agent`
-- ALTER TABLE `sub_agent_insurer_rates` DROP FOREIGN KEY `fk_sair_insurer`
-- ALTER TABLE `renewal_workflows` DROP FOREIGN KEY `fk_renewal_workflow_policy`
-- ALTER TABLE `renewal_workflow_events` DROP FOREIGN KEY `fk_renewal_event_workflow`
-- ALTER TABLE `quotes` DROP FOREIGN KEY `fk_quote_prospect`
-- ALTER TABLE `quote_options` DROP FOREIGN KEY `fk_qopt_quote`
-- ALTER TABLE `policy_terms` DROP FOREIGN KEY `fk_terms_policy`
-- ALTER TABLE `policy_terms` DROP FOREIGN KEY `fk_terms_coverage`
-- ALTER TABLE `policy_risk_members` DROP FOREIGN KEY `fk_risk_member_policy`
-- ALTER TABLE `policy_risk_details` DROP FOREIGN KEY `fk_risk_details_policy`
-- ALTER TABLE `policy_installments` DROP FOREIGN KEY `fk_installment_policy`
-- ALTER TABLE `policy_cargo_declarations` DROP FOREIGN KEY `fk_decl_policy`
-- ALTER TABLE `documents` DROP FOREIGN KEY `fk_document_policy`
-- ALTER TABLE `documents` DROP FOREIGN KEY `fk_document_customer`
-- ALTER TABLE `documents` DROP FOREIGN KEY `fk_document_claim`
-- ALTER TABLE `commission_statement_items` DROP FOREIGN KEY `fk_csi_statement`
-- ALTER TABLE `commission_statement_items` DROP FOREIGN KEY `fk_csi_commission`
-- ALTER TABLE `claims` DROP FOREIGN KEY `fk_claim_policy`
-- ALTER TABLE `claims` DROP FOREIGN KEY `fk_claim_customer`
-- ALTER TABLE `claim_events` DROP FOREIGN KEY `fk_cev_claim`
-- DROP TABLE IF EXISTS `tasks`
-- DROP TABLE IF EXISTS `subscription_payment`
-- DROP TABLE IF EXISTS `sub_agents`
-- DROP TABLE IF EXISTS `sub_agent_insurer_rates`
-- DROP TABLE IF EXISTS `renewal_workflows`
-- DROP TABLE IF EXISTS `renewal_workflow_events`
-- DROP TABLE IF EXISTS `quotes`
-- DROP TABLE IF EXISTS `quote_options`
-- DROP TABLE IF EXISTS `prospects`
-- DROP TABLE IF EXISTS `policy_terms`
-- DROP TABLE IF EXISTS `policy_risk_members`
-- DROP TABLE IF EXISTS `policy_risk_details`
-- DROP TABLE IF EXISTS `policy_installments`
-- DROP TABLE IF EXISTS `policy_cargo_declarations`
-- DROP TABLE IF EXISTS `payment_webhook_event`
-- DROP TABLE IF EXISTS `notifications`
-- DROP TABLE IF EXISTS `message_templates`
-- DROP TABLE IF EXISTS `message_log`
-- DROP TABLE IF EXISTS `message_broadcasts`
-- DROP TABLE IF EXISTS `documents`
-- DROP TABLE IF EXISTS `customer_groups`
-- DROP TABLE IF EXISTS `commission_statements`
-- DROP TABLE IF EXISTS `commission_statement_items`
-- DROP TABLE IF EXISTS `claims`
-- DROP TABLE IF EXISTS `claim_events`
-- ALTER TABLE `commissions` DROP INDEX `idx_commission_sub_agent`
-- ALTER TABLE `customers` DROP INDEX `idx_customer_group`
-- ALTER TABLE `master_products` DROP INDEX `idx_master_products_type`
-- ALTER TABLE `policies` DROP COLUMN `payment_reference`
-- ALTER TABLE `policies` DROP COLUMN `payment_date`
-- ALTER TABLE `policies` DROP COLUMN `payment_due_date`
-- ALTER TABLE `master_products` DROP COLUMN `product_type`
-- ALTER TABLE `customers` DROP COLUMN `group_id`
-- ALTER TABLE `commissions` DROP COLUMN `sub_agent_amount`
-- ALTER TABLE `commissions` DROP COLUMN `sub_agent_split_rate`
-- ALTER TABLE `commissions` DROP COLUMN `sub_agent_id`
-- policy_logs.event_type back to ENUM is only valid while no row uses an event outside the original list:
-- ALTER TABLE `policy_logs` MODIFY COLUMN `event_type` enum('policy_created','policy_updated','payment_status_changed','payment_confirmation_submitted','payment_confirmed','renewal_status_changed','endorsement','followup_logged','document_uploaded','document_deleted','commission_marked_received','commission_discrepancy') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
