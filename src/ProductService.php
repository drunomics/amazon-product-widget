<?php

namespace Drupal\amazon_product_widget;

use Drupal\amazon\Amazon;
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
  }

  /**
   * Gets the amazon api.
   *
   * @return \Drupal\amazon\Amazon
   */
  protected function getAmazonApi() {
    if (!$this->amazonApi instanceof Amazon) {
      $this->amazonApi = new Amazon($this->associatesId);
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
          $price = $item->Offers->Offer->OfferListing->Price;
          $amazon_data[(string) $item->ASIN] = [
            'ASIN' => (string) $item->ASIN,
            'title' => (string) $item->ItemAttributes->Title,
            'url' => (string) $item->DetailPageURL,
            'img_src' => (string) $item->MediumImage->URL,
            'price' => number_format((float) $price->Amount / 100, 2, ',', ''),
            'currency' => (string) $price->CurrencyCode,
            'manufacturer' => (string) $item->ItemAttributes->Manufacturer,
            'product_group' => (string) $item->ItemAttributes->ProductGroup,
          ];
        }
      }

      if (!empty($amazon_data)) {
        // An expiration of at least one day should be enough to not run into
        // amazons throttling limits.
        $expire = 3600 * 24 + rand(0, 3600 * 24);
        $this->productStore->setMultipleWithExpire($amazon_data, $expire);
        $product_data += $amazon_data;
      }
    }

    return $product_data;
  }

}
