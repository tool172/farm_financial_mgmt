<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service\Aue;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\asset\Entity\AssetInterface;

/**
 * Optional AUE provider that prefers farm_grazing_rotation_plan snapshots.
 *
 * Swap this in for the default by overriding the AueProviderInterface alias in
 * a site's services (SPEC §8/§9). It has NO hard dependency on the grazing
 * module: when that module is absent (or has no snapshot for an animal) it
 * delegates to the DefaultAueProvider lifecycle logic. The grazing move-time
 * AUE snapshot is the intended override point for getAnimalAue().
 */
class GrazingAueProvider extends DefaultAueProvider {

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function getAnimalAue(AssetInterface $animal): float {
    if ($this->moduleHandler->moduleExists('farm_grazing_rotation_plan')) {
      // Extension point: read the animal's most recent move-time AUE snapshot
      // from the grazing plan and return it when available. Until that is wired,
      // fall through to the animal_stage-based default so results stay correct.
    }
    return parent::getAnimalAue($animal);
  }

}
