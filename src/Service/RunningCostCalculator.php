<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\farm_financial_mgmt\Service\Aue\AueProviderInterface;

/**
 * Per-animal running cost (SPEC §8, Phase 2). Public API for farm_ranch_ui.
 *
 *   attributable = Σ expense-line.amount where line.asset = animal (period)
 *   shared_pool  = Σ expense-line.amount where asset empty AND category
 *                    allocatable = true (period)
 *   aue_share    = animal_AUE / total_herd_AUE
 *   time_weight  = days_present / period_days   (pro-rate partial presence)
 *   allocated    = shared_pool × aue_share × time_weight
 *   running_cost = attributable + allocated
 *
 * The AUE source is decoupled behind AueProviderInterface; overhead/fixed costs
 * never enter the pool (that is enforced by the allocatable flag, see §7).
 */
class RunningCostCalculator {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ReportBuilder $reportBuilder,
    protected AueProviderInterface $aueProvider,
  ) {}

  /**
   * Computes an animal's running cost over [start, end] (unix seconds).
   *
   * @param int $animal_id
   *   The animal to cost.
   * @param int $start
   *   Period start (unix seconds).
   * @param int $end
   *   Period end (unix seconds).
   * @param int[]|null $herd_animal_ids
   *   The cost-sharing group the shared pool is divided across (e.g. the cattle
   *   herd). Pass this so a cattle feed pool is not AUE-diluted across other
   *   species (SPEC §9). When NULL, the provider's default herd (all animals
   *   present in the period) is used.
   *
   * @return array
   *   Breakdown: attributable, shared_pool, aue, total_herd_aue, aue_share,
   *   period_days, present_days, time_weight, allocated, running_cost.
   */
  public function getRunningCost(int $animal_id, int $start, int $end, ?array $herd_animal_ids = NULL, ?array $enterprise_tids = NULL): array {
    $animal = $this->entityTypeManager->getStorage('asset')->load($animal_id);
    $filters = [
      'from' => date('Y-m-d', $start),
      'to' => date('Y-m-d', $end),
    ];

    $attributable = $animal ? $this->reportBuilder->assetTotals($animal_id, $filters)['expense'] : 0.0;

    // Two-tier pool (SPEC §7 refinement):
    //  - species pool: allocatable expenses tagged to this enterprise, split
    //    within the species herd only.
    //  - shared pool: untagged allocatable expenses, split across the whole
    //    farm by AUE so no single species absorbs them.
    $species_pool = $enterprise_tids ? $this->reportBuilder->allocatablePool($filters, $enterprise_tids) : 0.0;
    $shared_pool = $this->reportBuilder->allocatablePool($filters, 'shared');

    $herd_ids = $herd_animal_ids ?? $this->aueProvider->getHerd($start, $end);
    $herd_aue = $this->sumAue($herd_ids, $start, $end);
    // Farm-wide AUE denominator for the shared pool.
    $farm_aue = $herd_animal_ids === NULL
      ? $herd_aue
      : $this->sumAue($this->aueProvider->getHerd($start, $end), $start, $end);

    $aue = $animal ? $this->aueProvider->getAnimalAue($animal) : 0.0;
    $period_days = max(1, (int) floor(($end - $start) / 86400));
    $present_days = $animal ? $this->aueProvider->getPresenceDays($animal, $start, $end) : 0;
    $time_weight = min(1.0, $present_days / $period_days);

    $allocated_species = $herd_aue > 0 ? $species_pool * ($aue / $herd_aue) * $time_weight : 0.0;
    $allocated_shared = $farm_aue > 0 ? $shared_pool * ($aue / $farm_aue) * $time_weight : 0.0;
    $allocated = $allocated_species + $allocated_shared;

    return [
      'attributable' => round($attributable, 2),
      'species_pool' => round($species_pool, 2),
      'shared_pool' => round($shared_pool, 2),
      'aue' => $aue,
      'herd_aue' => round($herd_aue, 2),
      'farm_aue' => round($farm_aue, 2),
      'period_days' => $period_days,
      'present_days' => $present_days,
      'time_weight' => round($time_weight, 4),
      'allocated_species' => round($allocated_species, 2),
      'allocated_shared' => round($allocated_shared, 2),
      'allocated' => round($allocated, 2),
      'running_cost' => round($attributable + $allocated, 2),
    ];
  }

  /**
   * Sums AUE over animals present in the period.
   */
  protected function sumAue(array $animal_ids, int $start, int $end): float {
    $storage = $this->entityTypeManager->getStorage('asset');
    $total = 0.0;
    foreach ($storage->loadMultiple($animal_ids) as $animal) {
      if ($animal->bundle() !== 'animal' || $this->aueProvider->getPresenceDays($animal, $start, $end) <= 0) {
        continue;
      }
      $total += $this->aueProvider->getAnimalAue($animal);
    }
    return $total;
  }

}
