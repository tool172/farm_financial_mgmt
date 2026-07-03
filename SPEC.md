# farm_financial_mgmt — SPEC

FarmOS 4.x / Drupal 11 module for ranch income and expense tracking, built on purpose-built
content entities (Option C), with financial reporting, tax planning, and QuickBooks CSV
interchange architected as later phases that add config and code — never a schema migration.

> Companion docs: `CLAUDE.md` (agent working instructions) and `TASKS.md` (ordered build).

---

## 1. Purpose & scope

Track money in and money out for a ranch: sales of animals and products, purchases and
operating costs, lease income, government payments. Attach receipts. Attribute costs and income
to specific assets (animals, land, equipment) so per-record profitability and per-animal running
cost can be computed. Produce financial reports. Later, map to Schedule F for tax planning and
exchange data with QuickBooks.

**In scope (phased, §8):** ledger entry, receipts, split transactions, per-record P&L,
per-animal running cost, financial reports, Schedule F tax planning, CSV/QuickBooks export +
own-format CSV import.

**Deferred to the horizon (Phase 5, not detailed):** depreciation, asset valuation, Section 179,
balance sheet, liabilities, revenue-share overhead allocation.

**Deliberate non-dependencies:**
- **`farm_ledger`** — not used (the log-first path we rejected).
- **External automation (n8n / outside services)** — not part of this module. Any future
  import is FarmOS-native and limited to this module's own export format.

---

## 2. Architectural decision (why Option C)

These are **standard Drupal content entities**, not farmOS `asset` / `log` / `plan` /
`quantity` types. A financial record is a first-class ledger object, not an event on the log
model.

- This is fundamentally a **reporting-and-attribution** module; purpose-built entities report as
  a mostly single-table `GROUP BY`, where the log+quantity path forced a
  log→quantity→category join on every report.
- **Per-line attribution** (the fencing portion of a supply-store receipt tied to one paddock
  while feed and fuel stay farm-wide) requires line items that each carry their own asset
  reference — only child line entities model this.
- The per-animal running cost's hard part (AUE-weighted allocation of shared costs) is bespoke
  in any design, so the log model's "free asset timeline" only ever helped the easy,
  directly-attributed minority.

Trade-offs accepted: rebuild receipt handling (one file field), the count×unit_price=total
auto-calc (a few lines in presave), and forgo free calendar/quick-form integration.

---

## 3. Data model

Two content entity types + two taxonomy vocabularies. Money is `decimal(14,2)`. **Currency is a
configurable site setting** (ISO 4217 code, default `USD`), applied uniformly — not
per-transaction, no multi-currency/exchange rates.

### 3.1 `financial_transaction` (payment envelope)

One payment event: money moved on a date, to/from one counterparty. Holds no amount itself — the
money lives on its lines. **Independently revisionable** (`RevisionableContentEntityBase`) for a
full audit trail.

| Field | Type | Notes |
|---|---|---|
| `id`, `uuid`, `revision_id` | base | |
| `label` | string | Auto: `{counterparty} — {date}` if blank. |
| `direction` | list (`income`\|`expense`) | **Single bundle + this field** (not two bundles). Enforced homogeneous with its lines (validation constraint). |
| `date` | datetime (date) | When money moved (cash-basis anchor). |
| `reporting_year` | integer | Defaults to `YEAR(date)`; overridable (prepaid supplies crossing tax years). |
| `counterparty` | entity_ref → `financial_contact` | Payee (expense) or payer (income). |
| `payment_method` | list | cash, check, card, ACH, other. |
| `reference` | string | Check #, invoice #, confirmation #. |
| `payment_status` | list | pending, partial, paid (lightweight A/R signal, no invoicing subsystem). |
| `receipt` | file (multi) | Images + PDFs — receipt capture. |
| `total` | decimal(14,2), computed+stored | Sum of line `amount`; recomputed on save, stored for query speed. |
| `notes` | text_long | |
| `owner` | entity_ref → user | `EntityOwnerTrait`. |
| `created`, `changed` | base | `EntityChangedTrait`. |

Line editing on the transaction form is via **Inline Entity Form** (see §4 for the D11 caveat
and fallback).

### 3.2 `financial_line` (money + categorization)

Where amount, category, and per-line asset attribution live — the entity Option C exists for.
**Independently revisionable**, references its parent (Commerce order-item pattern).

