# Amazon product widget

Provides a field widget to render amazon products given ASINs.

The product data will be fetched from Amazon using the [Product Advertising API](https://docs.aws.amazon.com/AWSECommerceService/latest/DG/Welcome.html).
Once the data is fetched it will be cached locally to stay withins Amazons request limit.

On top of that the widget itself will be loaded via Ajax which will be cached
in the response. This way an article or page can be cached indefinitely but the 
amazon products will be updated regularly disregarding the sites overall caching 
strategy.

## Table of content

  * [Amazon product widget](#amazon-product-widget)
    * [Table of content](#table-of-content)
    * [Features](#features)
    * [Requirements](#requirements)
    * [Installation](#installation)
    * [Configuration](#configuration)
      * [Amazon settings configuration](#amazon-settings-configuration)
      * [Amazon product widget configuration](#amazon-product-widget-configuration)
      * [Caching and request limits](#caching-and-request-limits)
      * [Permissions](#permissions)
    * [Usage](#usage)
    * [Maintainers](#maintainers)

## Features

  * Fetch & render amazon products within a field widget
  * Fetches the field via Ajax to get cached product data
  * Generic styling for Desktop & Mobile
  * Fallback to amazon search results when products are unavailable

## Requirements

You will need an Amazon Associates account and register it for the Product 
Advertising API to get the credentials needed.

The module requires the drupal\amazon module with the following patches:

```json
"drupal/amazon": {
    "Issue #3005862 Allow to set locale when using API." : "https://www.drupal.org/files/issues/2018-10-16/3005862-4-allow-to-set-locale.patch",
    "Issue #3006056 Respect extra response groups" : "https://www.drupal.org/files/issues/2018-10-11/3006056-2-include-extra-response-groups.patch",
    "Issue #3029351 Enable api key input fields in administration form." : "https://www.drupal.org/files/issues/2019-02-11/3029351-2-admin-form-enable-api-key-input-fields.patch"
},
```

## Installation

 * `composer require drupal/amazon:2.x-dev`
 * Either add patches manually or set `enable-patching` to true in the projects 
   root composer.json (see [Requirements](#requirements)).
 * Install this module as you would a normal Drupal module

## Configuration

### Amazon settings configuration

Set following `amazon.settings` configuration:

  * `access_key` - Amazon API access key
  * `access_secret` - Amazon API secret
  * `associates_id` - Amazon API associates id
  * `locale` - Amazon page for your country (eg: `com` for USA, `de` for germany)
 
### Amazon product widget configuration 

Set the following `amazon_product_widget.settings` configuration:

  * `max_requests_per_day` - Amazons own request per day limit (default 8640)
    (see [Caching and request limits](#caching-and-request-limits))
  * `max_requests_per_second` - Amazons own request per second limit (default 1)
    (see [Caching and request limits](#caching-and-request-limits))
  * `render_max_age` - Render cache for the widget in seconds
  * `call_to_action_text` - Link text for the product which leads to amazon page
 
Add the `Amazon product widget` field to a node or paragraph and configure 
form & display.
   
### Caching and request limits

Amazon has very specific requirements regarding request limits (see [Efficiency Guidelines](https://docs.aws.amazon.com/AWSECommerceService/latest/DG/TroubleshootingApplications.html#efficiency-guidelines).)
so it is necessary to cache the data locally and update it on a regular basis
via cronjob. At least there is a base limit per day (8640) and one per second, 
these can be overriden if needed. 

When the data is saved it will set a renewal date (which is 48 hours by default)
for when the cronjob will try to update the data form amazon again.
The next renewal can be overridden in the setting (in hours):
  
  `amazon_product_widget.products.renewal_time`
  `amazon_product_widget.search_results.renewal_time`
  
The number of items which will be renewed per cron run is by default 100, and
can be set in this setting:

  `amazon_product_widget.products.renewal_limit`
  `amazon_product_widget.search_results.renewal_limit`

### Permissions

  * `Renew amazon product data` - for being able to manually refresh the 
    product data via `/admin/config/services/amazon/product-renewal`
 
## Usage

In the form widget, enter one or more ASINs for the products which should be 
displayed by default. Amazon Standard Identification Numbers (ASINs) are unique 
blocks of 10 letters and/or numbers that identify items. You can find the ASIN
on the item's product information page at Amazon.

Optionally enter search terms which will be used when the products are
unavailable to list the search results in place of the entered products.

## Maintainers

 * Mathias (mbm80) - https://www.drupal.org/u/mbm80

Supporting organizations:

 * drunomics - https://www.drupal.org/drunomics
