<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Editor for the year → §179 cap / bonus % limits (Phase 5).
 *
 * These are the most time-volatile numbers in the module: set by law each tax
 * year and changed annually (the bonus phase-down especially). The engine
 * deliberately refuses to guess an unconfigured year (it surfaces $0/0% with a
 * notice); this form is the operator's in-app path to supply the answer, so the
 * module stays current without a code/config edit. A year left blank is treated
 * as unset — the engine's loud-degradation path, not a silent zero.
 */
class DepreciationLimitsForm extends ConfigFormBase {

  /**
   * The limits config object name.
   */
  protected const CONFIG = 'farm_financial_mgmt.depreciation_limits';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'farm_financial_mgmt_depreciation_limits';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $years = $this->config(self::CONFIG)->get('years') ?? [];
    $current = (int) date('Y');

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Section 179 maximum deduction and bonus depreciation %% are set by law each tax year and change annually. Leave a year blank to have the engine treat it as $0 / 0%% with a surfaced notice, rather than guessing from a stale year. Update these as the IRS publishes each year.') . '</p>',
    ];

    $form['years'] = [
      '#type' => 'table',
      '#header' => [$this->t('Tax year'), $this->t('§179 maximum'), $this->t('Bonus %')],
    ];
    for ($year = $current - 3; $year <= $current + 6; $year++) {
      $form['years'][$year]['year'] = ['#markup' => (string) $year];
      $form['years'][$year]['section_179_cap'] = [
        '#type' => 'number',
        '#min' => 0,
        '#step' => 1,
        '#title' => $this->t('§179 cap for @y', ['@y' => $year]),
        '#title_display' => 'invisible',
        '#default_value' => $years[$year]['section_179_cap'] ?? '',
      ];
      $form['years'][$year]['bonus_pct'] = [
        '#type' => 'number',
        '#min' => 0,
        '#max' => 100,
        '#title' => $this->t('Bonus % for @y', ['@y' => $year]),
        '#title_display' => 'invisible',
        '#default_value' => $years[$year]['bonus_pct'] ?? '',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $rows = $form_state->getValue('years') ?? [];
    // Preserve years outside the edited window; overwrite those in it.
    $years = $this->config(self::CONFIG)->get('years') ?? [];
    foreach ($rows as $year => $row) {
      $cap = $row['section_179_cap'] ?? '';
      $bonus = $row['bonus_pct'] ?? '';
      if ($cap === '' && $bonus === '') {
        // Blank both → unset, so the engine degrades loudly for that year.
        unset($years[$year]);
        continue;
      }
      $years[(int) $year] = [
        'section_179_cap' => (int) ($cap !== '' ? $cap : 0),
        'bonus_pct' => (int) ($bonus !== '' ? $bonus : 0),
      ];
    }
    ksort($years);
    $this->config(self::CONFIG)->set('years', $years)->save();
    parent::submitForm($form, $form_state);
  }

}
