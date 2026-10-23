-- ==============================================================================
-- ETRACS 2.54 / 2.55 — Phase 4.2: Corrected Integrity & Validation Queries
-- Database: etracs254_kitaotao | User: chatgpt_ro (READ-ONLY)
-- Audit Mode: STRICT READ-ONLY (SELECT queries only, zero mutations/repairs)
-- Execution Environment: MySQL on localhost
-- ==============================================================================

-- ------------------------------------------------------------------------------
-- WORKSTREAM 1: FAAS & Predecessor Lineage Validation
-- ------------------------------------------------------------------------------

-- Query 1.1A: Predecessor Link Modes in faas_previous (Independent Aggregates)
SELECT 
    COUNT(*) as total_links,
    SUM(CASE WHEN fp.prevfaasid IS NULL THEN 1 ELSE 0 END) as null_prevfaasid,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND f_prev.objid IS NOT NULL THEN 1 ELSE 0 END) as resolved_prevfaasid,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND f_prev.objid IS NULL THEN 1 ELSE 0 END) as unresolved_prevfaasid,
    SUM(CASE WHEN fp.prevrpuid IS NULL THEN 1 ELSE 0 END) as null_prevrpuid,
    SUM(CASE WHEN fp.prevrpuid IS NOT NULL AND r_prev.objid IS NOT NULL THEN 1 ELSE 0 END) as resolved_prevrpuid,
    SUM(CASE WHEN fp.prevrpuid IS NOT NULL AND r_prev.objid IS NULL THEN 1 ELSE 0 END) as unresolved_prevrpuid
FROM faas_previous fp
LEFT JOIN faas f_prev ON fp.prevfaasid = f_prev.objid
LEFT JOIN rpu r_prev ON fp.prevrpuid = r_prev.objid;

-- Query 1.1B: Joint Predecessor Resolution Cross-Tabulation (Phase 4.2 Requirement 1)
-- Tests whether prevfaasid and prevrpuid resolution statuses match row by row
SELECT 
    CASE 
        WHEN fp.prevfaasid IS NULL THEN 'prevfaasid IS NULL'
        WHEN f_prev.objid IS NOT NULL THEN 'prevfaasid RESOLVED'
        ELSE 'prevfaasid UNRESOLVED'
    END as faas_status,
    CASE 
        WHEN fp.prevrpuid IS NULL THEN 'prevrpuid IS NULL'
        WHEN r_prev.objid IS NOT NULL THEN 'prevrpuid RESOLVED'
        ELSE 'prevrpuid UNRESOLVED'
    END as rpu_status,
    COUNT(*) as link_count
FROM faas_previous fp
LEFT JOIN faas f_prev ON fp.prevfaasid = f_prev.objid
LEFT JOIN rpu r_prev ON fp.prevrpuid = r_prev.objid
GROUP BY faas_status, rpu_status
ORDER BY link_count DESC;

-- Query 1.2: Distinct FAAS Records by Predecessor Count (Branching & Baselines)
SELECT 
    pred_count,
    COUNT(*) as num_faas
FROM (
    SELECT f.objid, COUNT(fp.objid) as pred_count
    FROM faas f
    LEFT JOIN faas_previous fp ON f.objid = fp.faasid
    GROUP BY f.objid
) t
GROUP BY pred_count
ORDER BY pred_count;

-- Query 1.3: Self-Links, Bounded Cycle Detection, and Same-TD Guards
-- 1.3A: Direct Self-Links (faasid = prevfaasid)
SELECT fp.objid, fp.faasid, fp.prevfaasid, fp.prevtdno, f.tdno, f.txntype_objid, f.state
FROM faas_previous fp
JOIN faas f ON fp.faasid = f.objid
WHERE fp.faasid = fp.prevfaasid;

