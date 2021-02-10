<?php

namespace Drupal\amazon_product_widget;

use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\GetItemsRequest;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\GetItemsResource;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\PartnerType;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\SearchItemsRequest;
use Drupal\amazon_paapi\AmazonPaapi;
use Drupal\amazon_paapi\AmazonPaapiTrait;
use Drupal\amazon_product_widget\Exception\AmazonRequestLimitReachedException;
use Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\State\StateInterface;

/**
 * Provides amazon product data.
 */
class ProductService {

  use AmazonPaapiTrait;

  /**
   * Search index default category.
   */
  const AMAZON_CATEGORY_DEFAULT = 'All';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Amazon product widget settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $settings;

  /**
   * Product store.
   *
   * Key value store with ASIN-number as keys and serialized product data as
   * data.
   *
   * @var \Drupal\amazon_product_widget\productStore
   */
  protected $productStore;

  /**
   * Search result store.
   *
   * Key value store with a hashed search term string as key and a list of
   * ASINs which were returned from amazon search as data.
   *
   * @var \Drupal\amazon_product_widget\productStore
   */
  protected $searchResultStore;

  /**
   * State.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Lock backend.
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
   *   Lock backend.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   Config factory.
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue.
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(ProductStoreFactory $store_factory, StateInterface $state, LockBackendInterface $lock, ConfigFactoryInterface $config, QueueInterface $queue, EntityTypeManager $entityTypeManager) {
    $this->productStore = $store_factory->get(ProductStore::COLLECTION_PRODUCTS);
    $this->searchResultStore = $store_factory->get(ProductStore::COLLECTION_SEARCH_RESULTS);
    $this->state = $state;
    $this->lock = $lock;
    $this->queue = $queue;
    $this->entityTypeManager = $entityTypeManager;

    $this->settings = $config->get('amazon_product_widget.settings');
    $this->maxRequestPerDay = $config->get('amazon_product_widget.settings')->get('max_requests_per_day');
    $this->maxRequestPerSecond = $config->get('amazon_product_widget.settings')->get('max_requests_per_second');

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
   * Gets the search result store.
   *
   * @return \Drupal\amazon_product_widget\productStore
   */
  public function getSearchResultStore() {
    return $this->searchResultStore;
  }

  /**
   * Gets amazon product data.
   *
   * Since this will use amazon requests which are limited, never use this
   * method in a way where the ASINs are provided by anonymous users input.
   *
   * @param string[] $asins
   *   Product ASINs.
   * @param bool $renew
   *   Clear cache and fetch product data from amazon.
   *
   * @return array
   *   Associative array with ASIN-number as key, and product data as values.
   *   If no data was retrieved for an ASIN, then the value is FALSE.
   *
   * @throws \Drupal\amazon_product_widget\Exception\AmazonRequestLimitReachedException
   */
  public function getProductData(array $asins, $renew = FALSE) {
    $asins = array_unique($asins);
    $asins = array_filter($asins);
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

    $overrides = $this->productStore->getOverrides($asins);
    foreach ($product_data as $key => $value) {
      if (isset($overrides[$key])) {
        $product_data[$key]['overrides'] = $overrides[$key];
      }
    }

    return $product_data;
  }

