<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Attaches the currency toolbar icon site-wide.
 */
class ToolbarHooks {

  /**
   * Implements hook_preprocess_toolbar().
   */
  #[Hook('preprocess_toolbar')]
  public function preprocessToolbar(array &$variables): void {
    $variables['#attached']['library'][] = 'farm_financial_mgmt/toolbar';
  }

}
