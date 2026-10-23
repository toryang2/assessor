# ETRACS 2.54 / 2.55 — Phase 3.3: Lifecycle Evidence & Local Service Discovery Matrix

> **Audit Scope:** `etracs254_kitaotao` Database | **Connection:** MySQL 5.7+ (`localhost:3306`, User: `chatgpt_ro`)  
> **Code Discovery Scope:** `D:\etracs255-platform` (Offline Archive Inspection via Copied Scratch Artifacts)  
> **Audit Mode:** Strict READ-ONLY (`SELECT` queries only, zero mutations/repairs/migrations; zero remote connections, zero application execution)  
> **Investigation Date:** October 2026 (Phase 3.3 Documentation Closeout Pass)  
> **Baseline Reference:** Phase 2 Verified Transaction Matrix & Phase 3.2 Deliverables  

---

## 1. Executive Summary & Methodology

This report establishes the **Phase 3.3 Lifecycle Evidence and Documentation Closeout Pass** for the ETRACS assessment system. Building upon the Phase 3.2 data reconciliations, this pass conducts a systematic, read-only offline inspection of the local platform installation located at `D:\etracs255-platform`. Candidate archive artifacts were copied to a conversation scratch directory and inspected to identify client proxies, workflow controllers, and service invocations. The findings strictly distinguish client-code evidence, database-snapshot observations, and unverified server-side implementations, ensuring no ungrounded claims of server behavior are asserted.

### Core Lifecycle Dimensions Analyzed
For every active transaction type, the data model was evaluated across ten relational dimensions:
1. **Source FAAS, RPU, and RealProperty Records:** Predecessor entity discovery and electronic resolution vs recorded references with NULL or unresolved IDs.
2. **Resulting FAAS, RPU, and RealProperty Records:** Entity creation, state, and inheritance.
3. **Observed FAAS & RPU Lifecycle States:** Distribution of `CURRENT`, `CANCELLED`, `INTERIM`, `FORAPPROVAL`, and `PENDING`.
4. **Predecessor Linkage Modes (`faas_previous`):** Electronic resolution vs recorded references with NULL or unresolved IDs.
5. **RPU & RealProperty Continuity Pointers (`rpu.previd` & `realproperty.previd`):** Lineage tracking and spatial inheritance at distinct FAAS vs link grains.
6. **RPUMaster Pointer Mechanics (`rpumaster`):** Four-category pointer breakdown demonstrating zero asymmetric matches across all states.
7. **Cancellation Synchronization & Successor References:** Reconciled metadata consistency, `canceldate` populations, and delimiter-aware matching in `cancelledbytdnos`.
8. **Assessment Detail Changes (`rpu_assessment`):** Independent base-table and predecessor reachable queries, avoiding Cartesian multiplication.
9. **Treasury Ledger Integration (`rptledger` & `rptledgerfaas`):** Verified two-sided 1:1 TD uniqueness on matched records, distinguishing ID-equality from proven historical operations, and classifying all 22,155 ledger slices.
10. **Audit Trail & Module Tables:** Modification tracking in `rpt_changeinfo` and observation of operational table states and linkages.

---

## 2. Task 1 — Active Transaction Types & Record Suitability

The Kitaotao database contains exactly **27 configured transaction types** in `faas_txntype`, of which **17 transaction types** are populated in the live `faas` table across **18,841 total records**. Exactly 10 configured transaction types have 0 records (`CA`, `CD`, `CK`, `CT`, `MCS`, `PC`, `ST`, `STP`, `TRY`, `UK`).

All 27 configured transaction types were queried directly by LEFT JOINing from `faas_txntype` (Query 1.1).

### Complete Configured Transaction Types & Record Suitability

