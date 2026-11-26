=== ChurnAnchor ===
Contributors: kreatiftr
Tags: woocommerce subscriptions, cancellation, churn, retention, pause subscription
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A fair cancellation flow for WooCommerce Subscriptions: ask why, offer a skip or a pause, and see how much monthly revenue you actually kept.

== Description ==

When a customer clicks **Cancel** on a subscription, ChurnAnchor shows a short, honest flow instead of an instant cancel:

1. **Why are you leaving?** One question with reasons you control (plus an optional comment).
2. **One matching offer**, only if it makes sense: *skip the next renewal* or *pause for 1–3 months*. The subscription stays active, nothing is suspended, and the customer keeps access.
3. **A cancel button on every step.** No mazes, no hidden links, no guilt trips.

Then see what happened on the **dashboard** (WooCommerce → ChurnAnchor): how many customers started to cancel, how many accepted an offer, the top reasons, and the monthly revenue kept.

= Honest numbers =

A save is only counted as **verified** after the customer pays the next renewal. If they cancel first, it is shown as **lost later**. Customers can accept one offer per paid renewal, and cancelling after a skip or pause still ends access when the period they paid for ends. Amounts exclude tax and shipping, are normalised to one month and are shown per currency.

= Requirements =

* WooCommerce and WooCommerce Subscriptions
* Works with classic and block themes and HPOS (custom order tables)

Offers only appear when the subscription's payment method supports changing payment dates, so renewals never break.

= Privacy =

ChurnAnchor makes **no external requests** and sends no data anywhere. Reasons and comments are stored in your own database and are included in WordPress personal data export and erasure tools.

= ChurnAnchor Pro =

An optional paid add-on, [ChurnAnchor Pro](https://churnanchor.marul.co/pricing/), adds a one-time renewal discount, a longer pause, an "ask the store to fix it" option, up to two offers per reason, category rules, win-back and renewal reminder emails, and per-offer reports. The free plugin is complete on its own and does not need Pro.

= Not legal advice =

ChurnAnchor is designed to keep cancelling easy (for example, the cancel button is always visible). Laws such as California's automatic renewal law or the UK's subscription contract rules have their own requirements; check what applies to your store.

== Frequently Asked Questions ==

= Does it work without WooCommerce Subscriptions? =

No. It needs the WooCommerce Subscriptions extension. Without it, the flow stays off and you see a notice.

= Does "pause" put the subscription on hold? =

No. It moves the next payment date forward. The subscription stays active, the customer keeps access, and there is nothing to resume later.

= Can I change the reasons and which offer each one shows? =

Yes, in WooCommerce → ChurnAnchor. Each reason can show "skip", "pause" or no offer.

= Can customers still cancel immediately? =

Yes. "Cancel subscription now" is on the first screen and a cancel button is on every step.

= How do I reduce cancellations in WooCommerce Subscriptions? =

Ask why customers cancel and answer the reason: a skip for "too much product", a pause for "can't afford it right now", and no offer for people who are switching. ChurnAnchor does this on the existing Cancel button and shows which offers actually keep paying customers.

= Is a cancellation flow allowed under click-to-cancel and auto-renewal laws? =

A short flow is generally fine when cancelling stays as easy as signing up: ChurnAnchor keeps the cancel button on every step, makes the reason optional and shows one screen of offers. This is not legal advice; laws differ by state and country.

= Can customers get free time by skipping and then cancelling? =

No. If someone skips or pauses and then cancels, access ends on the date they actually paid for.

= How is saved revenue counted? =

A save counts only after the customer's next renewal is paid. Accepted offers stay "pending" until then, and become "lost" if the customer cancels anyway.

= Does it send data to an external service? =

No. Everything stays in your WordPress database.

= Can I customise the template? =

Copy `templates/cancel-flow.php` to `yourtheme/churnanchor/cancel-flow.php`.

== Changelog ==

= 0.2.3 =
* Database: the events table column `event` is renamed to `event_type` (existing data is kept). The schema version is only saved once the tables exist.

= 0.2.2 =
* Readme: answers to common questions about reducing cancellations, cancellation laws and how saves are counted.

= 0.2.1 =
* New `churnanchor_max_offers` filter: limits how many offers are shown, applied after eligibility checks so an ineligible offer never hides an eligible one.

= 0.2.0 =
* Developer API for additional offers: `churnanchor_offers_for_reason`, `churnanchor_offer_details`, `churnanchor_offer_accepted` and `churnanchor_subscription_cancelled`.
* The offer step can show up to three offers, each with its own before/after summary.

= 0.1.1 =
* Redesigned cancellation flow: subscription summary, step indicator, selectable reasons, clear before/after dates on the offer, equal-size buttons, focus moves to each step heading.
* Redesigned dashboard: summary figures, date range (7/30/90 days), revenue ledger, reason bars, empty state.
* The offer screen now also says when access ends if the customer cancels instead.

= 0.1.0 =
* First release: cancellation reasons, skip and pause offers, verified-save dashboard.
