<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Defines the depreciable_asset content entity (Phase 5, the capital layer).
 *
 * A purpose-built content entity (Option C) — the foundation the whole of
 * Phase 5 stands on. It records the tax basis of a capital asset and the MACRS
 * class/method used to depreciate it. Two facts are load-bearing:
 *
 *  - `basis_type` (purchased | raised | acquired_other) is THE single source of
 *    truth for the raised-vs-purchased fork (PHASE5 §2.3). Raised breeding stock
 *    is non-depreciable with zero basis — a normal state, not an error — because
 *    its rearing costs were already expensed cash-basis. Both the depreciation
 *    schedule and (5.8) the Form 4797 recapture read this same field off the
 *    same entity, so they can never disagree about one animal.
 *  - `macrs_class` / `depreciation_method` are inherited from the acquisition
 *    line's capital category (the "tax intelligence on the category" pattern)
 *    but overridable per asset, so a taxpayer can elect 150% DB or ADS on one
 *    animal without reclassing it (PHASE5 §2.1).
 */
#[ContentEntityType(
  id: 'depreciable_asset',
  label: new TranslatableMarkup('Depreciable asset'),
  label_collection: new TranslatableMarkup('Depreciable assets'),
  label_singular: new TranslatableMarkup('depreciable asset'),
  label_plural: new TranslatableMarkup('depreciable assets'),
  label_count: [
    'singular' => '@count depreciable asset',
    'plural' => '@count depreciable assets',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'view_builder' => 'Drupal\Core\Entity\EntityViewBuilder',
    'list_builder' => 'Drupal\Core\Entity\EntityListBuilder',
    'views_data' => EntityViewsData::class,
    'access' => 'Drupal\farm_financial_mgmt\Access\FinancialAccessControlHandler',
    'form' => [
      'default' => ContentEntityForm::class,
      'add' => ContentEntityForm::class,
      'edit' => ContentEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  base_table: 'depreciable_asset',
  revision_table: 'depreciable_asset_revision',
  admin_permission: 'administer financial mgmt',
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
    'label' => 'label',
    'owner' => 'uid',
  ],
  revision_metadata_keys: [
    'revision_default' => 'revision_default',
    'revision_created' => 'revision_created',
    'revision_user' => 'revision_user',
    'revision_log_message' => 'revision_log_message',
  ],
  links: [
    'canonical' => '/financial/depreciable-asset/{depreciable_asset}',
    'add-form' => '/financial/depreciable-asset/add',
    'edit-form' => '/financial/depreciable-asset/{depreciable_asset}/edit',
    'delete-form' => '/financial/depreciable-asset/{depreciable_asset}/delete',
    'collection' => '/admin/content/depreciable-asset',
  ],
)]
class DepreciableAsset extends RevisionableContentEntityBase implements DepreciableAssetInterface {

  use EntityOwnerTrait;
  use EntityChangedTrait;

  /**
   * MACRS property classes (recovery periods). "none" = never depreciable.
   */
  public const MACRS_CLASSES = [
    '3yr' => '3-year',
    '5yr' => '5-year',
    '7yr' => '7-year',
    '10yr' => '10-year',
    '15yr' => '15-year',
    '20yr' => '20-year',
    'none' => 'None (not depreciable)',
  ];

  /**
   * Depreciation methods. GDS split into 200%/150% DB (PHASE5 §2.1).
   */
  public const METHODS = [
    'macrs_gds_200' => 'MACRS GDS — 200% declining balance',
    'macrs_gds_150' => 'MACRS GDS — 150% declining balance',
    'macrs_ads' => 'MACRS ADS — straight line',
    'straight_line' => 'Straight line (book)',
  ];

  /**
   * Basis provenance. Drives depreciability and how basis is determined.
   */
  public const BASIS_TYPES = [
    'purchased' => 'Purchased (cost basis, depreciates)',
    'raised' => 'Raised (zero basis, not depreciable)',
    'acquired_other' => 'Gifted / inherited / transferred (entered basis)',
  ];

  /**
   * {@inheritdoc}
   */
  public function isDepreciable(): bool {
    $basis_type = $this->get('basis_type')->value;
    $class = $this->get('macrs_class')->value;
    $basis = (float) $this->get('basis')->value;
    $disposed = !$this->get('disposed_date')->isEmpty();
    return $basis_type !== 'raised'
      && $basis > 0
      && $class !== NULL && $class !== 'none'
      && !$disposed;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // Raised stock is zero-basis by definition — enforce the invariant so the
    // engine's skip rule and the balance sheet can never see a raised animal
    // carrying a stray basis (PHASE5 §2.3).
    if ($this->get('basis_type')->value === 'raised') {
      $this->set('basis', 0);
    }

    // Inherit basis, in-service date, and MACRS class/method from the
    // acquisition transaction's capital line when not explicitly set — so
    // linking an acquisition makes the asset self-sufficient regardless of
    // entry path. Class/method live on the capital category (the "tax
    // intelligence on the category" pattern, PHASE5 §2.1) and remain
    // overridable per asset (the election path).
    if (!$this->get('acquisition')->isEmpty()) {
      $txn = $this->get('acquisition')->entity;
      if ($txn !== NULL) {
        // basis defaults to 0, so treat a non-positive basis as "inherit from
        // the acquisition" for purchased assets (a purchased asset has a cost).
        if ($this->get('basis_type')->value === 'purchased' && (float) $this->get('basis')->value <= 0) {
          $this->set('basis', $txn->get('total')->value);
        }
        if ($this->get('in_service_date')->isEmpty() && !$txn->get('date')->isEmpty()) {
          $this->set('in_service_date', $txn->get('date')->value);
        }
        if ($this->get('macrs_class')->isEmpty() || $this->get('depreciation_method')->isEmpty()) {
          $category = $this->capitalCategory($txn);
          if ($category !== NULL) {
            if ($this->get('macrs_class')->isEmpty() && !$category->get('macrs_class')->isEmpty()) {
              $this->set('macrs_class', $category->get('macrs_class')->value);
            }
            if ($this->get('depreciation_method')->isEmpty() && !$category->get('depreciation_method')->isEmpty()) {
              $this->set('depreciation_method', $category->get('depreciation_method')->value);
            }
          }
        }
      }
    }

    // Auto-label when left blank.
    if ($this->get('label')->isEmpty()) {
      $name = NULL;
      if (!$this->get('farm_asset')->isEmpty() && $this->get('farm_asset')->entity) {
        $name = $this->get('farm_asset')->entity->label();
      }
      $date = $this->get('in_service_date')->value ?: date('Y-m-d');
      $this->set('label', trim(($name ? $name . ' — ' : '') . $date));
    }
  }

