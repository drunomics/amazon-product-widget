<?php

namespace Drupal\amazon_product_widget\EventSubscriber;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets cache headers for amazon api responses.
 */
class AmazonApiSubscriber implements EventSubscriberInterface {

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Amazon product widget settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $settings;

  /**
   * Constructs the AmazonApiSubscriber.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Config\ImmutableConfig $settings
   *   Amazon product widget settings.
   */
  public function __construct(RouteMatchInterface $route_match, ImmutableConfig $settings) {
    $this->routeMatch = $route_match;
    $this->settings = $settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Needs to be called before authentication subscriber which has 300.
    $events[KernelEvents::REQUEST][] = ['onRequest', 301];
    // Needs to be called after finish response subscriber which has zero.
    // Needs to be called after s_max_age event subscriber which has -10.
    $events[KernelEvents::RESPONSE][] = ['onRespond', -11];
    return $events;
  }

  /**
   * Unset cookie so the whole request acts as anonymous for everybody.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The event to process.
   *
   * @see \Drupal\Core\EventSubscriber\FinishResponseSubscriber::onRespond()
   */
  public function onRequest(GetResponseEvent $event) {
    if (!$event->isMasterRequest()) {
      return;
    }
    $request = $event->getRequest();
    // No route match at this point, so match with regular expression.
    $pathInfo = $request->getPathInfo();
    if (preg_match('/^\/api\/amazon\/product$/', $pathInfo)) {
      // Strip session cookie if presented so the whole request is anonymous.
      foreach ($request->cookies as $key => $value) {
        $sessionPrefix = $request->isSecure() ? 'SSESS' : 'SESS';
        if (strpos($key, $sessionPrefix) === 0) {
          $request->cookies->remove($key);
        }
      }
    }
  }

  /**
   * Sets proper cache control header.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   The event to process.
   *
   * @see \Drupal\Core\EventSubscriber\FinishResponseSubscriber::onRespond()
   */
  public function onRespond(FilterResponseEvent $event) {
    if (!$event->isMasterRequest()) {
      return;
    }
    if ($this->routeMatch->getRouteName() == 'amazon_product_widget.product_api') {
      /** @var \Drupal\Core\Cache\CacheableJsonResponse $response */
      $response = $event->getResponse();
      // Adjust cache control headers.
      $response->headers->removeCacheControlDirective('must-revalidate');
      $response->headers->removeCacheControlDirective('no-cache');

      // We usually want to use the set max age from the metadata, but in case
      // it wasn't set fallback to default settings.
      if ($response instanceof CacheableResponseInterface) {
        $max_age = $response->getCacheableMetadata()->getCacheMaxAge();
      }

      if (!isset($max_age)) {
        $max_age = $this->settings->get('render_max_age');
        $max_age = !is_null($max_age) ? $max_age : 3600;
      }

      $response->setMaxAge($max_age);
      $response->setPublic();
    }
  }

}
