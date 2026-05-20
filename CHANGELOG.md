# Changelog — ETechFlow Back-in-Stock Notification

All notable changes to this module. Adheres to [Semantic Versioning](https://semver.org/).

---

## [1.0.2] — 2026-05-20 — Inline-AJAX subscribe (progressive enhancement)

Removes the full-page reload on subscribe. Customer types email, clicks Notify me, the form swaps inline to a "You're on the list" success card. No spinner, no flash-message round-trip.

### Added

- **Inline-AJAX submit via vanilla `fetch()`** — no jQuery, no Alpine, no Magewire dependency. Works on every browser Magento officially supports (Safari 11+, Chrome / Edge / Firefox last 2 versions).
- **Form `data-element` hooks** on the wrapper + form so the script binds reliably without conflicting with anything else on the page.
- **Inline-error rendering** — invalid email / form-key expired / dedupe message all render inline next to the form, not as a flash message.

### Changed

- **`Controller/Subscription/Create.php` is now dual-mode**: when the request includes `X-Requested-With: XMLHttpRequest` or `Accept: application/json`, returns a JSON envelope (`{success, message}`). Otherwise falls back to the v1.0.1 flash-message + redirect-to-referrer flow. Same persistence + dedupe + double-opt-in logic, only the response shape differs.

### Backwards compatibility

- **JavaScript disabled** → plain HTML POST + redirect still works (the `<form>` still has `action` + `method="post"`).
- **AJAX endpoint** is the same URL — no new routes, no extra controllers, no breaking changes for anyone who built integrations against v1.0.0/1.0.1's URL.
- No new DB schema, no new admin config, no new dependencies. Drop-in replacement.

### Theme compatibility

- **Luma**: native browser `fetch()` available — works.
- **Hyvä Theme**: `fetch()` available — works. Inline JS doesn't conflict with Alpine or Hyvä's own scripts.
- **Hyvä Checkout**: BISN's form is on the PDP, not the checkout, so this is irrelevant — but the same form works if a Hyvä Checkout merchant decides to display it inside the checkout for any reason.

### Migration

```
composer update etechflow/module-back-in-stock-notification
bin/magento cache:flush
```

No `setup:upgrade`, no `setup:di:compile` needed — only template + controller changed, no schema or DI tree changes. Customers upgrading from v1.0.1 just need to flush cache.

---

## [1.0.1] — 2026-05-20 — Real-install hotfix (3 bugs caught by live Magento 2.4.8 test)

Caught the moment we ran this module against a real Magento install for the first time. None of these would have shown up in `php -l` / composer-resolution / XSD-validation — only `setup:upgrade` and a full end-to-end run surface them.

### Fixed

- **`etc/db_schema.xml`: invalid `comment="..."` attributes on `<index>` elements.** Magento's declarative-schema XSD only permits `comment` on `<table>` and `<column>`, not on `<index>` or `<constraint>`. `setup:upgrade` failed with: *"Element 'index', attribute 'comment': The attribute 'comment' is not allowed. Line: 96 / Line: 170"*, and the two BISN tables never got created. Five invalid attributes stripped; XML stays well-formed. Subsystem-by-subsystem table comments (on `<table>` and `<column>`) are unaffected.
- **`Observer/StockSaveObserver::getStoreIdsForProduct()` was a stub that returned only `[0]`.** Any store-scoped subscription (the normal case — `store_id=1` for the default store view) was missed, so even when stock came back, the queue stayed empty. Replaced with `getStoreIdsWithActiveSubs(productId)` which queries DISTINCT store_id from the subscription table for active subs on this product. Hot-path-indexed on `(product_id, store_id, status)`. Adds one constructor arg (`CollectionFactory`) — requires `setup:di:compile` after upgrade.
- **Test/Unit/Model/LicenseValidatorTest.php added.** 15 PHPUnit tests, 37 assertions, covering: HMAC determinism, www-stripping, case-insensitive canonicalisation, key uniqueness per host, per-module vs bundle key differentiation, whitespace trimming, 18 dev-host patterns recognised, 5 production-host patterns NOT mistakenly recognised as dev, isValid() on dev/prod hosts, tamper-detection. All 15 pass.

### Migration

```
composer update etechflow/module-back-in-stock-notification
bin/magento setup:upgrade
bin/magento setup:di:compile
# Restart php-fpm to clear OPcache (mandatory on prod with opcache.validate_timestamps=0)
```

If you were on v1.0.0 and `setup:upgrade` failed (you would have seen the index/comment XSD error), the v1.0.1 upgrade applies cleanly — the failed v1.0.0 install didn't create the tables, so re-running setup:upgrade after `composer update` will create them now.

---

## [1.0.0] — 2026-05-20 — Customer subscribes, gets emailed when stock returns

First commercial release. Solves the universal "customer wanted the product, you were OOS, you lost the sale" problem.

### The differentiator vs every existing M2 back-in-stock module

Every existing module on the marketplace shares one or more of these footguns:

1. **No queue / no rate limit** — popular SKU restocks, module synchronously sends 5,000 emails inside one stock-save observer, breaks the stock-import job. Ours queues and rate-limits.
2. **Per-website only, not per-store** — multi-store stores send notifications across all stores indiscriminately. Ours is store-scoped.
3. **No anonymous subscriptions OR no logged-in linking** — either guests can't subscribe (limits lead capture) or logged-in customer subs aren't linked to their account (UX regression). Ours does both, with auto-linking on customer creation.
4. **No one-click unsubscribe** — required by [CAN-SPAM / RFC 8058](https://datatracker.ietf.org/doc/html/rfc8058). Many modules ship without it. Ours has signed-token one-click unsubscribe.
5. **No suite integration** — pure standalone, so a customer whose drop-ship rules (NDE) say "not eligible" still gets a "back in stock!" email. Ours respects NDE eligibility when installed.

### Added

**Foundation**
- `registration.php`, `composer.json` (proprietary licence, soft-deps on NDE / BED / ISP)
- `etc/module.xml` setup_version `1.0.0`
- **DB schema**: 2 tables.
  - `etechflow_bisn_subscription` — customer_id (nullable), email, product_id, store_id, subscribed_at, notified_at, unsubscribe_token, status.
  - `etechflow_bisn_notification_queue` — subscription_id, scheduled_at, attempts, status. Decouples "stock came back" from "email was sent".
- **Admin config** (`etc/adminhtml/system.xml`) — License section + General Settings + eTechFlow Suite Integrations + Notifications. Standard 4-section layout.

**Licensing + Infrastructure**
- `Model/LicenseValidator` — per-domain HMAC + bundle key. `MODULE_ID = back-in-stock-notification`. Shares `BUNDLE_SECRET_FRAGMENTS` with every eTechFlow module.
- `Model/Config` — license-aware `isEnabled()`. Soft-detection of optional integrations (NDE, BED, ISP) via `class_exists`.
- `Model/Performance/Profiler` — Tideways span helper, tags `ETechFlow_BISN_*`.

**Entities + Service Contracts**
- Full Api/Data + Api/Repository contracts for the Subscription entity.
- Model + ResourceModel + Collection + Repository implementations.

**Frontend**
- `Block/Product/NotifyMeForm` + template — renders on PDP when product is out of stock. Replaces the disabled Add-to-Cart with a "Notify me when back in stock" form (email + optional first-name).
- `Block/Customer/SubscriptionList` + template — customer account "My Subscriptions" page. Lists active subscriptions with unsubscribe-from-here button.
- One-click unsubscribe controller — `etechflow_bisn/subscription/unsubscribe?token=<signed>` validates the signed token, removes the subscription, shows a confirmation page.

**Detection + Notification**
- `Observer/StockSaveObserver` — fires on `cataloginventory_stock_item_save_after`. Cheap: bails immediately if no subscriptions exist for the product. When `qty` goes from 0 → positive, enqueues notifications.
- `Cron/QueueConsumer` — runs every 5 min via cron. Pulls due notifications from queue, rate-limited (default 60/min), sends transactional email per subscription, marks as sent. Retries on transient failure up to 3 attempts.
- `Model/Notification/BackInStockSender` — composes + sends the email. Customisable template via Magento's standard email-template system.
- `etc/email_templates.xml` — registers `etechflow_bisn_back_in_stock` template. Default template at `view/frontend/email/back_in_stock.html`.

**Admin**
- Magento UI Component listing grid at **Sales → Operations → Back-in-Stock Subscriptions**: ID, Email, First Name, Product ID, Customer ID, Store View, Status (filterable), Subscribed At, Notified At, per-row Actions (Delete / Cancel).
- Mass actions: **Delete** (removes audit row), **Cancel** (keeps audit row, blocks future notifications), **Notify Now** (force-queues notification — bypasses the qty 0→positive trigger).
- Two ACL resources: `subscriptions_delete` (Delete + Cancel) and `subscriptions_notify_now` (force-queue) so you can grant view-only access to most admin roles.
- Source model for the Status column dropdown.

**Auto-Link Anonymous Subscriptions**
- `Plugin/Customer/AutoLinkSubscriptionsPlugin` — `after`-plugin on `Magento\Customer\Api\AccountManagementInterface::createAccount`. When a guest who previously subscribed by email later registers with the same email, their pending subscriptions auto-link to the new customer (so they appear in My Account → Subscriptions immediately).
- Defensive: never blocks customer creation. Logs failures and continues.

**Lifetime Expiry**
- `Cron/LifetimeExpiryCron` — runs daily at 03:00. Updates `status` from `pending|confirmed` → `expired` for rows older than the admin-configurable lifetime (default 180 days, 0 = never expire). Single UPDATE query — index-only on `(status, subscribed_at)`. Logs the affected row count when nonzero.

**Double Opt-In Confirmation Email**
- `Model/Notification/ConfirmSender` — sends the confirmation email when the admin "Require Double Opt-In" toggle is on. Best-effort — if SMTP fails, the pending subscription stays in DB so the customer can re-trigger from the PDP form.
- Default template at `view/frontend/email/confirm.html` (configurable via Marketing → Email Templates).

**Optional eTechFlow Suite Adapters**
- `Model/Adapter/NdeEligibilityAdapter` — when NDE is installed + admin opted in, "in stock" is determined by NDE's `IneligibilityChecker::isEligible()` instead of raw `qty > 0`. Falls back to direct stock check when NDE isn't present.
- `Model/Adapter/BedEtaAdapter` — when BED is installed, restock notifications include BED's per-product ETA in the email if the restock is itself a future backorder.
- `Model/Adapter/IspStoreAdapter` — when ISP is installed, customers can opt to subscribe to a specific pickup-store's stock (not just the global stock).

**Verify CLI**
- `bin/magento etechflow:bisn:verify` — 12 checks covering license, config, all 2 DB tables, repository via DI, observer registration, cron registration, email-template registration. Exit 0 on full pass, 1 on any failure.

### Standalone-first architecture

Same pattern as ISP. This module works **fully standalone** — no other ETechFlow module required. The integrations with NDE / BED / ISP are **opt-in enhancements** soft-detected via `class_exists`. If the sibling module isn't installed, BISN falls back to its own self-contained logic.

| Optional pairing | Standalone | With pairing |
|---|---|---|
| **+ NDE** | "In stock" = `qty > 0` | "In stock" = NDE eligibility rules (respects drop-ship + supplier mode) |
| **+ BED** | Generic "Back in stock!" | "Back on Jun 12!" (uses BED's ETA) |
| **+ ISP** | Global stock check | Optional per-store subscription |

### Compatibility

- Magento Open Source 2.4.4 – 2.4.8
- Adobe Commerce 2.4.4 – 2.4.8
- PHP 8.1 / 8.2 / 8.3 / 8.4
- Hyvä Theme + Hyvä Checkout (PDP form renders via Hyvä-safe block)
