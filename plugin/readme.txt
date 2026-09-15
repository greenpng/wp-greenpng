=== GreenPNG ===
Contributors: greenpng
Tags: security, analytics, attribution, crm
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Traffic security, marketing attribution, conversion funnels, A/B testing, CRM scoring, and local analytics in one free plugin. Everything runs on your own site; nothing is sent anywhere by default.

== Description ==

GreenPNG is a free, self-contained operations suite for independent WordPress site owners. It covers five areas in one local loop:

* **Traffic security** — request logging with surge folding, a unified allow/block rule table, and a lightweight client probe that reports automation conclusions. The security module only records by default; blocking is opt-in per rule, so search engine crawlers are never blocked accidentally.
* **Marketing attribution** — UTM and click-id capture, a 30-day signed visitor identity, first and last touch, and five attribution models computed locally.
* **Funnels and A/B testing** — funnel state machines, A/B exposure and conversion events with significance testing, and cart-abandonment capture with recovery links.
* **CRM scoring** — contact records with hashed emails, lead scoring, RFM segments, and tags. Security conclusions enter the CRM only as labels, never as raw signals.
* **Local analytics** — dashboards read daily aggregate tables rather than raw event tables, so reports stay fast as data grows.

= Why another plugin? =

* **No outbound calls, no telemetry.** The plugin never contacts any GreenPNG-operated server: no heartbeat, no version check, no statistics, no key validation. The only outbound requests are the ones you configure yourself (see External services), and they are all off by default.
* **Privacy by default.** Marketing data (attribution, behavior, CRM) stores anonymized IPs and identifiable collection is gated on visitor consent via the WordPress Consent API. Security logs keep full client IPs on a legitimate-interest basis, are masked when displayed, and can be switched to truncated storage (IPv4 /24, IPv6 /48) at any time.
* **MySQL / MariaDB only.** Schema discipline with dbDelta, atomic upserts for high-frequency writes, and no promises about other database engines.

= Data ownership =

All data lives in your own database: fifteen tables using your table prefix plus `gr_`, and one settings row. Uninstalling keeps your data unless you explicitly enable the delete-on-uninstall option.

== Installation ==

1. Upload the `greenpng` folder to `/wp-content/plugins/`, or install it via the Plugins screen.
2. Activate the plugin. Tables and default settings are created on activation and repaired automatically if an update is interrupted.
3. Optional but recommended on low-traffic sites: register a system cron entry running `wp greenpng maintenance` so daily maintenance runs on a fixed schedule instead of depending on visitor-triggered WP-Cron.

== Frequently Asked Questions ==

= Where is my data stored? =

In your own WordPress database. The plugin creates fifteen tables prefixed with your table prefix plus `gr_`, plus one autoloaded settings row. Nothing is stored anywhere off your site.

= Does this plugin contact any external server? =

Not by default, and never any GreenPNG-operated server. The optional integrations (GA4, Meta) only send data after you enter credentials and enable them. The DB-IP geolocation database update only fetches when you click the update button. Crawler verification resolves connecting addresses through your server's normal DNS infrastructure and stores only the resulting verdict.

= What does the client probe collect? =

The probe has two modules. The security module loads by default and reports only automation conclusions (a bot score and automation flags); it never collects fingerprint strings, canvas or audio data, or persistent identifiers. It exists to protect forms and checkouts under GDPR legitimate interest (Recital 49) and can be switched off with one setting. The behavior module is off by default and additionally gated on visitor consent through the WordPress Consent API: both gates must open before anything is recorded. It reports four signals — dwell time in coarse buckets, the deepest scroll milestone reached, rage clicks (repeated rapid clicks on one spot), and dead clicks (clicks on non-interactive elements with no visible response). Click locators are structural only (tag plus id or first class name): never page text, never coordinates.

= What does the CRM store from form submissions? =

