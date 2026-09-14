# Hi.Events Payment Architecture Report

Prepared as groundwork for adding a **Swish** payment gateway to this fork.
Analysed against upstream `HiEventsDev/Hi.Events` at commit `bad7f51` (2026-09-09 on `develop`), then **re-verified against release tag `v2.0.0-rc.1`** (commit `06d30d2e`, 2026-08-26; the `VERSION` file still reads `2.0.0-alpha.1`), which is the base of the fork's `swish-integration` branch.
The tag is 12 commits behind the analysed commit; none of those commits touch payment code. Differences worth knowing: (1) `backend/routes/api.php` line numbers are ~8 lower at the tag (Stripe webhook route at line 642); (2) `organizer_configurations.default_for_currency` (migration `2026_08_27_…`) does not exist at the tag; (3) the offline payment component still uses `dangerouslySetInnerHTML` where `develop` uses `UserGeneratedContent`. Every file path and code fact cited below was spot-checked at the tag.
Laravel 13 backend (`backend/`), React 19 + Vite SSR frontend (`frontend/`), PostgreSQL.

All paths below are relative to the repo root. `backend/app/…` is namespace `HiEvents\…`.

---

## 1. Payment flow end-to-end (Stripe)

### 1.1 Sequence for a paid order

| # | Layer | Component | What happens |
|---|-------|-----------|--------------|
| 1 | Frontend | `frontend/src/components/routes/product-widget/SelectProducts` → `mutations/useCreateOrderPublic.ts` → `api/order.client.ts::orderClientPublic.create` | `POST /public/events/{event_id}/order` with products + promo/affiliate code. |
| 2 | Backend | `Http/Actions/Orders/Public/CreateOrderActionPublic.php` | Validates via `Services/Domain/Order/OrderCreateRequestValidationService`, obtains a checkout session id from `Services/Infrastructure/Session/CheckoutSessionManagementService` (query param → cookie → new `sha1(uuid+random)`), calls handler, sets `session_identifier` cookie (Secure, SameSite=None, Partitioned) and returns `OrderResourcePublic` with `session_identifier` in the body. |
| 3 | Backend | `Services/Application/Handlers/Order/CreateOrderHandler.php` | In a DB transaction with `pg_advisory_xact_lock(eventId)`: checks event is LIVE, **deletes the session's other RESERVED orders for the event** (`OrderManagementService::deleteExistingOrders`), resolves promo/affiliate, re-checks availability with `AvailableProductQuantitiesFetchService` (cache bypassed), creates the order via `Services/Domain/Order/OrderManagementService::createNewOrder` with `status=RESERVED`, `reserved_until = now + event_settings.order_timeout_in_minutes` (schema default 15, admin range 1–120), then `OrderItemProcessingService::process` creates `order_items`, and `updateOrderTotals` writes totals + taxes/fees rollup. |
| 4 | Frontend | `routes/product-widget/CollectInformation/index.tsx` | Collects buyer/attendee details + questions. `PUT /public/events/{event_id}/order/{order_short_id}`. Navigates to `payment` if `order.is_payment_required`, else `summary`. |
| 5 | Backend | `Http/Actions/Orders/Public/CompleteOrderActionPublic.php` → `Services/Application/Handlers/Order/CompleteOrderHandler.php` | In a transaction with `pg_advisory_xact_lock(hashtext(orderShortId))`: verifies session, verifies order is RESERVED, not expired, email not yet set; writes buyer details; sets `payment_status = AWAITING_PAYMENT` and keeps `status = RESERVED` for paid orders (free orders → `NO_PAYMENT_REQUIRED` / `COMPLETED`); inserts attendees with `status = AWAITING_PAYMENT` (or `ACTIVE` when free); stores question answers. For free orders it immediately commits inventory (`ProductQuantityUpdateService::updateQuantitiesFromOrder`). Fires `Events\OrderStatusChangedEvent` (emails + invoice) and, if completed, `DomainEventType::ORDER_CREATED` (outgoing webhooks). |
| 6 | Frontend | `routes/product-widget/Payment/index.tsx` | Reads `event.settings.payment_providers` (`['STRIPE','OFFLINE']`) and shows a hard-coded selector. Stripe tab mounts `Payment/PaymentMethods/Stripe/index.tsx`. |
| 7 | Frontend | `queries/useCreateStripePaymentIntent.ts` | `POST /public/events/{event_id}/order/{order_short_id}/stripe/payment_intent` on mount (react-query, `retry:false`, `staleTime:0`, `gcTime:0`). |
| 8 | Backend | `Http/Actions/Orders/Payment/Stripe/CreatePaymentIntentActionPublic.php` → `Services/Application/Handlers/Order/Payment/Stripe/CreatePaymentIntentHandler.php` | See 1.2. Returns `client_secret`, `account_id`, `public_key`, `stripe_platform`. |
| 9 | Frontend | `forms/StripeCheckoutForm/index.tsx` | Mounts Stripe `<Elements>` + `<PaymentElement>`. On "Pay", `stripe.confirmPayment({redirect:'if_required', return_url: /checkout/{eventId}/{orderShortId}/payment_return?session_identifier=…})` and navigates to `payment_return`. Embedded widget: return URL points at the parent page with `hievents_*` params (`utilites/iframeResize.ts`, `embed/widget.js`). |
| 10 | Stripe → Backend | `POST /public/webhooks/stripe` → `Http/Actions/Common/Webhooks/StripeIncomingWebhookAction.php` | Immediately enqueues a closure job running `IncomingWebhookHandler` and returns 204. See §3. |
| 11 | Backend (queue) | `Services/Domain/Payment/Stripe/EventHandlers/PaymentIntentSucceededHandler.php` | Marks the order paid (see §2.2), commits inventory, activates attendees, records platform fee, fires `OrderStatusChangedEvent` + `ORDER_CREATED`. |
| 12 | Backend (queue) | `Listeners/Order/SendOrderDetailsEmailListener` → `Jobs/Order/SendOrderDetailsEmailJob` → `Services/Domain/Mail/SendOrderDetailsService`; `Listeners/Order/CreateInvoiceListener`; `Listeners/Waitlist/ResolveWaitlistEntryOnOrderCompletedListener`; `Listeners/Event/UpdateEventStatsListener` | Order summary + per-attendee ticket emails (QR), organizer notification, invoice PDF, stats. |
| 13 | Frontend | `routes/product-widget/PaymentReturn/index.tsx` | Polls `GET /public/events/{id}/order/{short_id}` every 5 s (`queries/usePollGetOrderPublic.ts`). After 10 s it stops polling and calls `GET …/stripe/payment_intent` once (`queries/useGetOrderStripePaymentIntentPublic.ts`), which reconciles server-side (step 14). `status === 'COMPLETED'` → navigate to `summary`; `payment_status === 'PAYMENT_FAILED'` → back to `payment?payment_failed=true`. |
| 14 | Backend | `Http/Actions/Orders/Payment/Stripe/GetPaymentIntentActionPublic.php` → `Handlers/Order/Payment/Stripe/GetPaymentIntentHandler.php` | Retrieves the PaymentIntent from Stripe; **if `succeeded` and order not yet `PAYMENT_RECEIVED`, runs `PaymentIntentSucceededHandler::handleEvent` inline** — the upstream "lost webhook" safety net. Note: this endpoint does **not** verify the checkout session. |
| 15 | Frontend | `routes/product-widget/OrderSummaryAndProducts` | Final summary; `OrderResourcePublic` only exposes attendee/ticket details once `status === COMPLETED`. |

