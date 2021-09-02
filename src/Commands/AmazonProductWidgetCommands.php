<?php

namespace Drupal\amazon_product_widget\Commands;

use Drupal\amazon_product_widget\BatchProductMapUpdateService;
use Drupal\amazon_product_widget\DealFeedService;
use Drupal\amazon_product_widget\Exception\AmazonApiDisabledException;
use Drupal\amazon_product_widget\Exception\AmazonRequestLimitReachedException;
use Drupal\amazon_product_widget\ProductService;
use Drupal\amazon_product_widget\ProductUsageService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
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
   * DealFeedService.
   *
   * @var \Drupal\amazon_product_widget\DealFeedService
   */
  protected $dealFeedService;

  /**
   * ProductUsageService.
   *
   * @var \Drupal\amazon_product_widget\ProductUsageService
   */
  protected $productUsage;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * AmazonProductWidgetCommands constructor.
   *
   * @param \Drupal\amazon_product_widget\ProductService $productService
   *   ProductService.
   * @param \Drupal\Core\Queue\QueueFactory $queue
   *   QueueFactory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queueWorker
   *   QueueWorkerManagerInterface.
   * @param \Drupal\amazon_product_widget\DealFeedService $dealFeedService
   *   Deal feed service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   */
  public function __construct(ProductService $productService, QueueFactory $queue, QueueWorkerManagerInterface $queueWorker, DealFeedService $dealFeedService, ProductUsageService $productUsage, EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct();
    $this->productService = $productService;
    $this->queue = $queue;
    $this->queueWorker = $queueWorker;
    $this->dealFeedService = $dealFeedService;
    $this->productUsage = $productUsage;
    $this->entityTypeManager = $entityTypeManager;
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
        $count = count($asins);
        $this->output()->writeln("$count products have been queued for renewal.");
      }
      catch (\Exception $exception) {
        $this->output()->writeln("An unrecoverable error has occurred:");
        $this->output()->writeln($exception->getMessage());
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
        catch (RequeueException $exception) {
          $this->io()->writeln("Update limit reached. Run the command again to update more products.");
        }
        catch (SuspendQueueException $exception) {
          $queue->releaseItem($item);
          break;
        }
        catch (\Exception $exception) {
          watchdog_exception('amazon_product_widget', $exception);
        }
      }

      if ($this->productService->getProductStore()->hasStaleData()) {
        $outdated = $this->productService->getProductStore()->getOutdatedKeysCount();
        $this->output()->writeln("There are $outdated products still remaining.");
      }
      else {
        $this->output()->writeln("All items have been processed.");
      }
    }
    else {
      $this->output()->writeln("There is nothing to update.");
    }
  }

  /**
   * Gets the number of products due for renewal.
   *
   * @command apw:stale
   */
  public function itemsDueForRenewal() {
    $outdated = $this->productService->getProductStore()->getOutdatedKeysCount();
    $this->output()->writeln("There are $outdated products waiting for renewal.");
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
      if (isset($productData[$asin]['overrides']) && $productData[$asin]['overrides'] !== FALSE) {
        $this->output()->writeln("The following overrides were found for: $asin");
        $this->output()->writeln(var_export($productData[$asin]['overrides'], TRUE));
      }
      else {
        $this->output()->writeln("No overrides for ASIN $asin have been found.");
      }
    }
    catch (\Exception $exception) {
      $this->output()->writeln("An unexpected error has occurred:");
      $this->output()->writeln($exception->getMessage());
    }
  }

  /**
   * Resets all renewal times so all products are stale.
   *
   * @command apw:reset-all-renewals
   */
  public function resetAllRenewals() {
    $this->productService->getProductStore()->resetAll();
    $this->output()->writeln("All products have been marked for renewal.");
  }

  /**
   * Prints the number of active deals.
   *
   * @command apw:deals:active-deals
   */
  public function dealsCount() {
    $count = $this->dealFeedService->getDealStore()->getActiveCount();
    $this->output()->writeln("There are $count active deals in the database.");
  }

  /**
   * Updates the deals in the storage.
   *
   * @param string $path
   *   Path to the CSV to be used to update the store, otherwise calls the API.
   *
   * @command apw:deals:update
   */
  public function dealsUpdate($path = NULL) {
    try {
      if ($path) {
        $importPath = $path;
      }
      else {
        $importPath = $this->dealFeedService->downloadDealsCsv();
      }

      if (!file_exists($importPath) || is_dir($importPath)) {
        $this->output()->writeln("Path '$importPath' is either a directory or does not exist.");
        return;
      }

      $this->output()->writeln("File to import: $importPath");
      $this->output()->writeln('Now importing...');

      $totalEntries = count(file($importPath)) - 1;
      $start = 0;
      $entriesPerRound = 5000;
      while (true) {
        $state  = $this->dealFeedService->import($importPath, $start, $entriesPerRound);
        $start += $state->processed;
        $errors = $state->errors;

        $progress = round($start / $totalEntries * 100, 2);
        $this->output()->writeln("Processed $start / $totalEntries (" . $progress . "%) with $errors errors.");
      }
    }
    catch (\Throwable $exception) {
      $this->output()->writeln("Error occurred while importing deals:");
      $this->output()->writeln($exception->getMessage());
    }
  }

  /**
   * Gets deal information for an ASIN.
   *
   * @param string $asin
   *   ASIN.
   *
   * @command apw:deals:info
   */
  public function dealInfo(string $asin) {
    $deal = $this->dealFeedService->getDealStore()->getByAsin($asin);
    $deal = $this->dealFeedService->getDealStore()->prettifyDeal($deal);
    $this->output()->writeln("Deal information for $asin:");
    $this->output()->writeln(var_export($deal, TRUE));
  }

  /**
   * Gets product information for the given ASIN.
   *
   * @param string $asin
   *   ASIN.
   *
   * @option renew
   *   Force an update and get the product data drectly from Amazon.
   *
   * @command apw:product-info
   */
  public function getProductInfo(string $asin, array $options = ['renew' => FALSE]) {
    try {
      $productData = $this->productService->getProductData([$asin], $options['renew']);
      if ($productData[$asin] === FALSE) {
        $this->io()->writeln("No product information could be retrieved for ASIN $asin.");
      }
      else {
        $this->io()->writeln("Got the following product information for ASIN $asin:");
        $this->io()->writeln(var_export($productData[$asin], TRUE));
      }
    }
    catch (AmazonApiDisabledException $exception) {
      $this->io()->error("The Amazon API is disabled by configuration.");
    }
    catch (AmazonRequestLimitReachedException $exception) {
      $this->io()->error("You have reached the Amazon API request limit.");
    }
  }

  /**
   * Updates the Node - ASIN map.
   *
   * @command apw:update-asin-map
   */
  public function updateAsinMap() {
    $batch = [
      'title'        => t('Updating Product Node mapping'),
      'init_message' => t('Preparing to sync @count nodes...'),
      'finished'     => [BatchProductMapUpdateService::class, 'finished'],
    ];

    try {
      $nodeIds = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->execute();
      $totalNodes = count($nodeIds  );
      $nodeIdsChunked = array_chunk($nodeIds, 20);
      foreach ($nodeIdsChunked as $chunk) {
        $batch['operations'][] = [
          [BatchProductMapUpdateService::class, 'update'], [
            $chunk,
            $totalNodes,
          ],
        ];
      }

      batch_set($batch);
      $batch =& batch_get();
      $batch['progressive'] = FALSE;

      drush_backend_batch_process();
    }
    catch (\Exception $exception) {
      watchdog_exception('amazon_product_widget', $exception);
    }
  }

}
