# Swish – manual verification against the Merchant Swish Simulator (MSS)

Phase A checklist: create payment → MSS callback arrives → order flips to paid, then the lost-callback
fallback (poller) and cancel-on-expiry. Everything runs against the local dev stack
(`docker/development/docker-compose.dev.yml`).

## 0. Prerequisites (once)

1. The MSS test certificate bundle (`MSS_test_3.0.zip` from
   https://developer.swish.nu/documentation/environments#certificates) is extracted at
   `~/Biljettlösning/swish-certs/MSS_test_3.0/client_cert/`. It ships every certificate in `.pem`,
   `.key`, `.csr` and `.p12` form, so **no conversion is needed**. The backend uses three files:
   - `Swish_Merchant_TestCertificate_1234679304.pem` — merchant client certificate incl. the
     Nordea issuing chain (3 certificates in one file), subject `CN=1234679304`, valid to 2027-09-11
   - `Swish_Merchant_TestCertificate_1234679304.key` — matching private key, PKCS#8, **not encrypted**
     (only the `.p12` bundles carry the password `swish`; leave `SWISH_KEY_PASSPHRASE` empty)
   - `Swish_TLS_RootCA.pem` — DigiCert Global Root G2, the CA that issues the Swish server's TLS certificate
   Ignore `Swish_Merchant_TestSigningCertificate_*` (payout payload signing only) and
   `Swish_TechnicalSupplier_TestCertificate_*` (technical-supplier API user, alias 9870474641).
2. Install ngrok and add the reserved domain `iodine-safeness-strainer.ngrok-free.dev` to your account.
3. The three files are copied to `backend/storage/app/swish-certs/` (everything under
   `backend/storage/app/` is git-ignored, so nothing can be committed by accident). The dev compose
   mounts `backend/` at `/var/www/html`, so inside the container they are at
   `/var/www/html/storage/app/swish-certs/…`. To refresh them:
   ```bash
   mkdir -p backend/storage/app/swish-certs
   cp ~/Biljettlösning/swish-certs/MSS_test_3.0/client_cert/Swish_Merchant_TestCertificate_1234679304.{pem,key} \
      ~/Biljettlösning/swish-certs/MSS_test_3.0/client_cert/Swish_TLS_RootCA.pem backend/storage/app/swish-certs/
   ```

4. Dev-stack quirks on this Windows host (all outside the repo):
   - Host port 5432 is taken by a local `postgres.exe`, so always run compose with
     `FORWARD_DB_PORT=5434` exported (`export FORWARD_DB_PORT=5434` before any `docker compose … up`);
     otherwise compose recreates the `pgsql` container on 5432 and it fails to start.
   - `docker/development/pgsql-init/01-create-test-db.sh` must have LF line endings inside the container
     (a CRLF checkout breaks Postgres init with `env: 'bash\r': No such file`). `sed -i 's/\r$//'` it if needed.
   - The `minio/minio` image cannot be pulled here; `backend/.env` uses `FILESYSTEM_PUBLIC_DISK=local`
     and `FILESYSTEM_PRIVATE_DISK=local` instead.
   - The backend container listens on 8080 internally (host port 1234 answers nothing); talk to it
     through nginx (`http://localhost:8080/api`) or from inside the container (`http://localhost:8080`).
   - **Use the dev app over plain HTTP: `http://localhost:8080`.** The self-signed certificate on 8443 is
     valid (CN=localhost, SAN, regenerated with `MSYS_NO_PATHCONV=1`), but Chrome still shows a
     "not private" interstitial for it. Chrome treats `localhost` as a secure context, so the Secure
     checkout cookie and login work over HTTP. For that, the frontend container must be started with the
     API base pointed at 8080 (shell overrides, no tracked file changes):
     ```bash
     export FORWARD_DB_PORT=5434 API_URL_CLIENT=http://localhost:8080/api FRONTEND_URL=http://localhost:8080
     docker compose -f docker-compose.dev.yml up -d frontend
     ```
     and `backend/.env` has `APP_FRONTEND_URL=http://localhost:8080` so email links open without the
     interstitial. Account registration → organizer onboarding was verified in Chrome this way.
     `https://localhost:8443` keeps working if you accept the interstitial once.

## 1. Configure the backend (`backend/.env`, git-ignored)

```dotenv
SWISH_ENABLED=true
SWISH_ENVIRONMENT=mss
SWISH_PAYEE_ALIAS=1234679304
SWISH_CERT_PATH=/var/www/html/storage/app/swish-certs/Swish_Merchant_TestCertificate_1234679304.pem
SWISH_KEY_PATH=/var/www/html/storage/app/swish-certs/Swish_Merchant_TestCertificate_1234679304.key
SWISH_KEY_PASSPHRASE=
SWISH_CA_PATH=/var/www/html/storage/app/swish-certs/Swish_TLS_RootCA.pem
SWISH_CALLBACK_BASE_URL=https://iodine-safeness-strainer.ngrok-free.dev/api
```

Quick mTLS sanity check from inside the container (expects HTTP 404 — unknown id — which proves the
certificate handshake and merchant identity are accepted by MSS):

```bash
$C exec backend curl -s -o /dev/null -w "%{http_code}\n" \
  --cert /var/www/html/storage/app/swish-certs/Swish_Merchant_TestCertificate_1234679304.pem \
  --key  /var/www/html/storage/app/swish-certs/Swish_Merchant_TestCertificate_1234679304.key \
  --cacert /var/www/html/storage/app/swish-certs/Swish_TLS_RootCA.pem \
  https://mss.cpc.getswish.net/swish-cpcapi/api/v1/paymentrequests/00000000000000000000000000000000
```

Then, from `docker/development/`:

```bash
C="docker compose -f docker-compose.dev.yml"
$C exec backend php artisan config:clear
# restart the workers so they pick up the new config
$C exec -d backend php artisan queue:work --queue=default,webhook-queue,occurrences --sleep=3 --tries=3 --timeout=60
$C exec -d backend php artisan schedule:work
```

## 2. Start the tunnel (BEFORE creating a payment)

MSS fires the callback a few seconds after the payment request is created, so the tunnel must be up first.
Target is the dev nginx on host port 8080 (it serves `/api/` → backend and `/` → frontend):

```bash
ngrok http 8080 --domain=iodine-safeness-strainer.ngrok-free.dev
```

Sanity check from any machine:

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST https://iodine-safeness-strainer.ngrok-free.dev/api/public/webhooks/swish/payments \
  -H "Content-Type: application/json" -d '{"status":"PAID"}'
# expect 400 (payload without id is rejected) — proves the route is reachable through the tunnel
```

## 3. Create a test order

```bash
API=http://localhost:8080/api   # or https://localhost:8443/api with curl -k
# token + ids come from: $C exec backend php artisan dev:bootstrap   (prints event/product ids and a Bearer token)
TOKEN=<bearer token>
EVENT=<single_event_id>
PRODUCT=<paid_product_id>
PRICE=<paid price_id>

ORG=<organizer_id>
J='-H Content-Type:application/json -H Accept:application/json'   # Accept is required, otherwise validation errors become 302 redirects

# dev:bootstrap creates a USD organizer/event; Swish is SEK-only, so switch the dev data to SEK first
$C exec pgsql psql -U username -d backend -c "UPDATE events SET currency='SEK' WHERE id=$EVENT; UPDATE organizers SET currency='SEK' WHERE id=$ORG;"

# enable Swish for the event
curl -sk -X PATCH "$API/events/$EVENT/settings" -H "Authorization: Bearer $TOKEN" $J \
  -d '{"payment_providers":["STRIPE","SWISH"]}'

# reserve (orders created after the SEK switch carry currency SEK)
R=$(curl -sk -X POST "$API/public/events/$EVENT/order" $J \
  -d "{\"products\":[{\"product_id\":$PRODUCT,\"quantities\":[{\"price_id\":$PRICE,\"quantity\":1}]}],\"promo_code\":null}")
SHORT=$(echo "$R" | grep -o '"short_id":"[^"]*"' | head -1 | cut -d'"' -f4)
SESSION=$(echo "$R" | grep -o '"session_identifier":"[^"]*"' | head -1 | cut -d'"' -f4)
echo "order=$SHORT session=$SESSION"

# buyer details (moves the order to AWAITING_PAYMENT); each product entry needs product_id AND product_price_id
curl -sk -X PUT "$API/public/events/$EVENT/order/$SHORT?session_identifier=$SESSION" $J \
  -d "{\"order\":{\"first_name\":\"Test\",\"last_name\":\"Buyer\",\"email\":\"buyer@example.test\",\"email_confirmation\":\"buyer@example.test\"},
       \"products\":[{\"product_id\":$PRODUCT,\"product_price_id\":$PRICE,\"first_name\":\"Test\",\"last_name\":\"Buyer\",\"email\":\"buyer@example.test\",\"email_confirmation\":\"buyer@example.test\"}]}"
```

## 4. Happy path: e-commerce payment → callback → order paid

```bash
# start watching the logs in a second terminal:
#   $C logs -f backend | grep -i swish

curl -sk -X POST "$API/public/events/$EVENT/order/$SHORT/swish/payment?session_identifier=$SESSION" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"flow":"ECOMMERCE","payer_alias":"0701234567"}'
# expect 201 with status CREATED. Within ~5-10 s MSS posts the callback through ngrok (source IP 89.46.83.171).
# The payer number must be a real Swedish mobile format (07XXXXXXXX or 467XXXXXXXX, 11 digits international);
# the 10-digit example number in the Swish docs is rejected by our validation. MSS accepts any number.

