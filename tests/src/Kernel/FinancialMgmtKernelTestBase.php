<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_financial_mgmt\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Base class for farm_financial_mgmt kernel tests.
 *
 * Installs the module's four content entities and seeds the depreciation limits
 * config (2023–2025; 2099 deliberately absent, to exercise loud degradation).
 */
#[Group('farm')]
#[RunTestsInSeparateProcesses]
abstract class FinancialMgmtKernelTestBase extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'datetime',
    'file',
    'taxonomy',
    'views',
    'entity',
    'asset',
    'farm_unit',
    'inline_entity_form',
    'farm_financial_mgmt',
  ];

  /**
   * The depreciation engine.
   *
   * @var \Drupal\farm_financial_mgmt\Service\DepreciationEngine
   */
  protected $engine;

  /**
   * The depreciable_asset storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $assetStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpCurrentUser([], [], TRUE);
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('asset');
    $this->installEntitySchema('financial_transaction');
    $this->installEntitySchema('financial_line');
    $this->installEntitySchema('depreciable_asset');
    $this->installEntitySchema('financial_liability');

    // Established §179/bonus years only; 2099 absent (degradation path).
    \Drupal::configFactory()->getEditable('farm_financial_mgmt.depreciation_limits')
      ->setData([
        'years' => [
          2023 => ['section_179_cap' => 1160000, 'bonus_pct' => 80],
          2024 => ['section_179_cap' => 1220000, 'bonus_pct' => 60],
          2025 => ['section_179_cap' => 1250000, 'bonus_pct' => 40],
        ],
      ])->save();

    $this->engine = \Drupal::service('farm_financial_mgmt.depreciation_engine');
    $this->assetStorage = \Drupal::entityTypeManager()->getStorage('depreciable_asset');
  }

  /**
   * Creates a depreciable_asset with sensible defaults.
   */
  protected function createAsset(array $values) {
    $values += ['basis_type' => 'purchased', 'label' => 'Test asset'];
    $asset = $this->assetStorage->create($values);
    $asset->save();
    return $asset;
  }

  /**
   * Ensures the financial_category vocabulary exists.
   */
  protected function ensureCategoryVocabulary(): void {
    if (!Vocabulary::load('financial_category')) {
      Vocabulary::create(['vid' => 'financial_category', 'name' => 'Financial category'])->save();
    }
  }

  /**
   * Creates an income transaction returning $proceeds (for disposal_txn).
   *
   * The totalizer computes the transaction total from the single line amount.
   */
  protected function sellFor(float $proceeds, string $date = '2027-06-01') {
    $this->ensureCategoryVocabulary();
    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->create(['vid' => 'financial_category', 'name' => 'Sale']);
    $term->save();
    $line = \Drupal::entityTypeManager()->getStorage('financial_line')
      ->create(['category' => $term->id(), 'amount' => $proceeds]);
    $line->save();
    $txn = \Drupal::entityTypeManager()->getStorage('financial_transaction')
      ->create(['label' => 'Sale', 'direction' => 'income', 'date' => $date, 'lines' => [$line->id()]]);
    $txn->save();
    return $txn;
  }

}
