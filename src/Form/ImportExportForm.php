<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\farm_financial_mgmt\Service\Export\CsvImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Export (CPA / QuickBooks CSV) and own-format import UI (Task 4.4).
 */
class ImportExportForm extends FormBase {

  public function __construct(
    protected CsvImporter $csvImporter,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('farm_financial_mgmt.csv_importer'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'farm_financial_mgmt_import_export';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['export'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Export'),
    ];
    $form['export']['year'] = [
      '#type' => 'number',
      '#title' => $this->t('Reporting year'),
      '#description' => $this->t('Leave blank to export all years.'),
      '#min' => 2000,
      '#max' => 2100,
    ];
    $form['export']['cpa'] = [
      '#type' => 'submit',
      '#value' => $this->t('Download CPA / backup CSV'),
      '#submit' => ['::exportCpa'],
    ];
    $form['export']['qb'] = [
      '#type' => 'submit',
      '#value' => $this->t('Download QuickBooks CSV'),
      '#submit' => ['::exportQuickbooks'],
    ];

    $form['import'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Import'),
      '#description' => $this->t("Upload a CSV previously exported by this module (round-trip / restore). Bank-statement and third-party formats are not supported."),
    ];
    $form['import']['file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('CSV file'),
      // Transient upload (read once, then discarded); private:// is not
      // guaranteed configured on a given install.
      '#upload_location' => 'temporary://financial-import',
      '#upload_validators' => ['FileExtension' => ['extensions' => 'csv']],
    ];
    $form['import']['create_categories'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create categories that do not exist'),
      '#default_value' => TRUE,
    ];
    $form['import']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#submit' => ['::importSubmit'],
    ];

    return $form;
  }

  /**
   * Export submit: redirect to the CPA CSV download.
   */
  public function exportCpa(array &$form, FormStateInterface $form_state): void {
    $this->redirectExport($form_state, 'farm_financial_mgmt.export.cpa_csv');
  }

  /**
   * Export submit: redirect to the QuickBooks CSV download.
   */
  public function exportQuickbooks(array &$form, FormStateInterface $form_state): void {
    $this->redirectExport($form_state, 'farm_financial_mgmt.export.quickbooks_csv');
  }

  /**
   * Shared export redirect carrying the year filter.
   */
  protected function redirectExport(FormStateInterface $form_state, string $route): void {
    $year = $form_state->getValue('year');
    $options = ($year !== NULL && $year !== '') ? ['query' => ['year' => (int) $year]] : [];
    $form_state->setRedirect($route, [], $options);
  }

  /**
   * Import submit: run the importer on the uploaded file.
   */
  public function importSubmit(array &$form, FormStateInterface $form_state): void {
    $fids = $form_state->getValue('file');
    if (empty($fids)) {
      $this->messenger()->addError($this->t('Please choose a CSV file to import.'));
      return;
    }
    $file = $this->entityTypeManager->getStorage('file')->load(reset($fids));
    if (!$file) {
      $this->messenger()->addError($this->t('The uploaded file could not be read.'));
      return;
    }
    $contents = file_get_contents($file->getFileUri());
    $stats = $this->csvImporter->import($contents, (bool) $form_state->getValue('create_categories'));

    $this->messenger()->addStatus($this->t('Imported @t transactions and @l lines (@c categories, @p contacts created).', [
      '@t' => $stats['transactions'],
      '@l' => $stats['lines'],
      '@c' => $stats['categories_created'],
      '@p' => $stats['contacts_created'],
    ]));
    foreach (array_slice($stats['warnings'], 0, 20) as $warning) {
      $this->messenger()->addWarning($warning);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Per-button #submit handlers do the work.
  }

}