### 1.2 Where the Stripe PaymentIntent is created

`Services/Application/Handlers/Order/Payment/Stripe/CreatePaymentIntentHandler::handle(string $orderShortId)`:

1. Loads order with `order_items`, `stripe_payment` (hasOne), `event`. Verifies session (`CheckoutSessionManagementService::verifySession`) → `UnauthorizedException`. Requires `status === RESERVED` and not expired → `ResourceConflictException`.
2. Loads organizer with `OrganizerStripePlatformDomainObject`, `organizer_configuration`, `organizer_vat_setting`; resolves platform (`ca`/`ie`/default) and connected account id (SaaS mode only).
3. **Idempotency per order:** if `order.stripe_payment` already exists, it re-fetches the existing intent's `client_secret` and returns it — no second intent is created.
4. Otherwise `Services/Domain/Payment/Stripe/StripePaymentIntentCreationService::createPaymentIntentWithClient` creates a Stripe Customer (upsert in `stripe_customers`) and the PaymentIntent (`amount` in minor units from `MoneyValue::fromFloat($order->getTotalGross(), currency)`, `metadata.order_id / event_id / order_short_id / account_id`, optional `application_fee_amount`).
5. Persists a `stripe_payments` row (`order_id`, `payment_intent_id`, `connected_account_id`, fee columns, `currency`, `stripe_platform`) through `Repository/Eloquent/StripePaymentsRepository`.

Stripe client construction: `Services/Infrastructure/Stripe/StripeClientFactory::createForPlatform` using `Services/Infrastructure/Stripe/StripeConfigurationService` (reads `config('services.stripe.*')`). Both are singletons registered in `Providers/AppServiceProvider::bindStripeServices`.

### 1.3 Frontend initiation & completion detection (summary)

- Initiation: `Payment/PaymentMethods/Stripe/index.tsx` + `forms/StripeCheckoutForm/index.tsx` (+ `queries/useCreateStripePaymentIntent.ts`).
- Completion: `PaymentReturn/index.tsx` polling order (`usePollGetOrderPublic`, 5 s) then one reconciliation call (`useGetOrderStripePaymentIntentPublic`).
- Session identity in the browser: `utilites/checkoutSession.ts` (sessionStorage `hievents.checkout.session.<shortId>`, seeded from `?session_identifier=`); backend accepts it as query param or cookie.
- Checkout shell: `layouts/Checkout/index.tsx` shows a `Countdown` to `order.reserved_until`, handles abandon (`mutations/useAbandonOrderPublic.ts` → `POST …/abandon`) and the "Powered by" footer (`common/PoweredByFooter`).

---

## 2. Order state machine

### 2.1 Statuses

`backend/app/DomainObjects/Status/`:

| Enum | Cases |
|------|-------|
| `OrderStatus` | `RESERVED`, `CANCELLED`, `COMPLETED`, `AWAITING_OFFLINE_PAYMENT`, `ABANDONED` |
| `OrderPaymentStatus` | `NO_PAYMENT_REQUIRED`, `AWAITING_PAYMENT`, `AWAITING_OFFLINE_PAYMENT`, `PAYMENT_FAILED`, `PAYMENT_RECEIVED` |
| `OrderRefundStatus` | `REFUND_PENDING`, `REFUND_FAILED`, `REFUNDED`, `PARTIALLY_REFUNDED` |
| `AttendeeStatus` | `ACTIVE`, `AWAITING_PAYMENT`, `CANCELLED` |
| `OrderApplicationFeeStatus` | `AWAITING_PAYMENT`, `PAID`, `REFUNDED`, `PAYMENT_WAIVED` |
| `InvoiceStatus` | `PAID`, `UNPAID`, `VOID` |

`orders.payment_provider` (string, nullable) holds `DomainObjects/Enums/PaymentProviders` = `STRIPE | OFFLINE`. Stripe-paid orders get it set **only at payment success** (`PaymentIntentSucceededHandler::updateOrderStatuses`), offline orders at transition time.

### 2.2 Transitions

| From → To | Where |
|-----------|-------|
| (none) → `RESERVED` / payment_status null | `OrderManagementService::createNewOrder` (CreateOrderHandler) |
| `RESERVED` → `RESERVED` + `AWAITING_PAYMENT` (paid) or `COMPLETED` + `NO_PAYMENT_REQUIRED` (free) | `CompleteOrderHandler::updateOrder` |
| `RESERVED`+`AWAITING_PAYMENT` → `COMPLETED`+`PAYMENT_RECEIVED`, `payment_provider=STRIPE` | `Services/Domain/Payment/Stripe/EventHandlers/PaymentIntentSucceededHandler::updateOrderStatuses` (called from webhook **or** from `GetPaymentIntentHandler` fallback) |
| `AWAITING_PAYMENT` → `PAYMENT_FAILED` (status stays `RESERVED`) | `EventHandlers/PaymentIntentFailedHandler` (guarded `updateWhere` on `payment_status = AWAITING_PAYMENT`). Buyer may retry; `PaymentIntentSucceededHandler` accepts orders in `AWAITING_PAYMENT` or `PAYMENT_FAILED`. `SendOrderDetailsService` sends `Mail/Order/OrderFailed` when `isOrderFailed()`. |
| `RESERVED` → `AWAITING_OFFLINE_PAYMENT` (+payment_status same, `payment_provider=OFFLINE`), inventory committed | `Handlers/Order/TransitionOrderToOfflinePaymentHandler` (`POST …/await-offline-payment`) |
| `AWAITING_OFFLINE_PAYMENT` → `COMPLETED`+`PAYMENT_RECEIVED` | `Services/Domain/Order/MarkOrderAsPaidService` (admin `POST /events/{e}/orders/{o}/mark-as-paid`) |
| `RESERVED` → `ABANDONED` | `Handlers/Order/Public/AbandonOrderPublicHandler` (buyer leaves checkout), also `Jobs/Waitlist/ProcessExpiredWaitlistOffersJob` for waitlist orders with a Stripe intent |
| any → `CANCELLED` | `Services/Domain/Order/OrderCancelService::cancelOrder` (admin cancel, refund-with-cancel, occurrence cancellation). Decrements `quantity_sold`/capacities for ACTIVE attendees, cancels attendees, reverts waitlist offers, emails buyer, `ORDER_CANCELLED` domain event, `CapacityChangedEvent`. |
| refund_status null → `REFUND_PENDING` → `REFUNDED`/`PARTIALLY_REFUNDED`/`REFUND_FAILED` | See §5. |

