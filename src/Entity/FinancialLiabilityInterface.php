<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Interface for the financial_liability content entity (Phase 5.4).
 */
interface FinancialLiabilityInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
