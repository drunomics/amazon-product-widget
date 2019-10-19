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
    $entity_id = $request->query->get('entity_id');
    $entity_type = $request->query->get('entity_type');
    $fieldname = $request->query->get('field');

    $build = $this->getProductService()->buildProducts($entity_type, $entity_id, $fieldname);
    $cache_dependency = CacheableMetadata::createFromRenderArray($build);
    $cache_dependency->addCacheContexts(['url.query_args']);

    $content = $this->renderer->renderRoot($build);

    $response = new CacheableJsonResponse();
    $response->addCacheableDependency($cache_dependency);
    $response->setData(['count' => count($build['#products']), 'content' => $content]);
    $max_age = $this->settings->get('render_max_age');
    $response->setMaxAge(!empty($max_age) ? $max_age : 3600);

    return $response;
  }

}