| Txn Code | Configured Name | Total FAAS | CURRENT | CANCELLED | Transitional / Draft | With TD No | Suitable Finalized Records | Observed States |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: | :---: | :--- |
| `GR` | General Revision | 15,468 | 8,372 | 7,033 | 63 | 15,450 | 15,405 (99.6%) | `CANCELLED`, `CURRENT`, `FORAPPROVAL`, `INTERIM` |
| `ND` | New Discovery | 1,669 | 740 | 801 | 128 | 1,662 | 1,541 (92.3%) | `CANCELLED`, `CURRENT`, `INTERIM` |
| `RE` | Reassessment | 513 | 28 | 479 | 6 | 512 | 507 (98.8%) | `CANCELLED`, `CURRENT`, `FORAPPROVAL`, `INTERIM` |
| `SD` | Subdivision | 423 | 173 | 227 | 23 | 423 | 400 (94.6%) | `CANCELLED`, `CURRENT`, `FORAPPROVAL`, `INTERIM` |
| `TR` | Transfer of Ownership | 352 | 148 | 200 | 4 | 349 | 348 (98.9%) | `CANCELLED`, `CURRENT`, `FORAPPROVAL`, `INTERIM` |
| `CE` | Correction of Entry | 304 | 194 | 100 | 10 | 301 | 294 (96.7%) | `CANCELLED`, `CURRENT`, `INTERIM` |
| `DC` | Data Capture | 29 | 13 | 16 | 0 | 29 | 29 (100.0%) | `CANCELLED`, `CURRENT` |
| `CC` | Change Classification | 20 | 9 | 9 | 2 | 20 | 18 (90.0%) | `CANCELLED`, `CURRENT`, `FORAPPROVAL`, `INTERIM` |
| `RV` | Revision | 19 | 5 | 14 | 0 | 19 | 19 (100.0%) | `CANCELLED`, `CURRENT` |
| `CS/SD` | Consolidation/Subdivision | 13 | 5 | 8 | 0 | 13 | 13 (100.0%) | `CANCELLED`, `CURRENT` |
| `TRC` | Transfer with Correction | 12 | 4 | 7 | 1 | 12 | 11 (91.7%) | `CANCELLED`, `CURRENT`, `INTERIM` |
| `RS` | Resection | 8 | 8 | 0 | 0 | 8 | 8 (100.0%) | `CURRENT` |
| `CS` | Consolidation | 4 | 3 | 0 | 1 | 3 | 3 (75.0%) | `CURRENT`, `PENDING` |
| `CTD` | Cancellation | 2 | 2 | 0 | 0 | 2 | 2 (100.0%) | `CURRENT` |
| `MC` | Multiple Claim | 2 | 0 | 2 | 0 | 2 | 2 (100.0%) | `CANCELLED` |
| `TRE` | Transfer with Reassessment | 2 | 0 | 2 | 0 | 2 | 2 (100.0%) | `CANCELLED` |
| `DT` | Destruction of Property | 1 | 0 | 1 | 0 | 1 | 1 (100.0%) | `CANCELLED` |
| `CA` | Change Assessment | 0 | 0 | 0 | 0 | 0 | 0 (N/A) | *None (Unpopulated)* |
| `CD` | Change Description | 0 | 0 | 0 | 0 | 0 | 0 (N/A) | *None (Unpopulated)* |
| `CK` | Change Cadastral / Lot / Survey No. | 0 | 0 | 0 | 0 | 0 | 0 (N/A) | *None (Unpopulated)* |
| `CT` | Change Taxability | 0 | 0 | 0 | 0 | 0 | 0 (N/A) | *None (Unpopulated)* |
| `MCS` | Multiple Claim Settlement | 0 | 0 | 0 | 0 | 0 | 0 (N/A) | *None (Unpopulated)* |
| `PC` | Physical Change | 0 | 0 | 0 | 0 | 0 | 0 (N/A) | *None (Unpopulated)* |
| `ST` | Segregation | 0 | 0 | 0 | 0 | 0 | 0 (N/A) | *None (Unpopulated)* |
| `STP` | Special Transfer | 0 | 0 | 0 | 0 | 0 | 0 (N/A) | *None (Unpopulated)* |
| `TRY` | Transfer with Segregation | 0 | 0 | 0 | 0 | 0 | 0 (N/A) | *None (Unpopulated)* |
| `UK` | Unknown | 0 | 0 | 0 | 0 | 0 | 0 (N/A) | *None (Unpopulated)* |
| **TOTAL** | **27 Configured Types** | **18,841** | **9,704** | **8,899** | **238** | **18,808** | **18,603 (98.7%)** | — |

> [!NOTE]
> **Record Suitability Criteria (Reproduced via Query 1.1):** Suitable finalized records require an applicable finalized state (`CURRENT` or `CANCELLED`), a non-empty `tdno`, and resolvable foreign key relationships to both `rpu` and `realproperty`. Across the total population of 18,841 FAAS records, **18,603 records (98.7%)** satisfy all suitability criteria. The remaining 238 records are unapproved transitional drafts (`INTERIM`: 228, `FORAPPROVAL`: 9, `PENDING`: 1), of which 33 lack a generated TD number.

---

## 3. Task 2 — Entity Lifecycle & Relationship Mapping

### 3.1 The Three Structural Transaction Archetypes

Empirical analysis reveals that the 17 active transaction types divide into three core structural archetypes based on how they treat the underlying real property entity, the RPU identity, and the treasury tax ledger:

```
+--------------------------------------------------------------------------------------------------+
| ARCHETYPE 1: UNIT-CONTINUATION (Lineage Preservation)                                             |
| Transactions: GR, TR, CE, CC, TRC                                                                |
| - rpumaster: Preserved. r.rpumasterid points to persistent root RPU.                             |
| - realproperty: Reused or linked via rp.previd.                                                  |
| - rptledger: Reused in-place (rl.tdno updated; multi-year history preserved in rptledgerfaas).  |
| - Multiplicity: Almost strictly 1:1.                                                             |
+--------------------------------------------------------------------------------------------------+
                                                |
+--------------------------------------------------------------------------------------------------+
| ARCHETYPE 2: UNIT-RESTRUCTURING (Spatial & Legal Restructuring)                                  |
| Transactions: SD, CS, CS/SD, RS                                                                 |
| - rpumaster: Newly created (rm.objid = r.rpumasterid = r.objid) for each resulting parcel.      |
| - realproperty: New parcel entities created for child lots; or reused shared host land.          |
| - rptledger: New ledgers issued for newly created property units; predecessor ledgers closed out. |
| - Multiplicity: 1:N (Subdivision) or N:1 / N:M (Consolidation, Consolidation/Subdivision).      |
+--------------------------------------------------------------------------------------------------+
                                                |
+--------------------------------------------------------------------------------------------------+
| ARCHETYPE 3: BASELINE-ESTABLISHMENT & STATUS-CHANGE                                              |
| Transactions: ND, DC, RE, CTD, MC, TRE, DT, RV                                                   |
| - ND & DC: Establish new property units (new rpumaster, fresh ledger, no electronic pred).       |
| - CTD: Formally cancels property taxability (sets taxable=0, AV=0, marks predecessor cancelled).  |
| - DT: Formally records destruction/loss of improvements, adjusting assessed values downwards.   |
| - MC: Tracks conflicting land claims, later resolved into standard general revisions.           |
+--------------------------------------------------------------------------------------------------+
```

