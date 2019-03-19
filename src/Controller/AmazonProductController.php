<?php

namespace Drupal\amazon_product_widget\Controller;

use Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField;
use Drupal\amazon_product_widget\ProductService;
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

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Product service.
   *
   * @var \Drupal\amazon_product_widget\ProductService
   */
  protected $productService;

  /**
   * Amazon product widget settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $settings;

  /**
   * AmazonProductController constructor.
   *
   * @param \Drupal\amazon_product_widget\ProductService $product_service
   *   The product service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Config\ImmutableConfig $settings
   *   Amazon product widget settings.
   */
  public function __construct(ProductService $product_service, RendererInterface $renderer, ImmutableConfig $settings) {
    $this->productService = $product_service;
    $this->renderer = $renderer;
    $this->settings = $settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('amazon_product_widget.product_service'),
      $container->get('renderer'),
      $container->get('config.factory')->get('amazon_product_widget.settings')
    );
  }

  /**
   * Gets list of amazon products from provided ASINs.
   */
  public function get(Request $request) {
    $entity_id = $request->query->get('entity_id');
    $entity_type = $request->query->get('entity_type');
    $fieldname = $request->query->get('field');

    $title = '';
    $asins = [];

    $cache_dependency = new CacheableMetadata();

    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->entityTypeManager()->getStorage($entity_type);
    if ($entity = $storage->load($entity_id)) {
      if ($entity->hasField($fieldname)) {
        /** @var \Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField $field */
        $field = $entity->get($fieldname)->first();
        if ($field instanceof AmazonProductField) {
          $cache_dependency = CacheableMetadata::createFromObject($entity)->merge($cache_dependency);
          $asins = $field->getAsins();
          $title = $field->getTitle();
        }
      }
    }

    $product_data = $this->productService->fetchProductData($asins);

    $product_build = [];
    foreach ($product_data as $data) {
      $data = (array) $data;
      $product_build[] = [
        '#theme' => 'amazon_product_widget_product',
        '#img_src' => $data['img_src'],
        '#name' => $data['title'],
        '#title' => $data['title'],
        '#url' => $data['url'],
        '#call_to_action_text' => $this->settings->get('call_to_action_text'),
        '#currency_symbol' => $data['currency'],
        '#manufacturer' => $data['manufacturer'],
        '#price' => $data['price'],
      ];
    }

    $build = [
      '#theme' => 'amazon_product_widget_shopping',
      '#title' => $title,
      '#products' => $product_build,
    ];

    $cache_dependency->applyTo($build);

    $response = new CacheableJsonResponse();
    $response->addCacheableDependency($cache_dependency);
    $response->setData(['count' => count($product_data), 'content' => $this->renderer->renderRoot($build)]);
    $max_age = $this->settings->get('render_max_age');
    $response->setMaxAge(!empty($max_age) ? $max_age : 3600);

    return $response;
  }

}
