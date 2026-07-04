<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service\Export;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Flattens transactions + lines to CSV rows (SPEC §8 Phase 4).
 *
 * One row per financial_line, carrying its transaction's envelope fields. This
 * is the module's own export schema — it doubles as the CPA hand-off file and
 * the round-trip / backup format the importer reads (Task 4.3). The leading
 * transaction_id column is what lets the importer regroup lines into their
 * transactions; a CPA can simply ignore it. Receipts (files) are not exported.
 */
class CsvExporter {

  /**
   * The own-format column schema, in order.
   */
  public const COLUMNS = [
    'transaction_id',
    'label',
    'date',
    'reporting_year',
    'direction',
    'counterparty',
    'payment_method',
    'payment_status',
    'reference',
    'notes',
    'category',
    'schedule_f_line',
    'amount',
    'quantity',
    'unit_price',
    'unit',
    'asset',
    'memo',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds rows (header first) for the given period filters.
   *
   * @return array
   *   List of scalar rows; the first is the header.
   */
  public function rows(array $filters = []): array {
    $rows = [self::COLUMNS];
    $line_storage = $this->entityTypeManager->getStorage('financial_line');

    $query = $line_storage->getQuery()->accessCheck(FALSE);
    if (!empty($filters['year'])) {
      $query->condition('reporting_year', $filters['year']);
    }
    if (!empty($filters['from'])) {
      $query->condition('txn_date', $filters['from'], '>=');
    }
    if (!empty($filters['to'])) {
      $query->condition('txn_date', $filters['to'], '<=');
    }
    $query->sort('transaction', 'ASC')->sort('id', 'ASC');
    $ids = $query->execute();
    if (empty($ids)) {
      return $rows;
    }

    foreach ($line_storage->loadMultiple($ids) as $line) {
      $transaction = $line->get('transaction')->entity;
      $category = $line->get('category')->entity;
      $unit = $line->get('unit')->entity;
      $counterparty = $transaction ? $transaction->get('counterparty')->entity : NULL;

      // Asset ids (multi) as a space-free, semicolon-joined list.
      $asset_ids = [];
      foreach ($line->get('asset')->referencedEntities() as $asset) {
        $asset_ids[] = $asset->id();
      }

      $rows[] = [
        'transaction_id' => $transaction?->id() ?? '',
        'label' => $transaction?->label() ?? '',
        'date' => $line->get('txn_date')->value ?? ($transaction ? $transaction->get('date')->value : ''),
        'reporting_year' => $line->get('reporting_year')->value ?? '',
        'direction' => $line->get('direction')->value ?? '',
        'counterparty' => $counterparty?->label() ?? '',
        'payment_method' => $transaction ? $transaction->get('payment_method')->value : '',
        'payment_status' => $transaction ? $transaction->get('payment_status')->value : '',
        'reference' => $transaction ? $transaction->get('reference')->value : '',
        'notes' => $transaction ? (string) $transaction->get('notes')->value : '',
        'category' => $category?->label() ?? '',
        'schedule_f_line' => $category && !$category->get('schedule_f_line')->isEmpty() ? $category->get('schedule_f_line')->value : '',
        'amount' => $line->get('amount')->value ?? '',
        'quantity' => $line->get('quantity')->value ?? '',
        'unit_price' => $line->get('unit_price')->value ?? '',
        'unit' => $unit?->label() ?? '',
        'asset' => implode(';', $asset_ids),
        'memo' => (string) $line->get('memo')->value,
      ];
    }
    return $rows;
  }

  /**
   * QuickBooks-mappable rows (Task 4.2), header first.
   *
   * QBO-compatible columns: Date, Payee, Account (from the category's
   * qb_account), Amount (income positive, expense negative), Memo. One row per
   * line so each maps to its account.
   */
  public function qbRows(array $filters = []): array {
    $rows = [['Date', 'Payee', 'Account', 'Amount', 'Memo']];
    $line_storage = $this->entityTypeManager->getStorage('financial_line');
    $query = $line_storage->getQuery()->accessCheck(FALSE);
    if (!empty($filters['year'])) {
      $query->condition('reporting_year', $filters['year']);
    }
    if (!empty($filters['from'])) {
      $query->condition('txn_date', $filters['from'], '>=');
    }
    if (!empty($filters['to'])) {
      $query->condition('txn_date', $filters['to'], '<=');
    }
    $query->sort('txn_date', 'ASC')->sort('id', 'ASC');
    $ids = $query->execute();
    if (empty($ids)) {
      return $rows;
    }
    foreach ($line_storage->loadMultiple($ids) as $line) {
      $transaction = $line->get('transaction')->entity;
      $category = $line->get('category')->entity;
      $counterparty = $transaction ? $transaction->get('counterparty')->entity : NULL;
      $account = $category && !$category->get('qb_account')->isEmpty()
        ? $category->get('qb_account')->value
        : ($category?->label() ?? '');
      $amount = (float) $line->get('amount')->value;
      // QBO convention: money in positive, money out negative.
      $signed = $line->get('direction')->value === 'expense' ? -$amount : $amount;
      $rows[] = [
        $line->get('txn_date')->value ?? '',
        $counterparty?->label() ?? '',
        $account,
        number_format($signed, 2, '.', ''),
        (string) $line->get('memo')->value ?: ($transaction?->label() ?? ''),
      ];
    }
    return $rows;
  }

  /**
   * Renders the own-format rows as a CSV string.
   */
  public function toCsv(array $filters = []): string {
    return $this->render($this->rows($filters));
  }

  /**
   * Renders the QuickBooks rows as a CSV string.
   */
  public function toQbCsv(array $filters = []): string {
    return $this->render($this->qbRows($filters));
  }

  /**
   * Renders rows to a CSV string.
   */
  protected function render(array $rows): string {
    $handle = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
      fputcsv($handle, array_values($row), ',', '"', '\\');
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return $csv;
  }

}