**What marks an order paid:** exactly two code paths set `PAYMENT_RECEIVED`: `PaymentIntentSucceededHandler` (Stripe) and `MarkOrderAsPaidService` (offline). Both then: activate attendees (`AWAITING_PAYMENT → ACTIVE`), increment affiliate sales, fire `OrderStatusChangedEvent` (triggers `SendOrderDetailsEmailListener`, `CreateInvoiceListener`, `UpdateEventStatsListener`, waitlist resolution) and a `DomainEventDispatcherService` `OrderEvent` (outgoing organizer webhooks), and store an `order_application_fees` row via `Services/Domain/Order/OrderApplicationFeeService`.

**Payment success after expiry / on invalid order** (`PaymentIntentSucceededHandler::validatePaymentAndOrderStatus`):
- Order `CANCELLED`/`ABANDONED`, or its occurrence no longer purchasable, or `reserved_until` in the past → `Services/Domain/Payment/Stripe/StripeRefundExpiredOrderService` refunds the full intent, emails `Mail/Order/PaymentSuccessButOrderExpiredMail` (`->beforeCommit()` because the transaction is rolled back) and throws `Exceptions/CannotAcceptPaymentException`. There is a `@todo` acknowledging that completing the order when inventory still allows would be better — this is exactly the behaviour the Swish task asks for.
- Order not in `AWAITING_PAYMENT`/`PAYMENT_FAILED` → `CannotAcceptPaymentException` (logged, event *not* marked handled, so Stripe will retry and log again).

**Timeout / abandoned checkout:** there is **no scheduler that expires orders**. Expiry is purely `reserved_until < now()`, evaluated at read time:
- `CompleteOrderHandler::validateOrder`, `CreatePaymentIntentHandler`, `TransitionOrderToOfflinePaymentHandler`, `AbandonOrderPublicHandler` all reject expired orders with `ResourceConflictException`.
- `OrderResourcePublic` exposes `reserved_until` + computed `is_expired`; the frontend `Countdown` in `layouts/Checkout/index.tsx` drives the UX.
- Expired `RESERVED` rows simply stay in the table and stop counting as reserved.

### 2.3 Inventory reservation

- **Reservation = existence of a `RESERVED` order whose `reserved_until > now()`.** Nothing is decremented at reservation time.
- Availability is computed in `Services/Domain/Product/AvailableProductQuantitiesFetchService::fetchReservedProductQuantities` (raw SQL): `initial_quantity_available - quantity_sold - SUM(order_items.quantity WHERE orders.status='RESERVED' AND orders.reserved_until > NOW())`, further capped by `capacity_assignments` (`used_capacity`) and, for recurring events, per-occurrence capacity via `Repository/Eloquent/OrderItemRepository::getReservedQuantityForOccurrence`. Same rule appears in `Repository/Eloquent/ProductRepository` (lines ~67, ~302) and `OrderRepository::countActivePromoCodeUsage`.
- **Race protection:** `CreateOrderHandler` takes `pg_advisory_xact_lock(eventId)` for the whole create + availability check, so concurrent buyers of the last ticket are serialized: exactly one wins.
- **Commit:** `Services/Domain/Product/ProductQuantityUpdateService::updateQuantitiesFromOrder` increments `product_prices.quantity_sold`, `capacity_assignments.used_capacity`, `event_occurrences.used_capacity`. Called from `CompleteOrderHandler` (free), `PaymentIntentSucceededHandler` (Stripe), `TransitionOrderToOfflinePaymentHandler` (offline).
- **Release:** implicit on expiry (see above), explicit via `OrderCancelService::adjustProductQuantities` (`decreaseQuantitySold`) for completed/offline orders. RESERVED orders are hard-deleted when the same session starts a new order for the same event (`OrderManagementService::deleteExistingOrders`).
- **Consequence for Swish:** any Swish payment request must be bounded by `reserved_until`; a `PAID` result after that time must re-check availability (the SQL above, with `ignoreCache: true`) inside the `pg_advisory_xact_lock(eventId)` before committing, or be flagged.

---

## 3. Webhook handling (Stripe)

- **Endpoint:** `POST /public/webhooks/stripe` (`routes/api.php` line 642 at `v2.0.0-rc.1`) → `Http/Actions/Common/Webhooks/StripeIncomingWebhookAction`. No throttle middleware. Reads raw body + `Stripe-Signature`, **dispatches a queued closure** (`dispatch(static fn (IncomingWebhookHandler $h) => …)->catch(log)`), replies 204 immediately (400 only if dispatching itself throws). With `QUEUE_CONNECTION=sync` (default `.env.example`) it runs inline.
- **Verification:** `Services/Application/Handlers/Order/Payment/Stripe/IncomingWebhookHandler::constructEventWithValidPlatform` tries `Stripe\Webhook::constructEvent` against each configured webhook secret (default/ca/ie) from `StripeConfigurationService::getAllWebhookSecrets`. Failure → `SignatureVerificationException` logged and rethrown (job fails; no retry config beyond queue `--tries=3`).
- **Deduplication:** `Cache::has('stripe_event_'.$event->id)` before processing, `Cache::put(…, 60 min)` after. Second layer in `PaymentIntentSucceededHandler`: `payment_intent_handled_'.$pi->id` (60 min) checked first, set after success **and** on reject-and-refund. Both are cache-based (Redis in Docker, `array` in tests) — not durable across cache flushes; the DB guards (`payment_status` must be `AWAITING_PAYMENT`/`PAYMENT_FAILED`, `updateWhere` conditions) are the real idempotency backstop.
- **Mapping to orders:** by `stripe_payments.payment_intent_id` (PI events, charges via `charge.payment_intent`, refunds via `refund.payment_intent`). Unknown PI → error log + return `null` (event still marked handled).
- **Amount validation:** none — the handler trusts the PI; it only records `amount_received`.
- **Handled events** (`IncomingWebhookHandler::$validEvents`): `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.succeeded/updated` (platform-fee extraction), `refund.created/updated`, `charge.refunded`, `account.updated` (Connect), `payout.paid/updated`. Unknown types are logged and ignored.
- **Idempotency of processing:** the success path runs in one `DatabaseManager::transaction`; events (`OrderStatusChangedEvent`, domain event) fire after commit. Emails are `BaseMail` = queued + `afterCommit()` (see `CLAUDE.md`).

**Pattern to reuse for Swish callbacks:** thin Action → queued handler; DB-row lookup by provider payment id; status guard on the order; cache marker as a fast path; single transaction; post-commit events. Swish differs in that callbacks carry no signature — authenticity must come from mTLS on our outbound `GET` re-check (see §7).

