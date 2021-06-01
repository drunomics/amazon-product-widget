<?php

/**
 * @file
 * Views include file.
 */

/**
 * Implements hook_views_data().
 */
function amazon_product_widget_views_data() {
  $data = [];

  // Base information.
  $data['amazon_product_widget_key_value']['table']['group'] = t('Amazon Product Widget: Product');
  $data['amazon_product_widget_key_value']['table']['base'] = [
    'title'    => t('Amazon Product Widget: Product'),
    'help'     => t('Represents an Amazon Product'),
  ];


  // Fields.
  $data['amazon_product_widget_key_value']['name'] = [
    'title' => t('ASIN'),
    'help'  => t('Amazon Standard Identification Number'),
    'relationship' => [
      'base' => 'amazon_product_widget_deal_feed',
      'base field' => 'asin',
      'id' => 'standard',
      'label' => t('Product: Deal'),
    ],
    'field' => [
      'id' => 'standard',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  $data['amazon_product_widget_key_value']['collection'] = [
    'title' => t('Collection'),
    'help'  => t('Collection, either products or search_results.'),
    'field' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
  ];

  $data['amazon_product_widget_key_value']['renewal'] = [
    'title' => t('Renewal date'),
    'help'  => t('When this product will expire.'),
    'field' => [
      'id' => 'date',
    ],

    'sort' => [
      'id' => 'date',
    ],

    'filter' => [
      'id' => 'date',
    ],
  ];

  $data['amazon_product_widget_key_value']['value'] = [
    'title' => t('Has Data'),
    'help'  => t('Shows whether this product has data from Amazon.'),
    'field' => [
      'id' => 'amazon_product_widget_product_has_data',
    ],
  ];

  $data['amazon_product_widget_key_value']['overrides'] = [
    'title' => t('Overrides'),
    'help'  => t('Shows whether this product overrides set.'),
    'field' => [
      'id' => 'amazon_product_widget_product_has_data',
    ],
  ];

  $data['amazon_product_widget_key_value']['more_info'] = [
    'title' => t('More Info'),
    'help'  => t('Shows a link to a details page about the product.'),
    'field' => [
      'id' => 'amazon_product_widget_more_link',
    ],
  ];

  // Base information.
  $data['amazon_product_widget_deal_feed']['table']['group'] = t('Amazon Product Widget: Deal');
  $data['amazon_product_widget_key_deal_feed']['table']['base'] = [
    'title'    => t('Amazon Product Widget: Deal'),
    'help'     => t('Represents an Amazon Deal.'),
  ];

  $data['amazon_product_widget_deal_feed']['deal_status'] = [
    'title' => t('Deal Status'),
    'help'  => t('The deal status for this product.'),
    'field' => [
      'id' => 'amazon_product_widget_deal_status',
    ],
    'filter' => [
      'id' => 'amazon_product_widget_deal_status',
    ],
  ];

  $data['amazon_product_widget_deal_feed']['deal_start'] = [
    'title' => t('Deal Start'),
    'help'  => t('When the deal starts for this product.'),
    'field' => [
      'id' => 'date',
    ],
  ];

  $data['amazon_product_widget_deal_feed']['deal_end'] = [
    'title' => t('Deal End'),
    'help'  => t('When the deal ends for this product.'),
    'field' => [
      'id' => 'date',
    ],
  ];

  return $data;
}
