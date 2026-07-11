<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\farm_financial_mgmt\Entity\DepreciableAsset;
use Drupal\farm_financial_mgmt\Service\DepreciationEngine;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for depreciable assets.
 *
 * Shows the tax-relevant facts at a glance — basis, MACRS class, in-service
 * date, and current book value — instead of the bare label + operations the
 * default list builder gives.
 */
class DepreciableAssetListBuilder extends EntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected DepreciationEngine $engine,
    protected string $currency,
    protected int $currentYear,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('farm_financial_mgmt.depreciation_engine'),
      $container->get('config.factory')->get('farm_financial_mgmt.settings')->get('currency') ?: 'USD',
      (int) date('Y', $container->get('datetime.time')->getRequestTime()),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Asset'),
      'basis_type' => $this->t('Basis type'),
      'basis' => $this->t('Basis'),
      'class' => $this->t('MACRS class'),
      'in_service' => $this->t('Placed in service'),
      'book' => $this->t('Book value'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    $money = fn($v): string => $this->currency . ' ' . number_format((float) $v, 2);
    $basis_type = $entity->get('basis_type')->value;
    $class = $entity->get('macrs_class')->value;

    return [
      'label' => $entity->toLink(NULL, 'canonical'),
      'basis_type' => DepreciableAsset::BASIS_TYPES[$basis_type] ?? $basis_type,
      'basis' => $money($entity->get('basis')->value),
      'class' => DepreciableAsset::MACRS_CLASSES[$class] ?? ($class ?: '—'),
      'in_service' => substr((string) $entity->get('in_service_date')->value, 0, 10) ?: '—',
      'book' => $entity->isDepreciable()
        ? $money($this->engine->bookValue($entity, $this->currentYear))
        : $this->t('n/a'),
    ] + parent::buildRow($entity);
  }

}
