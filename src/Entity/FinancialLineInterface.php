<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;

/**
 * Interface for the financial_line entity.
 *
 * A line carries the money, category, and per-line asset attribution. It is the
 * entity that makes split transactions and per-line attribution possible
 * (SPEC §3.2). Independently revisionable; references its parent transaction.
 */
interface FinancialLineInterface extends ContentEntityInterface, RevisionableInterface {

}
