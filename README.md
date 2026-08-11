# ULP Dropship Connect

A production WordPress/WooCommerce plugin I designed and built solo for a
research-peptide retailer (shared with the client's permission). It automates
their wholesale partner / dropship program end to end and processes real order
volume every week.

## What it does

Partner e-commerce stores send their paid orders here through standard
WooCommerce webhooks. The plugin verifies each delivery, translates the
partner's SKUs to ours, recreates the order locally at that partner's
contracted wholesale pricing, and holds it until payment is confirmed. Once
released it flows to fulfillment (ShipStation), and when it ships the plugin
pushes tracking + status back into the partner's store through their REST API —
so their customer gets a branded shipping email from them, never from us.

## Architecture

Modular, event-driven — nine single-responsibility classes:

| File | Responsibility |
|---|---|
| `class-ulp-ds-receiver.php` | REST endpoint `POST /wp-json/ulp/v1/dropship/webhook/{partner}` — HMAC-SHA256 signature verification, unsigned-setup-ping handling, paid-status filtering, idempotency, one-shot payload capture |
| `class-ulp-ds-builder.php` | Order construction: SKU mapping, per-line tiered wholesale pricing (customer-group quantity tiers + individual override discovery), fees, partner-selected shipping options, deposit credit application |
| `class-ulp-ds-shipnotify.php` | Ship events → partner tracking email + ShipStation custom-field exports |
| `class-ulp-ds-writeback.php` | Outbound REST client: pushes tracking + Completed status into the partner's store, with cron-backed retries and failure alerting |
| `class-ulp-ds-referral.php` | Affiliate commission creation at order release (fee-excluded commission base) |
| `class-ulp-ds-zelle.php` | Observes the site's Zelle receipt pipeline and auto-releases held orders on exact-amount match, with ambiguity guards |
| `class-ulp-ds-account.php` | Partner-facing "Deposit Credit" statement tab on My Account (WooCommerce endpoint) |
| `class-ulp-ds-admin.php` | Admin: partner CRUD, credit ledger, simulate tool, payload inspectors, durable paginated activity log |
| `ulp-dropship.php` | Bootstrap: custom order status, credit ledger primitives, log table + migrations |

Key design choices:

- **Custom order status as a structural gate.** Unpaid orders sit in
  `wc-ulp-dropship`, a status the warehouse's ShipStation integration never
  exports — unpaid orders are invisible to fulfillment by construction, not by
  convention.
- **Durable audit surfaces.** Every external interaction is logged to a
  dedicated, paginated table; every incoming payload is stored with its order
  for forensic replay; partner credit movements live in an append-only ledger
  with automatic reversal on cancellation.
- **Prices are never trusted from the wire.** Partner payloads select options
  (shipping choice, SKUs); all money — tier pricing, shipping rates, fees —
  resolves from our own configuration.

## Preview / publish workflow

Orders go live in stages — a publishing workflow in e-commerce clothing:

1. **Preview** — a simulate tool runs the full pipeline on pasted order JSON
   without touching live data.
2. **Draft** — real orders land in the held status, inspectable and editable.
3. **Publish** — payment confirmation (manual, deposit credit, or Zelle
   auto-match) releases the order to fulfillment.

A one-shot payload inspector captures raw webhook bodies on demand for
debugging.

## Testing

Pure logic (pricing resolver, SKU-map parser, payload matchers) is covered by
standalone PHP harnesses with WordPress stubbed out — the pricing resolver has
a 28-case table verified against real production data before it was trusted
with money. Integration paths are exercised through the simulate tool, and
every release was proven against a real partner order end to end. The audit
log makes any calculation traceable after the fact.

## Releases & migrations

Now at v1.11.x across many releases with live orders flowing throughout, so
migrations are safe by default: schema changes are one-time version-gated
`dbDelta` migrations (e.g. the activity log moved from a rotating option to
its own table with zero entries lost), rewrite endpoints self-flush once per
version, and data written by newer versions tolerates a version rollback.

## A problem worth telling

Partner webhook onboarding kept failing with signature errors that made the
security layer look broken. Root cause: WooCommerce's webhook *setup ping* is
delivered unsigned and form-encoded — nothing like the signed JSON deliveries
that follow — so any receiver that verifies signatures first rejects the ping,
and the partner's store marks the webhook dead before a single real order
flows. The fix is an explicitly ordered pipeline: recognize and acknowledge
the harmless ping first, then enforce HMAC on everything else — with rejection
logging that distinguishes "no signature" from "wrong secret," which later
turned a partner's misconfigured server from a multi-day mystery into a
minutes-long diagnosis.
