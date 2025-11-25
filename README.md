Commerce Unleashed
===============

CONTENTS OF THIS FILE
---------------------
* Introduction
* Requirements
* Configuration

INTRODUCTION
------------
This module integrates Drupal Commerce Core with [Unleashed Software](https://unleashedsoftware.com/)

## Features

* Syncing product inventory from Unleashed to Commerce Core.
* Products are matched via Commerce Core sku and Unleashed ProductCode field.
* Syncing orders from Commerce Core to Unleashed.
* Syncing stock inventory from Unleashed to Commerce Core.
* Enforcing stock availability.
* Cron and Drush options for syncing.
* Creating product variations automatically from product inventory sync.
* Queue integration with advancedqueue module.


REQUIREMENTS
------------
This module should be added to your codebase via Composer

`composer require "drupal/commerce_unleashed"`

You must also have an Unleashed account.

Dependencies
* [Commerce Core 3](https://www.drupal.org/project/commerce)
* [Advanced Queue](https://www.drupal.org/project/advancedqueue)


CONFIGURATION
-------------

## General configuration
Go to `Commerce => Configuration => Store => Unleashed`

Configure api key and id.

**Product settings**
* Enable sync.
* Full sync - by default we are running brief=true for faster sync.
* Select default variation type, store and currency.

**Purchase order settings**
* Enable sync.
* Order types - select which order types should be sent to Unleashed.
* Provide default supplier code if applicable.
* Complete orders - depending on your workflow you may want complete order from Drupal.

**Stock settings**
* Enable sync.
* Enforce stock availability.
* Update local stock after order placement.

## Stock overview
Go to `Commerce => Stock on hand`

## Syncing products
You can sync products from Unleashed using Drupal cron or drush command.
Cron is limited with no additional filtering options.

With drush command you can use all filters available in Unleashed API.
@see https://apidocs.unleashedsoftware.com/Products

Example:
`drush commerce_unleashed:sync:products --query=productGroup=Tobacco//brief=true`
It would sync all products from a Tobacco product group with the brief=true parameter.

Note that you need to use `//` instead of `&` for multiple query parameters,
to avoid issues with executing drush. The drush command transforms it to `&`
for the API call.

## Custom configuration / modifications.
You can enrich order payload for purchase orders with this event
`\Drupal\commerce_unleashed\Events\UnleashedOrderEvent`.

You can alter how product variations are created / synced from Unleashed with this event
`\Drupal\commerce_unleashed\Events\UnleashedProductVariationEvent`

You can skip syncing specific Unleashed products with this event
`\Drupal\commerce_unleashed\Events\UnleashedSyncEvent`

The http client for communication with Unleashed has all available methods
for interacting with API. If you want to use it in your custom code,
you can easily initiate it like this:

```php
$client = new \Drupal\commerce_unleashed\UnleashedClient('api_id', 'api_key');
$client->getProduct('xxxx-xxxx-xxxx-xxxx');
```
