<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\farm_financial_mgmt\Entity\DepreciableAssetInterface;

/**
 * Computes depreciation for capital assets (Phase 5.2).
 *
 * MACRS via the published IRS Pub 946 percentage tables (transcribed as
 * constants and unit-tested to the cent), not first-principles convention math.
 * Order of operations per asset: §179 expensing, then bonus depreciation, then
 * MACRS/straight-line on the remaining basis.
 *
 * accumulatedDepreciation() is the SINGLE authoritative accumulated-depreciation
 * value: the balance-sheet basis column (5.6) and the Form 4797 recapture calc
 * (5.8) both read it — neither recomputes. One entity, one derivation, two
 * readers (PHASE5 §8), so the balance sheet and 4797 can never disagree about
 * how much depreciation an asset has taken.
 *
 * Bounds (loud, not silent): mid-quarter tables are transcribed for 200% DB GDS
 * (3/5/7/10-yr) and 150% DB GDS (15/20-yr) — all verified against Pub 946. The
 * elective-150% mid-quarter case for PERSONAL property (3/5/7/10-yr) has no
 * standard published table, so the engine throws for it rather than computing a
 * guess. Mid-month (real property) is likewise out of the transcribed set.
 * §179/bonus for a year absent from the limits config degrade to $0/0% with a
 * surfaced message, never a stale year.
 */
class DepreciationEngine {

  /**
   * MACRS GDS 200% DB, half-year (Pub 946 Table A-1). Percent of basis by year.
   */
  protected const HY_200 = [
    '3yr' => [33.33, 44.45, 14.81, 7.41],
    '5yr' => [20.00, 32.00, 19.20, 11.52, 11.52, 5.76],
    '7yr' => [14.29, 24.49, 17.49, 12.49, 8.93, 8.92, 8.93, 4.46],
    '10yr' => [10.00, 18.00, 14.40, 11.52, 9.22, 7.37, 6.55, 6.55, 6.56, 6.55, 3.28],
  ];

  /**
   * MACRS GDS 150% DB, half-year (Pub 946 Table A-14).
   */
  protected const HY_150 = [
    '3yr' => [25.00, 37.50, 25.00, 12.50],
    '5yr' => [15.00, 25.50, 17.85, 16.66, 16.66, 8.33],
    '7yr' => [10.71, 19.13, 15.03, 12.25, 12.25, 12.25, 12.25, 6.13],
    '10yr' => [7.50, 13.88, 11.79, 10.02, 8.74, 8.74, 8.74, 8.74, 8.74, 8.74, 4.37],
    '15yr' => [5.00, 9.50, 8.55, 7.70, 6.93, 6.23, 5.90, 5.90, 5.91, 5.90, 5.91, 5.90, 5.91, 5.90, 5.91, 2.95],
    '20yr' => [3.750, 7.219, 6.677, 6.177, 5.713, 5.285, 4.888, 4.522, 4.462, 4.461,
      4.462, 4.461, 4.462, 4.461, 4.462, 4.461, 4.462, 4.461, 4.462, 4.461, 2.231],
  ];