-- 1.3B: Same-TD Links (faas.tdno = faas_previous.prevtdno)
SELECT fp.objid, fp.faasid, fp.prevfaasid, fp.prevtdno, f.tdno, f.txntype_objid, f.state
FROM faas_previous fp
JOIN faas f ON fp.faasid = f.objid
WHERE f.tdno = fp.prevtdno AND fp.prevtdno IS NOT NULL AND fp.prevtdno != '';

-- 1.3C: Direct Two-Way Cycles (Excluding Self-Links)
-- Evaluates direct two-way cycles (A -> B and B -> A) where A != B
SELECT fp1.faasid as faas_a, fp1.prevfaasid as faas_b
FROM faas_previous fp1
JOIN faas_previous fp2 ON fp1.faasid = fp2.prevfaasid AND fp1.prevfaasid = fp2.faasid
WHERE fp1.prevfaasid IS NOT NULL 
  AND fp2.prevfaasid IS NOT NULL
  AND fp1.faasid != fp1.prevfaasid;

-- 1.3D: Bounded Three-Way Cycles (Excluding Self-Links)
-- Evaluates direct three-way cycles (A -> B -> C -> A)
SELECT fp1.faasid as faas_a, fp2.faasid as faas_b, fp3.faasid as faas_c
FROM faas_previous fp1
JOIN faas_previous fp2 ON fp1.prevfaasid = fp2.faasid
JOIN faas_previous fp3 ON fp2.prevfaasid = fp3.faasid
WHERE fp3.prevfaasid = fp1.faasid
  AND fp1.faasid != fp1.prevfaasid
  AND fp2.faasid != fp2.prevfaasid
  AND fp3.faasid != fp3.prevfaasid;

-- Query 1.4: Lineage Agreement: faas_previous vs rpu.previd
SELECT 
    COUNT(*) as total_resolved_links,
    SUM(CASE WHEN r.previd = fp.prevrpuid THEN 1 ELSE 0 END) as matching_rpu_previd,
    SUM(CASE WHEN r.previd IS NULL AND fp.prevrpuid IS NOT NULL THEN 1 ELSE 0 END) as rpu_previd_null,
    SUM(CASE WHEN r.previd IS NOT NULL AND fp.prevrpuid IS NOT NULL AND r.previd != fp.prevrpuid THEN 1 ELSE 0 END) as rpu_previd_mismatch
FROM faas_previous fp
JOIN faas f ON fp.faasid = f.objid
JOIN rpu r ON f.rpuid = r.objid
WHERE fp.prevrpuid IS NOT NULL;

-- Query 1.5: Land Spatial Lineage: faas_previous vs realproperty.previd
SELECT 
    COUNT(*) as total_resolved_land_links,
    SUM(CASE WHEN rp.previd = pf.realpropertyid THEN 1 ELSE 0 END) as land_previd_matches_pred_rp,
    SUM(CASE WHEN f.realpropertyid = pf.realpropertyid THEN 1 ELSE 0 END) as land_rp_reused,
    SUM(CASE WHEN rp.previd IS NULL AND f.realpropertyid != pf.realpropertyid THEN 1 ELSE 0 END) as land_previd_null_different_rp,
    SUM(CASE WHEN rp.previd IS NOT NULL AND rp.previd != pf.realpropertyid AND f.realpropertyid != pf.realpropertyid THEN 1 ELSE 0 END) as land_previd_mismatch
FROM faas_previous fp
JOIN faas f ON fp.faasid = f.objid
JOIN rpu r ON f.rpuid = r.objid
JOIN realproperty rp ON f.realpropertyid = rp.objid
JOIN faas pf ON fp.prevfaasid = pf.objid
WHERE r.rputype = 'land' AND fp.prevfaasid IS NOT NULL;

-- ------------------------------------------------------------------------------
-- WORKSTREAM 2: RPUMaster Population & Pointer Integrity (Phase 4.2 Refined)
-- ------------------------------------------------------------------------------

