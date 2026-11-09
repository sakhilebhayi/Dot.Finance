# Dot.Emall to Dot.Finance: seller sales contract

Status: **contract defined, not yet connected.** Dot.Emall has no sender for this today (see `README.md`). This side (`POST /api/integrations/emall/sales`, `EmallSalesImport`, the review screen and `EmallSalesPoster`) is built and tested against payloads written to this contract.

## Request

`POST {finance}/api/integrations/emall/sales`, `Content-Type: application/json`.

| Header | Value |
|---|---|
| `X-InfoDot-Timestamp` | unix time in seconds, within five minutes of this server |
| `X-InfoDot-Signature` | `sha256=` + hex `HMAC-SHA256(secret, "{timestamp}.{raw body}")`, secret = `FINANCE_EMALL_SECRET` on both sides |

Sign the exact bytes you send. A bare hex digest is also accepted.

```json
{
  "delivery_id": "emall-2026-11-12-0001",
  "company_uuid": "3f2b8c1e-9a4d-4e7b-8c55-1d2e3f4a5b6c",
  "currency": "ZAR",
  "lines": [
    {
      "order_item_id": "12345",
      "order_ref": "ORD-2026-0077",
      "seller_ref": "u-9",
      "sold_on": "2026-11-12",
      "description": "Handwoven basket x2",
      "quantity": 2,
      "unit_price_cents": 11500,
      "discount_cents": 0,
      "total_cents": 23000,
      "tax_code": "ZA_VAT_STD",
      "vat_cents": 3000,
      "fee_cents": 2300,
      "currency": "ZAR"
    }
  ]
}
```

- `delivery_id` (3 to 100 characters of `A-Za-z0-9._:-`) is unique per delivery. `company_uuid` is InfoDot's id for the seller's business and must match a company ledger here. At most 500 lines and 512 KB per delivery.
- Prices are **VAT-inclusive** minor units (whole integers). `total_cents` is what Emall believes the line comes to; it is checked, not trusted.
- `tax_code` is one of this app's codes (`ZA_VAT_STD`, `ZA_VAT_ZERO`, `ZA_VAT_EXEMPT` ...). `vat_cents` and `fee_cents` are optional. `fee_cents` is the marketplace commission Emall keeps.

## What this side does with it

1. Authenticates (generic 401 on any failure), validates the shape (a delivery with any malformed line is refused whole with 422, nothing stored), maps `company_uuid` to a ledger (404 if unknown, after authentication).
2. **Idempotency.** The same `delivery_id` with identical bytes is a 200 replay; with different bytes it is a 409. An `order_item_id` already staged or posted in that ledger is never imported again, whatever a later delivery says.
3. **Recomputation.** Gross is `quantity x unit_price_cents - discount_cents`; a different `total_cents` is flagged and the recomputed figure used. When `tax_code` is given VAT is recomputed with the shared tax calculator (inclusive, rule version from the rule book) and a difference of more than one cent is flagged; VAT sent without a code is flagged as unverifiable; a fee larger than the sale is flagged. The fee itself cannot be recomputed and is taken as claimed. A flagged line is stored as "needs attention", with what was claimed kept beside it, and cannot be posted.
4. **Nothing is journalled on arrival.** Lines wait on `/integrations/emall`. A person with the journal capability posts a clean line through `JournalPoster`:

```
Dr  Receivables (what Emall owes the seller: gross - fee)
Dr  Cost of sales, or the expense account chosen (the fee)
Cr  Sales (gross - VAT)
Cr  VAT output (VAT, tagged with the tax code)
```

   source `import_batch`, id `emall:{order_item_id}`. Posting is refused when accounting is off, the period is closed, the amount needs approval under the ledger's journal policy, or the line is not in the ledger's base currency (no exchange rate is invented).

## Responses

`201 {"status":"stored","received":n,"staged":n,"needs_attention":n,"already_imported":n}`, `200` replay, `401`, `404`, `409`, `413`, `422`.

## Owner steps

Build the sender in Dot.Emall (it needs a fee model and per-line VAT first), generate a secret with `php -r 'echo bin2hex(random_bytes(32));'`, set `FINANCE_EMALL_SECRET` here and Emall's matching setting there. Decide which expense account holds marketplace fees (the default is cost of sales).