---

## 4. Payment-provider abstraction

### 4.1 Verdict

There is **no payment-provider interface**. The only abstraction is the string enum `PaymentProviders { STRIPE, OFFLINE }` stored on `orders.payment_provider`, `order_refunds.payment_provider`, `order_application_fees.payment_method`, and `event_settings.payment_providers` (JSON array). Everything else is Stripe-specific code living in `…/Stripe/…` namespaces plus a handful of `if (provider === OFFLINE) … else Stripe` branches.

### 4.2 Every place that assumes Stripe specifically

**Backend – config / bootstrap**
- `backend/config/services.php` → `stripe` block (keys, webhook secrets, `primary_platform`).
- `backend/config/app.php` → `saas_stripe_application_fee_*`, `stripe_connect_account_type`, `frontend_urls.stripe_connect_*`.
- `backend/.env.example` → `STRIPE_PUBLIC_KEY`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `APP_STRIPE_CONNECT_ACCOUNT_TYPE`, `APP_SAAS_STRIPE_APPLICATION_FEE_PERCENT`.
- `Providers/AppServiceProvider::bindStripeServices` (singletons + `StripeClient` binding); `Providers/RepositoryServiceProvider` (Stripe repositories).
- `composer.json` → `stripe/stripe-php ^17`.

**Backend – enums / domain**
- `DomainObjects/Enums/PaymentProviders.php` (`STRIPE`, `OFFLINE`), `StripePlatform.php`, `StripeConnectAccountType.php`, `MessagingEligibilityFailureEnum::STRIPE_NOT_CONNECTED`.
- `DomainObjects/OrderDomainObject.php`: `$stripePayment` property, `getStripePayment()/setStripePayment()`, `isRefundable()` whitelists `STRIPE`/`OFFLINE`.
- `Models/Order::stripe_payment()` hasOne; `Models/StripePayment`, `StripeCustomer`, `StripePayout`, `AccountStripePlatform`, `OrganizerStripePlatform`; matching `DomainObjects/*` and `Generated/*`.
- `Repository/Eloquent/OrderRepository` joins `stripe_payments` to decide "account has a paid order" (used by `Services/Domain/Message/MessagingEligibilityService`, SaaS anti-spam).
- `Services/Domain/Order/OrderPlatformFeePassThroughService`, `OrderApplicationFeeCalculationService` (fee maths assume Stripe application fees; SaaS only).

**Backend – flow**
- Routes: `routes/api.php` `…/stripe/payment_intent` (POST/GET), `/webhooks/stripe`, `/organizers/{id}/stripe/*`.
- `Http/Actions/Orders/Payment/Stripe/*`, `Http/Actions/Common/Webhooks/StripeIncomingWebhookAction`, `Http/Actions/Organizers/Stripe/*`.
- `Services/Application/Handlers/Order/Payment/Stripe/*` (create/get intent, incoming webhook, refund), `…/Organizer/Payment/Stripe/*` (Connect).
- `Services/Domain/Payment/Stripe/*` (intent creation, event handlers, refund, expired-order refund, payouts, platform fee extraction, account sync).
- `Services/Infrastructure/Stripe/*` (client factory, configuration).
- `Services/Application/Handlers/Order/RefundOrderHandler` → `if OFFLINE → RefundOfflineOrderHandler else RefundStripeOrderHandler` (Stripe is the default branch).
- `Http/Actions/Orders/Payment/RefundOrderAction` catches `Stripe\Exception\ApiErrorException` and prefixes "Stripe error:".
- `Services/Application/Handlers/EventSettings/DTO/UpdateEventSettingsDTO` default `payment_providers = [STRIPE]`; `Http/Request/EventSettings/UpdateEventSettingsRequest` validates against `PaymentProviders::valuesArray()` (**auto-includes new enum cases**).
- `CompleteOrderHandler` docblock references `PaymentIntentSucceededHandler` (comment only).
- `Jobs/Waitlist/ProcessExpiredWaitlistOffersJob` checks `stripe_payments` existence to decide ABANDON vs delete.
- Mail: `Mail/Order/PaymentSuccessButOrderExpiredMail` (generic wording), `Mail/Order/OrderFailed`.
- Lang: `backend/lang/*.json` contain Stripe strings (`se.json` = Swedish).

**Frontend**
- `src/types.ts`: `PaymentProvider = 'STRIPE' | 'OFFLINE'`, `StripePaymentIntent`, `OrganizerStripeConnectAccount`, etc.
- `src/api/order.client.ts`: `createStripePaymentIntent`, `findOrderStripePaymentIntent`; `src/api/organizer-stripe.client.ts`.
- `src/queries/useCreateStripePaymentIntent.ts`, `useGetOrderStripePaymentIntentPublic.ts`, `useGetOrganizerStripeConnect.ts`, `useCreateOrGetOrganizerStripeConnect.ts`; `src/mutations/useCopyStripeConnectFromOrganizer.ts`, `useDisconnectOrganizerStripeConnect.ts`.
- Checkout: `routes/product-widget/Payment/index.tsx` (state type `'STRIPE' | 'OFFLINE'`, hard-coded two-tab selector, Stripe = "Online"), `Payment/PaymentMethods/Stripe/index.tsx`, `forms/StripeCheckoutForm/*`, `routes/product-widget/PaymentReturn/index.tsx` (Stripe-only reconciliation), `embed/widget.js` (`STRIPE_RETURN_PARAMS`).
- Admin: `routes/event/Settings/Sections/PaymentSettings/index.tsx` (hard-coded `paymentOptions` STRIPE/OFFLINE), `routes/organizer/Settings/Sections/PayoutsSettings/*` + `routes/organizer/Payments/*` (Connect, SaaS only), `common/OrdersTable/index.tsx` (provider badge STRIPE/OFFLINE), `common/OrderDetails`, `modals/ManageOrderModal` (capitalized provider), `modals/PublishEventModal` (blocks publish when STRIPE enabled but not connected — SaaS), `routes/event/EventDashboard/SetupChecklist.tsx`, `layouts/OrganizerLayout`, `utilites/orderHelper.ts::isOrderRefundable` (whitelist STRIPE/OFFLINE), `utilites/analytics.ts`, `utilites/config.ts` (`VITE_STRIPE_PUBLISHABLE_KEY`, unused by checkout — key comes from the API).
- `package.json`: `@stripe/react-stripe-js`, `@stripe/stripe-js`.
- Locales: `src/locales/*.po` (Swedish = `se`), regenerated by `yarn messages:extract` / `messages:compile`.

### 4.3 Recommended minimal-change strategy

