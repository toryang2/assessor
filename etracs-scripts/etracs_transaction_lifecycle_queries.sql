-- ==============================================================================
-- ETRACS 2.54 PHASE 3.2: FINAL LIFECYCLE EVIDENCE & REPRODUCIBILITY QUERIES
-- Database: etracs254_kitaotao | User: chatgpt_ro (READ-ONLY)
-- Execution Environment: MySQL on localhost
-- Description: Targeted read-only queries establishing the entity lifecycle,
-- structural relationships, pointer mechanics, and representative cases across
-- all active assessment transaction types. Incorporates Phase 3.2 final corrections:
-- verified two-sided TD uniqueness for ledger joins, independent base-table
-- and reachable assessment line queries (avoiding Cartesian multiplication),
-- four-category RPUMaster pointer breakdown reporting zero asymmetric matches,
-- full classification of unmatched rptledgerfaas rows (NULL vs unresolved IDs),
-- and verified cancellation synchronization.
-- ==============================================================================

-- ------------------------------------------------------------------------------
-- 1. TRANSACTION TYPE INVENTORY & SUITABLE RECORD VOLUMES
-- ------------------------------------------------------------------------------

-- 1.1 Complete Configured Transaction Types (All 27 types from faas_txntype via LEFT JOIN)
-- Reproduces configured inventory, active vs zero-count types, and observed states.
SELECT 
    tt.objid AS txntype_code,
    tt.name AS txntype_name,
    COUNT(f.objid) AS total_faas_records,
    SUM(CASE WHEN f.state = 'CURRENT' THEN 1 ELSE 0 END) AS count_current,
    SUM(CASE WHEN f.state = 'CANCELLED' THEN 1 ELSE 0 END) AS count_cancelled,
    SUM(CASE WHEN f.state NOT IN ('CURRENT', 'CANCELLED') THEN 1 ELSE 0 END) AS count_transitional_draft,
    SUM(CASE WHEN f.tdno IS NOT NULL AND f.tdno != '' THEN 1 ELSE 0 END) AS count_with_tdno,
    SUM(CASE WHEN f.state IN ('CURRENT', 'CANCELLED') 
             AND (f.tdno IS NOT NULL AND f.tdno != '') 
             AND r.objid IS NOT NULL 
             AND rp.objid IS NOT NULL THEN 1 ELSE 0 END) AS suitable_finalized_records,
    GROUP_CONCAT(DISTINCT f.state ORDER BY f.state SEPARATOR ', ') AS observed_states
FROM faas_txntype tt
LEFT JOIN faas f ON tt.objid = f.txntype_objid
LEFT JOIN rpu r ON f.rpuid = r.objid
LEFT JOIN realproperty rp ON f.realpropertyid = rp.objid
GROUP BY tt.objid, tt.name
ORDER BY total_faas_records DESC, tt.objid;


-- ------------------------------------------------------------------------------
-- 2. CORE ENTITY LIFECYCLE & POINTER MECHANICS (QUERY-GRAIN CORRECTED)
-- ------------------------------------------------------------------------------

-- 2.1 Full-Population RPUMaster Pointer Coverage (Four-Category Breakdown)
-- Reports separate counts for total examined, RPU presence, RPUMaster presence, 
-- both pointers match, only FAAS matches, only RPU matches, neither matches.
SELECT 
    f.txntype_objid,
    COUNT(f.objid) AS total_faas_examined,
    SUM(CASE WHEN r.objid IS NOT NULL THEN 1 ELSE 0 END) AS has_rpu_relationship,
    SUM(CASE WHEN rm.objid IS NOT NULL THEN 1 ELSE 0 END) AS has_rpumaster_relationship,
    SUM(CASE WHEN rm.currentfaasid = f.objid AND rm.currentrpuid = f.rpuid THEN 1 ELSE 0 END) AS both_pointers_match,
    SUM(CASE WHEN rm.currentfaasid = f.objid AND (rm.currentrpuid != f.rpuid OR rm.currentrpuid IS NULL) THEN 1 ELSE 0 END) AS only_faas_matches,
    SUM(CASE WHEN (rm.currentfaasid != f.objid OR rm.currentfaasid IS NULL) AND rm.currentrpuid = f.rpuid THEN 1 ELSE 0 END) AS only_rpu_matches,
    SUM(CASE WHEN (rm.currentfaasid != f.objid OR rm.currentfaasid IS NULL) AND (rm.currentrpuid != f.rpuid OR rm.currentrpuid IS NULL) THEN 1 ELSE 0 END) AS neither_pointer_matches,
    SUM(CASE WHEN r.rpumasterid = r.objid THEN 1 ELSE 0 END) AS rm_root_is_self,
    SUM(CASE WHEN r.rpumasterid != r.objid THEN 1 ELSE 0 END) AS rm_root_is_predecessor