When a visitor with marketing consent submits a form on a supported form plugin, the CRM stores the submitted name and email address (the email as a searchable hash plus an encrypted copy), the cookie-track visitor binding, and derived state: a lead score computed from your scoring rules over the scoring window, and a customer segment refreshed nightly. Phone-number fields are not stored. Email addresses are shown masked in the admin and appear in plaintext only behind an audited reveal; the personal data export and erasure tools cover the contact row, its tags, and the visitor binding.

= What do funnels record? =

Funnel journeys derive from the events the stream already recorded: for each active funnel you define, the tracker stores how far each consented session got through the ordered steps. A journey row carries the session and visitor identifiers, the step position, and timestamps — never an email address, never an IP. Step matching uses pageview paths and a closed event vocabulary; probe verdicts are never steps. Journey rows follow the same 30-day retention as the events they derive from, and the personal data erasure tools remove a person's journey rows together with their other marketing data.

= What is cart-abandonment recovery and what does it send? =

An optional WooCommerce feature, off by default until you enable it in Settings → Attribution. On the classic (shortcode) checkout it adds one honest checkbox, "Send me a link to finish this purchase if I do not complete checkout": only a checked box plus a typed email address (sent once on blur, then only when the address changes) captures a cart snapshot — the checkout email and the cart's line items (product id, quantity, name; never addresses or payment details), stored with the consent snapshot taken at capture. On block-based checkouts there is no checkbox surface; there, a cart is captured only when an order is created in a pending state (a payment started but not finished), so a paid or in-transit order is never treated as an abandonment. After the delay you set (5–120 minutes), one recovery email goes out through your site's own `wp_mail` — the same channel your order emails already use, no third-party endpoint, nothing sent from the plugin's side; if delivery fails it retries once after six hours, then stops and says so on the status page. The email carries a one-time recovery link and an unsubscribe link; unsubscribing is permanent for that address. Rows and their encrypted emails are kept for the 90-day retention window and are covered by the personal data export and erasure tools.

= What happens to my data when I uninstall? =

It is kept by default. For a full wipe, enable the delete-all-data-on-uninstall option first; uninstalling then removes every `gr_` table and option.

== External services ==

This plugin performs no outbound requests by default. Each item below is opt-in and off until you enable it:

* **Google Analytics 4 (Measurement Protocol)** — Purpose: forward purchase events to your GA4 property. Sends: event name, event parameters (order value, currency, transaction id), and the client identifier from the visitor's own `_ga` cookie; no event is sent without that cookie. When: on paid orders, after marketing consent. The "Test connection" button in settings sends one validation request to Google's debug endpoint with a placeholder client identifier; the debug endpoint validates without ingesting, and the button is the only thing that ever triggers it. Privacy policy: https://policies.google.com/privacy
* **Meta Conversions API** — Purpose: server-side conversion tracking. Sends: event name, order value and currency, and the SHA-256-hashed billing email; no raw identifiers, no IP address, no user agent. When: on paid orders, after marketing consent. The thank-you page also exposes the shared event id as `window.GreenPNGPurchaseEventId` so your existing browser pixel can deduplicate. Privacy policy: https://www.facebook.com/privacy/policy
* **DB-IP Lite geolocation database update** — Purpose: refresh the bundled country-level IP database. Sends: a single HTTP request to db-ip.com, only when you click the update button in settings; no visitor data is sent. Receives: the country-level database file. Terms and attribution: https://db-ip.com/

Planned for later versions and not present in 1.0: AbuseIPDB blocklist checks and search-engine spider IP-segment subscriptions. Both will be opt-in, off by default, and disclosed in this section when they land.

== Privacy ==

