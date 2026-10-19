-- ==============================================================================
-- ETRACS 2.54 DATABASE AUDIT: PHASE 2 VERIFIED TRANSACTION-TYPE MATRIX QUERIES
-- Database: etracs254_kitaotao | User: chatgpt_ro (READ-ONLY)
-- Execution Environment: MySQL on localhost
-- Audit Timestamp: 2026-10-12
--
-- Description: Standalone SQL script containing all SELECT queries executed
-- to establish the transaction type inventory, analyze lineage and lifecycle
-- patterns, audit operational tables, and trace multi-predecessor genealogies.
-- ==============================================================================

-- ------------------------------------------------------------------------------
-- PHASE 1: TRANSACTION INVENTORY & CONFIGURATION VERIFICATION
-- ------------------------------------------------------------------------------

-- 1.1 Retrieve configured transaction types and configuration flags
SELECT 
    objid AS txntype_code,
    name,
    newledger,
    newrpu,
    newrealproperty,
    allowEditOwner,
    checkbalance,
    allowEditPin,
    allowEditPinInfo,
    allowEditAppraisal,
    opener,
    reconcileledger,
    allowannotated
FROM faas_txntype
ORDER BY objid;

-- 1.2 Total FAAS count per transaction type and state breakdown
SELECT 
    f.txntype_objid,
    COUNT(*) AS total_faas,
    SUM(CASE WHEN f.state = "CURRENT" THEN 1 ELSE 0 END) AS count_current,
    SUM(CASE WHEN f.state = "CANCELLED" THEN 1 ELSE 0 END) AS count_cancelled,
    SUM(CASE WHEN f.state = "INTERIM" THEN 1 ELSE 0 END) AS count_interim,
    SUM(CASE WHEN f.state = "FORAPPROVAL" THEN 1 ELSE 0 END) AS count_forapproval,
    SUM(CASE WHEN f.state = "PENDING" THEN 1 ELSE 0 END) AS count_pending
FROM faas f
GROUP BY f.txntype_objid
ORDER BY total_faas DESC;

-- 1.3 Audit populated vs missing TD, RPU, and RealProperty references
SELECT 
    f.txntype_objid,
    COUNT(*) AS total,
    SUM(CASE WHEN f.tdno IS NOT NULL AND f.tdno != "" THEN 1 ELSE 0 END) AS populated_tdno,
    SUM(CASE WHEN f.tdno IS NULL OR f.tdno = "" THEN 1 ELSE 0 END) AS missing_tdno,
    SUM(CASE WHEN f.rpuid IS NOT NULL AND f.rpuid != "" THEN 1 ELSE 0 END) AS populated_rpuid,
    SUM(CASE WHEN f.rpuid IS NULL OR f.rpuid = "" THEN 1 ELSE 0 END) AS missing_rpuid,
    SUM(CASE WHEN f.realpropertyid IS NOT NULL AND f.realpropertyid != "" THEN 1 ELSE 0 END) AS populated_realpropertyid,
    SUM(CASE WHEN f.realpropertyid IS NULL OR f.realpropertyid = "" THEN 1 ELSE 0 END) AS missing_realpropertyid
FROM faas f
GROUP BY f.txntype_objid
ORDER BY total DESC;


-- ------------------------------------------------------------------------------
-- PHASE 2: CORE LINEAGE & LIFECYCLE RELATIONSHIP QUERIES
-- ------------------------------------------------------------------------------

-- 2.1 RPU Lineage and rpumaster Pointer Verification (by Transaction Code)
SELECT 
    f.txntype_objid,
    COUNT(*) AS total,
    SUM(CASE WHEN r.previd IS NOT NULL AND r.previd != "" THEN 1 ELSE 0 END) AS has_rpu_previd,
    SUM(CASE WHEN r.previd IS NOT NULL AND r_prev.objid IS NOT NULL THEN 1 ELSE 0 END) AS rpu_previd_resolved,
    SUM(CASE WHEN r.rpumasterid IS NOT NULL AND r.rpumasterid != "" THEN 1 ELSE 0 END) AS has_rpumaster,
    SUM(CASE WHEN rm.currentfaasid = f.objid THEN 1 ELSE 0 END) AS rpumaster_points_to_faas,
    SUM(CASE WHEN rm.currentrpuid = r.objid THEN 1 ELSE 0 END) AS rpumaster_points_to_rpu
