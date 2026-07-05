<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service\Valuation;

use Drupal\farm_financial_mgmt\Entity\DepreciableAssetInterface;

/**
 * Values a depreciable asset for the balance sheet (Phase 5.5).
 *
 * The §2.2 commitment: BOTH a basis (cost-basis / book) value AND a market
 * value are returned for EVERY asset — the balance sheet's two columns are
 * always both populated, never null. Basis drives depreciation and the
 * cost-basis balance sheet; market drives the managerial balance sheet.
 *
 * Degenerate cases are defined, not errors: a raised breeding cow has book 0
 * (zero basis, non-depreciable — the §2.3 fork) but a real market value;
 * equipment has book from the engine but market falls back to book or an
 * entered value rather than inventing a number.
 *
 * Market carries its as-of date and source so the balance sheet can state what
 * the number is built on (a three-week-old price is a number a banker wants
 * flagged) — same honesty principle as the entered-cash disclosure.
 */
interface AssetValuationProviderInterface {

  /**
   * Values one asset.
   *
   * @return array
   *   [
   *     'basis' => float,          // Book value: cost less accumulated depr.
   *     'market' => float,         // Managerial/market value (never null).
   *     'market_as_of' => int|null, // Unix ts of the market figure, or null.
   *     'market_source' => string, // 'appraised' | 'market_feed' | 'book'.
   *   ]
   *
   * @param \Drupal\farm_financial_mgmt\Entity\DepreciableAssetInterface $asset
   *   The asset to value.
   * @param int|null $through_year
   *   The balance-sheet as-of year for the book value (accumulated depreciation
   *   through this year). NULL = full-life residual.
   */
  public function value(DepreciableAssetInterface $asset, ?int $through_year = NULL): array;

}
