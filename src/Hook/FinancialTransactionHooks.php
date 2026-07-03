<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\farm_financial_mgmt\Entity\FinancialTransactionInterface;
use Drupal\farm_financial_mgmt\Service\TransactionTotalizer;

/**
 * Lifecycle hooks for the financial_transaction entity.
 */
class FinancialTransactionHooks {

  public function __construct(
    protected TransactionTotalizer $totalizer,
  ) {}

  /**
   * Defaults reporting_year from the date and auto-generates a blank label.
   */
  #[Hook('financial_transaction_presave')]
  public function presave(FinancialTransactionInterface $transaction): void {
    if ($transaction->get('reporting_year')->isEmpty() && !$transaction->get('date')->isEmpty()) {
      $transaction->set('reporting_year', (int) substr((string) $transaction->get('date')->value, 0, 4));
    }
    if ($transaction->get('label')->isEmpty()) {
      $counterparty = $transaction->get('counterparty')->entity;
      $parts = array_filter([
        $counterparty?->label(),
        $transaction->get('date')->value,
      ]);
      $transaction->set('label', $parts ? implode(' — ', $parts) : 'Transaction');
    }
  }

  /**
   * Denormalize + totalize once the lines are persisted (SPEC §4 sequencing).
   */
  #[Hook('financial_transaction_insert')]
  public function insert(FinancialTransactionInterface $transaction): void {
    $this->totalizer->totalize($transaction);
  }

  /**
   * {@inheritdoc}
   */
  #[Hook('financial_transaction_update')]
  public function update(FinancialTransactionInterface $transaction): void {
    $this->totalizer->totalize($transaction);
  }

}