FROM faas f
LEFT JOIN rpu r ON f.rpuid = r.objid
LEFT JOIN rpumaster rm ON r.rpumasterid = rm.objid
GROUP BY f.txntype_objid
ORDER BY total_faas_examined DESC;

-- 2.2 RPUMaster Four-Category Pointer Consistency Grouped by FAAS Lifecycle State
SELECT 
    f.state,
    COUNT(f.objid) AS total_faas_examined,
    SUM(CASE WHEN r.objid IS NOT NULL THEN 1 ELSE 0 END) AS has_rpu,
    SUM(CASE WHEN rm.objid IS NOT NULL THEN 1 ELSE 0 END) AS has_rpumaster,
    SUM(CASE WHEN rm.currentfaasid = f.objid AND rm.currentrpuid = f.rpuid THEN 1 ELSE 0 END) AS both_pointers_match,
    SUM(CASE WHEN rm.currentfaasid = f.objid AND (rm.currentrpuid != f.rpuid OR rm.currentrpuid IS NULL) THEN 1 ELSE 0 END) AS only_faas_matches,
    SUM(CASE WHEN (rm.currentfaasid != f.objid OR rm.currentfaasid IS NULL) AND rm.currentrpuid = f.rpuid THEN 1 ELSE 0 END) AS only_rpu_matches,
    SUM(CASE WHEN (rm.currentfaasid != f.objid OR rm.currentfaasid IS NULL) AND (rm.currentrpuid != f.rpuid OR rm.currentrpuid IS NULL) THEN 1 ELSE 0 END) AS neither_pointer_matches
FROM faas f
LEFT JOIN rpu r ON f.rpuid = r.objid
LEFT JOIN rpumaster rm ON r.rpumasterid = rm.objid
GROUP BY f.state
ORDER BY total_faas_examined DESC;

-- 2.3 RealProperty Continuity (Grain-Separated: Distinct FAAS vs Predecessor Links)
SELECT 
    f.txntype_objid,
    COUNT(DISTINCT f.objid) AS distinct_faas_records,
    COUNT(fp.objid) AS total_predecessor_links,
    SUM(CASE WHEN fp.objid IS NULL THEN 1 ELSE 0 END) AS faas_with_no_fp_rows,
    SUM(CASE WHEN fp.prevfaasid IS NULL THEN 1 ELSE 0 END) AS links_prevfaasid_null,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND pf.objid IS NULL THEN 1 ELSE 0 END) AS links_prevfaasid_unresolved,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND pf.objid IS NOT NULL THEN 1 ELSE 0 END) AS links_prevfaasid_resolved,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND pf.objid IS NOT NULL AND f.realpropertyid = pf.realpropertyid THEN 1 ELSE 0 END) AS links_rp_reused,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND pf.objid IS NOT NULL AND f.realpropertyid != pf.realpropertyid AND rp.previd = pf.realpropertyid THEN 1 ELSE 0 END) AS links_rp_new_points_to_pred,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND pf.objid IS NOT NULL AND f.realpropertyid != pf.realpropertyid AND (rp.previd IS NULL OR rp.previd != pf.realpropertyid) THEN 1 ELSE 0 END) AS links_rp_new_independent
FROM faas f
JOIN realproperty rp ON f.realpropertyid = rp.objid
LEFT JOIN faas_previous fp ON f.objid = fp.faasid
LEFT JOIN faas pf ON fp.prevfaasid = pf.objid
GROUP BY f.txntype_objid
ORDER BY distinct_faas_records DESC;