  /**
   * Gets top listed ASINs for a search term.
   *
   * Since this will use amazon requests which are limited, never use this
   * method in a way where the search is provided by anonymous users input.
   *
   * @param string $search_terms
   *   A search string like you would input in the Amazon search bar.
   * @param string $category
   *   Amazon Search index, defaults to `All`.
   * @param bool $renew
   *   Clear cache and fetch product data from amazon.
   *
   * @return string[]
   *   An array of ASIN-numbers which are the top result for that search, with
   *   the first item in the array being the top result.
   *
   * @throws \Drupal\amazon_product_widget\Exception\AmazonRequestLimitReachedException
   */
  public function getSearchResults($search_terms, $category = ProductService::AMAZON_CATEGORY_DEFAULT, $renew = FALSE) {
    if (empty($search_terms)) {
      return [];
    }
    $key = ProductStore::createSearchResultKey($search_terms, $category);
    $data = $this->searchResultStore->get($key);
    if (empty($data['result']) || $renew) {
      $result = $this->fetchAmazonSearchResults($search_terms, $category);
      return $result;
    }
    return $data['result'];
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
  public function queueProductRenewal(array $asins = []) {
    $asins = array_filter($asins);
    foreach ($asins as $asin) {
      $this->productStore->setIfNotExists($asin, FALSE, 0);
    }
    if ($this->productStore->hasStaleData()) {
      $this->queue->createItem(['collection' => ProductStore::COLLECTION_PRODUCTS]);
    }
  }

  /**
   * Queue fetching of stale search results in the store.
   *
   * @param string $search_terms
   *   (optional) Add a search string like you would input in the Amazon search
   *   bar to fetch results for this string as well.
   * @param string $category
   *   Amazon Search index, defaults to `All`.
   *
   * @throws \Exception
   */
  public function queueSearchResults($search_terms = '', $category = ProductService::AMAZON_CATEGORY_DEFAULT) {
    if (strlen($search_terms)) {
      $key = ProductStore::createSearchResultKey($search_terms, $category);
      $default = ProductStore::createSearchResultData($search_terms, $category);
      $this->searchResultStore->setIfNotExists($key, $default, 0);
    }
    if ($this->searchResultStore->hasStaleData()) {
      $this->queue->createItem(['collection' => ProductStore::COLLECTION_SEARCH_RESULTS]);
    }
  }

  /**
   * Fetch & cache amazon product data.
   *
   * @param string[] $asins
   *   Product ASINs.
   *
   * @return array
   *   Associative array with ASIN-number as key, and product data as values.
   *   If no data was retrieved for an ASIN, then the value is FALSE.
   *
   * @throws \Drupal\amazon_product_widget\Exception\AmazonRequestLimitReachedException
   */
  protected function fetchAmazonProducts(array $asins) {
    $asins = array_unique($asins);
    $product_data = [];

    $requests_per_second_limit = min(1, 1 / $this->maxRequestPerSecond);
    $expected_lock_time = $requests_per_second_limit * count($asins) / 10;
    $timeout = min(30, $expected_lock_time + 5);
    if (!$this->lock->acquire(__CLASS__, $timeout)) {
      $this->lock->wait(__CLASS__);
      if (!$this->lock->acquire(__CLASS__, $timeout)) {
        throw new AmazonRequestLimitReachedException('Amazon API currently blocked by another process.');
      }
    }

    $fetch_asins = $asins;
    while ($fetch_asins) {
      // Amazon API allows querying 10 products per single request.
      $asins_chunk = array_splice($fetch_asins, 0, 10);
      $retry_individual = FALSE;

      try {
        $amazon_data = $this->fetchItemData($asins_chunk);
      }
      catch (AmazonRequestLimitReachedException $e) {
        $this->lock->release(__CLASS__);
        throw $e;
      }
      catch (\Exception $e) {
        $this->getAmazonPaapi()->logException($e);
        $retry_individual = TRUE;
      }

      // When one of the asins caused an exception while fetching multiples, we
      // have to go through them individually so we still get the data for the
      // valid ones, as well as cache invalid asins.
      if ($retry_individual) {
        $amazon_data = [];
        foreach ($asins_chunk as $asin) {
          try {
            $amazon_data += $this->fetchItemData([$asin]);
          }
          catch (AmazonRequestLimitReachedException $e) {
            $this->lock->release(__CLASS__);
            throw $e;
          }
          catch (\Exception $e) {
            // Make sure the invalid response is cached as well.
            $amazon_data[$asin] = FALSE;
            $this->getAmazonPaapi()->logException($e);
          }
        }
      }

      // Cache the results.
      $this->productStore->setMultiple($amazon_data);

      if (!empty($amazon_data)) {
        $product_data += $amazon_data;
      }
    }

    $this->lock->release(__CLASS__);
    return $product_data;
  }

  /**
   * Fetch item data directly from amazon.
   *
   * @param string[] $asins
   *   Product ASINs.
   *
   * @return array
   *   Associative array with ASIN-number as key, and product data as values.
   *   If no data was retrieved for an ASIN, then the value is FALSE.
   *
   * @throws \Drupal\amazon_product_widget\Exception\AmazonRequestLimitReachedException
   * @throws \Amazon\ProductAdvertisingAPI\v1\ApiException
   */
  protected function fetchItemData(array $asins) {
    if (empty($asins)) {
      return [];
    }

    if ($this->getTodaysRequestCount() >= $this->getMaxRequestsPerDay()) {
      throw new AmazonRequestLimitReachedException('Maximum number of requests per day to Amazon API reached.');
    }

    $amazon_data = [];
    foreach ($asins as $asin) {
      $amazon_data[$asin] = FALSE;
    }

    $valid_asins = array_filter($asins, 'amazon_product_widget_is_valid_asin');
    if (empty($valid_asins)) {
      return $amazon_data;
    }

    $resources = [
      GetItemsResource::IMAGESPRIMARYMEDIUM,
      GetItemsResource::IMAGESPRIMARYLARGE,
      GetItemsResource::ITEM_INFOBY_LINE_INFO,
      GetItemsResource::ITEM_INFOTITLE,
      GetItemsResource::OFFERSLISTINGSPRICE,
      GetItemsResource::OFFERSLISTINGSDELIVERY_INFOIS_PRIME_ELIGIBLE,
    ];

    $partner_tag = AmazonPaapi::getPartnerTag();
    $request = new GetItemsRequest();
    $request->setItemIds($valid_asins);
    $request->setPartnerTag($partner_tag);
    $request->setPartnerType(PartnerType::ASSOCIATES);
    $request->setResources($resources);

    // In case other requests preceded this one, wait at the start.
    $this->waitRequestsPerSecondLimit();
    $this->increaseTodaysRequestCount();

    $response = $this->getAmazonPaapi()->getApi()->getItems($request);

    if ($response->getItemsResult() && $response->getItemsResult()->getItems()) {
      foreach ($response->getItemsResult()->getItems() as $item) {
        $item_data = [
          'ASIN' => $item->getASIN(),
          'title' => NULL,
          'url' => NULL,
          'medium_image' => [],
          'large_image' => [],
          'price' => NULL,
          'suggested_price' => NULL,
          'currency' => NULL,
          'manufacturer' => NULL,
          'product_available' => FALSE,
          'is_eligible_for_prime' => FALSE,
        ];

        if ($item->getDetailPageURL()) {
          $item_data['url'] = $item->getDetailPageURL();
        }

        if ($item_info = $item->getItemInfo()) {
          if ($item_info->getTitle()) {
            $item_data['title'] = $item_info->getTitle()
              ->getDisplayValue();
          }
          if ($item_info->getByLineInfo() && $item_info->getByLineInfo()
              ->getManufacturer()) {
            $item_data['manufacturer'] = $item_info->getByLineInfo()
              ->getManufacturer()
              ->getDisplayValue();
          }
        }

        if ($item->getOffers() && $item->getOffers()
            ->getListings() && $item->getOffers()->getListings()[0]) {

          $offer = $item->getOffers()->getListings()[0];
          if ($price = $offer->getPrice()) {
            $item_data['price'] = $price->getAmount();
            $item_data['currency'] = $price->getCurrency();
            $item_data['product_available'] = TRUE;

            if ($savings = $price->getSavings()) {
              $item_data['suggested_price'] = $item_data['price'] + $savings->getAmount();
            }
          }

          if ($offer->getDeliveryInfo() && $offer->getDeliveryInfo()
              ->getIsPrimeEligible()) {
            $item_data['is_eligible_for_prime'] = TRUE;
          }
        }

        if ($item->getImages() && $primary_images = $item->getImages()
            ->getPrimary()) {
          if ($primary_images->getMedium()) {
            $item_data['medium_image'] = [
              'URL' => $primary_images->getMedium()->getURL(),
              'Width' => $primary_images->getMedium()->getWidth(),
              'Height' => $primary_images->getMedium()->getHeight(),
            ];
          }
          if ($primary_images->getLarge()) {
            $item_data['large_image'] = [
              'URL' => $primary_images->getLarge()->getURL(),
              'Width' => $primary_images->getLarge()->getWidth(),
              'Height' => $primary_images->getLarge()->getHeight(),
            ];
          }
        }
        $amazon_data[$item->getASIN()] = $item_data;
      }
    }

    return $amazon_data;
  }

  /**
   * Fetch search results directly from amazon.
   *
   * @param string $search_terms
   *   A search string like you would input in the Amazon search bar.
   * @param string $category
   *   Amazon Search index, defaults to `All`.
   *
   * @return string[]
   *   An array of ASIN-numbers which are the top result for that search.
   *
   * @throws \Drupal\amazon_product_widget\Exception\AmazonRequestLimitReachedException
   */
  public function fetchAmazonSearchResults($search_terms, $category = ProductService::AMAZON_CATEGORY_DEFAULT) {
    if ($this->getTodaysRequestCount() >= $this->getMaxRequestsPerDay()) {
      throw new AmazonRequestLimitReachedException('Maximum number of requests per day to Amazon API reached.');
    }

    if (!$this->lock->acquire(__CLASS__)) {
      $this->lock->wait(__CLASS__);
      if (!$this->lock->acquire(__CLASS__)) {
        throw new AmazonRequestLimitReachedException('Amazon API currently blocked by another process.');
      }
    }

    // In case other requests preceded this one, wait at the start.
    $this->waitRequestsPerSecondLimit();
    $this->increaseTodaysRequestCount();

    $resources = [];
    $partner_tag = AmazonPaapi::getPartnerTag();

    $request = new SearchItemsRequest();
    $request->setSearchIndex($category);
    $request->setKeywords($search_terms);
    $request->setItemCount(10);
    $request->setPartnerTag($partner_tag);
    $request->setPartnerType(PartnerType::ASSOCIATES);
    $request->setResources($resources);

    $asins = [];

    try {
      $response = $this->getAmazonPaapi()->getApi()->searchItems($request);
      if ($response->getSearchResult() && $response->getSearchResult()->getItems()) {
        foreach ($response->getSearchResult()->getItems() as $item) {
          $asin = $item->getASIN();
          $asins[] = $asin;
        }
      }
    }
    catch (\Exception $e) {
      $this->getAmazonPaapi()->logException($e);
    }

    $this->lock->release(__CLASS__);

    // Make sure to cache the response even if there are no results, that way
    // we don't query the api every time.
    $key = ProductStore::createSearchResultKey($search_terms, $category);
    $data = ProductStore::createSearchResultData($search_terms, $category, $asins);
    $this->searchResultStore->set($key, $data);

    return $asins;
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
   * Waits the time that complies with the request per second limit.
   */
  protected function waitRequestsPerSecondLimit() {
    $requests_per_second_limit = min(1, 1 / $this->maxRequestPerSecond);
    usleep(round($requests_per_second_limit * 1000 * 1000));
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
      $count = $default;
    }
    $count['count'] += $increment;
    $this->state->set('amazon_product_widget.todays_request_count', $count);
    return $count['count'];
  }

  /**
   * Builds products for theming with fallback.
   *
   * @param AmazonProductField $product_field
   *   Product field.
   *
   * @return mixed[]
   *   Render array.
   */
  public function buildProductsWithFallback(AmazonProductField $product_field) {
    $products_container = $this->getProductsWithFallback($product_field);

    $product_build = [];
    $product_data = !empty($products_container['products']) ? $products_container['products'] : [];

    foreach ($product_data as $data) {
      $product_build[] = [
        '#theme' => 'amazon_product_widget_product',
        '#asin' => $data['asin'],
        '#medium_image' => $data['medium_image'],
        '#large_image' => $data['large_image'],
        '#name' => $data['name'],
        '#title' => $data['title'],
        '#url' => $data['url'],
        '#call_to_action_text' => $data['call_to_action_text'],
        '#currency_symbol' => $data['currency_symbol'],
        '#manufacturer' => $data['manufacturer'],
        '#price' => $data['price'],
        '#suggested_price' => $data['suggested_price'],
        '#is_eligible_for_prime' => $data['is_eligible_for_prime'],
      ];
    }

    $build = [
      '#theme' => 'amazon_product_widget_shopping',
      '#title' => $products_container['title'],
      '#products' => $product_build,
    ];

    return $build;
  }

  /**
   * Gets the raw product data with fallback.
   *
   * @param AmazonProductField $product_field
   *   Product field.
   *
   * @return mixed[]
   *   Data array.
   */
  public function getProductsWithFallback(AmazonProductField $product_field) {
    $asins = $product_field->getAsins();
    $title = $product_field->getTitle();
    $search_terms = $product_field->getSearchTerms();

    try {
      $product_data = $this->getProductData($asins);
    }
    catch (\Exception $e) {
      $product_data = [];
      watchdog_exception('amazon_product_widget', $e);
    }

    // Replace unavailable products with ones from the search term fallback.
    $replace = [];
    foreach ($product_data as $asin => $data) {
      if (!$this->validateProductData($data)) {
        $replace[] = $asin;
      }
    }

    $fill_up_with_fallback = $this->settings->get('fill_up_with_fallback');
    $remaining_to_fill_up = 0;

    if ($fill_up_with_fallback && !empty($product_data) && count($product_data) < 3) {
      $remaining_to_fill_up = 3 - count($product_data);
    }

    if (!empty($replace) || $remaining_to_fill_up) {
      try {
        $fallback_asins = $this->getSearchResults($search_terms, ProductService::AMAZON_CATEGORY_DEFAULT);
        $fallback_data = $this->getProductData($fallback_asins);
      }
      catch (\Exception $e) {
        $fallback_asins = [];
        $fallback_data = [];
        watchdog_exception('amazon_product_widget', $e);
      }

      // Replace outdated products and keep the result order: $fallback_asins
      // contains ordered results (top first).
      $product_data = array_diff_key($product_data, array_flip($replace));
      foreach ($fallback_asins as $asin) {
        if (
          empty($product_data[$asin])
          && !empty($fallback_data[$asin])
          && $this->validateProductData($fallback_data[$asin])
        ) {
          $product_data[$asin] = $fallback_data[$asin];
          if (count($replace)) {
            array_pop($replace);
          }
          else {
            $remaining_to_fill_up--;
          }
          if (count($replace) + $remaining_to_fill_up <= 0) {
            break;
          }
        }
      }
    }

    $decimal_separator = $this->settings->get('price_decimal_separator');
    $thousand_separator = $this->settings->get('price_thousand_separator');

    $image_defaults = [
      'URL' => NULL,
      'Height' => NULL,
      'Width' => NULL,
    ];

    $products = [];
    foreach ($product_data as $data) {
      $data = (array) $data;
      $products[] = [
        'medium_image' => $data['medium_image'] + $image_defaults,
        'large_image' => $data['large_image'] + $image_defaults,
        'asin' => $data['ASIN'],
        'name' => $data['title'],
        'title' => $data['title'],
        'url' => $data['url'],
        'call_to_action_text' => $this->settings->get('call_to_action_text'),
        'currency_symbol' => $data['currency'],
        'manufacturer' => $data['manufacturer'],
        'price' => !empty($data['price']) ? number_format($data['price'], 2, $decimal_separator, $thousand_separator) : NULL,
        'suggested_price' => !empty($data['suggested_price']) && !empty($data['price']) && $data['suggested_price'] != $data['price'] ? number_format($data['suggested_price'], 2, $decimal_separator, $thousand_separator) : NULL,
        'is_eligible_for_prime' => $data['is_eligible_for_prime'] ?? FALSE,
      ];
    }

    $products_container = [
      'title' => (string) $title,
      'products' => $products,
    ];

    return $products_container;
  }

  /**
   * Validates product data.
   *
   * @param $data
   *   The product data to check.
   *
   * @return bool
   *   The validity.
   */
  protected function validateProductData($data) {
    if (!empty($data['medium_image'])
      && !empty($data['large_image'])
      && !empty($data['title'])
      && !empty($data['price'])
      && !empty($data['product_available'])) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Sets overrides for the given ASIN keys.
   *
   * @param array $overrides
   *   Overrides, keyed by ASIN.
   *
   * @throws \Exception
   */
  public function setOverrides(array $overrides) {
    foreach ($overrides as $key => $override) {
      $this->productStore->setOverride($key, $override);
    }
  }

}
