# ETRACS 2.54 / 2.55 — Phase 4.2: Data Integrity and Business-Rule Validation Matrix

> **Audit Scope:** `etracs254_kitaotao` Database | **Connection:** MySQL (`localhost:3306`, User: `chatgpt_ro`)  
> **Audit Mode:** Strict READ-ONLY (`SELECT` queries only, zero mutations/repairs/migrations; zero remote connections, zero application execution)  
> **Investigation Date:** October 2026 (Phase 4.2 Evidence Classification & Denominator Pass)  
> **Baseline Reference:** Phase 2 Verified Transaction Matrix, Phase 3.3 Lifecycle Matrix, & Phase 4.1 Deliverables  

---

## 1. Executive Summary & Methodology

This report establishes the **Phase 4.2 Evidence Classification and Denominator Correction Pass** for the ETRACS assessment system. Continuing from the Phase 4.1 corrective review, this pass incorporates joint predecessor resolution cross-tabulations, removes unsupported causal explanations, refines state terminology, separates NULL from unresolvable pointers in the 87 `CURRENT` pointer mismatch cases, tests NULL totalav values independently, classifies missing successor references into component grains, refines multi-ledger and multi-slice denominators, and establishes strict evidential boundaries throughout.

### Verified Population Denominators
All measurements are strictly anchored to verified database table counts:
- **`faas` (Property Declarations):** **18,841 total records** (9,704 `CURRENT`, 8,899 `CANCELLED`, 228 `INTERIM`, 9 `FORAPPROVAL`, 1 `PENDING`).
- **`faas_previous` (Predecessor Linkages):** **18,855 total links** (9,241 electronic resolved, 9,586 recorded historical references with NULL ID, 28 unresolved populated IDs).
- **`rpu` (Real Property Units):** **18,843 total records** (18,841 linked to FAAS; 2 unlinked historical records).
- **`rpumaster` (Root Parcel Identity):** **27,661 total records** in base table; **10,121 distinct RPUMasters** referenced across the 18,841 FAAS-linked RPUs.
- **`rpu_assessment` (Assessment Line Items):** **22,430 total lines** (0 orphans unlinked to RPU; spanning 18,828 distinct RPUs).
- **`rptledger` (Treasury Property Ledgers):** **10,241 total ledgers** (strictly 0 duplicate TD numbers).
- **`rptledgerfaas` (Ledger Assessment Slices):** **22,155 total slices** (19,430 populated `faasid` slices spanning 19,098 distinct pairs; 2,725 NULL/blank `faasid` slices).

---

## 2. Workstream 1 — FAAS & Predecessor Lineage Validation

### 2.1 Predecessor Link Modes & Joint Resolution Cross-Tabulation (Queries 1.1A & 1.1B)
Historical predecessor connections across the **18,855 total rows** in `faas_previous` were evaluated independently and cross-tabulated joint row by row:

#### Joint Predecessor Resolution Cross-Tabulation (Query 1.1B)
| Predecessor FAAS ID Status (`prevfaasid`) | Predecessor RPU ID Status (`prevrpuid`) | Link Count | Pct of Links | Evidential Classification |
| :--- | :--- | :---: | :---: | :--- |
| **`prevfaasid IS NULL`** | **`prevrpuid IS NULL`** | **9,586** | 50.84% | Directly established by database snapshot |
| **`prevfaasid RESOLVED`** | **`prevrpuid RESOLVED`** | **9,241** | 49.01% | Directly established by database snapshot |
| **`prevfaasid UNRESOLVED`** | **`prevrpuid UNRESOLVED`** | **28** | 0.15% | **Confirmed referential-integrity issue** |
| *Any Asymmetric Status* | *Any Divergent Status* | **0** | **0.00%** | Directly established by database snapshot |
| **TOTAL** | **All Predecessor Links** | **18,855** | **100.00%** | — |

> [!NOTE]
> Query 1.1B confirms that in 100% of rows (18,855 of 18,855), `prevfaasid` and `prevrpuid` resolution statuses match row by row. Exactly 0 rows exhibit asymmetric resolution. The 28 unresolved links reference **20 distinct missing FAAS IDs** (e.g., `Fb1aa850:175df309598:-3e64`, `F-16983047:17017f1ff98:-1605`). The historical cause of these missing records (whether incomplete migration, uncommitted drafts, or administrative cleanup) remains unverified.

