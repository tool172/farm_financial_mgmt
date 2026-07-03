<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Plugin\Validation\Constraint;

use Drupal\farm_financial_mgmt\Entity\FinancialTransactionInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the HomogeneousDirection constraint.
 */
class HomogeneousDirectionValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof FinancialTransactionInterface) {
      return;
    }
    $direction = $value->get('direction')->value;
    if (empty($direction)) {
      return;
    }
    foreach ($value->get('lines')->referencedEntities() as $line) {
      $category = $line->get('category')->entity;
      if ($category === NULL) {
        continue;
      }
      $category_direction = $category->get('direction')->value;
      if (!empty($category_direction) && $category_direction !== $direction) {
        $this->context->buildViolation($constraint->message)
          ->setParameter('@line', (string) $line->label())
          ->setParameter('@category', (string) $category->label())
          ->setParameter('@line_direction', (string) $category_direction)
          ->setParameter('@direction', (string) $direction)
          ->atPath('lines')
          ->addViolation();
      }
    }
  }

}