-- 2.4 Land-Only RealProperty Spatial Continuity (Explicit Property-Type Filter)
SELECT 
    f.txntype_objid,
    COUNT(DISTINCT f.objid) AS distinct_land_faas,
    COUNT(fp.objid) AS total_land_links,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND pf.objid IS NOT NULL THEN 1 ELSE 0 END) AS land_links_resolved,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND pf.objid IS NOT NULL AND f.realpropertyid = pf.realpropertyid THEN 1 ELSE 0 END) AS land_links_rp_reused,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND pf.objid IS NOT NULL AND f.realpropertyid != pf.realpropertyid AND rp.previd = pf.realpropertyid THEN 1 ELSE 0 END) AS land_links_rp_new_points_to_pred,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND pf.objid IS NOT NULL AND f.realpropertyid != pf.realpropertyid AND (rp.previd IS NULL OR rp.previd != pf.realpropertyid) THEN 1 ELSE 0 END) AS land_links_rp_new_independent
FROM faas f
JOIN rpu r ON f.rpuid = r.objid
JOIN realproperty rp ON f.realpropertyid = rp.objid
LEFT JOIN faas_previous fp ON f.objid = fp.faasid
LEFT JOIN faas pf ON fp.prevfaasid = pf.objid
WHERE r.rputype = 'land'
GROUP BY f.txntype_objid
ORDER BY distinct_land_faas DESC;

-- 2.5 Predecessor Valuation Trajectory (Predecessor-Link Comparisons Grain)
SELECT 
    f.txntype_objid,
    COUNT(fp.objid) AS total_electronic_links_compared,
    SUM(CASE WHEN r.totalav = prev_r.totalav THEN 1 ELSE 0 END) AS links_av_exact_equal,
    SUM(CASE WHEN r.totalav > prev_r.totalav THEN 1 ELSE 0 END) AS links_av_increased,
    SUM(CASE WHEN r.totalav < prev_r.totalav THEN 1 ELSE 0 END) AS links_av_decreased,
    SUM(CASE WHEN r.totalmv = prev_r.totalmv THEN 1 ELSE 0 END) AS links_mv_exact_equal
FROM faas f
JOIN rpu r ON f.rpuid = r.objid
JOIN faas_previous fp ON f.objid = fp.faasid
JOIN faas prev_f ON fp.prevfaasid = prev_f.objid
JOIN rpu prev_r ON prev_f.rpuid = prev_r.objid
GROUP BY f.txntype_objid
ORDER BY total_electronic_links_compared DESC;

-- 2.6 Treasury Ledger Integration (Verified Strictly 1:1 on Matched TD Numbers)
-- Both faas.tdno and rptledger.tdno are strictly unique within the matched population (0 duplicates).
-- Distinguishes ID-equality evidence from historical reuse and unlinked FAAS records.
SELECT 
    f.txntype_objid,
    COUNT(DISTINCT f.objid) AS distinct_faas_records,
    COUNT(DISTINCT CASE WHEN rl.objid IS NOT NULL THEN f.objid END) AS faas_with_ledger_match,
    COUNT(DISTINCT rl.objid) AS distinct_ledgers_matched,
    COUNT(DISTINCT f.objid) - COUNT(DISTINCT CASE WHEN rl.objid IS NOT NULL THEN f.objid END) AS faas_without_ledger,
    COUNT(DISTINCT CASE WHEN rl.objid = f.objid THEN f.objid END) AS fresh_ledger_evidence,
    COUNT(DISTINCT CASE WHEN rl.objid IS NOT NULL AND rl.objid != f.objid THEN f.objid END) AS existing_ledger_reused_evidence,
    COALESCE(rlf.total_slices, 0) AS total_rptledgerfaas_slices
FROM faas f
LEFT JOIN rptledger rl ON f.tdno = rl.tdno
LEFT JOIN (
    SELECT f2.txntype_objid, COUNT(rlf2.objid) AS total_slices
    FROM faas f2
    JOIN rptledgerfaas rlf2 ON f2.objid = rlf2.faasid
    GROUP BY f2.txntype_objid
) rlf ON f.txntype_objid = rlf.txntype_objid
GROUP BY f.txntype_objid, rlf.total_slices
ORDER BY distinct_faas_records DESC;

