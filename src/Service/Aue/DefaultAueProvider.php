<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service\Aue;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\asset\Entity\AssetInterface;

/**
 * Default AUE provider: coefficients from animal_stage, presence from lifecycle.
 *
 * AUE follows NRCS-style animal-unit weights keyed to the five animal_stage
 * values (farm_breeding). Presence uses birthdate/created for arrival and, since
 * farm_asset_termination is not installed here, the farm_animal_disposition
 * departure signal (disposition off-farm + disposition_date) or the asset's
 * archived timestamp; absent any departure signal the animal is treated as
 * present through the period end.
 */
class DefaultAueProvider implements AueProviderInterface {

  /**
   * AUE coefficient per animal_stage (NRCS-style; mature cow = 1.0).
   */
  protected const AUE_MAP = [
    'mature_female' => 1.0,
    'intact_male' => 1.3,
    'immature_female' => 0.7,
    'castrated_male' => 0.7,
    'juvenile' => 0.6,
  ];

  /**
   * Dispositions that mean the animal has left the herd (departure signal).
   */
  protected const OFF_FARM = ['dead', 'sold', 'missing', 'historical', 'reference_only', 'leased_out', 'returned'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getAnimalAue(AssetInterface $animal): float {
    $stage = ($animal->hasField('animal_stage') && !$animal->get('animal_stage')->isEmpty())
      ? $animal->get('animal_stage')->value : NULL;
    return self::AUE_MAP[$stage] ?? 1.0;
  }

  /**
   * {@inheritdoc}
   */
  public function getPresenceDays(AssetInterface $animal, int $start, int $end): int {
    $arrival = $this->arrival($animal);
    $departure = $this->departure($animal) ?? $end;
    $lo = max($start, $arrival);
    $hi = min($end, $departure);
    if ($hi <= $lo) {
      return 0;
    }
    return (int) floor(($hi - $lo) / 86400);
  }

  /**
   * {@inheritdoc}
   */
  public function getHerd(int $start, int $end): array {
    $storage = $this->entityTypeManager->getStorage('asset');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'animal')
      ->execute();
    $herd = [];
    foreach ($storage->loadMultiple($ids) as $animal) {
      if ($this->getPresenceDays($animal, $start, $end) > 0) {
        $herd[] = (int) $animal->id();
      }
    }
    return $herd;
  }

  /**
   * Arrival timestamp: birthdate if set, else the asset creation time.
   */
  protected function arrival(AssetInterface $animal): int {
    if ($animal->hasField('birthdate') && !$animal->get('birthdate')->isEmpty()) {
      return (int) $animal->get('birthdate')->value;
    }
    return (int) $animal->getCreatedTime();
  }

  /**
   * Departure timestamp, or NULL if the animal is still present.
   */
  protected function departure(AssetInterface $animal): ?int {
    if ($animal->hasField('disposition') && !$animal->get('disposition')->isEmpty()
      && in_array($animal->get('disposition')->value, self::OFF_FARM, TRUE)) {
      if ($animal->hasField('disposition_date') && !$animal->get('disposition_date')->isEmpty()) {
        return (int) $animal->get('disposition_date')->value;
      }
    }
    if ($animal->hasField('archived') && (bool) $animal->get('archived')->value) {
      if ($animal->hasField('last_archived') && !$animal->get('last_archived')->isEmpty()) {
        return (int) $animal->get('last_archived')->value;
      }
    }
    return NULL;
  }

}