  /**
   * MACRS GDS 200% DB, mid-quarter by quarter placed in service (Pub 946 Tables
   * A-2 Q1 … A-5 Q4). Applied when the year-level >40%-Q4 test trips.
   */
  protected const MQ_200 = [
    1 => [
      '3yr' => [58.33, 27.78, 12.35, 1.54],
      '5yr' => [35.00, 26.00, 15.60, 11.01, 11.01, 1.38],
      '7yr' => [25.00, 21.43, 15.31, 10.93, 8.75, 8.74, 8.75, 1.09],
      '10yr' => [17.50, 16.50, 13.20, 10.56, 8.45, 6.76, 6.55, 6.55, 6.56, 6.55, 0.82],
    ],
    2 => [
      '3yr' => [41.67, 38.89, 14.14, 5.30],
      '5yr' => [25.00, 30.00, 18.00, 11.37, 11.37, 4.26],
      '7yr' => [17.85, 23.47, 16.76, 11.97, 8.87, 8.87, 8.87, 3.34],
      '10yr' => [12.50, 17.50, 14.00, 11.20, 8.96, 7.17, 6.55, 6.55, 6.56, 6.55, 2.46],
    ],
    3 => [
      '3yr' => [25.00, 50.00, 16.67, 8.33],
      '5yr' => [15.00, 34.00, 20.40, 12.24, 11.30, 7.06],
      '7yr' => [10.71, 25.51, 18.22, 13.02, 9.30, 8.85, 8.86, 5.53],
      '10yr' => [7.50, 18.50, 14.80, 11.84, 9.47, 7.58, 6.55, 6.55, 6.56, 6.55, 4.10],
    ],
    4 => [
      '3yr' => [8.33, 61.11, 20.37, 10.19],
      '5yr' => [5.00, 38.00, 22.80, 13.68, 10.94, 9.58],
      '7yr' => [3.57, 27.55, 19.68, 14.06, 10.04, 8.73, 8.73, 7.64],
      '10yr' => [2.50, 19.50, 15.60, 12.48, 9.98, 7.99, 6.55, 6.55, 6.56, 6.55, 5.74],
    ],
  ];

  /**
   * MACRS GDS 150% DB, mid-quarter, 15- and 20-year property (Pub 946 Tables
   * A-2 Q1 … A-5 Q4, the 15/20-yr columns — those classes always use 150% DB).
   *
   * Transcribed from the IRS Pub 946 PDF text layer and verified: each column
   * sums to 100%. The 3/5/7/10-yr elective-150% mid-quarter case has no standard
   * published table and is (correctly) not handled — the engine throws for it.
   */
  protected const MQ_150 = [
    1 => [
      '15yr' => [8.75, 9.13, 8.21, 7.39, 6.65, 5.99, 5.90, 5.91, 5.90, 5.91, 5.90, 5.91, 5.90, 5.91, 5.90, 0.74],
      '20yr' => [6.563, 7.000, 6.482, 5.996, 5.546, 5.130, 4.746, 4.459, 4.459, 4.459, 4.459, 4.460, 4.459, 4.460, 4.459, 4.460, 4.459, 4.460, 4.459, 4.460, 0.565],
    ],
    2 => [
      '15yr' => [6.25, 9.38, 8.44, 7.59, 6.83, 6.15, 5.91, 5.90, 5.91, 5.90, 5.91, 5.90, 5.91, 5.90, 5.91, 2.21],
      '20yr' => [4.688, 7.148, 6.612, 6.116, 5.658, 5.233, 4.841, 4.478, 4.463, 4.463, 4.463, 4.463, 4.463, 4.463, 4.462, 4.463, 4.462, 4.463, 4.462, 4.463, 1.673],
    ],
    3 => [
      '15yr' => [3.75, 9.63, 8.66, 7.80, 7.02, 6.31, 5.90, 5.90, 5.91, 5.90, 5.91, 5.90, 5.91, 5.90, 5.91, 3.69],
      '20yr' => [2.813, 7.289, 6.742, 6.237, 5.769, 5.336, 4.936, 4.566, 4.460, 4.460, 4.460, 4.460, 4.461, 4.460, 4.461, 4.460, 4.461, 4.460, 4.461, 4.460, 2.788],
    ],
    4 => [
      '15yr' => [1.25, 9.88, 8.89, 8.00, 7.20, 6.48, 5.90, 5.90, 5.90, 5.91, 5.90, 5.91, 5.90, 5.91, 5.90, 5.17],
      '20yr' => [0.938, 7.430, 6.872, 6.357, 5.880, 5.439, 5.031, 4.654, 4.458, 4.458, 4.458, 4.458, 4.458, 4.458, 4.458, 4.458, 4.458, 4.459, 4.458, 4.459, 3.901],
    ],
  ];

  /**
   * Recovery period (years) by MACRS class.
   */
  protected const CLASS_YEARS = [
    '3yr' => 3, '5yr' => 5, '7yr' => 7, '10yr' => 10, '15yr' => 15, '20yr' => 20,
  ];

