<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_financial_mgmt\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The raised-vs-purchased fork on the disposition side (Form 4797 recapture).
 *
 * The fork touched every phase; its correctness rides entirely on basis_type
 * routing to one field and recapture reading the one accumulated-depreciation
 * source. This is the test that fails loudly if a parallel raised/purchased
 * derivation is ever reintroduced.
 */
#[Group('farm')]
#[RunTestsInSeparateProcesses]
class Form4797RecaptureTest extends FinancialMgmtKernelTestBase {

  /**
   * Purchased recaptures up to accumulated then §1231; raised is all §1231.
   */
  public function testRaisedVersusPurchasedFork(): void {
    $builder = \Drupal::service('farm_financial_mgmt.form_4797_builder');
    $price = 3000.0;

    // Purchased: basis 2500, 5-yr 200% DB, in service 2024, sold 2026.
    $purchased = $this->createAsset([
      'basis' => 2500, 'macrs_class' => '5yr', 'depreciation_method' => 'macrs_gds_200',
      'in_service_date' => '2024-06-01', 'bonus_pct' => 0,
      'disposed_date' => '2026-08-01', 'disposal_txn' => $this->sellFor($price)->id(),
    ]);
    $tp = $builder->treatment($purchased);
    $this->assertSame(1780.0, $tp['accumulated_depreciation'], 'Purchased: accumulated = 500+800+480.');
    $this->assertSame(1780.0, $tp['ordinary_recapture'], 'Purchased: recapture up to accumulated depreciation.');
    $this->assertSame(500.0, $tp['section_1231_gain'], 'Purchased: gain beyond original basis is §1231.');

    // Raised: zero basis, zero depreciation, no recapture — the fork's point.
    $raised = $this->createAsset([
      'basis_type' => 'raised', 'basis' => 0, 'macrs_class' => '5yr',
      'in_service_date' => '2024-06-01',
      'disposed_date' => '2026-08-01', 'disposal_txn' => $this->sellFor($price)->id(),
    ]);
    $tr = $builder->treatment($raised);
    $this->assertSame(0.0, $tr['accumulated_depreciation'], 'Raised: nothing depreciated.');
    $this->assertSame(0.0, $tr['ordinary_recapture'], 'Raised: nothing to recapture.');
    $this->assertSame(3000.0, $tr['section_1231_gain'], 'Raised: whole gain is §1231.');
  }

  /**
   * acquired_other recaptures against its RECORDED basis, not zero.
   */
  public function testAcquiredOtherUsesRecordedBasis(): void {
    // Inherited: basis 1800, 5-yr 200% DB, in service 2024, sold 2026 for 3000.
    $inherited = $this->createAsset([
      'basis_type' => 'acquired_other', 'basis' => 1800, 'macrs_class' => '5yr',
      'depreciation_method' => 'macrs_gds_200', 'in_service_date' => '2024-06-01', 'bonus_pct' => 0,
      'disposed_date' => '2026-08-01', 'disposal_txn' => $this->sellFor(3000.0)->id(),
    ]);
    $t = \Drupal::service('farm_financial_mgmt.form_4797_builder')->treatment($inherited);
    $this->assertSame(1281.6, $t['accumulated_depreciation'], 'Recaptures against 1800 basis (360+576+345.60).');
    $this->assertSame(1281.6, $t['ordinary_recapture']);
    $this->assertSame(1200.0, $t['section_1231_gain'], '§1231 = 3000 − 1800 recorded basis.');
  }

}
