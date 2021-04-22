<?php

namespace Drupal\amazon_product_widget\Controller;

use Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField;
use Drupal\amazon_product_widget\ProductServiceTrait;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
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
   * Lock backend.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * Amazon product widget settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $settings;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * AmazonProductController constructor.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   Lock backend.
   * @param \Drupal\Core\Config\ImmutableConfig $settings
   *   Amazon product widget settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   */
  public function __construct(RendererInterface $renderer, LockBackendInterface $lock, ImmutableConfig $settings, EntityTypeManagerInterface $entityTypeManager) {
    $this->renderer = $renderer;
    $this->lock = $lock;
    $this->settings = $settings;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer'),
      $container->get('lock'),
      $container->get('config.factory')->get('amazon_product_widget.settings'),
      $container->get('entity_type.manager')
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
    $node_id = $request->query->get('node_id');
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

    $node = NULL;
    if (!empty($node_id)) {
      $node = $this->entityTypeManager->getStorage('node')->load($node_id);
    }

    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->entityTypeManager()->getStorage($entity_type);
    if ($entity = $storage->load($entity_id)) {
      if ($entity->hasField($fieldname)) {
        /** @var \Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField $field */
        $product_field = $entity->get($fieldname)->first();
        if ($product_field instanceof AmazonProductField) {
          $cacheability = CacheableMetadata::createFromObject($entity)->merge($cacheability);
          if ($as_json) {
            $content = $this->getProductService()->getProductsWithFallback($product_field, $node);
            $count = count($content['products']);
          }
          else {
            $build = $this->getProductService()->buildProductsWithFallback($product_field, $node);
            $count = count($build['#products']);
            $content = $this->renderer->renderRoot($build);
          }
        }
      }
    }

    // Max age for the response will be set in the event subscriber.
    // @see \Drupal\amazon_product_widget\EventSubscriber\AmazonApiSubscriber::onRespond()
    $max_age = $this->settings->get('render_max_age');
    $max_age = !is_null($max_age) ? $max_age : 3600;
    $cacheability->setCacheMaxAge($max_age);

    $response = new CacheableJsonResponse();
    $response->addCacheableDependency($cacheability);
    $response->setData(['count' => $count, 'content' => $content]);

    return $response;
  }

}