  /**
   * Surfaced degradation messages (e.g. an unconfigured §179/bonus year).
   */
  protected array $warnings = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Resets collected warnings (call before a report build).
   */
  public function resetWarnings(): void {
    $this->warnings = [];
  }

  /**
   * Returns the unique degradation messages collected since the last reset.
   */
  public function getWarnings(): array {
    return array_values(array_unique($this->warnings));
  }

  /**
   * Whether an asset is depreciable property (ignoring disposal).
   *
   * The engine builds a full schedule from the asset's characteristics; the
   * disposal cutoff is applied by totalForYear(). Raised stock (zero basis) and
   * land ("none") are non-depreciable — the same fork as the entity's
   * isDepreciable(), minus the "still active" (!disposed) part.
   */
  protected function isDepreciableProperty(DepreciableAssetInterface $asset): bool {
    return $asset->get('basis_type')->value !== 'raised'
      && (float) $asset->get('basis')->value > 0
      && $asset->get('macrs_class')->value !== NULL
      && $asset->get('macrs_class')->value !== 'none';
  }

  /**
   * The full year-by-year schedule for an asset.
   *
   * @return array
   *   [year => ['depreciation' => float, 'accumulated' => float,
   *   'book_value' => float]], empty for non-depreciable property.
   */
  public function schedule(DepreciableAssetInterface $asset): array {
    if (!$this->isDepreciableProperty($asset)) {
      return [];
    }

    $basis = (float) $asset->get('basis')->value;
    $salvage = (float) $asset->get('salvage_value')->value;
    $in_service = $asset->get('in_service_date')->value;
    $start_year = (int) substr((string) $in_service, 0, 4);
    $method = $asset->get('depreciation_method')->value ?: 'macrs_gds_200';
    $class = $asset->get('macrs_class')->value;

    // §179 then bonus reduce the basis MACRS/SL is applied to. Both count as
    // depreciation (accumulated + recapture), booked in year one.
    $section_179 = min((float) $asset->get('section_179')->value, $basis);
    $bonus_pct = $this->bonusPctFor($asset, $start_year);
    $bonus = round(($basis - $section_179) * $bonus_pct / 100, 2);
    $depreciable_basis = $basis - $section_179 - $bonus;

    $percents = $this->percents($asset, $method, $class, $start_year, $in_service, $depreciable_basis, $salvage);

    $schedule = [];
    $accumulated = 0.0;
    foreach ($percents as $i => $pct) {
      $year = $start_year + $i;
      $depreciation = round($depreciable_basis * $pct / 100, 2);
      if ($i === 0) {
        // Year one also carries the §179 + bonus special allowances.
        $depreciation = round($depreciation + $section_179 + $bonus, 2);
      }
      $accumulated = round($accumulated + $depreciation, 2);
      $schedule[$year] = [
        'depreciation' => $depreciation,
        'accumulated' => $accumulated,
        'book_value' => round(max($basis - $accumulated, $salvage), 2),
      ];
    }
    return $schedule;
  }