# poll our status endpoint (this also reconciles server-side if the callback was lost):
curl -sk -H "Accept: application/json" "$API/public/events/$EVENT/order/$SHORT/swish/payment?session_identifier=$SESSION"
# expect status PAID and order.status COMPLETED / order.payment_status PAYMENT_RECEIVED

# ticket + confirmation mail land in Mailpit: http://localhost:8025
```

Expected log lines (order of appearance): `Swish payment request created` → `Swish payment callback received`
→ `Processing Swish callback` → `Swish API call succeeded` (the confirming GET) → `Swish payment completed order`.

## 5. M-commerce variant

```bash
curl -sk -X POST "$API/public/events/$EVENT/order/$SHORT/swish/payment?session_identifier=$SESSION" \
  -H "Content-Type: application/json" -H "Accept: application/json" -d '{"flow":"MCOMMERCE"}'
# expect 201 with a payment_request_token. Deeplink the app would open:
#   swish://paymentrequest?token=<payment_request_token>&callbackurl=<url-encoded return URL>
# MSS still auto-confirms, so the order completes exactly like §4.
```
(Use a fresh order for this: an order can only be paid once.)

## 6. Lost callback → poller reconciles

1. Make the callback undeliverable. Either stop ngrok, or (without touching the tunnel) point the callback at a
   dead path on our own API: `SWISH_CALLBACK_BASE_URL=https://iodine-safeness-strainer.ngrok-free.dev/api/blackhole`
   + `php artisan config:clear`. MSS then gets a 404 from nginx, which you can see in `docker compose logs nginx`.
