<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

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
