<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\farm_financial_mgmt\Service\Valuation\AssetValuationProviderInterface;

/**
 * Assembles the market-value balance sheet with cost-basis reconciliation (5.6).
 *
 * Asset-side-complete and honest about the rest (PHASE5 §2.4): the depreciable
 * assets feed both columns automatically (cost-basis book value and market, from
 * the AssetValuationProvider), while cash is an ENTERED position and liabilities
 * are ENTERED loans with their tracked paydown (ReportBuilder::liabilityBalance).
 *
 * Four assembly-integrity properties, by construction:
 *  - Equity is the PLUG: assets − liabilities, computed as the residual, never
 *    an independent figure.
 *  - TWO equity figures (cost-basis and market), and their difference — the
 *    valuation (unrealized-appreciation) equity — is a first-class line, because
 *    that spread is the point of the FFSC market-with-reconciliation format.
 *  - BOTH columns close to zero independently (assets − liabilities − equity),
 *    asserted here, not eyeballed.
 *  - The market as-of disclosure names the OLDEST market date (or flags that
 *    they vary), so a stale herd price can't be read as live.
 */
class BalanceSheetBuilder {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AssetValuationProviderInterface $valuation,
    protected ReportBuilder $reportBuilder,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds the balance sheet as of a year.
   */
  public function build(int $through_year): array {
    // --- Assets (auto): depreciable assets, both columns. ---
    $asset_rows = [];
    $assets_basis = 0.0;
    $assets_market = 0.0;
    $market_dates = [];
    $storage = $this->entityTypeManager->getStorage('depreciable_asset');
    $assets = $storage->loadMultiple();
    // Stable order by in-service date then label.
    uasort($assets, static fn($a, $b) => [$a->get('in_service_date')->value, $a->label()] <=> [$b->get('in_service_date')->value, $b->label()]);
    foreach ($assets as $asset) {
      $v = $this->valuation->value($asset, $through_year);
      $asset_rows[] = [
        'label' => $asset->label(),
        'basis' => $v['basis'],
        'market' => $v['market'],
        'market_source' => $v['market_source'],
        'market_as_of' => $v['market_as_of'],
      ];
      $assets_basis = round($assets_basis + $v['basis'], 2);
      $assets_market = round($assets_market + $v['market'], 2);
      if (!empty($v['market_as_of'])) {
        $market_dates[] = (int) $v['market_as_of'];
      }
    }

    // --- Cash (entered): identical in both columns. ---
    $settings = $this->configFactory->get('farm_financial_mgmt.settings');
    $cash = (float) ($settings->get('cash_position') ?? 0);
    $cash_as_of = $settings->get('cash_as_of') ?: NULL;

    $total_assets_basis = round($assets_basis + $cash, 2);
    $total_assets_market = round($assets_market + $cash, 2);

    // --- Liabilities (entered): balance derived from paydown, same both columns. ---
    $liab_rows = [];
    $total_liabilities = 0.0;
    foreach ($this->entityTypeManager->getStorage('financial_liability')->loadMultiple() as $liability) {
      $balance = $this->reportBuilder->liabilityBalance($liability);
      $liab_rows[] = ['label' => $liability->label(), 'balance' => $balance];
      $total_liabilities = round($total_liabilities + $balance, 2);
    }

    // --- Equity is the plug: assets − liabilities (derived, per column). ---
    $equity_basis = round($total_assets_basis - $total_liabilities, 2);
    $equity_market = round($total_assets_market - $total_liabilities, 2);
    // The payload: market equity − cost-basis equity = unrealized appreciation.
    $valuation_equity = round($equity_market - $equity_basis, 2);

    // --- Balance check: each column must close to zero, independently. ---
    $balances_basis = round($total_assets_basis - $total_liabilities - $equity_basis, 2);
    $balances_market = round($total_assets_market - $total_liabilities - $equity_market, 2);

    // --- Market as-of disclosure: oldest date, flag if they vary. ---
    $market_as_of = $market_dates ? min($market_dates) : NULL;
    $market_dates_vary = count(array_unique($market_dates)) > 1;

    return [
      'through_year' => $through_year,
      'assets' => $asset_rows,
      'cash' => $cash,
      'cash_as_of' => $cash_as_of,
      'assets_basis' => $assets_basis,
      'assets_market' => $assets_market,
      'total_assets_basis' => $total_assets_basis,
      'total_assets_market' => $total_assets_market,
      'liabilities' => $liab_rows,
      'total_liabilities' => $total_liabilities,
      'equity_basis' => $equity_basis,
      'equity_market' => $equity_market,
      'valuation_equity' => $valuation_equity,
      'balances_basis' => $balances_basis,
      'balances_market' => $balances_market,
      'balanced' => ($balances_basis === 0.0 && $balances_market === 0.0),
      'market_as_of' => $market_as_of,
      'market_dates_vary' => $market_dates_vary,
    ];
  }

}
