<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\farm_financial_mgmt\Entity\FinancialTransactionInterface;

/**
 * Writes denormalized fields down onto a transaction's lines and stores total.
 *
 * Called from the transaction's postsave (insert/update), by which point IEF
 * has persisted the child lines (SPEC §4 sequencing invariant): the lines must
 * be persisted before they are summed and before we can set their back-ref.
 *
 * Two subtleties keep the audit trail clean:
 *  - Each line's write-through save uses setNewRevision(FALSE), updating the
 *    revision IEF just created in place rather than spawning another.
 *  - Storing the recomputed total re-saves the transaction once, guarded
 *    against re-entry and in place (setNewRevision(FALSE)), so one user save
 *    produces exactly one transaction revision.
 */
class TransactionTotalizer {

  /**
   * Re-entry guard for the in-place total re-save.
   */
  protected bool $processing = FALSE;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Denormalizes to lines and recomputes + stores the transaction total.
   */
  public function totalize(FinancialTransactionInterface $transaction): void {
    if ($this->processing) {
      return;
    }

    $date = $transaction->get('date')->value;
    $year = $transaction->get('reporting_year')->isEmpty()
      ? NULL : (int) $transaction->get('reporting_year')->value;
    $direction = $transaction->get('direction')->value;

    $sum = 0.0;
    foreach ($transaction->get('lines')->referencedEntities() as $line) {
      $changed = FALSE;
      if ((int) $line->get('transaction')->target_id !== (int) $transaction->id()) {
        $line->set('transaction', $transaction->id());
        $changed = TRUE;
      }
      if ($line->get('txn_date')->value !== $date) {
        $line->set('txn_date', $date);
        $changed = TRUE;
      }
      $line_year = $line->get('reporting_year')->isEmpty()
        ? NULL : (int) $line->get('reporting_year')->value;
      if ($line_year !== $year) {
        $line->set('reporting_year', $year);
        $changed = TRUE;
      }
      if ($line->get('direction')->value !== $direction) {
        $line->set('direction', $direction);
        $changed = TRUE;
      }
      if ($changed) {
        $line->setNewRevision(FALSE);
        $line->save();
      }
      $sum += (float) $line->get('amount')->value;
    }

    $total = number_format($sum, 2, '.', '');
    $current = number_format((float) $transaction->get('total')->value, 2, '.', '');
    if ($current !== $total) {
      $this->processing = TRUE;
      $transaction->set('total', $total);
      $transaction->setNewRevision(FALSE);
      $transaction->save();
      $this->processing = FALSE;
    }
  }

}
