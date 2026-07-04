# farm_financial_mgmt — PHASE 5 SPEC (Enterprise costing, depreciation, balance sheet)

Detailed specification for Phase 5, previously "horizon / not detailed" in `SPEC.md` §8/§11
and `TASKS.md`. Expands those sections; `CLAUDE.md` conventions and invariants still apply.

Phase 5 is the **capital and full-cost layer**: it takes the ledger (Phases 1–2), the tax
mapping (Phase 3) and the interchange (Phase 4) and adds the pieces that turn a cash-basis
income/expense record into **enterprise profitability** and a **net-worth statement** — the
things a rancher's lender and CPA ask for that the cash ledger alone can't answer.

---

## 1. Purpose & scope

**In scope:**
- **Depreciation engine** — straight-line + MACRS (GDS/ADS), half-year / mid-quarter / mid-month
  conventions, Section 179 expensing, bonus (special) depreciation. Drives Schedule F line 14.
- **Asset valuation** — book value (from depreciation) and market value (livestock via
  `farm_cattle_prices`, reusing the Phase-2 pattern) for the balance sheet.
- **Liabilities** — loans/notes with maintained balances; principal vs interest split on payments.
- **Balance sheet / net-worth statement** — assets (book + market) minus liabilities = equity.
- **Revenue-share overhead allocation** — allocate overhead/fixed costs (the *non*-allocatable
  pool + depreciation) to enterprises by **relative value of production (gross revenue share)**,
  the complement to the Phase-2 AUE direct-cost pool. Produces true **enterprise P&L**.
- **Tax completion** — Form 4562 (depreciation + §179), Form 4797 gain/loss with **depreciation
  recapture** on capital-asset sales, and filling the Schedule F line-14 placeholder.

**Explicitly OUT of scope (hard boundaries):**
- **Not a double-entry general ledger.** The module is a cash-basis ledger, not a bookkeeping
  system with a chart of accounts and debits/credits. The "balance sheet" is a **management
  net-worth / market-value statement**, not GAAP double-entry, and there is **no cash-account
  reconciliation** (we do not track bank balances).
- No accounts-payable/receivable subsystem (payment_status stays the lightweight signal it is).
- No audited financials; outputs are for **management + tax planning**.
- MACRS uses the published IRS percentage tables (Pub 946), not first-principles convention math.

---

## 2. Architectural decisions

Consistent with Option C (SPEC §2): capital/liability data are **purpose-built content
entities**, not farmOS assets or logs and not fields bolted onto the ledger.

1. **Depreciation lives on a new `depreciable_asset` content entity**, not on the farmOS
   equipment/land asset (avoids coupling to farmOS asset schema and keeps tax params out of the
   physical-asset record) and not on the acquiring transaction (a transaction is a payment event;
   a depreciable asset has a multi-year life independent of it). The depreciable asset *references*
   both the farmOS asset (optional, for the physical link) and the acquiring transaction (basis
   source).
2. **Depreciation is always computed, never entered.** Schedule F line 14 stays absent from manual
   transaction entry (the placeholder Phase 3 left). The engine derives it. This is why capital
   categories are `tax_form = none` and excluded from the Part II deduction rollup.
3. **Overhead allocation is revenue-share, structurally distinct from the AUE pool** (SPEC §7).
   AUE allocates *direct/variable, consumption-scaling* costs; revenue-share allocates
   *overhead/fixed* costs by value of production. The two never mix; the `EnterpriseCostAllocator`
   composes them for a full enterprise cost.
4. **Both new entities are revisionable** (basis, method, balances change over time and must audit).
5. **Valuation is behind a swappable provider interface** (like `AueProviderInterface`): book value
   from the engine; livestock market value from `farm_cattle_prices` when present; a plain
   manual-value fallback otherwise. No hard dependency.
6. **All Phase 5 install steps are `hook_update_N` (in place).** The reinstall loop was retired at
   the Phase-3 cutover; the module holds data.

---

## 3. Data model

### 3.1 `depreciable_asset` (content entity, revisionable)

