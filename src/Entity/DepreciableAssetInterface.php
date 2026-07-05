<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Interface for the depreciable_asset content entity (Phase 5).
 */
interface DepreciableAssetInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  /**
   * Whether this asset is depreciable.
   *
   * The single derived rule the DepreciationEngine and reports read:
   * a non-raised asset with positive basis, a real MACRS class, not disposed.
   * Raised (zero-basis) stock and land (class "none") are non-depreciable — a
   * first-class state, not an error (PHASE5 §2.3).
   */
  public function isDepreciable(): bool;

}