FROM faas f
JOIN rpu r ON f.rpuid = r.objid
LEFT JOIN rpu r_prev ON r.previd = r_prev.objid
LEFT JOIN rpumaster rm ON r.rpumasterid = rm.objid
GROUP BY f.txntype_objid;

-- 2.2 RealProperty Continuity and Previous Pointer Verification
SELECT 
    f.txntype_objid,
    SUM(CASE WHEN rp.previd IS NOT NULL AND rp.previd != "" THEN 1 ELSE 0 END) AS has_rp_previd,
    SUM(CASE WHEN rp.previd IS NOT NULL AND rp_prev.objid IS NOT NULL THEN 1 ELSE 0 END) AS rp_previd_resolved
FROM faas f
JOIN realproperty rp ON f.realpropertyid = rp.objid
LEFT JOIN realproperty rp_prev ON rp.previd = rp_prev.objid
GROUP BY f.txntype_objid;

-- 2.3 faas_previous Multiplicity Distribution (0, 1, or Multi Predecessors)
SELECT 
    t.txntype_objid,
    COUNT(*) AS total_faas,
    SUM(CASE WHEN prev_cnt = 0 THEN 1 ELSE 0 END) AS faas_prev_0,
    SUM(CASE WHEN prev_cnt = 1 THEN 1 ELSE 0 END) AS faas_prev_1,
    SUM(CASE WHEN prev_cnt > 1 THEN 1 ELSE 0 END) AS faas_prev_multi,
    MAX(prev_cnt) AS max_prev_cnt
FROM (
    SELECT f.objid, f.txntype_objid, COUNT(fp.objid) AS prev_cnt
    FROM faas f
    LEFT JOIN faas_previous fp ON f.objid = fp.faasid
    GROUP BY f.objid, f.txntype_objid
) t
GROUP BY t.txntype_objid
ORDER BY total_faas DESC;

-- 2.4 faas_previous Row-Level Resolution: Detailed Predecessor Classification
-- Note: prevtdno is treated as a recorded historical reference (including physical tax book records),
-- not automatic proof of an electronic FAAS record.
SELECT 
    f.txntype_objid,
    COUNT(fp.objid) AS total_fp_rows,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND prev_f.objid IS NOT NULL THEN 1 ELSE 0 END) AS fp_prevfaasid_resolves,
    SUM(CASE WHEN fp.prevfaasid IS NULL THEN 1 ELSE 0 END) AS fp_prevfaasid_null,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND prev_f.objid IS NULL THEN 1 ELSE 0 END) AS fp_prevfaasid_unresolved,
    SUM(CASE WHEN fp.prevrpuid IS NOT NULL AND prev_r.objid IS NOT NULL THEN 1 ELSE 0 END) AS fp_prevrpuid_resolves,
    SUM(CASE WHEN fp.prevrpuid IS NULL THEN 1 ELSE 0 END) AS fp_prevrpuid_null,
    SUM(CASE WHEN fp.prevrpuid IS NOT NULL AND prev_r.objid IS NULL THEN 1 ELSE 0 END) AS fp_prevrpuid_unresolved,
    SUM(CASE WHEN fp.prevfaasid IS NOT NULL AND prev_f.objid IS NOT NULL AND fp.prevrpuid IS NOT NULL AND prev_r.objid IS NOT NULL THEN 1 ELSE 0 END) AS fp_both_faas_rpu_resolve,
    SUM(CASE WHEN fp.prevtdno IS NOT NULL AND fp.prevtdno != "" THEN 1 ELSE 0 END) AS fp_has_prevtdno,
    SUM(CASE WHEN fp.prevav IS NOT NULL THEN 1 ELSE 0 END) AS fp_has_prevav,
    SUM(CASE WHEN fp.prevmv IS NOT NULL THEN 1 ELSE 0 END) AS fp_has_prevmv
