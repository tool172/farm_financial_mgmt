<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\EntityViewsData;

/**
 * Defines the financial_line content entity.
 *
 * Independently revisionable (Commerce order-item pattern). Amount is computed
 * as quantity × unit_price on presave when both are set (see FinancialLineHooks);
 * txn_date/reporting_year/direction are denormalized from the parent transaction
 * on the transaction's postsave (see TransactionTotalizer).
 */
#[ContentEntityType(
  id: 'financial_line',
  label: new TranslatableMarkup('Financial line'),
  label_collection: new TranslatableMarkup('Financial lines'),
  label_singular: new TranslatableMarkup('financial line'),
  label_plural: new TranslatableMarkup('financial lines'),
  label_count: [
    'singular' => '@count financial line',
    'plural' => '@count financial lines',
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
  base_table: 'financial_line',
  revision_table: 'financial_line_revision',
  admin_permission: 'administer financial mgmt',
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
  ],
  revision_metadata_keys: [
    'revision_default' => 'revision_default',
    'revision_created' => 'revision_created',
    'revision_user' => 'revision_user',
    'revision_log_message' => 'revision_log_message',
  ],
  links: [
    'canonical' => '/financial/line/{financial_line}',
    'edit-form' => '/financial/line/{financial_line}/edit',
    'delete-form' => '/financial/line/{financial_line}/delete',
    'collection' => '/admin/content/financial-line',
  ],
)]
class FinancialLine extends RevisionableContentEntityBase implements FinancialLineInterface {

  /**
   * {@inheritdoc}
   */
  public function label() {
    $category = $this->get('category')->entity;
    $amount = $this->get('amount')->value;
    if ($category !== NULL && $amount !== NULL) {
      return $category->label() . ' — ' . $amount;
    }
    if ($category !== NULL) {
      return $category->label();
    }
    return (string) ($this->get('memo')->value ?? $this->t('Line @id', ['@id' => $this->id() ?? '']));
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Parent back-reference (Commerce order-item → order pattern). Populated by
    // the transaction's postsave write-through (TransactionTotalizer), so not
    // required at the field level.
    $fields['transaction'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Transaction'))
      ->setSetting('target_type', 'financial_transaction')
      ->setRevisionable(TRUE);

    // Per-line category — required; makes split transactions work.
    $fields['category'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Category'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['financial_category' => 'financial_category']])
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Money. Computed as quantity × unit_price when both set, else entered.
    $fields['amount'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Amount'))
      ->setSetting('precision', 14)
      ->setSetting('scale', 2)
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['quantity'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Quantity'))
      ->setDescription(new TranslatableMarkup('e.g. head count or weight sold.'))
      ->setSetting('precision', 14)
      ->setSetting('scale', 4)
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['unit_price'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Unit price'))
      ->setDescription(new TranslatableMarkup('e.g. $/head or $/cwt.'))
      ->setSetting('precision', 14)
      ->setSetting('scale', 4)
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // farmOS unit vocabulary reference (head, cwt, lb…).
    $fields['unit'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Unit'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['unit' => 'unit']])
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Per-line asset attribution (any bundle, multi). Empty = farm-wide, which
    // feeds the AUE allocation pool (SPEC §3.2, §7).
    $fields['asset'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Asset'))
      ->setSetting('target_type', 'asset')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['memo'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Memo'))
      ->setSetting('max_length', 255)
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Optional enterprise (species) scope for an UNATTRIBUTED allocatable
    // expense: tag a cattle feed bill "Cattle" so its pool allocates only to
    // cattle, not proportionally across every species (SPEC §7 refinement).
    // Empty = farm-wide shared cost, split across all animals by AUE.
    $fields['enterprise'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Enterprise'))
      ->setDescription(new TranslatableMarkup('Optional: the species this shared expense belongs to. Scopes the allocatable pool.'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['animal_type' => 'animal_type']])
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Denormalized from the parent transaction (write-through on txn postsave).
    // Load-bearing reporting optimization (SPEC §3.2): lets P&L / by-category /
    // per-record reports query the single financial_line table with no join.
    $fields['txn_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Transaction date'))
      ->setSetting('datetime_type', 'date')
      ->setRevisionable(TRUE);

    $fields['reporting_year'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Reporting year'))
      ->setRevisionable(TRUE);

    $fields['direction'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Direction'))
      ->setSetting('allowed_values', ['income' => 'Income', 'expense' => 'Expense'])
      ->setRevisionable(TRUE);

    return $fields;
  }

}
