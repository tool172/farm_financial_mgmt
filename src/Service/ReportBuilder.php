<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\farm_financial_mgmt\Entity\FinancialLiabilityInterface;

/**
 * Report aggregations over the single financial_line table.
 *
 * Every query hits only financial_line and its denormalized txn_date /
 * reporting_year / direction fields — no join to the transaction or category —
 * which is the reporting optimization the Option C data model exists for
 * (SPEC §3.2). Directional and by-category rollups use SQL aggregate queries;
 * monthly bucketing and per-asset attribution load the (modest) matching rows
 * and fold them in PHP.
 *
 * Filters (all optional): year (int reporting_year), from/to ('YYYY-MM-DD' on
 * txn_date), direction ('income'|'expense'), asset (asset id).
 */
class ReportBuilder {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * A fresh aggregate query with the common filters applied.
   */
  protected function aggregateQuery(array $filters) {
    $query = $this->entityTypeManager->getStorage('financial_line')->getAggregateQuery()->accessCheck(FALSE);
    $this->applyFilters($query, $filters);
    return $query;
  }

  /**
   * Applies the common filters to an (aggregate or entity) query.
   */
  protected function applyFilters($query, array $filters): void {
    if (!empty($filters['year'])) {
      $query->condition('reporting_year', $filters['year']);
    }
    if (!empty($filters['from'])) {
      $query->condition('txn_date', $filters['from'], '>=');
    }
    if (!empty($filters['to'])) {
      $query->condition('txn_date', $filters['to'], '<=');
    }
    if (!empty($filters['direction'])) {
      $query->condition('direction', $filters['direction']);
    }
    if (!empty($filters['asset'])) {
      $query->condition('asset', $filters['asset']);
    }
    // Cash-basis: only lines whose transaction has actually been paid. Reaches
    // the payment_status on the parent transaction (not denormalized on lines).
    if (!empty($filters['payment_status'])) {
      $query->condition('transaction.entity.payment_status', $filters['payment_status']);
    }
    // Financing principal (loan principal repayment) is a balance-sheet movement,
    // NOT an operating expense — exclude it from every operating rollup here, the
    // one gate all reports pass through, so no present or future report can sweep
    // it back into P&L or Schedule F. A line with principal_portion > 0 is a
    // principal line; interest is a separate normal expense line. (PHASE5 §3.3.)
    $not_principal = $query->orConditionGroup()
      ->notExists('principal_portion')
      ->condition('principal_portion', 0);
    $query->condition($not_principal);
  }

  /**
   * Income, expense and net totals for the period.
   *
   * @return array
   *   ['income' => float, 'expense' => float, 'net' => float].
   */
  public function directionTotals(array $filters = []): array {
    $rows = $this->aggregateQuery($filters)
      ->groupBy('direction')
      ->aggregate('amount', 'SUM')
      ->execute();
    $out = ['income' => 0.0, 'expense' => 0.0];
    foreach ($rows as $row) {
      $dir = $row['direction'] ?? NULL;
      if (isset($out[$dir])) {
        $out[$dir] = (float) $row['amount_sum'];
      }
    }
    $out['net'] = $out['income'] - $out['expense'];
    return $out;
  }

  /**
   * SUM(amount) grouped by category term id.
   *
   * @return array
   *   [category_tid => float]. Pass a 'direction' filter to scope to one side.
   */
  public function categoryTotals(array $filters = []): array {
    $rows = $this->aggregateQuery($filters)
      ->groupBy('category')
      ->aggregate('amount', 'SUM')
      ->execute();
    $out = [];
    foreach ($rows as $row) {
      if (!empty($row['category'])) {
        $out[(int) $row['category']] = (float) $row['amount_sum'];
      }
    }
    return $out;
  }

  /**
   * Monthly totals per direction: ['YYYY-MM' => ['income'=>, 'expense'=>]].
   *
   * Buckets in PHP because the entity aggregate query cannot date-truncate.
   */
  public function monthlyTotals(array $filters = []): array {
    $out = [];
    foreach ($this->lineRows($filters) as $row) {
      $month = substr((string) $row['txn_date'], 0, 7);
      if ($month === '') {
        continue;
      }
      $out[$month] ??= ['income' => 0.0, 'expense' => 0.0];
      $dir = $row['direction'];
      if (isset($out[$month][$dir])) {
        $out[$month][$dir] += $row['amount'];
      }
    }
    ksort($out);
    return $out;
  }

  /**
   * Income/expense/net for a single asset (per-record P&L).
   */
  public function assetTotals(int $asset_id, array $filters = []): array {
    $filters['asset'] = $asset_id;
    return $this->directionTotals($filters);
  }

  /**
   * Total of unattributed, allocatable expense lines (the AUE pool).
   *
   * SUM(amount) of expense lines with NO asset whose category is flagged
   * allocatable — the pool the per-animal running cost divides by AUE (SPEC §7).
   * Overhead/fixed categories (allocatable = false) are excluded.
   *
   * @param mixed $enterprise
   *   NULL = whole pool regardless of enterprise; 'shared' = only lines with no
   *   enterprise (farm-wide); array of term ids = only lines tagged to those
   *   species (the species-scoped pool).
   */
  public function allocatablePool(array $filters = [], $enterprise = NULL): float {
    $filters['direction'] = 'expense';
    unset($filters['asset']);
    $query = $this->aggregateQuery($filters)
      ->notExists('asset')
      ->condition('category.entity.allocatable', 1);
    if ($enterprise === 'shared') {
      $query->notExists('enterprise');
    }
    elseif (is_array($enterprise) && $enterprise) {
      $query->condition('enterprise', $enterprise, 'IN');
    }
    $result = $query->aggregate('amount', 'SUM')->execute();
    return (float) ($result[0]['amount_sum'] ?? 0);
  }