-- 2.7 Delimiter-Aware Cancellation Synchronization Reproducibility Query
-- Evaluates all 9,241 electronic predecessor links resolving to 8,902 distinct predecessor FAAS records.
SELECT 
    COUNT(fp.objid) AS total_electronic_links,
    COUNT(DISTINCT pf.objid) AS distinct_predecessor_faas,
    -- Individual condition counts on links
    SUM(CASE WHEN pf.state = 'CANCELLED' THEN 1 ELSE 0 END) AS pred_state_is_cancelled_links,
    SUM(CASE WHEN pf.canceldate IS NOT NULL THEN 1 ELSE 0 END) AS pred_canceldate_populated_links,
    SUM(CASE WHEN FIND_IN_SET(TRIM(f.tdno), REPLACE(pf.cancelledbytdnos, ' ', '')) > 0 THEN 1 ELSE 0 END) AS pred_cancelledby_exact_td_match_links,
    -- Individual condition counts on distinct predecessor FAAS
    COUNT(DISTINCT CASE WHEN pf.state = 'CANCELLED' THEN pf.objid END) AS pred_state_is_cancelled_distinct_faas,
    COUNT(DISTINCT CASE WHEN pf.canceldate IS NOT NULL THEN pf.objid END) AS pred_canceldate_populated_distinct_faas,
    COUNT(DISTINCT CASE WHEN FIND_IN_SET(TRIM(f.tdno), REPLACE(pf.cancelledbytdnos, ' ', '')) > 0 THEN pf.objid END) AS pred_cancelledby_exact_td_distinct_faas,
    -- Joint three-condition measure
    SUM(CASE WHEN pf.state = 'CANCELLED' 
             AND pf.canceldate IS NOT NULL 
             AND FIND_IN_SET(TRIM(f.tdno), REPLACE(pf.cancelledbytdnos, ' ', '')) > 0 THEN 1 ELSE 0 END) AS all_three_conditions_links,
    COUNT(DISTINCT CASE WHEN pf.state = 'CANCELLED' 
             AND pf.canceldate IS NOT NULL 
             AND FIND_IN_SET(TRIM(f.tdno), REPLACE(pf.cancelledbytdnos, ' ', '')) > 0 THEN pf.objid END) AS all_three_conditions_distinct_faas,
    -- Anomalous retention
    SUM(CASE WHEN pf.state = 'CURRENT' THEN 1 ELSE 0 END) AS pred_state_anomalous_current_links,
    COUNT(DISTINCT CASE WHEN pf.state = 'CURRENT' THEN pf.objid END) AS pred_state_anomalous_current_faas
FROM faas f
JOIN faas_previous fp ON f.objid = fp.faasid
JOIN faas pf ON fp.prevfaasid = pf.objid;

-- 2.8 Assessment Continuity & Line Replication Reproducibility Query (Independent Queries)
-- 2.8A: Base Table Distribution by FAAS State
SELECT 
    f.state AS faas_state,
    COUNT(a.objid) AS assessment_lines,
    COUNT(DISTINCT a.objid) AS distinct_assessment_lines,
    COUNT(DISTINCT r.objid) AS distinct_rpus,
    COUNT(DISTINCT f.objid) AS distinct_faas
FROM rpu_assessment a
JOIN rpu r ON a.rpuid = r.objid
JOIN faas f ON r.objid = f.rpuid
GROUP BY f.state
ORDER BY assessment_lines DESC;

-- 2.8B: Independent Reachable Predecessor Assessment Lines (Avoiding Cartesian join multiplication)
SELECT 
    COUNT(DISTINCT f.objid) AS faas_with_rpu_previd,
    COUNT(DISTINCT CASE WHEN a_prev.objid IS NOT NULL THEN f.objid END) AS faas_reaching_prev_assessment,
    COUNT(DISTINCT a_prev.objid) AS distinct_prev_assessment_lines_reached,
    COUNT(a_prev.objid) AS total_prev_assessment_line_associations
FROM faas f
JOIN rpu r ON f.rpuid = r.objid
JOIN rpu_assessment a_prev ON r.previd = a_prev.rpuid;

