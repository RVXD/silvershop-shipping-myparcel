# silvershop/shipping-myparcel

A **MyParcel** label provider for [`silvershop/shipping-labels`](https://github.com/silvershop/silvershop-shipping-labels).
It creates a real carrier label (PostNL) for an order through the [MyParcel SDK](https://github.com/myparcelNL/sdk),
returning the label PDF + barcode/tracking; the shipping-labels framework stores and serves the PDF.

MyParcel is an **NL/BE-account** aggregator (ships NL/BE domestic + EU/international). Merchants outside NL/BE should
use a different adapter.

## Install

```bash
composer require silvershop/shipping-myparcel
```

Pulls in `silvershop/shipping-labels` and `myparcelnl/sdk`. Install `silvershop/shipping` too if you want the parcel
weight derived automatically from your products.

## Configure

Put your MyParcel API key in `.env` (never in config/SiteConfig):

```
SS_MYPARCEL_API_KEY="your-myparcel-key"
```

Everything else is optional config on `SilverShop\ShippingMyParcel\MyParcelProvider`:

```yaml
SilverShop\ShippingMyParcel\MyParcelProvider:
  default_service: 'postnl_parcel'
  default_weight_grams: 1000      # fallback when a parcel has no product weight
  weight_to_grams: 1              # set 1000 if your shop stores product weight in kilograms
  services:
    postnl_parcel:  { title: 'PostNL — Parcel',         carrier_id: 1, package_type: 1 }
    postnl_mailbox: { title: 'PostNL — Mailbox parcel',  carrier_id: 1, package_type: 2 }
    # Add DHL/DPD here once the installed myparcelnl/sdk major provides the carrier — config only, no code.
```

## Use

Create a label for an order (the only entry point; the shipping-labels framework persists the result):

```php
$label = $order->createShippingLabel('myparcel', ['service' => 'postnl_parcel']);
// $label->LabelFile (PDF), $label->TrackingNumber (barcode), $label->TrackingUrl, $label->ProviderRef (consignment id)
```

The label PDF is viewable at `/shippinglabels/label/{ID}`. `track()` returns the barcode + public MyParcel
track-trace URL.

## Cancelling labels

`cancelLabel()` asks MyParcel to delete the shipment and then **verifies it is actually gone** before reporting success,
so it never reports a cancellation that didn't happen.

**Limitation:** MyParcel's API can only delete shipments still in **concept** status. Because this module **produces the
label PDF when it creates the label** — which registers / pre-announces the shipment with the carrier — a created label
is no longer a concept, so `cancelLabel()` on it returns `false` and leaves the label unchanged. To remove such a
shipment, delete it in the **MyParcel dashboard** (possible the same day, before it is collected).

Making created labels cancellable through the API would need a **concept-first flow** (register the shipment as a
concept and produce the PDF only when printing) — a planned v2 change.

## Scope

v1 = labels (PostNL, carrier-extensible via config). Planned: pickup-point + delivery-date selection in checkout,
opt-in auto-create on payment, bulk label creation, concept-first create (API-cancellable labels), more carriers/options.
