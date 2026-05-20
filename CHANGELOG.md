# Changelog — ETechFlow Back-in-Stock Notification

All notable changes to this module. Adheres to [Semantic Versioning](https://semver.org/).

---

## [1.0.0] — TBD — Customer subscribes, gets emailed when stock returns

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
- Magento UI Component listing grid: Subscription Status, Product, Customer/Email, Store, Subscribed At, Notified At, Status.
- Mass actions: Delete, Notify Now (force-send to bypass queue), Cancel.
- Per-product stats column.

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