| Field | Type | Notes |
|---|---|---|
| `id`, `uuid`, `revision_id` | base | |
| `transaction` | entity_ref → `financial_transaction` | Parent link. |
| `category` | entity_ref → `financial_category` | **Per-line** — makes split transactions work. Required. |
| `amount` | decimal(14,2) | If `quantity` and `unit_price` set → computed as their product on presave; else entered directly. |
| `quantity` | decimal, optional | e.g. head count or weight sold. |
| `unit_price` | decimal, optional | e.g. $/head or $/cwt (enables sale math + later comparison vs `farm_cattle_prices`). |
| `unit` | entity_ref → farmOS `unit` vocab, optional | Reuse farmOS units (head, cwt, lb…). |
| `asset` | entity_ref → `asset` (multi, any bundle), optional | Animal/land/equipment this line pertains to. **Empty = farm-wide** (feeds the allocation pool). |
| `memo` | string, optional | Line-level note. |
| **Denormalized (synced from parent on save):** | | Reporting optimization. |
| `txn_date` | datetime | From `transaction.date`. |
| `reporting_year` | integer | From `transaction.reporting_year`. |
| `direction` | list | From `transaction.direction`. |

> **Reporting optimization (load-bearing):** the denormalized `txn_date`/`reporting_year`/
> `direction` on each line let P&L, spending-by-category, and per-record P&L query the single
> `financial_line` table with no join. Keep the write-through in the transaction's postsave.

### 3.3 `financial_category` (vocabulary, two-level)

Parent/child, like the species and `animal_life_stage` pattern. Top-level terms encode direction;
children are the working categories. **Tax fields exist from Phase 1 but are unused until
Phase 3.**

- Top-level: `Income`, `Expense`.
- Children: the working categories, seeded Schedule-F-aligned (§12).

Fields on the vocabulary:

| Field | Type | Phase | Notes |
|---|---|---|---|
| `direction` | list, from parent | 1 | Denormalized for queries. |
| `schedule_f_line` | string | 3 | Tax mapping (e.g. `16` Feed, `1a` purchased-resale sales). |
| `tax_form` | list | 3 | `schedule_f` \| `form_4797` \| `form_4835` \| `none`. Handles breeding/draft/dairy/sport-stock → 4797 and lease → 4835/Sch E. |
| `capital` | boolean | 3/5 | Capital categories get depreciated, not deducted. |
| `requires_cost_basis` | boolean | 3 | Purchased-for-resale income (Sch F 1a/1b/1c cost-basis netting). |
| `qb_account` | string | 4 | QuickBooks account name for export mapping. |
| `allocatable` | boolean, default per §12 | 2 | Whether an unattributed expense here feeds the AUE running-cost pool. **See §7 for the methodology.** |

### 3.4 `financial_contact` (vocabulary)

**Single** vocabulary for counterparties (a vendor can also be a buyer — one list avoids
duplication). Optional `contact_type` field (vendor/customer/both) for filtering.

### 3.5 Reused, not rebuilt

- **Units** → farmOS `unit` vocabulary.
- **Assets** → referenced via entity_ref; no asset data duplicated.

---

## 4. Entity implementation notes (Drupal 11 / farmOS 4.x)

- Define both entity types with the PHP 8 `#[ContentEntityType(...)]` attribute
  (`Drupal\Core\Entity\Attribute\ContentEntityType`). No annotations.
- Both extend `RevisionableContentEntityBase`; `financial_transaction` also implements
  `EntityOwnerInterface`, `EntityChangedInterface`, and a revision-log interface.
- Handlers: `ViewsData`, `ListBuilder`, default add/edit/delete forms, `AdminHtmlRouteProvider`,
  access handler backed by §6 permissions. Entity links: canonical, add-form, edit-form,
  delete-form, collection.
- **Line editing:** embed `financial_line` sub-forms via `inline_entity_form`.
  ⚠️ **Pre-flight:** confirm a Drupal 11–compatible release exists; if not, use the custom
  inline line-row widget fallback (**not** a composite multi-value field). Gates Task 1.8.
- **Lifecycle logic:**
  - Line presave: `amount = quantity * unit_price` when both set.
  - Transaction postsave: write-through `date`/`reporting_year`/`direction` to child lines
    (§3.2); recompute and store `total`. (May be extracted to a small `TransactionTotalizer`
    service for testability.)
  - Validation constraint: every line's `category` direction == transaction `direction`.
