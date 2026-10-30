<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<nav class="drpm-guide__contents" aria-label="Contents">
    <ol>
        <li><a href="#what-it-does">What COD Guard does</a></li>
        <li><a href="#getting-started">Getting started</a></li>
        <li><a href="#daily-work">Daily work: when a parcel comes back</a></li>
        <li><a href="#checkout">What customers see at checkout</a></li>
        <li><a href="#dashboard">Reading the dashboard</a></li>
        <li><a href="#exceptions">Trusting or blocking a customer</a></li>
        <li><a href="#privacy">Customer privacy requests</a></li>
        <li><a href="#updating">Updating the plugin</a></li>
        <li><a href="#questions">Common questions</a></li>
    </ol>
</nav>

<h2 id="what-it-does">What COD Guard does</h2>
<p>COD Guard stops customers who keep refusing parcels from paying by cash on delivery again. They can still order, using any other payment method you offer.</p>
<p>It works in three steps:</p>
<ol>
    <li>When a parcel comes back, you mark the order <strong>Delivery refused</strong>.</li>
    <li>COD Guard remembers that refusal and links it to the customer's email, phone, addresses and name, depending on what you choose to match on.</li>
    <li>When that customer reaches checkout again and has passed your limit, the payment methods you selected are hidden.</li>
</ol>
<p>You'll find it under <strong>WooCommerce → COD Guard</strong>, on the <strong>Dashboard</strong> and <strong>Settings</strong> tabs. The screenshots in this guide come from a demo shop.</p>

<h2 id="getting-started">Getting started</h2>
<p>Setup takes about five minutes, and the defaults below suit most shops. Go to <strong>WooCommerce → COD Guard → Settings</strong>.</p>

<h3>1. Choose how to recognise customers</h3>
<p>Make sure <strong>Protect checkout</strong> is ticked, then tick <strong>Email</strong> and <strong>Phone number</strong> under <strong>Match on</strong>. A past order counts if it matches any ticked detail, so someone using a new email with the same phone is still recognised.</p>
<p>Addresses and names can also match other people, such as housemates or someone with a common name. Only use them alongside email or phone.</p>
<?php $image('settings-recognise', 'General and Match on settings, with Email and Phone number ticked'); ?>

<h3>2. Set your limit</h3>
<ul>
    <li><strong>Restriction rule:</strong> we suggest <strong>2 or more orders</strong>. You can use a percentage instead, for example 50% of a customer's orders. Setting it to 0 turns off automatic restriction.</li>
    <li><strong>Look-back period:</strong> 24 months is the default. Older refusals stop counting, so customers get a fresh start. 0 means all time.</li>
</ul>

<h3>3. Choose what to hide</h3>
<p>Tick <strong>Cash on delivery</strong> under <strong>Payment methods</strong>, and any other method you want to protect. If hiding them would leave a customer no way to pay, COD Guard hides nothing, so keep at least one other method switched on.</p>
<p>You can also reword the <strong>Checkout message</strong> that restricted customers see, or leave it empty to show no message.</p>
<?php $image('settings-rule', 'Restriction rule, Look-back period, Payment methods and Checkout message settings'); ?>

<h3>4. Enter your costs and save</h3>
<ul>
    <li><strong>Cost per refused parcel:</strong> add up shipping out, shipping back and handling. The dashboard uses this to show what refusals cost you.</li>
    <li><strong>Restock:</strong> leave this ticked to put items back in stock when an order is marked refused.</li>
</ul>
<p>Click <strong>Save changes</strong>. The button stays disabled until you've ticked at least one way to recognise customers and one payment method.</p>
<?php $image('settings-exceptions', 'Exceptions, Cost per refused parcel and Restock settings'); ?>

<h3>5. Show the quick button on your orders list</h3>
<p>The one-click refuse button sits in the <strong>Actions</strong> column, which WooCommerce hides by default. On <strong>WooCommerce → Orders</strong>, open <strong>Screen Options</strong> at the top right and tick <strong>Actions</strong>. Each staff member does this once on their own login.</p>
<?php $image('screen-options', 'Screen Options on the orders page, with Actions ticked'); ?>
<p>Your existing orders are added to COD Guard automatically in the background. Past parcels only count if the order has the <strong>Delivery refused</strong> status, so re-mark any recent refusals you recorded another way.</p>

