<?php

namespace Drupal\amazon_product_widget\Plugin\QueueWorker;

use Drupal\amazon_product_widget\Exception\AmazonServiceException;
use Drupal\amazon_product_widget\ProductService;
use Drupal\amazon_product_widget\ProductStore;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Updates locally cached product data via Amazon API.
 *
 * @QueueWorker(
 *   id = "amazon_product_widget.product_data_update",
 *   title = @Translation("Update amazon product data"),
 *   cron = {"time" = 300}
 * )
 */
class ProductDataUpdate extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use LoggerChannelTrait;

  /**
   * Product service.
   *
   * @var \Drupal\amazon_product_widget\ProductService
   */
  protected $productService;

  /**
   * Keep track of processed collection updates per request.
   *
   * @var array
   */
  protected static $processed = [];

  /**
   * Constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\amazon_product_widget\ProductService $product_service
   *   Product service.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, ProductService $product_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->productService = $product_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('amazon_product_widget.product_service'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($item) {
    if (isset(static::$processed[$item['collection']])) {
      // Only process items of a single collection once during a queue run.
      return;
    }
    static::$processed[$item['collection']] = TRUE;

    switch ($item['collection']) {
      case ProductStore::COLLECTION_PRODUCTS:
        $product_store = $this->productService->getProductStore();
        $outdated_asins = $product_store->getOutdatedKeys();

        try {
          $this->productService->getProductData($outdated_asins, TRUE);
          $this->getLogger('amazon_product_widget')->info('QueueWorker: Updated %number amazon products.', [
            '%number' => count($outdated_asins),
          ]);
        }
        catch (AmazonServiceException $e) {
          $this->getLogger('amazon_product_widget')->error($e->getMessage());
        }

        // Allow the queue to finish processing items when invoked multiple times -
        // without cron.
        if ($product_store->hasStaleData()) {
          throw new RequeueException();
        }
        break;

      case ProductStore::COLLECTION_SEARCH_RESULTS:
        $search_store = $this->productService->getSearchResultStore();
        $outdated_search_terms = $search_store->getOutdatedKeys();

        try {
          foreach ($outdated_search_terms as $search_term) {
            $this->productService->getSearchResults($search_term, TRUE);
          }
          $this->getLogger('amazon_product_widget')->info('QueueWorker: Updated %number search results.', [
            '%number' => count($outdated_search_terms),
          ]);
        }
        catch (AmazonServiceException $e) {
          $this->getLogger('amazon_product_widget')->error($e->getMessage());
        }
        // Allow the queue to finish processing items when invoked multiple times -
        // without cron.
        if ($search_store->hasStaleData()) {
          throw new RequeueException();
        }
        break;
    }
  }

}
