<?php

namespace Drupal\amazon_product_widget;

/**
 * Handles batch update of product usage table.
 *
 * @package Drupal\amazon_product_widget
 */
class BatchProductMapUpdateService {

  /**
   * Updates the nodes in the
   *
   * @param array $nodeIds
   *   The IDs of the nodes to update.
   * @param int $total
   *   Total number of nodes to process.
   * @param $context
   *   The context.
   */
  public static function update(array $nodeIds, int $total, &$context) {
    $entityTypeManager = \Drupal::entityTypeManager();
    /** @var \Drupal\amazon_product_widget\ProductUsageService $usageService */
    $usageService = \Drupal::service('amazon_product_widget.usage');

    try {
      $nodeStorage = $entityTypeManager->getStorage('node');

      foreach ($nodeIds as $nodeId) {
        $node = $nodeStorage->load($nodeId);
        if (!$node) {
          continue;
        }
        $usageService->update($node);
        $context['results']['processed'] += 1;
        $context['message'] = t('Processed @count out of a total of @total nodes.', [
          '@count' => $context['results']['processed'],
          '@total' => $total,
        ]);
      }
    }
    catch (\Exception $exception) {
      watchdog_exception('amazon_product_widget', $exception);
    }
  }

  /**
   * Called when the batch operation is finished.
   *
   * @param bool $success
   *   Success.
   * @param array $results
   *   Results.
   * @param array $operations
   *   Operations.
   */
  public static function finish(bool $success, array $results, array $operations) {
    /** @var \Drupal\Core\Messenger\MessengerInterface $messenger */
    $messenger = \Drupal::messenger();
    if ($success) {
      $messenger->addMessage(t('Successfully processed @count items.', [
        '@count' => $results['processed'],
      ]));
    }
  }
}
