<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\farm_financial_mgmt\Service\Aue\AueProviderInterface;

/**
 * Enterprise (per-species) profit & loss (Phase 5.7).
 *
 *   net = revenue − direct − overhead
 *
 * The correctness of this task is that every shared dollar is allocated exactly
 * once, in three shapes:
 *
 *  1. ONE PARTITION. Direct and overhead are complementary subsets of the same
 *     operating-expense universe, split by the category `allocatable` flag —
 *     direct = allocatable, overhead = non-allocatable (both excluding capital
 *     outlays; principal is already excluded by ReportBuilder::applyFilters()).
 *     The allocator asserts direct-pool + overhead-expense == total operating
 *     expense, so completeness and non-overlap are structural, not eyeballed.
 *  2. DEPRECIATION, NOT THE OUTLAY. Overhead takes depreciation (the annual
 *     recognized cost) and specifically NOT the capital-purchase lines (the
 *     outlays, which are excluded from operating expense). Outlay-capitalized
 *     and cost-recognized-over-time are distinct events — the enterprise-P&L
 *     echo of the Sch F line-14 vs capital-grid distinction (5.3).
 *  3. HONEST DENOMINATOR. Revenue-share breaks when an enterprise has zero
 *     revenue (a build-up species) or total revenue is zero. Overhead then falls
 *     back to an AUE-share basis (else an even split), and the report states
 *     which basis it used — a build-up enterprise must not look free.
 */
