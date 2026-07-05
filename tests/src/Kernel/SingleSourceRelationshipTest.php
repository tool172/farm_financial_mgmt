<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_financial_mgmt\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Relationship tests — the invariants boundary tests structurally cannot catch.
 *
 * These pin an equality BETWEEN code paths as a live identity, not a reproduced
 * constant: if both paths drifted together to a new shared-but-wrong value, a
 * golden-value test would still pass, but these fail. The specific numbers are
 * incidental — the equality is the spec.
 */
#[Group('farm')]
#[RunTestsInSeparateProcesses]
class SingleSourceRelationshipTest extends FinancialMgmtKernelTestBase {

  /**
   * The 4797 recapture and the balance-sheet basis column read ONE accumulated
   * value — asserted as an identity, not against a memorized number.
   */
  public function testRecaptureAndBalanceSheetShareOneAccumulated(): void {
    $asset = $this->createAsset([
      'basis' => 2500, 'macrs_class' => '5yr', 'depreciation_method' => 'macrs_gds_200',
      'in_service_date' => '2024-06-01', 'bonus_pct' => 0,
      'disposed_date' => '2026-08-01', 'disposal_txn' => $this->sellFor(3000.0)->id(),
    ]);

    $recapture_accumulated = \Drupal::service('farm_financial_mgmt.form_4797_builder')
      ->treatment($asset)['accumulated_depreciation'];
    // The balance-sheet basis column is the valuation provider's book value.
    $balance_sheet_book = \Drupal::service('farm_financial_mgmt.asset_valuation')
      ->value($asset, 2026)['basis'];

    $this->assertSame(
      round((float) $asset->get('basis')->value - $recapture_accumulated, 2),
      $balance_sheet_book,
      'balanceSheetBook == originalBasis − 4797Accumulated: both trace to accumulatedDepreciation().',
    );
  }

  /**
   * ownedAssets() as of a year excludes assets disposed on/before it, keeps
   * those disposed later — the single "owned as-of" source the balance sheet
   * inherits, so a sold animal is not double-counted.
   */
  public function testOwnedAsOfExcludesDisposed(): void {
    $common = ['basis' => 1000, 'macrs_class' => '7yr', 'depreciation_method' => 'macrs_gds_200', 'in_service_date' => '2024-06-01'];
    $this->createAsset($common + ['label' => 'Owned']);
    $this->createAsset($common + ['label' => 'Sold 2025', 'disposed_date' => '2025-05-01', 'disposal_txn' => $this->sellFor(1000.0)->id()]);
    $this->createAsset($common + ['label' => 'Sold 2027', 'disposed_date' => '2027-05-01', 'disposal_txn' => $this->sellFor(1000.0)->id()]);

    $labels = array_map(static fn($a) => $a->label(), $this->engine->ownedAssets(2026));
    $this->assertContains('Owned', $labels);
    $this->assertContains('Sold 2027', $labels, 'Disposed after the as-of year is still owned.');
    $this->assertNotContains('Sold 2025', $labels, 'Disposed before the as-of year is off the books.');
  }

}