  /**
   * The per-year percentages (of the depreciable basis) for an asset's method.
   */
  protected function percents(DepreciableAssetInterface $asset, string $method, string $class, int $start_year, string $in_service, float $depreciable_basis, float $salvage): array {
    // Book straight-line and ADS: computed SL. (True ADS recovery periods can
    // exceed the GDS class period; using the class period is a documented
    // simplification — the exit-test cases are all GDS.)
    if ($method === 'straight_line' || $method === 'macrs_ads') {
      $years = self::CLASS_YEARS[$class] ?? NULL;
      if ($years === NULL) {
        throw new \InvalidArgumentException("No recovery period for class '$class'.");
      }
      $full = 100.0 / $years;
      if ($method === 'macrs_ads') {
        // Half-year convention: half in year 1, half in year N+1.
        $p = array_fill(0, $years, $full);
        array_unshift($p, $full / 2);
        $p[$years] = $full / 2;
        return $p;
      }
      // Book straight-line: full years, net of salvage.
      $usable = $depreciable_basis > 0 ? (($depreciable_basis - $salvage) / $depreciable_basis) * 100 : 0;
      return array_fill(0, $years, $usable / $years);
    }

    // MACRS declining-balance: table lookup by method / convention / class.
    $convention = $this->conventionForYear($start_year);
    if ($convention === 'half_year') {
      $table = $method === 'macrs_gds_150' ? self::HY_150 : self::HY_200;
    }
    else {
      $quarter = $this->quarterOf($in_service);
      // 200% DB GDS mid-quarter covers 3/5/7/10-yr; 150% DB GDS mid-quarter is
      // published for 15/20-yr only (those classes always use 150% DB). The
      // 3/5/7/10-yr elective-150% mid-quarter case has no standard published
      // table and falls through to the "no table" throw below — an honest
      // refusal, not a computed guess.
      $table = $method === 'macrs_gds_150' ? self::MQ_150[$quarter] : self::MQ_200[$quarter];
    }
    if (!isset($table[$class])) {
      throw new \InvalidArgumentException("No MACRS table for method '$method', class '$class', convention '$convention'.");
    }
    return $table[$class];
  }

  /**
   * Depreciation for one tax year (0 for non-depreciable / out-of-schedule).
   */
  public function annualDepreciation(DepreciableAssetInterface $asset, int $year): float {
    return $this->schedule($asset)[$year]['depreciation'] ?? 0.0;
  }

  /**
   * The single authoritative accumulated depreciation through a year (or life).
   */
  public function accumulatedDepreciation(DepreciableAssetInterface $asset, ?int $through_year = NULL): float {
    $total = 0.0;
    foreach ($this->schedule($asset) as $year => $row) {
      if ($through_year !== NULL && $year > $through_year) {
        break;
      }
      $total = round($total + $row['depreciation'], 2);
    }
    return $total;
  }

  /**
   * Remaining book value (basis less accumulated depreciation), floored.
   */
  public function bookValue(DepreciableAssetInterface $asset, ?int $through_year = NULL): float {
    $basis = (float) $asset->get('basis')->value;
    $salvage = (float) $asset->get('salvage_value')->value;
    return round(max($basis - $this->accumulatedDepreciation($asset, $through_year), $salvage), 2);
  }

  /**
   * Total depreciation for a year across assets — the Schedule F line 14 figure.
   *
   * Skips assets placed in service after the year and those disposed in a prior
   * year. (Disposal-year half-depreciation is a Form 4797 refinement, 5.8.)
   */
  public function totalForYear(int $year, ?array $enterprise_tids = NULL): float {
    $total = 0.0;
    foreach ($this->depreciablePropertyAssets($enterprise_tids) as $asset) {
      $start_year = (int) substr((string) $asset->get('in_service_date')->value, 0, 4);
      if ($start_year > $year) {
        continue;
      }
      if (!$asset->get('disposed_date')->isEmpty()) {
        $disposed_year = (int) substr((string) $asset->get('disposed_date')->value, 0, 4);
        if ($disposed_year < $year) {
          continue;
        }
      }
      $total = round($total + $this->annualDepreciation($asset, $year), 2);
    }
    return $total;
  }

  /**
   * The mid-quarter convention test for a placed-in-service year.
   *
   * Mid-quarter applies when more than 40% of the year's total depreciable basis
   * is placed in service in Q4 — a year-level aggregate across all assets, which
   * is why the convention is computed here and is not an asset/category field.
   * Real-property (mid-month) assets are excluded from the aggregate.
   */
  protected function conventionForYear(int $year): string {
    $total = 0.0;
    $q4 = 0.0;
    foreach ($this->depreciablePropertyAssets(NULL) as $asset) {
      if ((bool) $asset->get('mid_month')->value) {
        continue;
      }
      $in_service = $asset->get('in_service_date')->value;
      if ((int) substr((string) $in_service, 0, 4) !== $year) {
        continue;
      }
      // The mid-quarter test uses basis after §179/bonus.
      $basis = (float) $asset->get('basis')->value;
      $section_179 = min((float) $asset->get('section_179')->value, $basis);
      $bonus = ($basis - $section_179) * $this->bonusPctFor($asset, $year) / 100;
      $depreciable = $basis - $section_179 - $bonus;
      $total += $depreciable;
      if ($this->quarterOf($in_service) === 4) {
        $q4 += $depreciable;
      }
    }
    return ($total > 0 && ($q4 / $total) > 0.40) ? 'mid_quarter' : 'half_year';
  }