* Marketing-track data (sessions, touchpoints, behavior, CRM) stores anonymized IPs by default; identifiable marketing collection is gated on visitor consent through the WordPress Consent API.
* Security logs store full client IPs on a legitimate-interest basis (site protection, GDPR Recital 49), are masked in the admin by default, and can be set to truncated storage instead, with the admin clearly noting that blocking then degrades to subnet level.
* The client probe's security module reports only automation conclusions (a bot score and automation flags) for form and checkout protection under the same legitimate-interest basis; it collects no fingerprint identifier strings, no canvas or audio data, and no persistent identifiers, and can be switched off with one setting. The behavior module (dwell buckets, deepest scroll milestone, rage clicks, dead clicks) is off by default and additionally gated on visitor consent through the WordPress Consent API; its click locators are structural (tag plus id or first class name), never page text or coordinates.
* Crawler verification resolves connecting addresses through your server's normal DNS infrastructure and records only the verdict word and the resolved hostname on the security track; it uses no third-party API, stores no credentials, and a failed lookup always resolves to allow.
* Visitor identity: a 30-day signed cookie when consent allows it; otherwise a daily-rotated salted hash of the anonymized IP and browser type, which cannot link visits across days.
* The WordPress privacy API is supported where it applies: personal data export and erase handlers cover this plugin's marketing tables (sessions, touchpoints, conversions, funnel journeys, the CRM contact row and its tags) and the visitor binding on orders. Security logs are retained on a legitimate-interest basis with short retention and masked display, and are intentionally outside person-level erasure.
* Email addresses are stored as a searchable hash plus encrypted form and are never written outside the contacts table. Contact capture from forms happens only inside the marketing-consent gate; the admin shows masked addresses and an audited reveal; lead scores read the same event window retention keeps, and a suspected-bot verdict outranks every rule with a zero score.
* Cart-abandonment recovery is off by default and stays inside the consent gate: a checkout email is captured only behind an explicit opt-in checkbox (classic checkout) or a pending order (block checkout), stored as a hash plus an encrypted envelope, and never written into the event stream. The recovery mail is sent by your site's own `wp_mail` after the delay you set, carries the cart's line items and two links (a one-time recovery link and a permanent unsubscribe link), keeps failed deliveries visible on the status page instead of silently dropping them, and the rows fall under the same 90-day retention plus the personal data export and erasure tools as the rest of the marketing track.

== Attribution ==

This plugin bundles a country-level IP geolocation database from DB-IP (https://db-ip.com/), used under CC BY 4.0. The exact data date is stated in the NOTICE file inside the plugin package.

The scanner user-agent detection rules bundled in assets/data/ are seeded from JayBizzle/Crawler-Detect (https://github.com/JayBizzle/Crawler-Detect), used under the MIT license, and maintained locally from that seed. The NOTICE file inside the plugin package states the license, source, and data date.

== Screenshots ==

1. Dashboard — sessions, visitors, and conversion trends computed locally.
2. Settings — privacy defaults (IP anonymization, consent gate), security switches, and attribution options.
3. Traffic & Security — the request log with bot verdicts and the allow/block rule table.
4. Campaigns — attribution breakdowns by channel, source, and campaign.
5. Funnels & Goals — funnel definitions, the step-loss staircase, goals by source, and A/B significance testing.
6. Behavior Insights — dwell, scroll, rage-click, and dead-click engagement and friction signals, consent-gated and off by default.
7. Contacts — the captured-lead list with masked emails, lead scores, and RFM segments; profiles open inline with an audited email reveal.

== Changelog ==

= 1.0.1 =
* The client probe's bot score and verdict now land on the session row, so the bot reports and the outbound traffic-quality gate read real conclusions instead of the default.
* Statically banned addresses are now refused (403) unconditionally; temporary locks refuse only in "log and block" mode. URL allow rules exempt detectors, never bans.
* WooCommerce orders born directly in a paid status (offline gateways through the Store API) now bind their conversion through the status transition.
* Settings, Security tab: bot verdict threshold, login failure threshold, lockout base duration, honeypot traps, and blackhole trap switches.

= 1.0.0 =
* Initial release.
