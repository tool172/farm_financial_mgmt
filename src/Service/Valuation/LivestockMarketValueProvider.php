<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service\Valuation;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\farm_financial_mgmt\Entity\DepreciableAssetInterface;

/**
 * Values breeding livestock at market, decorating BookValueProvider (5.5).
 *
 * Basis and the entered-appraisal path come straight from BookValueProvider (so
 * the single-sourced book value is preserved). For a depreciable asset linked to
 * a cattle animal with no operator appraisal, market is sourced from the same
 * farm_cattle_prices feed the ranch dashboards use, via farm_ranch_ui's
 * RanchEconomics::projectedValue() — a SOFT integration (no hard dependency;
 * mirrors how ranch_ui already calls into this module), so the module degrades
 * cleanly when either module is absent.
 *
 * Staleness is surfaced, not hidden: the market figure carries the as-of date of
 * the freshest USDA report behind it, so the balance sheet can flag a price
 * built on stale data. When the feed can't value the animal (missing weight,
 * unclassable, no price for the class, gapped feed) it falls back to the
 * BookValueProvider result rather than inventing a number.
 */
class LivestockMarketValueProvider implements AssetValuationProviderInterface {

  public function __construct(
    protected BookValueProvider $bookValueProvider,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function value(DepreciableAssetInterface $asset, ?int $through_year = NULL): array {
    $base = $this->bookValueProvider->value($asset, $through_year);

    // An operator appraisal is the operator's explicit market call — respect it.
    if ($base['market_source'] === 'appraised') {
      return $base;
    }

    $feed = $this->livestockMarket($asset);
    if ($feed !== NULL) {
      $base['market'] = $feed['value'];
      $base['market_as_of'] = $feed['as_of'];
      $base['market_source'] = 'market_feed';
    }
    // Else the BookValueProvider book fallback stands (defined, not null).
    return $base;
  }

  /**
   * Market value of the linked cattle animal from the price feed, or NULL.
   *
   * @return array|null
   *   ['value' => float, 'as_of' => int|null], or NULL when unavailable.
   */
  protected function livestockMarket(DepreciableAssetInterface $asset): ?array {
    if (!$this->moduleHandler->moduleExists('farm_ranch_ui')
      || !$this->moduleHandler->moduleExists('farm_cattle_prices')) {
      return NULL;
    }
    if ($asset->get('farm_asset')->isEmpty()) {
      return NULL;
    }
    $animal = $asset->get('farm_asset')->entity;
    if ($animal === NULL || $animal->getEntityTypeId() !== 'asset' || $animal->bundle() !== 'animal') {
      return NULL;
    }

    // Soft calls (resolved lazily to avoid a hard cross-module dependency).
    $economics = \Drupal::service('farm_ranch_ui.economics');
    $value = $economics->projectedValue($animal);
    if ($value === NULL) {
      return NULL;
    }
    return ['value' => (float) $value, 'as_of' => $this->latestPriceDate()];
  }

  /**
   * The freshest USDA report date behind the price feed, or NULL.
   *
   * A soft read of farm_cattle_prices' records table, guarded by existence, so
   * the balance sheet can state "market values as of {date}".
   */
  protected function latestPriceDate(): ?int {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('farm_cattle_prices_records')) {
      return NULL;
    }
    $ts = $database->select('farm_cattle_prices_records', 'r')
      ->fields('r', ['report_date'])
      ->orderBy('report_date', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $ts !== FALSE && $ts !== NULL ? (int) $ts : NULL;
  }

}