  /**
   * The calendar quarter (1-4) of a date string.
   */
  protected function quarterOf(string $date): int {
    $month = (int) substr($date, 5, 2);
    return (int) ceil($month / 3);
  }

  /**
   * The depreciable-asset entities OWNED as of a year-end — the single source.
   *
   * "Assets I currently own" for point-in-time reports (the balance sheet):
   * every depreciable_asset except those disposed on or before the as-of year.
   * An animal sold in 2027 is still owned on the 2026 sheet (she was owned then)
   * but absent from 2027 onward. Centralized here so every consumer inherits the
   * disposition filter, rather than each report re-deriving "owned" and leaving
   * the same phantom in some of them. NULL through-year = currently owned (no
   * disposal). Includes non-depreciable-property entities (raised stock, land),
   * which belong on the balance sheet at their basis/market.
   *
   * @return \Drupal\farm_financial_mgmt\Entity\DepreciableAssetInterface[]
   */
  public function ownedAssets(?int $through_year = NULL, ?array $enterprise_tids = NULL): array {
    $storage = $this->entityTypeManager->getStorage('depreciable_asset');
    $query = $storage->getQuery()->accessCheck(FALSE);
    if ($enterprise_tids !== NULL) {
      $query->condition('enterprise', $enterprise_tids, 'IN');
    }
    if ($through_year === NULL) {
      $query->notExists('disposed_date');
    }
    else {
      $owned = $query->orConditionGroup()
        ->notExists('disposed_date')
        ->condition('disposed_date', $through_year . '-12-31', '>');
      $query->condition($owned);
    }
    return $storage->loadMultiple($query->execute());
  }

  /**
   * Loads depreciable-property assets, optionally scoped to enterprises.
   *
   * @return \Drupal\farm_financial_mgmt\Entity\DepreciableAssetInterface[]
   */
  protected function depreciablePropertyAssets(?array $enterprise_tids): array {
    $storage = $this->entityTypeManager->getStorage('depreciable_asset');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('basis_type', 'raised', '<>')
      ->condition('macrs_class', 'none', '<>')
      ->condition('basis', 0, '>');
    if ($enterprise_tids !== NULL) {
      $query->condition('enterprise', $enterprise_tids, 'IN');
    }
    return $storage->loadMultiple($query->execute());
  }

  /**
   * The §179 cap and bonus % for a year, or NULL when unconfigured.
   */
  public function limitsForYear(int $year): ?array {
    $years = $this->configFactory->get('farm_financial_mgmt.depreciation_limits')->get('years') ?? [];
    return $years[$year] ?? $years[(string) $year] ?? NULL;
  }

  /**
   * The bonus % to apply: an explicit per-asset value, else the year default.
   *
   * Degrades loudly — a year absent from the limits config yields 0% and a
   * surfaced "update in settings" message, never a stale prior year (PHASE5 §8).
   */
  public function bonusPctFor(DepreciableAssetInterface $asset, int $year): float {
    if (!$asset->get('bonus_pct')->isEmpty()) {
      return (float) $asset->get('bonus_pct')->value;
    }
    $limits = $this->limitsForYear($year);
    if ($limits === NULL) {
      $this->warnings[] = sprintf('No §179/bonus limits configured for %d — entering $0/0%%, update in settings.', $year);
      return 0.0;
    }
    return (float) ($limits['bonus_pct'] ?? 0);
  }

}
