=== GreenPNG ===
Contributors: greenpng
Tags: security, analytics, attribution, crm
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
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

Not by default, and never any GreenPNG-operated server. The optional integrations (GA4, Meta, TikTok, generic webhook) only send data after you enter credentials and enable them. The DB-IP geolocation database update only fetches when you click the update button.

= What does the client probe collect? =

The probe has two modules. The security module loads by default and reports only automation conclusions (a bot score and automation flags); it never collects fingerprint strings, canvas or audio data, or persistent identifiers. It exists to protect forms and checkouts under GDPR legitimate interest (Recital 49) and can be switched off with one setting. The behavior module (dwell, scrolling, rage clicks) is only emitted after you enable it and the visitor consents through the WordPress Consent API.

= What happens to my data when I uninstall? =

It is kept by default. For a full wipe, enable the delete-all-data-on-uninstall option first; uninstalling then removes every `gr_` table and option.

== External services ==

This plugin performs no outbound requests by default. Each item below is opt-in and off until you enable it:

* **Google Analytics 4 (Measurement Protocol)** — Purpose: forward chosen events to your GA4 property. Sends: event name, event parameters, client identifier, timestamp. When: on the events you mark for export. Privacy policy: https://policies.google.com/privacy
* **Meta Conversions API** — Purpose: server-side conversion tracking. Sends: event name and data, hashed customer fields, client IP address and user agent as required by the API. When: on conversion events. Privacy policy: https://www.facebook.com/privacy/policy
* **TikTok Events API** — Purpose: server-side conversion tracking. Sends: event name, event properties, hashed identifiers. When: on conversion events. Privacy policy: https://www.tiktok.com/legal/page/row/privacy-policy/en
* **Generic outgoing webhook** — Purpose: notify any endpoint you own. Sends: the JSON payload you configure. When: on the events you choose. No third party is involved; the destination is yours.
* **DB-IP Lite geolocation database update** — Purpose: refresh the bundled country-level IP database. Sends: a single HTTP request to db-ip.com, only when you click the update button in settings; no visitor data is sent. Receives: the country-level database file. Terms and attribution: https://db-ip.com/

== Privacy ==

* Marketing-track data (sessions, touchpoints, behavior, CRM) stores anonymized IPs by default; identifiable marketing collection is gated on visitor consent through the WordPress Consent API.
* Security logs store full client IPs on a legitimate-interest basis (site protection, GDPR Recital 49), are masked in the admin by default, and can be set to truncated storage instead, with the admin clearly noting that blocking then degrades to subnet level.
* Visitor identity: a 30-day signed cookie when consent allows it; otherwise a daily-rotated salted hash of the anonymized IP and browser type, which cannot link visits across days.
* The WordPress privacy API is fully supported: personal data export and erase handlers cover every `gr_` table, and suggested privacy policy text is provided in the admin.
* Email addresses are stored as a searchable hash plus encrypted form and are never written outside the contacts table.

== Attribution ==

This plugin bundles a country-level IP geolocation database from DB-IP (https://db-ip.com/), used under CC BY 4.0. The exact data date is stated in the NOTICE file inside the plugin package.

The scanner user-agent detection rules bundled in assets/data/ are seeded from JayBizzle/Crawler-Detect (https://github.com/JayBizzle/Crawler-Detect), used under the MIT license, and maintained locally from that seed. The NOTICE file inside the plugin package states the license, source, and data date.

== Changelog ==

= 1.0.0 =
* Initial release.
