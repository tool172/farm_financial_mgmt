<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service\Export;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Imports this module's own CSV export schema (Task 4.3).
 *
 * Round-trip / backup-restore / cross-install move only — no bank-statement or
 * third-party formats (SPEC §8). Rows are grouped by transaction_id back into
 * transactions; categories and counterparties are matched by name (categories
 * optionally created on import); units matched-or-created in the farmOS unit
 * vocabulary; asset references restored by id when the asset exists on this
 * install (skipped with a warning otherwise, since ids do not survive a
 * cross-install move). The transaction postsave recomputes total + denorm.
 */
class CsvImporter {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Imports CSV text. Returns a stats array.
   */
  public function import(string $csv, bool $create_categories = TRUE): array {
    $stats = [
      'transactions' => 0,
      'lines' => 0,
      'categories_created' => 0,
      'contacts_created' => 0,
      'warnings' => [],
    ];

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $csv);
    rewind($handle);
    $header = fgetcsv($handle, 0, ',', '"', '\\');
    if (!$header || !in_array('transaction_id', $header, TRUE)) {
      fclose($handle);
      $stats['warnings'][] = 'Not a recognized financial export file (missing transaction_id column).';
      return $stats;
    }
    $idx = array_flip($header);
    $get = static fn(array $row, string $col) => isset($idx[$col]) ? trim((string) ($row[$idx[$col]] ?? '')) : '';

    // Group rows by transaction_id.
    $groups = [];
    $n = 0;
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== FALSE) {
      if (count(array_filter($row, static fn($v) => $v !== '' && $v !== NULL)) === 0) {
        continue;
      }
      $tid = $get($row, 'transaction_id');
      $key = $tid !== '' ? 'id:' . $tid : 'row:' . $n;
      $groups[$key][] = $row;
      $n++;
    }
    fclose($handle);

    $line_storage = $this->entityTypeManager->getStorage('financial_line');
    $txn_storage = $this->entityTypeManager->getStorage('financial_transaction');

    foreach ($groups as $group) {
      $first = $group[0];
      $direction = $get($first, 'direction') ?: 'expense';

      $line_ids = [];
      foreach ($group as $row) {
        $category_name = $get($row, 'category');
        $category = $category_name ? $this->resolveCategory($category_name, $get($row, 'direction') ?: $direction, $create_categories, $stats) : NULL;
        if ($category === NULL) {
          $stats['warnings'][] = sprintf('Line skipped: category "%s" not found.', $category_name);
          continue;
        }
        $values = ['category' => $category->id()];
        foreach (['amount', 'quantity', 'unit_price'] as $f) {
          if ($get($row, $f) !== '') {
            $values[$f] = $get($row, $f);
          }
        }
        if (($unit_name = $get($row, 'unit')) !== '') {
          $unit = $this->ensureUnit($unit_name);
          if ($unit) {
            $values['unit'] = $unit->id();
          }
        }
        if (($asset_str = $get($row, 'asset')) !== '') {
          $asset_ids = $this->validAssets($asset_str, $stats);
          if ($asset_ids) {
            $values['asset'] = $asset_ids;
          }
        }
        if (($memo = $get($row, 'memo')) !== '') {
          $values['memo'] = $memo;
        }
        $line = $line_storage->create($values);
        $line->save();
        $line_ids[] = $line->id();
        $stats['lines']++;
      }

      if (!$line_ids) {
        continue;
      }

      $counterparty_name = $get($first, 'counterparty');
      $values = [
        'direction' => $direction,
        'payment_status' => $get($first, 'payment_status') ?: 'paid',
        'lines' => $line_ids,
      ];
      foreach (['date', 'payment_method', 'reference', 'notes', 'label'] as $f) {
        if ($get($first, $f) !== '') {
          $values[$f] = $get($first, $f);
        }
      }
      if (($ry = $get($first, 'reporting_year')) !== '') {
        $values['reporting_year'] = (int) $ry;
      }
      if ($counterparty_name !== '') {
        $values['counterparty'] = $this->ensureContact($counterparty_name, $stats)->id();
      }
      $txn = $txn_storage->create($values);
      $txn->save();
      $stats['transactions']++;
    }

    return $stats;
  }

  /**
   * Finds a category by name, optionally creating it under Income/Expense.
   */
  protected function resolveCategory(string $name, string $direction, bool $create, array &$stats) {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing = $storage->loadByProperties(['vid' => 'financial_category', 'name' => $name]);
    if ($existing) {
      return reset($existing);
    }
    if (!$create) {
      return NULL;
    }
    $parents = $storage->loadByProperties([
      'vid' => 'financial_category',
      'name' => $direction === 'income' ? 'Income' : 'Expense',
    ]);
    $parent = $parents ? reset($parents) : NULL;
    $term = $storage->create([
      'vid' => 'financial_category',
      'name' => $name,
      'direction' => $direction,
      'allocatable' => FALSE,
    ] + ($parent ? ['parent' => $parent->id()] : []));
    $term->save();
    $stats['categories_created']++;
    return $term;
  }

  /**
   * Finds or creates a counterparty contact by name.
   */
  protected function ensureContact(string $name, array &$stats) {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing = $storage->loadByProperties(['vid' => 'financial_contact', 'name' => $name]);
    if ($existing) {
      return reset($existing);
    }
    $term = $storage->create(['vid' => 'financial_contact', 'name' => $name]);
    $term->save();
    $stats['contacts_created']++;
    return $term;
  }

  /**
   * Finds or creates a unit term by name in the farmOS unit vocabulary.
   */
  protected function ensureUnit(string $name) {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing = $storage->loadByProperties(['vid' => 'unit', 'name' => $name]);
    if ($existing) {
      return reset($existing);
    }
    $term = $storage->create(['vid' => 'unit', 'name' => $name]);
    $term->save();
    return $term;
  }

  /**
   * Filters a ';'-joined asset id list to those that exist on this install.
   */
  protected function validAssets(string $asset_str, array &$stats): array {
    $ids = array_filter(array_map('intval', explode(';', $asset_str)));
    if (!$ids) {
      return [];
    }
    $existing = $this->entityTypeManager->getStorage('asset')->getQuery()
      ->accessCheck(FALSE)
      ->condition('id', $ids, 'IN')
      ->execute();
    $missing = array_diff($ids, array_map('intval', $existing));
    foreach ($missing as $id) {
      $stats['warnings'][] = sprintf('Asset %d not found on this install; reference skipped.', $id);
    }
    return array_values(array_map('intval', $existing));
  }

}
