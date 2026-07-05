<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_financial_mgmt\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Golden-value and degradation tests for the depreciation engine.
 *
 * Two kinds of assertion live here, deliberately: golden-value tests where an
 * external authority (the IRS Pub 946 percentage tables) is the spec and the
 * hardcoded constant detects drift away from it; and degradation tests where the
 * spec is that the engine REFUSES or degrades loudly rather than computing wrong.
 */
#[Group('farm')]
#[RunTestsInSeparateProcesses]
class DepreciationEngineTest extends FinancialMgmtKernelTestBase {

  /**
   * MACRS half-year schedules match Pub 946 to the cent (golden constants).
   */
  public function testHalfYearGoldenValues(): void {
    // 7-year, 200% DB, half-year, $10,000 (Table A-1; year-1 14.29% anchor).
    $m7 = $this->createAsset(['basis' => 10000, 'macrs_class' => '7yr', 'depreciation_method' => 'macrs_gds_200', 'in_service_date' => '2024-06-01', 'bonus_pct' => 0]);
    $this->assertSame(
      [1429.0, 2449.0, 1749.0, 1249.0, 893.0, 892.0, 893.0, 446.0],
      array_values(array_map(static fn($r) => $r['depreciation'], $this->engine->schedule($m7))),
      '7-year 200% DB half-year matches Pub 946.',
    );

    // 5-year, 150% DB, half-year, $2,500 (purchased breeding cow).
    $b5 = $this->createAsset(['basis' => 2500, 'macrs_class' => '5yr', 'depreciation_method' => 'macrs_gds_150', 'in_service_date' => '2024-06-01', 'bonus_pct' => 0]);
    $this->assertSame(
      [375.0, 637.5, 446.25, 416.5, 416.5, 208.25],
      array_values(array_map(static fn($r) => $r['depreciation'], $this->engine->schedule($b5))),
      '5-year 150% DB half-year matches Pub 946.',
    );

    // 15-year, 150% DB, half-year, $30,000 land improvement: year 1 and total.
    $l15 = $this->createAsset(['basis' => 30000, 'macrs_class' => '15yr', 'depreciation_method' => 'macrs_gds_150', 'in_service_date' => '2024-06-01', 'bonus_pct' => 0]);
    $sched = $this->engine->schedule($l15);
    $this->assertSame(1500.0, $sched[2024]['depreciation'], '15-year year-1 is 5% = 1500.');
    $this->assertSame(30000.0, round(array_sum(array_column($sched, 'depreciation')), 2), '15-year schedule exhausts basis.');
  }

  /**
   * The year-level mid-quarter test flips both assets to mid-quarter tables.
   */
  public function testMidQuarterGoldenValues(): void {
    // Two 2023 assets; Q4 = 50% of basis (> 40%) trips mid-quarter.
    $q1 = $this->createAsset(['basis' => 10000, 'macrs_class' => '7yr', 'depreciation_method' => 'macrs_gds_200', 'in_service_date' => '2023-02-01', 'bonus_pct' => 0]);
    $q4 = $this->createAsset(['basis' => 10000, 'macrs_class' => '7yr', 'depreciation_method' => 'macrs_gds_200', 'in_service_date' => '2023-11-01', 'bonus_pct' => 0]);
    $this->assertSame(2500.0, $this->engine->schedule($q1)[2023]['depreciation'], 'Q1 7-year mid-quarter year-1 = 25%.');
    $this->assertSame(357.0, $this->engine->schedule($q4)[2023]['depreciation'], 'Q4 7-year mid-quarter year-1 = 3.57%.');
  }

  /**
   * Section 179 then bonus reduce basis before MACRS; both booked year one.
   */
  public function testSection179AndBonus(): void {
    // $10,000, 7-year; $5,000 §179; 40% bonus on the $5,000 remaining = $2,000;
    // MACRS basis $3,000. Year 1 = 3000*.1429 + 5000 + 2000 = 7428.70.
    $asset = $this->createAsset(['basis' => 10000, 'macrs_class' => '7yr', 'depreciation_method' => 'macrs_gds_200', 'in_service_date' => '2024-06-01', 'section_179' => 5000, 'bonus_pct' => 40]);
    $sched = $this->engine->schedule($asset);
    $this->assertSame(7428.7, $sched[2024]['depreciation'], 'Year 1 carries §179 + bonus + MACRS.');
    $this->assertSame(734.7, $sched[2025]['depreciation'], 'Year 2 is MACRS on the reduced basis (3000*.2449).');
  }

  /**
   * DEGRADATION: an unconfigured §179/bonus year yields 0% + a surfaced warning,
   * never a silent stale prior year.
   */
  public function testUnpopulatedYearDegradesLoudly(): void {
    $this->engine->resetWarnings();
    // 2099 is absent from the limits config; bonus_pct left unset.
    $asset = $this->createAsset(['basis' => 10000, 'macrs_class' => '7yr', 'depreciation_method' => 'macrs_gds_200', 'in_service_date' => '2099-05-01']);
    $sched = $this->engine->schedule($asset);
    // Pure MACRS with 0% bonus: year 1 = 14.29%.
    $this->assertSame(1429.0, $sched[2099]['depreciation'], 'Bonus degraded to 0% (no silent stale year).');
    $warnings = $this->engine->getWarnings();
    $this->assertNotEmpty($warnings, 'A degradation warning is surfaced.');
    $this->assertStringContainsString('2099', $warnings[0]);
    $this->assertStringContainsString('update in settings', $warnings[0]);
  }

  /**
   * DEGRADATION: a mid-quarter year on a 150% DB asset REFUSES (throws) rather
   * than computing with an untranscribed table.
   */
  public function test150PercentMidQuarterThrows(): void {
    // A single 15-year 150% asset placed in Q4 -> 100% Q4 -> mid-quarter.
    $asset = $this->createAsset(['basis' => 30000, 'macrs_class' => '15yr', 'depreciation_method' => 'macrs_gds_150', 'in_service_date' => '2025-11-01', 'bonus_pct' => 0]);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Mid-quarter tables are transcribed for 200% DB GDS only');
    $this->engine->schedule($asset);
  }

  /**
   * Raised (zero-basis) stock is non-depreciable — an empty schedule, not error.
   */
  public function testRaisedIsNonDepreciable(): void {
    $raised = $this->createAsset(['basis_type' => 'raised', 'basis' => 0, 'macrs_class' => '5yr', 'in_service_date' => '2024-06-01']);
    $this->assertSame([], $this->engine->schedule($raised), 'Raised stock has no schedule.');
    $this->assertSame(0.0, $this->engine->accumulatedDepreciation($raised), 'Raised stock has zero accumulated depreciation.');
  }

}
