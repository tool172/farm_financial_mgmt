# farm_financial_mgmt — PHASE 5 SPEC (Enterprise costing, depreciation, balance sheet)

Detailed specification for Phase 5, previously "horizon / not detailed" in `SPEC.md` §8/§11
and `TASKS.md`. Expands those sections; `CLAUDE.md` conventions and invariants still apply.

Phase 5 is the **capital and full-cost layer**: it takes the ledger (Phases 1–2), the tax
mapping (Phase 3) and the interchange (Phase 4) and adds the pieces that turn a cash-basis
income/expense record into **enterprise profitability** and a **net-worth statement**.

> **Revision note.** This spec pins the four Phase-5 modeling forks that pre-flight would not
> surface (they precede the MACRS table work): (1) MACRS property-class mapping lives on the
> capital category term; (2) valuation is **dual** (basis *and* market), each driving a different
> report; (3) **raised** breeding stock is a first-class zero-basis, non-depreciable state; (4) the
> balance sheet is asset-side-complete with **entered** cash and liabilities, not auto-derived.

---

## 1. Purpose & scope

**In scope:** depreciation engine (MACRS + straight-line, §179, bonus); dual basis/market
valuation; liabilities; a market-value balance sheet with cost-basis reconciliation;
revenue-share overhead allocation → enterprise P&L; tax completion (Form 4562, Form 4797 with
depreciation recapture, and the Schedule F line-14 figure).

**Explicitly OUT of scope (hard boundaries):**
- **Not a double-entry general ledger.** Cash-basis ledger, no chart of accounts, no debits/credits.
- **Cash position and liabilities are ENTERED, not derived.** An income/expense ledger knows flows,
  not opening balances or non-operating movements, so it cannot compute a cash balance. Phase 5's
  balance sheet is **asset-side-complete** (the depreciation schedule and livestock valuation feed
  the asset side automatically) with **manually entered current assets (cash) and liabilities**.
  Anything more would imply a balance sheet the data can't produce.
- No AP/AR subsystem, no bank reconciliation, no audited financials.
- MACRS via published IRS percentage tables (Pub 946), not first-principles convention math.

---

## 2. The four modeling decisions (settled — build to these)

### 2.1 Property class is tax intelligence on the capital **category**, not code

MACRS treatment is determined by the asset's **property class** and **placed-in-service
convention**, and those fork by asset type. Rather than hard-code a class map, each capital-flagged
`financial_category` (SPEC §12) carries its MACRS defaults — the same "tax intelligence on the
category term" pattern that carries `schedule_f_line`/`tax_form`. New fields on `financial_category`
(Phase 5, populated in place by update hook):

| Field | Type | Notes |
|---|---|---|
| `macrs_class` | list | `3yr`,`5yr`,`7yr`,`10yr`,`15yr`,`20yr`,`none` |
| `depreciation_method` | list | `macrs_gds` (150% DB, farm default), `macrs_ads` (SL), `straight_line` |

**Seed defaults** (editable per operation):
- **Purchase of Breeding Stock** → 5-yr, `macrs_gds`
- **Equipment / Machinery Purchase** → 7-yr, `macrs_gds`
- **Land Improvement** (fences, tile, etc.) → 15-yr, `macrs_gds`
  *(note: some ag fencing is 7-yr — this is exactly why it lives on the category, editable, rather
  than hard-coded; operators set the class their preparer uses.)*
- Single-purpose ag **structures**, if a category is added → 10-yr.
- **Land** itself → `none` (never depreciable).

A `depreciable_asset` **inherits** `macrs_class` + `depreciation_method` from its acquisition
line's category, and may **override** per asset. Class logic thus has a home (the category);
5.1/5.2 read it, they don't encode it.

**Convention is engine-computed, not a field.** Half-year is the default; the engine applies
**mid-quarter** when >40% of the year's total depreciable basis is placed in service in Q4 (a
year-level aggregate test across all assets), and **mid-month** for real property (structures/land
improvements). Only a per-asset mid-month override is exposed.

### 2.2 Valuation is DUAL — basis *and* market — each drives a different report

These answer different questions, so the spec supports both rather than choosing:
- **Basis / book value** (cost less accumulated depreciation) — what depreciation and the **tax-side
  (cost-basis) balance sheet** require. What the IRS cares about.
- **Market value** — what a **managerial balance sheet** and any lending conversation require.

Per FFSC farm-financial standards, the deliverable is a **market-value balance sheet with a
cost-basis reconciliation column alongside** — not one or the other. The `AssetValuationProvider`
returns **both** for every asset. On-screen default view: **managerial / market** (the day-to-day
question), with the cost-basis column available. Forcing a single value builds the wrong balance
sheet for whichever audience you dropped.

