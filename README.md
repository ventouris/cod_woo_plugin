# WooCommerce Delivery Refusal & Payment Manager

Stops customers who keep refusing Cash on Delivery parcels from choosing COD (or any payment method you pick) again.

## How it works

1. When a parcel comes back, set the order to **Refused delivery**. You can do this from the order's status dropdown, with the bulk action, or with the one-click button in the orders list.
2. The plugin keeps a small index of hashed customer identifiers for every order: email, phone, shipping address, billing address and full name. Values are normalized, so `John.Doe+shop@Gmail.com` matches `johndoe@gmail.com` and `+30 690 000 0001` matches `6900000001`.
3. At checkout, the customer's past orders inside the retention window are looked up by **any** of the enabled identifiers (and by their account, if they're logged in). If they reach the threshold, the selected payment methods are hidden.
4. WooCommerce re-checks the chosen method against the available ones when the order is placed, so a restricted method can't be forced through by editing the form.

Supported: classic (shortcode) checkout, Checkout block (Store API), "Pay for order" page, HPOS and legacy order storage.

## Settings

*WooCommerce → Settings → Delivery Refusal*

| Setting | Description |
| --- | --- |
| Overview | Refusal stats, number of orders placed with restrictions applied, index status and a **Rebuild order index** button |
| Enable restriction | Master switch |
| Customer Data Fields | Which identifiers to match on |
| Threshold Type / Value | *Number*: restrict after N refusals. *Percentage*: restrict when refused ÷ (refused + completed) ≥ N%. Set the value to 0 to turn off automatic restriction |
| Minimum orders (percentage) | Customers need at least this many delivered + refused orders before the percentage rule applies to them |
| Payment Methods | Methods to hide. Hiding never leaves a customer with no payment method at all |
| Message to customer | Optional notice shown in the classic checkout when methods are hidden |
| Always restrict / Always allow | Lists of emails or phone numbers that override the automatic rule |
| Number of Months | Retention window (0 = all time) |
| Restock refused orders | Put the items back in stock when an order is marked refused |
| When plugin is deleted | Remove all data and convert refused orders to Cancelled |

You can also override individual registered customers from their user profile: **Automatic**, **Always allow** or **Always restrict**.

## Admin tools

- **Delivery risk** column in the orders list (e.g. `2 / 5 refused`).
- Orders placed with restricted methods are highlighted in the list.
- A **Delivery refusal risk** box on the order screen shows the verdict, refusal rate and links to the refused orders.
- An automatic order note is added on refusal, with the customer's updated history.

## WP-CLI

```bash
wp drpm reindex          # rebuild the order index
wp drpm check <order_id> # show the risk verdict for an order's customer
```

## Developer hooks

| Hook | Purpose |
| --- | --- |
| `drpm_risk_result` (filter) | Change the final verdict: `($result, $hashes, $customer_id, $email, $phone)` |
| `drpm_restricted_gateways` (filter) | Change which gateway IDs are hidden |
| `drpm_delivered_statuses` (filter) | Statuses that count as delivered for the percentage rule (default `completed`) |
| `drpm_allow_empty_gateways` (filter) | Return `true` to allow hiding every payment method |
| `delivery_refusal_payment_manager_settings` (filter) | Change the settings fields |

## Requirements

WordPress 6.0+, WooCommerce 7.1+, PHP 7.4+.
