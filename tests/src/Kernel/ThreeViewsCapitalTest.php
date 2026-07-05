<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_financial_mgmt\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The three profit views agree on capital treatment — a relationship test.
 *
 * The drive found that the managerial P&L counted capital purchases as operating
 * expense while the Tax Summary and Enterprise P&L excluded them. This pins the
 * fix as a live equality: all three views derive depreciation from ONE engine
 * call and all three exclude the capital outlay from operating expense. Asserted
 * as equalities between the three builders, not against memorized figures.
 */
#[Group('farm')]
#[RunTestsInSeparateProcesses]
class ThreeViewsCapitalTest extends FinancialMgmtKernelTestBase {

  /**
   * All three views recognise capital as depreciation, not as operating outlay.
   */
  public function testThreeViewsAgreeOnCapital(): void {
    $this->installCategoryFields();
    $capital_cat = $this->createCategory('Equipment Purchase', ['capital' => TRUE, 'tax_form' => 'none']);
    $operating_cat = $this->createCategory('Utilities', ['capital' => FALSE, 'allocatable' => FALSE, 'tax_form' => 'schedule_f', 'schedule_f_line' => '30']);

    // A $10,000 capital purchase + its depreciable asset (7-yr, 2027 -> 1429),
    // and a $900 operating expense.
    $this->spend($capital_cat, 10000, '2027-02-10');
    $this->spend($operating_cat, 900, '2027-01-20');
    $this->createAsset(['basis' => 10000, 'macrs_class' => '7yr', 'depreciation_method' => 'macrs_gds_200', 'in_service_date' => '2027-02-10', 'bonus_pct' => 0]);

    $filters = ['year' => 2027];
    $rb = \Drupal::service('farm_financial_mgmt.report_builder');

    // The one depreciation source.
    $engine_depr = $this->engine->totalForYear(2027);

    // Managerial P&L: operating expense (capital excluded) + depreciation.
    $managerial_operating = $rb->totalOperatingExpense($filters);

    // Tax Summary: line 14 arrives via the alter hook from the same engine.
    $tax = \Drupal::service('farm_financial_mgmt.tax_summary_builder')->build($filters);

    // Enterprise P&L: overhead pool takes depreciation, not the outlay.
    $enterprise = \Drupal::service('farm_financial_mgmt.enterprise_cost_allocator')->build(2027);

    // Depreciation is ONE figure across all three views (live equality).
    $this->assertSame($engine_depr, $tax['depreciation']['line_14'], 'Tax line 14 == engine depreciation.');
    $this->assertSame($engine_depr, $enterprise['depreciation'], 'Enterprise depreciation == engine depreciation.');

    // The $10,000 capital outlay is in NONE of the three operating figures.
    $this->assertSame(900.0, $managerial_operating, 'Managerial operating excludes the capital outlay.');
    $this->assertSame(900.0, $enterprise['total_operating_expense'], 'Enterprise operating excludes the capital outlay.');
    // Tax: the capital purchase is bucketed as capital, not a Schedule F expense.
    $this->assertNotEmpty($tax['capital'], 'The capital purchase is in the capital bucket.');
    // Sch F expense = the $900 operating + the $1429 depreciation line 14.
    $this->assertSame(round(900 + $engine_depr, 2), round($tax['schedule_f']['expense_total'], 2), 'Sch F expense = operating + depreciation, no capital outlay.');
  }

  /**
   * Installs the capital/allocatable/tax_form/schedule_f_line category fields.
   */
  protected function installCategoryFields(): void {
    $this->ensureCategoryVocabulary();
    $fields = [
      'capital' => 'boolean',
      'allocatable' => 'boolean',
      'schedule_f_line' => 'string',
      'tax_form' => 'list_string',
    ];
    foreach ($fields as $name => $type) {
      $settings = $type === 'list_string'
        ? ['allowed_values' => ['schedule_f' => 'Schedule F', 'form_4797' => 'Form 4797', 'form_4835' => 'Form 4835', 'schedule_e' => 'Schedule E', 'none' => 'None']]
        : [];
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'taxonomy_term', 'type' => $type, 'settings' => $settings])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'taxonomy_term', 'bundle' => 'financial_category'])->save();
    }

    // The enterprise allocator queries the asset animal_type field (from
    // farm_animal, absent here). Install a minimal storage so the query resolves
    // — with no animals, the allocator returns no enterprises but still computes
    // the capital-relevant pools (depreciation, operating expense).
    if (!Vocabulary::load('animal_type')) {
      Vocabulary::create(['vid' => 'animal_type', 'name' => 'Animal type'])->save();
    }
    if (!FieldStorageConfig::loadByName('asset', 'animal_type')) {
      FieldStorageConfig::create([
        'field_name' => 'animal_type',
        'entity_type' => 'asset',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'taxonomy_term'],
      ])->save();
    }
  }

  /**
   * Creates a financial_category term with tax/allocation fields.
   */
  protected function createCategory(string $name, array $fields) {
    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->create(['vid' => 'financial_category', 'name' => $name] + $fields);
    $term->save();
    return $term;
  }

  /**
   * Creates an expense transaction with a single categorised line.
   */
  protected function spend($category, float $amount, string $date): void {
    $line = \Drupal::entityTypeManager()->getStorage('financial_line')
      ->create(['category' => $category->id(), 'amount' => $amount]);
    $line->save();
    $txn = \Drupal::entityTypeManager()->getStorage('financial_transaction')
      ->create(['label' => 'Spend', 'direction' => 'expense', 'date' => $date, 'lines' => [$line->id()]]);
    $txn->save();
  }

}
