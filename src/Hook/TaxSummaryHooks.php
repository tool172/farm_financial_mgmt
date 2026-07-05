<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\farm_financial_mgmt\Service\DepreciationEngine;

/**
 * Phase 5.3: contributes the depreciation figure to the Schedule F tax summary.
 *
 * Uses the extension seam Phase 3 exposed (hook_farm_financial_mgmt_tax_summary_
 * alter) so the Phase-3 TaxSummaryBuilder stays unaware of depreciation. Line 14
 * ("Depreciation and section 179 expense") is a Schedule F Part II *deduction*,
 * distinct from the capital-purchase grid (which shows capitalized outlays that
 * are NOT deducted). The figure is the DepreciationEngine's year total — the
 * same single source the schedule report shows.
 */
class TaxSummaryHooks {

  use StringTranslationTrait;

  public function __construct(
    protected DepreciationEngine $depreciationEngine,
  ) {}

  /**
   * Adds Schedule F line 14 (depreciation) to the summary for a reporting year.
   */
  #[Hook('farm_financial_mgmt_tax_summary_alter')]
  public function addDepreciation(array &$data, array $filters): void {
    // Line 14 is a per-year figure; skip when no single year is in scope.
    $year = $filters['year'] ?? $data['year'] ?? NULL;
    if ($year === NULL || $year === '') {
      return;
    }

    $this->depreciationEngine->resetWarnings();
    $total = $this->depreciationEngine->totalForYear((int) $year);
    $warnings = $this->depreciationEngine->getWarnings();

    // Nothing to contribute and nothing to flag — leave the summary untouched.
    if ($total <= 0 && empty($warnings)) {
      return;
    }

    $data['schedule_f']['expense'][] = [
      'line' => '14',
      'label' => (string) $this->t('Depreciation and section 179 expense'),
      'amount' => $total,
    ];
    $data['schedule_f']['expense_total'] += $total;
    $data['schedule_f']['net_profit'] -= $total;

    // Expose for the export layer and any warning surfacing.
    $data['depreciation'] = ['line_14' => $total, 'warnings' => $warnings];
  }

}
