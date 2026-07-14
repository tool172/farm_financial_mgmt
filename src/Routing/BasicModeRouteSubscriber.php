<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Blocks the capital-entity collection routes in Basic mode.
 *
 * The depreciable-asset and liability list routes are auto-generated with an
 * admin-permission requirement (not the entity access handler), so add the
 * Basic-mode access check to them here to keep Basic mode airtight.
 */
class BasicModeRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $access = '\Drupal\farm_financial_mgmt\Controller\FinancialReportController::basicModeAccess';
    foreach (['entity.depreciable_asset.collection', 'entity.financial_liability.collection'] as $name) {
      if ($route = $collection->get($name)) {
        $route->setRequirement('_custom_access', $access);
      }
    }
  }

}