-- Query 2.0: Distinct Key & Cardinality Validation across FAAS, RPU, and RPUMaster
SELECT 
    COUNT(*) as total_faas_rows,
    COUNT(DISTINCT f.objid) as distinct_faas_objid,
    COUNT(DISTINCT f.rpuid) as distinct_faas_rpuid,
    COUNT(DISTINCT r.objid) as distinct_rpu_objid,
    COUNT(DISTINCT r.rpumasterid) as distinct_rpu_masterid,
    COUNT(DISTINCT rm.objid) as distinct_rm_objid
FROM faas f
LEFT JOIN rpu r ON f.rpuid = r.objid
LEFT JOIN rpumaster rm ON r.rpumasterid = rm.objid;

-- Query 2.1: Population & Four-Category Pointer Breakdown with NULL-Safe Operators
SELECT 
    f.state,
    COUNT(*) as total_faas,
    SUM(CASE WHEN r.objid IS NULL THEN 1 ELSE 0 END) as missing_rpu_rel,
    SUM(CASE WHEN r.objid IS NOT NULL AND rm.objid IS NULL THEN 1 ELSE 0 END) as missing_rm_rel,
    SUM(CASE WHEN (rm.currentfaasid <=> f.objid) AND (rm.currentrpuid <=> f.rpuid) THEN 1 ELSE 0 END) as both_match,
    SUM(CASE WHEN (rm.currentfaasid <=> f.objid) AND NOT (rm.currentrpuid <=> f.rpuid) THEN 1 ELSE 0 END) as only_faas_match,
    SUM(CASE WHEN NOT (rm.currentfaasid <=> f.objid) AND (rm.currentrpuid <=> f.rpuid) THEN 1 ELSE 0 END) as only_rpu_match,
    SUM(CASE WHEN r.objid IS NOT NULL AND rm.objid IS NOT NULL 
             AND NOT (rm.currentfaasid <=> f.objid) 
             AND NOT (rm.currentrpuid <=> f.rpuid) THEN 1 ELSE 0 END) as neither_match
FROM faas f
LEFT JOIN rpu r ON f.rpuid = r.objid
LEFT JOIN rpumaster rm ON r.rpumasterid = rm.objid
GROUP BY f.state;

-- Query 2.2: Refined Resolution of Pointers in the 87 CURRENT Pointer Mismatch Cases (Phase 4.2 Requirement 4)
-- Separates NULL pointers from populated unresolved pointers and evaluates FAAS & RPU targets independently
SELECT 
    CASE 
        WHEN rm.currentfaasid IS NULL THEN 'currentfaasid IS NULL'
        WHEN f_curr.objid IS NOT NULL THEN CONCAT('currentfaasid RESOLVED (', f_curr.state, ')')
        ELSE 'currentfaasid POPULATED UNRESOLVED'
    END as faas_pointer_status,
    CASE 
        WHEN rm.currentrpuid IS NULL THEN 'currentrpuid IS NULL'
        WHEN r_curr.objid IS NOT NULL THEN CONCAT('currentrpuid RESOLVED (', r_curr.state, ')')
        ELSE 'currentrpuid POPULATED UNRESOLVED'
    END as rpu_pointer_status,
    COUNT(*) as count_cases
FROM faas f
JOIN rpu r ON f.rpuid = r.objid
JOIN rpumaster rm ON r.rpumasterid = rm.objid
LEFT JOIN faas f_curr ON rm.currentfaasid = f_curr.objid
LEFT JOIN rpu r_curr ON rm.currentrpuid = r_curr.objid
WHERE f.state = 'CURRENT'
  AND NOT (rm.currentfaasid <=> f.objid)
  AND NOT (rm.currentrpuid <=> f.rpuid)
GROUP BY faas_pointer_status, rpu_pointer_status
ORDER BY count_cases DESC;

-- Query 2.3: Targeted Check of RPUs Without a FAAS Link (Reconciling 10,121 vs 10,123 Roots)
SELECT 
    r.objid as rpu_id,
    r.rpumasterid,
    rm.currentfaasid,
    rm.currentrpuid,
    r.rputype,
    r.state as rpu_state,
    f_curr.objid as resolved_curr_faas,
    r_curr.objid as resolved_curr_rpu
