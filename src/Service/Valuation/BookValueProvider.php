<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service\Valuation;

use Drupal\farm_financial_mgmt\Entity\DepreciableAssetInterface;
use Drupal\farm_financial_mgmt\Service\DepreciationEngine;

/**
 * Default valuation: book value for basis, entered-or-book for market (5.5).
 *
 * Basis is the single-sourced book value — cost less the engine's ONE
 * authoritative accumulated-depreciation figure
 * (DepreciationEngine::bookValue(), which reads accumulatedDepreciation()) — not
 * a recomputation. This is the first consumer of that method outside the engine;
 * BookValueProvider and the eventual Form 4797 recapture both trace back to the
 * same call, so the single-source guarantee holds through the valuation layer.
 *
 * Market defaults to an operator-entered appraisal when present, else falls back
 * to book (never invented, never null). LivestockMarketValueProvider decorates
 * this to add live cattle-market pricing.
 */
class BookValueProvider implements AssetValuationProviderInterface {

  public function __construct(
    protected DepreciationEngine $depreciationEngine,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function value(DepreciableAssetInterface $asset, ?int $through_year = NULL): array {
    // Book value = cost less the single authoritative accumulated depreciation
    // through the as-of year. Raised (zero-basis) stock and land floor at 0
    // here — a defined value.
    $basis = $this->depreciationEngine->bookValue($asset, $through_year);

    // Operator-entered appraisal wins; its as-of is when it was last set.
    if (!$asset->get('market_value')->isEmpty()) {
      return [
        'basis' => $basis,
        'market' => (float) $asset->get('market_value')->value,
        'market_as_of' => (int) $asset->get('changed')->value,
        'market_source' => 'appraised',
      ];
    }

    // No market signal: fall back to book (defined, not invented).
    return [
      'basis' => $basis,
      'market' => $basis,
      'market_as_of' => NULL,
      'market_source' => 'book',
    ];
  }

}
