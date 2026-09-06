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

Configure the Paystack dashboard webhook URL as:

`https://<platform-host>/index.php?action=paystack_webhook`

The endpoint validates `X-Paystack-Signature`, verifies the transaction with
Paystack, checks reference, amount, and currency, and records an idempotency
key before updating the local transaction.

## Database TLS

Upload the provider CA chain as `/etc/secrets/db-ca.pem`. Whenever `DB_SSL_CA`
is configured, hostname and certificate-chain verification are mandatory. A
deployment must not be promoted if the health endpoint fails validation.

## Retention maintenance

The scheduled GitHub workflow calls `run_maintenance` once per day. Configure
these repository secrets before enabling it:

- `PLATFORM_MAINTENANCE_URL`: the full URL ending in
  `index.php?action=run_maintenance`
- `PLATFORM_ADMIN_SECRET`: the platform service's admin secret

Each run is bounded by `PLATFORM_MAINTENANCE_BATCH_SIZE` and
`PLATFORM_MAINTENANCE_MAX_BATCHES`. Sync compaction always preserves the newest
event for every logical row.
