Commerce Unleashed Invoice
==========================

Mirrors Unleashed sales invoices onto Commerce invoices.

INTRODUCTION
------------

Unleashed is the source of truth. The Invoices endpoint is **read-only** -
there is no create or update operation - so invoices are copied here and never
sent back, and every design decision in this module follows from that:

* The invoice **state comes from the payload**, not from anything that happens
  on this site. A local transition would be undone by the next read.
* The invoice is fetched **by order number, one request per order**. A tenant
  can hold hundreds of thousands of invoices; mirroring wholesale would build
  a copy of something nobody asked to browse. An order is the only unit anyone
  actually looks at.
* An invoice that has gone from Unleashed is marked **deleted rather than
  removed**, because a record a customer has already seen should not silently
  disappear.

REQUIREMENTS
------------

* [Commerce Invoice](https://www.drupal.org/project/commerce_invoice)
* Commerce Unleashed, configured with API credentials

HOW IT WORKS
------------

The join is the order number: `commerce_unleashed` sends the Drupal order
number to Unleashed as `OrderNumber` when an order syncs, so an order can be
looked up later with `GET /Invoices?orderNumber=...`.

When an order **enters** one of the configured states, a job is queued to read
its invoice. Keyed on the state rather than on a named transition, because a
site can rename a transition, add a second route into the same state, or place
an order straight into it, and all three mean the same thing here.

If Unleashed has not raised the invoice yet the job retries - hourly, up to
five times - rather than failing. That is the ordinary case for an order that
has only just completed.

CONFIGURATION
-------------

`Commerce => Configuration => Unleashed invoices`

* **Mirror invoices from Unleashed** - the master switch.
* **Read the invoice when an order reaches** - which order states trigger a
  read. Pick the state that means the order has been invoiced in Unleashed.
  Orders already in that state are left alone; see the backfill command.
* **Read in the background** - queues the read instead of making it while the
  order is saved. Leave this on: an order should not fail to save, or hang,
  because Unleashed is slow.

BACKFILL
--------

Nothing happens automatically to orders that were already in a trigger state.
One API request per order, so it is deliberately opt-in and bounded:

```
drush commerce-unleashed:invoices:backfill --limit=50
drush commerce-unleashed:invoices:backfill --since=2026-01-01 --store=2
```

Orders that already carry an invoice are skipped, so the command can be run
repeatedly to work through a backlog at a pace you are happy to spend.

THE INVOICE TYPE AND WORKFLOW
-----------------------------

The module ships an `unleashed` invoice type and an `invoice_unleashed`
workflow whose states are the ones Unleashed reports:

| Unleashed | State |
| --- | --- |
| `Parked` | parked |
| `Completed` | completed |
| `PaymentReceived: true` | paid |
| `Deleted` | deleted |

Payment outranks status; deletion outranks payment. An unrecognized status
becomes `parked` rather than being guessed at - `parked` claims least, and a
mirror that invented "completed" would be asserting something the source never
said.

This is deliberately **not** commerce_invoice's default vocabulary. A state
this site can reach but Unleashed cannot express is a state the mirror could
never restore after the next read. `draft` is avoided in particular:
commerce_invoice hides draft invoices from customers and skips number
generation for them, neither of which suits a record that arrives complete and
already numbered.

The invoice number is Unleashed's `InvoiceNumber`, and the type ships with no
number pattern so nothing local can invent one.

TOTALS
------

A Commerce invoice total is **derived** from its items plus adjustments; it
cannot be assigned, and an item's total is recalculated as unit price times
quantity on every save. To reproduce Unleashed's figures exactly:

* Each line carries its **effective** unit price - `LineTotal / quantity`,
  after any discount - so the recalculation lands back on the line total. The
  list price and discount rate are kept in the item's `data`.
* Invoice tax is added as a **single tax adjustment** carrying `TaxTotal`,
  because the invoice states one tax total and that is the number to match.

After each read the mirrored total is compared with the total Unleashed
reported, and a mismatch is logged rather than shown silently.

API USAGE
---------

One request per order read, and nothing on cron. A backfill costs exactly one
request per order it reads. See the API usage notes in Commerce Unleashed's
own README for how that fits a monthly budget.
