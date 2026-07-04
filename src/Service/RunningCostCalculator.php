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
  public function getRunningCost(int $animal_id, int $start, int $end, ?array $herd_animal_ids = NULL): array {
    $animal = $this->entityTypeManager->getStorage('asset')->load($animal_id);
    $filters = [
      'from' => date('Y-m-d', $start),
      'to' => date('Y-m-d', $end),
    ];

    $attributable = $animal ? $this->reportBuilder->assetTotals($animal_id, $filters)['expense'] : 0.0;
    $pool = $this->reportBuilder->allocatablePool($filters);

    // Herd AUE total over the period, restricted to those actually present.
    $storage = $this->entityTypeManager->getStorage('asset');
    $herd_ids = $herd_animal_ids ?? $this->aueProvider->getHerd($start, $end);
    $total_aue = 0.0;
    foreach ($storage->loadMultiple($herd_ids) as $herd_animal) {
      if ($herd_animal->bundle() !== 'animal' || $this->aueProvider->getPresenceDays($herd_animal, $start, $end) <= 0) {
        continue;
      }
      $total_aue += $this->aueProvider->getAnimalAue($herd_animal);
    }

    $aue = $animal ? $this->aueProvider->getAnimalAue($animal) : 0.0;
    $aue_share = $total_aue > 0 ? $aue / $total_aue : 0.0;

    $period_days = max(1, (int) floor(($end - $start) / 86400));
    $present_days = $animal ? $this->aueProvider->getPresenceDays($animal, $start, $end) : 0;
    $time_weight = min(1.0, $present_days / $period_days);

    $allocated = $pool * $aue_share * $time_weight;

    return [
      'attributable' => round($attributable, 2),
      'shared_pool' => round($pool, 2),
      'aue' => $aue,
      'total_herd_aue' => round($total_aue, 2),
      'aue_share' => round($aue_share, 6),
      'period_days' => $period_days,
      'present_days' => $present_days,
      'time_weight' => round($time_weight, 4),
      'allocated' => round($allocated, 2),
      'running_cost' => round($attributable + $allocated, 2),
    ];
  }

}
