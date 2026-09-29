# Deployment runbook

## Sync batching rollout

1. Deploy the mobile release that sends sync changes in batches of 200.
2. Deploy the platform API. Its temporary `PLATFORM_SYNC_LEGACY_BATCH_LIMIT`
   default of 1000 keeps older clients working during adoption.
3. Confirm current clients are sending no more than 200 changes per request.
4. Set `PLATFORM_SYNC_LEGACY_BATCH_LIMIT=200` and redeploy the platform API.

The API also enforces a 16 MiB aggregate payload limit regardless of record
count. Do not lower the legacy count limit before the batched client release is
available to every supported installation.

## Paystack webhook

For the shared Paystack account used by license purchases and shop payments,
configure the **Live Webhook URL** as:

`https://license.nexapos.cc/paystack-webhook.php`

The router forwards the original signed payload to the license or platform
handler. Each handler validates `X-Paystack-Signature`; the platform handler
also verifies the transaction with Paystack, checks reference, amount, and
currency, and records an idempotency key before updating the local transaction.

## IntaSend collection

Set `PLATFORM_INTASEND_SECRET_KEY`, `PLATFORM_INTASEND_PUBLISHABLE_KEY`, and
`PLATFORM_INTASEND_WEBHOOK_CHALLENGE` before any device can use the IntaSend
button. Use `PLATFORM_INTASEND_API_BASE=https://sandbox.intasend.com/api/v1`
with sandbox keys until verified end-to-end; the live base is
`https://payment.intasend.com/api/v1`.

Configure the IntaSend dashboard's webhook destination URL as:

`https://<platform-host>/index.php?action=intasend_webhook`

and set its "challenge" field to the same value as
`PLATFORM_INTASEND_WEBHOOK_CHALLENGE`. Unlike Paystack, IntaSend does not sign
webhook bodies with an HMAC - it echoes the configured challenge string back
in every payload's `challenge` field instead, and the endpoint re-fetches the
transaction's authoritative status from IntaSend before trusting it either
way. IntaSend deactivates a webhook after 20 consecutive failed deliveries and
requires contacting their support to re-activate it.

There is currently no disbursement/payout step: money collected into a shop's
IntaSend wallet stays IntaSend-held until that is built (paused pending
IntaSend's answer on whether automatic, no-manual-approval payouts are
available for this account).

## Database TLS

Upload the provider CA chain as `/etc/secrets/db-ca.pem`. Whenever `DB_SSL_CA`
is configured, hostname and certificate-chain verification are mandatory. A
deployment must not be promoted if the health endpoint fails validation.

## Retention maintenance

The scheduled GitHub workflow calls
`https://sync.nexapos.cc/index.php?action=run_maintenance` once per day.
Configure this repository secret before enabling it:

- `PLATFORM_ADMIN_SECRET`: the platform service's admin secret

Each run is bounded by `PLATFORM_MAINTENANCE_BATCH_SIZE` and
`PLATFORM_MAINTENANCE_MAX_BATCHES`. Sync compaction always preserves the newest
event for every logical row.