  /**
   * Finds the capital-flagged category of a transaction's capital line.
   *
   * A transaction can hold several lines (e.g. a machine purchase plus freight);
   * the one that drives MACRS is the line whose category is capital-flagged.
   * Returns the first such category term, or NULL.
   */
  protected function capitalCategory($txn): ?object {
    foreach ($txn->get('lines')->referencedEntities() as $line) {
      if ($line->get('category')->isEmpty()) {
        continue;
      }
      $category = $line->get('category')->entity;
      if ($category !== NULL && $category->hasField('capital') && (bool) $category->get('capital')->value) {
        return $category;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('Auto-generated from the linked asset and in-service date if left blank.'))
      ->setSetting('max_length', 255)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // The raised/purchased fork — the single source of truth (PHASE5 §2.3).
    $fields['basis_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Basis type'))
      ->setDescription(new TranslatableMarkup('Purchased carries a cost basis and depreciates; raised is zero-basis and not depreciable; gifted/inherited/transferred takes an entered (stepped-up or carryover) basis. Defaults from whether an acquisition line is present — override for the acquired-otherwise cases.'))
      ->setSetting('allowed_values', self::BASIS_TYPES)
      ->setRequired(TRUE)
      ->setDefaultValue('purchased')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Optional physical link to the farmOS asset (animal/equipment/land).
    $fields['farm_asset'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Farm asset'))
      ->setSetting('target_type', 'asset')
      ->setSetting('handler', 'default')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Basis source for purchased assets (empty for raised / acquired_other).
    $fields['acquisition'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Acquisition transaction'))
      ->setDescription(new TranslatableMarkup('The capital purchase this basis comes from. Empty for raised or gifted/inherited stock.'))
      ->setSetting('target_type', 'financial_transaction')
      ->setSetting('handler', 'default')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['basis'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Basis'))
      ->setDescription(new TranslatableMarkup('Depreciable cost basis. Defaults to the acquisition total for purchased; 0 for raised; entered for gifted/inherited/transferred.'))
      ->setSetting('precision', 14)
      ->setSetting('scale', 2)
      ->setDefaultValue(0)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['in_service_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Placed in service'))
      ->setSetting('datetime_type', 'date')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 4])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Inherited from the acquisition category; overridable per asset (the
    // election path — override must reach BOTH class and method, PHASE5 §2.1).
    $fields['macrs_class'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('MACRS property class'))
      ->setSetting('allowed_values', self::MACRS_CLASSES)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['depreciation_method'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Depreciation method'))
      ->setSetting('allowed_values', self::METHODS)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Real-property convention override; the year-level mid-quarter test is
    // engine-computed, not a field (PHASE5 §2.1).
    $fields['mid_month'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Mid-month convention'))
      ->setDescription(new TranslatableMarkup('Real property only (structures / land improvements).'))
      ->setDefaultValue(FALSE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 7])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['salvage_value'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Salvage value'))
      ->setDescription(new TranslatableMarkup('Straight-line (book) method only.'))
      ->setSetting('precision', 14)
      ->setSetting('scale', 2)
      ->setDefaultValue(0)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 8])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['section_179'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Section 179 expensed'))
      ->setDescription(new TranslatableMarkup('Elected year-1 expensing; reduces basis before MACRS.'))
      ->setSetting('precision', 14)
      ->setSetting('scale', 2)
      ->setDefaultValue(0)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 9])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['bonus_pct'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Bonus depreciation %'))
      ->setDescription(new TranslatableMarkup('Special depreciation allowance %. Year-dependent; from the limits config (Phase 5.2).'))
      ->setSetting('min', 0)
      ->setSetting('max', 100)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['disposed_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Disposed date'))
      ->setDescription(new TranslatableMarkup('Stops depreciation; triggers Form 4797 (Phase 5.8).'))
      ->setSetting('datetime_type', 'date')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 11])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['disposal_txn'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Disposal transaction'))
      ->setSetting('target_type', 'financial_transaction')
      ->setSetting('handler', 'default')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 12])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['market_value'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Market value'))
      ->setDescription(new TranslatableMarkup('Optional entered managerial value (else provider-supplied, Phase 5.5).'))
      ->setSetting('precision', 14)
      ->setSetting('scale', 2)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 13])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // One enterprise concept across the module (animal_type species term).
    $fields['enterprise'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Enterprise'))
      ->setDescription(new TranslatableMarkup('Optional: the species enterprise this asset belongs to.'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['animal_type' => 'animal_type']])
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 14])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['notes'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 15])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setRevisionable(TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setRevisionable(TRUE);

    return $fields;
  }

}