| Field | Type | Notes |
|---|---|---|
| `id`,`uuid`,`revision_id` | base | |
| `label` | string | Auto: farm asset label or acquisition label. |
| `farm_asset` | entity_ref → `asset` (equipment/land/structure/animal) | Optional physical link. |
| `acquisition` | entity_ref → `financial_transaction` | Basis source; `basis` defaults to its `total`. |
| `basis` | decimal(14,2) | Cost/other basis (net of trade-in). |
| `in_service_date` | datetime (date) | Depreciation start (may differ from purchase date). |
| `property_class` | list | `3yr`,`5yr`,`7yr`,`10yr`,`15yr`,`20yr` (farm class life: breeding cattle/vehicles 5, machinery 7, single-purpose ag structures 10, land improvements 15, general-purpose buildings 20). |
| `method` | list | `macrs_gds` (150% DB for farm, default), `macrs_ads` (straight-line), `straight_line`. |
| `convention` | list | `half_year`, `mid_quarter`, `mid_month`. |
| `salvage_value` | decimal | Straight-line only (MACRS ignores salvage). |
| `section_179` | decimal | Amount elected to expense in year 1 (reduces depreciable basis). |
| `bonus_pct` | integer | Special depreciation allowance % (e.g. 0/40/60/100 by year). |
| `disposed_date` | datetime, optional | Sale/retirement; stops depreciation, triggers 4797. |
| `disposal_txn` | entity_ref → `financial_transaction`, optional | The sale (proceeds). |
| `enterprise` | entity_ref → `animal_type` species term, optional | Enterprise attribution (mirrors `financial_line.enterprise`). |
| `notes` | text_long | |
| `owner`,`created`,`changed`,revision log | base | |

### 3.2 `financial_liability` (content entity, revisionable)

| Field | Type | Notes |
|---|---|---|
| `id`,`uuid`,`revision_id` | base | |
| `label` | string | |
| `lender` | entity_ref → `financial_contact` | |
| `liability_type` | list | `operating_note`,`term_loan`,`mortgage`,`ccc_loan`,`other`. |
| `original_principal` | decimal(14,2) | |
| `interest_rate` | decimal | APR, informational. |
| `origination_date` | datetime (date) | |
| `term_months` | integer | |
| `current_balance` | decimal(14,2), computed+stored | Original − Σ principal payments; recomputed on payment. |
| `enterprise` | entity_ref → species term, optional | |
| `notes` | text_long | owner/created/changed/revision. |

**Principal/interest split.** A loan payment is a `financial_transaction` whose lines split into
an **interest** portion (an existing `Interest – …` category → Schedule F) and a **principal**
portion. Principal reduces the liability but is **not** a Schedule F deduction. Implementation:
add an optional `liability` entity_ref + `principal_portion` decimal to `financial_line`
(installed via update hook). A postsave (extending the totalizer pattern) rolls principal
payments into `financial_liability.current_balance`. Principal-portion lines are excluded from
P&L/tax rollups (like the 1b basis lines).

### 3.3 Reused hooks (already in place)

- `capital` category flag → identifies purchases that create depreciable assets (Task 5.1 offers
  to create a `depreciable_asset` when a capital-category line is saved).
- `tax_form = form_4797` on breeding-stock sales → the 4797 gain/loss path.
- `enterprise` field on `financial_line` → revenue-share enterprise attribution.
- Revisionable transaction/line → audit trail extends naturally to the new entities.

---

## 4. Services

- **`DepreciationEngine`** — pure computation over a `depreciable_asset`:
  - `annualDepreciation($asset, int $year): float`
  - `schedule($asset): array` (per-year: depreciation, accumulated, book value)
  - `accumulatedDepreciation($asset, int $throughYear): float`
  - `bookValue($asset, int $asOfYear): float` (floored at salvage / zero)
  - `totalForYear(int $year, ?array $enterprise_tids = null): float` → Schedule F line 14
  - MACRS tables (Pub 946, GDS 150%DB + ADS) embedded as constants; §179 applied first, then
    bonus on remaining basis, then MACRS on the rest.
- **`AssetValuationProviderInterface` + `BookValueProvider`** (default) — current value of a
  capital asset (book value). A `LivestockMarketValueProvider` values breeding stock at market
  (reuse the `RanchEconomics`/`farm_cattle_prices` $/cwt path). Swappable via service alias.
- **`EnterpriseCostAllocator`** — full enterprise cost = direct (attributable + AUE pool, from
  `RunningCostCalculator`) **+** overhead (revenue-share). Overhead pool = non-allocatable expense
  + depreciation for the period; allocated to each enterprise by its share of **gross revenue**
  (income lines, by `enterprise`). Distinct from AUE by construction.
- **`BalanceSheetBuilder`** — assembles the net-worth statement (assets − liabilities = equity)
  from valuation providers + `financial_liability`.

---

## 5. Reports (under Financial → Reports, gated like the others)

1. **Depreciation schedule** — per depreciable asset: basis, §179, method, per-year depreciation,
   accumulated, book value; herd/period total = Schedule F line 14.
2. **Net-worth statement (balance sheet)** — Assets: capital book values + livestock market value
   + (optional entered current-asset lines); Liabilities: from `financial_liability`; Equity =
   Assets − Liabilities. As-of date, with prior-period comparison (uses entity revisions).
3. **Enterprise P&L** — per enterprise: revenue − (direct AUE cost + revenue-share overhead) =
   enterprise net. The full-cost complement to the Phase-2 running cost.
4. **Tax completion** — Form 4562 (depreciation + §179 detail) and Form 4797 (capital-asset sale
   gain/loss with **depreciation recapture**), plus the Schedule F **line 14** figure fed into the
   Phase-3 Tax Summary via the existing `hook_farm_financial_mgmt_tax_summary_alter`.

