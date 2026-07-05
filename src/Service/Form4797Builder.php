<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\farm_financial_mgmt\Entity\DepreciableAssetInterface;

/**
 * Form 4797 — gain/loss on disposed capital assets, with §1245 recapture (5.8).
 *
 * The disposition-side payoff of the raised-vs-purchased fork. Two invariants,
 * both by construction:
 *
 *  - ONE ACCUMULATED-DEPRECIATION SOURCE. Recapture reads exactly the value the
 *    balance-sheet basis column reads — DepreciationEngine::accumulatedDepreciation()
 *    — so the two can never disagree about how much depreciation an asset has
 *    taken. 5.8 is the second reader the single-source commitment (PHASE5 §8) was
 *    made for; 4562 (the depreciation schedule) and 4797 are two views of one
 *    history, and total recaptured can never exceed total depreciation reported.
 *  - ONE basis_type SIGNAL. Ordinary recapture is capped at accumulated
 *    depreciation, which is 0 for a raised (zero-basis) animal — so a raised sale
 *    is all §1231 with no recapture, falling out of the same basis_type/basis the
 *    schedule used, not a parallel rule. An acquired_other (gifted/inherited)
 *    animal recaptures against its actual recorded basis and depreciation, not
 *    zero — the 5.1 override path produces a correct form.
 *
 * §1245/§1231 treatment (breeding livestock is §1231 property with §1245-style
 * recapture): adjusted basis = original basis − accumulated depreciation; gain =
 * proceeds − adjusted basis; ordinary recapture = min(gain, accumulated); §1231
 * gain = gain − recapture; a loss is a §1231 loss with no recapture.
 */
class Form4797Builder {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DepreciationEngine $depreciationEngine,
  ) {}

  /**
   * Builds the Form 4797 rollup for assets disposed in a year.
   */
  public function build(int $year): array {
    $rows = [];
    $ordinary_total = 0.0;
    $s1231_gain_total = 0.0;
    $s1231_loss_total = 0.0;
    foreach ($this->disposedInYear($year) as $asset) {
      $t = $this->treatment($asset);
      $rows[] = $t;
      $ordinary_total = round($ordinary_total + $t['ordinary_recapture'], 2);
      $s1231_gain_total = round($s1231_gain_total + $t['section_1231_gain'], 2);
      $s1231_loss_total = round($s1231_loss_total + $t['section_1231_loss'], 2);
    }
    return [
      'year' => $year,
      'rows' => $rows,
      'ordinary_recapture_total' => $ordinary_total,
      'section_1231_gain_total' => $s1231_gain_total,
      'section_1231_loss_total' => $s1231_loss_total,
    ];
  }

  /**
   * The §1245/§1231 disposition treatment for one asset.
   */
  public function treatment(DepreciableAssetInterface $asset): array {
    $disposal_year = (int) substr((string) $asset->get('disposed_date')->value, 0, 4);

    // The single authoritative accumulated depreciation — the SAME call the
    // balance-sheet basis column makes for this asset (PHASE5 §8).
    $accumulated = $this->depreciationEngine->accumulatedDepreciation($asset, $disposal_year);
    $original_basis = (float) $asset->get('basis')->value;
    $adjusted_basis = round($original_basis - $accumulated, 2);

    $proceeds = 0.0;
    if (!$asset->get('disposal_txn')->isEmpty() && $asset->get('disposal_txn')->entity) {
      $proceeds = (float) $asset->get('disposal_txn')->entity->get('total')->value;
    }

    $gain = round($proceeds - $adjusted_basis, 2);
    if ($gain >= 0) {
      // Ordinary §1245 recapture up to depreciation taken; the rest is §1231.
      $ordinary = round(min($gain, $accumulated), 2);
      $section_1231_gain = round($gain - $ordinary, 2);
      $section_1231_loss = 0.0;
    }
    else {
      $ordinary = 0.0;
      $section_1231_gain = 0.0;
      $section_1231_loss = $gain;
    }

    return [
      'label' => $asset->label(),
      'basis_type' => $asset->get('basis_type')->value,
      'proceeds' => round($proceeds, 2),
      'original_basis' => round($original_basis, 2),
      'accumulated_depreciation' => round($accumulated, 2),
      'adjusted_basis' => $adjusted_basis,
      'gain' => $gain,
      'ordinary_recapture' => $ordinary,
      'section_1231_gain' => $section_1231_gain,
      'section_1231_loss' => $section_1231_loss,
      'disposal_year' => $disposal_year,
    ];
  }

  /**
   * Depreciable assets disposed in the given year.
   *
   * @return \Drupal\farm_financial_mgmt\Entity\DepreciableAssetInterface[]
   */
  protected function disposedInYear(int $year): array {
    $storage = $this->entityTypeManager->getStorage('depreciable_asset');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->exists('disposed_date')
      ->condition('disposed_date', $year . '-01-01', '>=')
      ->condition('disposed_date', $year . '-12-31', '<=')
      ->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

}
