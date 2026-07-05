<?php

declare(strict_types=1);

namespace Drupal\farm_financial_mgmt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\farm_financial_mgmt\Service\Export\CsvExporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams financial CSV exports (Phase 4).
 */
class FinancialExportController extends ControllerBase {

  public function __construct(
    protected CsvExporter $csvExporter,
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('farm_financial_mgmt.csv_exporter'),
      $container->get('request_stack'),
    );
  }

  /**
   * CPA / own-format CSV download (Task 4.1).
   */
  public function cpaCsv(): Response {
    $year = $this->requestStack->getCurrentRequest()->query->get('year');
    $filters = ($year !== NULL && $year !== '') ? ['year' => (int) $year] : [];
    $csv = $this->csvExporter->toCsv($filters);

    return $this->download($csv, 'financial-export' . ($year ? '-' . (int) $year : ''));
  }

  /**
   * QuickBooks (QBO-compatible) CSV download (Task 4.2).
   */
  public function quickbooksCsv(): Response {
    $year = $this->requestStack->getCurrentRequest()->query->get('year');
    $filters = ($year !== NULL && $year !== '') ? ['year' => (int) $year] : [];
    return $this->download($this->csvExporter->toQbCsv($filters), 'quickbooks-export' . ($year ? '-' . (int) $year : ''));
  }

  /**
   * Liabilities own-format CSV download (Phase 5.9 backup).
   */
  public function liabilitiesCsv(): Response {
    return $this->download($this->csvExporter->toLiabilityCsv(), 'financial-liabilities');
  }

  /**
   * Depreciable-assets own-format CSV download (Phase 5.9 backup).
   */
  public function depreciableAssetsCsv(): Response {
    return $this->download($this->csvExporter->toDepreciableAssetCsv(), 'financial-depreciable-assets');
  }

  /**
   * Wraps a CSV string in a download response.
   */
  protected function download(string $csv, string $basename): Response {
    $response = new Response($csv);
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $basename . '.csv"');
    return $response;
  }

}
