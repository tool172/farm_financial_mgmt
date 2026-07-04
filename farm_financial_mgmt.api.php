<?php

/**
 * @file
 * Hooks provided by the Farm Financial Management module.
 */

declare(strict_types=1);

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter the computed Schedule-F tax summary.
 *
 * Fired by \Drupal\farm_financial_mgmt\Service\TaxSummaryBuilder::build(); the
 * export/extension point for the tax summary (Task 3.5). The Phase 4 CSV /
 * QuickBooks export consumes this same structure.
 *
 * @param array $data
 *   The tax summary: 'method', 'year', 'schedule_f' (income/expense rows +
 *   totals + net_profit), 'form_4797', 'form_4835', 'schedule_e', 'capital'.
 * @param array $filters
 *   The period filters used (year / from / to / payment_status).
 */
function hook_farm_financial_mgmt_tax_summary_alter(array &$data, array $filters): void {
  // Example: append a memo line to the Schedule F expense section.
  $data['schedule_f']['expense'][] = [
    'line' => '32',
    'label' => t('Adjustment'),
    'amount' => 0.0,
  ];
}

/**
 * @} End of "addtogroup hooks".
 */
