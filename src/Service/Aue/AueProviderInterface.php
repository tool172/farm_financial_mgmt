<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Service\Aue;

use Drupal\asset\Entity\AssetInterface;

/**
 * Supplies Animal Unit Equivalent (AUE) weights and presence for allocation.
 *
 * Decoupled behind an interface so the AUE source is swappable (SPEC §8): the
 * DefaultAueProvider reads animal_stage + asset lifecycle; a GrazingAueProvider
 * can use farm_grazing_rotation_plan move-time snapshots when that module is
 * present, selected by overriding the service alias. The financial module has
 * no hard dependency on the grazing module.
 */
interface AueProviderInterface {

  /**
   * The AUE coefficient for one animal (e.g. mature cow = 1.0).
   */
  public function getAnimalAue(AssetInterface $animal): float;

  /**
   * Days the animal was present on farm within [start, end] (unix seconds).
   */
  public function getPresenceDays(AssetInterface $animal, int $start, int $end): int;

  /**
   * Asset ids of the herd sharing the allocation pool over [start, end].
   *
   * @return int[]
   */
  public function getHerd(int $start, int $end): array;

}