- **Default categories** (§12) seeded via `hook_install()`, editable thereafter.

---

## 5. Menu, routing, icon

Follow the **`farm_cattle_prices`** pattern: top-level **"Financial"** toolbar link with an SVG
**currency** icon (`icons/financial.svg`).

- `*.links.menu.yml` — top-level `Financial`.
- `*.links.task.yml` / `*.links.action.yml` — tabs/actions.
- Routes (`*.routing.yml`):
  - `/financial` — dashboard (summary cards + charts).
  - `/financial/transactions` — list (**Views** + BEF exposed filters).
  - `/financial/transaction/add`, `/financial/transaction/{id}`, `/edit`, `/delete`.
  - `/financial/reports/{report}` — report controllers (Phase 2).
  - `/financial/settings` — currency, accounting method.

---

## 6. Permissions

- `manage financial transactions` — create/update/delete transactions and lines.
- `view financial transactions` — read ledger.
- `view financial reports` — read reports/dashboard (separable from raw ledger).
- `administer financial mgmt` — categories, contacts, settings.

Single-operator ranches grant the manage+view+reports bundle to one role; the split exists so a
bookkeeper can see reports without settings.

---

## 7. Accounting semantics

- **Cash vs accrual** — site setting (`/financial/settings`), default **cash**. Drives report
  date logic (cash keys off `date`).
- **Reporting year** — reports bucket by `reporting_year`, so prepaid cross-year supplies land
  in the right tax year.
- **Split transactions** — native: one transaction, N lines, each its own category and optional
  asset.
- **Payment status** — pending/partial/paid; a lightweight A/R signal without invoicing.
- **Tax-form fork at the category level** — `tax_form` / `capital` / `requires_cost_basis`
  (Phase 3) route breeding-stock sales to Form 4797, lease income to 4835/Schedule E, and
  capital purchases out of deductible expense — without special-casing the ledger.
- **Cost-allocation methodology (drives `allocatable`):** farm managerial accounting splits
  costs into *direct/variable* (scale with each cow — feed, supplements, vet, minerals, tags,
  grazing lease) and *overhead/fixed* (don't scale with herd size — interest, taxes, insurance,
  equipment, facilities). AUE-weighting is a **consumption/usage** proxy, so it is the correct
  allocation method **only for the direct/variable bucket**. Overhead, if ever allocated
  per-enterprise, is correctly allocated by **relative value of production (revenue-share)** — a
  separate Phase 5 concern, never the AUE pool. Therefore `allocatable = true` only for
  consumption-scaling categories (see §12 defaults).

---

## 8. Phase plan

### Phase 1 — Core ledger (MVP)
Entities (transaction + line, with denormalization + auto-calc + revisions); `financial_category`
(two-level, all fields defined, tax fields dormant) + seeded defaults; `financial_contact`;
transaction add/edit form with inline lines + receipt upload; transaction list View + BEF;
"Financial" menu + icon; settings (currency, cash/accrual); dashboard summary.
**Exit:** create a split expense (feed + fuel + fencing-to-a-paddock on one receipt) and an
animal-sale income (head × $/head auto-total); confirm totals, receipt, reporting-year,
homogeneous-direction rejection, and default categories on a fresh install.

### Phase 2 — Financial reports
Custom controllers + `ReportBuilder` service + Chart.js (Views for tabular/exportable lists):
- **Profit & Loss** — income, expense, net; by category; date/reporting-year range.
- **Spending by Category** / **Income by Category** — drill parent→child.
- **Cash Flow** — monthly buckets (line chart).
- **Per-Record P&L** — filter lines by `asset` → income/expense/net for one animal/land/equipment.
- **Monthly view**.

**Per-animal running cost:**
```
attributable = Σ expense-line.amount where line.asset = animal, over period
shared_pool  = Σ expense-line.amount where line.asset is empty
                 AND line.category.allocatable = true, over period
aue_share    = animal_AUE / total_herd_AUE                (over the period)
time_weight  = days_animal_present / period_days           (pro-rate partial periods)
allocated    = shared_pool × aue_share × time_weight
running_cost = attributable + allocated
```
- AUE from `animal_life_stage` → AUE mapping (five stages). Presence/head-days from asset
  acquisition + `farm_asset_termination` dates.
