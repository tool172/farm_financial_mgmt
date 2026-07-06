<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_financial_mgmt\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\farm_financial_mgmt\Form\DepreciationLimitsForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The §179/bonus config UI persists a year, and the engine then consumes it.
 *
 * Closes the loop the degradation test only half-covered: the engine correctly
 * REFUSES to guess an unconfigured year, and this proves the operator has an
 * in-app path to supply the answer — after which the engine uses it rather than
 * degrading. Persist-then-consume, asserted end to end.
 */
#[Group('farm')]
#[RunTestsInSeparateProcesses]
class DepreciationLimitsFormTest extends FinancialMgmtKernelTestBase {

  /**
   * Persisting 2031 limits via the form makes the engine stop degrading.
   */
  public function testConfigPersistsAndEngineConsumes(): void {
    $common = ['basis' => 10000, 'macrs_class' => '7yr', 'depreciation_method' => 'macrs_gds_200'];

    // Before: 2031 is unconfigured -> loud degradation, bonus 0 (yr1 = 14.29%).
    $this->engine->resetWarnings();
    $before = $this->createAsset($common + ['in_service_date' => '2031-06-01', 'label' => 'Before']);
    $this->assertSame(1429.0, $this->engine->schedule($before)[2031]['depreciation']);
    $this->assertNotEmpty($this->engine->getWarnings(), 'Unconfigured 2031 degrades before the form is used.');

    // Operator supplies 2031 via the config UI (submit the actual form).
    $form_state = new FormState();
    $form_state->setValues(['years' => [2031 => ['section_179_cap' => 500000, 'bonus_pct' => 25]]]);
    \Drupal::formBuilder()->submitForm(DepreciationLimitsForm::class, $form_state);

    // The UI persisted the year.
    $years = $this->config('farm_financial_mgmt.depreciation_limits')->get('years');
    $this->assertSame(25, $years[2031]['bonus_pct'], 'Form persisted the bonus %.');
    $this->assertSame(500000, $years[2031]['section_179_cap'], 'Form persisted the §179 cap.');
    // Seeded years survive the edit.
    $this->assertSame(40, $years[2025]['bonus_pct'], 'Existing years are preserved.');

    // After: the engine consumes it — 25% bonus, no degradation warning.
    // bonus = 10000*0.25 = 2500; MACRS basis 7500; yr1 = 7500*.1429 + 2500.
    $this->engine->resetWarnings();
    $after = $this->createAsset($common + ['in_service_date' => '2031-06-01', 'label' => 'After']);
    $this->assertSame(3571.75, $this->engine->schedule($after)[2031]['depreciation'], 'Engine consumes the persisted bonus.');
    $this->assertSame([], $this->engine->getWarnings(), 'No degradation once the year is configured.');
  }

  /**
   * Reads the config with a helper (kernel tests lack the config() trait).
   */
  protected function config($name) {
    return \Drupal::config($name);
  }

}