**Do not introduce a provider interface.** Refactoring `RefundOrderHandler`, `PaymentIntentSucceededHandler`, `Payment/index.tsx` etc. into a pluggable abstraction would touch many upstream files and create a permanent merge burden. Instead, **mirror the Stripe layout under a parallel `Swish` namespace** (new files only) and accept a small, well-defined set of one-to-three-line edits in upstream files (enum case, route registrations, one dispatch branch, one scheduler line, type unions, selector entries). This is exactly how `OFFLINE` was added upstream.

Where Swish needs behaviour that today lives inside Stripe classes (order completion on payment, expired-order handling), **copy the sequence into a Swish-owned service** rather than calling into `PaymentIntentSucceededHandler` (which takes a `Stripe\PaymentIntent`). The completion sequence is ~40 lines and stable; duplicating it is cheaper than a cross-cutting refactor. If upstream later extracts an `OrderPaymentCompletionService`, the fork can switch to it.

---

## 5. Refunds

- **Entry:** admin UI `modals/RefundOrderModal` → `mutations/useRefundOrder.ts` → `orderClient.refund` → `POST /events/{event_id}/orders/{order_id}/refund` (`{amount, notify_buyer, cancel_order}`), `Http/Actions/Orders/Payment/RefundOrderAction` (authorized via `isActionAuthorized(eventId, EventDomainObject)`). Also `POST …/cancel` with `refund: true` (`Http/Actions/Orders/CancelOrderAction`) and occurrence cancellation (`Jobs/Occurrence/RefundOccurrenceOrdersJob`).
- **Dispatch:** `Services/Application/Handlers/Order/RefundOrderHandler` loads the order; `payment_provider === OFFLINE` → `Handlers/Order/Payment/Offline/RefundOfflineOrderHandler`; **anything else → `Handlers/Order/Payment/Stripe/RefundOrderHandler`**. This is the branch Swish must hook into.
- **Stripe path (asynchronous):** `RefundOrderHandler` (Stripe) → validates `stripe_payment` exists and no `REFUND_PENDING`; optionally `OrderCancelService::cancelOrder`; `Services/Domain/Payment/Stripe/StripePaymentIntentRefundService::refundPayment` (`refunds.create` with idempotency key `refund_{pi}_amount_{minor}`); optional `Mail/Order/OrderRefunded`; sets `refund_status = REFUND_PENDING`. The actual ledger update happens later via webhook `refund.updated`/`charge.refunded` → `EventHandlers/ChargeRefundUpdatedHandler`: dedupes on `order_refunds.refund_id`, increments `orders.total_refunded`, sets `REFUNDED` / `PARTIALLY_REFUNDED` (or `REFUND_FAILED`), updates event statistics (`Services/Domain/EventStatistics/EventStatisticsRefundService`), inserts `order_refunds` (`payment_provider`, `refund_id`, `amount`, `currency`, `status`, `metadata`), dispatches `ORDER_REFUNDED`.
- **Offline path (synchronous):** `RefundOfflineOrderHandler` → `Services/Domain/Order/OfflineOrderRefundService::refundOrder` does the same ledger update immediately with a synthetic `refund_id = offline_<uuid>`.
- **Refundability:** `OrderDomainObject::isRefundable()` (backend) and `utilites/orderHelper.ts::isOrderRefundable` (frontend) both whitelist providers explicitly — must include `SWISH`.
- **Swish mapping:** Swish refunds are asynchronous like Stripe (create refund → callback `PAID`/`ERROR`), so the Stripe two-phase shape (`REFUND_PENDING` now, ledger on callback) is the correct model; the ledger step can reuse the exact logic of `ChargeRefundUpdatedHandler` in a Swish-owned handler.

---

## 6. Configuration & secrets

| Scope | Mechanism | Files |
|-------|-----------|-------|
| Installation-wide secrets | `.env` → `config/services.php` (`services.stripe.*`), `config/app.php` | `backend/.env.example`, `backend/config/services.php`, `backend/config/app.php`; Docker: `docker/all-in-one/.env.example`, `docker/development/.env` |
| Installation-wide feature flags | `config('app.saas_mode_enabled')`, etc. | `config/app.php` |
| Per-organizer payout account (SaaS) | table `organizer_stripe_platforms` (`organizer_id`, `stripe_account_id`, platform, setup completed, details JSON) | migration `2026_05_11_000000_create_organizer_stripe_platforms_table.php`, `Models/OrganizerStripePlatform`, `Repository/Eloquent/OrganizerStripePlatformRepository`, actions `Http/Actions/Organizers/Stripe/*`, routes `/organizers/{organizerId}/stripe/*`, UI `routes/organizer/Settings/Sections/PayoutsSettings` (shown only when `account.is_saas_mode_enabled`) |
| Per-organizer fee config (SaaS) | `organizer_configurations` (belongsTo from `organizers.organizer_configuration_id`) | `2026_05_12_000001_create_organizer_configurations_table.php` |
| Per-organizer VAT | `organizer_vat_settings` hasOne, routes `/organizers/{id}/vat-settings` (GET/POST/PUT) | `Http/Actions/Organizers/Vat/*` — **good template for a small per-organizer settings resource** |
| Per-organizer general settings | `organizer_settings` hasOne (`social_media_handles`, homepage theme, SEO, tracking pixels, event defaults) | `Http/Actions/Organizers/Settings/{Get,PartialUpdate}OrganizerSettingsAction`, `Http/Request/Organizer/Settings/PartialUpdateOrganizerSettingsRequest`, `Handlers/Organizer/Settings/PartialUpdateOrganizerSettingsHandler`, `Resources/Organizer/OrganizerSettingsResource`; frontend `queries/useGetOrganizerSettings.ts`, `mutations/useUpdateOrganizerSettings.ts`, `routes/organizer/Settings/index.tsx` (section registry) |
| Per-event payment toggles | `event_settings.payment_providers` (JSON array of `PaymentProviders`), `offline_payment_instructions`, `order_timeout_in_minutes` | `Http/Request/EventSettings/UpdateEventSettingsRequest`, `Resources/Event/EventSettingsResource(+Public)`, UI `routes/event/Settings/Sections/PaymentSettings/index.tsx` |

**Where per-organizer Swish settings should live:** a **new dedicated table `organizer_swish_settings`** (hasOne from `organizers`), exposed via new routes `GET/PUT /organizers/{organizerId}/swish-settings` and `POST /organizers/{organizerId}/swish-settings/test` — modelled on the VAT-settings trio. Reasons: (a) zero edits to the large upstream `organizer_settings` request/handler/resource/DTO; (b) certificate paths and payee alias are sensitive and deserve their own resource with a restricted response shape; (c) `generate-domain-objects` produces the domain object automatically. Columns: `organizer_id`, `enabled` (bool), `environment` (`mss`|`production`), `payee_alias` (string, 10–11 digits), `cert_path`, `key_path`, `key_passphrase` (encrypted, nullable), `ca_path`, `last_verified_at`, timestamps, soft deletes. Certificate **paths only**; files live outside the repo (e.g. `/etc/hievents/swish/…` mounted into the container) and `.gitignore` should exclude `*.pem/*.p12/*.key`. Installation-wide fallbacks (`SWISH_*` env → `config/swish.php`) let a single-tenant install skip the per-organizer form entirely; resolution order = organizer row → env.

