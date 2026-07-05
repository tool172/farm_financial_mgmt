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

    $form['tax_planning_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable tax planning (Schedule F)'),
      '#description' => $this->t('Turns the Tax Summary report on or off. Category tax mappings are retained either way, so this is a display toggle, not a data change.'),
      '#default_value' => $config->get('tax_planning_enabled') ?? TRUE,
    ];

    $form['balance_sheet'] = [
      '#type' => 'details',
      '#title' => $this->t('Balance sheet — entered figures'),
      '#description' => $this->t('The balance sheet is asset-side-complete (the depreciation schedule and valuations feed it automatically). Cash cannot be derived from an income/expense ledger, so it is entered here.'),
      '#open' => TRUE,
    ];
    $form['balance_sheet']['cash_position'] = [
      '#type' => 'number',
      '#step' => '0.01',
      '#title' => $this->t('Cash position'),
      '#description' => $this->t('Current cash / bank balance. An entered figure, not derived from the ledger.'),
      '#default_value' => $config->get('cash_position') ?? 0,
    ];
    $form['balance_sheet']['cash_as_of'] = [
      '#type' => 'date',
      '#title' => $this->t('Cash as-of date'),
      '#description' => $this->t('The date the cash position above was taken.'),
      '#default_value' => $config->get('cash_as_of') ?: '',
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
      ->set('tax_planning_enabled', (bool) $form_state->getValue('tax_planning_enabled'))
      ->set('cash_position', (float) $form_state->getValue('cash_position'))
      ->set('cash_as_of', $form_state->getValue('cash_as_of') ?: '')
      ->save();
    // Rebuild menu links so the Tax Summary link appears/disappears immediately.
    \Drupal::service('plugin.manager.menu.link')->rebuild();
    parent::submitForm($form, $form_state);
  }

}