### 2.2 Predecessor Multiplicity & Branching (Query 1.2)
Evaluating predecessor rows per distinct FAAS record across all 18,841 FAAS:
- **Zero Predecessors (0 rows):** **27 FAAS records** (1 `CURRENT` GR, 6 `INTERIM` ND, 13 `CANCELLED` GR, 5 `INTERIM` GR, 1 `PENDING` CS, 1 `CANCELLED` RE).
- **Single Predecessor (1 row):** **18,776 FAAS records** (99.65%).
- **Dual Predecessors (2 rows):** **36 FAAS records** (Consolidations `CS`, `CS/SD`, and parcel merges).
- **Three Predecessors (3 rows):** **1 FAAS record** (`F5de3380f:19a70447287:-37d9`, TD `22-010-0001-06320`).
- **Four Predecessors (4 rows):** **1 FAAS record** (`F5de3380f:19a70447287:-343c`, TD `22-010-0001-06321`).

### 2.3 Self-Links, Bounded Cycle Detection, and Same-TD Links (Query 1.3A, 1.3B, 1.3C, 1.3D)
1. **Direct Self-Links (`faasid = prevfaasid`):** Exactly **2 records** in `faas_previous`:
   - `PF-fb9dbf5:18377264a5a:-70a3` (FAAS: `F-49edd98d:176d4e5e3cd:-6015`, TD `10-002-03980`, State `CANCELLED`).
   - `PF-6d83a19e:17273a8adae:-3cb2` (FAAS: `F63344efe:17273e405f2:-7113`, TD `10-001-09871`, State `CANCELLED`).
   - *Classification:* **Confirmed internal contradiction** (graph self-cycle).
2. **Direct Two-Way Cycles (Excluding Self-Links - Query 1.3C):**
   - Testing direct mutual links ($A \to B$ and $B \to A$) with `fp1.faasid != fp1.prevfaasid`: Exactly **0 two-way cycles** detected.
3. **Bounded Three-Way Cycles (Excluding Self-Links - Query 1.3D):**
   - Testing three-node cycles ($A \to B \to C \to A$) where no node is self-linked: Exactly **0 three-way cycles** detected.
   - *Evidential Limitation:* This establishes that direct 2-cycles and 3-cycles are absent; it does not prove the entire graph is acyclic across arbitrary path lengths.
4. **Same-TD Links (`faas.tdno = faas_previous.prevtdno` - Query 1.3B):** Exactly **17 records**. A present relationship pattern where the successor record retains the predecessor's TD number. The historical cause (data entry choice, system conversion, or administrative policy) remains unverified.
5. **Duplicate Predecessor Links:** Exactly **0 duplicate `(faasid, prevfaasid)` pairs** exist. Exactly **1 duplicate `(faasid, prevtdno)` link** exists (`PF4b509d42:18654505642:-3b45` and `PF4b509d42:18654505642:-3b47` under FAAS `F4b509d42:18654505642:-3b46`).

### 2.4 Lineage Agreement: `faas_previous` vs `rpu.previd` & `realproperty.previd` (Queries 1.4 & 1.5)
- **`rpu.previd` Agreement:** Across the 9,269 links with populated `prevrpuid`:
  - **8,731 links (94.20%)** match perfectly (`r.previd = fp.prevrpuid`).
  - **537 links (5.79%)** have `r.previd IS NULL` while `fp.prevrpuid` is populated (newly instantiated RPU roots in subdivisions and consolidations).
  - **1 link (0.01%)** demonstrates a direct ID mismatch (`PF4b509d42:18654505642:-3b45`: `r.previd` points to `RPU5c24dc5:16fe5927aa2:-68d1` while `fp.prevrpuid` records `RPU-14da9f13:178344cf792:248d`).
- **Land `realproperty.previd` Spatial Lineage:** Across 7,620 resolved land predecessor links:
  - **7,130 links (93.57%)** have `rp.previd` matching predecessor `realpropertyid`.
  - **12 links (0.16%)** reuse `realproperty` directly (`f.realpropertyid = pf.realpropertyid`).
  - **478 links (6.27%)** instantiate a new land parcel where `rp.previd IS NULL` (primarily Subdivisions `SD` and Transfers `TR`).
  - **0 links (0.00%)** demonstrate an asymmetric conflicting target pointer.

---

## 3. Workstream 2 — RPUMaster Population Validation & Pointer Integrity

### 3.1 Population Cardinality & Key Verification (Query 2.0)
Query 2.0 explicitly evaluates distinct keys across the relationship path `faas -> rpu -> rpumaster`:
- **Total `faas` rows:** **18,841**; distinct `f.objid`: **18,841**; distinct `f.rpuid`: **18,841**.
- **Joined `rpu` rows:** Distinct `r.objid`: **18,841**; distinct `r.rpumasterid`: **10,121**.
- **Joined `rpumaster` rows:** Distinct `rm.objid`: **10,121**.
- **Base table counts independently:**
  - `rpu`: **18,843 rows** (18,843 distinct `objid`; exactly 2 unlinked records: `RPU4e19689c:1775bec1612:a92` and `RPU5c24dc5:16fe44d2423:-2bc6`).
  - `rpumaster`: **27,661 rows** (27,661 distinct `objid`).
