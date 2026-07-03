<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access handler for financial_transaction and financial_line (SPEC §6).
 *
 * view   → 'view financial transactions'
 * create/update/delete → 'manage financial transactions'
 * 'administer financial mgmt' grants everything.
 */
class FinancialAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('administer financial mgmt')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view financial transactions'),
      'update', 'delete' => AccessResult::allowedIfHasPermission($account, 'manage financial transactions'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    if ($account->hasPermission('administer financial mgmt')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return AccessResult::allowedIfHasPermission($account, 'manage financial transactions');
  }

}
