<?php

namespace Drupal\amazon_product_widget;

use ApaiIO\ApaiIO;
use ApaiIO\Configuration\GenericConfiguration;
use ApaiIO\Operations\Search;
use InvalidArgumentException;
use Drupal\amazon\Amazon;
use Drupal\amazon_product_widget\Exception\AmazonRequestLimitReachedException;
use Drupal\amazon_product_widget\Exception\AmazonServiceUnavailableException;
use Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\State\StateInterface;

/**
 * Provides amazon product data.
 */
class ProductService {

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
   * Amazon API.
   *
   * @var \ApaiIO\ApaiIO
   */
  protected $client;

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
   * Gets the search result store.
   *
   * @return \Drupal\amazon_product_widget\productStore
   */
  public function getSearchResultStore() {
    return $this->searchResultStore;
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
   * Gets the api client.
   *
   * @return ApaiIO
   *   The api client.
   *
   * @throws InvalidArgumentException
   */
  protected function getClient() {
    if (!$this->client instanceof ApaiIO) {
      if (empty($access_key)) {
        $access_key = Amazon::getAccessKey();
        if (!$access_key) {
          throw new InvalidArgumentException('Configuration missing: Amazon access key.');
        }
      }
      if (empty($access_secret)) {
        $access_secret = Amazon::getAccessSecret();
        if (!$access_secret) {
          throw new InvalidArgumentException('Configuration missing: Amazon access secret.');
        }
      }
      if (empty($locale)) {
        $locale = Amazon::getLocale();
        if (!$locale) {
          throw new InvalidArgumentException('Configuration missing: Amazon locale.');
        }
      }

      $conf = new GenericConfiguration();

      $conf->setCountry($locale)
        ->setAccessKey($access_key)
        ->setSecretKey($access_secret)
        ->setAssociateTag($this->associatesId);

      $this->client = new ApaiIO($conf);
    }

    return $this->client;
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
   * @throws \Drupal\amazon_product_widget\Exception\AmazonServiceUnavailableException
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
  * @throws \Drupal\amazon_product_widget\Exception\AmazonServiceUnavailableException
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
    // In case other requests preceded this one.
    usleep(round($requests_per_second_limit * 1000 * 1000));
    $expected_lock_time = $requests_per_second_limit * count($asins) / 10;
    if (!$this->lock->acquire(__CLASS__, min(30, $expected_lock_time + 5))) {
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
        $suggested_price = NULL;
        $currency = NULL;

        if (!empty($item->Offers->Offer->OfferListing->Price)) {
          $product_available = TRUE;
          // Price in cents.
          $price = (int) $item->Offers->Offer->OfferListing->Price->Amount;
          $currency = (string) $item->Offers->Offer->OfferListing->Price->CurrencyCode;
          $suggested_price = $price;
          if (!empty($item->Offers->Offer->OfferListing->AmountSaved->Amount)) {
            $saved_amount = (int) $item->Offers->Offer->OfferListing->AmountSaved->Amount;
            $suggested_price = $price + $saved_amount;
          }
        }

        $medium_image = '';
        if (!empty($item->MediumImage->URL)) {
          $medium_image = (string) $item->MediumImage->URL;
        }
        elseif (!empty($item->ImageSets->ImageSet[0]->MediumImage->URL)) {
          $medium_image = (string) $item->ImageSets->ImageSet[0]->MediumImage->URL;
        }

        $large_image = '';
        if (!empty($item->LargeImage->URL)) {
          $large_image = (string) $item->LargeImage->URL;
        }
        elseif (!empty($item->ImageSets->ImageSet[0]->LargeImage->URL)) {
          $large_image = (string) $item->ImageSets->ImageSet[0]->LargeImage->URL;
        }

        // SimpleXMLElement needs to be casted to string first.
        $is_eligible_for_prime = isset($item->Offers->Offer->OfferListing->IsEligibleForPrime) ?
          (bool)(string) $item->Offers->Offer->OfferListing->IsEligibleForPrime : FALSE;

        $amazon_data[(string) $item->ASIN] = [
          'ASIN' => (string) $item->ASIN,
          'title' => (string) $item->ItemAttributes->Title,
          'url' => (string) $item->DetailPageURL,
          'img_src' => $medium_image,
          'img_src_large' => $large_image,
          'price' => $price,
          'suggested_price' => $suggested_price,
          'currency' => $currency,
          'manufacturer' => (string) $item->ItemAttributes->Manufacturer,
          'product_group' => (string) $item->ItemAttributes->ProductGroup,
          'product_available' => $product_available,
          'is_eligible_for_prime' => $is_eligible_for_prime,
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

    $this->lock->release(__CLASS__);
    return $product_data;
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
   * @throws \Drupal\amazon_product_widget\Exception\AmazonServiceUnavailableException
   */
  public function fetchAmazonSearchResults($search_terms, $category = ProductService::AMAZON_CATEGORY_DEFAULT) {
    $requests_per_second_limit = min(1, 1 / $this->maxRequestPerSecond);
    // In case other requests preceded this one.
    usleep(round($requests_per_second_limit * 1000 * 1000));
    if (!$this->lock->acquire(__CLASS__)) {
      throw new AmazonRequestLimitReachedException('Amazon API currently blocked by another process.');
    }

    if ($this->getTodaysRequestCount() >= $this->getMaxRequestsPerDay()) {
      throw new AmazonRequestLimitReachedException('Maximum number of requests per day to Amazon API reached.');
    }

    $this->increaseTodaysRequestCount();

    $client = $this->getClient();

    $search = new Search();
    $search
      ->setKeywords($search_terms)
      ->setCategory($category)
      ->setResponseGroup(['Small']);

    $response = $client->runOperation($search);
    $simple_xml = simplexml_load_string($response);
    $asins = [];
    if (!empty($simple_xml->Items->Item)) {
      foreach ($simple_xml->Items->Item as $item) {
        $asin = (string) $item->ASIN;
        if (amazon_product_widget_is_valid_asin($asin)) {
          $asins[] = $asin;
        }
      }
    }

    // Make sure to cache the response even if there are no results, that way
    // we don't query the api every time.
    $key = ProductStore::createSearchResultKey($search_terms, $category);
    $data = ProductStore::createSearchResultData($search_terms, $category, $asins);
    $this->searchResultStore->set($key, $data);

    $this->lock->release(__CLASS__);
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
   * Builds products.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $entity_id
   *   The entity id.
   * @param string $fieldname
   *   The field name.
   *
   * @return mixed[]
   *   Build array.
   *
   * @throws \Drupal\amazon_product_widget\Exception\AmazonRequestLimitReachedException
   * @throws \Drupal\amazon_product_widget\Exception\AmazonServiceUnavailableException
   */
  public function buildProducts($entity_type, $entity_id, $fieldname) {
    $content = NULL;
    $title = '';
    $search_terms = '';
    $asins = [];
    $cache_dependency = new CacheableMetadata();

    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage($entity_type);
    if ($entity = $storage->load($entity_id)) {
      if ($entity->hasField($fieldname)) {
        /** @var \Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField $field */
        $field = $entity->get($fieldname)->first();
        if ($field instanceof AmazonProductField) {
          $cache_dependency = CacheableMetadata::createFromObject($entity)->merge($cache_dependency);
          $asins = $field->getAsins();
          $title = $field->getTitle();
          $search_terms = $field->getSearchTerms();
        }
      }
    }

    $product_data = $this->getProductData($asins);

    // Replace unavailable products with ones from the search term fallback.
    $replace = [];
    foreach ($product_data as $asin => $data) {
      if (empty($data['product_available'])) {
        $replace[] = $asin;
      }
    }

    if (!empty($replace)) {
      $fallback_asins = $this->getSearchResults($search_terms, ProductService::AMAZON_CATEGORY_DEFAULT);
      $fallback_data = $this->getProductData($fallback_asins);
      // Replace outdated products and keep the result order: $fallback_asins
      // contains ordered results (top first).
      $product_data = array_diff_key($product_data, array_flip($replace));
      foreach ($fallback_asins as $asin) {
        if (
          empty($product_data[$asin])
          && !empty($fallback_data[$asin])
          && $fallback_data[$asin]['product_available']
        ) {
          $product_data[$asin] = $fallback_data[$asin];
          array_pop($replace);
          if (empty($replace)) {
            break;
          }
        }
      }
    }

    $decimal_separator = $this->settings->get('price_decimal_separator');
    $thousand_separator = $this->settings->get('price_thousand_separator');

    $product_build = [];
    foreach ($product_data as $data) {
      $data = (array) $data;
      $product_build[] = [
        '#theme' => 'amazon_product_widget_product',
        '#img_src' => $data['img_src'],
        '#img_src_large' => $data['img_src_large'],
        '#name' => $data['title'],
        '#title' => $data['title'],
        '#url' => $data['url'],
        '#call_to_action_text' => $this->settings->get('call_to_action_text'),
        '#currency_symbol' => $data['currency'],
        '#manufacturer' => $data['manufacturer'],
        '#price' => $data['price'] ? number_format($data['price'] / 100, 2, $decimal_separator, $thousand_separator) : NULL,
        '#suggested_price' => $data['suggested_price'] ? number_format($data['suggested_price'] / 100, 2, $decimal_separator, $thousand_separator) : NULL,
        '#is_eligible_for_prime' => $data['is_eligible_for_prime'] ?? FALSE,
      ];
    }

    $build = [
      '#theme' => 'amazon_product_widget_shopping',
      '#title' => $title,
      '#products' => $product_build,
    ];

    $cache_dependency->applyTo($build);

    return $build;
  }

}