- *Cardinality Proof:* The relationship between `faas` and `rpu` is strictly **1:1** across the 18,841 FAAS records (0 missing RPU foreign keys, 0 duplicate RPU associations). The relationship between `rpu` and `rpumaster` is **N:1** (18,841 RPUs map to 10,121 distinct RPUMaster parcel roots).

### 3.2 Population & Four-Category Pointer Breakdown (Query 2.1)
Query 2.1 evaluates pointer distribution with `LEFT JOIN`s and MySQL's NULL-safe comparison operator `<=>`:

| FAAS State | Total FAAS | Missing RPU Rel | Missing RM Rel | Both Match (`<=>`) | Only FAAS Matches | Only RPU Matches | Neither Matches |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **`CURRENT`** | 9,704 | **0** | **0** | 9,617 (99.10%) | **0** | **0** | **87 (0.90%)** |
| **`CANCELLED`** | 8,899 | **0** | **0** | 187 (2.10%) | **0** | **0** | **8,712 (97.90%)** |
| **`INTERIM`** | 228 | **0** | **0** | 211 (92.54%) | **0** | **0** | **17 (7.46%)** |
| **`FORAPPROVAL`** | 9 | **0** | **0** | 9 (100.00%) | **0** | **0** | **0 (0.00%)** |
| **`PENDING`** | 1 | **0** | **0** | 0 (0.00%) | **0** | **0** | **1 (100.00%)** |
| **TOTAL** | **18,841** | **0** | **0** | **10,024 (53.20%)** | **0 (0.00%)** | **0 (0.00%)** | **8,817 (46.80%)** |

> [!IMPORTANT]
> **Observed Pointer-Match Distribution:** Across all 18,841 FAAS records, exactly **0 records** match on `currentfaasid` alone and **0 records** match on `currentrpuid` alone. Pointers match together or fail to match together in the observed snapshot. Maintenance behavior (synchronous vs asynchronous) remains unverified because backend service code was not inspected.

### 3.3 Refined Resolution of Pointers in the 87 `CURRENT` Mismatch Cases (Query 2.2)
Query 2.2 separates NULL pointer values from populated unresolvable IDs, evaluating FAAS and RPU targets independently:

| FAAS Pointer Target Status | RPU Pointer Target Status | Count | Representative IDs | Status / Classification |
| :--- | :--- | :---: | :--- | :--- |
| **POPULATED UNRESOLVED** | **POPULATED UNRESOLVED** | **54** | `F-154c3657:1757306b8c7:-2ca6` (targets missing `F6cbfd73c:18735cdc683:3f45` / `RPU6cbfd73c:18735cdc683:3f47`) | **Unresolved; requires documentary or backend evidence.** 0 pointers are NULL; all 54 contain populated IDs absent from the database. |
| **RESOLVED (`INTERIM`)** | **RESOLVED (`INTERIM`)** | **11** | `F-12588285:18550d6a3b4:-2048` (targets `F-273574b2:19ea997a4f7:-5d5d`, TD `06454`) | **Unresolved hypothesis (supported by workflow state).** Pointers reference unapproved draft records. |
| **RESOLVED (`CANCELLED`)** | **RESOLVED (`CANCELLED`)** | **10** | `F-22a9e8dd:18ee9570f0d:1aef` (targets `CANCELLED` `F6cfdda65:185ec042e4b:4319`) | **Confirmed internal contradiction.** Active `CURRENT` record has pointers referencing an already cancelled predecessor. |
| **RESOLVED (`CURRENT`)** | **RESOLVED (`CURRENT`)** | **6** | `F-3fd3e567:1750abd4a54:-6c7c` (targets `CURRENT` `F-5a4d8582:1879bf2818f:32ee`) | **Unresolved; requires documentary evidence.** Pointers reference another `CURRENT` record on the same parcel. |
| **RESOLVED (`FORAPPROVAL`)** | **RESOLVED (`INTERIM`)** | **4** | `F-2cd0fe3c:18599d4870a:669a` (FAAS is `FORAPPROVAL`, RPU is `INTERIM`) | **Unresolved hypothesis (supported by workflow state).** Pointers reference draft records in provincial review. |
| **RESOLVED (`INTERIM`)** | **RESOLVED (`CURRENT`)** | **2** | `F-1983dc94:16f879d13d2:-22da` (FAAS is `INTERIM`, RPU is `CURRENT`) | **Unresolved hypothesis (supported by workflow state).** Mixed state between target FAAS and RPU. |
| **`IS NULL`** | *Any Status* | **0** | *None* | Zero NULL pointers observed among the 87 cases. |
| **TOTAL** | — | **87** | — | — |

