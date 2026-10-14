# COD Guard for WooCommerce

Stop losing money on refused cash-on-delivery parcels. COD Guard tracks refused deliveries, recognises customers who keep refusing, and hides cash on delivery (or any payment method you choose) from them at checkout.

## How it works

1. When a parcel comes back, mark the order **Delivery refused**. You can use the order's status dropdown, the bulk action, or the one-click button in the orders list.
2. COD Guard keeps a small index of hashed customer details for every order: email, phone, shipping address, billing address and full name. Values are normalized, so `John.Doe+shop@Gmail.com` matches `johndoe@gmail.com` and `+30 690 000 0001` matches `6900000001`.
3. At checkout, the customer's past orders in the look-back period are found by **any** of the details you selected (and by their account, if they're logged in). If they meet your restriction rule, the selected payment methods are hidden.
4. WooCommerce re-checks the chosen method against the available ones when the order is placed, so a hidden method can't be forced through by editing the form.

Supported: classic (shortcode) checkout, Checkout block (Store API), "Pay for order" page, HPOS and legacy order storage.

## Dashboard

*WooCommerce → COD Guard → Dashboard*, with date ranges of 30 days, 90 days, 12 months or all time.

- **Headline figures:**
  - refusal rate (with the change vs the previous period)
  - number of refused deliveries
  - cost of refusals (refusals × your cost per refused parcel)
  - order value refused
  - checkouts with methods hidden, and how many of them completed with another payment method
- **Trends:** refused deliveries and refusal rate per day, week or month. Hover or focus a period for details; each chart has a data table.
- **Breakdowns:** refusal rate by payment method, the regions with the most refusals, and the most refused products (needs WooCommerce Analytics).
- **Repeat refusers:** customers who refused two or more orders, with their current status and a link to their latest order.

## Settings

*WooCommerce → COD Guard → Settings*

| Section | Settings |
| --- | --- |
| General | **Protect checkout** (master switch) |
| Recognise returning customers by | Email, phone number, shipping address, billing address, full name |
| When to restrict | **Restriction rule:** "Restrict when a customer has refused [2] [or more orders / % or more of their orders]". For the percentage rule, you can require a minimum number of completed + refused orders first. 0 turns automatic restriction off. **Look-back period** in months (0 = all time) |
| Hide these payment methods | The methods to hide, and the **Checkout message**. Hiding never leaves a customer with no way to pay |
| Exceptions | **Always block** / **Always trust** lists of emails or phone numbers |
| Refused orders | **Cost per refused parcel** (for the dashboard), **Restock** refused items |
| Advanced | Order index status and rebuild button, **Uninstall** data removal |

You can also set an exception for individual registered customers on their user profile: **Automatic**, **Always trust** or **Always block**.

## On the order screens

- A **Delivery risk** column in the orders list (e.g. `2 / 5 refused`).
- Orders placed while payment methods were hidden are highlighted in the list.
- A **COD Guard** box on the order screen shows the verdict (*High risk · methods hidden*, *Watch · 1 refusal*, *No refusal history*), the refusal rate and links to the refused orders.
- An automatic order note is added on refusal, with the customer's updated history.

## Privacy & GDPR

- **Keyed hashes only.** Customer details are stored as HMAC-SHA256 hashes. The key is `DRPM_HASH_KEY`, if you define it in `wp-config.php` (recommended), and otherwise WordPress's `AUTH` salt. Neither is kept in the database, so a database dump alone can't be brute-forced back to emails or phone numbers. If the key changes, the index is wiped and rebuilt automatically.
- **Privacy policy text.** Suggested wording (legitimate interest, Art. 6(1)(f)) appears under *Settings → Privacy → Policy Guide*. Adapt it to your store.
- **Checkout message.** A neutral default message tells customers that some payment methods aren't available and how to contact you.
- **Export personal data** (*Tools → Export Personal Data*) includes the orders used for risk checks, the current verdict, any exception set on the profile, and membership of the exception lists.
- **Erase personal data** follows WooCommerce's *"Remove personal data from orders on request"* setting. When that setting is on, the person's index rows, profile exception and list entries are removed. When it's off, they are kept for fraud prevention (like the orders themselves), and the request says the data was retained. You can change this with the `drpm_privacy_erase_data` filter.
- **Anonymized orders** (from an erasure request or WooCommerce's retention policy) are removed from the index and their risk snapshot is deleted.

## WP-CLI

```bash
wp cod-guard reindex          # rebuild the order index
wp cod-guard check <order_id> # show the verdict for an order's customer
```

## Developer hooks

| Hook | Purpose |
| --- | --- |
| `drpm_risk_result` (filter) | Change the final verdict: `($result, $hashes, $customer_id, $email, $phone)` |
| `drpm_restricted_gateways` (filter) | Change which gateway IDs are hidden |
| `drpm_delivered_statuses` (filter) | Statuses that count as delivered for the percentage rule (default `completed`) |
| `drpm_allow_empty_gateways` (filter) | Return `true` to allow hiding every payment method |
| `drpm_privacy_erase_data` (filter) | Whether privacy erasure requests remove COD Guard data |
| `drpm_settings` (filter) | Change the settings fields |

## Upgrading from 2.0 ("Delivery Refusal & Payment Manager")

- The main plugin file was renamed to `cod-guard.php`, so WordPress deactivates the plugin once after the update. Reactivate it under *Plugins*.
- Settings move to new option names automatically on first load, so nothing needs re-entering.
- The admin page moved to *WooCommerce → COD Guard*, and old settings links redirect there.
- The WP-CLI command changed from `wp drpm` to `wp cod-guard`, and the settings filter from `delivery_refusal_payment_manager_settings` to `drpm_settings`.

## Translations

Greek (`el`) is included. Strings use the `cod-guard` text domain.

## Requirements

WordPress 6.0+, WooCommerce 7.1+, PHP 7.4+.
