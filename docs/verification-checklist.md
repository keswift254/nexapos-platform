# Verification checklist

Run all of this against a Paystack **TEST-mode** secret key
(`sk_test_...`) in `config/platform.local.php`. Do not point this at a
live key until every item below passes.

Base URL for local testing: `http://localhost/nexapos_platform/public/index.php`

## 1. Percentage-split direction (non-skippable)

Get this backwards and money quietly settles the wrong way, with no
error to alert anyone.

1. Register a device and save settlement details with a deliberately
   distinctive `default_percentage_charge` (e.g. temporarily set it to
   25 in `platform.local.php`).
2. Complete one Paystack TEST-mode transaction through that subaccount
   (use a Paystack test card - see Paystack's docs for current test
   card numbers).
3. In the Paystack TEST dashboard, open the transaction's split
   breakdown and confirm which side actually received the 25%: the
   platform (main) account, or the subaccount. It must be the platform.

## 2. Cross-client isolation (non-skippable)

1. Register two devices (A and B), complete `save_settlement_details`
   for both.
2. As A, call `initialize_transaction` and note the `reference` it
   returns.
3. As B (different API key), call `verify_transaction` with A's
   reference.
4. Must get `404 {"status":false,"message":"Transaction not found."}` -
   never A's payment data.

## 3. Happy path + error cases, per endpoint

- `register_device`: first call succeeds (201); immediate second call
  with the same `device_id` returns 409 (or succeeds only if within the
  10-minute pending-and-fresh grace window).
- `save_settlement_details`: succeeds once (200); a second call on the
  same device returns 409. A bad `account_number` returns 422 with
  Paystack's own rejection message.
- `list_banks`: returns entries with both `type: "nuban"` (bank) and
  `type: "mobile_money"` (M-Pesa) for Kenya.
- `initialize_transaction`: called on a device with no settlement yet →
  422. Called on an active device → 200 with `authorization_url`.
- `verify_transaction`: on an unknown reference → 404. On a real
  reference before payment completes → still-pending response, local
  status stays `initialized`.

## 4. Authorization header reaches PHP

If every authenticated call 401s despite a correct
`Authorization: Bearer ...` header, this XAMPP/Apache config may be
stripping it. `Auth::client()` already checks
`HTTP_AUTHORIZATION` / `REDIRECT_HTTP_AUTHORIZATION` / `getallheaders()`
in that order - if all three come back empty, check Apache's
`mod_headers` / `CGIPassAuth` config.

## 5. M-Pesa account_number format

When creating a subaccount with `settlement_type: mpesa`, try the
phone number in the format Kenyan users would naturally type it
(`07XXXXXXXX`) first. Check the create-subaccount response's
`account_name` and `is_verified` fields to confirm Paystack resolved it
correctly; if not, retry with the `2547XXXXXXXX` format and note which
one actually works for future reference.

## 6. End-to-end from the phone

Rebuild the Windows (or Android) app, open Payment Settings, register
the device against `http://localhost/nexapos_platform/public/index.php`,
complete settlement details, then run a full checkout with Paystack
selected through to a confirmed TEST-mode payment.