### 3.4 RPUMaster Reference-Coverage Breakdown Across Base Population (Query 2.3 & 2.4)
Evaluating reference coverage across the entire base table of **27,661 `rpumaster` records**:
- **Total `rpumaster` rows in database:** **27,661**.
- **Referenced by any RPU:** Exactly **10,123 records** (36.60% of base rows).
  - *Reconciliation of 10,121 vs 10,123 Roots (Query 2.3):* Across all 18,841 FAAS-linked RPUs, exactly **10,121 distinct RPUMasters** are referenced. The 2 additional referenced roots belong to the 2 RPUs lacking a FAAS link:
    1. `RPU4e19689c:1775bec1612:a92` (`state = 'INTERIM'`, land): `rpumasterid` self-references `RPU4e19689c:1775bec1612:a92`. Both `currentfaasid` and `currentrpuid` are `NULL`.
    2. `RPU5c24dc5:16fe44d2423:-2bc6` (`state = 'CURRENT'`, land): `rpumasterid` self-references `RPU5c24dc5:16fe44d2423:-2bc6`. Target `currentrpuid` resolves to itself, but target `currentfaasid = 'F5c24dc5:16fe44d2423:-2bca'` is absent from the `faas` table.
- **Not referenced by any RPU (Query 2.4B):** Exactly **17,538 records** (63.40% of base rows):
  - **17,431 records (99.39% of unreferenced):** Both `currentfaasid` and `currentrpuid` contain populated target IDs that are **unresolved** (absent from both `faas` and `rpu` tables).
  - **107 records (0.61% of unreferenced):** Both `currentfaasid` and `currentrpuid` are **NULL or blank**.
  - *Classification:* **Unresolved; requires documentary or backend evidence.** These records are documented as unreferenced roots; they are not labeled as confirmed orphans, corrupt records, or obsolete records solely from these snapshot results.
- **Pointer Resolution Among Referenced Roots (10,123 Roots - Query 2.4C):**
  - **10,024 roots (99.02%):** Both pointer targets resolve to existing records in `faas` and `rpu`.
  - **88 roots (0.87%):** Both pointer targets are populated but unresolved (absent from `faas` and `rpu`).
  - **10 roots (0.10%):** Both `currentfaasid` and `currentrpuid` are NULL or blank.
  - **1 root (0.01%):** `RPU5c24dc5:16fe44d2423:-2bc6` (unresolved FAAS pointer `F5c24dc5:16fe44d2423:-2bca`, but resolvable RPU pointer resolving to itself).
  - *Check Sum:* $10,024 + 88 + 10 + 1 = 10,123$ (100.0%).

---

## 4. Workstream 3 — Cancellation & Successor Consistency

### 4.1 Delimiter-Aware Cancellation Metrics (Query 3.1)
Across all **9,241 electronic predecessor links**:
- **Predecessor State is `CANCELLED`:** **9,199 links (99.55%)**; **42 links (0.45%)** point to predecessors not marked `CANCELLED`.
- **Predecessor `canceldate` Populated:** **9,203 links (99.59%)**; **38 links (0.41%)** have `canceldate IS NULL`.
- **Successor TD Matched in `cancelledbytdnos`:** **8,879 links (96.08%)** match using delimiter-aware matching (`FIND_IN_SET(TRIM(f.tdno), REPLACE(pf.cancelledbytdnos, ' ', '')) > 0`).
- **All Three Conditions Satisfied Simultaneously:** **8,868 links (95.96%)**.

### 4.2 Investigation of Predecessors Not Marked CANCELLED (Query 3.2)
The 42 links where the predecessor is not cancelled break down into:
- **Predecessor `INTERIM` $\to$ Successor `INTERIM` / `FORAPPROVAL`:** **12 links**. Draft-to-draft workflows.
- **Predecessor `CURRENT` $\to$ Successor `INTERIM` Draft:** **21 links (19 distinct predecessors)**. Unresolved hypothesis: predecessor remains `CURRENT` and taxable until the successor draft is formally approved.
- **Predecessor `CURRENT` $\to$ Successor `FORAPPROVAL` Draft:** **5 links (5 distinct predecessors)**. Unresolved hypothesis: provincial review pending; property remains active under predecessor.
- **Predecessor `CURRENT` $\to$ Successor `CURRENT` (Dual-Active Conflict):** Exactly **4 links (4 distinct predecessors)**:
  1. Pred `10-031-09440` (`CURRENT`) $\to$ Succ `22-010-0031-00269` (`CURRENT`, approved 2023-04-21).
  2. Pred `10-031-09454` (`CURRENT`) $\to$ Succ `22-010-0031-00273` (`CURRENT`, approved 2023-04-21).
  3. Pred `22-010-0001-01001` (`CURRENT`) $\to$ Succ `22-010-0001-05022` (`CURRENT`, approved 2023-02-21).
  4. Pred `10-001-02538` (`CURRENT`) $\to$ Succ `22-010-0001-00205` (`CURRENT`, approved 2023-01-09).
  - *Classification:* **Confirmed internal contradiction** (predecessor cancellation step omitted during successor approval).

