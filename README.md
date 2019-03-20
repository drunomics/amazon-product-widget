# Amazon product widget

Provides a field widget to render amazon products given ASINs.

**Caching layers**

Product data fetched directly from the Amazon API will be cached for a
random amount between one and two days. This prevents product data being
fetched constantly or reaching amazons throttle limits.

On top of that the widget will be loaded via Ajax, and the cache
expiration of the rendered response can be configured via the setting
`render_max_age` which defaults to one hour. The purpose of this is,
that this way an article can be cached indefinitely but the amazon
products will be updated regularly.


## Table of content

  * [Amazon product widget](#amazon-product-widget)
    * [Table of content](#table-of-content)
    * [Features](#features)
    * [Requirements](#requirements)
    * [Installation](#installation)
    * [Configuration](#configuration)
    * [Usage](#usage)
    * [Maintainers](#maintainers)

## Features

  * Fetch & render amazon products within a field widget
  * Fetches the field via Ajax to get cached product data
  * Generic styling for Desktop & Mobile
  * Fallback to amazon search results when products are unavailable

## Requirements

You will need an amazon account for the Product Advertising API to get
the api credentials.

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
 * Either add patches manually or set `enable-patching` to true in
   the projects root composer.json (see [Requirements](#requirements)).
 * Install this module as you would a normal Drupal module

## Configuration

 * Set following amazon.settings:
    * access_key
    * access_secret
    * associates_id
    * locale
 * Add the `Amazon product widget` field to a node or paragraph and
   configure form & display.

## Usage

Enter one or more ASINs (Amazon Standard Identification Numbers) for the
products which should be displayed by default. (ASINs) are unique blocks
of 10 letters and/or numbers that identify items. You can find the ASIN
on the item's product information page at Amazon.

Optionally enter search terms which will be used when the products are
unavailable to list the search results in place of the entered products.

## Maintainers

 * Mathias (mbm80) - https://www.drupal.org/u/mbm80

Supporting organizations:

 * drunomics - https://www.drupal.org/drunomics