FROM rpu r
LEFT JOIN faas f ON f.rpuid = r.objid
LEFT JOIN rpumaster rm ON r.rpumasterid = rm.objid
LEFT JOIN faas f_curr ON rm.currentfaasid = f_curr.objid
LEFT JOIN rpu r_curr ON rm.currentrpuid = r_curr.objid
WHERE f.objid IS NULL;

-- Query 2.4: RPUMaster Reference-Coverage Breakdown Across Base Population (27,661 Rows)
-- 2.4A: Overall Reference Coverage by RPUs
SELECT 
    COUNT(*) as total_rpumaster_rows,
    SUM(CASE WHEN r.cnt > 0 THEN 1 ELSE 0 END) as referenced_by_any_rpu,
    SUM(CASE WHEN r.cnt IS NULL THEN 1 ELSE 0 END) as not_referenced_by_any_rpu
FROM rpumaster rm
LEFT JOIN (
    SELECT rpumasterid, COUNT(*) as cnt
    FROM rpu
    GROUP BY rpumasterid
) r ON rm.objid = r.rpumasterid;

-- 2.4B: Unreferenced Roots Pointer Resolution Breakdown (17,538 Roots)
SELECT 
    CASE 
        WHEN (rm.currentfaasid IS NULL OR TRIM(rm.currentfaasid) = '') 
             AND (rm.currentrpuid IS NULL OR TRIM(rm.currentrpuid) = '') THEN 'Both pointers NULL/blank'
        WHEN (rm.currentfaasid IS NOT NULL AND TRIM(rm.currentfaasid) != '') 
             AND (rm.currentrpuid IS NOT NULL AND TRIM(rm.currentrpuid) != '') 
             AND f.objid IS NULL AND r_ptr.objid IS NULL THEN 'Both populated targets unresolved'
        ELSE 'Other mixed'
    END as unreferenced_pointer_category,
    COUNT(*) as root_count
FROM rpumaster rm
LEFT JOIN (
    SELECT rpumasterid, COUNT(*) as cnt
    FROM rpu
    GROUP BY rpumasterid
) r ON rm.objid = r.rpumasterid
LEFT JOIN faas f ON rm.currentfaasid = f.objid
LEFT JOIN rpu r_ptr ON rm.currentrpuid = r_ptr.objid
WHERE r.cnt IS NULL
GROUP BY unreferenced_pointer_category;

-- 2.4C: Referenced Roots Pointer Resolution Breakdown (10,123 Roots)
SELECT 
    CASE 
        WHEN f.objid IS NOT NULL AND r_ptr.objid IS NOT NULL THEN 'Both pointer targets resolving'
        WHEN (rm.currentfaasid IS NOT NULL AND TRIM(rm.currentfaasid) != '') 
             AND (rm.currentrpuid IS NOT NULL AND TRIM(rm.currentrpuid) != '') 
             AND f.objid IS NULL AND r_ptr.objid IS NULL THEN 'Both populated targets unresolved'
        WHEN (rm.currentfaasid IS NULL OR TRIM(rm.currentfaasid) = '') 
             AND (rm.currentrpuid IS NULL OR TRIM(rm.currentrpuid) = '') THEN 'Both pointers NULL/blank'
        WHEN f.objid IS NULL AND r_ptr.objid IS NOT NULL THEN 'Unresolved FAAS pointer, resolvable RPU pointer'
        WHEN f.objid IS NOT NULL AND r_ptr.objid IS NULL THEN 'Resolvable FAAS pointer, unresolved RPU pointer'
        ELSE 'Other mixed'
    END as referenced_pointer_category,
    COUNT(*) as root_count