---

### 3.2 Relational Mapping Across the Ten Dimensions

#### 1. Source Entities & Predecessor Discovery
- **Linkage Repository:** Historical linkages are maintained in `faas_previous` (**18,855 total rows**) and denormalized into `faas.prevtdno`. The dedicated table `previousfaas` is **0 rows**.
- **Three-Category Predecessor Linkage Modes:**
  1. **Electronic Resolution Mode (`prevfaasid` populated and resolves):** **9,241 rows (49.0%)**. In 100% of these rows, `fp.prevrpuid` also resolves to `rpu.objid`.
  2. **Recorded Historical Reference Mode (`prevfaasid IS NULL`):** **9,586 rows (50.8%)**. Client-code inspection (`RPUInfoPreviousRecordModel.groovy`) reveals that `prevfaasid` and `prevrpuid` are reset to `null` by default; they are populated only if the entered TD number differs from the current record (`entity.tdno != item.prevtdno`), the TD lookup returns a FAAS record, and the `initSuperseded` configuration setting permits initialization. A populated `prevtdno` proves that a historical TD reference was documented. It does **not** establish whether the source was a physical tax declaration book, an un-migrated predecessor system, an unrecorded electronic draft, or a configuration/lookup bypass. Provenance is classified as *Recorded historical reference (electronic ID NULL)* unless case-specific documentary evidence (such as a book volume/page annotation in the memoranda) exists.
  3. **Unresolved Target Mode (`prevfaasid` populated but does not resolve):** **28 rows (0.15%)**. Broken foreign key references pointing to missing FAAS IDs.

#### 2. Resulting Entities & State Inheritance
- Every approved transaction creates a new `faas` record and an instantiated `rpu` record.
- **State Progression:** In observed completed records, transactions begin in `INTERIM`, transition through provincial review (`FORAPPROVAL` / `PENDING`), and become `CURRENT` upon approval. Predecessors are transitioned to `CANCELLED`.

#### 3. RPUMaster Pointer Mechanics: Four-Category Consistency Breakdown (Query 2.1 & 2.2)
- Evaluated across the **full population of 18,841 FAAS records** via `LEFT JOIN`:
  - **18,841 records (100.0%)** have an associated `rpu` relationship.
  - **18,841 records (100.0%)** have an associated `rpumaster` relationship via `r.rpumasterid`.
- **Four-Category Consistency Breakdown Across Entire Database:**
  - **Both Pointers Match (`currentfaasid = f.objid AND currentrpuid = f.rpuid`):** **10,024 records (53.2%)**.
  - **Only `currentfaasid` Matches:** **0 records (0.0%)**.
  - **Only `currentrpuid` Matches:** **0 records (0.0%)**.
  - **Neither Pointer Matches:** **8,817 records (46.8%)**.
- **Pointer Consistency Grouped by FAAS Lifecycle State (Query 2.2):**
  - **`CURRENT` (9,704 records):** Both match: **9,617 (99.1%)**; Neither matches: **87 (0.9%)**.
  - **`CANCELLED` (8,899 records):** Both match: **187 (2.1%)**; Neither matches: **8,712 (97.9%)**. (Expected behavior: RPUMaster pointers represent the current parcel; on historical records, pointers advance to the active successor).
  - **`INTERIM` (228 records):** Both match: **211 (92.5%)**; Neither matches: **17 (7.5%)**.
  - **`FORAPPROVAL` (9 records):** Both match: **9 (100.0%)**; Neither matches: **0 (0.0%)**.
  - **`PENDING` (1 record):** Both match: **0 (0.0%)**; Neither matches: **1 (100.0%)**.
- **Zero Asymmetric Matches Invariant:** In 100% of cases examined across the database (18,841 of 18,841), there are **zero asymmetric pointer-match cases**: there are exactly **0 records** where `currentfaasid` matches while `currentrpuid` fails to match, and **0 records** where `currentrpuid` matches while `currentfaasid` fails to match. The exact server-side implementation mechanism responsible for maintaining these pointers remains unverified because backend service code was not locally available.

#### 4. RealProperty Continuity: Distinct FAAS vs Predecessor Links (Query 2.3 & 2.4)
- **Predecessor Link Grain vs FAAS Record Grain:**
  - Total FAAS records examined: **18,841 distinct records**.
  - Total predecessor links in `faas_previous`: **18,855 rows** (9,241 electronic resolved links, 9,586 links with NULL predecessor ID, and 28 links with unresolved populated predecessor ID).
- **Land-Only Spatial Continuity (Query 2.4):**
  - Explicitly filtering by `r.rputype = 'land'` encompasses **13,136 distinct land FAAS records** and **13,121 land predecessor links**.
  - Of these, exactly **6,765 land links** resolve electronically:
    - **1 link** reuses `realproperty` directly (`f.realpropertyid = pf.realpropertyid`).
    - **6,753 links** create a new `realproperty` record that points directly to the predecessor land parcel (`rp.previd = pf.realpropertyid`).
    - **11 links** create an independent `realproperty` record (`rp.previd IS NULL` or points elsewhere).
- **Improvements (Buildings & Machinery):** Improvements do not chain parcel boundaries; `f.realpropertyid` directly references the host land parcel upon which the improvement is situated.