### 4.3 Missing Successor References in `cancelledbytdnos` (Query 3.3)
Query 3.3 evaluates all predecessor links where the predecessor FAAS state is `CANCELLED`:
- **Eligible Link Denominator:** Exactly **9,199 links** (resolving to **8,872 distinct cancelled predecessor FAAS** and **9,188 distinct successor FAAS**).
- **Missing Successor Reference Links:** Exactly **330 links** (3.59% of eligible links) across **104 distinct predecessor FAAS** and **330 distinct successor FAAS**.
- **Component Breakdown:**
  1. **Successor TD is NULL or blank:** **3 links** (`F-2b4de200:198ba763213:-6e1f`, `F-49758b20:18ebc41cf6a:-3b51`, `F-5fddd44a:187f89bdb47:305`).
  2. **Predecessor `cancelledbytdnos` is NULL or blank despite populated successor TD:** **1 link** (`PF-2cd0fe3c:18599d4870a:6699`, pred `22-010-0024-00201`).
  3. **Populated cancellation text lacks the successor TD token:** **326 links** (e.g., text lists alternative or predecessor references).
- *Classification:* **Unresolved; workflow cause unverified.** Whether caused by batch script processing, manual UI entry omissions, or legacy data migration remains unproven without audit logs.

---

## 5. Workstream 4 — Assessment & Treasury Integrity

### 5.1 Completeness Check: RPUs Without Assessment Lines (Query 4.2)
Kept strictly separate from value consistency checks:
- **Total `rpu` population:** **18,843 records**.
- **RPUs without assessment lines:** Exactly **15 records** (13 linked to FAAS, 2 unlinked records):
  - **2 `CURRENT` Land Lots:** `RPU-7e12f64e:1a12e268225:-7874` (TD `22-010-0001-09734`) and `RPU-7e12f64e:1a12e268225:-7af7` (TD `22-010-0001-09733`). Both represent child lots of a subdivision in draft completion.
  - **1 `CANCELLED` Improvement:** `RPU-49edd98d:176d4e5e3cd:-1e8f` (TD `10-006-09844`, building reassessment).
  - **10 `INTERIM` / `PENDING` Drafts:** Incomplete draft records.
  - **2 Unlinked Historical RPUs:** `RPU4e19689c:1775bec1612:a92` and `RPU5c24dc5:16fe44d2423:-2bc6`.

### 5.2 Value Consistency: Evaluated ONLY for RPUs with Assessment Lines (Query 4.3)
Query 4.3 evaluates value consistency exclusively for the **18,828 RPUs** possessing at least one assessment line in `rpu_assessment` (**22,430 total lines**), testing NULL values explicitly:

| Valuation Reconciliation Category | RPU Count | Pct of Evaluated | Min Difference | Max Difference | Average Difference | Evidential Status |
| :--- | :---: | :---: | :---: | :---: | :---: | :--- |
| **`totalav` or `sum(av)` IS NULL** | **0** | 0.00% | — | — | — | Directly established by database snapshot |
| **Exact Match ($\le 0.01$)** | **15,849** | 84.18% | ₱0.00 | ₱0.01 | ₱0.000001 | Directly established by database snapshot |
| **Difference $< 10$** | **2,974** | 15.80% | ₱0.02 | ₱5.00 | ₱2.60 | **Unresolved hypothesis (supported by calculation)** |
| **Difference $\ge 10$** | **5** | 0.03% | ₱350.00 | ₱2,070.00 | ₱838.32 | **Unresolved; requires documentary evidence** |
| **TOTAL EVALUATED** | **18,828** | **100.00%** | — | — | — | **$15,849 + 2,974 + 5 = 18,828$** |