class EnterpriseCostAllocator {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ReportBuilder $reportBuilder,
    protected DepreciationEngine $depreciationEngine,
    protected AueProviderInterface $aueProvider,
  ) {}

  /**
   * Builds the enterprise P&L for a reporting year.
   */
  public function build(int $year): array {
    $filters = ['year' => $year];

    // --- Enterprises: species with active animals; their herds and AUE. ---
    $enterprises = $this->enterprises();
    $farm_aue = 0.0;
    foreach ($enterprises as &$e) {
      $farm_aue += $e['aue'];
    }
    unset($e);

    // --- Shared (farm-wide) allocatable pool, split by AUE share. ---
    $shared_pool = $this->reportBuilder->allocatablePool($filters, 'shared');

    $total_revenue = 0.0;
    $all_have_revenue = TRUE;
    foreach ($enterprises as &$e) {
      $e['revenue'] = $this->reportBuilder->enterpriseRevenue($filters, $e['tids']);
      $e['aue_share'] = $farm_aue > 0 ? $e['aue'] / $farm_aue : 0.0;
      // The non-shared, exactly-summing parts of direct cost: attributable
      // (asset-tagged) + the species-tagged pool. The farm-wide shared pool is
      // distributed below with an exact remainder so the rows tie to the pool.
      $e['direct_fixed'] = round(
        $this->reportBuilder->attributableAllocatable($filters, $e['herd_ids'])
        + $this->reportBuilder->allocatablePool($filters, $e['tids']),
        2
      );
      $total_revenue += $e['revenue'];
      if ($e['revenue'] <= 0) {
        $all_have_revenue = FALSE;
      }
    }
    unset($e);

    // --- Overhead pool: non-allocatable operating expense + depreciation. ---
    $overhead_expense = $this->reportBuilder->overheadExpense($filters);
    $depreciation = $this->depreciationEngine->totalForYear($year);
    $overhead_pool = round($overhead_expense + $depreciation, 2);

    // --- Allocation basis: honest fallback when revenue-share degenerates. ---
    if ($total_revenue > 0 && $all_have_revenue) {
      $basis = 'revenue';
    }
    elseif ($farm_aue > 0) {
      $basis = 'aue';
    }
    else {
      $basis = 'even';
    }
    $count = count($enterprises) ?: 1;

    // Weights per basis; distribute the shared pool and the overhead pool with a
    // largest-remainder pass so Σ over enterprises equals each pool exactly (no
    // per-row rounding drift) — the partition stays exact at the row level too.
    $shared_shares = $this->distribute($shared_pool, $enterprises, static fn($e) => $e['aue_share']);
    $overhead_weight = fn(array $e) => match ($basis) {
      'revenue' => $total_revenue > 0 ? $e['revenue'] / $total_revenue : 0.0,
      'aue' => $e['aue_share'],
      'even' => 1 / $count,
    };
    $overhead_shares = $this->distribute($overhead_pool, $enterprises, $overhead_weight);

    foreach ($enterprises as $tid => &$e) {
      $e['direct'] = round($e['direct_fixed'] + $shared_shares[$tid], 2);
      $e['overhead'] = $overhead_shares[$tid];
      $e['net'] = round($e['revenue'] - $e['direct'] - $e['overhead'], 2);
    }
    unset($e);

    // --- Partition diagnostics: direct + overhead == operating expense. ---
    $direct_pool = $this->reportBuilder->totalAllocatable($filters);
    $total_operating = $this->reportBuilder->totalOperatingExpense($filters);
    $partition_gap = round($total_operating - $direct_pool - $overhead_expense, 2);

    return [
      'year' => $year,
      'enterprises' => $enterprises,
      'basis' => $basis,
      'shared_pool' => round($shared_pool, 2),
      'direct_pool' => round($direct_pool, 2),
      'overhead_expense' => round($overhead_expense, 2),
      'depreciation' => round($depreciation, 2),
      'overhead_pool' => $overhead_pool,
      'total_operating_expense' => round($total_operating, 2),
      // Zero by construction: the allocatable flag partitions operating expense.
      'partition_gap' => $partition_gap,
      'partition_ok' => ($partition_gap === 0.0),
      'total_revenue' => round($total_revenue, 2),
    ];
  }

  /**
   * The enterprises to cost: SPECIES with active animals (not breeds).
   *
   * animal_type is breed-level here (Angus, Hereford, …) with a species parent
   * (Cattle, Goats, …); the enterprise is the species, matching the ranch
   * dashboards. Each species carries the set of animal_type tids (species root +
   * the breeds present) so revenue/pool lookups match lines tagged to any breed
   * under it or to the species itself.
   *
   * @return array
   *   [species_tid => ['label' => string, 'herd_ids' => int[], 'aue' => float,
   *   'tids' => int[]]].
   */
  protected function enterprises(): array {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $asset_storage = $this->entityTypeManager->getStorage('asset');
    $ids = $asset_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'animal')
      ->condition('archived', 0)
      ->exists('animal_type')
      ->execute();
    if (empty($ids)) {
      return [];
    }

    // Resolve a breed term to its species (topmost ancestor), cached.
    $species_cache = [];
    $resolve = function ($term) use ($term_storage, &$species_cache) {
      $tid = (int) $term->id();
      if (isset($species_cache[$tid])) {
        return $species_cache[$tid];
      }
      $current = $term;
      while (($parents = $term_storage->loadParents($current->id())) !== []) {
        $current = reset($parents);
      }
      return $species_cache[$tid] = $current;
    };

    $enterprises = [];
    foreach ($asset_storage->loadMultiple($ids) as $animal) {
      $breed = $animal->get('animal_type')->entity;
      if ($breed === NULL) {
        continue;
      }
      $species = $resolve($breed);
      $stid = (int) $species->id();
      if (!isset($enterprises[$stid])) {
        $enterprises[$stid] = ['label' => $species->label(), 'herd_ids' => [], 'aue' => 0.0, 'tids' => [$stid => $stid]];
      }
      $enterprises[$stid]['herd_ids'][] = (int) $animal->id();
      $enterprises[$stid]['aue'] += $this->aueProvider->getAnimalAue($animal);
      $enterprises[$stid]['tids'][(int) $breed->id()] = (int) $breed->id();
    }
    foreach ($enterprises as &$e) {
      $e['tids'] = array_values($e['tids']);
    }
    unset($e);

    uasort($enterprises, static fn($a, $b) => strcmp((string) $a['label'], (string) $b['label']));
    return $enterprises;
  }

  /**
   * Distributes a pool total across enterprises by weight, to the exact cent.
   *
   * Integer-cent largest-remainder method: Σ of the returned amounts equals the
   * rounded pool exactly, so per-row allocation carries no rounding drift and
   * the partition holds at the row level, not just the pool level. Falls back to
   * an even split when the weights sum to zero.
   *
   * @return array
   *   [enterprise_tid => float].
   */
  protected function distribute(float $total, array $enterprises, callable $weight_fn): array {
    if (empty($enterprises)) {
      return [];
    }
    $weights = [];
    $wsum = 0.0;
    foreach ($enterprises as $tid => $e) {
      $w = max(0.0, (float) $weight_fn($e));
      $weights[$tid] = $w;
      $wsum += $w;
    }
    $n = count($enterprises);
    if ($wsum <= 0) {
      foreach ($weights as $tid => $w) {
        $weights[$tid] = 1.0;
      }
      $wsum = (float) $n;
    }

    $total_cents = (int) round($total * 100);
    $floor = [];
    $frac = [];
    $sum_floor = 0;
    foreach ($weights as $tid => $w) {
      $exact = $total_cents * ($w / $wsum);
      $f = (int) floor($exact);
      $floor[$tid] = $f;
      $frac[$tid] = $exact - $f;
      $sum_floor += $f;
    }
    $remainder = $total_cents - $sum_floor;
    arsort($frac);
    foreach (array_keys($frac) as $tid) {
      if ($remainder <= 0) {
        break;
      }
      $floor[$tid]++;
      $remainder--;
    }

    $out = [];
    foreach ($floor as $tid => $cents) {
      $out[$tid] = round($cents / 100, 2);
    }
    return $out;
  }

}