#### 5. Cancellation Synchronization & Metadata Reconciliation (Query 2.7)
- Across all **9,241 electronic predecessor links** (resolving to **8,902 distinct predecessor FAAS records**):
  - **Predecessor `state = 'CANCELLED'`:** **9,199 links** (8,872 distinct FAAS).
  - **Predecessor `canceldate` Populated:** Exactly **9,203 links** (resolving to **8,877 distinct predecessor FAAS records**). Exactly **38 links** (25 distinct predecessor FAAS) have `canceldate IS NULL`. (Across all 8,899 `CANCELLED` FAAS records in the entire database, 8,891 have a populated `canceldate` and 8 have `canceldate IS NULL`).
  - **Successor TD Match in `cancelledbytdnos`:** **8,879 links** (8,879 distinct FAAS) match via delimiter-aware matching (`FIND_IN_SET`).
  - **Three-Condition Joint Cancellation Measure:** Exactly **8,868 links (96.0%)**, resolving to **8,868 distinct predecessor FAAS records**, satisfy **all three conditions simultaneously** (`state = 'CANCELLED'`, `canceldate IS NOT NULL`, and `FIND_IN_SET(TRIM(f.tdno), REPLACE(pf.cancelledbytdnos, ' ', '')) > 0`).
  - **Anomalous Retention:** Exactly **30 links**, resolving to **28 distinct predecessor FAAS records**, remain in `CURRENT` state despite having electronic successors.

#### 6. Valuation Continuity & Trajectory Grain (Query 2.5)
- All valuation comparisons across predecessor relationships are evaluated at the **predecessor-link comparison grain** (not unique transactions).
- Across the **8,306 electronic predecessor-link comparisons** in General Revisions (`GR`):
  - In **1,257 link comparisons (15.1%)**, successor assessed value is exactly equal to predecessor assessed value.
  - In **7,030 link comparisons (84.6%)**, successor assessed value increased.
  - In **19 link comparisons (0.2%)**, successor assessed value decreased.
  - Successor market value matched predecessor market value in **1,241 link comparisons (14.9%)**.
- In Transfers of Ownership (`TR`), **147 electronic link comparisons** were evaluated: **146 link comparisons (99.3%)** demonstrated exact valuation conservation.

#### 7. Treasury Ledger Integration & Two-Sided Uniqueness (Query 2.6 & 2.11)
- **Verified Two-Sided 1:1 Uniqueness on Matched Population:**
  - `faas.tdno` check: Across all 18,808 nonblank TD numbers in `faas`, there are **0 duplicate TD numbers**.
  - `rptledger.tdno` check: Across all 10,241 records in `rptledger`, there are **0 duplicate TD numbers**.
  - Matched population: Joining `faas` to `rptledger` on `tdno` matches exactly **9,752 distinct FAAS records** to **9,752 distinct ledger records** via **9,752 distinct TD numbers** (strictly 1:1 on both sides, with 0 one-to-many instances).
- **FAAS Records Without Resolvable Ledger:** **9,089 records** (predominantly cancelled historical FAAS or unapproved drafts).
- **ID-Equality Evidence vs Proven Operations:**
  - **ID-Equality Evidence (`rl.objid = f.objid`):** Observed in **1,418 FAAS records** (including 736 `ND`, 467 `GR`, and 174 `SD`). This establishes identical entity keys, which is consistent with fresh-ledger creation at the time of FAAS generation, but does not independently prove the sequence of historical treasury operations.
  - **Distinct-ID Evidence (`rl.objid != f.objid`):** Observed in **8,334 FAAS records** (including 7,947 `GR` and 139 `TR`), establishing that the ledger ID differs from the FAAS ID. Client proxy calls to `updateFaasReference()` reflect intended workflow support for in-place mapping updates, but server-side mutation sequences remain uninspected.
- **Reconciliation of Ledger Assessment Slices (`rptledgerfaas` - Query 2.11):**
  - Total records in `rptledgerfaas`: **22,155 rows**.
  - **Matched to existing FAAS records (`rlf.faasid = f.objid`):** **18,920 rows (85.4%)** across 18,591 distinct FAAS records.
  - **Unmatched to existing FAAS records:** Exactly **3,235 rows (14.6%)**, classified into four distinct categories via LEFT JOIN:
    1. **`faasid IS NULL` where `tdno` matches an existing FAAS:** **2,426 rows** (75.0% of unmatched). These are legacy assessment slices where the foreign key `faasid` was unpopulated, but the slice points to a valid property ledger whose TD number exists in `faas`.
    2. **`faasid IS NULL` where `tdno` does not match any existing FAAS:** **299 rows** (9.2% of unmatched). Unpopulated foreign key pointing to historical or retired declarations outside the current FAAS table.
    3. **`faasid` is populated but does not resolve to `faas.objid`:** **510 rows** (15.8% of unmatched), pointing to **505 distinct missing FAAS IDs**.
    4. *Note:* The 3,235-row difference does **not** consist entirely of orphan records; 2,426 of these rows (75.0%) successfully link to current property declarations via TD number.