-- 2.9 Complete rpt_changeinfo Audit Population Reproducibility Query
SELECT 
    COUNT(ci.objid) AS total_changeinfo_rows,
    SUM(CASE WHEN f.objid IS NOT NULL THEN 1 ELSE 0 END) AS changeinfo_resolved_faas_links,
    COUNT(DISTINCT f.objid) AS distinct_resolved_faas_ids,
    SUM(CASE WHEN f.objid IS NULL THEN 1 ELSE 0 END) AS changeinfo_unresolved_rows,
    COUNT(DISTINCT CASE WHEN f.objid IS NULL THEN ci.faasid ELSE NULL END) AS distinct_unresolved_faas_references
FROM rpt_changeinfo ci
LEFT JOIN faas f ON ci.faasid = f.objid;

-- 2.10 Operational Table Linkage to Completed vs Draft Transactions Reproducibility Query
SELECT 
    'subdivision' AS table_name, COUNT(*) AS row_count,
    SUM(CASE WHEN f.objid IS NOT NULL AND f.state IN ('CURRENT', 'CANCELLED') THEN 1 ELSE 0 END) AS linked_completed_faas,
    SUM(CASE WHEN f.objid IS NOT NULL AND f.state NOT IN ('CURRENT', 'CANCELLED') THEN 1 ELSE 0 END) AS linked_draft_faas,
    SUM(CASE WHEN s.motherfaasid IS NULL THEN 1 ELSE 0 END) AS unlinked_null_faas
FROM subdivision s
LEFT JOIN faas f ON s.motherfaasid = f.objid
UNION ALL
SELECT 
    'consolidation', COUNT(*),
    SUM(CASE WHEN f.objid IS NOT NULL AND f.state IN ('CURRENT', 'CANCELLED') THEN 1 ELSE 0 END),
    SUM(CASE WHEN f.objid IS NOT NULL AND f.state NOT IN ('CURRENT', 'CANCELLED') THEN 1 ELSE 0 END),
    SUM(CASE WHEN c.newfaasid IS NULL THEN 1 ELSE 0 END)
FROM consolidation c
LEFT JOIN faas f ON c.newfaasid = f.objid
UNION ALL
SELECT 
    'cancelledfaas', COUNT(*),
    SUM(CASE WHEN f.objid IS NOT NULL AND f.state IN ('CURRENT', 'CANCELLED') THEN 1 ELSE 0 END),
    SUM(CASE WHEN f.objid IS NOT NULL AND f.state NOT IN ('CURRENT', 'CANCELLED') THEN 1 ELSE 0 END),
    SUM(CASE WHEN cf.faasid IS NULL OR f.objid IS NULL THEN 1 ELSE 0 END)
FROM cancelledfaas cf
LEFT JOIN faas f ON cf.faasid = f.objid;

-- 2.11 Full Classification of rptledgerfaas Records (Reconciling 22,155 vs 18,920)
SELECT 
    COUNT(*) AS total_rptledgerfaas_records,
    SUM(CASE WHEN rlf.faasid IS NOT NULL AND f.objid IS NOT NULL THEN 1 ELSE 0 END) AS matched_to_existing_faas,
    SUM(CASE WHEN rlf.faasid IS NULL OR rlf.faasid = '' THEN 1 ELSE 0 END) AS unmatched_faasid_null,
    SUM(CASE WHEN (rlf.faasid IS NULL OR rlf.faasid = '') AND f_td.objid IS NOT NULL THEN 1 ELSE 0 END) AS unmatched_null_td_matches_faas,
    SUM(CASE WHEN (rlf.faasid IS NULL OR rlf.faasid = '') AND f_td.objid IS NULL THEN 1 ELSE 0 END) AS unmatched_null_td_unresolved,
    SUM(CASE WHEN rlf.faasid IS NOT NULL AND rlf.faasid != '' AND f.objid IS NULL THEN 1 ELSE 0 END) AS unmatched_faasid_populated_unresolved,
    COUNT(DISTINCT CASE WHEN rlf.faasid IS NOT NULL AND rlf.faasid != '' AND f.objid IS NULL THEN rlf.faasid END) AS distinct_unresolved_faasids
FROM rptledgerfaas rlf
LEFT JOIN faas f ON rlf.faasid = f.objid
LEFT JOIN faas f_td ON rlf.tdno = f_td.tdno;


