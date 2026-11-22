# CoinsPay for XenForo

Let your members pay for user upgrades in cryptocurrency. The member pays on the CoinsPay payment page, XenForo applies the upgrade by itself, and the money goes on to your own wallets.

## Requirements

XenForo 2.2 or 2.3 with the PHP it runs on (7.0 to 8.5), and a [CoinsPay](https://coinspay.app) account with a shop.

## Description

The add-on adds CoinsPay to the payment providers of XenForo. A member buys a user upgrade, or anything another add-on sells through XenForo's payment profiles, picks a coin and a network on the CoinsPay payment page and pays. When the payment is confirmed, XenForo applies the purchase the way it does for any payment provider: for a user upgrade, the member joins its user groups.

Prices stay in your forum's currency. CoinsPay converts the amount at the current rate and holds the coin amount for 30 minutes, so the rate does not change while the member opens their wallet.

A crypto payment cannot renew by itself, so the add-on is for upgrades that do not recur: a fixed length or permanent. XenForo does not let you attach the CoinsPay profile to a recurring upgrade.

### Coins and networks

More than 30 coins and tokens on TRON, Ethereum, BNB Smart Chain, Solana, Bitcoin and Litecoin, among them USDT, USDC, BTC, ETH, BNB, TRX, LTC and SOL. The full list is on [coinspay.app/coins](https://coinspay.app/coins). You can limit the payment page to stablecoins.

### What the add-on takes care of

- Each purchase is applied exactly once, even when the webhook, the member's return and the cron arrive at the same moment.
- When the member comes back from the payment page, the add-on checks the invoice at once instead of waiting for the webhook.
- Webhooks are signed and checked, and the add-on reads every invoice back from CoinsPay instead of trusting the event. If webhooks do not arrive, the cron of XenForo checks unpaid invoices every 5 minutes.
- Money that cannot apply its purchase is not lost: an invoice that expired or was cancelled partly paid, a test payment by a member, an invoice made with a key of the other mode, a purchase XenForo refused or failed to apply. Each one gets an error line in Logs → Payment provider log and a line under "Need your decision" on the payment profile page. A purchase XenForo refused or failed on is tried again every 30 minutes for 30 days and applies by itself once the cause is gone; the line leaves the list when it does.
- An overpayment applies the purchase and leaves a note about the excess, also when the extra money comes later.
- A test key works for admins only, so members get nothing for free while you test.
- English and Russian.

### Why CoinsPay

Payments go straight to your wallets. Each network takes up to five payout addresses with percentage shares, for example 70% to you and 30% to a partner, and nothing is kept on the platform.

The fee is as low as 0.3% of each payout: the rate goes down as the monthly turnover of all your shops grows. There is no monthly or setup fee. For the first 10 days after your first payment, payouts up to $500 carry no fee. You can let the member pay the CoinsPay fee and the network fee on top of the price. Details are on the [pricing page](https://coinspay.app/pricing).

## Installation

1. Get the archive `coinspay-xenforo-<version>.zip` from the releases of this repository, or build it yourself (see [Development](#development)).
2. In the admin control panel open Add-ons and press "Install/upgrade from archive", then choose the archive. XenForo shows this button only with `$config['enableAddOnArchiveInstaller'] = true;` in `src/config.php`. Without it, copy the contents of the archive's `upload/` folder into the root of your forum, where `index.php` is, then find "CoinsPay: crypto payments" on the Add-ons page and press Install.

The add-on adds the payment provider CoinsPay, the tables `xf_coinspay_invoice` and `xf_coinspay_kv`, the cron entry "CoinsPay: check unpaid invoices" and the public route `coinspay/return`, the address the payment page sends the member back to.

The installation guide is also available as a separate document in [English](docs/install-en.md) and [Russian](docs/install-ru.md).

## Configuration

1. Setup → Payment profiles → Add payment profile, choose CoinsPay as the provider and press Proceed. The display title is what members see, for example "Crypto (USDT, USDC, BTC and more)".
2. In the CoinsPay dashboard open your shop:
   - under "API keys" create a key with "Invoices only" access and paste it into "API key". The add-on needs nothing more, and such a key cannot withdraw money;
   - under "Webhook" enter the "Webhook address" the form shows (`https://<your forum>/payment_callback.php?_xfProvider=coinspay`), save, and copy the `whsec_…` secret into "Webhook secret".
3. Save the profile. The panel at the top of the form shows your CoinsPay shop and the key mode. Press "Send test event" in the CoinsPay dashboard and reload the form: the panel shows when the event came.
4. Users → User upgrades: open an upgrade that does not recur and tick the CoinsPay profile under "Payment profile".
5. Make a test payment before you take real ones, as described in [First payment: a test one](#first-payment-a-test-one).

Other settings:

| Field | Default | What it does |
|---|---|---|
| Invoice lifetime | 60 | Minutes the member has to pay, from 15 to 1440 |
| Return automatically | Yes | After the payment the payment page takes the member back to the forum |
| Stablecoins only | No | The member can pay in USDT, USDC and other stablecoins only |
| Member pays the CoinsPay fee / network fee | No | The fees are added to the member's amount |
| Debug log | No | Every step of the add-on goes to Logs → Payment provider log |

The panel also shows when the cron entry "CoinsPay: check unpaid invoices" last ran. XenForo runs its cron as people visit the forum; on a quiet forum, set up a real cron job as the XenForo manual describes.
| API address | Empty | Leave empty: the add-on then uses `https://coinspay.app` |

The prices of your upgrades must be in a currency CoinsPay accepts: XenForo checks it when you save an upgrade with the CoinsPay profile.

## Test mode

With a test key (`cpk_test_…`) invoices are paid with a button on the payment page and no coins move. Only an admin can pay with a test key: a member who picks CoinsPay sees "Payment in cryptocurrency is not available right now".

### First payment: a test one

Every forum is set up its own way: its address, web server rules, cron, other add-ons. Pay one upgrade in test mode first. It takes five minutes and goes through the whole path a real payment takes, with no money moving.

1. In the CoinsPay dashboard create a key with "Invoices only" access and tick "Test key": it starts with `cpk_test_`. Paste it into "API key" of the CoinsPay profile, tick "Debug log" and save.
2. Attach the profile to a user upgrade that does not recur. You can make one for the test, for any small price.
3. Signed in as an admin, open Account upgrades on the forum and buy that upgrade with CoinsPay. On the CoinsPay payment page, marked "Test invoice", pick a coin and press "Pay".
4. Within a minute the upgrade should be active on your account, and Logs → Payment provider log should show a "Payment received" line with the CoinsPay invoice.
5. Clean up: end the test upgrade under Users → User upgrades → Active upgrades, untick "Debug log", and replace the test key with a live one (without "Test key").

If the upgrade does not become active, send the lines of the payment provider log and what the panel of the profile shows to [CoinsPay support](https://t.me/coinspayapp) or support@coinspay.app.

## Removal

Uninstall the add-on on the Add-ons page. It removes the payment provider, the cron entry, the route and the tables `xf_coinspay_invoice` and `xf_coinspay_kv`. Payment profiles made with it stay, hidden, until you install it again.

Uninstall only when no CoinsPay invoice is waiting for payment: with the tables gone, the add-on no longer knows the invoices made before, and a payment that comes for one of them is not applied. Such a payment shows in the CoinsPay dashboard: apply the purchase by hand or refund it.

## Development

```
upload/src/addons/CoinsPay/Payment/
  addon.json, Setup.php           the add-on, its tables and the payment provider
  Payment/CoinsPay.php            the payment provider: form, purchase, webhook
  XfHost.php, XfDb.php            XenForo for the core: purchase requests, payment log, database
  XfTransport.php                 requests to CoinsPay through the HTTP client of XenForo
  Admin.php                       the profile form and its panel
  Cron/Poll.php                   the cron entry
  Pub/Controller/Back.php         coinspay/return
  core/                           the core: API client, decisions, payment, webhook, polling; en and ru texts
  _data/                          cron entry, route and phrase, as XenForo exports them
```

The webhook comes in through `payment_callback.php` of XenForo, and the add-on handles it in full: signature, invoice read back from CoinsPay, decision. A purchase is applied with the provider methods of XenForo (`completeTransaction()` and `log()`), the way its payment callback does, under a claim in the add-on's own table and inside one database transaction with the mark that it is applied, so two concurrent requests cannot apply it twice.

The code uses only what XenForo 2.2 and 2.3 both have: entities and repositories by short name (`XF:PurchaseRequest`), no `Purchase::$updateUrl`. Requests to CoinsPay go through the HTTP client of XenForo, so the proxy and certificate settings of the forum apply, with only the options Guzzle 6 (XenForo 2.2) and Guzzle 7 (2.3) both take. The build checks it.

Checks and the build:

```bash
P=$(pwd)
docker run --rm -v "$P:/app" -w /app composer:2 install
docker run --rm -v "$P:/app" -w /app php:7.4-cli vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:8.5-cli vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:7.4-cli vendor/bin/phpcs -q
php bin/build.php
```

`phpcs` checks PHP 7.0 compatibility. `bin/build.php` writes `dist/coinspay-xenforo-<version>.zip` with `hashes.json` for the file health check of XenForo.

## Changelog

### 0.1.0

- First release.

## License

GPL-3.0-or-later. The full text is in [LICENSE](LICENSE).
