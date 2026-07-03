<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Global financial settings: currency (ISO 4217) and accounting method.
 *
 * Currency is configurable-global and applied uniformly — a single currency, no
 * per-transaction currency and no exchange rates (SPEC §3, §7).
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The settings config object name.
   */
  protected const SETTINGS = 'farm_financial_mgmt.settings';

  /**
   * Common ISO 4217 currency codes offered in the select.
   */
  protected const CURRENCIES = [
    'USD' => 'USD — US Dollar',
    'CAD' => 'CAD — Canadian Dollar',
    'MXN' => 'MXN — Mexican Peso',
    'EUR' => 'EUR — Euro',
    'GBP' => 'GBP — British Pound',
    'AUD' => 'AUD — Australian Dollar',
    'NZD' => 'NZD — New Zealand Dollar',
    'BRL' => 'BRL — Brazilian Real',
    'ZAR' => 'ZAR — South African Rand',
    'INR' => 'INR — Indian Rupee',
  ];

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'farm_financial_mgmt_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::SETTINGS);

    $form['currency'] = [
      '#type' => 'select',
      '#title' => $this->t('Currency'),
      '#description' => $this->t('ISO 4217 currency code applied uniformly to all amounts. Single-currency — no multi-currency or exchange rates.'),
      '#options' => self::CURRENCIES,
      '#default_value' => $config->get('currency') ?: 'USD',
      '#required' => TRUE,
    ];

    $form['accounting_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Accounting method'),
      '#description' => $this->t('Cash-basis keys reports off the payment date. Accrual is reserved for later refinement.'),
      '#options' => [
        'cash' => $this->t('Cash'),
        'accrual' => $this->t('Accrual'),
      ],
      '#default_value' => $config->get('accounting_method') ?: 'cash',
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::SETTINGS)
      ->set('currency', $form_state->getValue('currency'))
      ->set('accounting_method', $form_state->getValue('accounting_method'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