- **Decoupled providers:** `AueProviderInterface`; `DefaultAueProvider` reads `animal_life_stage`
  + asset lifecycle. If `farm_grazing_rotation_plan` is installed, a `GrazingAueProvider` using
  its move-time AUE snapshots can be swapped in via services. **No hard dependency on the grazing
  module.**
- **Widget surface (rendered in `farm_ranch_ui`, not here):** expose `RunningCostCalculator` as a
  public service; the cattle-dashboard widget composes `running_cost` (this module) with
  projected value = current weight × market $/cwt (from `farm_cattle_prices`).

### Phase 3 — Tax planning (Schedule F)
Additive — populate and read the dormant category tax fields:
- Populate `schedule_f_line` / `tax_form` / `capital` / `requires_cost_basis` on seeded categories.
- **Tax Summary report** — roll up lines by `schedule_f_line`, respect `tax_form` (list
  4797/4835 items separately from the Schedule F rollup), net purchased-resale cost basis
  (1a/1b/1c).
- Apply cash/accrual to rollup dates.
- Lease handling: cash-rent (Sch E) vs share-rent/material-participation (4835 vs Sch F).
- Depreciation (Sch F line 14) is **not** computed here — it belongs to Phase 5; the line stays
  present, sourced later from the depreciation engine.

### Phase 4 — CSV export + own-format import
FarmOS-native, no external automation:
- **CPA CSV export** — transactions/lines flattened (date, reporting_year, direction, category,
  schedule_f_line, amount, quantity, unit_price, unit, counterparty, payment_method, reference,
  asset, memo).
- **QuickBooks export** — QBO-compatible **CSV** mapped via each category's `qb_account`.
- **CSV import — this module's own export schema only** (round-trip / backup-restore / moving
  data between installs). Map category names → `financial_category` terms (create-on-import
  configurable). No bank-statement or third-party formats.

### Phase 5 — Horizon (not detailed)
Depreciation, asset valuation, Section 179, balance sheet, liabilities, and revenue-share
overhead allocation for full enterprise costing. The `capital` flag and revisionable entities are
the hooks left in place.

---

## 9. Integration points

| With | Direction | Coupling |
|---|---|---|
| `farm_ranch_ui` | Consumes `RunningCostCalculator` for the cattle dashboard widget. | Soft — service call; widget lives there. |
| `farm_cattle_prices` | Widget combines its market $/cwt with running cost for projected value. | Soft — not called directly here. |
| `farm_grazing_rotation_plan` | Optional precise AUE/presence provider. | **Optional** — swappable provider, not a hard dependency. |
| farmOS `asset`, `unit` | Entity references. | Standard. |

---

## 10. Module file layout

```
farm_financial_mgmt/
  farm_financial_mgmt.info.yml
  farm_financial_mgmt.module
  farm_financial_mgmt.install            # seed default categories (Phase 1); Phase 3 update hook
  farm_financial_mgmt.routing.yml
  farm_financial_mgmt.links.menu.yml     # "Financial" top-level + icon
  farm_financial_mgmt.links.task.yml
  farm_financial_mgmt.links.action.yml
  farm_financial_mgmt.permissions.yml
  farm_financial_mgmt.services.yml       # ReportBuilder, RunningCostCalculator, AueProvider(s)
  schema/farm_financial_mgmt.schema.yml  # settings config schema
  config/install/
    farm_financial_mgmt.settings.yml
    taxonomy.vocabulary.financial_category.yml
    taxonomy.vocabulary.financial_contact.yml
    field.storage.*  field.field.*
    core.entity_form_display.*           # transaction form w/ inline lines
    core.entity_view_display.*
    views.view.financial_transactions.yml
  src/Entity/FinancialTransaction.php          # #[ContentEntityType]
  src/Entity/FinancialTransactionInterface.php
  src/Entity/FinancialLine.php                 # #[ContentEntityType]
  src/Entity/FinancialLineInterface.php
  src/Plugin/Validation/Constraint/*           # homogeneous-direction constraint
  src/Controller/FinancialDashboardController.php
  src/Controller/FinancialReportController.php  # Phase 2
  src/Form/SettingsForm.php
  src/Service/TransactionTotalizer.php
  src/Service/ReportBuilder.php                 # Phase 2
  src/Service/RunningCostCalculator.php         # Phase 2
  src/Service/Aue/AueProviderInterface.php
  src/Service/Aue/DefaultAueProvider.php
  src/Service/Aue/GrazingAueProvider.php        # optional, Phase 2
  src/Service/Export/*                           # Phase 4 (CSV / QuickBooks / import)
  templates/
  css/  js/                                       # Chart.js report rendering
  icons/financial.svg
```

