# SnapEX Pay for Blesta

Accept crypto on Blesta invoices. The client chooses **SnapEX Pay (Crypto)**, pays on the SnapEX Pay page
(USDT on TRON, Ethereum, BSC and other networks, or other coins converted to USDT), and the payment is
recorded in Blesta and applied to the invoices automatically.

- No wallets or nodes to run; funds arrive in your SnapEX Pay merchant balance.
- The payment is recorded from the signed webhook or as soon as the client comes back to the
  "payment received" page — whichever comes first. Either way the invoice is first read back from the
  SnapEX Pay API; the webhook body alone never marks anything paid, and a payment is never recorded twice.
- Works with any invoice currency: non-USD amounts are converted to USD with the exchange rates Blesta
  already keeps; the transaction is recorded in the invoice currency.
- Test mode: test invoices confirm by themselves without real money; only staff see the button while it is on.

Requirements: Blesta 6.x (written against the 6.0 gateway API; the non-merchant gateway API is the same
in 5.x), PHP with cURL, a SnapEX Pay merchant account.

> **Connecting to SnapEX Pay?** Create a merchant account, check the pricing and see every ready-made module on **[pay.snapex.pro](https://pay.snapex.pro)**.

## Install

1. Upload the contents of this archive to the Blesta root folder, keeping the path:
   ```
   components/gateways/nonmerchant/snapex_pay/…
   ```
2. Blesta admin → **Settings → Payment Gateways → Available** → **SnapEX Pay (Crypto)** → **Install**.
   Installing creates one table, `snapex_pay_invoices`.
3. In the gateway settings tick **USD** and every currency your invoices are in under **Accepted Currencies**,
   then save. Until a currency is ticked, clients do not see SnapEX Pay on invoices in it.
4. In the SnapEX Pay merchant cabinet create an **API key** with the `invoices.read` and
   `invoices.write` scopes and paste it into **API key**.
5. In the cabinet add a webhook with the URL shown in the gateway settings
   (`https://<your Blesta>/callback/gw/<company id>/snapex_pay/`) and paste the `whsec_…` secret into
   **Webhook secret**. The webhook is required: Blesta has no cron for gateways, so it is how payments
   arrive when the client does not come back to the site.
6. If your invoices are not in USD, add **USD** under **Settings → Company → Currencies** with an exchange
   rate (or let Blesta update rates automatically) so amounts can be converted.
7. Turn on **Test mode**, log in to the client area as a client from the admin area (staff only see the
   button in test mode), pay an invoice on the SnapEX page with a test method and check the invoice is paid.
   Then turn test mode off.

## Settings

| Setting | |
|---|---|
| API key | From the SnapEX Pay cabinet. Stored encrypted. |
| Webhook secret | `whsec_…` from the cabinet webhook settings. Stored encrypted. |
| Network fee paid by | *Merchant*: the client pays exactly the invoice amount. *Client*: the fee is added on top. |
| Payment window, minutes | How long the payment page stays valid (1–1440, default 60). Paying again later opens a fresh one. |
| Test mode | Test invoices, staff only. Test payments are ignored once test mode is off. |
| Partner ID | Optional. The Partner ID of the integrator who set this up (SnapEX Pay referral program). Leave empty if you set it up yourself. |
| API URL | Leave as is. |

## What lands in Blesta

- **Paid in full** → an approved transaction in the invoice currency, applied to the invoices that were
  paid; the transaction ID is the SnapEX Pay invoice number (`INV-…`). The client gets Blesta's usual
  "payment received" email.
- **Paid partly** (the client sent less and did not top up in time) → a *pending* transaction that is not
  applied, with the received amount in its message. Review it under the client's transactions.
- **Expired** without payment → nothing is recorded.
- **Extra payment** after the invoice was closed → an entry in the gateway log; the money is in your SnapEX Pay balance.

Everything the gateway does is in **Tools → Logs → Gateway**.

## Test without money

Test mode creates SnapEX Pay invoices with `environment=test`: they go through the same statuses and send
the same signed webhooks, but never touch the real balance. Pick any method on the payment page — the test
invoice confirms by itself shortly after and Blesta records the payment like a real one.

## Support

support: https://snapex.pro