FROM faas f
JOIN faas_previous fp ON f.objid = fp.faasid
LEFT JOIN faas prev_f ON fp.prevfaasid = prev_f.objid
LEFT JOIN rpu prev_r ON fp.prevrpuid = prev_r.objid
GROUP BY f.txntype_objid
ORDER BY total_fp_rows DESC;

-- 2.5 Predecessor Cancellation State and Delimiter-Aware Metadata Consistency
-- Uses FIND_IN_SET on cleaned comma-separated list to prevent loose substring false-positives
SELECT 
    f.txntype_objid,
    COUNT(fp.objid) AS total_fp_rows,
    SUM(CASE WHEN prev_f.state = "CANCELLED" THEN 1 ELSE 0 END) AS pred_state_cancelled,
    SUM(CASE WHEN prev_f.state = "CURRENT" THEN 1 ELSE 0 END) AS pred_state_current,
    SUM(CASE WHEN prev_f.canceldate IS NOT NULL THEN 1 ELSE 0 END) AS pred_has_canceldate,
    SUM(CASE WHEN FIND_IN_SET(TRIM(f.tdno), REPLACE(prev_f.cancelledbytdnos, " ", "")) > 0 THEN 1 ELSE 0 END) AS pred_cancelledby_matches_td,
    SUM(CASE WHEN prev_f.state = "CANCELLED" AND prev_f.canceldate IS NOT NULL AND FIND_IN_SET(TRIM(f.tdno), REPLACE(prev_f.cancelledbytdnos, " ", "")) > 0 THEN 1 ELSE 0 END) AS pred_all_three_conditions
FROM faas f
JOIN faas_previous fp ON f.objid = fp.faasid
JOIN faas prev_f ON fp.prevfaasid = prev_f.objid
GROUP BY f.txntype_objid
ORDER BY total_fp_rows DESC;

-- 2.6 Treasury Linkage: Non-Multiplying rptledger and rptledgerfaas Associations
SELECT 
    f.txntype_objid,
    COUNT(DISTINCT f.objid) AS total_faas_records,
    COUNT(DISTINCT CASE WHEN rl.objid IS NOT NULL THEN f.objid ELSE NULL END) AS faas_with_rptledger,
    COUNT(DISTINCT CASE WHEN rlf.objid IS NOT NULL THEN f.objid ELSE NULL END) AS faas_with_rptledgerfaas,
    COUNT(rlf.objid) AS total_rptledgerfaas_slices
FROM faas f
LEFT JOIN rptledger rl ON f.tdno = rl.tdno
LEFT JOIN rptledgerfaas rlf ON f.objid = rlf.faasid
GROUP BY f.txntype_objid
ORDER BY total_faas_records DESC;

-- 2.7 Assessment Continuity: Non-Multiplying rpu_assessment Item Metrics
SELECT 
    f.txntype_objid,
    COUNT(DISTINCT f.objid) AS total_faas,
    COUNT(DISTINCT r.objid) AS total_rpu,
    COUNT(DISTINCT a.objid) AS total_current_assessment_lines,
    COUNT(DISTINCT CASE WHEN a.objid IS NOT NULL THEN f.objid ELSE NULL END) AS faas_with_current_assessment,
    COUNT(DISTINCT CASE WHEN r.previd IS NOT NULL AND a_prev.objid IS NOT NULL THEN f.objid ELSE NULL END) AS faas_with_prev_assessment_lines,
    COUNT(DISTINCT a_prev.objid) AS total_prev_assessment_lines
FROM faas f
JOIN rpu r ON f.rpuid = r.objid
LEFT JOIN rpu_assessment a ON r.objid = a.rpuid
LEFT JOIN rpu_assessment a_prev ON r.previd = a_prev.rpuid
GROUP BY f.txntype_objid
ORDER BY total_faas DESC;

