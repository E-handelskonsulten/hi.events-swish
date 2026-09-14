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
  -d '{"flow":"ECOMMERCE","payer_alias":"4671234567"}'
# expect 201 with status CREATED. Within ~5 s MSS posts the callback through ngrok.

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

1. Stop ngrok (or run `ngrok` against a wrong port) so MSS cannot deliver the callback.
2. Create a new order (§3) and a payment (§4).
3. Do **not** call the status endpoint. Watch the logs: the scheduler runs `ReconcilePendingSwishPaymentsJob`
   every 15 s; within 15–30 s you should see `Swish payment completed order` triggered by the job and
   the order is COMPLETED. (Requires `php artisan schedule:work` to be running.)

## 7. Simulated decline and error

MSS simulates outcomes through the `message` field, which we derive from the event title. Temporarily rename
the event title to `RF07` (declined) or `BANKIDCL` (payer cancelled BankID), create a payment, then poll:
expect status `DECLINED`/`ERROR`, `order.payment_status = PAYMENT_FAILED`, order still RESERVED so the buyer can retry.
Rename the title back afterwards.

## 8. Cancel-on-expiry

Set the event's `order_timeout_in_minutes` to 1 (event settings), create an order + payment, wait ~90 s without paying.
The poller cancels the Swish request (`PATCH … cancelled`) and marks the local payment `EXPIRED`;
`GET …/swish/payment` then returns `status: EXPIRED`.

## 9. Double-submit protection

Fire the create call twice in quick succession:
```bash
for i in 1 2; do curl -sk -X POST "$API/public/events/$EVENT/order/$SHORT/swish/payment?session_identifier=$SESSION" \
  -H "Content-Type: application/json" -H "Accept: application/json" -d '{"flow":"MCOMMERCE"}' & done; wait
```
Both responses must carry the **same** `instruction_uuid`; `select count(*) from swish_payments where order_id=…` = 1.

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
