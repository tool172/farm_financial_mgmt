# Farm Financial Management

Ranch **income and expense tracking** for [farmOS](https://farmos.org) 4.x, with
financial reporting, Schedule F tax planning, a MACRS depreciation engine, a
market-value balance sheet, per-species enterprise profitability, and CSV
interchange.

Built on **purpose-built content entities** (the "Option C" data model) rather
than the farmOS log model, so a transaction is a first-class financial record —
not a repurposed log — and every report queries a single denormalized line
table with no joins.

- **farmOS:** 4.x · **Drupal:** ^10 || ^11 · **PHP:** 8.1+
- **Status:** Phases 1–5 complete (ledger → reporting → tax → interchange →
  capital/depreciation/balance sheet). Version `1.0.0-dev`.

---

## What it does

- **Double-nothing, single-currency ledger.** Cash-basis income/expense
  transactions with itemized lines, receipts, counterparties, and payment
  status — not a double-entry general ledger, deliberately.
- **Reports** (server-rendered, Chart.js where charted): Profit & Loss, Spending
  by Category, Income by Category, Cash Flow, Monthly view, Per-Record P&L,
  **Tax Summary (Schedule F)**, **Depreciation Schedule (Form 4562)**, **Form 4797
  (dispositions)**, **Enterprise P&L**, and a **Balance Sheet (Net Worth)**.
- **Schedule F tax planning.** Category → Schedule F line mapping, purchased-for-
  resale cost-basis netting (1a − 1b = 1c), and routing of breeding-stock sales
  to Form 4797 and lease income to Form 4835 / Schedule E.
- **Capital & depreciation.** A MACRS depreciation engine (GDS 200%/150% DB, ADS,
  §179, bonus), verified against IRS Pub 946 to the cent; a dual **cost-basis +
  market** valuation; and a market-value balance sheet with a cost-basis
  reconciliation column.
- **Enterprise profitability.** Per-species profit & loss — revenue less direct
  (AUE-allocated) cost less overhead (revenue-share) — with a structural
  guarantee that every operating dollar lands in exactly one pool.
- **Liabilities.** Loans/notes whose balance is derived from principal paydown,
  with loan payments split into deductible interest and (excluded) principal.
- **CSV interchange.** Own-format export/import (round-trip backup of the whole
  ledger + capital layer) and a QuickBooks-mappable export.

---

## Requirements

Declared in `farm_financial_mgmt.info.yml`:

- `asset:asset`, `farm:farm_unit`
- `inline_entity_form:inline_entity_form`
- `drupal:taxonomy`, `drupal:views`, `drupal:file`, `drupal:options`,
  `drupal:datetime`, `drupal:user`

Optional (soft integrations, guarded by `moduleExists` — no hard dependency):

- `farm_cattle_prices` + `farm_ranch_ui` — live USDA cattle market values feed the
  balance sheet's market column when present.

## Installation

```bash
drush en farm_financial_mgmt -y
drush updb -y      # installs entity schemas, fields, and seeded config
drush cr
```

A top-level **Financial** item appears in the farmOS toolbar with the dashboard,
ledger, reports, depreciable assets, liabilities, import/export, and settings.

---

## Concepts

- **Transaction + lines.** A `financial_transaction` is the payment envelope
  (date, counterparty, method, status, receipt); its `financial_line` children
  carry category, amount, and optional asset/enterprise attribution. Both are
  independently revisionable. On save, the transaction's date / reporting-year /
  direction are **denormalized onto each line** so reports hit one table.
- **Categories.** A two-level `financial_category` taxonomy (Income / Expense →
  working categories). Each category carries the "tax intelligence" the reports
  read: `schedule_f_line`, `tax_form`, `capital`, `allocatable`,
  `requires_cost_basis`, `qb_account`, and (for capital) `macrs_class` /
  `depreciation_method`.
- **Allocatable = the direct/overhead partition.** A category flagged
  `allocatable` is a direct, consumption-scaling cost (AUE-allocated to animals);
  everything else is overhead. This one flag is the structural partition the
  enterprise P&L and per-animal running cost both rely on.
- **Capital vs. recognized.** A capital purchase is **capitalized, not expensed**
  — it never hits operating expense; its cost is recognized over its life as
  depreciation. The Tax Summary, Enterprise P&L, and (managerial) Profit & Loss
  all treat it that way, from one depreciation figure.
- **Raised vs. purchased breeding stock.** A `basis_type` on each depreciable
  asset (`purchased` / `raised` / `acquired_other`) is the single signal that
  drives both depreciation and Form 4797 recapture — a raised animal has zero
  basis and zero recapture; a purchased one recaptures depreciation taken.

---

## Reports

| Report | What it shows |
|---|---|
| Profit & Loss | Income − operating expense − depreciation = net (a true income statement) |
| Spending / Income by Category | Category rollups with charts |
| Cash Flow / Monthly | Period and month-bucketed income/expense |
| Per-Record P&L | Income/expense/net for a single asset |
| Tax Summary (Schedule F) | Part I/II by line, 1a/1b/1c netting, 4797/4835/Sch E split, line 14 |
| Depreciation Schedule (4562) | Per-asset basis / §179 / bonus / method / accumulated / book value |
| Form 4797 | Disposition gain/loss with §1245 ordinary recapture vs. §1231 |
| Enterprise P&L | Per-species revenue − direct (AUE) − overhead (revenue-share) |
| Balance Sheet (Net Worth) | Market + cost-basis columns, entered cash + liabilities, derived equity |

Tax-facing reports (Tax Summary, Depreciation Schedule, Form 4797) are hidden and
access-gated when tax planning is turned off in settings.

---

## Depreciation & tax detail

- **MACRS** via published IRS Pub 946 percentage tables (transcribed and unit-
  tested to the cent): GDS 200% DB and 150% DB half-year; 200% DB mid-quarter
  (3/5/7/10-yr) and 150% DB mid-quarter (15/20-yr); ADS / straight-line. The
  mid-quarter convention is computed at the year level (the >40%-in-Q4 test).
- **§179 and bonus** limits live in an operator-editable **year → config table**
  (Financial → Depreciation Limits), because these change by law annually. A year
  with no configured limit **degrades loudly** ($0 / 0% with a surfaced notice),
  never a silent stale year.
- **Honest refusals.** Where no standard published table exists (e.g. elective
  150% DB mid-quarter on personal property), the engine **throws** rather than
  computing a guess.

---

## Architecture

Content entities: `financial_transaction`, `financial_line`, `depreciable_asset`,
`financial_liability` (all revisionable). Vocabularies: `financial_category`,
`financial_contact`.

Key services (all injected; `\Drupal::` only in hooks):

- `TransactionTotalizer` — recomputes total and writes the denormalization through
  to lines on save.
- `ReportBuilder` — single-table aggregations; the one gate every operating
  rollup passes through (so principal is excluded by construction).
- `DepreciationEngine` — MACRS/SL schedules; `accumulatedDepreciation()` is the
  **single authoritative source** the balance sheet's basis column and the Form
  4797 recapture both read.
- `AssetValuationProviderInterface` (`BookValueProvider` +
  `LivestockMarketValueProvider`) — dual basis/market valuation, swappable.
- `TaxSummaryBuilder`, `Form4797Builder`, `BalanceSheetBuilder`,
  `EnterpriseCostAllocator`, `RunningCostCalculator`, `CsvExporter` /
  `CsvImporter`, and a pluggable `AueProviderInterface`.

A running design principle: **one derived source per fact** (accumulated
depreciation, liability balance, owned-as-of asset set), so reports that consume
it cannot disagree.

## Permissions

- `view financial transactions` — read the ledger
- `manage financial transactions` — create/update/delete transactions and lines
- `view financial reports` — reports and the dashboard
- `administer financial mgmt` — categories, contacts, settings (restricted)

---

## Testing

Kernel test suite (13 tests) pins the invariants: Pub 946 golden values,
cross-report relationship identities (recapture = balance-sheet accumulated; the
three profit views agree on capital), the raised-vs-purchased fork, and the loud-
degradation paths. See `tests/README.md`.

```bash
# host resolves the DB container as e.g. farmosdev-db-1, not "db"
XDEBUG_MODE=off SIMPLETEST_DB="pgsql://farm:farm@<db-host>/farm" \
  vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/contrib/farm_financial_mgmt/tests/src/Kernel/
```

## Boundaries (by design)

Cash-basis, single-currency. Not a double-entry general ledger, no AP/AR, no bank
reconciliation. The balance sheet is **asset-side-complete** with **entered** cash
and liabilities (a flow ledger can't derive a cash position) — the report header
states this. CSV import handles only this module's own export schema (backup /
restore), not bank-statement or third-party formats.

## Documentation

- `SPEC.md` — architecture and data-model rationale
- `PHASE5.md` — the capital/depreciation/balance-sheet phase specification
- `TASKS.md` — the phased build order
- `CLAUDE.md` — working conventions and invariants