FROM rpumaster rm
JOIN (
    SELECT DISTINCT rpumasterid
    FROM rpu
    WHERE rpumasterid IS NOT NULL
) r ON rm.objid = r.rpumasterid
LEFT JOIN faas f ON rm.currentfaasid = f.objid
LEFT JOIN rpu r_ptr ON rm.currentrpuid = r_ptr.objid
GROUP BY referenced_pointer_category
ORDER BY root_count DESC;

-- ------------------------------------------------------------------------------
-- WORKSTREAM 3: Cancellation & Successor Consistency
-- ------------------------------------------------------------------------------

-- Query 3.1: Delimiter-Aware Cancellation Metrics Across Resolved Links
SELECT 
    COUNT(*) as total_resolved_links,
    SUM(CASE WHEN pf.state = 'CANCELLED' THEN 1 ELSE 0 END) as pred_cancelled,
    SUM(CASE WHEN pf.state != 'CANCELLED' THEN 1 ELSE 0 END) as pred_not_cancelled,
    SUM(CASE WHEN pf.canceldate IS NOT NULL THEN 1 ELSE 0 END) as canceldate_not_null,
    SUM(CASE WHEN pf.canceldate IS NULL THEN 1 ELSE 0 END) as canceldate_null,
    SUM(CASE WHEN FIND_IN_SET(TRIM(f.tdno), REPLACE(pf.cancelledbytdnos, ' ', '')) > 0 THEN 1 ELSE 0 END) as td_matched_in_cancelledby,
    SUM(CASE WHEN pf.state = 'CANCELLED' AND pf.canceldate IS NOT NULL AND FIND_IN_SET(TRIM(f.tdno), REPLACE(pf.cancelledbytdnos, ' ', '')) > 0 THEN 1 ELSE 0 END) as all_three_match
FROM faas_previous fp
JOIN faas f ON fp.faasid = f.objid
JOIN faas pf ON fp.prevfaasid = pf.objid
WHERE fp.prevfaasid IS NOT NULL;

-- Query 3.2: Breakdown of Predecessor Links by Predecessor and Successor States
SELECT 
    pf.state as predecessor_state,
    f.state as successor_state,
    COUNT(*) as link_count,
    COUNT(DISTINCT pf.objid) as distinct_predecessor_count,
    COUNT(DISTINCT f.objid) as distinct_successor_count
FROM faas_previous fp
JOIN faas f ON fp.faasid = f.objid
JOIN faas pf ON fp.prevfaasid = pf.objid
GROUP BY pf.state, f.state;

-- Query 3.3: Refined Missing Successor Reference Analysis (Phase 4.2 Requirement 6)
-- Denominator: All predecessor links where predecessor FAAS state is CANCELLED (9,199 links)
SELECT 
    COUNT(*) as missing_successor_reference_links,
    COUNT(DISTINCT pf.objid) as distinct_pred_faas,
    COUNT(DISTINCT f.objid) as distinct_succ_faas,
    SUM(CASE WHEN f.tdno IS NULL OR TRIM(f.tdno) = '' THEN 1 ELSE 0 END) as comp1_succ_td_null_or_blank,
    SUM(CASE WHEN f.tdno IS NOT NULL AND TRIM(f.tdno) != '' AND (pf.cancelledbytdnos IS NULL OR TRIM(pf.cancelledbytdnos) = '') THEN 1 ELSE 0 END) as comp2_cancelledby_null_or_blank,
    SUM(CASE WHEN f.tdno IS NOT NULL AND TRIM(f.tdno) != '' AND pf.cancelledbytdnos IS NOT NULL AND TRIM(pf.cancelledbytdnos) != '' 
             AND FIND_IN_SET(TRIM(f.tdno), REPLACE(pf.cancelledbytdnos, ' ', '')) = 0 THEN 1 ELSE 0 END) as comp3_cancelledby_populated_token_missing