<h2 id="daily-work">Daily work: when a parcel comes back</h2>
<p>Your one regular job is to mark returned parcels as <strong>Delivery refused</strong>. COD Guard only knows about refusals you record, and the rest is automatic.</p>
<p>There are three ways to do it, all from <strong>WooCommerce → Orders</strong>:</p>
<table class="drpm-guide__table">
    <thead><tr><th scope="col">When</th><th scope="col">How</th></tr></thead>
    <tbody>
        <tr><td>One parcel, from the list</td><td>Click the <strong>✕</strong> button in the <strong>Actions</strong> column. Hover over it first: the label reads "Delivery refused". It appears on Processing, On hold and Completed orders.</td></tr>
        <tr><td>Several parcels</td><td>Tick the orders, choose <strong>Change status to delivery refused</strong> under <strong>Bulk actions</strong>, then click <strong>Apply</strong>.</td></tr>
        <tr><td>From inside an order</td><td>Set <strong>Status</strong> to <strong>Delivery refused</strong>, then click <strong>Update</strong>.</td></tr>
    </tbody>
</table>
<p>The ✕ button means "refused", not "cancelled". It sits next to the ✓ button, which marks an order Completed.</p>
<?php $image('orders', 'The orders list with the Delivery risk column, Actions buttons and pink highlighted rows'); ?>
<p>Opening an order shows its status alongside the customer's details:</p>
<?php $image('status', 'An order with its status set to Delivery refused'); ?>

<h3>What happens next</h3>
<ul>
    <li>The order moves to <strong>Delivery refused</strong>.</li>
    <li>Items go back in stock, if <strong>Restock</strong> is ticked.</li>
    <li>A private note records the customer's history and whether they'll be restricted next time.</li>
</ul>
<?php $image('notes', 'Order notes showing that the customer has refused 2 orders and will be restricted on their next order'); ?>
<p>Marked one by mistake? Change the status back. The refusal stops counting and the stock is taken out again.</p>

<h3>Reading the orders list</h3>
<p>Before you ship a cash on delivery order, glance at the <strong>Delivery risk</strong> column. <code>3 / 4 refused</code> means 3 refused out of 4 orders that were either refused or completed.</p>
<table class="drpm-guide__table">
    <thead><tr><th scope="col">What you see</th><th scope="col">What it means</th></tr></thead>
    <tbody>
        <tr><td>Dash</td><td>No refusals on record</td></tr>
        <tr><td>Yellow badge</td><td>Has refused before, but is under your limit or marked Always trust</td></tr>
        <tr><td>Red badge</td><td>Has reached your limit, or is on your Always block list</td></tr>
        <tr><td>Pink row</td><td>Payment methods were hidden when this order was placed</td></tr>
    </tbody>
</table>
<p>These numbers count the customer's <strong>other</strong> orders. On a refused order, that order isn't included in its own badge, so the note may show one more refusal than the badge.</p>

<h3>The COD Guard box on an order</h3>
<p>Every order page has a <strong>COD Guard</strong> box in the right-hand column. It gives a verdict, the numbers behind it and links to the refused orders. If the customer has an account, it also links to where you can set an exception.</p>
<table class="drpm-guide__table">
    <thead><tr><th scope="col">Verdict</th><th scope="col">Meaning</th></tr></thead>
    <tbody>
        <tr><td>No refusal history</td><td>Nothing to worry about</td></tr>
        <tr><td>Watch · 1 refusal</td><td>Has refused before, still under your limit</td></tr>
        <tr><td>High risk · methods hidden</td><td>Has reached your limit</td></tr>
        <tr><td>Always blocked (exception)</td><td>You restricted this customer yourself</td></tr>
        <tr><td>Always trusted (exception)</td><td>You exempted this customer yourself</td></tr>
    </tbody>
</table>
<div class="drpm-guide__row">
    <?php
    $image('box-nikos', 'COD Guard box showing High risk, with 3 refused deliveries and links to the refused orders');
    $image('box-kostas', 'COD Guard box showing Watch, 1 refusal');
    $image('box-eleni', 'COD Guard box showing No refusal history');
    ?>
</div>

<h2 id="checkout">What customers see at checkout</h2>
<p>Customers shop and check out as normal. The only difference for a restricted customer is that the hidden payment method doesn't appear. Your shop's pages and prices don't change.</p>
<?php $image('shop', 'The demo shop\'s product page, unchanged by COD Guard'); ?>
<div class="drpm-guide__row">
    <figure>
        <figcaption>An ordinary customer sees every payment method:</figcaption>
        <?php $image('pay-normal', 'Checkout for an ordinary customer, offering Direct bank transfer and Cash on delivery'); ?>
    </figure>
    <figure>
        <figcaption>A restricted customer sees the other methods, plus your checkout message:</figcaption>
        <?php $image('pay-refuser', 'Checkout for a restricted customer, offering only Direct bank transfer, with a message explaining some methods are unavailable'); ?>
    </figure>
