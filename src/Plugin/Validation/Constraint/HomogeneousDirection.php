<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Every line's category direction must equal the transaction's direction.
 *
 * Enforces the homogeneous-direction invariant (CLAUDE / SPEC §3.1): an income
 * transaction may only contain income-category lines, and likewise for expense.
 */
#[Constraint(
  id: 'HomogeneousDirection',
  label: new TranslatableMarkup('Homogeneous line direction', [], ['context' => 'Validation']),
  type: ['entity'],
)]
class HomogeneousDirection extends SymfonyConstraint {

  /**
   * Violation message.
   */
  public string $message = 'The line "@line" has a @line_direction category (@category), but this is a @direction transaction. All lines must match the transaction direction.';

}
