# CLAUDE.md — farm_financial_mgmt

Working instructions for Claude Code. Read this, then `SPEC.md` (architecture), then
`TASKS.md` (the ordered build). Build **one phase at a time**; the operator verifies each phase
on the live install before you proceed to the next. Do not jump ahead.

---

## What this module is

A FarmOS 4.x / Drupal 11 module for ranch **income and expense** tracking, with financial
reporting, and (later phases) Schedule F tax planning and QuickBooks CSV interchange. It is
built on **purpose-built content entities**, not on the farmOS log model.

- Host: local Docker install (`farmos.docker.home`), drush available.
- Menu/icon pattern to copy: **`farm_cattle_prices`** (top-level toolbar link + SVG icon).
- Rendering pattern to copy: **`farm_ranch_ui`** — native Drupal server-side rendering
  (controllers + Twig + entity API). **No Vue, no JSON:API.** Charts via **Chart.js** (as in
  `farm_cattle_prices`).

---

## Non-negotiable conventions

1. **PHP 8 attributes, never annotations.** Entity types use
   `#[\Drupal\Core\Entity\Attribute\ContentEntityType(...)]`. Any plugin (actions, field types,
   etc.) uses its attribute form. This is a verified requirement in this codebase.
2. **Standard Drupal content entities, not farmOS asset/log/plan/quantity.** Both entity types
   extend `RevisionableContentEntityBase`. This is the deliberate Option C decision — do not
   re-implement these as log types or attach them to the log model.
3. **Views for lists; custom controllers + services for computed/charted output.** The
   transaction list and tabular/exportable report lists are Views. The per-animal running-cost
   report and the charted P&L/cash-flow reports are custom (Views can't express the allocation
   math or render the charts).
4. **Config in `config/install`; default taxonomy terms via `hook_install()`.** Terms are
   content, so config alone won't ship them.
5. **Dependency injection in classes.** Avoid `\Drupal::` inside services/controllers/entities;
   inject. All settings get a `schema/*.schema.yml` entry.
6. **Idempotent install/update hooks.**

---

## Dependencies

- **Requires:** farmOS core (for `asset` references and the `unit` vocabulary),
  `inline_entity_form` (transaction form embeds line sub-forms).
- **Deliberately NOT used:** `farm_ledger` (its Sale/Purchase log types + Price quantity are the
  log-first path we rejected) and any external automation (no n8n, no outside services —
  everything stays inside the module and FarmOS).

---

## Pre-flight (do BEFORE writing code)

This codebase has bitten us on dependency/attribute details before, so verify first:

1. **`inline_entity_form` Drupal 11 compatibility.** Confirm a D11-compatible release exists.
   **If it does not:** implement the custom inline line-row widget fallback (a multi-value form
   element that creates/edits child `financial_line` entities). Do **not** fall back to modeling
   lines as a multi-value composite *field* — that reintroduces the aggregation problem this
   module's data model exists to avoid. Gate Task 1.8 on this.
2. **Attribute namespaces.** Confirm the exact FQCN of the content-entity attribute and any
   others you use before scaffolding.
3. **farmOS `unit` vocabulary.** Confirm the machine name (`unit`) before wiring the line
   `unit` reference.
4. **Asset reference target.** Confirm the `asset` entity type id/bundles for the line `asset`
   reference (expected targets: animal, land, equipment).

Report the results of these four checks before starting Task 1.1.

---

## Invariants you must preserve (do not "simplify" these away)

- **Denormalization write-through.** On transaction save, copy `date`, `reporting_year`, and
  `direction` down onto each child `financial_line`. This is what makes reports single-table.
  If you remove it, every report grows a join and the Option C rationale collapses.
- **Homogeneous-direction constraint.** All of a transaction's lines must have a `category`
  whose direction equals the transaction's `direction`. Enforce as a validation constraint.
- **`allocatable` enforces methodological correctness, not convenience.** The per-animal
  running-cost pool AUE-weights only categories flagged `allocatable` (direct/variable,
  consumption-scaling costs). Never AUE-allocate overhead/fixed costs (interest, taxes,
  insurance, equipment) — AUE-weighting is the wrong method for them. If per-head overhead is
  ever wanted, that's a separate revenue-share allocation (Phase 5), not the AUE pool.
- **Currency is configurable-global (ISO 4217 code in settings, default USD), not
  per-transaction.** No simultaneous multi-currency, no exchange rates.
- **Direction is a field on a single bundle**, not two bundles. Income and expense transactions
  share one uniform schema.
- **Both `financial_transaction` and `financial_line` are independently revisionable** (the
  Commerce order/order-item pattern), line references parent.
- **Tax fields on `financial_category` exist from Phase 1 but stay unused until Phase 3.** Their
  presence is what keeps tax planning additive rather than a schema migration.
- **CSV import handles only this module's own export schema** (round-trip / backup-restore). No
  bank-statement import, no external formats.

---

## Verify each phase (operator will deploy; give them the commands)

At the end of each phase, provide the exact commands and manual checks, e.g.:

```
composer require drupal/inline_entity_form   # (or note the fallback was used)
drush en farm_financial_mgmt -y
drush updb -y        # entity/schema installs & update hooks
drush cr
```

Then the manual confirmations relevant to that phase (see each phase's exit checklist in
`TASKS.md`) — menu link + currency icon render, default categories seeded, a split transaction
saves with correct totals and an attached receipt, a report renders, etc.

State clearly what is implemented vs. what needs runtime confirmation. Keep commits small and
well-described so the diff is reviewable.
