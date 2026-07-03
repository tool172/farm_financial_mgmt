<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Interface for the financial_transaction entity.
 *
 * The payment envelope (SPEC §3.1): one payment event on a date to/from one
 * counterparty. Holds no amount itself — the money lives on its lines; `total`
 * is the recomputed, stored sum. Independently revisionable with a full audit
 * trail (owner, changed, revision log).
 */
interface FinancialTransactionInterface extends ContentEntityInterface, RevisionableInterface, RevisionLogInterface, EntityOwnerInterface, EntityChangedInterface {

}