FROM faas_previous fp
JOIN faas pf ON fp.prevfaasid = pf.objid
JOIN faas f ON fp.faasid = f.objid
WHERE pf.state = 'CANCELLED'
  AND (
      f.tdno IS NULL 
      OR TRIM(f.tdno) = ''
      OR pf.cancelledbytdnos IS NULL 
      OR TRIM(pf.cancelledbytdnos) = ''
      OR FIND_IN_SET(TRIM(f.tdno), REPLACE(pf.cancelledbytdnos, ' ', '')) = 0
  );

-- ------------------------------------------------------------------------------
-- WORKSTREAM 4: Assessment & Treasury Ledger Integrity
-- ------------------------------------------------------------------------------

-- Query 4.1: Assessment Line Integrity & Orphan Checks
SELECT 
    COUNT(*) as total_assessment_lines,
    COUNT(DISTINCT a.objid) as distinct_line_objids,
    COUNT(DISTINCT a.rpuid) as distinct_rpuid_in_assessment,
    SUM(CASE WHEN r.objid IS NULL THEN 1 ELSE 0 END) as orphan_assessment_lines,
    SUM(CASE WHEN a.assessedvalue = 0 THEN 1 ELSE 0 END) as zero_av_lines,
    SUM(CASE WHEN a.marketvalue = 0 THEN 1 ELSE 0 END) as zero_mv_lines,
    SUM(CASE WHEN a.taxable = 0 THEN 1 ELSE 0 END) as exempt_assessment_lines
FROM rpu_assessment a
LEFT JOIN rpu r ON a.rpuid = r.objid;

-- Query 4.2: Completeness Check — RPUs Without Assessment Lines
SELECT 
    r.objid as rpuid, r.rputype, r.state as rpu_state, 
    f.objid as faasid, f.tdno, f.txntype_objid, f.state as faas_state
FROM rpu r
LEFT JOIN faas f ON f.rpuid = r.objid
LEFT JOIN rpu_assessment a ON r.objid = a.rpuid
WHERE a.objid IS NULL;

-- Query 4.3: Value Consistency — Evaluated ONLY for RPUs with >= 1 Assessment Line (Phase 4.2 Rewritten)
-- Separately tests NULL totalav, NULL aggregate AV, exact matches, and differences
SELECT 
    CASE 
        WHEN r.totalav IS NULL AND sum_a.av IS NULL THEN 'Both totalav and sum_a.av ARE NULL'
        WHEN r.totalav IS NULL AND sum_a.av IS NOT NULL THEN 'r.totalav IS NULL, sum_a.av NOT NULL'
        WHEN r.totalav IS NOT NULL AND sum_a.av IS NULL THEN 'r.totalav NOT NULL, sum_a.av IS NULL'
        WHEN ABS(r.totalav - sum_a.av) <= 0.01 THEN 'Exact Match (<= 0.01)'
        WHEN ABS(r.totalav - sum_a.av) < 10 THEN 'Difference < 10'
        ELSE 'Difference >= 10'
    END as av_category,
    COUNT(*) as rpu_count,
    MIN(ABS(r.totalav - sum_a.av)) as min_diff,
    MAX(ABS(r.totalav - sum_a.av)) as max_diff,
    AVG(ABS(r.totalav - sum_a.av)) as avg_diff
FROM rpu r
JOIN (
    SELECT rpuid, SUM(assessedvalue) as av
    FROM rpu_assessment
    GROUP BY rpuid
) sum_a ON r.objid = sum_a.rpuid
GROUP BY av_category;

