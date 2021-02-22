<?php

namespace Drupal\amazon_product_widget\Commands;

use Drupal\amazon_product_widget\ProductService;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drush\Commands\DrushCommands;

/**
 * Class AmazonProductWidgetCommands.
 *
 * Provides custom drush commands for queueing and updating product
 * information.
 *
 * @package Drupal\amazon_product_widget\Commands
 */
class AmazonProductWidgetCommands extends DrushCommands {

  /**
   * ProductService.
   *
   * @var \Drupal\amazon_product_widget\ProductService
   */
  protected $productService;

  /**
   * Queue.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * QueueWorkerManagerInterface.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueWorker;

  /**
   * AmazonProductWidgetCommands constructor.
   *
   * @param \Drupal\amazon_product_widget\ProductService $productService
   *   ProductService.
   * @param \Drupal\Core\Queue\QueueFactory $queue
   *   QueueFactory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queueWorker
   *   QueueWorkerManagerInterface.
   */
  public function __construct(ProductService $productService, QueueFactory $queue, QueueWorkerManagerInterface $queueWorker) {
    $this->productService = $productService;
    $this->queue = $queue;
    $this->queueWorker = $queueWorker;
  }

  /**
   * Queues all products for renewal.
   *
   * @command apw:queue-product-renewal
   */
  public function queueProductRenewal() {
    $asins = amazon_product_widget_get_all_asins();

    if (!empty($asins)) {
      try {
        $this->productService->queueProductRenewal($asins);
        $this->io()->note(count($asins) . " have been queued for renewal.");
      }
      catch (\Exception $exception) {
        $this->io()->warning("An unrecoverable error has occurred:");
        $this->io()->warning($exception->getMessage());
      }
    }
  }

  /**
   * Updates all product data.
   *
   * @command apw:run-product-renewal
   *
   * @throws \Exception
   */
  public function updateProductData() {
    $queue = $this->queue->get('amazon_product_widget.product_data_update');
    if ($this->productService->getProductStore()->hasStaleData()) {
      $this->productService->queueProductRenewal();

      /** @var \Drupal\amazon_product_widget\Plugin\QueueWorker\ProductDataUpdate $queueWorker */
      $queueWorker = $this->queueWorker->createInstance('amazon_product_widget.product_data_update');
      while ($item = $queue->claimItem()) {
        try {
          $queueWorker->processItem($item->data);
          $queue->deleteItem($item);
        }
        catch (SuspendQueueException $exception) {
          $queue->releaseItem($item);
          break;
        }
        catch (\Exception $exception) {
          $this->io()->warning("An exception has occurred:");
          $this->io()->warning($exception->getMessage());
        }
      }
      $this->io()->note("All items have been processed.");
    }
    else {
      $this->io()->note("There is nothing to update.");
    }
  }

  /**
   * Gets the number of products due for renewal.
   *
   * @command apw:stale
   */
  public function itemsDueForRenewal() {
    // Default of getOutdatedKeys() is 100, since we want to show all that are
    // stale we use 1M as a safe bet that nobody will have this many products
    // stored.
    $outdated = count($this->productService->getProductStore()->getOutdatedKeys(1000000));
    $this->io()->note("There are " . $outdated . " products waiting for renewal.");
  }

  /**
   * Gets overrides for a specific Amazon product.
   *
   * @param string $asin
   *   The ASIN to get the overrides for.
   *
   * @command apw:overrides
   * @usage apw:overrides AE91ECBUDA
   */
  public function getOverridesForProduct($asin) {
    try {
      $productData = $this->productService->getProductData([$asin]);
      if (isset($productData[$asin]['overrides'])) {
        $this->io()->note("The following overrides were found for: $asin");
        $this->io()->note(var_export($productData[$asin]['overrides'], TRUE));
      }
    }
    catch (\Exception $exception) {
      $this->io()->warning("An unexpected error has occurred:");
      $this->io()->warning($exception->getMessage());
    }
  }
}