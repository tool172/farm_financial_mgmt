<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\farm_financial_mgmt\Service\ReportBuilder;
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
    protected ReportBuilder $reportBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('datetime.time'),
      $container->get('farm_financial_mgmt.report_builder'),
    );
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

    // Income & spending by category for the year — the two category reports
    // embedded on the dashboard, each with a pie chart.
    $income_cat = $this->categoryBreakdown('income', $year);
    $expense_cat = $this->categoryBreakdown('expense', $year);
    $settings = [];
    if ($income_cat['has_data']) {
      $settings['farmFinancialMgmt']['charts']['ffm-dash-income-chart'] = $this->chartConfig($income_cat);
    }
    if ($expense_cat['has_data']) {
      $settings['farmFinancialMgmt']['charts']['ffm-dash-spending-chart'] = $this->chartConfig($expense_cat);
    }

    return [
      '#theme' => 'financial_dashboard',
      '#attached' => [
        // The report library carries Chart.js + the canvas-rendering behavior.
        'library' => ['farm_financial_mgmt/dashboard', 'farm_financial_mgmt/report'],
        'drupalSettings' => $settings,
      ],
      '#year' => $year,
      '#currency' => $currency,
      '#income' => number_format($income, 2),
      '#expense' => number_format($expense, 2),
      '#net' => number_format($income - $expense, 2),
      '#income_by_category' => ['chart_id' => 'ffm-dash-income-chart'] + $income_cat,
      '#spending_by_category' => ['chart_id' => 'ffm-dash-spending-chart'] + $expense_cat,
      '#recent' => $recent,
      '#add_url' => Url::fromRoute('entity.financial_transaction.add_form')->toString(),
      '#list_url' => Url::fromRoute('view.financial_transactions.page_1')->toString(),
      '#cache' => [
        'tags' => ['financial_line_list', 'financial_transaction_list'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Category totals for a direction in a year, shaped for a pie + a list.
   */
  protected function categoryBreakdown(string $direction, int $year): array {
    $totals = $this->reportBuilder->categoryTotals(['reporting_year' => $year, 'direction' => $direction]);
    arsort($totals);
    $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadMultiple(array_keys($totals));
    $colors = $this->palette(count($totals));
    $labels = [];
    $data = [];
    $categories = [];
    $sum = 0.0;
    $i = 0;
    foreach ($totals as $tid => $amount) {
      $label = isset($terms[$tid]) ? $terms[$tid]->label() : (string) $this->t('Uncategorized');
      $labels[] = $label;
      $data[] = round($amount, 2);
      $categories[] = ['label' => $label, 'amount' => number_format($amount, 2), 'color' => $colors[$i]];
      $sum += $amount;
      $i++;
    }
    return [
      'labels' => $labels,
      'data' => $data,
      'colors' => $colors,
      'categories' => $categories,
      'total' => number_format($sum, 2),
      'has_data' => !empty($data),
    ];
  }

  /**
   * A Chart.js doughnut config for a category breakdown.
   *
   * The built-in legend is off — the category list beside the chart is the
   * legend (with matching swatches).
   */
  protected function chartConfig(array $breakdown): array {
    return [
      'type' => 'doughnut',
      'data' => [
        'labels' => $breakdown['labels'],
        'datasets' => [['data' => $breakdown['data'], 'backgroundColor' => $breakdown['colors']]],
      ],
      'options' => [
        'responsive' => TRUE,
        'maintainAspectRatio' => FALSE,
        'plugins' => ['legend' => ['display' => FALSE]],
      ],
    ];
  }

  /**
   * A repeating category color palette (matches the report charts).
   */
  protected function palette(int $count): array {
    $base = ['#2f7d32', '#a12d2d', '#1e5a8a', '#8b6914', '#5b3fa0', '#0f766e', '#b45309', '#9d174d', '#374151', '#65a30d'];
    $out = [];
    for ($i = 0; $i < $count; $i++) {
      $out[] = $base[$i % count($base)];
    }
    return $out;
  }

}