> [!NOTE]
> Query 4.3 confirms that 0 records have NULL `totalav` or NULL aggregate assessment values. The 2,974 records with Difference $< 10$ exhibit component market value and rate calculations that produce fractional centavo amounts before being rounded at the line vs total grain (min ₱0.02, max ₱5.00, average ₱2.60). This is classified as an **unresolved hypothesis supported by calculation**.
>
> The 5 large discrepancies ($\ge 10$) remain unexplained anomalies:
> 1. `RPU2acc2502:17501d45530:-66d7` (`CURRENT`, TD `10-031-09399`): `totalav = 150.00`, lines sum to `2,220.00` (diff: `-2,070.00`).
> 2. `RPU116f54e4:178c8807423:6a15` (`INTERIM`, TD `10-029-09559`): `totalav = 110.88`, lines sum to `942.48` (diff: `-831.60`).
> 3. `RPU1f1a5c7b:174f6d75476:2106` (`CANCELLED`, TD `10-009-08470`): `totalav = 280.00`, lines sum to `820.00` (diff: `-540.00`).
> 4. `RPU47d606b:17784196be3:2ee8` (`CANCELLED`, TD `10-013-10138`): `totalav = 1,390.00`, lines sum to `1,790.00` (diff: `-400.00`).
> 5. `RPU1f1a5c7b:174f6d75476:1b6e` (`CANCELLED`, TD `10-009-09114`): `totalav = 90.00`, lines sum to `440.00` (diff: `-350.00`).

### 5.3 Treasury Slice Reconciliation (Query 4.4)
Query 4.4 uses `EXISTS` subqueries, guaranteeing that every row in `rptledgerfaas` (**22,155 rows**) contributes exactly once:

| Category # | Slice Categorization | Foreign Key `faasid` Status | TD Number Match Status | Row Count | Pct of Total | Verified Sum Check |
| :---: | :--- | :--- | :--- | :---: | :---: | :---: |
| **Cat 1** | **Populated Resolving Slices** | Populated & Resolves to `faas.objid` | — | **18,920** | 85.39% | $\checkmark$ Part of 22,155 |
| **Cat 2** | **Populated Unresolved Slices** | Populated but absent from `faas` | — | **510** | 2.30% | $\checkmark$ Part of 22,155 |
| **Cat 3** | **NULL/Blank ID Matching TD** | `IS NULL` or blank | Matches `faas.tdno` | **2,426** | 10.95% | $\checkmark$ Part of 22,155 |
| **Cat 4** | **NULL/Blank ID Nonmatching TD**| `IS NULL` or blank | Absent from `faas.tdno` | **299** | 1.35% | $\checkmark$ Part of 22,155 |
| **TOTAL** | **All Categories Summed** | — | — | **22,155** | **100.00%** | **$18,920 + 510 + 2,426 + 299 = 22,155$** |

> [!NOTE]
> Category 2 references **505 distinct missing FAAS IDs**. The historical causes for missing FAAS references or unpopulated foreign keys remain unverified.

### 5.4 Multi-Ledger Associations (Query 4.5A)
Query 4.5A identifies FAAS records associated with multiple distinct ledger IDs:
- Exactly **2 FAAS records** are linked to multiple distinct ledger IDs:
  1. `F4799b60:16fa7c62b72:-46e7` (TD `10-001-03489`): linked to ledgers `F4799b60:16fa7c62b72:-46e7` (`PENDING`) and `RLM-58f50162:1865b8896b5:-7e0a` (`APPROVED`). Both slices cover 2019–2022.
  2. `F6d95e1c6:181fb5bde0e:-65e5` (TD `10-009-12207`): linked to ledgers `F6d95e1c6:181fb5bde0e:-65e5` (`CANCELLED`, 2022) and `F131af9ef:186c035838b:-447a` (`APPROVED`, 2020–2022).
- *Classification:* **Confirmed internal contradiction** (multiple ledger mappings). Whether these represented simultaneous accounting conflicts cannot be proven without time-specific accounting logs.

### 5.5 Repeated Pairs & Temporal Multi-Slice Breakdown (Queries 4.5B & 4.5C)
Repeated `(rptledgerid, faasid)` slices were evaluated across distinct pair populations and total slice rows:
- **Total populated `(rptledgerid, faasid)` pairs in database:** Exactly **19,098 distinct pairs** (across 19,430 populated slices).
- **Repeated pairs (Pair Grain):** Exactly **157 repeated pairs** out of 19,098 distinct populated pairs (**0.82% of pairs**).
- **Affected slice rows (Row Grain):** Exactly **489 rows** out of 22,155 total slices (**2.21% of slices**).
- **Excess rows above one per pair:** Exactly **332 excess rows** ($489 - 157 = 332$).
- **Temporal Bounds Inspection (Query 4.5C):**
  - **124 pairs (78.98%):** Span **distinct year bounds** (e.g., 2019–2020 vs 2021–2022). Unresolved hypothesis: sequential multi-year assessment periods.
  - **32 pairs (20.38%):** Share **exact identical year and quarter bounds**. Unresolved hypothesis: backtax adjustments or duplicate slice postings.
  - **1 pair (0.64%):** Shares the same year span but distinct quarter bounds (`F2e9ced45:1729cfde703:-7f65`).

