# farm_financial_mgmt — TASKS

Ordered build. Complete a phase, run its **Exit checklist** on the live install, get operator
sign-off, then proceed. Task numbering is `Phase.Task`. See `SPEC.md` for the design and
`CLAUDE.md` for conventions and invariants.

---

## Pre-flight (before Task 1.1)

- [ ] **P.1** Confirm `inline_entity_form` has a Drupal 11–compatible release. If not, plan the
      custom inline line-row widget fallback (gates Task 1.8).
- [ ] **P.2** Confirm the FQCN of `#[ContentEntityType]` and any other attributes used.
- [ ] **P.3** Confirm the farmOS `unit` vocabulary machine name.
- [ ] **P.4** Confirm the `asset` entity type id + bundles for the line `asset` reference.
- [ ] Report P.1–P.4 results before starting.

---

## Phase 1 — Core ledger (MVP)

- [ ] **1.1 Module scaffold.** `farm_financial_mgmt.info.yml` (Drupal 11 / farmOS core deps +
      `inline_entity_form`), `.module`, directory structure, empty `permissions.yml` and
      `services.yml`.
      *Verify:* `drush en farm_financial_mgmt -y` succeeds; module listed.
- [ ] **1.2 Settings + schema.** `config/install/farm_financial_mgmt.settings.yml`
      (`currency: USD` ISO 4217, `accounting_method: cash`), `schema/farm_financial_mgmt.schema.yml`,
      `SettingsForm` at `/financial/settings` (currency select, cash/accrual).
      *Verify:* settings save; no config-schema warnings (`drush config:inspect` / status).
- [ ] **1.3 Vocabularies + category fields.** `financial_category` and `financial_contact`
      vocabularies (config/install). Add to `financial_category`: `direction`, `schedule_f_line`,
      `tax_form`, `capital`, `requires_cost_basis`, `qb_account`, `allocatable`
      (field.storage + field.field). Add `contact_type` to `financial_contact`.
      *Verify:* both vocabularies + all fields present.
- [ ] **1.4 Seed default categories** via `hook_install()` — the `SPEC.md` §12 list, two-level,
      with `allocatable` defaults set (tax fields left for Phase 3).
      *Verify:* fresh install shows Income/Expense trees with children; `allocatable` flags match §12.
- [ ] **1.5 `financial_line` entity.** `#[ContentEntityType]`, `RevisionableContentEntityBase`,
      interface, base fields (transaction, category, amount, quantity, unit_price, unit,
      asset[multi], memo, denormalized txn_date/reporting_year/direction). Presave:
      `amount = quantity * unit_price` when both present.
      *Verify:* `drush updb` installs the entity; can create a line programmatically; auto-calc works.
- [ ] **1.6 `financial_transaction` entity.** `#[ContentEntityType]`,
      `RevisionableContentEntityBase`, EntityOwner/Changed + revision log, interface, base fields
      (label auto, direction, date, reporting_year, counterparty, payment_method, reference,
      payment_status, receipt[file multi], total computed, notes, owner). Postsave via
      `TransactionTotalizer`: write-through denormalized fields to lines; recompute+store `total`.
      *Verify:* installs; create a transaction with lines programmatically; `total` correct; lines
      carry denormalized values.
- [ ] **1.7 Homogeneous-direction constraint.** Validation constraint + validator: every line's
      `category` direction must equal the transaction `direction`.
      *Verify:* a mismatched line is rejected on save.
- [ ] **1.8 Transaction form with inline lines.** `inline_entity_form` widget (or fallback per
      P.1) for `financial_line`; receipt upload widget; `core.entity_form_display`.
      *Verify:* in the UI, create a split transaction, attach a receipt, save; totals correct.
- [ ] **1.9 Transaction list View.** `views.view.financial_transactions` with BEF exposed filters
      (date range, direction, category, counterparty), columns incl. total + payment_status.
      *Verify:* list renders at `/financial/transactions`; filters work (single AJAX exposed form).
- [ ] **1.10 Menu + routing + icon.** `routing.yml` (dashboard, list, add/edit/delete, settings);
      `links.menu.yml` (top-level **Financial**); `links.task.yml`/`links.action.yml`;
      `icons/financial.svg` (currency icon) — `farm_cattle_prices` pattern.
      *Verify:* "Financial" in the toolbar with icon; all routes resolve.
- [ ] **1.11 Permissions + access handler.** `permissions.yml` (manage / view / view reports /
      administer) wired to an access control handler.
      *Verify:* permissions appear; access enforced by role.
