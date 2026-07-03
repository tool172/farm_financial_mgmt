<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\farm_financial_mgmt\Service\ReportBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Financial report pages (Phase 2). Charted/computed output via ReportBuilder
 * + Chart.js; tabular lists remain Views.
 */
class FinancialReportController extends ControllerBase {

  public function __construct(
    protected ReportBuilder $reportBuilder,
    protected TimeInterface $time,
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('farm_financial_mgmt.report_builder'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
    );
  }

  /**
   * Profit & Loss: income, expense, net; by category; period range.
   */
  public function profitAndLoss(): array {
    $filters = $this->filters();
    $currency = $this->currency();
    $totals = $this->reportBuilder->directionTotals($filters);

    $chart = [
      'type' => 'bar',
      'data' => [
        'labels' => ['Income', 'Expense', 'Net'],
        'datasets' => [[
          'data' => [
            round($totals['income'], 2),
            round($totals['expense'], 2),
            round($totals['net'], 2),
          ],
          'backgroundColor' => ['#2f7d32', '#a12d2d', '#1e5a8a'],
        ]],
      ],
      'options' => [
        'plugins' => ['legend' => ['display' => FALSE]],
        'scales' => ['y' => ['beginAtZero' => TRUE]],
        'responsive' => TRUE,
        'maintainAspectRatio' => FALSE,
      ],
    ];

    return $this->reportRender(
      $this->t('Profit & Loss'),
      $filters,
      [
        ['kind' => 'income', 'label' => $this->t('Income'), 'value' => number_format($totals['income'], 2)],
        ['kind' => 'expense', 'label' => $this->t('Expense'), 'value' => number_format($totals['expense'], 2)],
        ['kind' => 'net', 'label' => $this->t('Net'), 'value' => number_format($totals['net'], 2)],
      ],
      'ffm-pl-chart',
      $chart,
      [
        $this->categorySection($this->t('Income by category'), 'income', $filters, $currency),
        $this->categorySection($this->t('Expense by category'), 'expense', $filters, $currency),
      ],
    );
  }

  /**
   * Builds a category breakdown section for a direction (children rolled up).
   */
  protected function categorySection($heading, string $direction, array $filters, string $currency): array {
    $totals = $this->reportBuilder->categoryTotals($filters + ['direction' => $direction]);
    arsort($totals);
    $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadMultiple(array_keys($totals));
    $rows = [];
    $sum = 0.0;
    foreach ($totals as $tid => $amount) {
      $rows[] = [
        'label' => isset($terms[$tid]) ? $terms[$tid]->label() : $this->t('Uncategorized'),
        'amount' => number_format($amount, 2),
      ];
      $sum += $amount;
    }
    return ['heading' => $heading, 'rows' => $rows, 'total' => number_format($sum, 2)];
  }

  /**
   * Assembles the render array shared by report pages.
   */
  protected function reportRender($title, array $filters, array $summary, ?string $chart_id, ?array $chart, array $sections): array {
    $settings = [];
    if ($chart_id && $chart) {
      $settings['farmFinancialMgmt']['charts'][$chart_id] = $chart;
    }
    return [
      '#theme' => 'financial_report',
      '#attached' => [
        'library' => ['farm_financial_mgmt/report'],
        'drupalSettings' => $settings,
      ],
      '#report_title' => $title,
      '#currency' => $this->currency(),
      '#year' => $filters['year'] ?? '',
      '#summary' => $summary,
      '#chart_id' => $chart_id,
      '#sections' => $sections,
      '#cache' => [
        'tags' => ['financial_line_list'],
        'contexts' => ['user.permissions', 'url.query_args'],
      ],
    ];
  }

  /**
   * Reads period filters from the query string (defaults to the current year).
   */
  protected function filters(): array {
    $query = $this->requestStack->getCurrentRequest()->query;
    $year = $query->get('year');
    $filters = ['year' => $year !== NULL && $year !== '' ? (int) $year : (int) date('Y', $this->time->getRequestTime())];
    if ($from = $query->get('from')) {
      $filters['from'] = $from;
    }
    if ($to = $query->get('to')) {
      $filters['to'] = $to;
    }
    return $filters;
  }

  /**
   * The configured currency code.
   */
  protected function currency(): string {
    return $this->config('farm_financial_mgmt.settings')->get('currency') ?: 'USD';
  }

}
