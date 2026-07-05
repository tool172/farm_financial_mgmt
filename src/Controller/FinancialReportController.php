<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\farm_financial_mgmt\Service\BalanceSheetBuilder;
use Drupal\farm_financial_mgmt\Service\DepreciationEngine;
use Drupal\farm_financial_mgmt\Service\ReportBuilder;
use Drupal\farm_financial_mgmt\Service\TaxSummaryBuilder;
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
    protected TaxSummaryBuilder $taxSummaryBuilder,
    protected DepreciationEngine $depreciationEngine,
    protected BalanceSheetBuilder $balanceSheetBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('farm_financial_mgmt.report_builder'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
      $container->get('farm_financial_mgmt.tax_summary_builder'),
      $container->get('farm_financial_mgmt.depreciation_engine'),
      $container->get('farm_financial_mgmt.balance_sheet_builder'),
    );
  }

  /**
   * Access for the Tax Summary route: gated by the tax-planning setting.
   */
  public function taxAccess(AccountInterface $account): AccessResultInterface {
    $config = $this->config('farm_financial_mgmt.settings');
    $enabled = $config->get('tax_planning_enabled') ?? TRUE;
    if (!$enabled) {
      return AccessResult::forbidden('Tax planning is disabled.')->addCacheableDependency($config);
    }
    return AccessResult::allowedIfHasPermission($account, 'view financial reports')
      ->addCacheableDependency($config);
  }

  /**
   * Tax Summary (Schedule F) — Task 3.2/3.3/3.4.
   */
  public function taxSummary(): array {
    $filters = $this->filters();
    $currency = $this->currency();
    $data = $this->taxSummaryBuilder->build($filters);
    $sf = $data['schedule_f'];

    $summary = [
      ['kind' => 'income', 'label' => $this->t('Sch F income'), 'value' => number_format($sf['income_total'], 2)],
      ['kind' => 'expense', 'label' => $this->t('Sch F expenses'), 'value' => number_format($sf['expense_total'], 2)],
      ['kind' => 'net', 'label' => $this->t('Net farm profit'), 'value' => number_format($sf['net_profit'], 2)],
    ];

    $grids = [
      $this->taxGrid($this->t('Schedule F — Part I: Income (@method basis)', ['@method' => $data['method']]), $sf['income'], $currency, $sf['income_total']),
      $this->taxGrid($this->t('Schedule F — Part II: Expenses'), $sf['expense'], $currency, $sf['expense_total']),
    ];
    if ($data['form_4797']) {
      $grids[] = $this->taxGrid($this->t('Form 4797 — Breeding/draft/dairy/sport stock (separate from Sch F)'), $data['form_4797'], $currency);
    }
    if ($data['form_4835']) {
      $grids[] = $this->taxGrid($this->t('Form 4835 — Farm rental income (separate from Sch F)'), $data['form_4835'], $currency);
    }
    if ($data['schedule_e']) {
      $grids[] = $this->taxGrid($this->t('Schedule E — Cash rent (separate from Sch F)'), $data['schedule_e'], $currency);
    }
    if ($data['capital']) {
      $grids[] = $this->taxGrid($this->t('Capital — depreciated, not deducted (Sch F line 14, Phase 5)'), $data['capital'], $currency);
    }

    return $this->reportRender($this->t('Tax Summary (Schedule F)'), $filters, $summary, NULL, NULL, [], $grids);
  }

  /**
   * Builds a Line / Description / Amount grid from tax rows.
   */
  protected function taxGrid($heading, array $rows, string $currency, ?float $total = NULL): array {
    $grid_rows = [];
    foreach ($rows as $row) {
      $grid_rows[] = ['cells' => [
        ['value' => $row['line'] !== '' ? $row['line'] : '—'],
        ['value' => $row['label']],
        ['value' => $currency . ' ' . number_format($row['amount'], 2), 'num' => TRUE],
      ]];
    }
    if ($total !== NULL) {
      $grid_rows[] = ['total' => TRUE, 'cells' => [
        ['value' => ''],
        ['value' => (string) $this->t('Total')],
        ['value' => $currency . ' ' . number_format($total, 2), 'num' => TRUE],
      ]];
    }
    return [
      'heading' => $heading,
      'headers' => [['label' => $this->t('Line')], ['label' => $this->t('Description')], ['label' => $this->t('Amount'), 'num' => TRUE]],
      'rows' => $grid_rows,
    ];
  }

  /**
   * Depreciation schedule (Phase 5.3). Per-asset MACRS detail; year total feeds
   * Schedule F line 14. Raised/zero-basis and land rows shown as non-depreciable.
   */
  public function depreciationSchedule(): array {
    $filters = $this->filters();
    $year = (int) $filters['year'];
    $currency = $this->currency();
    $engine = $this->depreciationEngine;
    $engine->resetWarnings();

    $assets = $this->entityTypeManager()->getStorage('depreciable_asset')->loadMultiple();
    // Stable order: by placed-in-service date, then label.
    uasort($assets, static function ($a, $b) {
      return [$a->get('in_service_date')->value, $a->label()] <=> [$b->get('in_service_date')->value, $b->label()];
    });

    $rows = [];
    $line_14 = 0.0;
    $accum_total = 0.0;
    $book_total = 0.0;
    $deprec_count = 0;
    foreach ($assets as $asset) {
      $basis = (float) $asset->get('basis')->value;
      $class = $asset->get('macrs_class')->value;
      $method = $asset->get('depreciation_method')->value;
      $s179 = (float) $asset->get('section_179')->value;
      $schedule = $engine->schedule($asset);

      if (empty($schedule)) {
        // Non-depreciable: raised (zero basis) or land ("none") — a normal state.
        $status = $asset->get('basis_type')->value === 'raised'
          ? $this->t('Raised — 0 basis, not depreciable')
          : $this->t('Not depreciable');
        $rows[] = ['cells' => [
          ['value' => $asset->label()],
          ['value' => $this->methodClassLabel($class, $method)],
          ['value' => $currency . ' ' . number_format($basis, 2), 'num' => TRUE],
          ['value' => '—', 'num' => TRUE],
          ['value' => '—', 'num' => TRUE],
          ['value' => '—', 'num' => TRUE],
          ['value' => '—', 'num' => TRUE],
          ['value' => $currency . ' ' . number_format($basis, 2), 'num' => TRUE],
          ['value' => $status],
        ]];
        continue;
      }

      $bonus_pct = $engine->bonusPctFor($asset, (int) substr((string) $asset->get('in_service_date')->value, 0, 4));
      $bonus = round(($basis - min($s179, $basis)) * $bonus_pct / 100, 2);

      $year_dep = $schedule[$year]['depreciation'] ?? 0.0;
      // Disposed before this year: no current-year depreciation (mirror totalForYear).
      if (!$asset->get('disposed_date')->isEmpty()
        && (int) substr((string) $asset->get('disposed_date')->value, 0, 4) < $year) {
        $year_dep = 0.0;
      }
      $accum = $engine->accumulatedDepreciation($asset, $year);
      $book = $engine->bookValue($asset, $year);

      $line_14 += $year_dep;
      $accum_total += $accum;
      $book_total += $book;
      $deprec_count++;

      $rows[] = ['cells' => [
        ['value' => $asset->label()],
        ['value' => $this->methodClassLabel($class, $method)],
        ['value' => $currency . ' ' . number_format($basis, 2), 'num' => TRUE],
        ['value' => $s179 > 0 ? $currency . ' ' . number_format($s179, 2) : '—', 'num' => TRUE],
        ['value' => $bonus > 0 ? $currency . ' ' . number_format($bonus, 2) : '—', 'num' => TRUE],
        ['value' => $currency . ' ' . number_format($year_dep, 2), 'num' => TRUE],
        ['value' => $currency . ' ' . number_format($accum, 2), 'num' => TRUE],
        ['value' => $currency . ' ' . number_format($book, 2), 'num' => TRUE],
        ['value' => ''],
      ]];
    }

    // Total row = Schedule F line 14 for the year.
    $rows[] = ['total' => TRUE, 'cells' => [
      ['value' => (string) $this->t('Total — Schedule F line 14')],
      ['value' => ''],
      ['value' => ''],
      ['value' => ''],
      ['value' => ''],
      ['value' => $currency . ' ' . number_format($line_14, 2), 'num' => TRUE],
      ['value' => $currency . ' ' . number_format($accum_total, 2), 'num' => TRUE],
      ['value' => $currency . ' ' . number_format($book_total, 2), 'num' => TRUE],
      ['value' => ''],
    ]];

    // Surface loud degradation (e.g. an unconfigured §179/bonus year).
    foreach ($engine->getWarnings() as $warning) {
      $this->messenger()->addWarning($warning);
    }

    $grid = [
      'heading' => $this->t('Depreciation schedule — @year', ['@year' => $year]),
      'headers' => [
        ['label' => $this->t('Asset')],
        ['label' => $this->t('Class / method')],
        ['label' => $this->t('Basis'), 'num' => TRUE],
        ['label' => $this->t('§179'), 'num' => TRUE],
        ['label' => $this->t('Bonus'), 'num' => TRUE],
        ['label' => $this->t('Depreciation @year', ['@year' => $year]), 'num' => TRUE],
        ['label' => $this->t('Accumulated'), 'num' => TRUE],
        ['label' => $this->t('Book value'), 'num' => TRUE],
        ['label' => $this->t('Status')],
      ],
      'rows' => $rows,
    ];

    $summary = [
      ['kind' => 'expense', 'label' => $this->t('Depreciation (line 14)'), 'value' => number_format($line_14, 2)],
      ['kind' => 'net', 'label' => $this->t('Depreciable assets'), 'value' => (string) $deprec_count],
      ['kind' => 'income', 'label' => $this->t('Book value'), 'value' => number_format($book_total, 2)],
    ];

    return $this->reportRender($this->t('Depreciation Schedule'), $filters, $summary, NULL, NULL, [], [$grid]);
  }

  /**
   * A compact "5-year · GDS 200%" style label from class + method machine names.
   */
  protected function methodClassLabel(?string $class, ?string $method): string {
    $class_label = $class ? str_replace('yr', '-yr', $class) : '—';
    $method_label = match ($method) {
      'macrs_gds_200' => 'GDS 200%',
      'macrs_gds_150' => 'GDS 150%',
      'macrs_ads' => 'ADS',
      'straight_line' => 'SL',
      default => (string) $method,
    };
    return $class_label . ' · ' . $method_label;
  }

  /**
   * Balance sheet / net-worth statement (Phase 5.6).
   *
   * Market column (managerial, default) with a cost-basis reconciliation column
   * alongside. Equity is the derived plug (assets − liabilities); the spread
   * between the two equity figures is the valuation (unrealized-appreciation)
   * equity. Both columns must close to zero. The header states what is entered
   * vs automatic and the market as-of date.
   */
  public function balanceSheet(): array {
    $filters = $this->filters();
    $year = (int) $filters['year'];
    $currency = $this->currency();
    $data = $this->balanceSheetBuilder->build($year);

    $money = fn($n) => $currency . ' ' . number_format((float) $n, 2);

    // --- Assets grid (4 cols: item, cost basis, market, market source). ---
    $asset_rows = [];
    foreach ($data['assets'] as $a) {
      $source = match ($a['market_source']) {
        'appraised' => $this->t('appraised @date', ['@date' => $a['market_as_of'] ? date('Y-m-d', $a['market_as_of']) : '—']),
        'market_feed' => $this->t('market @date', ['@date' => $a['market_as_of'] ? date('Y-m-d', $a['market_as_of']) : '—']),
        default => $this->t('book (no market)'),
      };
      $asset_rows[] = ['cells' => [
        ['value' => $a['label']],
        ['value' => $money($a['basis']), 'num' => TRUE],
        ['value' => $money($a['market']), 'num' => TRUE],
        ['value' => $source],
      ]];
    }
    $asset_rows[] = ['cells' => [
      ['value' => $this->t('Cash (entered)')],
      ['value' => $money($data['cash']), 'num' => TRUE],
      ['value' => $money($data['cash']), 'num' => TRUE],
      ['value' => $data['cash_as_of'] ? $this->t('entered @date', ['@date' => $data['cash_as_of']]) : $this->t('entered')],
    ]];
    $asset_rows[] = ['total' => TRUE, 'cells' => [
      ['value' => $this->t('Total assets')],
      ['value' => $money($data['total_assets_basis']), 'num' => TRUE],
      ['value' => $money($data['total_assets_market']), 'num' => TRUE],
      ['value' => ''],
    ]];
    $assets_grid = [
      'heading' => $this->t('Assets'),
      'headers' => [
        ['label' => $this->t('Item')],
        ['label' => $this->t('Cost basis'), 'num' => TRUE],
        ['label' => $this->t('Market'), 'num' => TRUE],
        ['label' => $this->t('Market basis')],
      ],
      'rows' => $asset_rows,
    ];

    // --- Liabilities grid. ---
    $liab_rows = [];
    foreach ($data['liabilities'] as $l) {
      $liab_rows[] = ['cells' => [
        ['value' => $l['label']],
        ['value' => $money($l['balance']), 'num' => TRUE],
        ['value' => $money($l['balance']), 'num' => TRUE],
      ]];
    }
    $liab_rows[] = ['total' => TRUE, 'cells' => [
      ['value' => $this->t('Total liabilities')],
      ['value' => $money($data['total_liabilities']), 'num' => TRUE],
      ['value' => $money($data['total_liabilities']), 'num' => TRUE],
    ]];
    $liab_grid = [
      'heading' => $this->t('Liabilities (entered)'),
      'headers' => [['label' => $this->t('Loan')], ['label' => $this->t('Cost basis'), 'num' => TRUE], ['label' => $this->t('Market'), 'num' => TRUE]],
      'rows' => $liab_rows,
    ];

    // --- Equity grid: derived plug + valuation spread + balance check. ---
    $equity_grid = [
      'heading' => $this->t('Owner equity (derived — assets less liabilities)'),
      'headers' => [['label' => $this->t('')], ['label' => $this->t('Cost basis'), 'num' => TRUE], ['label' => $this->t('Market'), 'num' => TRUE]],
      'rows' => [
        ['cells' => [
          ['value' => $this->t('Total assets')],
          ['value' => $money($data['total_assets_basis']), 'num' => TRUE],
          ['value' => $money($data['total_assets_market']), 'num' => TRUE],
        ]],
        ['cells' => [
          ['value' => $this->t('Less: liabilities')],
          ['value' => '(' . $money($data['total_liabilities']) . ')', 'num' => TRUE],
          ['value' => '(' . $money($data['total_liabilities']) . ')', 'num' => TRUE],
        ]],
        ['total' => TRUE, 'cells' => [
          ['value' => $this->t('Owner equity (net worth)')],
          ['value' => $money($data['equity_basis']), 'num' => TRUE],
          ['value' => $money($data['equity_market']), 'num' => TRUE],
        ]],
        ['cells' => [
          ['value' => $this->t('Valuation equity (market − cost basis = unrealized appreciation)')],
          ['value' => '—', 'num' => TRUE],
          ['value' => $money($data['valuation_equity']), 'num' => TRUE],
        ]],
        ['cells' => [
          ['value' => $this->t('Balance check (assets − liabilities − equity, must be 0)')],
          ['value' => $money($data['balances_basis']), 'num' => TRUE],
          ['value' => $money($data['balances_market']), 'num' => TRUE],
        ]],
      ],
    ];

    // --- Summary tiles: the two net-worth figures and the spread. ---
    $summary = [
      ['kind' => 'income', 'label' => $this->t('Net worth (market)'), 'value' => number_format($data['equity_market'], 2)],
      ['kind' => 'expense', 'label' => $this->t('Net worth (cost basis)'), 'value' => number_format($data['equity_basis'], 2)],
      ['kind' => 'net', 'label' => $this->t('Valuation equity (spread)'), 'value' => number_format($data['valuation_equity'], 2)],
    ];

    // --- Header disclosures: three, because the sheet is partly entered. ---
    if ($data['market_as_of'] === NULL) {
      $market_line = $this->t('Market values: book/appraisal only — no market-feed date.');
    }
    elseif ($data['market_dates_vary']) {
      $market_line = $this->t('Market values as of @date (dates vary — see the Market basis column).', ['@date' => date('Y-m-d', $data['market_as_of'])]);
    }
    else {
      $market_line = $this->t('Market values as of @date.', ['@date' => date('Y-m-d', $data['market_as_of'])]);
    }
    $disclosures = [
      $market_line,
      $data['cash_as_of']
        ? $this->t('Cash is an entered position as of @date, not derived from the ledger.', ['@date' => $data['cash_as_of']])
        : $this->t('Cash is an entered position, not derived from the ledger.'),
      $this->t('Liabilities reflect entered loans and their tracked principal paydown.'),
    ];

    if (!$data['balanced']) {
      $this->messenger()->addError($this->t('Balance sheet does not close: cost-basis @b, market @m. This is a bug — both columns must sum to zero.', [
        '@b' => $money($data['balances_basis']),
        '@m' => $money($data['balances_market']),
      ]));
    }

    return $this->reportRender($this->t('Balance Sheet (Net Worth) — as of @year', ['@year' => $year]), $filters, $summary, NULL, NULL, [], [$assets_grid, $liab_grid, $equity_grid], $disclosures);
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
  protected function reportRender($title, array $filters, array $summary, ?string $chart_id, ?array $chart, array $sections, array $grids = [], array $disclosures = []): array {
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
      '#grids' => $grids,
      '#disclosures' => $disclosures,
      '#cache' => [
        'tags' => ['financial_line_list'],
        'contexts' => ['user.permissions', 'url.query_args'],
      ],
    ];
  }

  /**
   * Spending by category (expense).
   */
  public function spendingByCategory(): array {
    return $this->byCategory('expense', $this->t('Spending by Category'), 'ffm-spending-chart', $this->t('Total expense'));
  }

  /**
   * Income by category.
   */
  public function incomeByCategory(): array {
    return $this->byCategory('income', $this->t('Income by Category'), 'ffm-income-chart', $this->t('Total income'));
  }

  /**
   * Shared single-direction category report with a doughnut chart.
   */
  protected function byCategory(string $direction, $title, string $chart_id, $total_label): array {
    $filters = $this->filters();
    $currency = $this->currency();
    $totals = $this->reportBuilder->categoryTotals($filters + ['direction' => $direction]);
    arsort($totals);
    $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadMultiple(array_keys($totals));
    $labels = [];
    $data = [];
    $sum = 0.0;
    foreach ($totals as $tid => $amount) {
      $labels[] = isset($terms[$tid]) ? $terms[$tid]->label() : (string) $this->t('Uncategorized');
      $data[] = round($amount, 2);
      $sum += $amount;
    }
    $chart = [
      'type' => 'doughnut',
      'data' => ['labels' => $labels, 'datasets' => [['data' => $data, 'backgroundColor' => $this->palette(count($data))]]],
      'options' => ['responsive' => TRUE, 'maintainAspectRatio' => FALSE],
    ];
    $section = $this->categorySection($this->t('By category'), $direction, $filters, $currency);
    return $this->reportRender($title, $filters, [
      ['kind' => $direction, 'label' => $total_label, 'value' => number_format($sum, 2)],
    ], $chart_id, $chart, [$section]);
  }

  /**
   * Cash flow: monthly income/expense/net with a line chart.
   */
  public function cashFlow(): array {
    $filters = $this->filters();
    $currency = $this->currency();
    $monthly = $this->reportBuilder->monthlyTotals($filters);

    $labels = array_keys($monthly);
    $income = $expense = $net = [];
    $grid_rows = [];
    $sum_in = $sum_out = 0.0;
    foreach ($monthly as $month => $v) {
      $n = $v['income'] - $v['expense'];
      $income[] = round($v['income'], 2);
      $expense[] = round($v['expense'], 2);
      $net[] = round($n, 2);
      $sum_in += $v['income'];
      $sum_out += $v['expense'];
      $grid_rows[] = ['cells' => [
        ['value' => $month],
        ['value' => $currency . ' ' . number_format($v['income'], 2), 'num' => TRUE],
        ['value' => $currency . ' ' . number_format($v['expense'], 2), 'num' => TRUE],
        ['value' => $currency . ' ' . number_format($n, 2), 'num' => TRUE],
      ]];
    }
    $grid_rows[] = ['total' => TRUE, 'cells' => [
      ['value' => (string) $this->t('Total')],
      ['value' => $currency . ' ' . number_format($sum_in, 2), 'num' => TRUE],
      ['value' => $currency . ' ' . number_format($sum_out, 2), 'num' => TRUE],
      ['value' => $currency . ' ' . number_format($sum_in - $sum_out, 2), 'num' => TRUE],
    ]];

    $chart = [
      'type' => 'line',
      'data' => ['labels' => array_values($labels), 'datasets' => [
        ['label' => 'Income', 'data' => array_values($income), 'borderColor' => '#2f7d32', 'fill' => FALSE, 'tension' => 0.2],
        ['label' => 'Expense', 'data' => array_values($expense), 'borderColor' => '#a12d2d', 'fill' => FALSE, 'tension' => 0.2],
        ['label' => 'Net', 'data' => array_values($net), 'borderColor' => '#1e5a8a', 'fill' => FALSE, 'tension' => 0.2],
      ]],
      'options' => ['responsive' => TRUE, 'maintainAspectRatio' => FALSE],
    ];
    $grid = [
      'heading' => $this->t('Monthly cash flow'),
      'headers' => [['label' => $this->t('Month')], ['label' => $this->t('Income'), 'num' => TRUE], ['label' => $this->t('Expense'), 'num' => TRUE], ['label' => $this->t('Net'), 'num' => TRUE]],
      'rows' => $grid_rows,
    ];
    return $this->reportRender($this->t('Cash Flow'), $filters, [], 'ffm-cashflow-chart', $chart, [], [$grid]);
  }

  /**
   * Monthly view: income/expense/net by month (tabular).
   */
  public function monthlyView(): array {
    // Same data as cash flow but table-first (no chart), useful for scanning.
    $render = $this->cashFlow();
    $render['#report_title'] = $this->t('Monthly view');
    return $render;
  }

  /**
   * Per-record P&L: income/expense/net for one asset, plus a record picker.
   */
  public function perRecord(): array {
    $filters = $this->filters();
    $currency = $this->currency();
    $asset_id = (int) ($this->requestStack->getCurrentRequest()->query->get('asset') ?? 0);

    // Assets referenced by any line in the period → the picker.
    $all_rows = $this->reportBuilder->lineRows($filters);
    $asset_ids = array_values(array_unique(array_filter(array_map(static fn($r) => $r['asset'], $all_rows))));
    $assets = $asset_ids ? $this->entityTypeManager()->getStorage('asset')->loadMultiple($asset_ids) : [];

    $picker_rows = [];
    foreach ($assets as $asset) {
      $picker_rows[] = ['cells' => [
        ['value' => $asset->label(), 'url' => $this->reportUrl('per_record', ['asset' => $asset->id()], $filters)],
        ['value' => $asset->get('type')->target_id ?? $asset->bundle()],
      ]];
    }
    $picker = [
      'heading' => $this->t('Records with attributed lines'),
      'headers' => [['label' => $this->t('Asset')], ['label' => $this->t('Type')]],
      'rows' => $picker_rows,
    ];

    $summary = [];
    $sections = [];
    $grids = [$picker];
    if ($asset_id) {
      $asset = $this->entityTypeManager()->getStorage('asset')->load($asset_id);
      $totals = $this->reportBuilder->assetTotals($asset_id, $filters);
      $summary = [
        ['kind' => 'income', 'label' => $this->t('Income'), 'value' => number_format($totals['income'], 2)],
        ['kind' => 'expense', 'label' => $this->t('Expense'), 'value' => number_format($totals['expense'], 2)],
        ['kind' => 'net', 'label' => $this->t('Net'), 'value' => number_format($totals['net'], 2)],
      ];
      // Line detail for this asset.
      $lines = $this->reportBuilder->lineRows($filters + ['asset' => $asset_id]);
      $terms = $this->entityTypeManager()->getStorage('taxonomy_term');
      $detail_rows = [];
      foreach ($lines as $row) {
        $cat = $row['category'] ? $terms->load($row['category']) : NULL;
        $detail_rows[] = ['cells' => [
          ['value' => $row['txn_date']],
          ['value' => $cat ? $cat->label() : (string) $this->t('Uncategorized')],
          ['value' => $row['direction']],
          ['value' => $currency . ' ' . number_format($row['amount'], 2), 'num' => TRUE],
        ]];
      }
      $grids[] = [
        'heading' => $this->t('Lines attributed to @label', ['@label' => $asset ? $asset->label() : $asset_id]),
        'headers' => [['label' => $this->t('Date')], ['label' => $this->t('Category')], ['label' => $this->t('Direction')], ['label' => $this->t('Amount'), 'num' => TRUE]],
        'rows' => $detail_rows,
      ];
    }
    return $this->reportRender($this->t('Per-Record P&L'), $filters, $summary, NULL, NULL, $sections, $grids);
  }

  /**
   * Builds a report URL preserving the period filter.
   */
  protected function reportUrl(string $report, array $extra, array $filters): string {
    $query = $extra;
    if (!empty($filters['year'])) {
      $query['year'] = $filters['year'];
    }
    return \Drupal\Core\Url::fromRoute('farm_financial_mgmt.report.' . $report, [], ['query' => $query])->toString();
  }

  /**
   * A repeating color palette for category charts.
   */
  protected function palette(int $count): array {
    $base = ['#2f7d32', '#a12d2d', '#1e5a8a', '#8b6914', '#5b3fa0', '#0f766e', '#b45309', '#9d174d', '#374151', '#65a30d'];
    $out = [];
    for ($i = 0; $i < $count; $i++) {
      $out[] = $base[$i % count($base)];
    }
    return $out;
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
