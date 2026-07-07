# Farm Financial Management

> Track your ranch's money — income, expenses, taxes, and net worth — right
> inside [farmOS](https://farmos.org).

Farm Financial Management adds a **Financial** area to farmOS where you record
what you earn and spend, categorize it the way your taxes need, and get reports
that answer real questions: *Did the cattle make money this year? What's my
Schedule F going to look like? What's the ranch worth if the banker asks?*

It's built for cash-basis farm bookkeeping — a single currency, income and
expenses with itemized lines and receipts — not a double-entry accounting
system. If you keep farm books for a Schedule F and want them to live next to
your animals, pastures, and logs in farmOS, this is for you.

![Financial dashboard](screenshots/dashboard.png)

- **farmOS:** 4.x · **Drupal:** 10 / 11
- **Status:** stable feature set (ledger, reporting, tax planning, depreciation,
  balance sheet, enterprise profitability, import/export).

---

## What you can do

- **Record income and expenses** with itemized lines, a category, an optional
  animal/field, a receipt photo, and payment status.
- **See where the money goes** — profit & loss, spending and income by category,
  cash flow, and month-by-month views.
- **Plan your Schedule F** — categories map to Schedule F lines, and a Tax
  Summary rolls everything up the way the form expects (including breeding-stock
  sales on Form 4797 and depreciation on line 14).
- **Depreciate equipment and breeding stock** — a MACRS depreciation schedule
  (Form 4562), §179 and bonus, and Form 4797 gain/recapture when you sell.
- **Know which enterprise pays** — a per-species profit & loss that splits shared
  costs fairly (feed by animal units, overhead by revenue share).
- **Produce a net-worth statement** — a balance sheet with both market value and
  cost basis, ready for a lending conversation.
- **Back up and hand off** — export your books for your CPA or QuickBooks, and
  re-import your own export to restore.

---

## Install

```bash
drush en farm_financial_mgmt -y
drush updb -y      # sets up the ledger, categories, and defaults
drush cr
```

A **Financial** item appears in the farmOS toolbar. Everything below lives under
it.

**Requires** the farmOS `asset` and `farm_unit` modules plus `inline_entity_form`
(all standard on a farmOS install). *Optional:* if you also run
`farm_cattle_prices` and `farm_ranch_ui`, the balance sheet will value breeding
cattle at live USDA market prices automatically.

---

## Getting started

1. **Open Financial → Transactions** and add your first transaction.
   Choose Income or Expense, set the date and who it was with, then add one or
   more lines — each with a category and an amount. Attach the receipt if you
   have it.

   ![Adding a transaction](screenshots/add-transaction.png)

2. **Categorize consistently.** The default categories already map to Schedule F
   lines, so the tax reports work out of the box. Tag a line to an animal or
   field when you want per-animal or per-enterprise costing.

3. **Read the reports.** Head to **Financial → Reports**. As soon as you have a
   few transactions, the dashboard and reports fill in.

---

## The reports

Everything under **Financial → Reports**. Each answers a specific question.

### Profit & Loss
Income minus operating expense minus depreciation. A true income statement —
buying a tractor doesn't show up as a loss the year you buy it; its cost is
spread over its life as depreciation, the same way your taxes treat it.

![Profit & Loss](screenshots/profit-loss.png)

### Spending / Income by Category, Cash Flow, Monthly
Where the money went, charted, and over time.

![Spending by category](screenshots/spending.png)

### Tax Summary (Schedule F)
Your farm income and deductions organized by Schedule F line — Part I income,
Part II expenses, the purchased-for-resale netting (lines 1a/1b/1c), depreciation
on line 14, and anything that belongs on Form 4797 or 4835 pulled out separately.
Turn tax planning off in Settings if you don't want it.

![Tax Summary](screenshots/tax-summary.png)

### Depreciation Schedule (Form 4562)
Every depreciable asset with its basis, §179, bonus, method, this year's
depreciation, accumulated depreciation, and remaining book value.

![Depreciation schedule](screenshots/depreciation.png)

### Form 4797 (Dispositions)
When you sell breeding or draft stock or equipment, this shows the gain or loss
split into ordinary depreciation recapture and §1231 gain — including the
difference between a **purchased** animal (recaptures depreciation) and a
**raised** one (zero basis, all §1231).

### Enterprise P&L
Per-species profit and loss: revenue, direct costs (feed and vet allocated by
animal units), and a fair share of overhead. A species you haven't sold from yet
still shows its true cost, so it can't look "free."

![Enterprise P&L](screenshots/enterprise-pl.png)

### Balance Sheet (Net Worth)
Assets minus liabilities equals equity, in two columns — **market value** (what
it's worth today) and **cost basis** (what the IRS sees) — with your cash and
loan balances. The gap between the two equity numbers is your unrealized
appreciation. The header states what's automatic and what you entered.

![Balance sheet](screenshots/balance-sheet.png)

---

## Capital assets, loans, and taxes

- **Depreciable Assets** (Financial → Depreciable Assets) — add equipment or
  breeding stock; the depreciation class and method are filled in from the
  purchase category and you can adjust them. Mark an asset **raised** for
  home-raised breeding stock (zero basis) or **gifted/inherited** for stepped-up
  or carryover basis.
- **Liabilities** (Financial → Liabilities) — record loans and notes. Enter a
  loan payment as an expense with an interest line and a principal line; the
  interest is deductible, the principal draws down the loan balance, and the
  balance is always computed from your payment history.
- **Depreciation Limits** (Financial → Depreciation Limits) — §179 caps and bonus
  % change by law every year. Set them here as the IRS publishes them. A year you
  haven't set is treated as $0 / 0% with a visible notice — the tool won't guess.

![Depreciation limits](screenshots/depreciation-limits.png)

---

## Import & export

Under **Financial → Import / Export**:

- **CPA / backup CSV** — your whole ledger, one row per line. Hand it to an
  accountant, or keep it as a backup.
- **QuickBooks CSV** — a QuickBooks-mappable version.
- **Liabilities / Depreciable Assets CSV** — the capital layer, for a full backup.
- **Import** — upload a CSV this module exported to restore or move it. (It reads
  its own format only — not bank statements or third-party files.)

---

## Settings

**Financial → Settings:** currency, cash vs. accrual, the tax-planning on/off
toggle, and your entered **cash position** for the balance sheet.

---

## Screenshots

The images above live in `screenshots/`. To capture them on your own install,
visit each page (with a few transactions entered so they're not empty):

| Image | Page |
|---|---|
| `dashboard.png` | `/financial` |
| `add-transaction.png` | `/financial/transaction/add` |
| `profit-loss.png` | `/financial/reports/profit-loss` |
| `spending.png` | `/financial/reports/spending` |
| `tax-summary.png` | `/financial/reports/tax-summary` |
| `depreciation.png` | `/financial/reports/depreciation` |
| `enterprise-pl.png` | `/financial/reports/enterprise-pl` |
| `balance-sheet.png` | `/financial/reports/balance-sheet` |
| `depreciation-limits.png` | `/financial/settings/depreciation-limits` |

---

## Good to know

- **Cash-basis, single currency.** Not a general ledger, no accounts payable /
  receivable, no bank reconciliation.
- **The balance sheet's cash and loan balances are entered by you** — a farm
  ledger tracks money in and out, not your bank balance, so you tell it your cash
  position and it does the rest. The report says so on its face.
- **Your data stays in farmOS.** No external services; the optional cattle-price
  integration only reads prices you're already pulling with `farm_cattle_prices`.
- **The tax math is checked.** MACRS depreciation follows the IRS Publication 946
  tables, verified to the cent, and the module refuses to compute a number it
  can't back with a published table rather than guessing.

## Permissions

- *View financial transactions* — read the ledger
- *Manage financial transactions* — add/edit transactions
- *View financial reports* — reports and dashboard
- *Administer financial management* — categories, contacts, settings