Per-event enablement stays where it is: `event_settings.payment_providers` gains the `SWISH` value through the existing enum-driven validation; the only UI change is one more entry in `paymentOptions`.

---

## 7. Integration plan (minimal diff vs upstream)

Design principle: **new files everywhere possible; upstream edits limited to registration points**. Swish specifics assumed (verify against https://developer.swish.nu/api/payment-request before coding): `PUT /api/v2/paymentrequests/{instructionUUID}` (client-generated UUID → natural idempotency key), `GET /api/v1/paymentrequests/{id}`, `PATCH /api/v1/paymentrequests/{id}` with `[{"op":"replace","path":"/status","value":"cancelled"}]` (only while `CREATED`), callbacks `PAID | DECLINED | ERROR | CANCELLED`, `PUT /api/v2/refunds/{instructionUUID}` + refund callback; m-commerce returns a `PaymentRequestToken` header → `swish://paymentrequest?token=…&callbackurl=…`; amounts are decimal SEK strings; mutual TLS with the merchant client cert + Swish CA.

### Phase A – backend gateway, callback, poller

**New files**
1. `backend/database/migrations/2026_09_14_000000_create_swish_payments_table.php` — `swish_payments`: `id`, `order_id` (FK), `instruction_uuid` (unique, our idempotency key), `swish_payment_id` (nullable, from `Location`), `payment_request_token` (nullable, m-commerce), `flow` (`MCOMMERCE`|`ECOMMERCE`), `payer_alias` (nullable), `payee_alias`, `amount` (decimal 14,2), `currency`, `status` (`CREATED|PAID|DECLINED|ERROR|CANCELLED|EXPIRED|PAID_LATE_FLAGGED`), `error_code`, `error_message`, `callback_payload` (jsonb), `date_paid`, `payment_reference`, `last_polled_at`, `poll_attempts`, timestamps, soft deletes; indexes on `order_id`, `status`, `instruction_uuid`.
2. `…/2026_09_14_000001_create_organizer_swish_settings_table.php` (see §6).
3. `…/2026_09_14_000002_create_swish_refunds_table.php` — `swish_refunds`: `id`, `order_id`, `swish_payment_id` (FK), `instruction_uuid` (unique), `swish_refund_id`, `amount`, `currency`, `status`, `error_code/message`, `callback_payload`, timestamps.
4. Models + domain objects (generated) + repositories + interfaces: `Models/SwishPayment.php`, `Models/SwishRefund.php`, `Models/OrganizerSwishSetting.php`; `Repository/Eloquent/Swish{Payments,Refunds}Repository.php`, `OrganizerSwishSettingsRepository.php` + `Repository/Interfaces/*`; run `php artisan generate-domain-objects` for `DomainObjects/Generated/*Abstract.php` and add thin `DomainObjects/SwishPaymentDomainObject.php` etc.
5. `DomainObjects/Status/SwishPaymentStatus.php`, `DomainObjects/Enums/SwishCheckoutFlow.php`, `DomainObjects/Enums/SwishEnvironment.php`.
6. `Services/Infrastructure/Swish/SwishConfigurationService.php` (resolve organizer row → env fallback; base URLs per environment), `Services/Infrastructure/Swish/SwishClientFactory.php` (Guzzle client with `cert`/`ssl_key`/`verify` = CA path, `timeout` 10 s, `connect_timeout` 5 s), `Services/Infrastructure/Swish/SwishApiClient.php` (createPaymentRequest / getPaymentRequest / cancelPaymentRequest / createRefund / getRefund; retries via existing `Services/Infrastructure/Utlitiy/Retry/Retrier` on `ConnectException`/5xx only; structured logging with `instruction_uuid`, `order_id`, `status`), `Services/Infrastructure/Swish/DTO/*`, `Exceptions/Swish/{SwishApiException, SwishConfigurationException, SwishValidationException}.php`.
7. `Services/Domain/Payment/Swish/SwishPaymentRequestService.php` (build request: `payeePaymentReference` = order `public_id`, `amount` from `order.total_gross`, `currency` = `SEK` guard, `message` ≤ 50 chars from event title, `callbackUrl` = `config('app.url')/api/public/webhooks/swish/payments`), `SwishPaymentCompletionService.php` (**the order-completion sequence copied from `PaymentIntentSucceededHandler`**: guards, `pg_advisory_xact_lock(eventId)`, late-payment availability re-check, update order to `COMPLETED`/`PAYMENT_RECEIVED`/`payment_provider=SWISH`, attendees ACTIVE, `updateQuantitiesFromOrder`, affiliate, application fee row with `PaymentProviders::SWISH`, post-commit `OrderStatusChangedEvent` + `ORDER_CREATED`), `SwishPaymentFailureService.php` (`DECLINED/ERROR/CANCELLED` → `payment_status = PAYMENT_FAILED` guarded like `PaymentIntentFailedHandler`, order stays `RESERVED` so the buyer can retry until `reserved_until`), `SwishLatePaymentHandlingService.php` (PAID after expiry: complete if the availability SQL still allows, else set `swish_payments.status = PAID_LATE_FLAGGED`, `Log::critical`, email organizer + buyer using a `PaymentSuccessButOrderExpiredMail`-style mail — never auto-refund silently), `SwishPaymentStatusReconciliationService.php` (GET → apply terminal status through the two services above; single entry point used by callback, poller and the public status endpoint).
8. `Services/Application/Handlers/Order/Payment/Swish/CreateSwishPaymentHandler.php` (session check, `RESERVED` + not expired, `SWISH` enabled in event settings, **reuse existing `CREATED` row** for the same flow; for e-commerce with a new `payerAlias` cancel the old request then create; row is inserted before the HTTP call so a crash cannot orphan a live request), `GetSwishPaymentStatusHandler.php` (public poll target; calls the reconciliation service when the local row is non-terminal and `last_polled_at` is older than ~3 s), `SwishPaymentCallbackHandler.php` (parse JSON, find by `id`/`payeePaymentReference`, **validate `amount` + `payeeAlias` + `currency` against our row**, ignore if already terminal, then **confirm via GET before applying PAID**), `DTO/*`.
9. `Http/Actions/Orders/Payment/Swish/CreateSwishPaymentActionPublic.php`, `GetSwishPaymentActionPublic.php`, `CancelSwishPaymentActionPublic.php`; `Http/Actions/Common/Webhooks/SwishPaymentCallbackAction.php` (returns 200 immediately, dispatches a queued job like Stripe); `Http/Request/Order/CreateSwishPaymentRequest.php` (`flow`, optional `payer_alias` validated `^(07\d{8}|467\d{8})$` normalised to `46…`).
10. `Jobs/Order/Swish/ReconcilePendingSwishPaymentsJob.php` — selects `swish_payments.status = CREATED` (and `orders.status = RESERVED`), polls each via the reconciliation service; for rows whose order `reserved_until` has passed: attempt `cancel` (ignore 4xx "not cancellable"), mark `EXPIRED`. Scheduled with `->everyFifteenSeconds()->withoutOverlapping()` (sub-minute scheduling is available in Laravel 11+; the all-in-one image runs `schedule:run` in a 60 s loop, so switch that loop to `schedule:work` or accept a 60 s cadence there).
11. `Jobs/Order/Swish/ProcessSwishCallbackJob.php` (queued callback processing) and `Jobs/Order/Swish/CancelSwishPaymentOnAbandonJob.php` (optional: cancel live request when buyer abandons).
12. `backend/config/swish.php`: `enabled`, `environment`, `payee_alias`, `cert_path`, `key_path`, `key_passphrase`, `ca_path`, `base_url_mss`, `base_url_production`, `timeout`, `poll_interval_seconds`.
13. Tests: `tests/Feature/Http/Actions/Webhooks/SwishPaymentCallbackTest.php`, `tests/Feature/Services/Domain/Payment/Swish/*Test.php`, `tests/Unit/Services/Infrastructure/Swish/*Test.php` — mock `SwishApiClient` (Mockery) / Guzzle `MockHandler`; `DatabaseTransactions`, never `RefreshDatabase`.

**Upstream files that must be edited (Phase A)**

| File | Edit | Why |
|------|------|-----|
| `backend/app/DomainObjects/Enums/PaymentProviders.php` | `case SWISH = 'SWISH';` | Stored on orders/refunds/fees; drives `event_settings.payment_providers` validation automatically. |
| `backend/routes/api.php` | +3 public order routes (`…/swish/payment` POST/GET/DELETE) and +2 callback routes (`/webhooks/swish/payments`, `/webhooks/swish/refunds`) | Route registration has no other extension point. |
| `backend/app/Providers/RepositoryServiceProvider.php` | +3 interface→repo bindings | Container bindings live in this map. |
| `backend/app/Console/Kernel.php` | +1 `$schedule->job(new ReconcilePendingSwishPaymentsJob)…` | Poller registration. |
| `backend/app/DomainObjects/OrderDomainObject.php` | add `SWISH` to `isRefundable()` whitelist; optional `$swishPayment` accessor | Refund button visibility / server-side guard. |
| `backend/app/Models/Order.php` | `public function swish_payment(): HasOne` | Needed for `loadRelation(SwishPaymentDomainObject::class)` in handlers. |
| `backend/.env.example`, `docker/all-in-one/.env.example` | `SWISH_*` keys (empty) | Documentation of secrets; no values committed. |
| `backend/config/scramble.php` (optional) | mention callback payloads | Only if the OpenAPI overview documents inbound webhooks; `OpenApiGenerationTest` otherwise passes automatically since public routes get "(public)" appended by `ScrambleServiceProvider`. |

Rejected alternative: editing `PaymentIntentSucceededHandler`/`GetPaymentIntentHandler` to make them provider-agnostic — higher merge risk than a Swish-owned copy.

**Manual verification (delivered after Phase A):** `ngrok http 80` → set `APP_URL` to the ngrok URL (callback must be HTTPS and reachable), create an order via `dev:bootstrap` + `curl`, `POST …/swish/payment` with `flow=ECOMMERCE&payer_alias=4671234567` against MSS, observe callback in `queue:work` logs, confirm `orders.payment_status = PAYMENT_RECEIVED`; then block the callback route (nginx 403) and confirm the poller completes the order within 15 s.

### Phase B – frontend checkout (both variants)

**New files**
- `frontend/src/api/swish.client.ts` (create/get/cancel Swish payment; keeps `order.client.ts` untouched).
- `frontend/src/queries/useGetSwishPaymentPublic.ts` (polling, 2–3 s, `enabled` while non-terminal), `frontend/src/mutations/useCreateSwishPaymentPublic.ts`, `useCancelSwishPaymentPublic.ts`.
- `frontend/src/components/routes/product-widget/Payment/PaymentMethods/Swish/index.tsx` + `Swish.module.scss` + subcomponents: `SwishFlowSwitch`, `SwishMobileFlow` ("Öppna Swish" → `swish://paymentrequest?token=…&callbackurl=<encoded return URL>`; on return show waiting state; falls back to a manual hint if the deeplink does not open), `SwishDesktopFlow` (phone input with `07x`/`+467x` validation, "Väntar på att du bekräftar i Swish-appen"), `SwishWaitingState`, `SwishErrorState` (declined / timed out / cancelled / unavailable, each with "Försök igen" that re-uses the order via cancel-and-recreate), `useIsMobileDevice.ts` hook (UA + `pointer:coarse`, SSR-safe).
- `frontend/src/components/routes/product-widget/SwishReturn/index.tsx` (route `:orderShortId/swish_return` — deeplink return target that resolves via `useGetSwishPaymentPublic` then navigates to `summary`/`payment`).
- `frontend/public/images/swish/` — Swish logo per brand guidelines (from the developer.swish.nu brand kit; not generated).
- `frontend/src/types/swish.ts` (keeps `types.ts` edits to the union only).

**Upstream files that must be edited (Phase B)**

| File | Edit | Why |
|------|------|-----|
| `frontend/src/types.ts` | `PaymentProvider = 'STRIPE' \| 'OFFLINE' \| 'SWISH'` | Shared type used by settings + order. |
| `frontend/src/components/routes/product-widget/Payment/index.tsx` | `isSwishEnabled`, state union, default-method order (Swish first when enabled + mobile), one `<div>` mount + one selector tab with logo, submit branch delegating to the Swish component | Only place the method selector exists; no plugin registry. |
| `frontend/src/router.tsx` | +1 child route `:orderShortId/swish_return` | Deeplink return page. |
| `frontend/src/utilites/orderHelper.ts` | include `'SWISH'` in `isOrderRefundable` | Refund button in admin. |
| `frontend/src/components/common/OrdersTable/index.tsx`, `common/OrderDetails/index.tsx` | provider badge/label for `SWISH` | Admin display (small). |
| `frontend/src/components/layouts/Checkout/index.tsx` (optional) | cancel live Swish request on abandon | Only if `CancelSwishPaymentOnAbandonJob` isn't used server-side. |
| `frontend/src/locales/*.po` (`en`, `se` primarily; other locales get English msgstr or empty) | new strings | Required by `CLAUDE.md`: add translations immediately (`yarn messages:extract && yarn messages:compile`). Swedish locale code is **`se`**. |

### Phase C – refunds, admin config UI, tests

**New backend files**
- `Services/Application/Handlers/Order/Payment/Swish/RefundSwishOrderHandler.php` (mirrors Stripe `RefundOrderHandler`: validate `swish_payment` PAID, no `REFUND_PENDING`, optional cancel, create Swish refund with new `instruction_uuid`, notify buyer, set `REFUND_PENDING`), `Services/Domain/Payment/Swish/SwishRefundService.php`, `SwishRefundCallbackHandler.php` + `Http/Actions/Common/Webhooks/SwishRefundCallbackAction.php` (ledger update = same steps as `ChargeRefundUpdatedHandler`: dedupe on `order_refunds.refund_id`, `total_refunded`, `refund_status`, event statistics, `order_refunds` row with `payment_provider = SWISH`, `ORDER_REFUNDED`), `Jobs/Order/Swish/ReconcilePendingSwishRefundsJob.php`.
- Settings: `Http/Actions/Organizers/Swish/{GetOrganizerSwishSettingsAction, UpsertOrganizerSwishSettingsAction, TestOrganizerSwishSettingsAction}.php`, `Http/Request/Organizer/Swish/UpsertOrganizerSwishSettingsRequest.php`, `Services/Application/Handlers/Organizer/Payment/Swish/*Handler.php` + DTOs, `Resources/Organizer/Swish/OrganizerSwishSettingsResource.php` (never returns the passphrase; returns paths + `last_verified_at`), `Services/Domain/Payment/Swish/SwishConnectivityCheckService.php` (mTLS handshake: `GET /api/v1/paymentrequests/<random uuid>` expecting 404 with a Swish error body = certs OK; TLS/handshake exception = clear error).
- Tests (Feature, mocked HTTP): callback happy path; duplicate callback; unknown payment; amount mismatch; declined; poller reconciles missed callback; double-submit creates one request; refund happy path; late PAID with inventory available completes; late PAID without inventory flags. Unit tests for phone normalisation, request building, config resolution.

**New frontend files**
- `frontend/src/api/organizer-swish.client.ts`, `queries/useGetOrganizerSwishSettings.ts`, `mutations/useUpdateOrganizerSwishSettings.ts`, `mutations/useTestOrganizerSwishSettings.ts`.
- `frontend/src/components/routes/organizer/Settings/Sections/SwishSettings/index.tsx` (form: enabled, environment, payee alias, cert/key/CA paths, passphrase, "Testa anslutning" button; uses `useFormErrorResponseHandler`, `showSuccess/showError`).

**Upstream files that must be edited (Phase C)**

| File | Edit | Why |
|------|------|-----|
| `backend/app/Services/Application/Handlers/Order/RefundOrderHandler.php` | one branch: `if ($order->getPaymentProvider() === PaymentProviders::SWISH->name) return $this->refundSwishOrderHandler->handle($dto);` (+ constructor dependency) | Only refund dispatch point. |
| `backend/app/Http/Actions/Orders/Payment/RefundOrderAction.php` | catch `SwishApiException` alongside `ApiErrorException` | User-facing error mapping. |
| `backend/routes/api.php` | +3 organizer routes, +1 refund callback route | Registration. |
| `backend/app/Console/Kernel.php` | +1 refund reconciliation job | Registration. |
| `backend/app/Models/Organizer.php` | `organizer_swish_setting(): HasOne` | Eager loading in handlers. |
| `frontend/src/components/routes/organizer/Settings/index.tsx` | +1 section entry (`swish-settings`) | Section registry is a literal array. |
| `frontend/src/components/routes/event/Settings/Sections/PaymentSettings/index.tsx` | +1 `paymentOptions` entry (`SWISH`) | Enables the per-event toggle. |
| `frontend/src/components/modals/PublishEventModal/index.tsx` (optional) | treat `SWISH` as a valid paid-ticket method so publish is not blocked when Stripe isn't connected | Only matters in SaaS mode. |
| `backend/lang/se.json` | Swedish strings for new backend messages (`__()` keys are English, so no `en.json` change) | i18n convention. |

### Cross-cutting constraints to respect while implementing

- **Currency:** Swish is SEK-only; `CreateSwishPaymentHandler` must reject non-SEK orders with a translated `ResourceConflictException`, and the event `PaymentSettings` UI should warn when the event currency is not SEK.
- **Fees:** the 6 kr service fee is a Hi.Events *fee* (`taxes_and_fees`), already included in `order.total_gross` and in the sales/`PlatformFeesReport`; Swish charges the gross. No Stripe application-fee logic applies (`OrderApplicationFeeService` row can be recorded with amount 0 / `PAYMENT_WAIVED` for reporting symmetry).
- **Session:** the m-commerce deeplink return may land in a different browser context (Safari vs in-app). Carry `session_identifier` in the `callbackurl` query exactly like `StripeCheckoutForm::buildReturnUrl` does, so `GetOrderActionPublic` can re-issue the cookie.
- **Idempotency layers:** DB unique `instruction_uuid`; status guards on `orders.payment_status`; `updateWhere` conditions; cache marker only as an optimisation.
- **Queue:** the all-in-one image runs `queue:work --queue=default,webhook-queue` and a `schedule:run` loop (`docker/all-in-one/supervisor/supervisord.conf`); dev uses `start-dev.sh`. Callback processing and the poller both need the worker running — document this in the ops runbook.
- **Testing rules** (`CLAUDE.md`): `DatabaseTransactions`, Mockery, Feature tests under `tests/Feature/…` mirroring paths, DB name must end in `_test`, run `php artisan scramble:analyze` after adding routes, `npx tsc --noEmit` for frontend.
- **Comments/dead-code rules** in `CLAUDE.md` apply to all new code (no explanatory comments, no speculative methods).
- **Git attribution:** upstream `CLAUDE.md` forbids AI attribution lines in commits; this fork's own commit policy should be decided before Phase A is committed.

### Summary of upstream files touched across all phases

Backend (9 + env/lang): `DomainObjects/Enums/PaymentProviders.php`, `routes/api.php`, `Providers/RepositoryServiceProvider.php`, `Console/Kernel.php`, `DomainObjects/OrderDomainObject.php`, `Models/Order.php`, `Models/Organizer.php`, `Services/Application/Handlers/Order/RefundOrderHandler.php`, `Http/Actions/Orders/Payment/RefundOrderAction.php`, plus `.env.example` files and `lang/se.json`.

Frontend (7 + locales): `types.ts`, `router.tsx`, `routes/product-widget/Payment/index.tsx`, `utilites/orderHelper.ts`, `common/OrdersTable/index.tsx`, `routes/organizer/Settings/index.tsx`, `routes/event/Settings/Sections/PaymentSettings/index.tsx`, plus locale `.po` catalogs (regenerated) and optionally `common/OrderDetails`, `modals/PublishEventModal`, `layouts/Checkout/index.tsx`.

Everything else is additive under `*/Swish/*` paths.
