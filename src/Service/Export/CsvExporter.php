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
    'liability',
    'principal_portion',
  ];

  /**
   * Own-format columns for the financial_liability entity (Phase 5.9).
   */
  public const LIABILITY_COLUMNS = [
    'liability_label',
    'lender',
    'liability_type',
    'original_principal',
    'interest_rate',
    'origination_date',
    'term_months',
    'enterprise',
    'notes',
  ];

  /**
   * Own-format columns for the depreciable_asset entity (Phase 5.9).
   */
  public const ASSET_COLUMNS = [
    'asset_label',
    'basis_type',
    'basis',
    'in_service_date',
    'macrs_class',
    'depreciation_method',
    'mid_month',
    'salvage_value',
    'section_179',
    'bonus_pct',
    'disposed_date',
    'market_value',
    'enterprise',
    'farm_asset',
    'acquisition',
    'disposal_txn',
    'notes',
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
        // Loan interest/principal split (Phase 5.9): the liability by label
        // (stable across a round-trip) and the principal portion.
        'liability' => (!$line->get('liability')->isEmpty() && $line->get('liability')->entity)
          ? $line->get('liability')->entity->label() : '',
        'principal_portion' => $line->get('principal_portion')->value ?? '',
      ];
    }
    return $rows;
  }

  /**
   * Own-format rows for all liabilities (header first).
   */
  public function liabilityRows(): array {
    $rows = [self::LIABILITY_COLUMNS];
    foreach ($this->entityTypeManager->getStorage('financial_liability')->loadMultiple() as $liability) {
      $lender = $liability->get('lender')->entity;
      $enterprise = $liability->get('enterprise')->entity;
      $rows[] = [
        'liability_label' => $liability->label(),
        'lender' => $lender?->label() ?? '',
        'liability_type' => $liability->get('liability_type')->value ?? '',
        'original_principal' => $liability->get('original_principal')->value ?? '',
        'interest_rate' => $liability->get('interest_rate')->value ?? '',
        'origination_date' => $liability->get('origination_date')->value ?? '',
        'term_months' => $liability->get('term_months')->value ?? '',
        'enterprise' => $enterprise?->label() ?? '',
        'notes' => (string) $liability->get('notes')->value,
      ];
    }
    return $rows;
  }

  /**
   * Own-format rows for all depreciable assets (header first).
   *
   * farm_asset / acquisition / disposal_txn are exported by id (best-effort on
   * restore, like the line asset references); the intrinsic basis, class,
   * method and dates — what depreciation and 4797 need — are self-contained.
   */
  public function depreciableAssetRows(): array {
    $rows = [self::ASSET_COLUMNS];
    foreach ($this->entityTypeManager->getStorage('depreciable_asset')->loadMultiple() as $asset) {
      $enterprise = $asset->get('enterprise')->entity;
      $rows[] = [
        'asset_label' => $asset->label(),
        'basis_type' => $asset->get('basis_type')->value ?? '',
        'basis' => $asset->get('basis')->value ?? '',
        'in_service_date' => $asset->get('in_service_date')->value ?? '',
        'macrs_class' => $asset->get('macrs_class')->value ?? '',
        'depreciation_method' => $asset->get('depreciation_method')->value ?? '',
        'mid_month' => $asset->get('mid_month')->value ? '1' : '0',
        'salvage_value' => $asset->get('salvage_value')->value ?? '',
        'section_179' => $asset->get('section_179')->value ?? '',
        'bonus_pct' => $asset->get('bonus_pct')->value ?? '',
        'disposed_date' => $asset->get('disposed_date')->value ?? '',
        'market_value' => $asset->get('market_value')->value ?? '',
        'enterprise' => $enterprise?->label() ?? '',
        'farm_asset' => $asset->get('farm_asset')->target_id ?? '',
        'acquisition' => $asset->get('acquisition')->target_id ?? '',
        'disposal_txn' => $asset->get('disposal_txn')->target_id ?? '',
        'notes' => (string) $asset->get('notes')->value,
      ];
    }
    return $rows;
  }

  /**
   * Renders the liabilities export as CSV.
   */
  public function toLiabilityCsv(): string {
    return $this->render($this->liabilityRows());
  }

  /**
   * Renders the depreciable-assets export as CSV.
   */
  public function toDepreciableAssetCsv(): string {
    return $this->render($this->depreciableAssetRows());
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
