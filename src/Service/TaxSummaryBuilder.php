<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Builds the Schedule-F tax summary from the ledger (Phase 3).
 *
 * Rolls lines up by category schedule_f_line, but routes non-Schedule-F items
 * out by tax_form: breeding-stock sales → Form 4797, lease income → Form 4835 /
 * Schedule E, capital purchases → depreciation (excluded from the Sch F rollup).
 * Purchased-for-resale income nets its cost basis (1a − 1b = 1c). Cash basis
 * counts only paid transactions (SPEC §7); accrual counts all.
 *
 * The returned structure is the single source the report page renders and the
 * Phase 4 export consumes (Task 3.5).
 */
class TaxSummaryBuilder {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ReportBuilder $reportBuilder,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Builds the tax summary for a period (filters: year / from / to).
   */
  public function build(array $filters): array {
    $method = $this->configFactory->get('farm_financial_mgmt.settings')->get('accounting_method') ?: 'cash';
    if ($method === 'cash') {
      $filters['payment_status'] = 'paid';
    }

    $lines = $this->reportBuilder->lineRows($filters);
    $cat_ids = array_values(array_unique(array_filter(array_map(static fn($r) => $r['category'], $lines))));
    $cats = $cat_ids ? $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($cat_ids) : [];

    $sf_income = $sf_expense = $f4797 = $f4835 = $sch_e = $capital = [];
    $basis_1a = $basis_1b = 0.0;

    foreach ($lines as $r) {
      $cat = $cats[$r['category']] ?? NULL;
      if ($cat === NULL) {
        continue;
      }
      $tax_form = $cat->get('tax_form')->value ?: 'schedule_f';
      $sf = (string) $cat->get('schedule_f_line')->value;
      $is_capital = (bool) $cat->get('capital')->value;
      $amount = $r['amount'];
      $label = $cat->label();

      if ($is_capital || $tax_form === 'none') {
        $this->accum($capital, $label, $label, $amount);
      }
      elseif ($tax_form === 'form_4797') {
        $this->accum($f4797, $label, $label, $amount);
      }
      elseif ($tax_form === 'form_4835') {
        $this->accum($f4835, $label, $label, $amount);
      }
      elseif ($tax_form === 'schedule_e') {
        $this->accum($sch_e, $label, $label, $amount);
      }
      elseif ($r['direction'] === 'income') {
        if ($sf === '1a') {
          $basis_1a += $amount;
        }
        else {
          $this->accum($sf_income, $sf, $label, $amount);
        }
      }
      else {
        if ($sf === '1b') {
          $basis_1b += $amount;
        }
        else {
          $this->accum($sf_expense, $sf, $label, $amount);
        }
      }
    }

    // Part I income: 1a/1b/1c cost-basis netting first, then the rest by line.
    $income_rows = [];
    $income_total = 0.0;
    if ($basis_1a > 0 || $basis_1b > 0) {
      $net_resale = $basis_1a - $basis_1b;
      $income_rows[] = ['line' => '1a', 'label' => 'Sales of livestock/produce bought for resale', 'amount' => $basis_1a];
      $income_rows[] = ['line' => '1b', 'label' => 'Cost or basis of livestock/produce in 1a', 'amount' => $basis_1b];
      $income_rows[] = ['line' => '1c', 'label' => 'Net profit on resale (1a − 1b)', 'amount' => $net_resale];
      $income_total += $net_resale;
    }
    foreach ($this->sortByLine($sf_income) as $line => $row) {
      $income_rows[] = ['line' => $line, 'label' => $row['label'], 'amount' => $row['amount']];
      $income_total += $row['amount'];
    }

    $expense_rows = [];
    $expense_total = 0.0;
    foreach ($this->sortByLine($sf_expense) as $line => $row) {
      $expense_rows[] = ['line' => $line, 'label' => $row['label'], 'amount' => $row['amount']];
      $expense_total += $row['amount'];
    }

    $data = [
      'method' => $method,
      'year' => $filters['year'] ?? NULL,
      'schedule_f' => [
        'income' => $income_rows,
        'income_total' => $income_total,
        'expense' => $expense_rows,
        'expense_total' => $expense_total,
        'net_profit' => $income_total - $expense_total,
      ],
      'form_4797' => $this->rows($f4797),
      'form_4835' => $this->rows($f4835),
      'schedule_e' => $this->rows($sch_e),
      'capital' => $this->rows($capital),
    ];

    // Task 3.5: export/extension hook. Lets Phase 4 (CSV/QuickBooks export) and
    // other modules consume or adjust the summary.
    // @see hook_farm_financial_mgmt_tax_summary_alter()
    $this->moduleHandler->alter('farm_financial_mgmt_tax_summary', $data, $filters);

    return $data;
  }

  /**
   * Accumulates an amount into a keyed bucket.
   */
  protected function accum(array &$bucket, string $key, string $label, float $amount): void {
    $bucket[$key] ??= ['label' => $label, 'amount' => 0.0];
    $bucket[$key]['amount'] += $amount;
  }

  /**
   * Flattens a bucket to a list of ['line','label','amount'].
   */
  protected function rows(array $bucket): array {
    $out = [];
    foreach ($bucket as $key => $row) {
      $out[] = ['line' => $key, 'label' => $row['label'], 'amount' => $row['amount']];
    }
    return $out;
  }

  /**
   * Sorts a line-keyed bucket by Schedule-F line order (21a before 21b, etc.).
   */
  protected function sortByLine(array $bucket): array {
    uksort($bucket, fn($a, $b) => $this->lineSortKey((string) $a) <=> $this->lineSortKey((string) $b));
    return $bucket;
  }

  /**
   * Numeric sort key for a Schedule-F line label ('21a' → 21.1, '24b' → 24.2).
   */
  protected function lineSortKey(string $line): float {
    if (preg_match('/^(\d+)([a-z]?)$/', $line, $m)) {
      $n = (float) $m[1];
      if (!empty($m[2])) {
        $n += (ord($m[2]) - ord('a') + 1) / 10;
      }
      return $n;
    }
    return 999.0;
  }

}