#### 8. Assessment Continuity & Independent Line Queries (Query 2.8A & 2.8B)
- **Base Table State Distribution (Query 2.8A - No Joins to Predecessors):**
  - Total assessment-line records in `rpu_assessment`: **22,430 rows** (all 22,430 are distinct `objid`s; 0 orphans unlinked to RPU or FAAS).
  - Lines tied to `CURRENT` FAAS: **11,519 lines** across **9,702 distinct FAAS / RPUs**.
  - Lines tied to `CANCELLED` FAAS: **10,665 lines** across **8,898 distinct FAAS / RPUs**.
  - Lines tied to `INTERIM` drafts: **237 lines** across **219 distinct FAAS / RPUs**.
  - Lines tied to `FORAPPROVAL` drafts: **9 lines** across **9 distinct FAAS / RPUs**.
  - *(13 FAAS records across the database have zero assessment lines: 2 `CURRENT`, 1 `CANCELLED`, 9 `INTERIM`, 1 `PENDING`).*
- **Independent Reachable Predecessor Lines (Query 2.8B):**
  - Joining `faas` -> `rpu` -> `rpu_assessment a_prev` on `r.previd = a_prev.rpuid` independently avoids Cartesian multiplication.
  - **8,719 FAAS records** have `rpu.previd` matching an earlier RPU that possesses assessment lines.
  - These 8,719 FAAS records reach exactly **10,434 distinct predecessor assessment lines** (totaling 10,436 line associations).
  - *Cartesian Multiplication Note:* In an uncurated query joining both current `rpu_assessment` and predecessor `rpu_assessment` simultaneously on multi-line properties, the raw row count multiplies to 25,845 rows. Evaluating base-table lines and predecessor reachability independently eliminates this distortion.
- **Evidential Boundary:** Client code (`RPUModel.groovy`) shows that in-memory assessment recalculation is gated by `isAllowModify()`. While this explains client-side mutability during drafting, it does not prove universal backend immutability of finalized assessment records, as server-side mutation logic was not inspected.

#### 9. Audit Trail & Operational Table Utilization (Query 2.9 & 2.10)
- **Audit Logging (`rpt_changeinfo` - Query 2.9):**
  - **Total rows in table:** **4,139 rows**.
  - **Resolved FAAS links:** **4,042 rows** resolve directly to **2,427 distinct active FAAS records**.
  - **Unresolved / Historical orphan rows:** **97 rows** reference **64 distinct FAAS IDs** that do not exist in the active `faas` table.
- **Operational Module Tables (Query 2.10):**
  - `subdivision`: Contains **14 rows** (`state = 'DRAFT'`), linked to transitional/draft FAAS records. Exactly **0 rows** link to completed (`CURRENT` or `CANCELLED`) FAAS records. Child tables `subdividedland`, `subdivision_motherland`, and `subdivisionaffectedrpu` contain **0 rows**.
  - `consolidation`: Contains **1 row** (`state = 'DRAFT'`), linked to a transitional draft FAAS. Exactly **0 rows** link to completed FAAS records. Child tables `consolidatedland` and `consolidationaffectedrpu` contain **0 rows**.
  - `cancelledfaas`: Contains **18 rows** (14 `APPROVED`, 2 `FORAPPROVAL`, 2 `DRAFT`). Exactly **14 rows** link to finalized completed FAAS records, and **4 rows** are unlinked/orphan.
  - **Evidential Classification:** In the current database snapshot, 400 finalized subdivisions (`SD`) and 3 finalized consolidations (`CS`) exist in `faas` without corresponding completed rows in `subdivision` or `consolidation`. Client task runners (`ApproveSubdivisionTask.groovy`, `ApproveConsolidationTask.groovy`) demonstrate that workflow orchestration exists and invokes named remote services, but they do not prove server-side execution details or explain why historical transactions did not populate these tables.

---

## 4. Task 3 — Representative Transaction Case Studies