</div>
<p>The check runs as the customer types. Cash on delivery can be visible at first, then disappears once they've entered a matching email or phone number. They don't need an account, and a new email won't help if the phone number matches.</p>
<p>The message only appears on the classic checkout page. On the newer block-based checkout page, the method is hidden with no message, so make sure your delivery or payment information explains this. If you're not sure which one your shop uses, ask whoever built your site.</p>

<h2 id="dashboard">Reading the dashboard</h2>
<p>The dashboard shows how much refusals cost you and whether things are improving. Open <strong>WooCommerce → COD Guard → Dashboard</strong> and pick a period: 30 days, 90 days, 12 months or all time.</p>

<h3>Headline figures</h3>
<?php $image('dash-kpis', 'Headline figures: refusal rate, refused deliveries, cost of refusals, order value refused and checkouts with methods hidden'); ?>
<table class="drpm-guide__table">
    <thead><tr><th scope="col">Figure</th><th scope="col">What it tells you</th></tr></thead>
    <tbody>
        <tr><td>Refusal rate</td><td>Refused orders as a share of refused plus completed orders. This is the main number to watch.</td></tr>
        <tr><td>Refused deliveries</td><td>How many parcels were refused</td></tr>
        <tr><td>Cost of refusals</td><td>Refused deliveries × your cost per refused parcel. It stays empty until you enter that cost in Settings.</td></tr>
        <tr><td>Order value refused</td><td>The value of sales that didn't go through</td></tr>
        <tr><td>Checkouts with methods hidden</td><td>Orders placed by restricted customers, and how many were completed using another method. Those are sales you kept without the risk.</td></tr>
    </tbody>
</table>
<p>For most figures, the arrow compares with the previous period of the same length. <strong>Red means more refusals, and green means fewer</strong>, so an up arrow here is bad news. For the rate, "pp" means percentage points.</p>

<h3>Trends</h3>
<?php $image('dash-trends', 'Charts of refused deliveries and refusal rate per month'); ?>
<p>These charts show whether refusals are rising or falling. Hover over a period for its details, or click <strong>Show data table</strong> to see the numbers.</p>

<h3>Where refusals come from</h3>
<?php $image('dash-breakdowns', 'Refusal rate by payment method, and the regions with the most refusals'); ?>
<p>If refusals concentrate on one region, you could review your courier there. Comparing payment methods shows how much of the problem comes from cash on delivery.</p>

<h3>Products and customers</h3>
<?php $image('dash-tables', 'Tables of the most refused products and repeat refusers'); ?>
<ul>
    <li><strong>Most refused products:</strong> look at a product with a high rate. Its description, photos or price may not match what customers expect. This table needs WooCommerce Analytics to be switched on.</li>
    <li><strong>Repeat refusers:</strong> customers who refused two or more orders in the period. <strong>Methods hidden</strong> means they're restricted now, and <strong>Allowed</strong> means they aren't. Click a name to open their latest order.</li>
</ul>

<h2 id="exceptions">Trusting or blocking a customer</h2>
<p>You can overrule the automatic rule for any customer, using either of two methods. <strong>Always trust</strong> keeps every payment method available whatever their history. <strong>Always block</strong> hides the selected methods even if they've never refused.</p>
<table class="drpm-guide__table">
    <thead><tr><th scope="col">Customer</th><th scope="col">Where to set it</th></tr></thead>
    <tbody>
        <tr><td>Anyone, including guests</td><td><strong>COD Guard → Settings → Exceptions</strong>. Add their email or phone number to <strong>Always block</strong> or <strong>Always trust</strong>, one per line, then click <strong>Save changes</strong>.</td></tr>
        <tr><td>Registered customers</td><td><strong>Users</strong>, open the customer, then <strong>COD Guard → Exception</strong>. From an order, click <strong>Set an exception for this customer</strong> in the COD Guard box.</td></tr>
    </tbody>
</table>
<?php $image('profile', 'The COD Guard section of a customer profile, showing refusal history and the Exception dropdown'); ?>
<p>A profile exception only applies when the customer is logged in or the order is linked to their account. If they might check out as a guest, use the lists in Settings instead.</p>
<p>A good use of Always trust is a loyal customer who refused a damaged parcel or missed a delivery through no fault of their own.</p>

