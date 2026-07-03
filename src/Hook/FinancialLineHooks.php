<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\farm_financial_mgmt\Entity\FinancialLineInterface;

/**
 * Lifecycle hooks for the financial_line entity.
 */
class FinancialLineHooks {

  /**
   * Auto-calculate amount = quantity × unit_price when both are provided.
   *
   * SPEC §3.2/§4: when quantity and unit_price are both set, amount is their
   * product; otherwise amount is entered directly and left untouched.
   */
  #[Hook('financial_line_presave')]
  public function presave(FinancialLineInterface $line): void {
    $quantity = $line->get('quantity');
    $unit_price = $line->get('unit_price');
    if ($quantity->isEmpty() || $unit_price->isEmpty()) {
      return;
    }
    $product = (float) $quantity->value * (float) $unit_price->value;
    // Store as a fixed 2-decimal string to match the decimal(14,2) amount field.
    $line->set('amount', number_format($product, 2, '.', ''));
  }

}