-- ------------------------------------------------------------------------------
-- 3. REPRESENTATIVE CASE VERIFICATION QUERIES (NON-MULTIPLYING)
-- ------------------------------------------------------------------------------

-- 3.1 Trace specific case study entities and linkages (Direct 1:1 join to rptledger, pre-aggregated slices)
SELECT 
    f.objid AS faasid,
    f.tdno,
    f.state AS faas_state,
    f.txntype_objid,
    f.prevtdno,
    f.canceldate,
    f.cancelledbytdnos,
    r.objid AS rpuid,
    r.rputype,
    r.previd AS rpu_previd,
    r.rpumasterid,
    r.taxable,
    r.totalav,
    r.totalmv,
    f.realpropertyid,
    rp.previd AS rp_previd,
    rl.objid AS ledger_id,
    rl.tdno AS ledger_tdno,
    rl.state AS ledger_state,
    COALESCE(rlf.total_slices, 0) AS rlf_slices,
    rlf.min_fromyear AS rlf_fromyear,
    rlf.max_toyear AS rlf_toyear
FROM faas f
JOIN rpu r ON f.rpuid = r.objid
JOIN realproperty rp ON f.realpropertyid = rp.objid
LEFT JOIN rptledger rl ON f.tdno = rl.tdno
LEFT JOIN (
    SELECT faasid, COUNT(objid) AS total_slices, MIN(fromyear) AS min_fromyear, MAX(toyear) AS max_toyear
    FROM rptledgerfaas
    GROUP BY faasid
) rlf ON f.objid = rlf.faasid
WHERE f.objid IN (
    'F-6defdc2a:18e5560fd65:-5377', -- EX-TR-01
    'Fd2d2dc1:185a34938e5:-4c48',   -- Predecessor to EX-TR-01
    'F-3618ea4e:1866d2fd03c:-5212', -- Mother lot for EX-SD-02
    'F1c487f07:1879300ce47:-2b1e', -- Child lot EX-SD-02
    'F-3c37d1fb:190c848694a:-475c', -- EX-CS-01
    'F-2cd0fe3c:1859914a4be:-6611', -- Predecessor 1 to EX-CS-01
    'F-2cd0fe3c:1859914a4be:-3181', -- Predecessor 2 to EX-CS-01
    'F-65f9b252:18b59c10494:-36b1', -- EX-CTD-01
    'F2711f584:18773251b9a:-79f7',  -- Predecessor to EX-CTD-01
    'F1228f0b9:1865db4f069:1b6d'   -- EX-GR-03 (Unresolved populated ID example)
)
ORDER BY f.objid;

-- 3.2 Verify predecessor links for representative case studies (Preserving individual status)
SELECT 
    f.objid AS faasid,
    f.tdno,
    f.state,
    fp.objid AS fp_objid,
    fp.prevtdno,
    fp.prevfaasid,
    CASE 
        WHEN fp.prevfaasid IS NULL THEN 'NULL ID (Recorded Reference)'
        WHEN pf.objid IS NOT NULL THEN 'Electronic (Resolved)'
        ELSE 'Unresolved (Populated ID Not Found)'
    END AS predecessor_resolution_status,
    pf.objid AS resolved_faasid,
    pf.state AS resolved_faas_state,
    pf.canceldate AS resolved_faas_canceldate,
    pf.cancelledbytdnos AS resolved_faas_cancelledbytdnos
FROM faas f
JOIN faas_previous fp ON f.objid = fp.faasid
LEFT JOIN faas pf ON fp.prevfaasid = pf.objid
WHERE f.objid IN (
    'F-6defdc2a:18e5560fd65:-5377', -- EX-TR-01
    'F1c487f07:1879300ce47:-2b1e', -- EX-SD-02
    'F-3c37d1fb:190c848694a:-475c', -- EX-CS-01 (Multi-predecessor)
    'F-3fab206:1774b73433e:-5182', -- EX-RE-02 (Multi-source recorded references)
    'F16542bcd:17741287a1b:-6353', -- EX-GR-02 (Multi-source recorded references)
    'F1228f0b9:1865db4f069:1b6d'   -- EX-GR-03 (Unresolved populated ID)
)
ORDER BY f.objid, fp.objid;