2. Create a new order (§3) and a payment (§4).
3. Do **not** call the Swish status endpoint (it would reconcile on its own). Poll the plain order endpoint
   `GET …/order/{short_id}?session_identifier=…` instead. The scheduler runs `ReconcilePendingSwishPaymentsJob`
   every 15 s; within 15–30 s the order is COMPLETED. Proof it was the poller: the `swish_payments` row has
   `poll_attempts > 0` and `callback_payload IS NULL`. (Requires `php artisan schedule:work` to be running;
   its log lines go to that process's stderr, not to `docker compose logs`.)
4. Restore `SWISH_CALLBACK_BASE_URL` and `config:clear` afterwards.

Verified 2026-09-14: callback 404'd at the dead path, order completed by the poller after 2 poll attempts.

## 7. Simulated decline and error

MSS simulates outcomes through the `message` field, which we derive from the event title. Temporarily rename
the event title to `RF07` (declined) or `BANKIDCL` (payer cancelled BankID), create a payment, then poll:
MSS reports these "payment result" simulations as status **`ERROR`** with `errorCode` `RF07`/`BANKIDCL` (not as
`DECLINED`); either way our status is terminal, `order.payment_status = PAYMENT_FAILED`, and the order stays RESERVED
so the buyer can retry. Rename the title back **before** the retry, otherwise the retry is declined too.

Verified 2026-09-14: `ERROR`/`RF07` via callback 12 s after creation; a retry on the same order created a new request
which MSS auto-paid, and the order completed.

## 8. Cancel-on-expiry

Set the event's `order_timeout_in_minutes` to 1 (event settings), create an order + payment, wait ~90 s without paying.
The poller cancels the Swish request (`PATCH … cancelled`) and marks the local payment `EXPIRED`;
`GET …/swish/payment` then returns `status: EXPIRED`.

**MSS caveat:** MSS auto-pays every request within seconds, so against MSS the request is already PAID when the
reservation expires and the poller completes it as a late payment (`late_payment: true` in the log) instead of
cancelling. The real cancel path (PATCH → `EXPIRED`, plus the public DELETE) was verified against the local fake
Swish with a 120 s payout delay (see appendix); the flagged case (paid after expiry, no inventory) likewise.

## 9. Double-submit protection

Fire the create call twice in quick succession:
```bash
for i in 1 2; do curl -sk -X POST "$API/public/events/$EVENT/order/$SHORT/swish/payment?session_identifier=$SESSION" \
  -H "Content-Type: application/json" -H "Accept: application/json" -d '{"flow":"MCOMMERCE"}' & done; wait
```
Both responses must carry the **same** `instruction_uuid`; `select count(*) from swish_payments where order_id=…` = 1.

## 10. Refunds (Phase C)

Refunds use the normal back-office endpoint; the Swish branch is chosen from `orders.payment_provider`. The order must
have a `swish_payments` row with status `PAID` and a `payment_reference` (MSS returns one on every paid request).

```bash
TOKEN=<bearer token>; ORDER_ID=<orders.id of a Swish-paid order>
curl -s -X POST "$API/events/$EVENT/orders/$ORDER_ID/refund" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"amount":10,"notify_buyer":false,"cancel_order":false}'
```

Expected:

1. `200` with the order, `refund_status = REFUND_PENDING`. A `swish_refunds` row exists with status `CREATED`,
   `payer_alias` = merchant number, `payee_alias` = the buyer's number, and `location_url` pointing at
   `/swish-cpcapi/api/v1/refunds/<instruction uuid>`.
2. MSS moves the refund to `DEBITED` and then `PAID` within a few seconds and posts the refund callback (twice) to
   `$CALLBACK_BASE/public/webhooks/swish/refunds`. Each callback re-fetches the refund over mTLS before applying it.
3. On `PAID`: `swish_refunds.status = PAID`, one `order_refunds` row (`payment_provider = SWISH`,
   `refund_id` = instruction uuid, `status = succeeded`), `orders.total_refunded` incremented,
   `refund_status = PARTIALLY_REFUNDED` or `REFUNDED`, event statistics updated, `ORDER_REFUNDED` domain event.
4. Lost callback: `ReconcilePendingSwishRefundsJob` runs every 30 s and reconciles `CREATED`/`DEBITED` refunds.
5. Simulated failure: MSS answers `ERROR` when the refund cannot be performed; the refund becomes `ERROR` with the
   Swish `errorCode`, the order gets `refund_status = REFUND_FAILED` and no `order_refunds` row is written.
   A synchronous rejection of the create call (`422` from Swish) is returned as a validation error on `amount`.

Verified against MSS on 2026-09-14: 10 SEK partial refund of a 25 SEK order → `DEBITED` after 4 s, `PAID` after 8 s,
order `PARTIALLY_REFUNDED` with `total_refunded = 10.00`.

## 11. Organizer settings and connection test

Organizer-level settings override the `SWISH_*` environment configuration for that organizer only.

```bash
curl -s "$API/organizers/$ORGANIZER/swish-settings" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
# data is null until saved; meta.environment_fallback_configured tells whether SWISH_* env is active

curl -s -X POST "$API/organizers/$ORGANIZER/swish-settings/test" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
# {"data":{"success":true,"message":"Swish accepted the certificate and the Swish number.","environment":"mss",...}}

curl -s -X PUT "$API/organizers/$ORGANIZER/swish-settings" -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" -d '{
    "enabled": true, "environment": "mss", "payee_alias": "1234679304",
    "cert_path": "/var/www/html/storage/app/swish-certs/Swish_Merchant_TestCertificate_1234679304.pem",
    "key_path": "/var/www/html/storage/app/swish-certs/Swish_Merchant_TestCertificate_1234679304.key",
    "ca_path": "/var/www/html/storage/app/swish-certs/Swish_TLS_RootCA.pem",
    "key_passphrase": ""
  }'
```

Saving with `enabled = true` performs the mTLS probe first (`GET /api/v1/paymentrequests/<random uuid>`; a `404` from
Swish proves the certificate and merchant identity). A rejected certificate returns `422` with the error on
`cert_path` and nothing is saved. The passphrase is stored encrypted and never returned; `has_key_passphrase` tells
whether one is stored and an omitted `key_passphrase` keeps the existing value.

The same flow is available in the back office under **Organizer settings → Swish**, and Swish appears as a payment
method checkbox under **Event settings → Payment & Invoicing**.

## 12. Accounting report

**Organizer → Reports → Accounting Report** (`GET /organizers/{id}/reports/accounting`, CSV at `…/accounting/export`).
One line per day (organizer timezone), event and payment method; refunds are separate negative `REFUND` lines.
Columns: transactions, gross, service fees, VAT 25/12/6/other, VAT total, net excl. VAT. The sum of the gross column
over a period is the amount settled to the bank account before payment-provider fees.

## Appendix: running against a local fake Swish instead of MSS

`config/swish.php` accepts `SWISH_MSS_BASE_URL` (and `SWISH_PRODUCTION_BASE_URL`) overrides. During Phase A the whole
flow was exercised against a tiny Node stand-in for MSS (mTLS, create → PAID after 4 s → callback twice, `RF07`/`BANKIDCL`
in the message field simulate decline/error, `PATCH` cancel with `RP07` once terminal) using self-signed certificates
generated with `openssl` under the git-ignored `backend/storage/app/fake-swish/`. Point the backend at it with

```dotenv
SWISH_MSS_BASE_URL=https://host.docker.internal:9443
SWISH_CERT_PATH=/var/www/html/storage/app/fake-swish/merchant.pem
SWISH_KEY_PATH=/var/www/html/storage/app/fake-swish/merchant.key
SWISH_CA_PATH=/var/www/html/storage/app/fake-swish/ca.pem
```

This is only a development aid; the automated tests mock the HTTP layer and never need it.

## Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| `422 Swish is not available for this event right now.` | Config incomplete or cert files not readable inside the container — check `SWISH_*` paths are container paths and `php artisan config:clear` was run. |
| `Swish rejected the merchant certificate.` (401) | Wrong cert/key pair or passphrase, or production URL with test certs. |
| `403 The Swish number does not match…` | `SWISH_PAYEE_ALIAS` must be `1234679304` for the MSS test certificate. |
| Callback never arrives but status endpoint completes the order | Tunnel down or `SWISH_CALLBACK_BASE_URL` wrong — the poller/status GET covered it, which is by design; fix the tunnel anyway. |
| Nothing happens on callback | Queue worker not running (`queue:work`). |
