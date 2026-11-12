# CoinsPay for Paymenter

Let your clients pay Paymenter invoices in cryptocurrency. The client pays on the CoinsPay payment page, the payment is added to the invoice automatically, and the money goes on to your own wallets.

## Requirements

Paymenter 1.4.0 or newer (PHP 8.2 or newer, as Paymenter needs) with the cron of Paymenter running, and a [CoinsPay](https://coinspay.app) account with a shop.

## Description

The client presses "Pay" on an invoice, picks CoinsPay, then a coin and a network on the CoinsPay payment page, and pays. When the payment is confirmed, the gateway adds it to the invoice, and Paymenter carries on as usual: the invoice is paid, the service is activated or renewed, the client gets the email.

The amount is what is left to pay on the invoice at the moment the client presses "Pay". CoinsPay converts it at the current rate and holds the coin amount for 30 minutes.

### Coins and networks

More than 30 coins and tokens on TRON, Ethereum, BNB Smart Chain, Solana, Bitcoin and Litecoin, among them USDT, USDC, BTC, ETH, BNB, TRX, LTC and SOL. The full list is on [coinspay.app/coins](https://coinspay.app/coins). You can limit the payment page to stablecoins.

### What the gateway takes care of

- The invoice gets what actually came. A payment on its way shows on the invoice as "processing". If the client pays only a part and the CoinsPay invoice expires, that part is added to the invoice, and the client pays the rest with a new payment. A late rest of the same CoinsPay invoice raises the part to the whole.
- Each payment is added exactly once, even when the webhook, the client's return and polling arrive at the same moment. Paymenter before 1.5.7 does not guard against that itself.
- If the invoice is cancelled, paid another way or its amount changes, the gateway cancels the open CoinsPay invoice. The next "Pay" creates one for the new amount.
- A payment for an invoice that was cancelled meanwhile is not added: Paymenter would mark the cancelled invoice paid and activate the service. Neither is one for an invoice whose currency changed. The invoice never gets more than it still owes. Such payments, the excess and payments for deleted invoices are listed in the "Status" block of the gateway settings for your decision.
- In a currency without cents (JPY, KRW…) CoinsPay asks the whole amount, and the payment pays the invoice in full.
- Webhooks are signed and checked. The gateway also asks CoinsPay about open invoices every minute through the scheduler of Paymenter and when a client opens an invoice.
- The "Status" block shows the shop of the key, the test or live mode, the webhook address to put into the CoinsPay dashboard, whether the test event came, and the currencies of your Paymenter that CoinsPay does not accept.
- The API key and the webhook secret are stored encrypted.
- English and Russian.

### Why CoinsPay

Payments go straight to your wallets. Each network takes up to five payout addresses with percentage shares, for example 70% to you and 30% to a partner, and nothing is kept on the platform.

The fee is as low as 0.3% of each payout: the rate goes down as the monthly turnover of all your shops grows. There is no monthly or setup fee. For the first 10 days after your first payment, payouts up to $500 carry no fee. You can let the client pay the CoinsPay fee and the network fee on top of the invoice. Details are on the [pricing page](https://coinspay.app/pricing).

## Installation

1. Get the archive `coinspay-paymenter-<version>.zip` from the releases of this repository, or build it yourself (see [Development](#development)).
2. In the Paymenter admin area open Extensions → Extensions, press "Upload Extension" and choose the archive. Or unpack it so that the folder is `extensions/Gateways/CoinsPay` in the Paymenter root.
3. Open Extensions → Gateways → "New gateway", pick "CoinsPay" and give it the name your clients see, for example "Crypto (CoinsPay)".

The installation guide is also available as a separate document in [English](docs/install-en.md) and [Russian](docs/install-ru.md).

## Configuration

1. In the CoinsPay dashboard open your shop:
   - under "API keys" create a key with "Invoices only" access and paste it into "API key". The gateway needs nothing more, and such a key cannot withdraw money;
   - under "Webhook" enter the address the "Status" block shows, `https://<your Paymenter>/extensions/gateways/coinspay/webhook`, save, and copy the `whsec_…` secret into "Webhook secret".
2. Leave "API address" empty: the gateway then uses `https://coinspay.app`.
3. Save the gateway. The "Status" block shows the shop, the key mode and the currencies. Press "Send test event" in the CoinsPay dashboard and open the gateway again: the block notes the event.

Other settings:

| Field | Default | What it does |
|---|---|---|
| Invoice lifetime | 60 | Minutes the client has to pay, from 15 to 1440 |
| Return automatically | Yes | After payment the CoinsPay page takes the client back to the invoice |
| Stablecoins only | No | The client can pay in USDT, USDC and other stablecoins only |
| Client pays the CoinsPay fee / network fee | No | The fees are added to the client's amount; the invoice gets its own amount |
| Test key for admins only | Yes | With a test key, clients do not see the gateway |
| Add test payments | No | Whether an admin's test payments are added to the invoice |
| Debug log | No | More lines in the log of Paymenter, `storage/logs` |

The gateway is hidden on invoices in currencies CoinsPay does not accept.

## Test mode

With a test key (`cpk_test_…`) invoices are paid with a button on the payment page and no coins move. Only a signed-in admin sees the gateway, on the admin's own invoices.

By default a test payment is only written to the log. An added test payment is a real payment for Paymenter, with services activated and emails sent, so turn on "Add test payments" only on a test copy of Paymenter.

## Removal

Delete the gateway in Extensions → Gateways and the folder `extensions/Gateways/CoinsPay`. The tables `coinspay_invoices` and `coinspay_kv` stay in the database; drop them by hand if you like.

## Development

```
CoinsPay/CoinsPay.php            the gateway: settings, "Pay", who sees it, install hooks
CoinsPay/Lib/Bridge.php          routes (webhook, return), the invoice listener, the scheduler
CoinsPay/Lib/PaymenterHost.php   the only class that knows Laravel and the models of Paymenter
CoinsPay/Lib/                    the rest: no framework, covered by unit tests
CoinsPay/lang/                   en and ru texts
```

Each CoinsPay invoice is one transaction on the Paymenter invoice, with the CoinsPay invoice id as the transaction id. The amount of that transaction only grows. Recording it goes under a named lock of the invoice and a row claim in the gateway's own table, so two concurrent requests cannot add it twice.

Unit tests, the PHP compatibility check and the build:

```bash
P=$(pwd)
docker run --rm -v "$P:/app" -w /app composer:2 composer install
docker run --rm -v "$P:/app" -w /app php:8.2-cli vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:8.5-cli vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:8.2-cli vendor/bin/phpcs -q
python bin/build.py
```

`bin/build.py` writes `dist/coinspay-paymenter-<version>.zip`.

## Changelog

### 0.1.0

- First release.

## License

GPL-3.0-or-later. The full text is in [LICENSE](LICENSE).
