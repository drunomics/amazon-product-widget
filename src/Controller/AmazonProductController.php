<?php

namespace Drupal\amazon_product_widget\Controller;

use Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField;
use Drupal\amazon_product_widget\ProductServiceTrait;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Render\RendererInterface;

/**
 * Controller to request Amazon products via Amazon API.
 */
class AmazonProductController extends ControllerBase {

  use ProductServiceTrait;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Amazon product widget settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $settings;

  /**
   * AmazonProductController constructor.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Config\ImmutableConfig $settings
   *   Amazon product widget settings.
   */
  public function __construct(RendererInterface $renderer, ImmutableConfig $settings) {
    $this->renderer = $renderer;
    $this->settings = $settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer'),
      $container->get('config.factory')->get('amazon_product_widget.settings')
    );
  }

  /**
   * Gets list of amazon products from provided ASINs.
   */
  public function get(Request $request) {
    $content = [];
    $count = 0;

    $entity_id = $request->query->get('entity_id');
    $entity_type = $request->query->get('entity_type');
    $fieldname = $request->query->get('field');
    $as_json = $request->query->get('json');

    $cache_contexts = [
      'url.query_args:entity_id',
      'url.query_args:entity_type',
      'url.query_args:field',
      'url.query_args:json',
    ];

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts($cache_contexts);

    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->entityTypeManager()->getStorage($entity_type);
    if ($entity = $storage->load($entity_id)) {
      if ($entity->hasField($fieldname)) {
        /** @var \Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField $field */
        $product_field = $entity->get($fieldname)->first();
        if ($product_field instanceof AmazonProductField) {
          $cacheability = CacheableMetadata::createFromObject($entity)->merge($cacheability);
          if ($as_json) {
            $content = $this->getProductService()->getProductsWithFallback($product_field);
            $count = count($content['products']);
          }
          else {
            $build = $this->getProductService()->buildProductsWithFallback($product_field);
            $count = count($build['#products']);
            $content = $this->renderer->renderRoot($build);
          }
        }
      }
    }

    $max_age = $this->settings->get('render_max_age');
    $max_age = !empty($max_age) ? $max_age : 3600;
    $cacheability->setCacheMaxAge($max_age);

    $response = new CacheableJsonResponse();
    $response->addCacheableDependency($cacheability);
    $response->setData(['count' => $count, 'content' => $content]);
    $response->setMaxAge($max_age);

    return $response;
  }

}