-- 2.8 Change Audit Trail: Complete rpt_changeinfo Audit (Matched vs Unresolved)
SELECT 
    CASE WHEN f.objid IS NOT NULL THEN f.txntype_objid ELSE "UNRESOLVED_FAASID" END AS txntype_objid,
    COUNT(ci.objid) AS changeinfo_count, 
    COUNT(DISTINCT ci.faasid) AS distinct_faas_ids
FROM rpt_changeinfo ci
LEFT JOIN faas f ON ci.faasid = f.objid
GROUP BY CASE WHEN f.objid IS NOT NULL THEN f.txntype_objid ELSE "UNRESOLVED_FAASID" END
ORDER BY changeinfo_count DESC;

-- 2.9 Routing Workflow: faas_task State Distribution by Transaction Type
SELECT 
    f.txntype_objid, 
    ft.state, 
    COUNT(*) AS cnt
FROM faas_task ft
JOIN faas f ON ft.refid = f.objid
GROUP BY f.txntype_objid, ft.state
ORDER BY f.txntype_objid, cnt DESC;


-- ------------------------------------------------------------------------------
-- PHASE 3: CANDIDATE TRANSACTION-SPECIFIC OPERATIONAL TABLES
-- ------------------------------------------------------------------------------

-- 3.1 Exact row count check across candidate tables
SELECT "subdivision" AS tbl, COUNT(*) AS cnt FROM subdivision
UNION ALL SELECT "subdivision_task", COUNT(*) FROM subdivision_task
UNION ALL SELECT "subdividedland", COUNT(*) FROM subdividedland
UNION ALL SELECT "subdivision_motherland", COUNT(*) FROM subdivision_motherland
UNION ALL SELECT "subdivisionaffectedrpu", COUNT(*) FROM subdivisionaffectedrpu
UNION ALL SELECT "subdivision_assist", COUNT(*) FROM subdivision_assist
UNION ALL SELECT "subdivision_assist_item", COUNT(*) FROM subdivision_assist_item
UNION ALL SELECT "subdivision_cancelledimprovement", COUNT(*) FROM subdivision_cancelledimprovement
UNION ALL SELECT "consolidation", COUNT(*) FROM consolidation
UNION ALL SELECT "consolidation_task", COUNT(*) FROM consolidation_task
UNION ALL SELECT "consolidatedland", COUNT(*) FROM consolidatedland
UNION ALL SELECT "consolidationaffectedrpu", COUNT(*) FROM consolidationaffectedrpu
UNION ALL SELECT "mcsettlement", COUNT(*) FROM mcsettlement
UNION ALL SELECT "mcsettlement_affectedrpu", COUNT(*) FROM mcsettlement_affectedrpu
UNION ALL SELECT "mcsettlement_otherclaim", COUNT(*) FROM mcsettlement_otherclaim
UNION ALL SELECT "faasupdate", COUNT(*) FROM faasupdate
UNION ALL SELECT "faas_affectedrpu", COUNT(*) FROM faas_affectedrpu
UNION ALL SELECT "faasbacktax", COUNT(*) FROM faasbacktax
UNION ALL SELECT "faas_restriction", COUNT(*) FROM faas_restriction
UNION ALL SELECT "faas_stewardship", COUNT(*) FROM faas_stewardship
UNION ALL SELECT "cancelledfaas", COUNT(*) FROM cancelledfaas
UNION ALL SELECT "cancelledfaas_task", COUNT(*) FROM cancelledfaas_task
UNION ALL SELECT "cancelledfaas_signatory", COUNT(*) FROM cancelledfaas_signatory
UNION ALL SELECT "canceltdreason", COUNT(*) FROM canceltdreason
UNION ALL SELECT "rpt_changeinfo", COUNT(*) FROM rpt_changeinfo
UNION ALL SELECT "batchgr", COUNT(*) FROM batchgr
UNION ALL SELECT "batchgr_item", COUNT(*) FROM batchgr_item
UNION ALL SELECT "batchgr_task", COUNT(*) FROM batchgr_task;