---

## 11. Resolved decisions & remaining pre-flight

**Resolved (this thread):**
- Line + transaction **independently revisionable** (Commerce order/order-item pattern).
- **Single** `financial_contact` vocabulary.
- **Configurable-global currency** (ISO 4217 code, default USD); not simultaneous multi-currency.
- **Single bundle + `direction` field** (not two bundles) — uniform schema ages better.
- Default categories seeded via **`hook_install()`**.
- **Views** for lists; custom controllers/services for computed/charted reports.
- Lease income → **4835/Schedule E** at the category level (Phase 3 handles material-participation
  nuance).
- QuickBooks export = **QBO-compatible CSV**.
- CSV import = **this module's own export format only**.
- `allocatable` defaults per §12, per the direct/overhead methodology in §7.

**Remaining pre-flight (see CLAUDE.md):**
- **`inline_entity_form` Drupal 11 compatibility** — the one runtime risk that gates Task 1.8;
  fallback is the custom inline widget.
- **Operator to review the judgment-call `allocatable` flags** (fuel, hired labor, freight,
  custom hire, supplies) — defaulted `true` for a livestock-primary operation, trivially flipped
  per operation.

---

## 12. Default `financial_category` seed (Schedule-F-aligned)

Seeded on install, editable thereafter. `schedule_f_line` shown for reference; that field is
populated in Phase 3. `alloc` = default `allocatable` value (Phase 2). `(review)` marks the
livestock-primary judgment calls the operator should confirm.

**Income (parent: Income)** — all `alloc=false` (allocation applies to expenses only)
- Sales of Purchased Livestock / Resale — SF 1a — requires_cost_basis
- Sales of Raised Livestock & Products — SF 2
- Cooperative Distributions — SF 3
- Agricultural Program Payments — SF 4
- CCC Loans — SF 5
- Crop Insurance Proceeds — SF 6
- Custom Hire Income — SF 7
- Other Income (incl. fuel tax credits/refunds) — SF 8
- Lease / Rental Income — tax_form: form_4835/schedule_e

**Expense (parent: Expense)**
- Feed — SF 16 — **alloc=true**
- Veterinary, Breeding & Medicine — SF 31 — **alloc=true**
- Rent/Lease – Other (incl. land) — SF 24b — **alloc=true** (grazing lease: strongest AUE fit)
- Supplies — SF 28 — **alloc=true (review)** (livestock supplies only)
- Gasoline, Fuel & Oil — SF 19 — **alloc=true (review)**
- Labor Hired — SF 22 — **alloc=true (review)**
- Freight & Trucking — SF 18 — **alloc=true (review)** (livestock hauling)
- Custom Hire / Machine Work — SF 13 — **alloc=true (review)** (livestock custom work)
- Car & Truck — SF 10 — alloc=false
- Chemicals — SF 11 — alloc=false
- Conservation — SF 12 — alloc=false
- Employee Benefits — SF 15 — alloc=false
- Fertilizer & Lime — SF 17 — alloc=false
- Insurance — SF 20 — alloc=false
- Interest – Mortgage — SF 21a — alloc=false
- Interest – Other — SF 21b — alloc=false
- Pension & Profit-Sharing — SF 23 — alloc=false
- Rent/Lease – Vehicles, Machinery, Equipment — SF 24a — alloc=false
- Repairs & Maintenance — SF 25 — alloc=false
- Seeds & Plants — SF 26 — alloc=false
- Storage & Warehousing — SF 27 — alloc=false
- Taxes — SF 29 — alloc=false
- Utilities — SF 30 — alloc=false
- Other Expense — SF 32 — alloc=false

**Capital / Form 4797 (tax_form + capital set in Phase 3; alloc=false)**
- Sale of Breeding/Draft/Dairy/Sport Livestock — tax_form: form_4797
- Purchase of Breeding Stock — capital
- Equipment / Machinery Purchase — capital
- Land Improvement — capital

> Depreciation (SF 14) is intentionally absent from manual entry — computed by the Phase 5
> depreciation engine, not entered as a transaction.
