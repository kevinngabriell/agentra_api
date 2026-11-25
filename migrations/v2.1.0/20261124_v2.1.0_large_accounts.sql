-- =============================================================================
-- Agentra v2.1.0: large-account features (generated; see docs/INSURANCE_GAP_AUDIT.md)
-- =============================================================================
-- Additive only: new tables, new nullable / defaulted columns, enum values appended.
-- Nothing is dropped, renamed or re-keyed. Re-runnable: every step is guarded.
--
-- HOW TO RUN: connect to the Agentra APP schema, select it as the default database,
-- run the whole file in one session. Back up first on production.
-- Uses DATABASE(), so no schema name is hardcoded. No DELIMITER, no procedures.
-- =============================================================================

-- preflight: must be the Agentra app schema
SET @s = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('policies','customers','claims','quotes','policy_coverages')) <> 5, 'SELECT 1 FROM PREFLIGHT_FAILED__wrong_database', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ===== STEP 1: new columns =====
-- policies.currency
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'currency') = 0, 'ALTER TABLE `policies` ADD COLUMN `currency` char(3) NOT NULL DEFAULT ''IDR''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.exchange_rate
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'exchange_rate') = 0, 'ALTER TABLE `policies` ADD COLUMN `exchange_rate` decimal(18,6) NOT NULL DEFAULT 1', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.sum_insured_fc
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'sum_insured_fc') = 0, 'ALTER TABLE `policies` ADD COLUMN `sum_insured_fc` decimal(20,2) DEFAULT NULL COMMENT ''Sum insured in the policy currency''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.premium_fc
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'premium_fc') = 0, 'ALTER TABLE `policies` ADD COLUMN `premium_fc` decimal(20,2) DEFAULT NULL COMMENT ''Premium in the policy currency''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.issued_premium_amount
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'issued_premium_amount') = 0, 'ALTER TABLE `policies` ADD COLUMN `issued_premium_amount` bigint DEFAULT NULL COMMENT ''Premium of the original debit note; endorsements are billed separately''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.issued_customer_premium
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'issued_customer_premium') = 0, 'ALTER TABLE `policies` ADD COLUMN `issued_customer_premium` bigint DEFAULT NULL COMMENT ''Customer amount of the original debit note''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.debit_note_number
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'debit_note_number') = 0, 'ALTER TABLE `policies` ADD COLUMN `debit_note_number` varchar(40) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.covernote_number
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'covernote_number') = 0, 'ALTER TABLE `policies` ADD COLUMN `covernote_number` varchar(60) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.covernote_date
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'covernote_date') = 0, 'ALTER TABLE `policies` ADD COLUMN `covernote_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.covernote_expiry
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'covernote_expiry') = 0, 'ALTER TABLE `policies` ADD COLUMN `covernote_expiry` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.policy_doc_received_date
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'policy_doc_received_date') = 0, 'ALTER TABLE `policies` ADD COLUMN `policy_doc_received_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.cancellation_date
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'cancellation_date') = 0, 'ALTER TABLE `policies` ADD COLUMN `cancellation_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.cancellation_reason
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'cancellation_reason') = 0, 'ALTER TABLE `policies` ADD COLUMN `cancellation_reason` varchar(30) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.cancellation_method
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'cancellation_method') = 0, 'ALTER TABLE `policies` ADD COLUMN `cancellation_method` varchar(20) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.cancellation_note
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'cancellation_note') = 0, 'ALTER TABLE `policies` ADD COLUMN `cancellation_note` varchar(500) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policies.renewal_lead_days
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policies' AND column_name = 'renewal_lead_days') = 0, 'ALTER TABLE `policies` ADD COLUMN `renewal_lead_days` smallint DEFAULT NULL COMMENT ''Per-policy renewal reminder lead time''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_coverages.location_id
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_coverages' AND column_name = 'location_id') = 0, 'ALTER TABLE `policy_coverages` ADD COLUMN `location_id` varchar(50) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.bi_gross_profit
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'bi_gross_profit') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `bi_gross_profit` bigint DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.bi_indemnity_months
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'bi_indemnity_months') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `bi_indemnity_months` tinyint DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.bi_time_deductible_days
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'bi_time_deductible_days') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `bi_time_deductible_days` smallint DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.is_stock_declaration
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'is_stock_declaration') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `is_stock_declaration` tinyint(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.stock_max_sum_insured
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'stock_max_sum_insured') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `stock_max_sum_insured` bigint DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.stock_rate_permille
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'stock_rate_permille') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `stock_rate_permille` decimal(8,4) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.stock_deposit_premium
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'stock_deposit_premium') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `stock_deposit_premium` bigint DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.stock_refund_cap_percent
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'stock_refund_cap_percent') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `stock_refund_cap_percent` decimal(5,2) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.inspection_status
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'inspection_status') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `inspection_status` enum(''not_required'',''pending'',''done'',''waived'') DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.inspection_date
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'inspection_date') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `inspection_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.travel_plan_type
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'travel_plan_type') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `travel_plan_type` enum(''single'',''annual'') DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.max_trip_days
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'max_trip_days') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `max_trip_days` smallint DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.plan_tier
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'plan_tier') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `plan_tier` varchar(60) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.travel_area
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'travel_area') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `travel_area` enum(''asean'',''asia'',''worldwide_excl_us'',''worldwide'') DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.family_type
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'family_type') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `family_type` enum(''individual'',''family'',''group'') DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.benefit_currency
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'benefit_currency') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `benefit_currency` char(3) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.medical_limit
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'medical_limit') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `medical_limit` decimal(16,2) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.evacuation_limit
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'evacuation_limit') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `evacuation_limit` decimal(16,2) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.baggage_limit
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'baggage_limit') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `baggage_limit` decimal(16,2) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.delay_limit
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'delay_limit') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `delay_limit` decimal(16,2) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.cancellation_limit
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'cancellation_limit') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `cancellation_limit` decimal(16,2) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_details.max_age
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_details' AND column_name = 'max_age') = 0, 'ALTER TABLE `policy_risk_details` ADD COLUMN `max_age` tinyint DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_members.certificate_number
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_members' AND column_name = 'certificate_number') = 0, 'ALTER TABLE `policy_risk_members` ADD COLUMN `certificate_number` varchar(60) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_members.trip_start
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_members' AND column_name = 'trip_start') = 0, 'ALTER TABLE `policy_risk_members` ADD COLUMN `trip_start` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- policy_risk_members.trip_end
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'policy_risk_members' AND column_name = 'trip_end') = 0, 'ALTER TABLE `policy_risk_members` ADD COLUMN `trip_end` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.currency
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'currency') = 0, 'ALTER TABLE `claims` ADD COLUMN `currency` char(3) NOT NULL DEFAULT ''IDR''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.exchange_rate
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'exchange_rate') = 0, 'ALTER TABLE `claims` ADD COLUMN `exchange_rate` decimal(18,6) NOT NULL DEFAULT 1', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.estimated_loss_fc
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'estimated_loss_fc') = 0, 'ALTER TABLE `claims` ADD COLUMN `estimated_loss_fc` decimal(20,2) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.coverage_id
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'coverage_id') = 0, 'ALTER TABLE `claims` ADD COLUMN `coverage_id` varchar(50) DEFAULT NULL COMMENT ''Affected coverage item (vehicle, location item)''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.claim_type
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'claim_type') = 0, 'ALTER TABLE `claims` ADD COLUMN `claim_type` enum(''partial'',''total_loss'') NOT NULL DEFAULT ''partial''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.value_at_risk
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'value_at_risk') = 0, 'ALTER TABLE `claims` ADD COLUMN `value_at_risk` bigint DEFAULT NULL COMMENT ''Actual value at the time of loss, for the average (underinsurance) calculation''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.salvage_amount
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'salvage_amount') = 0, 'ALTER TABLE `claims` ADD COLUMN `salvage_amount` bigint NOT NULL DEFAULT 0', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.adjuster_firm
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'adjuster_firm') = 0, 'ALTER TABLE `claims` ADD COLUMN `adjuster_firm` varchar(150) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.adjuster_name
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'adjuster_name') = 0, 'ALTER TABLE `claims` ADD COLUMN `adjuster_name` varchar(150) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.adjuster_appointed_date
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'adjuster_appointed_date') = 0, 'ALTER TABLE `claims` ADD COLUMN `adjuster_appointed_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.prelim_report_date
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'prelim_report_date') = 0, 'ALTER TABLE `claims` ADD COLUMN `prelim_report_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.final_report_date
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'final_report_date') = 0, 'ALTER TABLE `claims` ADD COLUMN `final_report_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.workshop_name
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'workshop_name') = 0, 'ALTER TABLE `claims` ADD COLUMN `workshop_name` varchar(150) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.spk_date
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'spk_date') = 0, 'ALTER TABLE `claims` ADD COLUMN `spk_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.own_risk_paid_date
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'own_risk_paid_date') = 0, 'ALTER TABLE `claims` ADD COLUMN `own_risk_paid_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.repair_status
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'repair_status') = 0, 'ALTER TABLE `claims` ADD COLUMN `repair_status` enum(''not_started'',''in_repair'',''ready'',''handed_over'') DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.vehicle_handover_date
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'vehicle_handover_date') = 0, 'ALTER TABLE `claims` ADD COLUMN `vehicle_handover_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.bpkb_handover_date
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'bpkb_handover_date') = 0, 'ALTER TABLE `claims` ADD COLUMN `bpkb_handover_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.notice_deadline
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'notice_deadline') = 0, 'ALTER TABLE `claims` ADD COLUMN `notice_deadline` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.docs_deadline
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'docs_deadline') = 0, 'ALTER TABLE `claims` ADD COLUMN `docs_deadline` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.decision_due
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'decision_due') = 0, 'ALTER TABLE `claims` ADD COLUMN `decision_due` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- claims.payment_due
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'payment_due') = 0, 'ALTER TABLE `claims` ADD COLUMN `payment_due` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- quotes.currency
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quotes' AND column_name = 'currency') = 0, 'ALTER TABLE `quotes` ADD COLUMN `currency` char(3) NOT NULL DEFAULT ''IDR''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- quotes.exchange_rate
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quotes' AND column_name = 'exchange_rate') = 0, 'ALTER TABLE `quotes` ADD COLUMN `exchange_rate` decimal(18,6) NOT NULL DEFAULT 1', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- quotes.accepted_panel
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quotes' AND column_name = 'accepted_panel') = 0, 'ALTER TABLE `quotes` ADD COLUMN `accepted_panel` json DEFAULT NULL COMMENT ''Option ids accepted as a co-insurance panel''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- quote_options.placement_status
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quote_options' AND column_name = 'placement_status') = 0, 'ALTER TABLE `quote_options` ADD COLUMN `placement_status` enum(''draft'',''submitted'',''quoted'',''declined'',''referred'',''awaiting_ri'') NOT NULL DEFAULT ''quoted''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- quote_options.submitted_date
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quote_options' AND column_name = 'submitted_date') = 0, 'ALTER TABLE `quote_options` ADD COLUMN `submitted_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- quote_options.responded_date
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quote_options' AND column_name = 'responded_date') = 0, 'ALTER TABLE `quote_options` ADD COLUMN `responded_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- quote_options.decline_reason
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quote_options' AND column_name = 'decline_reason') = 0, 'ALTER TABLE `quote_options` ADD COLUMN `decline_reason` varchar(300) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- quote_options.info_supplied
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quote_options' AND column_name = 'info_supplied') = 0, 'ALTER TABLE `quote_options` ADD COLUMN `info_supplied` varchar(500) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- quote_options.share_percent
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quote_options' AND column_name = 'share_percent') = 0, 'ALTER TABLE `quote_options` ADD COLUMN `share_percent` decimal(6,3) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- quote_options.is_leader
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quote_options' AND column_name = 'is_leader') = 0, 'ALTER TABLE `quote_options` ADD COLUMN `is_leader` tinyint(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- quote_options.terms_json
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quote_options' AND column_name = 'terms_json') = 0, 'ALTER TABLE `quote_options` ADD COLUMN `terms_json` json DEFAULT NULL COMMENT ''Structured deductibles / clauses / extensions offered''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- customers.risk_rating
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'risk_rating') = 0, 'ALTER TABLE `customers` ADD COLUMN `risk_rating` enum(''low'',''medium'',''high'') DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- customers.is_pep
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'is_pep') = 0, 'ALTER TABLE `customers` ADD COLUMN `is_pep` tinyint(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- customers.screening_status
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'screening_status') = 0, 'ALTER TABLE `customers` ADD COLUMN `screening_status` enum(''not_screened'',''clear'',''potential_match'',''confirmed_match'') NOT NULL DEFAULT ''not_screened''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- customers.screened_at
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'screened_at') = 0, 'ALTER TABLE `customers` ADD COLUMN `screened_at` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- customers.screening_lists
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'screening_lists') = 0, 'ALTER TABLE `customers` ADD COLUMN `screening_lists` varchar(200) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- customers.screening_note
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'screening_note') = 0, 'ALTER TABLE `customers` ADD COLUMN `screening_note` varchar(500) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- insurers.assistance_phone
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'insurers' AND column_name = 'assistance_phone') = 0, 'ALTER TABLE `insurers` ADD COLUMN `assistance_phone` varchar(40) DEFAULT NULL COMMENT ''24-hour emergency assistance number''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- insurer_commission_rates.is_active
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'insurer_commission_rates' AND column_name = 'is_active') = 0, 'ALTER TABLE `insurer_commission_rates` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ===== STEP 2: enum values =====
-- claims.status
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'claims' AND column_name = 'status' AND column_type LIKE '%''withdrawn''%') = 0, 'ALTER TABLE `claims` MODIFY COLUMN `status` enum(''reported'',''survey'',''documents_complete'',''approved'',''partially_paid'',''paid'',''rejected'',''appeal'',''withdrawn'') NOT NULL DEFAULT ''reported''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- renewal_workflows.stage
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'renewal_workflows' AND column_name = 'stage' AND column_type LIKE '%''awaiting_ri''%') = 0, 'ALTER TABLE `renewal_workflows` MODIFY COLUMN `stage` enum(''not_started'',''terms_requested'',''awaiting_ri'',''terms_received'',''offered'',''accepted'',''declined'',''issued'') NOT NULL DEFAULT ''not_started''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- notifications.type
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'type' AND column_type LIKE '%''year_premium_due''%') = 0, 'ALTER TABLE `notifications` MODIFY COLUMN `type` enum(''expiring_today'',''expiring_week'',''policy_lapsed'',''premium_overdue'',''commission_received'',''claim_status'',''covernote_due'',''claim_deadline'',''endorsement_due'',''warranty_due'',''party_proof_due'',''year_premium_due'') NOT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- documents.document_type
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'documents' AND column_name = 'document_type' AND column_type LIKE '%''adjuster_report''%') = 0, 'ALTER TABLE `documents` MODIFY COLUMN `document_type` enum(''policy'',''endorsement'',''sppa'',''ktp'',''npwp'',''nib'',''akta_pendirian'',''survey_photo'',''claim'',''payment_proof'',''covernote'',''survey_report'',''adjuster_report'',''other'') NOT NULL DEFAULT ''other''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ===== STEP 3: new tables =====
CREATE TABLE IF NOT EXISTS `document_sequences` (
  `company_id` varchar(50) NOT NULL,
  `seq_type` varchar(10) NOT NULL COMMENT 'DN = debit note, CN = credit note',
  `seq_year` smallint NOT NULL,
  `last_no` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`company_id`, `seq_type`, `seq_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sequential debit/credit note numbers per company and year';
CREATE TABLE IF NOT EXISTS `policy_endorsements` (
  `endorsement_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `policy_id` varchar(50) NOT NULL,
  `endorsement_number` varchar(60) DEFAULT NULL COMMENT 'Number printed by the insurer',
  `endorsement_type` varchar(30) NOT NULL COMMENT 'sum_insured_change, add_item, remove_item, extension, insured_change, period_change, cancellation, stock_adjustment, other',
  `effective_date` date NOT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `calc_method` enum('pro_rata','short_period','flat','manual','full') NOT NULL DEFAULT 'pro_rata',
  `annual_premium_delta` bigint NOT NULL DEFAULT 0 COMMENT 'Full-year premium change, signed',
  `premium_delta` bigint NOT NULL DEFAULT 0 COMMENT 'Premium charged (+) or returned (-) for this endorsement',
  `sum_insured_delta` bigint NOT NULL DEFAULT 0,
  `materai_amount` bigint NOT NULL DEFAULT 0,
  `biaya_polis` bigint NOT NULL DEFAULT 0,
  `commission_rate` decimal(5,2) NOT NULL DEFAULT 0,
  `commission_tax_rate` decimal(5,4) NOT NULL DEFAULT 0,
  `commission_delta` bigint NOT NULL DEFAULT 0,
  `commission_tax_delta` bigint NOT NULL DEFAULT 0,
  `note_type` enum('debit','credit','none') NOT NULL DEFAULT 'none',
  `note_number` varchar(40) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `settled_date` date DEFAULT NULL COMMENT 'Debit note paid by the customer / credit note refunded',
  `settled_reference` varchar(100) DEFAULT NULL,
  `commission_status` enum('included','pending','received','clawed_back','waived') NOT NULL DEFAULT 'pending' COMMENT 'included = folded into the policy commission record',
  `commission_settled_date` date DEFAULT NULL,
  `source` varchar(30) NOT NULL DEFAULT 'manual' COMMENT 'manual, policy_update, coverage, vehicle, cancellation, stock_adjustment',
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` varchar(50) DEFAULT NULL,
  `is_void` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`endorsement_id`),
  KEY `idx_pend_policy` (`policy_id`),
  KEY `idx_pend_company_due` (`company_id`, `settled_date`, `due_date`),
  KEY `idx_pend_company_comm` (`company_id`, `commission_status`),
  CONSTRAINT `fk_pend_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Premium ledger of mid-term changes: each row is one debit or credit note with its commission';
CREATE TABLE IF NOT EXISTS `policy_locations` (
  `location_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `policy_id` varchar(50) NOT NULL,
  `seq` smallint NOT NULL DEFAULT 1,
  `label` varchar(120) DEFAULT NULL,
  `address` text,
  `village` varchar(100) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `postal_code` varchar(10) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `area_name` varchar(150) DEFAULT NULL COMMENT 'Industrial estate / complex, used for accumulation',
  `occupation_code` varchar(40) DEFAULT NULL,
  `occupation_description` varchar(200) DEFAULT NULL,
  `construction_class` enum('I','II','III') DEFAULT NULL,
  `eq_zone` tinyint DEFAULT NULL COMMENT 'Earthquake tariff zone 1-5',
  `flood_zone` enum('low','medium','high') DEFAULT NULL,
  `flood_history` enum('none','once','repeated') DEFAULT NULL,
  `last_flood_date` date DEFAULT NULL,
  `wall_material` varchar(80) DEFAULT NULL,
  `roof_material` varchar(80) DEFAULT NULL,
  `floor_material` varchar(80) DEFAULT NULL,
  `storeys` tinyint DEFAULT NULL,
  `year_built` smallint DEFAULT NULL,
  `floor_area_m2` int DEFAULT NULL,
  `rebuild_cost_per_m2` bigint DEFAULT NULL,
  `has_apar` tinyint(1) NOT NULL DEFAULT 0,
  `has_hydrant` tinyint(1) NOT NULL DEFAULT 0,
  `has_sprinkler` tinyint(1) NOT NULL DEFAULT 0,
  `has_detector` tinyint(1) NOT NULL DEFAULT 0,
  `has_alarm` tinyint(1) NOT NULL DEFAULT 0,
  `has_cctv` tinyint(1) NOT NULL DEFAULT 0,
  `has_security_guard` tinyint(1) NOT NULL DEFAULT 0,
  `fire_station_distance_km` decimal(6,2) DEFAULT NULL,
  `hazardous_goods` varchar(300) DEFAULT NULL,
  `rack_height_m` decimal(5,2) DEFAULT NULL,
  `adjacent_exposure` varchar(300) DEFAULT NULL,
  `housekeeping` enum('good','fair','poor') DEFAULT NULL,
  `stock_ownership` enum('own','third_party','mixed') DEFAULT NULL,
  `third_party_stock_note` varchar(300) DEFAULT NULL,
  `bailee_liability_covered` tinyint(1) NOT NULL DEFAULT 0,
  `occupancy_status` enum('occupied','unoccupied','renovation','partly_rented') DEFAULT NULL,
  `unoccupied_since` date DEFAULT NULL,
  `pml_percent` decimal(5,2) DEFAULT NULL COMMENT 'Probable maximum loss as % of the location sum insured',
  `notes` text,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`location_id`),
  KEY `idx_ploc_policy` (`policy_id`),
  KEY `idx_ploc_company_area` (`company_id`, `area_name`),
  KEY `idx_ploc_company_city` (`company_id`, `city`),
  CONSTRAINT `fk_ploc_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Risk locations of a property policy; coverage items point here through policy_coverages.location_id';
CREATE TABLE IF NOT EXISTS `policy_vehicles` (
  `vehicle_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `policy_id` varchar(50) NOT NULL,
  `coverage_id` varchar(50) NOT NULL COMMENT 'The coverage item that carries this vehicle''s sum insured, rate and premium',
  `plate_number` varchar(20) DEFAULT NULL,
  `chassis_number` varchar(40) DEFAULT NULL,
  `engine_number` varchar(40) DEFAULT NULL,
  `vehicle_make` varchar(60) DEFAULT NULL,
  `vehicle_model` varchar(80) DEFAULT NULL,
  `vehicle_year` smallint DEFAULT NULL,
  `vehicle_color` varchar(30) DEFAULT NULL,
  `vehicle_usage` enum('private','commercial') DEFAULT NULL,
  `motor_coverage_type` enum('comprehensive','tlo') DEFAULT NULL,
  `tariff_region` tinyint DEFAULT NULL,
  `ext_flood` tinyint(1) NOT NULL DEFAULT 0,
  `ext_earthquake` tinyint(1) NOT NULL DEFAULT 0,
  `ext_rsmdcc` tinyint(1) NOT NULL DEFAULT 0,
  `ext_terrorism` tinyint(1) NOT NULL DEFAULT 0,
  `tpl_limit` bigint DEFAULT NULL,
  `pa_driver_limit` bigint DEFAULT NULL,
  `pa_passenger_limit` bigint DEFAULT NULL,
  `inspection_date` date DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`vehicle_id`),
  UNIQUE KEY `uq_pveh_coverage` (`coverage_id`),
  KEY `idx_pveh_policy` (`policy_id`),
  KEY `idx_pveh_company_plate` (`company_id`, `plate_number`),
  KEY `idx_pveh_company_chassis` (`company_id`, `chassis_number`),
  CONSTRAINT `fk_pveh_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pveh_coverage` FOREIGN KEY (`coverage_id`) REFERENCES `policy_coverages` (`coverage_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Fleet schedule: one vehicle per coverage item of type kendaraan';
CREATE TABLE IF NOT EXISTS `policy_parties` (
  `party_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `policy_id` varchar(50) NOT NULL,
  `party_type` enum('mortgagee','leasing','loss_payee','other') NOT NULL,
  `name` varchar(200) NOT NULL,
  `branch` varchar(150) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL COMMENT 'Loan / financing contract number',
  `tenor_end` date DEFAULT NULL,
  `on_insurer_panel` tinyint(1) DEFAULT NULL,
  `qq_in_name` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Print as <party> QQ <insured>',
  `notify_renewal` tinyint(1) NOT NULL DEFAULT 1,
  `proof_sent_date` date DEFAULT NULL COMMENT 'Last date the policy / renewal proof was sent to the party',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`party_id`),
  KEY `idx_pparty_policy` (`policy_id`),
  CONSTRAINT `fk_pparty_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Banks, leasing companies and loss payees with an interest in the policy';
CREATE TABLE IF NOT EXISTS `policy_year_schedule` (
  `year_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `policy_id` varchar(50) NOT NULL,
  `year_no` tinyint NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `sum_insured` bigint NOT NULL DEFAULT 0,
  `premium_amount` bigint NOT NULL DEFAULT 0,
  `due_date` date DEFAULT NULL,
  `paid_date` date DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`year_id`),
  UNIQUE KEY `uq_pyear_policy_year` (`policy_id`, `year_no`),
  KEY `idx_pyear_company_due` (`company_id`, `paid_date`, `due_date`),
  CONSTRAINT `fk_pyear_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Multi-year (long-term) policies: sum insured and premium per policy year';
CREATE TABLE IF NOT EXISTS `policy_risk_recommendations` (
  `recommendation_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `policy_id` varchar(50) NOT NULL,
  `location_id` varchar(50) DEFAULT NULL,
  `description` varchar(1000) NOT NULL,
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `is_warranty` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'A breached warranty can void a claim',
  `due_date` date DEFAULT NULL,
  `status` enum('open','done','waived') NOT NULL DEFAULT 'open',
  `completed_date` date DEFAULT NULL,
  `source` varchar(150) DEFAULT NULL COMMENT 'Surveyor / survey report reference',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`recommendation_id`),
  KEY `idx_prec_policy` (`policy_id`),
  KEY `idx_prec_company_status` (`company_id`, `status`, `due_date`),
  CONSTRAINT `fk_prec_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Survey risk-improvement recommendations and warranties';
CREATE TABLE IF NOT EXISTS `policy_stock_declarations` (
  `declaration_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `policy_id` varchar(50) NOT NULL,
  `location_id` varchar(50) DEFAULT NULL,
  `period_month` char(7) NOT NULL COMMENT 'YYYY-MM',
  `declared_value` bigint NOT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`declaration_id`),
  KEY `idx_psd_policy` (`policy_id`, `period_month`),
  CONSTRAINT `fk_psd_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`policy_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Monthly stock value declarations of an adjustable (stock declaration) policy';
CREATE TABLE IF NOT EXISTS `quote_items` (
  `item_id` varchar(50) NOT NULL,
  `quote_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `coverage_type` varchar(30) NOT NULL,
  `label` varchar(150) DEFAULT NULL,
  `sum_insured` bigint NOT NULL DEFAULT 0,
  `count_in_tsi` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint NOT NULL DEFAULT 0,
  PRIMARY KEY (`item_id`),
  KEY `idx_qitem_quote` (`quote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Itemised quote: sum insured per coverage item / peril';
CREATE TABLE IF NOT EXISTS `quote_option_items` (
  `option_id` varchar(50) NOT NULL,
  `item_id` varchar(50) NOT NULL,
  `rate_permille` decimal(8,4) NOT NULL DEFAULT 0,
  `premium_amount` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`option_id`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Rate and premium of one quote item in one insurer option';
CREATE TABLE IF NOT EXISTS `claim_reserves` (
  `reserve_id` varchar(50) NOT NULL,
  `claim_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `reserve_date` date NOT NULL,
  `amount` bigint NOT NULL,
  `source` enum('initial','insured','adjuster_prelim','adjuster_final','insurer','agent') NOT NULL DEFAULT 'agent',
  `note` varchar(500) DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reserve_id`),
  KEY `idx_cres_claim` (`claim_id`, `reserve_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='History of the loss estimate of a claim';
CREATE TABLE IF NOT EXISTS `claim_payments` (
  `payment_id` varchar(50) NOT NULL,
  `claim_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` bigint NOT NULL,
  `payment_type` enum('interim','final') NOT NULL DEFAULT 'interim',
  `payer_name` varchar(200) DEFAULT NULL COMMENT 'Insurer or co-insurer that paid',
  `reference` varchar(100) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  KEY `idx_cpay_claim` (`claim_id`, `payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Interim and final claim payments';
CREATE TABLE IF NOT EXISTS `claim_recoveries` (
  `recovery_id` varchar(50) NOT NULL,
  `claim_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `recovery_type` enum('salvage','subrogation','other') NOT NULL,
  `party` varchar(200) DEFAULT NULL,
  `amount` bigint NOT NULL DEFAULT 0,
  `recovery_date` date DEFAULT NULL,
  `returned_to_insured` bigint NOT NULL DEFAULT 0 COMMENT 'Part of the recovery paid back to the insured (e.g. their deductible)',
  `note` varchar(500) DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`recovery_id`),
  KEY `idx_crec_claim` (`claim_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Salvage and subrogation recoveries';
CREATE TABLE IF NOT EXISTS `customer_beneficial_owners` (
  `owner_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `customer_id` varchar(50) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `id_number` varchar(40) DEFAULT NULL,
  `nationality` varchar(60) DEFAULT NULL,
  `ownership_percent` decimal(5,2) DEFAULT NULL,
  `role` varchar(100) DEFAULT NULL,
  `is_pep` tinyint(1) NOT NULL DEFAULT 0,
  `notes` varchar(300) DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`owner_id`),
  KEY `idx_cbo_customer` (`customer_id`),
  CONSTRAINT `fk_cbo_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Beneficial owners of corporate customers (APU-PPT)';
CREATE TABLE IF NOT EXISTS `master_occupations` (
  `occupation_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `code` varchar(40) NOT NULL,
  `description` varchar(300) NOT NULL,
  `tariff_class` varchar(40) DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`occupation_id`),
  UNIQUE KEY `uq_mocc_company_code` (`company_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Occupation codes (OJK property tariff table) maintained per company';
CREATE TABLE IF NOT EXISTS `master_tariff_bands` (
  `band_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `tariff_type` enum('property','earthquake','motor') NOT NULL,
  `band_key` varchar(100) NOT NULL COMMENT 'property: <occupation code>:<class>; earthquake: zone:<n>; motor: <category>:<region>:<coverage>',
  `description` varchar(300) DEFAULT NULL,
  `rate_min` decimal(8,4) DEFAULT NULL COMMENT 'Permille',
  `rate_max` decimal(8,4) DEFAULT NULL COMMENT 'Permille',
  `discount_max_percent` decimal(5,2) DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`band_id`),
  UNIQUE KEY `uq_mtb_company_key` (`company_id`, `tariff_type`, `band_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Regulator tariff bands entered by the company, used for rate warnings';

-- ===== STEP 4: indexes =====
SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'policy_coverages' AND index_name = 'idx_cov_location') = 0, 'ALTER TABLE `policy_coverages` ADD INDEX `idx_cov_location` (`location_id`)', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'claims' AND index_name = 'idx_claim_coverage') = 0, 'ALTER TABLE `claims` ADD INDEX `idx_claim_coverage` (`coverage_id`)', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ===== STEP 5: data =====
-- Freeze the original debit note of existing policies so endorsements no longer change it.
UPDATE `policies` SET issued_premium_amount = premium_amount WHERE issued_premium_amount IS NULL;
UPDATE `policies` SET issued_customer_premium = customer_premium_amount WHERE issued_customer_premium IS NULL;
-- Cancelled policies keep their status; nothing else is backfilled.

-- ===== STEP 6: verification (every problems value must be 0) =====
SELECT 'missing_tables' AS check_name, 16 - COUNT(*) AS problems FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name IN ('document_sequences','policy_endorsements','policy_locations','policy_vehicles','policy_parties','policy_year_schedule','policy_risk_recommendations','policy_stock_declarations','quote_items','quote_option_items','claim_reserves','claim_payments','claim_recoveries','customer_beneficial_owners','master_occupations','master_tariff_bands');
SELECT 'unfrozen_policies' AS check_name, COUNT(*) AS problems FROM `policies` WHERE issued_premium_amount IS NULL;