---

## 6. Workstream 5 — Exception Classification Summary

The updated exception catalog in [`etracs_data_integrity_exceptions.csv`](file:///d:/CODE/assessor/etracs-scripts/etracs_data_integrity_exceptions.csv) reconciles all exception groups:

| Exception Group | Category | Anomaly Description | Count | Denominator | Evidential Classification |
| :--- | :--- | :--- | :---: | :---: | :--- |
| **EXC-01** | Lineage | Broken `prevfaasid` FK in `faas_previous` | 28 | 18,855 links | Confirmed referential-integrity issue |
| **EXC-02** | Lineage | Direct self-link (`faasid = prevfaasid`) | 2 | 18,855 links | Confirmed internal contradiction |
| **EXC-03** | Lineage | Same-TD link (`tdno = prevtdno`) | 17 | 18,855 links | Unusual but plausibly legitimate |
| **EXC-04** | RPUMaster | Current pointers reference missing record | 54 | 9,704 CURRENT | Unresolved; requires documentary evidence |
| **EXC-05** | RPUMaster | Current pointers reference draft record | 17 | 9,704 CURRENT | Unresolved hypothesis (supported by workflow state) |
| **EXC-06** | RPUMaster | Current pointers reference cancelled record | 10 | 9,704 CURRENT | Confirmed internal contradiction |
| **EXC-07** | RPUMaster | Current pointers reference another CURRENT | 6 | 9,704 CURRENT | Unresolved; requires documentary evidence |
| **EXC-08** | Cancellation| Predecessor remains CURRENT (Dual-active) | 4 | 9,241 links | Confirmed internal contradiction |
| **EXC-09** | Cancellation| Predecessor CURRENT while successor draft | 26 | 9,241 links | Unresolved hypothesis (supported by workflow state) |
| **EXC-10** | Cancellation| Missing successor reference in cancelledby | 330 | 9,199 eligible links | Unresolved; workflow cause unverified |
| **EXC-11** | Assessment | RPUs with zero assessment lines | 15 | 18,843 total RPUs | Unusual but plausibly legitimate |
| **EXC-12** | Assessment | Difference $< 10$ between totalav and sum(av)| 2,974 | 18,828 RPUs w/ lines| Unresolved hypothesis (supported by calculation) |
| **EXC-13** | Assessment | Significant AV discrepancy ($\ge 10$) | 5 | 18,828 RPUs w/ lines| Unresolved; requires documentary evidence |
| **EXC-14** | Treasury | Broken `faasid` FK in `rptledgerfaas` | 510 | 22,155 slices | Confirmed referential-integrity issue |
| **EXC-15** | Treasury | Legacy slices with NULL ID, matched TD | 2,426 | 22,155 slices | Expected or supported historical pattern |
| **EXC-16** | Treasury | Slices with NULL ID, unmatched TD | 299 | 22,155 slices | Expected or supported historical pattern |
| **EXC-17** | Treasury | FAAS linked to multiple distinct ledger IDs| 2 | 18,841 FAAS | Confirmed internal contradiction |
| **EXC-18A**| Treasury Pairs| Distinct repeated pairs in `rptledgerfaas` | 157 pairs | 19,098 distinct pairs| Unusual but plausibly legitimate |
| **EXC-18B**| Treasury Slices| Total slice rows in repeated pairs | 489 rows (332 excess)| 22,155 slices | Unusual but plausibly legitimate |
| **EXC-19** | RPUMaster | Roots not referenced by any RPU | 17,538 roots | 27,661 base roots | Unresolved; requires documentary evidence |

---

## 7. Phase 4.2 Closeout Log & Verification Summary

| Item / Claim Area | Phase 4.1 Finding | Phase 4.2 Corrected Finding | Evidential Basis & Status |
| :--- | :--- | :--- | :--- |
| **1. Joint Predecessor Status** | Independent aggregate counts. | Executed Query 1.1B cross-tabulation: 9,586 both NULL, 9,241 both resolved, 28 both unresolved, 0 asymmetric. Verified 100% row-by-row status agreement. | Executed SQL (Query 1.1B). |
| **2. Removal of Historical Inferences** | Attributed missing records to migration or deletion. | Removed unsupported historical causes. Preserved exact IDs, counts, and NULLs; labeled causes as unresolved. | Methodological correction. |
| **3. State Terminology Qualification** | Used "active FAAS/RPU" loosely. | Qualified as "records in the faas/rpu table" unless explicitly filtered by `state = 'CURRENT'`. | Terminology correction. |
| **4. RPUMaster Pointer Targets (87 Cases)** | Grouped 54 cases as missing. | Executed Query 2.2: 0 pointers are NULL; all 54 target populated IDs absent from both faas and rpu tables. Reported FAAS and RPU target statuses independently. Replaced synchronous maintenance claim with observed distribution. | Executed SQL (Query 2.2). |
| **5. NULL Assessment Handling** | Tested comparison only on valid values. | Executed Query 4.3 testing NULL totalav and NULL sum(av): 0 records have NULL values. 15,849 exact matches, 2,974 diff $<10$, 5 diff $\ge 10$. Reconciled all 18,828 RPUs. Labeled rounding as hypothesis. | Executed SQL (Query 4.3). |
| **6. Missing Successor References** | Referred to 330 links as "token missing". | Categorized aggregate 330 links as "missing successor reference": 3 succ TD blank/NULL, 1 cancelledby blank/NULL, 326 populated text missing token. Preserved component counts. | Executed SQL (Query 3.3). |
| **7. Multi-Ledger FAAS Classification** | Called 2 cases "simultaneous conflicts". | Reported as FAAS IDs associated with multiple distinct ledger IDs. Removed "simultaneous conflict" label due to lack of time-specific accounting logs. | Executed SQL (Query 4.5A). |
| **8. Multi-Slice Denominators (EXC-18)** | Reported 157 pairs against slice denominator. | Split into EXC-18A (157 pairs / 19,098 distinct pairs) and EXC-18B (489 rows / 22,155 total slices, with 332 excess rows). | Executed SQL (Queries 4.5B & 4.5C). |
| **9. Causal Downgrades** | Inferred workflow bypasses and script errors. | Downgraded same-TD links, vacant module tables, and multi-ledger links from proven causes to observed relationship patterns. | Methodological correction. |
| **10. RPUMaster Coverage & Root Counts** | Stated 10,121 roots across FAAS join. | Executed Queries 2.3 & 2.4: Base table has 27,661 roots. Reconciled 10,121 roots (FAAS-linked RPUs) vs 10,123 roots (all RPUs) by identifying the 2 unlinked RPUs (`RPU4e19689c...` and `RPU5c24dc5...`). Documented 17,538 unreferenced roots (17,431 populated unresolved, 107 NULL/blank). Among 10,123 referenced roots: 10,024 both resolve, 88 both unresolved, 10 both NULL/blank, 1 unresolved FAAS / resolvable RPU (`RPU5c24dc5...`). Unreferenced roots classified as unresolved (not labeled orphans/corrupt). | Executed SQL (Queries 2.3 & 2.4). |

---

## 8. Appendix — Confirmed Findings vs Unresolved Hypotheses

### Confirmed Database Findings (Direct Proof)
1. **Row-by-Row Predecessor Resolution Agreement:** In 100% of rows in `faas_previous` (18,855 of 18,855), `prevfaasid` and `prevrpuid` share identical nullability and resolution status (9,586 both NULL, 9,241 both resolved, 28 both unresolved, 0 asymmetric).
2. **Zero Asymmetric Matches Invariant:** Across 18,841 FAAS records, RPUMaster pointers either match together or fail to match together: exactly 0 records match on one pointer alone.
3. **Dual-CURRENT Active Contradictions:** Exactly 4 predecessor FAAS records remain in `CURRENT` state despite having electronic successors that are also active and approved in `CURRENT` state.
4. **Assessment Orphan Impossibility:** Zero orphan records exist in `rpu_assessment`; all 22,430 lines resolve to active `rpu` entities.
5. **Ledger Uniqueness Invariant:** `rptledger.tdno` contains strictly zero duplicate TD numbers across all 10,241 records. Matched FAAS-to-ledger records are strictly 1:1 on both sides.

### Remaining Unresolved Hypotheses & Source Limitations
1. **Origin of Unresolved Foreign Keys:** The historical cause of the 20 missing FAAS IDs in `faas_previous`, the 54 missing target IDs in `rpumaster`, and the 505 missing IDs in `rptledgerfaas` cannot be established without original migration or server audit logs.
2. **Root Cause of the 5 Large Assessment Discrepancies:** Whether the 5 records with AV differences $\ge 10$ pesos represent manual data corrections or legacy software bugs prior to 2022 remains unverified.
3. **Accounting Impact of Multi-Ledger FAAS:** Whether the 2 FAAS records linked to multiple ledgers produced duplicate tax assessments or sequential ledger migrations cannot be proven without transaction-dated payment and billing records.
4. **Causal Mechanism for Missing Successor References:** Whether the 330 links lacking successor TD tokens in `cancelledbytdnos` resulted from mass cancellation scripts, UI entry omissions, or legacy software behavior remains unproven.