### 2.3 Raised vs purchased breeding stock — zero-basis is a first-class state

Under cash-basis accounting a **purchased** breeding animal has a cost basis and depreciates; a
**raised** breeding heifer kept from your own calf crop has, effectively, **zero depreciable
basis** — her rearing costs were already expensed as feed/vet along the way. So a large share of a
cow-calf herd's breeding animals are **non-depreciable**, and the model treats `basis = 0,
non-depreciable` as a **normal state, not an error**.

- `depreciable_asset.basis_type` = `purchased` | `raised`. `raised` ⇒ basis 0, `depreciable = false`;
  the engine skips it (no schedule, no line-14 contribution).
- This reads the **same raised-vs-purchased signal the disposition side already knows** (it drives
  1a/1b/1c cost-basis netting and the Form 4797 routing from Phase 3). Depreciation and disposition
  must agree: a raised breeding animal has **no depreciation to recapture** on sale (its 4797 gain
  is its full proceeds as §1231 gain, no recapture), whereas a purchased one recaptures prior
  depreciation. 5.8 reads `basis_type` for both.

### 2.4 Balance sheet scope — entered cash + entered liabilities, honestly

A balance sheet needs assets, liabilities and equity to balance, and the module tracks neither
cash balances nor debt from the ledger. Phase 5's balance sheet is therefore:
- **Asset side, automatic:** depreciable-asset book/market values + livestock valuation.
- **Current assets (cash), manual:** an entered cash-position line (and any other entered current
  assets). Not derived from the flow ledger.
- **Liabilities, manual:** the `financial_liability` entities (§3.2), maintained by the operator
  (balances reduced by principal-payment tracking).
- **Equity = assets − liabilities.**

The spec is explicit that this is **asset-side-complete with entered cash and liabilities**, not a
fully auto-derived balance sheet — so the report's honesty matches the data we actually hold.

---

## 3. Data model

Two new content entities (Option C), plus the category + line field additions above/below. All
revisionable.

### 3.1 `depreciable_asset`

| Field | Type | Notes |
|---|---|---|
| `label` | string | Auto from farm asset / acquisition. |
| `basis_type` | list | `purchased` \| `raised` (§2.3). `raised` ⇒ non-depreciable. |
| `farm_asset` | entity_ref → `asset` (equipment/land/structure/animal) | Optional physical link. |
| `acquisition` | entity_ref → `financial_transaction` | Basis source (purchased); empty for raised. |
| `basis` | decimal(14,2) | Default = acquisition `total` (purchased); 0 (raised). |
| `in_service_date` | datetime (date) | |
| `macrs_class` | list | Inherited from acquisition category; overridable (§2.1). |
| `depreciation_method` | list | Inherited from category; overridable. |
| `mid_month` | boolean | Real-property convention override; otherwise engine-computed. |
| `salvage_value` | decimal | Straight-line only. |
| `section_179` | decimal | Elected year-1 expensing (reduces basis). |
| `bonus_pct` | integer | Special depreciation allowance % (year-dependent, §9). |
| `disposed_date` | datetime, optional | Stops depreciation; triggers 4797. |
| `disposal_txn` | entity_ref → `financial_transaction`, optional | Proceeds. |
| `market_value` | decimal, optional | Entered/overridden managerial value (else provider-supplied). |
| `enterprise` | entity_ref → species term, optional | Enterprise attribution. |
| `notes`,`owner`,`created`,`changed`,revision | base | |

Derived: `depreciable` = `basis_type == purchased && basis > 0 && macrs_class != none && !disposed`.

### 3.2 `financial_liability`

`lender` (→ contact), `liability_type` (`operating_note`/`term_loan`/`mortgage`/`ccc_loan`/`other`),
`original_principal`, `interest_rate`, `origination_date`, `term_months`, `current_balance`
(computed+stored), `enterprise` (optional), notes/owner/revision. Manually maintained; balance
reduced by principal-payment tracking (§3.3).

### 3.3 Line additions (installed via update hook)

- `financial_line.liability` (→ `financial_liability`) + `financial_line.principal_portion`
  (decimal): a loan payment splits **interest** (an `Interest – …` category → Schedule F) from
  **principal** (reduces `current_balance`, **not** a deduction). Principal-portion is excluded
  from P&L/tax rollups (like the 1b basis lines). A postsave rolls principal into the liability.

### 3.4 Reused hooks (in place)

`capital` flag (which categories create depreciable assets); the new category `macrs_class`/
`depreciation_method`; `tax_form = form_4797` (capital sales → 4797 path); `enterprise` on lines;
revisionable entities (basis/balance/valuation audit + prior-period balance-sheet compare).

---

## 4. Services

- **`DepreciationEngine`** — `annualDepreciation`, `schedule`, `accumulatedDepreciation`,
  `bookValue` (floored at salvage/zero), `totalForYear(int $year, ?array $enterprise_tids)` →
  Sch F line 14. Reads class/method from the asset (inherited from category); applies §179 → bonus
  → MACRS; computes the **year-level mid-quarter test** across all assets placed in service that
  year; skips non-depreciable (`raised`, land, disposed). MACRS tables = Pub 946 constants (§9).
  `accumulatedDepreciation($asset, $through_year)` is the **single authoritative accumulated-
  depreciation value** per asset: the balance-sheet basis column (§2.2) *and* the Form 4797
  recapture calc (5.8) both read it — neither recomputes independently. Same one-authoritative-
  signal discipline as the totalizer; if the balance sheet and 4797 disagreed on how much
  depreciation an animal has taken, the bug would be near-unfindable.
- **`AssetValuationProviderInterface`** returning **both** `basis` (book value from the engine) and
  `market`. `BookValueProvider` (default). `LivestockMarketValueProvider` values breeding stock at
  market (reuse the `RanchEconomics`/`farm_cattle_prices` $/cwt path); equipment market falls back
  to book unless an entered `market_value` is present. Swappable via alias.
- **`EnterpriseCostAllocator`** — full enterprise cost = direct (attributable + AUE pool, from
  `RunningCostCalculator`) **+** overhead (revenue-share). Overhead pool = non-`allocatable`
  expense + **depreciation** for the period; allocated by each enterprise's share of **gross
  revenue** (income lines by `enterprise`). Structurally distinct from AUE (SPEC §7).
- **`BalanceSheetBuilder`** — assets (auto: depreciable book/market + livestock market; manual:
  entered cash/current assets) − liabilities (`financial_liability`) = equity. Produces the market
  column and the cost-basis reconciliation column (§2.2).

---

## 5. Reports (Financial → Reports; report-permission + tax-toggle gated where tax-facing)

1. **Depreciation schedule** — per asset: basis, §179, bonus, method/class, per-year depreciation,
   accumulated, book value; year total = Sch F line 14. Raised/zero-basis rows shown as
   non-depreciable.
2. **Balance sheet (net worth)** — **market column (default)** + **cost-basis reconciliation
   column** (§2.2); assets (auto asset schedule + entered cash) − liabilities (entered) = equity;
   as-of date with prior-period compare from revisions. Header states the entered-cash/liabilities
   scope (§2.4).
3. **Enterprise P&L** — per enterprise: revenue − direct (AUE) − overhead (revenue-share) = net.
4. **Tax completion** — Form 4562 (§179 + bonus + MACRS detail); Form 4797 (capital-asset sale
   gain/loss with **depreciation recapture** for purchased, none for raised, §2.3); Sch F **line
   14** fed into the Phase-3 Tax Summary via `hook_farm_financial_mgmt_tax_summary_alter`.

---

## 6. Build order (tasks)

- **5.1** Category MACRS fields + seed defaults (update hook, in place); `depreciable_asset` entity
  (fields, form, list View, routes/menu) with `basis_type`; offer-to-create from a saved
  capital-category line (basis + class prefilled). *Exit:* create a purchased-equipment asset (7-yr
  inherited) and a raised-breeding asset (basis 0, non-depreciable); both persist correctly.
- **5.2** `DepreciationEngine` — straight-line first; then MACRS GDS/ADS tables, half-year +
  mid-quarter (year-level 40% test) + mid-month, §179, bonus. Skips non-depreciable. *Exit:*
  schedules match Pub 946 worked examples (7-yr machinery, 5-yr purchased breeding stock, 15-yr
  land improvement) to the cent, including a mid-quarter year.
- **5.3** Depreciation schedule report + Sch F line 14 into Tax Summary. *Exit:* line 14 = engine
  total for the year.
- **5.4** `financial_liability` + `financial_line.liability`/`principal_portion` (update hook) +
  balance postsave; exclude principal from P&L/tax. *Exit:* a payment splits interest (Sch F) vs
  principal (balance ↓); balance tracks.
- **5.5** `AssetValuationProviderInterface` + `BookValueProvider` + `LivestockMarketValueProvider`
  (dual basis/market). *Exit:* every asset returns both values; breeding stock market-valued when
  prices present.
- **5.6** `BalanceSheetBuilder` + net-worth report (market default + cost-basis reconciliation +
  entered cash + entered liabilities + prior-period compare). *Exit:* assets − liabilities = equity
  reconciles on test data in **both** columns.
- **5.7** `EnterpriseCostAllocator` + Enterprise P&L. *Exit:* enterprise net = revenue − direct −
  revenue-share overhead; overhead splits by gross-revenue share; **AUE pool and revenue-share pool
  never double-count**.
- **5.8** Form 4562 + Form 4797 with **depreciation recapture** (purchased) and **none** (raised),
  reading `basis_type`. *Exit:* a purchased breeding-cow sale recaptures prior depreciation; a
  raised one shows full §1231 gain, no recapture.
- **5.9** Phase-4 export integration — depreciable assets + liabilities in export; import
  round-trips them. *Exit:* export→import preserves the new entities.

---

## 7. Exit criteria (phase)

- Depreciation matches Pub 946 (SL + MACRS, all three conventions incl. a mid-quarter year); §179
  and bonus reduce basis correctly; book value floors at salvage/zero.
- **Raised** breeding stock is non-depreciable with 0 basis and **no recapture** on sale; purchased
  depreciates and recaptures — depreciation and Form 4797 agree.
- Property class comes from the capital category (editable), inherited by the asset.
- Sch F line 14 in the Tax Summary = engine total.
- Balance sheet reconciles (assets − liabilities = equity) in **both market and cost-basis**
  columns; scope (entered cash + liabilities) is stated on the report.
- Enterprise P&L = revenue − direct (AUE) − overhead (revenue-share); no cost double-counted.
- Loan balances track via interest/principal-split payments; principal excluded from Sch F.
- Deploys via `drush updb && drush cr` (no reinstall); demo ledger survives.

---

## 8. Invariants & boundaries (do not "simplify" away)

- **Depreciation is computed, never entered.** Sch F line 14 has no data-entry path.
- **Property class lives on the category term** (tax intelligence on the category), inherited by the
  asset — not hard-coded.
- **Valuation is dual.** Basis drives depreciation + tax/cost-basis balance sheet; market drives the
  managerial balance sheet. Neither is dropped.
- **Raised = zero basis, non-depreciable, no recapture** — a first-class state, and it must match the
  disposition side's raised/purchased signal.
- **Direct vs overhead is a hard split.** AUE = direct/variable; revenue-share = overhead/fixed +
  depreciation. A cost is in exactly one bucket.
- **Capital is depreciated, not deducted** (`capital = true`, `tax_form = none`).
- **Principal repayment is not an expense**; only interest is a Sch F deduction.
- **The balance sheet's cash and liabilities are entered, not derived**; the report says so.
- **One enterprise concept** (`enterprise` = animal_type species term) across lines, depreciable
  assets, and liabilities.
- **One accumulated-depreciation value per asset.** `DepreciationEngine::accumulatedDepreciation()`
  is the sole source; the basis balance-sheet column and 4797 recapture both read it, never
  recompute. (Same one-authoritative-signal discipline as the totalizer.)
- **§179/bonus degrade loudly, never silently.** For a year absent from the limits config table,
  apply $0/0% and surface the reason — never fall back to a stale prior year.

---

## 9. Pre-flight (before Task 5.1 — run after this spec is accepted)

1. **MACRS tables** — transcribe GDS 150%-DB + ADS SL percentage tables (Pub 946: 3/5/7/10/15/20-yr,
   half-year + mid-quarter) as verified constants; unit-test against Pub 946 worked examples before
   any report wiring.
2. **Basis linkage & trade-in** — confirm `basis` default = acquisition `total`; decide trade-in
   handling (contra line vs basis adjustment).
3. **§179 / bonus limits** — the most time-volatile numbers in the module (annual §179 caps; bonus
   on a legislated phase-down, future years often not settled law). Store as an **editable config
   table** (year → §179 cap, bonus %), not hard-coded to one year. **Degrade loudly:** a year with
   no row applies $0/0% and surfaces "no §179/bonus limits configured for {year} — entering $0/0%,
   update in settings"; it must never silently apply a prior year's numbers.
4. **Raised/purchased signal source** — confirm depreciation reads the *same* raised/purchased
   signal the 1a/1b/1c + 4797 disposition path already reads, resolving to one field, so
   depreciation and recapture cannot diverge.
5. **Single accumulated-depreciation source** — confirm the basis balance-sheet column and the 4797
   recapture calc both call `DepreciationEngine::accumulatedDepreciation()`; neither recomputes.
6. **Entity/field installs** — all new entity types + category MACRS fields + line
   `liability`/`principal_portion` install via `hook_update_N` (in place), mirroring 10402.
7. **Report gating** — Phase 5 reports respect the report permission and, where tax-facing, the
   `tax_planning_enabled` toggle.