- [ ] **1.12 Dashboard.** `FinancialDashboardController` at `/financial` — summary cards
      (period income, expense, net) + recent transactions, in the FarmOS layout.
      *Verify:* renders inside FarmOS; numbers match seeded test data.

**Phase 1 Exit checklist**
- [ ] Module enables, `drush updb` + `drush cr` clean.
- [ ] Fresh install seeds the full category tree with correct `allocatable` flags.
- [ ] Split expense (feed + fuel + fencing-to-a-paddock, one receipt) saves; per-line categories
      and optional per-line asset persist; `total` correct; receipt attached.
- [ ] Animal-sale income (head × $/head) auto-totals.
- [ ] Reporting-year override works (prepaid crossing tax years).
- [ ] Mismatched-direction line rejected.
- [ ] "Financial" menu + currency icon render; dashboard + list render.

---

## Phase 2 — Financial reports

- [ ] **2.1 `ReportBuilder` service.** Single-table `financial_line` queries via denormalized
      fields; group by category; date/reporting-year range; direction filter.
- [ ] **2.2 Profit & Loss.** `FinancialReportController` + Twig + Chart.js (bar): income,
      expense, net; by category; parent→child drill; date/reporting-year range.
- [ ] **2.3 Spending-by-Category + Income-by-Category** reports.
- [ ] **2.4 Cash Flow.** Monthly buckets, Chart.js line.
- [ ] **2.5 Per-Record P&L.** Filter lines by `asset` → income/expense/net for one
      animal/land/equipment.
- [ ] **2.6 Monthly view.** Transactions bucketed by month, by category.
- [ ] **2.7 `AueProviderInterface` + `DefaultAueProvider`.** AUE from `animal_life_stage`
      mapping; presence/head-days from asset acquisition + `farm_asset_termination`.
- [ ] **2.8 `GrazingAueProvider` (optional).** Uses `farm_grazing_rotation_plan` move-time AUE
      snapshots when that module is present; swappable via services. No hard dependency.
- [ ] **2.9 `RunningCostCalculator` service.** `attributable + AUE-allocated shared pool`,
      time-weighted, honoring `allocatable`. Public API for `farm_ranch_ui`.
- [ ] **2.10 Report nav + filters** under Financial (BEF/exposed or controller params).

**Phase 2 Exit checklist**
- [ ] P&L, spending/income-by-category, cash-flow, per-record P&L, monthly view all render with
      correct figures against test data.
- [ ] Running cost for a test animal = attributable lines + AUE-weighted share of the
      `allocatable` pool, time-weighted for partial-period presence.
- [ ] Overhead categories are **excluded** from the AUE pool.
- [ ] `RunningCostCalculator` callable from `farm_ranch_ui`.

---

## Phase 3 — Tax planning (Schedule F)

- [ ] **3.1** Populate `schedule_f_line` / `tax_form` / `capital` / `requires_cost_basis` on
      seeded categories (update hook + config).
- [ ] **3.2 Tax Summary report.** Roll up by `schedule_f_line`; respect `tax_form` (list
      4797/4835 separately from the Sch F rollup); net purchased-resale cost basis (1a/1b/1c).
- [ ] **3.3** Apply cash/accrual to rollup dates.
- [ ] **3.4** Lease handling: cash-rent (Sch E) vs share-rent/material-participation (4835 vs F).
- [ ] **3.5** Export hook for the tax summary (feeds Phase 4).

**Phase 3 Exit checklist**
- [ ] Tax Summary matches hand-computed Schedule F line totals on test data.
- [ ] Breeding-stock sale lands on the 4797 list, not the Sch F rollup.
- [ ] Purchased-resale cost basis nets correctly.

---

## Phase 4 — CSV export + own-format import

- [ ] **4.1 CPA CSV export.** Flatten transactions/lines (all columns per `SPEC.md` §8 Phase 4).
- [ ] **4.2 QuickBooks CSV export.** QBO-compatible columns mapped via category `qb_account`.
- [ ] **4.3 CSV import (own format only).** Parse the export schema; map category names → terms
      (create-on-import configurable); create transactions + lines. No bank/third-party formats.
- [ ] **4.4 Export/import UI** (admin forms under Financial).

**Phase 4 Exit checklist**
- [ ] Export → import **round-trips** with no data loss (backup/restore, cross-install move).
- [ ] QuickBooks CSV imports into QBO with categories mapped to the intended accounts.

---

## Phase 5 — Horizon (not scheduled)

- [ ] Depreciation engine (straight-line + MACRS), asset valuation, Section 179.
- [ ] Balance sheet + liabilities.
- [ ] Revenue-share overhead allocation for full enterprise costing (distinct from the AUE pool).
