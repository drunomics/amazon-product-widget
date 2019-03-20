<?php

namespace Drupal\amazon_product_widget;

use Drupal\amazon\Amazon;
use Drupal\amazon_product_widget\Exception\AmazonServiceUnavailableException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Provides amazon product data.
 */
class ProductService {

  /**
   * Product store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  protected $productStore;

  /**
   * Lock.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

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
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $product_store
   *   Product store.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   Lock.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   Config factory.
   */
  public function __construct(KeyValueStoreExpirableInterface $product_store, LockBackendInterface $lock, ConfigFactoryInterface $config) {
    $this->productStore = $product_store;
    $this->lock = $lock;

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
   * Fetch product data from temp storage and fall back to amazon api.
   *
   * @param string[] $asins
   *   Product ASINs.
   *
   * @return array
   *   Build.
   *
   * @throws AmazonServiceUnavailableException
   */
  public function fetchProductData(array $asins) {
    $asins = array_unique($asins);
    $product_data = $this->productStore->getMultiple($asins);
    $fetch_asins = array_diff($asins, array_keys($product_data));

    if (!empty($fetch_asins)) {
      $amazon_data = [];
      $lock_timeout = min(1, 1 / $this->maxRequestPerSecond);
      if (!$this->lock->acquire(__METHOD__, $lock_timeout)) {
        $this->lock->wait(__METHOD__, 3);
      }

      if ($this->lock->acquire(__METHOD__, $lock_timeout)) {
        $result = $this->getAmazonApi()->lookup($fetch_asins, ['Offers']);
        // We don't release the lock here to keep within throttling limits.
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
      }

      // An expiration of at least one day should be enough to not run into
      // amazons throttling limits.
      $expire = 3600 * 24 + rand(0, 3600 * 24);

      if (!empty($amazon_data)) {
        $this->productStore->setMultipleWithExpire($amazon_data, $expire);
        $product_data += $amazon_data;
      }

      // Also cache asins for which we couldn't get any data or else we would
      // query the API again using up the request limit.
      $unknown_asins = array_diff($asins, array_keys($amazon_data));
      if (!empty($unknown_asins)) {
        $this->productStore->setMultipleWithExpire(array_flip($unknown_asins), $expire);
      }
    }

    // Only return valid data.
    return array_filter($product_data);
  }

  /**
   * Invalidates cached data for specified ASINs.
   *
   * @param string[] $asins
   *   Product ASINs.
   */
  public function invalidateCache(array $asins) {
    $this->productStore->deleteMultiple($asins);
  }

}
