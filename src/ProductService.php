<?php

namespace Drupal\amazon_product_widget;

use Drupal\amazon\Amazon;
use Drupal\amazon_product_widget\Exception\AmazonRequestLimitReachedException;
use Drupal\amazon_product_widget\Exception\AmazonServiceUnavailableException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\State\StateInterface;

/**
 * Provides amazon product data.
 */
class ProductService {

  /**
   * Product store.
   *
   * @var \Drupal\amazon_product_widget\productStore
   */
  protected $productStore;

  /**
   * State.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Lock.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * Queue for fetching product data.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * Amazon API.
   *
   * @var \Drupal\amazon\Amazon
   */
  protected $amazonApi;

  /**
   * Amazon associates Id.
   *
   * @var string
   */
  protected $associatesId;

  /**
   * Maximum allowed requests per day (Amazon throttling).
   *
   * @var int
   */
  protected $maxRequestPerDay;

  /**
   * Maximum allowed requests per second (Amazon throttling).
   *
   * @var int
   */
  protected $maxRequestPerSecond;

  /**
   * ProductService constructor.
   *
   * @param \Drupal\amazon_product_widget\ProductStoreFactory $store_factory
   *   Product store.
   * @param \Drupal\Core\State\StateInterface $state
   *   State.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   Lock.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   Config factory.
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue.
   */
  public function __construct(ProductStoreFactory $store_factory, StateInterface $state, LockBackendInterface $lock, ConfigFactoryInterface $config, QueueInterface $queue) {
    $this->productStore = $store_factory->get(ProductStore::COLLECTION_PRODUCTS);
    $this->state = $state;
    $this->lock = $lock;
    $this->queue = $queue;

    $this->maxRequestPerDay = $config->get('amazon_product_widget.settings')->get('max_requests_per_day');
    $this->maxRequestPerSecond = $config->get('amazon_product_widget.settings')->get('max_requests_per_second');
    $this->associatesId = $config->get('amazon.settings')->get('associates_id');

    if (empty($this->maxRequestPerSecond)) {
      $this->maxRequestPerSecond = 1;
    }

    if (empty($this->maxRequestPerDay)) {
      $this->maxRequestPerDay = 8640;
    }
  }

  /**
   * Gets the product store.
   *
   * @return \Drupal\amazon_product_widget\productStore
   */
  public function getProductStore() {
    return $this->productStore;
  }

  /**
   * Gets the amazon api.
   *
   * @return \Drupal\amazon\Amazon
   *
   * @throws AmazonServiceUnavailableException
   */
  protected function getAmazonApi() {
    if (!$this->amazonApi instanceof Amazon) {
      try {
        $this->amazonApi = new Amazon($this->associatesId);
      }
      catch (\Exception $e) {
        watchdog_exception('amazon_product_widget', $e);
        throw new AmazonServiceUnavailableException('Error on initializing Amazon API, check log for more information.');
      }
    }

    return $this->amazonApi;
  }

  /**
   * Gets amazon product data.
   *
   * Since this will use amazon requests which are limited, never use this
   * method in a way where the asins are provided by anonymous users input.
   *
   * @param string[] $asins
   *   Product ASINs.
   * @param bool $renew
   *   Clear cache and fetch product data from amazon.
   *
   * @return array
   *   Associative array with ASIN-number as key, and product data as values.
   *
   * @throws \Drupal\amazon_product_widget\Exception\AmazonRequestLimitReachedException
   * @throws \Drupal\amazon_product_widget\Exception\AmazonServiceUnavailableException
   */
  public function getProductData(array $asins, $renew = FALSE) {
    $asins = array_unique($asins);
    $product_data = [];

    if ($renew) {
      $fetch_asins = $asins;
    }
    else {
      // Fetch data from the cache first.
      $product_data = $this->productStore->getMultiple($asins);
      $fetch_asins = array_diff($asins, array_keys($product_data));
    }

    if (!empty($fetch_asins)) {
      $product_data += $this->fetchAmazonProducts($fetch_asins);
    }

    return $product_data;
  }

  /**
   * Queue fetching of stale product data in the store.
   *
   * @param string[] $asins
   *   (optional) Provide ASINs which are not in the store yet, so that they
   *   get fetched too.
   *
   * @throws \Exception
   */
  public function queueProductDataRenewal(array $asins = []) {
    foreach ($asins as $asin) {
      $this->productStore->setIfNotExists($asin, FALSE, 0);
    }
    if ($this->productStore->hasStaleData()) {
      $this->queue->createItem(['collection' => ProductStore::COLLECTION_PRODUCTS]);
    }
  }