-- 3.2 Detailed inspection of populated operational tables
-- Inspect subdivision draft records
SELECT objid, state, ry, txntype_objid, txnno, txndate, mothertdnos, motherfaasid 
FROM subdivision;

-- Inspect consolidation draft record
SELECT objid, state, txnno, txndate, ry, newfaasid, newrpid, newrpuid, memoranda 
FROM consolidation;

-- Inspect cancelledfaas records
SELECT cf.objid, cf.state, cf.txnno, cf.txndate, cf.faasid, cf.reason_objid, r.name AS reason_name
FROM cancelledfaas cf
LEFT JOIN canceltdreason r ON cf.reason_objid = r.objid;


-- ------------------------------------------------------------------------------
-- PHASE 4: REPRESENTATIVE RECORD EXTRACTION FOR ALL 17 ACTIVE TYPES
-- ------------------------------------------------------------------------------

-- Query to retrieve deterministic representative record for a given transaction type
-- (Substitute "CS" with GR, ND, RE, SD, TR, CE, DC, CC, RV, CS/SD, TRC, RS, CTD, MC, TRE, DT)
SELECT 
    f.objid AS faasid,
    f.state AS faas_state,
    f.tdno,
    f.utdno,
    f.txntype_objid,
    f.prevtdno,
    f.canceldate,
    f.cancelreason,
    f.cancelledbytdnos,
    f.rpuid,
    r.previd AS rpu_previd,
    r.rpumasterid,
    r.taxable,
    r.totalmv,
    r.totalav,
    f.realpropertyid,
    rp.previd AS rp_previd,
    rp.cadastrallotno,
    rl.objid AS ledger_id,
    rl.tdno AS ledger_tdno,
    rlf.objid AS rlf_id
FROM faas f
JOIN rpu r ON f.rpuid = r.objid
JOIN realproperty rp ON f.realpropertyid = rp.objid
LEFT JOIN rptledger rl ON f.tdno = rl.tdno
LEFT JOIN rptledgerfaas rlf ON f.objid = rlf.faasid
WHERE f.txntype_objid = "CS"
ORDER BY (CASE WHEN f.state = "CURRENT" THEN 0 ELSE 1 END), f.tdno DESC
LIMIT 1;


-- ------------------------------------------------------------------------------
-- PHASE 5: VERIFIED TRANSACTION LINEAGE TRACES
-- ------------------------------------------------------------------------------

-- 5.1 Case Study 1: Reassessment -> General Revision Multi-Source Genealogy
-- TD 10-009-07925 & 10-009-08967 -> 10-009-10027 -> 22-010-0009-00018
SELECT 
    f.objid AS faasid, 
    f.state AS faas_state, 
    f.tdno, 
    f.utdno, 
    f.txntype_objid,
    f.prevtdno, 
    f.canceldate, 
    f.cancelreason, 
    f.cancelledbytdnos,
    f.rpuid, 
    r.previd AS rpu_previd, 
    r.rpumasterid, 
    r.taxable, 
    r.totalmv, 
    r.totalav,
    f.realpropertyid, 
    rp.previd AS rp_previd, 
    rp.cadastrallotno,
    rl.objid AS ledger_id, 
    rl.state AS ledger_state, 
    rl.tdno AS ledger_tdno,
    rlf.objid AS rlf_id
FROM faas f
JOIN rpu r ON f.rpuid = r.objid
JOIN realproperty rp ON f.realpropertyid = rp.objid
LEFT JOIN rptledger rl ON f.tdno = rl.tdno
LEFT JOIN rptledgerfaas rlf ON f.objid = rlf.faasid
WHERE f.tdno IN ("10-009-07925", "10-009-08967", "10-009-10027", "22-010-0009-00018");

-- Inspect faas_previous linkage for Case Study 1
SELECT 
    fp.objid, 
    fp.faasid, 
    fp.prevfaasid, 
    fp.prevrpuid, 
    fp.prevtdno, 
    fp.prevpin, 
    fp.prevowner, 
    fp.prevav, 
    fp.prevmv, 
    fp.preveffectivity