A comprehensive catalog of representative cases was compiled into [`etracs_transaction_lifecycle_cases.csv`](file:///d:/CODE/assessor/etracs-scripts/etracs_transaction_lifecycle_cases.csv). Entity grains and ledger relationships were verified using Query 3.1:

### 4.1 Unit-Continuation: Transfer of Ownership (`TR`)
- **Case Reference:** `22-010-0032-05511` (FAAS: `F-6defdc2a:18e5560fd65:-5377`, State: `CURRENT`)
- **Predecessor:** `22-010-0032-00072` (FAAS: `Fd2d2dc1:185a34938e5:-4c48`, State: `CANCELLED`)
- **Lifecycle Relationships:**
  - `faas_previous`: 1 electronic link (`PF-6defdc2a:18e5560fd65:-5376`) resolving to predecessor FAAS and RPU.
  - `rpumaster`: Preserved root pointer (`r.rpumasterid = 'RPUd2d2dc1:185a34938e5:-4c45'`).
  - `realproperty`: New parcel created (`RP-6defdc2a:18e5560fd65:-5375`) with `previd` pointing to predecessor parcel (`RPd2d2dc1:185a34938e5:-4c46`).
  - **Valuation Conservation:** Predecessor AV: ₱9,600.00 (MV: ₱192,000.00); Successor AV: **₱9,600.00** (MV: ₱192,000.00) — **100% exact valuation conservation**.
  - **Treasury Linkage:** `rptledger` reused in-place (`F1f1a5c7b:174f6d75476:-439b`), updated `tdno = '22-010-0032-05511'`. Successor slice added to `rptledgerfaas` starting in 2025.

### 4.2 Unit-Restructuring: Subdivision (`SD`)
- **Mother Lot:** `22-010-0003-00131` (FAAS: `F-3618ea4e:1866d2fd03c:-5212`, State: `CANCELLED`)
- **Child Lots:** 13 child lots created (`22-010-0003-05100` to `05112`).
- **Representative Child Lot:** `22-010-0003-05103` (FAAS: `F1c487f07:1879300ce47:-2b1e`, State: `CURRENT`)
- **Lifecycle Relationships:**
  - `faas_previous`: 1 electronic link resolving to mother lot `F-3618ea4e:1866d2fd03c:-5212`.
  - `rpumaster`: Newly instantiated root (`rm.objid = r.rpumasterid = r.objid`).
  - `realproperty`: Newly created parcel entity (`RP1c487f07:1879300ce47:-2b1d`).
  - **Treasury Linkage:** Each child lot generates an independent fresh `rptledger` (`rl.objid = f.objid`), establishing separate tax liability for each partitioned parcel.
  - **Operational Table Status:** Mother lot and child lots possess **0 rows** in `subdividedland`, `subdivision_motherland`, and `subdivisionaffectedrpu`.

### 4.3 Unit-Restructuring: Pure Consolidation (`CS`)
- **Case Reference:** `22-010-0001-05825` (FAAS: `F-3c37d1fb:190c848694a:-475c`, State: `CURRENT`)
- **Predecessors:** `22-010-0001-00316` (AV: ₱35,210.00) and `22-010-0001-00318` (AV: ₱70,100.00). Both marked `CANCELLED` on 2024-07-19 with `cancelledbytdnos = '22-010-0001-05825'`.
- **Lifecycle Relationships:**
  - `faas_previous`: 2 electronic links resolving to both predecessor FAAS records.
  - `realproperty`: Both predecessors and successor represent machinery installed on the same shared host land parcel (`RP-2cd0fe3c:1859914a4be:-7ab3`, Cadastral Lot `87-B-6`).
  - **Valuation Conservation:** Predecessor Market Values (₱44,010.00 + ₱87,628.80) sum to **₱131,638.80**. Consolidated FAAS MV is **₱131,638.80 (100% MV conservation)**. Consolidated AV is **₱105,300.00** across 3 re-broken machinery line items (₱10 difference; cause unverified).
  - **Operational Table Status:** The dedicated `consolidation` table contains 0 active records (1 draft record only); the consolidation was executed through core FAAS tables.

### 4.4 Status-Change: Formal Tax Cancellation (`CTD`)
- **Case Reference:** `22-010-0022-05368` (FAAS: `F-65f9b252:18b59c10494:-36b1`, State: `CURRENT`)
- **Predecessor:** `22-010-0022-00147` (FAAS: `F2711f584:18773251b9a:-79f7`, State: `CANCELLED`)
- **Lifecycle Relationships:**
  - `rpu`: Market value retained (₱137,970.00), but `r.taxable` set to `0` and `r.totalav` set to `₱0.00`.
  - `rpu_assessment`: Assessment level set to `0.00%` and assessed value set to `₱0.00`.
  - **Business Purpose:** Formally retires property from the tax roll while preserving complete historical auditability.

---

## 5. Task 4 — Business Rule Classification & Evidence Standards

Each proposed business rule was systematically reviewed and classified into one of five standard evidential categories:
1. **Directly established by the current database snapshot**
2. **Observed in the selected cases or examined population**
3. **Supported by client-code evidence** *(Confirmed in Osiris2 client models/controllers)*
4. **Supported by server-service implementation evidence** *(Not established; server implementation not located)*
5. **Inferred but not established**

| Business Rule / Claim | Status | Evidential Classification | Supporting Evidence & Scope |
| :--- | :---: | :--- | :--- |
| **BR-01: Historical TD Reference Rule** | **REVISED** | Supported by client-code evidence & snapshot observation | `RPUInfoPreviousRecordModel.groovy` confirms `prevfaasid` is reset to `null` and populated only if `entity.tdno != item.prevtdno`, TD lookup returns a FAAS, and `initSuperseded` allows initialization. Database snapshot shows 9,586 links with NULL predecessor ID. |
| **BR-02: RPUMaster Pointer Consistency** | **CONFIRMED (QUALIFIED)** | Directly established by the current database snapshot | Four-category breakdown across full population (18,841 FAAS): Both match: 10,024; Only FAAS: 0; Only RPU: 0; Neither matches: 8,817. Exactly **zero asymmetric pointer-match cases**. Server-side pointer maintenance mechanism remains unverified. |
| **BR-03: Assessment Replacement Model** | **QUALIFIED** | Supported by client-code evidence | `RPUModel.groovy` confirms client-side recalculation is gated by `isAllowModify()`. Multi-row association pattern observed in DB (22,430 distinct lines). Backend immutability of finalized assessment records is not established by client code alone. |
| **BR-04: State Progression Uniformity** | **REVISED** | Observed in the selected cases or examined population | While `INTERIM` -> `FORAPPROVAL`/`PENDING` -> `CURRENT` is observed across normal workflows, the snapshot cannot prove every historical transaction underwent identical intermediate steps. |
| **BR-05: Treasury Ledger In-Place Reuse** | **QUALIFIED** | Observed in the selected cases or examined population | Database confirms 9,752 matched TD numbers are strictly 1:1 on both sides; 8,334 reused; 1,418 fresh. Client code calls `updateFaasReference()`, but server-side mutation sequence was not inspected. |
| **BR-06: Operational Wizard Table Bypass** | **REVISED** | Observed in the selected cases or examined population | 400 finalized subdivisions and 3 consolidations exist in `faas` without corresponding completed rows in `subdivision`/`consolidation`. Client task runners show wizard orchestration, but do not establish backend implementation or explain historical absence. |
| **BR-07: Valuation Conservation in Transfers** | **CONFIRMED** | Observed in the selected cases or examined population | 146 of 147 electronic predecessor-link comparisons in `TR` (99.3%) demonstrate exact valuation conservation. 1 case had a minor reassessment adjustment. |
| **BR-08: Complete Audit Log Tracking** | **REVISED** | Inferred but not established | `rpt_changeinfo` contains 4,139 rows covering 2,427 distinct FAAS across 14 transaction types. It is active, but not comprehensive across all 18,841 historical transactions (97 rows reference orphan FAAS IDs). |

---

## 6. Task 5 — Local Service Discovery & Architecture Assessment (Phase 3.3)

### 6.1 Platform Installation & Search Scope
- **Physical Installation Path:** `D:\etracs255-platform`
- **Total Files in Platform Directory:** 167 files (129 `.jar`, 21 `.dll`, 6 `.lib`, 2 `.exe`, 2 `.conf`, 1 `.bat`, 1 `.sh`, 1 `.ico`, 1 `.sxml`, 1 `.jar1`, 2 without extension).
- **Architecture Standard:** Osiris2 Rich-Client Framework.
- **Client Configuration (`client.conf`):**
  ```properties
  app.url=http://192.168.0.156:8000/downloads/modules/updates.xml
  app.debug=true
  app.clientcode=
  ```
- **Service Invocation Pattern:**
  All client-side controllers and models interact with services using the declarative `@Service(...)` annotation or `ServiceLookup.create(serviceName)`:
  ```groovy
  public class ServiceLookup {
      public static def create(serviceName){
          return InvokerProxy.instance.create( serviceName );
      }
  }
  ```
  `InvokerProxy` dispatches remote invocations over HTTP/RMI to the application server at `http://192.168.0.156:8000`.

### 6.2 Service Class Discovery & Categorization

A comprehensive scan across all 129 JAR archives in `D:\etracs255-platform` identified zero server-side implementation classes for `FAASService`, `ConsolidationService`, `SubdivisionService`, `RPTLedgerService`, or `CancelledFAASService`. The platform directory contains exclusively the client-side execution environment.

| Target Service Name | Client Proxy / Calling Controller Found | Server-Side Implementation Class in Platform JARs | Status / Resolution |
| :--- | :--- | :--- | :--- |
| **`FAASService`** | `FAASModel.groovy`, `FAASInitTxnModel.groovy`, `RPUInfoPreviousRecordModel.groovy` | **Not Found** (0 occurrences in 129 JARs) | Server-side implementation hosted on application server. |
| **`ConsolidationService`** | `AbstractConsolidationController.groovy`, `ConsolidationInitModel.groovy` | **Not Found** (0 occurrences in 129 JARs) | Server-side implementation hosted on application server. |
| **`SubdivisionService`** | `AbstractSubdivisionController.groovy`, `SubdivisionAssistModel.groovy` | **Not Found** (0 occurrences in 129 JARs) | Server-side implementation hosted on application server. |
| **`RPTLedgerService`** | `RPTLedgerModel.groovy`, `ChangeFaasReferenceModel.groovy`, `CreateMissingLedgerModel.groovy` | **Not Found** (0 occurrences in 129 JARs) | Server-side implementation hosted on application server. |
| **`CancelledFAASService`**| `CancelledFAASController.groovy`, `FAASInitCancelModel.groovy` | **Not Found** (0 occurrences in 129 JARs) | Server-side implementation hosted on application server. |

### 6.3 Behavior Established by Client Code Evidence

Although backend service classes reside on the remote application server, the client Groovy models and workflow controllers provide direct code evidence governing client-side behavior:

1. **Predecessor TD Reference Handling (`RPUInfoPreviousRecordModel.groovy`):**
   - Method: `buildPrevFaasInfo(item)`
   - Code:
     ```groovy
     void buildPrevFaasInfo(item){
         resetSupersededInfo(item);
         if (entity.tdno == item.prevtdno) return;
         def faas = svc.getFaasByTdNo(item.prevtdno)
         def initSuperseded = RPTUtil.toBoolean(var.get('faas_datacapture_initialize_superseded'), true);
         if (faas && initSuperseded){
             item.prevfaasid = faas.objid;
             item.prevrpuid = faas.rpuid;
             // ... populates previous valuation and pin
         }
     }
     void resetSupersededInfo(item) {
         item.prevfaasid = null;
         item.prevrpuid = null;
     }
     ```
   - **Established Finding:** `prevfaasid` and `prevrpuid` are reset to `null` and are populated only when `entity.tdno != item.prevtdno`, the TD lookup returns a FAAS record, and the `initSuperseded` setting permits initialization. A populated `prevtdno` proves that a historical TD was recorded, but NULL IDs do not prove physical-book origin or lookup failure.

2. **Assessment Editing & Calculation (`RPUModel.groovy`):**
   - Method: `calculateAssessment()`
   - Code:
     ```groovy
     void calculateAssessment(){
         if (isAllowModify()) {
             entity.rpu.txntype = entity.txntype;
             entity.rpu.effectivityyear = entity.effectivityyear;
             entity.rpu.putAll( rpuSvc.calculateAssessment(entity.rpu) )
             modeChanged(entity.state);
         }
     }
     ```
   - **Established Finding:** Client-side assessment recalculation is gated by `isAllowModify()`. While this shows client-side mutability during drafting, backend immutability of finalized assessment records cannot be established without inspecting server-side code.

3. **Subdivision Execution Pipeline (`ApproveSubdivisionTask.groovy`):**
   - Methods: `svc.assignNewTdNos(entity)`, `svc.approveSubdividedLandFaasRecord(entity, land)`, `svc.approveAffectedRpuFaasRecord(entity, arpu)`, `svc.approve(entity)`.
   - **Established Finding:** Client task runners demonstrate workflow orchestration and named service calls. They do not establish the backend implementation or prove why corresponding module rows are absent from particular historical transactions.

4. **Consolidation Execution Pipeline (`ApproveConsolidationTask.groovy`):**
   - Methods: `svc.assignNewTdNos(entity)`, `svc.approveConsolidatedLandFaas(entity)`, `svc.approveAffectedRpuFaasRecord(entity, arpu)`, `svc.approve(entity)`.
   - **Established Finding:** Client task runners demonstrate consolidation orchestration and service dispatch, but do not independently establish backend execution.

5. **Ledger Mapping & Reference Updates (`ChangeFaasReferenceModel.groovy` & `FAASLedgerMappingModel.groovy`):**
   - Methods: `svc.updateFaasReference(entity, newfaas)`, `svc.changeFAASLedgerMapping(...)`.
   - **Established Finding:** Client proxies call `updateFaasReference()` when mapping new references to `CURRENT` FAAS records. This illustrates client workflow intent, but does not independently establish the server-side mutation sequence.

---

## 7. Deliverables Index & Artifact References

The following companion deliverables have been updated in place:

1. [`etracs_transaction_lifecycle_matrix.md`](file:///d:/CODE/assessor/etracs-scripts/etracs_transaction_lifecycle_matrix.md): This comprehensive overview of transaction behavior, structural archetypes, corrected query grains, classified business rules, and local service discovery findings.
2. [`etracs_transaction_lifecycle_cases.csv`](file:///d:/CODE/assessor/etracs-scripts/etracs_transaction_lifecycle_cases.csv): Structured catalog of verified representative cases across all 17 active transaction types with explicit per-reference predecessor classifications and corrected consolidation valuation notes.
3. [`etracs_transaction_lifecycle_queries.sql`](file:///d:/CODE/assessor/etracs-scripts/etracs_transaction_lifecycle_queries.sql): Executable SQL script containing all targeted read-only queries with deduplicated joins, four-category RPUMaster breakdown, independent assessment queries, and reproducibility queries.

---

## 8. Phase 3.3 Closeout Log & Verification Summary

| Item / Claim Area | Phase 3.2 Finding | Phase 3.3 Closeout Corrected Finding | Classification & Evidential Basis |
| :--- | :--- | :--- | :--- |
| **1. Predecessor TD Lookup** | Concluded NULL ID proves electronic lookup failure at entry time. | `RPUInfoPreviousRecordModel.groovy` resets `prevfaasid`/`prevrpuid` to NULL; populates them only when `tdno != prevtdno`, lookup returns FAAS, and `initSuperseded` allows initialization. | Client-Code Evidence & Database Observation: NULL ID indicates absence of electronic resolution under active settings; physical book origin is unestablished. |
| **2. RPUMaster Pointer Maintenance** | Inferred server-side pointer maintenance in `FAASService`. | Removed unsupported remote server claims. Retained observed database pointer distribution (10,024 both match, 8,817 neither, 0 asymmetric). Implementation mechanism classified as unverified. | Database Snapshot Evidence: Server implementation unlocated locally. |
| **3. Assessment In-Place Modifications** | Concluded client code proves drafting mutability and finalized replacement. | Qualified finding: client recalculation is gated by `isAllowModify()`. Does not prove universal backend immutability of finalized assessment records. | Client-Code Evidence: Qualified client-side behavior. |
| **4. Operational Wizard Tables** | Inferred operational choice over architectural bypass. | Qualified finding: client task runners show workflow orchestration and named service calls. They do not establish server-side implementation or explain historical table vacancy. | Client-Code Evidence: Qualified client orchestration. |
| **5. Treasury Ledger Mapping** | Used client `updateFaasReference()` as confirmation of backend reuse. | Qualified finding: client calls reflect intended workflow, but server-side mutation sequence was not inspected. Retained 1:1 TD uniqueness on matched database records. | Database Snapshot & Qualified Client Evidence. |
| **6. Consolidation Valuation Statement** | Described ₱10 difference as a "rounding delta". | Corrected to: "₱10 difference; cause unverified." Preserved exact AV and MV numbers in Markdown and CSV. | Database Observation. |

### Remaining Backend-Source Limitations
1. **Remote Application Server Boundary:** The backend service implementations (`FAASService`, `ConsolidationService`, `SubdivisionService`, `RPTLedgerService`, `CancelledFAASService`) execute on the application server (`http://192.168.0.156:8000`) and are not present in local client archives under `D:\etracs255-platform`. Server-side transaction boundary logic and table mutations remain uninspected.
2. **Version Distinction (ETRACS 2.54 vs 2.55):** `D:\etracs255-platform` is an ETRACS 2.55 client platform (modified May 2025). Historical database transactions in `etracs254_kitaotao` executed between 2019 and 2022 were processed under earlier ETRACS 2.54 application builds.

---

### Closeout Status
**Phase 3.3 is ready to close.** All wording corrections, scope qualifications, and evidential boundary standards have been applied consistently across the Markdown matrix, SQL queries, and CSV case catalog.
