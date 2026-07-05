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
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Defines the financial_liability content entity (Phase 5.4).
 *
 * A loan/note the operation owes. Manually maintained. Its current balance is
 * NOT stored here — it is derived on demand as original_principal − the sum of
 * principal_portion across the liability's payment lines
 * (ReportBuilder::liabilityBalance()), so it can never drift from the payment
 * history. Same single-source discipline as accumulated depreciation: one
 * derivation, the balance sheet (5.6) reads it.
 */
#[ContentEntityType(
  id: 'financial_liability',
  label: new TranslatableMarkup('Liability'),
  label_collection: new TranslatableMarkup('Liabilities'),
  label_singular: new TranslatableMarkup('liability'),
  label_plural: new TranslatableMarkup('liabilities'),
  label_count: [
    'singular' => '@count liability',
    'plural' => '@count liabilities',
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
  base_table: 'financial_liability',
  revision_table: 'financial_liability_revision',
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
    'canonical' => '/financial/liability/{financial_liability}',
    'add-form' => '/financial/liability/add',
    'edit-form' => '/financial/liability/{financial_liability}/edit',
    'delete-form' => '/financial/liability/{financial_liability}/delete',
    'collection' => '/admin/content/financial-liability',
  ],
)]
class FinancialLiability extends RevisionableContentEntityBase implements FinancialLiabilityInterface {

  use EntityOwnerTrait;
  use EntityChangedTrait;

  /**
   * Liability types.
   */
  public const TYPES = [
    'operating_note' => 'Operating note / line of credit',
    'term_loan' => 'Term loan',
    'mortgage' => 'Mortgage / real estate',
    'ccc_loan' => 'CCC loan',
    'other' => 'Other',
  ];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setDescription(new TranslatableMarkup('A label for this loan, e.g. "Land mortgage — First National".'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['lender'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Lender'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['financial_contact' => 'financial_contact']])
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['liability_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Type'))
      ->setSetting('allowed_values', self::TYPES)
      ->setDefaultValue('term_loan')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['original_principal'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Original principal'))
      ->setDescription(new TranslatableMarkup('The opening balance. The current balance is derived from this less principal paid.'))
      ->setSetting('precision', 14)
      ->setSetting('scale', 2)
      ->setDefaultValue(0)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['interest_rate'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Interest rate (%)'))
      ->setSetting('precision', 6)
      ->setSetting('scale', 3)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['origination_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Origination date'))
      ->setSetting('datetime_type', 'date')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 4])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['term_months'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Term (months)'))
      ->setSetting('min', 0)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['enterprise'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Enterprise'))
      ->setDescription(new TranslatableMarkup('Optional: the species enterprise this loan belongs to.'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['animal_type' => 'animal_type']])
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['notes'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 7])
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