-- Query 4.4: Mutually Exclusive Treasury Slice Reconciliation
-- Uses EXISTS subqueries to prevent join multiplication and independently validates base count
SELECT 
    COUNT(*) as total_base_rows,
    SUM(CASE 
        WHEN rlf.faasid IS NOT NULL AND TRIM(rlf.faasid) != '' 
             AND EXISTS (SELECT 1 FROM faas f WHERE f.objid = rlf.faasid) THEN 1 
        ELSE 0 
    END) as cat1_populated_resolving_faasid,
    SUM(CASE 
        WHEN rlf.faasid IS NOT NULL AND TRIM(rlf.faasid) != '' 
             AND NOT EXISTS (SELECT 1 FROM faas f WHERE f.objid = rlf.faasid) THEN 1 
        ELSE 0 
    END) as cat2_populated_unresolved_faasid,
    SUM(CASE 
        WHEN (rlf.faasid IS NULL OR TRIM(rlf.faasid) = '') 
             AND rlf.tdno IS NOT NULL AND TRIM(rlf.tdno) != ''
             AND EXISTS (SELECT 1 FROM faas f WHERE f.tdno = rlf.tdno) THEN 1 
        ELSE 0 
    END) as cat3_null_blank_matching_td,
    SUM(CASE 
        WHEN (rlf.faasid IS NULL OR TRIM(rlf.faasid) = '') 
             AND (rlf.tdno IS NULL OR TRIM(rlf.tdno) = '' OR NOT EXISTS (SELECT 1 FROM faas f WHERE f.tdno = rlf.tdno)) THEN 1 
        ELSE 0 
    END) as cat4_null_blank_nonmatching_td
FROM rptledgerfaas rlf;

-- Query 4.5A: FAAS IDs Associated with Multiple Distinct Ledger IDs (Phase 4.2 Refined)
SELECT rlf.faasid, COUNT(DISTINCT rlf.rptledgerid) as num_distinct_ledgers
FROM rptledgerfaas rlf
WHERE rlf.faasid IS NOT NULL AND TRIM(rlf.faasid) != ''
GROUP BY rlf.faasid
HAVING num_distinct_ledgers > 1;

-- Query 4.5B: Repeated (rptledgerid, faasid) Pairs Breakdown (Phase 4.2 Requirement 8)
SELECT 
    COUNT(*) as total_repeated_pairs,
    SUM(row_count) as total_rows_in_repeated_pairs,
    SUM(row_count - 1) as excess_rows_above_one
FROM (
    SELECT rlf.rptledgerid, rlf.faasid, COUNT(*) as row_count
    FROM rptledgerfaas rlf
    WHERE rlf.faasid IS NOT NULL AND TRIM(rlf.faasid) != ''
    GROUP BY rlf.rptledgerid, rlf.faasid
    HAVING row_count > 1
) t;

-- Query 4.5C: Temporal Bounds Inspection of Repeated Pairs (Years and Quarters)
SELECT 
    COUNT(*) as total_repeated_pairs,
    SUM(CASE WHEN min_fromyear != max_fromyear OR min_toyear != max_toyear THEN 1 ELSE 0 END) as pairs_distinct_year_spans,
    SUM(CASE WHEN min_fromyear = max_fromyear AND min_toyear = max_toyear 
             AND (min_fromqtr != max_fromqtr OR min_toqtr != max_toqtr) THEN 1 ELSE 0 END) as pairs_same_years_distinct_qtrs,
    SUM(CASE WHEN min_fromyear = max_fromyear AND min_toyear = max_toyear 
             AND min_fromqtr = max_fromqtr AND min_toqtr = max_toqtr THEN 1 ELSE 0 END) as pairs_identical_year_qtr_bounds
FROM (
    SELECT 
        rlf.rptledgerid, rlf.faasid, COUNT(*) as row_count,
        MIN(rlf.fromyear) as min_fromyear, MAX(rlf.fromyear) as max_fromyear,
        MIN(rlf.toyear) as min_toyear, MAX(rlf.toyear) as max_toyear,
        MIN(rlf.fromqtr) as min_fromqtr, MAX(rlf.fromqtr) as max_fromqtr,
        MIN(rlf.toqtr) as min_toqtr, MAX(rlf.toqtr) as max_toqtr
    FROM rptledgerfaas rlf
    WHERE rlf.faasid IS NOT NULL AND TRIM(rlf.faasid) != ''
    GROUP BY rlf.rptledgerid, rlf.faasid
    HAVING row_count > 1
) t;
