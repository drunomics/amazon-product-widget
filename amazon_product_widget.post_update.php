<?php

/**
 * @file
 * Post update hooks.
 */

use Drupal\Core\Config\FileStorage;
use Drupal\menu_link_content\Entity\MenuLinkContent;

/**
 * Installs products overview view and menu link.
 */
function amazon_product_widget_post_update_install_view(&$sandbox) {
  // Import the product overview view.
  $configPath = drupal_get_path('module', 'amazon_product_widget') . '/config/install';
  $source = new FileStorage($configPath);
  /** @var \Drupal\Core\Config\StorageInterface $configStorage */
  $configStorage = \Drupal::service('config.storage');
  if (!$configStorage->exists('views.view.amazon_product_widget_product_overview')) {
    $configStorage->write('views.view.amazon_product_widget_product_overview', $source->read('views.view.amazon_product_widget_product_overview'));
  }

  // Create a menu link.
  $menuStorage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  $menuEntries = $menuStorage->loadByProperties([
    'link.uri' => 'internal:/admin/config/services/amazon-product-widget/products',
  ]);

  if (!($menuLink = reset($menuEntries))) {
    MenuLinkContent::create([
      'link'      => ['uri' => 'internal:/admin/config/services/amazon-product-widget/products'],
      'title'     => 'Product Overview',
      'menu_name' => 'admin',
      'parent'    => 'amazon_product_widget.settings_form',
      'weight'    => 102,
    ])->save();
  }
}

/**
 * Installs unavailable products view and menu link.
 */
function amazon_product_widget_post_update_install_unavailable_products_view(&$sandbox) {
  // Import the product overview view.
  $configPath = drupal_get_path('module', 'amazon_product_widget') . '/config/install';
  $source = new FileStorage($configPath);
  /** @var \Drupal\Core\Config\StorageInterface $configStorage */
  $configStorage = \Drupal::service('config.storage');
  if (!$configStorage->exists('views.view.unavailable_products')) {
    $configStorage->write('views.view.unavailable_products', $source->read('views.view.amazon_product_widget_unavailable_products'));
  }

  // Create a menu link.
  $menuStorage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  $menuEntries = $menuStorage->loadByProperties([
    'link.uri' => 'internal:/admin/config/services/amazon-product-widget/unavailable-products',
  ]);

  if (!($menuLink = reset($menuEntries))) {
    MenuLinkContent::create([
      'link'      => ['uri' => 'internal:/admin/config/services/amazon-product-widget/unavailable-products'],
      'title'     => 'Unavailable Products',
      'menu_name' => 'admin',
      'parent'    => 'amazon_product_widget.settings_form',
      'weight'    => 103,
    ])->save();
  }
}
