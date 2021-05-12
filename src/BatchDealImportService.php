<?php

namespace Drupal\amazon_product_widget;

use Drupal\Core\Site\Settings;

/**
 * Class BatchDealImportService.
 *
 * @package Drupal\amazon_product_widget
 */
class BatchDealImportService {

  /**
   * Imports deals into storage from CSV.
   *
   * @param string $filename
   *   Filename from where to import.
   * @param int $total
   *   The total entries in the CSV file.
   * @param $context
   *   The context.
   */
  public static function importChunked(string $filename, int $total, &$context) {
    /** @var \Drupal\amazon_product_widget\DealFeedService $dealFeedService */
    $dealFeedService = \Drupal::service('amazon_product_widget.deal_feed_service');
    $maxProcessingTime = $dealFeedService->getMaxProcessingTime();

    // How many imports to do per call to DealFeedService::import().
    $importsPerRound = Settings::get('amazon_product_widget.deals.imports_per_round', 1000);

    if (!isset($context['sandbox']['filename'])) {
      $context['sandbox']['filename']  = $filename;
      $context['sandbox']['total']     = $total;
      $context['sandbox']['processed'] = 0;
      $context['sandbox']['errors']    = 0;
    }

    $timeStart = time();
    while (TRUE) {
      $state = $dealFeedService->import(
        $context['sandbox']['filename'],
        $context['sandbox']['processed'],
        $importsPerRound
      );

      $context['sandbox']['processed'] += $state->processed;
      $context['results']['processed']  = $state->processed;
      $context['sandbox']['errors']    += $state->errors;
      $context['results']['errors']     = $context['sandbox']['errors'];

      if ($state->finished || $context['sandbox']['errors'] >= $dealFeedService->getMaxDealImportErrors()) {
        $context['finished'] = 1;
        break;
      }

      $timeCurrent = time();
      if ($timeCurrent - $timeStart >= $maxProcessingTime) {
        $batch = &batch_get();
        $batch_next_set = $batch['current_set'] + 1;
        $batch_set = &$batch['sets'][$batch_next_set];
        $batch_set['operations'][] = [
          '\Drupal\amazon_product_widget\BatchDealImportService::importChunked',
        ];

        $batch_set['total']  = $batch_set['count'] = 1;
        $context['finished'] = $context['sandbox']['processed'] / $context['sandbox']['total'];

        $context['message'] = t('Processed @processed out of @total entries with @errors errors.', [
          '@processed' => $context['sandbox']['processed'],
          '@total'     => $context['sandbox']['total'],
          '@errors'    => $context['sandbox']['errors'],
        ]);

        _batch_populate_queue($batch, $batch_next_set);

        break;
      }
    }
  }

  /**
   * Finishes the import.
   *
   * @param bool $success
   *   Success.
   * @param array $results
   *   The results array.
   * @param array $operations
   *   The operations array.
   */
  public static function finishImport(bool $success, array $results, array $operations) {
    /** @var \Drupal\amazon_product_widget\DealFeedService $dealFeedService */
    $dealFeedService = \Drupal::service('amazon_product_widget.deal_feed_service');
    /** @var \Drupal\Core\Messenger\MessengerInterface $messenger */
    $messenger = \Drupal::messenger();

    if ($results['errors'] >= $dealFeedService->getMaxDealImportErrors()) {
      $messenger->addMessage(
        t('Import stopped due to too many invalid deals. Got @errors, max is @max.', [
          '@errors' => $results['errors'],
          '@max' => $dealFeedService->getMaxDealImportErrors(),
        ])
      );
    }
    else {
      $messenger->addMessage(
        t('Import finished with @errors errors. Maximum is @max', [
          '@errors' => $results['errors'],
          '@max' => $dealFeedService->getMaxDealImportErrors(),
        ]),
      );
    }
  }

}
