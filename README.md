Commerce Unleashed
===============

CONTENTS OF THIS FILE
---------------------
* Introduction
* Requirements
* Configuration
* API usage

INTRODUCTION
------------
This module integrates Drupal Commerce Core with [Unleashed Software](https://unleashedsoftware.com/)

## Features

* Syncing product inventory from Unleashed to Commerce Core.
* Products are matched via Commerce Core sku and Unleashed ProductCode field.
* Optional price sync, from the default sell price or any sell price tier.
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
* Read attribute sets - on by default, and the reason a product read is not
  `brief=true`. See "What a product read returns" below.
* Include obsolete products - Unleashed leaves obsolete products out of a
  product read unless asked. Off by default.
* Fetch each product individually - the old "full sync". Fetches every product
  again, one request per product. It does **not** return the attribute set, so
  with attribute reads on there is usually no reason to turn this on.
* Select default variation type, product type, store and currency.
  The product type is the type new **products** are created as, and is separate
  from the variation type. Left empty it falls back to the variation type, which
  only works where the two share a machine name.
* Price sync - whether an existing variation has its price overwritten on every
  sync. Turn it off where Drupal owns pricing, or where the Unleashed figure is
  not the price your storefront quotes - an ex-VAT amount against a
  VAT-inclusive price, for instance. A price is still set when a variation is
  first created, because a variation cannot be saved without one; subscribers
  can replace it from the product variation event.
* Price field - which Unleashed field the price is read from. Sell price tiers
  are how Unleashed models customer-group pricing, so a trade storefront
  generally wants a tier rather than the default sell price.
* Page sizes - see API usage below.

**Purchase order settings**
* Enable sync.
* Order types - select which order types should be sent to Unleashed.
* Provide default supplier code if applicable.
* Complete orders - depending on your workflow you may want complete order from Drupal.

**Stock settings**
* Enable sync.
* Enforce stock availability. Note this counts stock across **every** warehouse.
  If only some of your warehouses are sellable, enforce availability from your
  own field instead and leave this off.
* Update local stock after order placement.
* Page size - see API usage below.

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

You can alter how products are created / synced from Unleashed with this event
`\Drupal\commerce_unleashed\Events\UnleashedProductEvent`

You can skip syncing specific Unleashed products with this event
`\Drupal\commerce_unleashed\Events\UnleashedSyncEvent`

The product variation event is the place to map a price somewhere other than the
variation itself - to a price list scoped to a customer group, say. Turn price
sync off, read the tier you want off the payload, and write it where it belongs.

The http client for communication with Unleashed has all available methods
for interacting with API. If you want to use it in your custom code,
you can easily initiate it like this:

```php
$client = new \Drupal\commerce_unleashed\UnleashedClient('api_id', 'api_key');
$client->getProduct('xxxx-xxxx-xxxx-xxxx');
```

## What a product read returns

Unleashed has two shapes of product record, and they are mutually exclusive:

| | `brief=true` | `includeAttributes=true` |
| --- | --- | --- |
| Fields | 7 | the complete record |
| Attribute set | no | yes |
| Product group, supplier, obsolete flag | no | yes |

The seven brief fields are `Guid`, `ProductCode`, `ProductDescription`,
`DefaultPurchasePrice`, `DefaultSellPrice`, `SellPriceTier1` and
`DefaultSupplierId`.

**`brief=true` suppresses `includeAttributes`.** Sending both returns no
attributes, silently - so the module sends one or the other, never both.

Attribute sets are how Unleashed models per-product flags, and a sync that
cannot see them cannot act on them. That is why attribute reads are the
default even though a complete record is a larger response than a brief one:
it is still **one request per page**, whereas fetching each product
individually is one request per product and returns no attribute set at all,
because the single-product endpoint omits it.

Obsolete products are a separate axis. A storefront listing only current lines
wants them left out; anything reconciling against a back catalog needs them,
since an obsolete product is still one the store may hold stock of and have
sold. Expect a substantially larger catalog with them on.

API USAGE
---------

Unleashed accounts are metered per month, and paging is usually where an
integration spends its budget without anyone noticing. Page size is a trade-off
in both directions, so this module does not use one number everywhere:

| Setting | Default | Reasoning |
| --- | --- | --- |
| Products, delta read | 500 | A read carrying `modifiedSince` returns only what changed. Page size is a cap, not a promise, so a large page returns a small response and saves requests. |
| Products, full read | 200 | A read of the whole catalog really does build that many complete records per request, which is expensive for Unleashed to serve. |
| Stock on hand | 500 | One compact row per product per warehouse rather than whole product records. |

Which of the two product sizes applies is derived from the query, so cron, Drush
and any other caller get the same treatment without having to ask for it. A
caller that sets its own `pageSize` is left alone, and every configured value is
clamped to the 1..1000 the API accepts.

**Do not simply raise these to the maximum.** Unleashed have asked integrators
not to use `pageSize=1000`, having found that it strains their systems. A larger
page means fewer requests but a heavier one; the defaults above are chosen to
sit between the two rather than at either end.

Worth knowing when estimating a budget:

* Cron syncs products on every run. The first run walks the whole catalog;
  after that it sends `modifiedSince` and only reads what changed.
* Cron syncs stock on hand at most every ten minutes. On a catalog of ~9,000
  products that is roughly 18 requests a run at the default page size, so about
  78,000 a month - worth turning off if you are not consuming the stock table.
* Fetching each product individually costs one **additional** request per
  product, on top of the list pages - a 9,000-product catalog is ~9,000 extra
  requests per run, for a record the list read already returns in full when
  attribute reads are on.
