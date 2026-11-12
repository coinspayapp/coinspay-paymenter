# CoinsPay for Paymenter — installation

The gateway lets your clients pay Paymenter invoices in cryptocurrency. The money goes to your own wallets; the payment is added to the invoice automatically.

You need:

- Paymenter 1.4.0 or newer with its cron running (`php artisan schedule:run` every minute, as the Paymenter guide sets it up);
- a CoinsPay account with a shop and its payout addresses: [coinspay.app](https://coinspay.app).

## Installation

1. Download `coinspay-paymenter-<version>.zip` from the releases of the repository.
2. In the Paymenter admin area open Extensions → Extensions, press "Upload Extension" and choose the archive.

   Without the admin area: unpack the archive into the Paymenter root, so that the folder is `extensions/Gateways/CoinsPay`.
3. Open Extensions → Gateways → "New gateway". Pick "CoinsPay" in "Gateway" and type the name your clients will see in "Name", for example "Crypto (CoinsPay)".

## Settings

At the top of the gateway settings is the "Status" block with the webhook address of your Paymenter. Keep the page open.

1. In the CoinsPay dashboard open your shop → "API keys" and create a key with "Invoices only" access. Copy it into "API key".

   Such a key can make and read invoices only: it cannot withdraw money, even if it leaks.
2. In the dashboard open "Webhook", paste the address from the "Status" block (`https://<your Paymenter>/extensions/gateways/coinspay/webhook`) and save. Copy the `whsec_…` secret into "Webhook secret".
3. Leave "API address" empty: the gateway uses `https://coinspay.app`.
4. Save the gateway and open it again. The "Status" block should show:
   - "The API key works: shop "…", live mode";
   - no line about a key with full access;
   - the currencies of your Paymenter that CoinsPay does not accept, if there are any: clients do not see the gateway on invoices in them.
5. Press "Send test event" on the webhook page of the dashboard and open the gateway again: the block notes the event.

The other settings:

- **Invoice lifetime**: minutes the client has to pay, from 15 to 1440. 60 by default.
- **Return automatically**: after the payment the CoinsPay page takes the client back to the invoice.
- **Stablecoins only**: the payment page offers only USDT, USDC and other stablecoins.
- **Client pays the CoinsPay fee** and **Client pays the network fee**: the fees are added to what the client pays. The invoice still gets its own amount.
- **Debug log**: more lines in the log of Paymenter, `storage/logs/laravel.log`.

## Test mode

1. Create a test key in the dashboard (it starts with `cpk_test_`) and put it into "API key". The "Status" block says "test mode".
2. With "Test key for admins only" on (the default), clients do not see the gateway. Signed in as an admin, open one of your own invoices in the client area, press "Pay" and pick CoinsPay.
3. On the payment page press the test payment button: no coins move. The payment shows on the invoice as "processing", then it is confirmed.
4. By default a test payment is only written to the log. To see the whole way, invoice paid, service activated, turn on "Add test payments". Do that only on a test copy of Paymenter: for Paymenter it is a real payment.

Put the live key back when you are done.

## Payments that need your decision

The gateway does not add a payment when:

- the invoice was cancelled meanwhile (by you or by the cron for being overdue): Paymenter would mark the cancelled invoice paid and activate the service;
- the invoice is paid already, or belongs to another client now, or was deleted;
- the currency of the invoice changed after the client pressed "Pay".

The invoice never gets more than it still owes: if you lowered it after the client paid, the rest stays in CoinsPay. Such payments, the rest and overpayments are listed in the "Status" block for 30 days with a link to the invoice. The money is in CoinsPay: add the payment to the invoice by hand, or refund the client from the CoinsPay dashboard.

A partial payment is added when its CoinsPay invoice expires: the invoice gets the part received, and the client pays the rest with "Pay" again.

## If webhooks do not arrive

- Check that the webhook address in the dashboard is the one from the "Status" block and that it is reachable from the internet over HTTPS.
- Check that the secret in the gateway is the current one: replacing the webhook in the dashboard makes a new secret.
- Payments still arrive without webhooks, a minute or two later: the gateway asks CoinsPay about open invoices every minute through the cron of Paymenter. If the cron does not run, they arrive when the client opens the invoice.

## Removal

Delete the gateway in Extensions → Gateways and the folder `extensions/Gateways/CoinsPay`. The tables `coinspay_invoices` and `coinspay_kv` stay in the database.
