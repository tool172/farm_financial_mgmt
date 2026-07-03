<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Financial dashboard: income/expense/net for the current reporting year
 * plus recent transactions.
 *
 * The income/expense sums come from a single-table aggregate query on
 * financial_line using the denormalized reporting_year + direction — the
 * reporting optimization the Option C data model exists for (SPEC §3.2).
 */
class FinancialDashboardController extends ControllerBase {

  public function __construct(
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('datetime.time'));
  }

  /**
   * Builds the dashboard render array.
   */
  public function view(): array {
    $year = (int) date('Y', $this->time->getRequestTime());
    $currency = $this->config('farm_financial_mgmt.settings')->get('currency') ?: 'USD';

    // Single-table aggregate: SUM(amount) grouped by direction for the year.
    $rows = $this->entityTypeManager()->getStorage('financial_line')->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('reporting_year', $year)
      ->groupBy('direction')
      ->aggregate('amount', 'SUM')
      ->execute();
    $income = 0.0;
    $expense = 0.0;
    foreach ($rows as $row) {
      if (($row['direction'] ?? NULL) === 'income') {
        $income = (float) $row['amount_sum'];
      }
      elseif (($row['direction'] ?? NULL) === 'expense') {
        $expense = (float) $row['amount_sum'];
      }
    }

    // Recent transactions.
    $txn_storage = $this->entityTypeManager()->getStorage('financial_transaction');
    $ids = $txn_storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('date', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, 10)
      ->execute();
    $recent = [];
    foreach ($txn_storage->loadMultiple($ids) as $transaction) {
      $recent[] = [
        'url' => $transaction->toUrl()->toString(),
        'label' => $transaction->label(),
        'date' => $transaction->get('date')->value,
        'direction' => $transaction->get('direction')->value,
        'total' => number_format((float) $transaction->get('total')->value, 2),
        'status' => $transaction->get('payment_status')->value,
      ];
    }

    return [
      '#theme' => 'financial_dashboard',
      '#attached' => ['library' => ['farm_financial_mgmt/dashboard']],
      '#year' => $year,
      '#currency' => $currency,
      '#income' => number_format($income, 2),
      '#expense' => number_format($expense, 2),
      '#net' => number_format($income - $expense, 2),
      '#recent' => $recent,
      '#add_url' => Url::fromRoute('entity.financial_transaction.add_form')->toString(),
      '#list_url' => Url::fromRoute('view.financial_transactions.page_1')->toString(),
      '#cache' => [
        'tags' => ['financial_line_list', 'financial_transaction_list'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

}