---

## 6. Build order (tasks)

- **5.1** `depreciable_asset` entity (+ fields, form, list View, routes/menu). Offer-to-create a
  depreciable asset from a saved capital-category line (basis prefilled from the transaction).
  *Exit:* create a depreciable asset from an equipment purchase; basis defaults correctly.
- **5.2** `DepreciationEngine` — straight-line first, then MACRS GDS/ADS tables + conventions +
  §179 + bonus. *Exit:* schedules match IRS Pub 946 worked examples (7-yr machinery, 5-yr
  breeding stock) to the cent.
- **5.3** Depreciation schedule report + feed **Schedule F line 14** into the Tax Summary via the
  alter hook. *Exit:* line 14 in Tax Summary equals the engine's period total.
- **5.4** `financial_liability` entity + `financial_line.liability`/`principal_portion` fields
  (update hook) + balance maintenance postsave; exclude principal from P&L/tax. *Exit:* a loan
  payment splits interest (Sch F) vs principal (balance ↓), and the balance tracks.
- **5.5** `AssetValuationProviderInterface` + `BookValueProvider` + `LivestockMarketValueProvider`.
  *Exit:* book value from the engine; breeding stock valued at market when prices present.
- **5.6** `BalanceSheetBuilder` + net-worth report (with revision-based prior-period compare).
  *Exit:* assets − liabilities = equity reconciles against hand-computed test data.
- **5.7** `EnterpriseCostAllocator` + Enterprise P&L report. *Exit:* enterprise net = revenue −
  direct − revenue-share overhead; overhead splits by gross-revenue share; **AUE pool untouched**.
- **5.8** Form 4562 + Form 4797 (gain/loss + **depreciation recapture**) in the Tax Summary.
  *Exit:* a breeding-cow sale computes gain over remaining basis with recapture on the 4797 list.
- **5.9** Phase-4 export integration — depreciation schedule + net-worth in the CSV export; import
  round-trips the new entities. *Exit:* export→import preserves depreciable assets + liabilities.

---

## 7. Exit criteria (phase)

- Depreciation schedules match IRS Pub 946 examples (straight-line + MACRS, all three conventions).
- Section 179 + bonus reduce basis correctly before MACRS; book value floors at salvage/zero.
- Schedule F line 14 in the Tax Summary equals the engine total for the year.
- Capital-asset sale produces the correct 4797 gain/loss **with depreciation recapture**.
- Net-worth statement reconciles (assets − liabilities = equity) on test data, with a working
  prior-period comparison from entity revisions.
- Enterprise P&L = revenue − direct (AUE) − overhead (revenue-share); the **AUE pool and the
  revenue-share pool never double-count** a cost.
- Loan balances track through interest/principal-split payments; principal is excluded from Sch F.
- Everything deploys via `drush updb && drush cr` (no reinstall); the demo ledger survives.

---

## 8. Invariants & boundaries (do not "simplify" away)

- **Depreciation is computed, never a manual transaction.** Sch F line 14 has no data-entry path.
- **Direct vs overhead is a hard split.** AUE = direct/variable (consumption). Revenue-share =
  overhead/fixed (value of production). A cost is in exactly one bucket; the allocators must not
  both claim it. `allocatable = true` categories feed AUE; the rest + depreciation feed
  revenue-share.
- **Capital is depreciated, not deducted** (already enforced: `capital = true`, `tax_form = none`,
  excluded from Part II).
- **Principal repayment is not an expense.** Only the interest portion is a Schedule F deduction.
- **Book value never negative.** Floored at salvage (straight-line) or zero (MACRS).
- **The balance sheet is a net-worth/market-value statement, not double-entry.** No cash-account.
- **Enterprise scope reuses `enterprise` (animal_type species term)** — one attribution concept
  across lines, depreciable assets, and liabilities.

---

## 9. Pre-flight (before Task 5.1)

1. **MACRS tables** — transcribe the GDS 150%-declining-balance and ADS straight-line percentage
   tables (IRS Pub 946, farm 3/5/7/10/15/20-yr classes, half-year + mid-quarter) as verified
   constants; unit-test against the Pub 946 worked examples before wiring reports.
2. **Basis linkage** — confirm the capital-transaction → `basis` default (transaction `total`,
   net of any trade-in line). Decide trade-in handling (a negative/contra line vs a basis field).
3. **§179 / bonus limits** — the annual §179 cap and bonus % are year-dependent; store as a small
   config table (editable) rather than hard-coding a single year.
4. **Entity installs** — all new entity types + the `financial_line.liability`/`principal_portion`
   fields install via `hook_update_N` (in place), mirroring 10402. No reinstall.
5. **Report gating** — Phase 5 reports respect the existing report permission and (where tax-facing)
   the `tax_planning_enabled` toggle.