<h2 id="privacy">Customer privacy requests</h2>
<p>COD Guard works with WordPress's standard privacy tools, so you handle its data the same way as other customer data. It stores customer details as scrambled fingerprints rather than readable text, and doesn't share them with third parties. These tools usually need an Administrator login.</p>
<table class="drpm-guide__table">
    <thead><tr><th scope="col">Request</th><th scope="col">What to do</th><th scope="col">Result</th></tr></thead>
    <tbody>
        <tr><td>"What do you hold about me?"</td><td><strong>Tools → Export Personal Data</strong></td><td>Includes the orders checked, whether they're restricted, and any exception set for them</td></tr>
        <tr><td>"Delete my data"</td><td><strong>Tools → Erase Personal Data</strong></td><td>Depends on the setting below</td></tr>
    </tbody>
</table>
<p>Whether erasure removes COD Guard's data depends on <strong>Remove personal data from orders on request</strong>, under <strong>WooCommerce → Settings → Accounts &amp; Privacy</strong>. If it's on, the customer's refusal history and exceptions are removed. If it's off, they're kept for fraud prevention and the request says so.</p>
<p>Add the suggested wording to your privacy policy. You'll find it under <strong>Settings → Privacy → Policy Guide</strong>. Adapt it to your shop and check it with your legal adviser, as it isn't legal advice.</p>

<h2 id="updating">Updating the plugin</h2>
<p>Updates don't install by themselves: someone with an Administrator login approves each one, unless they've turned on auto-updates for the plugin. When a new version is out, it appears under <strong>Plugins</strong> like any other plugin.</p>
<ol>
    <li>Back up your site.</li>
    <li>Go to <strong>Plugins</strong> and find <strong>COD Guard for WooCommerce</strong>.</li>
    <li>Click <strong>View details</strong> to see what's changed.</li>
    <li>Click <strong>Update now</strong>.</li>
</ol>
<p>WordPress looks for updates about twice a day. To look straight away, go to <strong>Dashboard → Updates → Check again</strong>.</p>
<p>Your settings and refusal history stay in place when you update.</p>
<p>Be careful if you ever delete the plugin. If <strong>Uninstall</strong> is ticked under <strong>Settings → Advanced</strong>, deleting it erases COD Guard's data and moves refused orders to Cancelled. Leave it unticked unless you're removing COD Guard for good.</p>

<h2 id="questions">Common questions</h2>
<h3>A customer asks why cash on delivery is gone. What should I say?</h3>
<p>Open one of their orders and check the COD Guard box. It shows the refused orders behind the restriction, so you can check them together. If the restriction isn't fair, add them to <strong>Always trust</strong>.</p>
<h3>A customer I expected to be restricted can still pick cash on delivery. Why?</h3>
<p>Check these in order:</p>
<ol>
    <li><strong>Protect checkout</strong> is ticked, and the payment method is ticked under <strong>Hide these payment methods</strong>.</li>
    <li>Their refusals are marked <strong>Delivery refused</strong> and fall within your look-back period.</li>
    <li>They've reached your restriction rule.</li>
    <li>They used an email or phone number you're matching on. New details can't be linked to their old orders.</li>
    <li>They aren't on <strong>Always trust</strong>.</li>
    <li>Another payment method is available. If not, nothing is hidden.</li>
</ol>
<h3>Can a restricted customer still order?</h3>
<p>Yes. They just can't use the hidden methods. COD Guard never blocks an order outright.</p>
<h3>Can someone get around it with a new email?</h3>
<p>Not if you also match on phone number and they give the same one. Someone using an all-new email and phone won't be recognised.</p>
<h3>Does a restriction ever end?</h3>
<p>Yes, automatically, once enough refusals fall outside your look-back period. With a look-back of 0 (all time), it only ends if you add an exception. You can lift it at any time with <strong>Always trust</strong>.</p>
<h3>Should I mark an order refused if the courier couldn't find the address?</h3>
<p>Only use <strong>Delivery refused</strong> when the customer really refused the parcel. For failed deliveries that aren't their fault, use <strong>Cancelled</strong>, so they aren't counted against the customer.</p>
<h3>The numbers look wrong. What can I do?</h3>
<p>Go to <strong>Settings → Advanced</strong> and click <strong>Rebuild order index</strong>. It runs in the background, and the dashboard shows a notice until it's done. This doesn't change your orders.</p>
<h3>Who can use COD Guard?</h3>
<p>Anyone who can manage WooCommerce, which includes the Shop Manager and Administrator roles.</p>