  /**
   * Income tagged to one or more enterprises (species) — revenue for the P&L.
   */
  public function enterpriseRevenue(array $filters, array $enterprise_tids): float {
    if (empty($enterprise_tids)) {
      return 0.0;
    }
    $filters['direction'] = 'income';
    $result = $this->aggregateQuery($filters)
      ->condition('enterprise', $enterprise_tids, 'IN')
      ->aggregate('amount', 'SUM')
      ->execute();
    return (float) ($result[0]['amount_sum'] ?? 0);
  }

  /**
   * Allocatable expense attributed to a set of assets (direct, asset-tagged).
   */
  public function attributableAllocatable(array $filters, array $asset_ids): float {
    if (empty($asset_ids)) {
      return 0.0;
    }
    $filters['direction'] = 'expense';
    unset($filters['asset']);
    $result = $this->aggregateQuery($filters)
      ->condition('asset', $asset_ids, 'IN')
      ->condition('category.entity.allocatable', 1)
      ->aggregate('amount', 'SUM')
      ->execute();
    return (float) ($result[0]['amount_sum'] ?? 0);
  }

  /**
   * Total allocatable (direct-pool) expense for the period.
   *
   * One side of the direct/overhead partition: category allocatable = TRUE.
   */
  public function totalAllocatable(array $filters): float {
    $filters['direction'] = 'expense';
    $result = $this->aggregateQuery($filters)
      ->condition('category.entity.allocatable', 1)
      ->aggregate('amount', 'SUM')
      ->execute();
    return (float) ($result[0]['amount_sum'] ?? 0);
  }

  /**
   * Overhead expense: non-allocatable and non-capital.
   *
   * The other side of the partition: allocatable = FALSE, excluding capital
   * outlays (which are represented by depreciation, not operating expense).
   * Principal is already excluded by applyFilters().
   */
  public function overheadExpense(array $filters): float {
    $filters['direction'] = 'expense';
    $result = $this->aggregateQuery($filters)
      ->condition('category.entity.allocatable', 0)
      ->condition('category.entity.capital', 1, '<>')
      ->aggregate('amount', 'SUM')
      ->execute();
    return (float) ($result[0]['amount_sum'] ?? 0);
  }

  /**
   * All operating expense (excludes capital outlays; principal already gone).
   *
   * The universe the allocatable flag partitions into direct and overhead —
   * exposed so the allocator can assert direct + overhead == this, making
   * completeness and non-overlap structural rather than an afterthought.
   */
  public function totalOperatingExpense(array $filters): float {
    $filters['direction'] = 'expense';
    $result = $this->aggregateQuery($filters)
      ->condition('category.entity.capital', 1, '<>')
      ->aggregate('amount', 'SUM')
      ->execute();
    return (float) ($result[0]['amount_sum'] ?? 0);
  }

  /**
   * Total principal paid against a liability — SUM(principal_portion) of its lines.
   */
  public function principalPaid(int $liability_id): float {
    $result = $this->entityTypeManager->getStorage('financial_line')->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('liability', $liability_id)
      ->aggregate('principal_portion', 'SUM')
      ->execute();
    return (float) ($result[0]['principal_portion_sum'] ?? 0);
  }

  /**
   * The derived current balance of a liability.
   *
   * One computed value — original principal less all principal paid — read on
   * demand so it can never drift from the payment history (no stored counter).
   * Edit or delete a payment line and this recomputes. The balance sheet (5.6)
   * reads this. (PHASE5 §3.2.)
   */
  public function liabilityBalance(FinancialLiabilityInterface $liability): float {
    $original = (float) $liability->get('original_principal')->value;
    return round($original - $this->principalPaid((int) $liability->id()), 2);
  }

  /**
   * Loads matching line rows (txn_date, amount, direction, category, asset).
   *
   * Used for PHP-side bucketing (monthly, per-record breakdowns).
   *
   * @return array
   *   List of ['txn_date'=>string, 'amount'=>float, 'direction'=>string,
   *   'category'=>int|null, 'asset'=>int|null].
   */
  public function lineRows(array $filters = []): array {
    $storage = $this->entityTypeManager->getStorage('financial_line');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $this->applyFilters($query, $filters);
    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $line) {
      $rows[] = [
        'id' => (int) $line->id(),
        'txn_date' => $line->get('txn_date')->value,
        'amount' => (float) $line->get('amount')->value,
        'direction' => $line->get('direction')->value,
        'category' => $line->get('category')->target_id ? (int) $line->get('category')->target_id : NULL,
        'asset' => $line->get('asset')->target_id ? (int) $line->get('asset')->target_id : NULL,
      ];
    }
    return $rows;
  }

}
