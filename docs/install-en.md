# CoinsPay for XenForo: installation

The add-on lets members pay for user upgrades in cryptocurrency on the CoinsPay payment page. It works on XenForo 2.2 and 2.3. You need a [CoinsPay](https://coinspay.app) account with a shop.

## 1. Install the add-on

1. In the admin control panel open Add-ons, press "Install/upgrade from archive" and choose `coinspay-xenforo-<version>.zip`.

   XenForo shows this button only with `$config['enableAddOnArchiveInstaller'] = true;` in `src/config.php`. Without it, copy the contents of the archive's `upload/` folder into the root of your forum, where `index.php` is, then find "CoinsPay: crypto payments" on the Add-ons page and press Install.

## 2. Make a payment profile

1. Setup → Payment profiles → Add payment profile, choose CoinsPay and press Proceed. The display title is what members see, for example "Crypto (USDT, USDC, BTC and more)".
2. In the CoinsPay dashboard open your shop:
   - under "API keys" create a key with "Invoices only" access. For the first payment tick "Test key": the key starts with `cpk_test_`. Paste it into "API key";
   - under "Webhook" enter the "Webhook address" the form shows, save, and copy the `whsec_…` secret into "Webhook secret".
3. Save the profile. The panel at the top of the form should show your CoinsPay shop. Press "Send test event" in the CoinsPay dashboard and reload the form: the panel shows when the event came.

## 3. Attach it to an upgrade

Users → User upgrades: open an upgrade that does not recur (a fixed length or permanent) and tick the CoinsPay profile under "Payment profile". A crypto payment cannot renew by itself, so XenForo keeps the profile off recurring upgrades. The price must be in a currency CoinsPay accepts.

## 4. First payment: a test one

1. Signed in as an admin, open Account upgrades on the forum and buy the upgrade with CoinsPay. Only admins can pay with a test key.
2. On the CoinsPay payment page, marked "Test invoice", pick a coin and press "Pay".
3. Within a minute the upgrade should be active on your account, and Logs → Payment provider log should show a "Payment received" line with the CoinsPay invoice.
4. End the test upgrade under Users → User upgrades → Active upgrades and replace the test key with a live one (without "Test key").

If the upgrade does not become active, send the lines of the payment provider log and what the panel of the profile shows to [CoinsPay support](https://t.me/coinspayapp) or support@coinspay.app.

## Removal

Uninstall the add-on on the Add-ons page. It removes the payment provider, its cron entry and route, and the tables `xf_coinspay_invoice` and `xf_coinspay_kv`. Uninstall only when no CoinsPay invoice is waiting for payment: a payment for an invoice made before is no longer applied.
