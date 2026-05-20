# ETechFlow Back-in-Stock Notification

Customers subscribe to out-of-stock products. When stock returns, they get an email.

Standalone-first — works on any Magento 2.4.4+ install without other ETechFlow modules. Integrates richer when paired with NDE / BED / ISP.

## Install

```bash
composer require etechflow/module-back-in-stock-notification:^1.0
bin/magento module:enable ETechFlow_BackInStockNotification
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
# Restart php-fpm to clear OPcache (mandatory on prod with opcache.validate_timestamps=0)
```

## Activate the licence

```bash
php tools/generate-license.php --module=back-in-stock-notification --host=<your-domain>
```

Paste the key into **Stores → Configuration → eTechFlow → Back-in-Stock Notification → License Key**.

For multi-module customers, paste the bundle key into the **Bundle License Key** field instead. One key activates every ETechFlow module installed on the host.

## Verify

```bash
bin/magento etechflow:bisn:verify
```

Twelve PASS lines means you're good to go.

## How it works

1. **Customer hits an out-of-stock PDP** → a "Notify me when back in stock" form replaces the disabled Add-to-Cart button.
2. **Form submit** → `etechflow_bisn_subscription` row created. Anonymous (email only) or customer-linked.
3. **Merchant saves stock with qty > 0** → `StockSaveObserver` enqueues a notification for every subscription matching that product + store.
4. **Cron runs every 5 min** → `QueueConsumer` sends batched, rate-limited emails. Default 60 emails/minute.
5. **Customer clicks the email** → one-click unsubscribe (RFC 8058 compliant) OR goes to the PDP to purchase.

## Configuration

`Stores → Configuration → eTechFlow → Back-in-Stock Notification`:

- **License Key** — per-module key (or use Bundle License Key for the suite)
- **Module Enabled** — toggle the whole feature
- **Email Rate Limit** — emails/minute (default 60). Adjust to your SMTP capacity.
- **Subscription Lifetime** — auto-expire unredeemed subscriptions after N days (default 180, 0 = never)
- **eTechFlow Suite Integrations** — NDE / BED / ISP opt-in toggles (hidden if the sibling module isn't installed)
- **Notifications** — sender email, sender name, email subject template

## Compatibility

- Magento Open Source 2.4.4 – 2.4.8
- Adobe Commerce 2.4.4 – 2.4.8
- PHP 8.1 / 8.2 / 8.3 / 8.4
- Hyvä Theme + Hyvä Checkout (PDP form renders via Hyvä-safe block)

## Support

info@etechflow.com — please include your license key + Magento version when reporting issues.