FROM faas_previous fp
WHERE fp.faasid IN ("F-3fab206:1774b73433e:-5182", "F-2961fcfe:186902118ba:59f")
   OR fp.prevfaasid IN ("F-3fab206:1774b73433e:-5182", "F-2961fcfe:186902118ba:59f");

-- Inspect ledger continuity across Case Study 1
SELECT * 
FROM rptledger 
WHERE objid = "F-3fab206:1774b73433e:-5182" 
   OR tdno IN ("10-009-10027", "22-010-0009-00018");

SELECT * 
FROM rptledgerfaas 
WHERE faasid IN ("F-3fab206:1774b73433e:-5182", "F-2961fcfe:186902118ba:59f");

-- 5.2 Case Study 2: Pure Consolidation (CS) Lineage
-- TD 22-010-0001-00316 & 22-010-0001-00318 -> 22-010-0001-05825
SELECT 
    f.objid AS faasid, 
    f.state AS faas_state, 
    f.tdno, 
    f.txntype_objid,
    f.prevtdno, 
    f.canceldate, 
    f.cancelreason, 
    f.cancelledbytdnos,
    f.rpuid, 
    r.previd AS rpu_previd, 
    r.rpumasterid, 
    r.totalav, 
    r.totalmv,
    f.realpropertyid, 
    rp.cadastrallotno,
    rl.objid AS ledger_id, 
    rl.tdno AS ledger_tdno,
    rlf.objid AS rlf_id
FROM faas f
JOIN rpu r ON f.rpuid = r.objid
JOIN realproperty rp ON f.realpropertyid = rp.objid
LEFT JOIN rptledger rl ON f.tdno = rl.tdno
LEFT JOIN rptledgerfaas rlf ON f.objid = rlf.faasid
WHERE f.tdno IN ("22-010-0001-05825", "22-010-0001-00316", "22-010-0001-00318");

-- Inspect faas_previous for Case Study 2
SELECT 
    fp.objid, 
    fp.faasid, 
    fp.prevfaasid, 
    fp.prevrpuid, 
    fp.prevtdno, 
    fp.prevpin, 
    fp.prevowner, 
    fp.prevav, 
    fp.prevmv, 
    fp.preveffectivity
FROM faas_previous fp
WHERE fp.faasid = "F-3c37d1fb:190c848694a:-475c";

-- Inspect machinery component breakdown and assessment lines for Case Study 2
-- Predecessors sum: 35,210.00 + 70,100.00 = 105,310.00; Consolidated: 105,300.00 (PHP 10.00 difference)
SELECT 
    f.tdno,
    f.txntype_objid,
    r.objid AS rpuid,
    r.rputype,
    r.totalmv,
    r.totalav,
    a.objid AS assess_id,
    a.marketvalue AS assess_mv,
    a.assesslevel AS assess_level,
    a.assessedvalue AS assess_av
FROM faas f
JOIN rpu r ON f.rpuid = r.objid
JOIN rpu_assessment a ON r.objid = a.rpuid
WHERE f.tdno IN ("22-010-0001-05825", "22-010-0001-00316", "22-010-0001-00318");

SELECT 
    md.objid,
    md.machrpuid,
    md.machine_objid,
    md.basemarketvalue,
    md.marketvalue,
    md.assesslevel,
    md.assessedvalue
FROM machdetail md
WHERE md.machrpuid IN ("RPU-2cd0fe3c:1859914a4be:-660f", "RPU-2cd0fe3c:1859914a4be:-317f", "RPU-3c37d1fb:190c848694a:-475a");

-- 5.3 Multi-Predecessor Population Scan across entire database
SELECT 
    f.objid, 
    f.tdno, 
    f.state, 
    f.txntype_objid, 
    f.prevtdno,
    COUNT(fp.objid) AS pred_count
FROM faas f
JOIN faas_previous fp ON f.objid = fp.faasid
GROUP BY f.objid, f.tdno, f.state, f.txntype_objid, f.prevtdno
HAVING pred_count > 1
ORDER BY f.txntype_objid, pred_count DESC;