  /**
   * Fetch products directly from amazon.
   *
   * @param string[] $asins
   *   Product ASINs.
   *
   * @return array
   *   Associative array with ASIN-number as key, and product data as values.
   *   If no data was retrieved for an ASIN, then the value is FALSE.
   *
   * @throws \Drupal\amazon_product_widget\Exception\AmazonRequestLimitReachedException
   * @throws \Drupal\amazon_product_widget\Exception\AmazonServiceUnavailableException
   */
  protected function fetchAmazonProducts(array $asins) {
    $product_data = [];
    $requests_per_second_limit = min(1, 1 / $this->maxRequestPerSecond);
    $expected_lock_time = $requests_per_second_limit * count($asins) / 10;
    if (!$this->lock->acquire(__METHOD__, min(30, $expected_lock_time + 5))) {
      throw new AmazonRequestLimitReachedException('Amazon API currently blocked by another process.');
    }

    $fetch_asins = $asins;
    while ($fetch_asins) {
      // Amazon API allows querying 10 products per single request.
      $asins_chunk = array_splice($fetch_asins, 0, 10);
      $amazon_data = [];

      if ($this->getTodaysRequestCount() >= $this->getMaxRequestsPerDay()) {
        throw new AmazonRequestLimitReachedException('Maximum number of requests per day to Amazon API reached.');
      }

      $this->increaseTodaysRequestCount();
      $result = $this->getAmazonApi()->lookup($asins_chunk, ['Offers']);

      foreach ($result as $item) {
        $product_available = FALSE;
        $price = NULL;
        $currency = NULL;

        if (!empty($item->Offers->Offer->OfferListing->Price)) {
          $product_available = TRUE;
          $price = (string) $item->Offers->Offer->OfferListing->Price->Amount;
          $currency = (string) $item->Offers->Offer->OfferListing->Price->CurrencyCode;
        }

        $amazon_data[(string) $item->ASIN] = [
          'ASIN' => (string) $item->ASIN,
          'title' => (string) $item->ItemAttributes->Title,
          'url' => (string) $item->DetailPageURL,
          'img_src' => (string) $item->MediumImage->URL,
          'price' => $price ? number_format((float) $price / 100, 2, ',', '') : NULL,
          'currency' => $currency,
          'manufacturer' => (string) $item->ItemAttributes->Manufacturer,
          'product_group' => (string) $item->ItemAttributes->ProductGroup,
          'product_available' => $product_available,
        ];
      }

      // Also cache asins for which we couldn't get any data or else we would
      // query the API again using up the request limit.
      foreach ($asins_chunk as $asin) {
        if (empty($amazon_data[$asin])) {
          $amazon_data[$asin] = FALSE;
        }
      }

      if (!empty($amazon_data)) {
        $this->productStore->setMultiple($amazon_data);
        $product_data += $amazon_data;
      }

      // Wait for the request limit to pass if there are items left to process.
      if (!empty($fetch_asins)) {
        usleep(round($requests_per_second_limit * 1000 * 1000));
      }
    }

    $this->lock->release(__METHOD__);
    return $product_data;
  }

  /**
   * Get the maximum allowed number of requests per day to query the amazon api.
   *
   * @return int
   */
  public function getMaxRequestsPerDay() {
    return $this->maxRequestPerDay;
  }

  /**
   * Get number of requests sent to amazon today.
   *
   * @return int
   *   The number of requests made to amazon today.
   */
  public function getTodaysRequestCount() {
    $default = ['date' => date('Ymd'), 'count' => 0];
    $count = $this->state->get('amazon_product_widget.todays_request_count', $default);
    if ($count['date'] != date('Ymd')) {
      $this->state->set('amazon_product_widget.todays_request_count', $default);
      return 0;
    }
    return $count['count'];
  }

  /**
   * Increase the internal counter for number of requests made to amazon today.
   *
   * @param int $increment
   *   Number of requests which should be added to the current counter.
   *
   * @return int
   *   The number of requests made to amazon today.
   */
  protected function increaseTodaysRequestCount($increment = 1) {
    $default = ['date' => date('Ymd'), 'count' => 0];
    $count = $this->state->get('amazon_product_widget.todays_request_count', $default);
    if ($count['date'] != date('Ymd')) {
      $count['count'] = 0;
    }
    $count['count'] += $increment;
    $this->state->set('amazon_product_widget.todays_request_count', $count);
    return $count['count'];
  }

}
