<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Defines the financial_transaction content entity (the payment envelope).
 *
 * Single bundle + a `direction` field (not two bundles). Independently
 * revisionable with a revision log, owner, and changed timestamp. `total` is
 * recomputed and stored from the child lines on save (TransactionTotalizer),
 * which also writes date/reporting_year/direction down onto each line.
 */
#[ContentEntityType(
  id: 'financial_transaction',
  label: new TranslatableMarkup('Financial transaction'),
  label_collection: new TranslatableMarkup('Financial transactions'),
  label_singular: new TranslatableMarkup('financial transaction'),
  label_plural: new TranslatableMarkup('financial transactions'),
  label_count: [
    'singular' => '@count financial transaction',
    'plural' => '@count financial transactions',
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
  base_table: 'financial_transaction',
  revision_table: 'financial_transaction_revision',
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
    'canonical' => '/financial/transaction/{financial_transaction}',
    'add-form' => '/financial/transaction/add',
    'edit-form' => '/financial/transaction/{financial_transaction}/edit',
    'delete-form' => '/financial/transaction/{financial_transaction}/delete',
    'collection' => '/admin/content/financial-transaction',
  ],
  constraints: [
    'HomogeneousDirection' => [],
  ],
)]
class FinancialTransaction extends RevisionableContentEntityBase implements FinancialTransactionInterface {

  use EntityOwnerTrait;
  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    // Auto-generated in presave ("{counterparty} — {date}") when left blank.
    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('Auto-generated from counterparty and date if left blank.'))
      ->setSetting('max_length', 255)
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Single bundle + this field. Enforced homogeneous with its lines (Task 1.7).
    $fields['direction'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Direction'))
      ->setSetting('allowed_values', ['income' => 'Income', 'expense' => 'Expense'])
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDefaultValue('expense')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // When money moved (cash-basis anchor).
    $fields['date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Date'))
      ->setSetting('datetime_type', 'date')
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Defaults to YEAR(date) in presave; overridable (prepaid crossing years).
    $fields['reporting_year'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Reporting year'))
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Counterparty: payee (expense) or payer (income).
    $fields['counterparty'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Counterparty'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['financial_contact' => 'financial_contact']])
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['payment_method'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Payment method'))
      ->setSetting('allowed_values', [
        'cash' => 'Cash',
        'check' => 'Check',
        'card' => 'Card',
        'ach' => 'ACH',
        'other' => 'Other',
      ])
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['reference'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Reference'))
      ->setDescription(new TranslatableMarkup('Check #, invoice #, confirmation #.'))
      ->setSetting('max_length', 255)
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['payment_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Payment status'))
      ->setSetting('allowed_values', [
        'pending' => 'Pending',
        'partial' => 'Partial',
        'paid' => 'Paid',
      ])
      ->setDefaultValue('paid')
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Receipt capture — images + PDFs.
    $fields['receipt'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('Receipt'))
      ->setSetting('file_extensions', 'png jpg jpeg gif pdf')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Recomputed + stored from line amounts on save (query speed).
    $fields['total'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Total'))
      ->setSetting('precision', 14)
      ->setSetting('scale', 2)
      ->setDefaultValue(0)
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['notes'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Child lines — the IEF-managed reference (Task 1.8). Plain entity_reference
    // (by id) so the postsave write-through can re-save lines without pinning
    // stale revisions.
    $fields['lines'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Lines'))
      ->setSetting('target_type', 'financial_line')
      ->setSetting('handler', 'default')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setRevisionable(TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setRevisionable(TRUE);

    return $fields;
  }

}
