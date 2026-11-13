=== formconfirmed – Invisible spam protection for forms ===
Contributors: lwitsolutions
Tags: spam, anti-spam, captcha, contact form 7, honeypot
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Invisible spam protection for forms and comments: proof of work, timing signals and a message check, hosted in the EU. No puzzles.

== Description ==

formconfirmed stops automated form spam without asking your visitors to solve anything. A small calculation runs invisibly in the visitor's browser, your server asks the formconfirmed service whether the submission looks like a person, and the service answers *allow*, *flag* or *block* - with the reasons.

**Protects**

* Contact Form 7, WPForms, Gravity Forms, Elementor Pro forms, Fluent Forms, Formidable Forms
* Comments
* WordPress registration and WooCommerce account registration
* The WordPress login (optional)

**Every form, your choice.** Settings → formconfirmed → Forms lists every form of Contact Form 7, WPForms, Gravity Forms, Fluent Forms and Formidable with its own switch, so you protect exactly the forms you want. New forms are protected automatically (you can switch that off). Elementor Pro forms, comments, registration and login each have one switch.

**Forms of your own.** Put the shortcode `[formconfirmed]` into any form - a theme template, a landing page, a form of another plugin - and check the submission with one PHP call, `formconfirmed_check()`. In Contact Form 7 the form tag `[formconfirmed]` places the badge where you want it. Settings → formconfirmed → Developers has copy-ready examples.

**What it checks**

* An invisible proof of work: every submission costs the sender computing time. A person does not notice it (well under a second, even on a phone); a bot that sends thousands of messages pays for every one.
* Timing and a trap field: too fast, or a field only a bot fills in.
* The e-mail address: throw-away providers, domains that cannot receive mail.
* Optional: the message text (spam filter in every plan, an AI check where your plan includes it). Processed briefly on the service servers. AI checks use a temporary internal queue.

**No puzzles, no cookies.** Nothing is stored on the visitor's device and nothing is tracked, so the protection itself needs no consent banner. There is nothing to click, so it is no obstacle for screen readers, keyboards or voice control.

**Never a silent pass.** A message is delivered when the service says *allow*. If the service flags it, it is held for your review (or refused, as you choose) - never let through unseen. If the service cannot give a clear answer (an outage, a network filter), the plugin falls back on its **backup check** on your own server: a trap field, a signed fill time, proof that a person used the page, a proof of work handed out by your own site and a rate limit. A contact message or comment that passes it is delivered; anything else is held for review. Held messages wait 14 days under Settings → formconfirmed → Held messages and are delivered to your e-mail address with one click.

**Works with formexposed.** If the [formexposed](https://formexposed.com/) plugin is installed, formconfirmed uses its protection layer as the backup check and its quarantine for held messages, and takes over the keys from its form protection tab.

**Getting started**

1. Create a free account at [formconfirmed.com](https://formconfirmed.com/register) and add your site. You get a site key and a secret key.
2. Enter both in Settings → formconfirmed → Settings and click *Test the connection*.
3. Choose your forms under Forms. That is all - the protected forms show a small "Confirmed by formconfirmed" badge.

The free plan includes 50,000 checks a month. Above the quota nothing is switched off: checks continue without the AI content check and you get an e-mail. Paid plans add more checks, the AI content check and the option to hide the badge.

== External services ==

This plugin connects to **formconfirmed**, a form protection service run by LW IT Solutions (Lukas Wojcik) on servers in the EU. It is needed to tell people from bots; without the service's keys the plugin does nothing.

* **In the visitor's browser**, on pages with a protected form: the widget script `https://formconfirmed.com/v1/widget.js` is loaded. It fetches a small task from `https://formconfirmed.com/v1/challenge` (sending the site key, the page's host and language) and solves it. No cookies are set, nothing is stored on the device.
* **On your server**, when a protected form is sent: the plugin sends the token from the browser, your secret key, the visitor's IP address, the content of the trap field, the form type, the opaque form reference (never a form title), the first e-mail address entered in the form and - only if you switch on the message check - the message text (up to 2,000 characters) and the mapped contact name, registration username or comment author (up to 200 characters; never a password) to `https://formconfirmed.com/v1/verify`. The service returns allow, flag or block with the reasons. The message text is briefly processed on the service servers. AI checks use a temporary internal queue. IP addresses are only kept shortened and hashed.
* **In the admin**, when you click *Test the connection*: a request to `https://formconfirmed.com/v1/health` and a test request to `/v1/verify` with your secret key.
* **Account connection (optional)**, when you click *Connect with formconfirmed*: the site hostname and a hash of a random verifier are sent to `https://formconfirmed.com/v1/connect/start`. You approve the request in your account at `https://formconfirmed.com/wordpress-connect`. When you complete the connection, the request code and the verifier are sent to `https://formconfirmed.com/v1/connect/poll`, which hands over the keys once. Requests expire after ten minutes; the verifier and the secret key are never part of the approval address.
* **Technical integration report**, after a successful connection test, hourly through WordPress cron while the protection is on and not paused, and when you click *Send report now*: the site key, the site hostname, the service and widget addresses, the plugin component and its version are sent to `https://formconfirmed.com/v1/integration`, signed with a key derived from your secret key. No visitor data, no form contents, no secret key.
* **Feedback (optional)**, only when you click *Not spam* or *Spam* on a held message: your secret key, the opaque check ID of that message and your rating are sent to `https://formconfirmed.com/v1/feedback`. No message fields.
* **Only if the formexposed plugin is installed and connected** and you click *Get keys from my account*: a request to `https://formexposed.com/api/plugin/protect` with the site address and the connection keys of the formexposed plugin.

Terms of use: https://formconfirmed.com/en/legal/terms
Privacy policy: https://formconfirmed.com/en/legal/privacy

The backup check runs on your own server only and sends nothing anywhere.

== Installation ==

1. Install and activate the plugin.
2. Settings → formconfirmed: choose Connect with formconfirmed, approve the displayed site and request code in your account, then complete the connection. You can also enter both keys manually.
3. Click *Test the connection*.

If a page cache or CDN serves your pages, clear it once after saving, so the forms carry the protection.

== Quarantine cleanup ==

The plugin's own quarantine keeps held and released messages for 14 days. Cleanup removes at most 100 expired messages per batch. Remaining work schedules one follow-up batch after a minute; storage errors or blocked entries back off for an hour. It never sends mail. The Held messages screen shows overdue counts, the last successful batch and the next scheduled run. Site Health warns about stalled cleanup. If formexposed keeps messages instead, its retention remains separate; this status concerns any entries in formconfirmed's own quarantine.

WordPress cron normally needs visits. On low-traffic sites, configure your hosting scheduler to run due WordPress events every five minutes, using the actual site path and the site's operating-system account:

    */5 * * * * cd /absolute/path/to/wordpress && wp cron event run --due-now --quiet

If WP-CLI is unavailable, ask your host to configure WordPress cron. Enable DISABLE_WP_CRON only after confirming that the system scheduler works. Do not publish secret scheduler URLs.

For diagnostics: wp formconfirmed status. To run one bounded cleanup batch manually: wp formconfirmed cleanup. Remaining expired messages continue through scheduled batches. Deactivating the plugin removes both its recurring cleanup and its follow-up event.

== Frequently Asked Questions ==

= What happens if the service is down? =

Nothing is waved through unchecked. Contact messages and comments that pass the backup check on your own server are delivered as usual. Everything else - and every message when the backup check is off - is held for your review (or refused, as you choose). Registrations are refused and the visitor can try again; a login is decided by the password. The plugin shows a notice in the dashboard while the service gives no clear answer.

= Do I need a consent banner? =

Not for formconfirmed itself: it sets no cookies, stores nothing on the device and creates no fingerprint. Settings → Privacy offers a paragraph for your privacy policy.

= Which data is sent? =

See "External services" above.

= Can I use it behind Cloudflare or another proxy? =

Yes. If every visitor appears with the proxy's address, return the real address with the filter `formconfirmed_client_ip`.

= Can I use it on a staging site? =

Yes. Add the staging host to the protected site in your account, or define `FORMCONFIRMED_API` in wp-config.php to point to another instance of the service.

== Screenshots ==

1. Overview: status, connection test and what happened since activation.
2. Forms: switch every form of Contact Form 7, WPForms, Gravity Forms, Fluent Forms and Formidable on or off.
3. Settings: keys, message check, backup check and what happens to messages that are not a clear pass.
4. What visitors see: a normal form with a small badge - nothing to solve.
5. Developers: shortcode, PHP functions and filters for forms of your own.

== Field mapping ==

Settings → formconfirmed → Field mapping lists saved definitions from Contact Form 7, WPForms, Gravity Forms, Fluent Forms and Formidable. Choose Automatic, Name, Email, Message or Ignore per field. The detected effective use is displayed without loading visitor values. Hidden fields, passwords, uploads, unknown types and sensitive identifiers cannot be enabled. Custom code can use the formconfirmed_field_roles filter (roles, adapter, form ID); Elementor uses its server-owned record definitions and this filter.

For your own forms, pass the name explicitly as well as the message:

    $result = formconfirmed_check( array( 'purpose' => 'contact', 'name' => $name, 'email' => $email, 'content' => $message ) );

The fields argument is only the local held-message copy; it does not select the API name or message. The optional text check controls both name and message transmission.

Held messages show 50 entries per page, a form-name search, UTC date bounds and filters for waiting, uncertain delivery and passed-to-mailer status. A pending or uncertain delivery is never resent automatically.

== Technical reports and form references ==

The plugin sends a small authenticated technical report after a successful connection test and through the hourly WordPress scheduler while enabled. This contains the configured site key, site hostname, API/widget addresses, plugin component and version, but no visitor/form contents or secret key. Authentication uses a request signature. No report is sent while protection is disabled or paused.

Form references: numeric provider form IDs are namespaced per installation. Core login, registration, WooCommerce registration and comments each have a site-wide reference. Elementor uses a resolved record's stored form_post_id and widget id; missing/invalid metadata remains unassigned rather than trusting POST fallbacks. For custom forms, pass a fixed server-side form_id key to formconfirmed_check(). Keep the formconfirmed_form_namespace option when migrating the site; uninstalling removes it and a later reinstall creates new references. No titles, visitor content or namespace seed are sent as form references.

Technical report status is stored locally without response text, keys or hostnames. Failed deliveries retain the last accepted time and schedule a retry after 5, 10, 20, 40 and at most 60 minutes. Requests are at least 30 seconds apart. Manual retries require manage_options, POST and the action nonce. WP-Cron requires site visits (or a separately configured system cron).

== Changelog ==

= 1.6.0 =
* Stable opaque form references connect individual forms to the account statistics. IDs come from server-selected form objects, never visitor fields; names and keys can change without changing the ID.
* The plugin overview shows the last technical report attempt and last accepted report. An explicit protected POST action sends a report now, with a minimum interval and bounded scheduled retries.
* Late WP-Cron delivery is explained separately from form-protection availability.

= 1.5.0 =
* Sends an authenticated technical integration report with plugin version and server receipt time. Reports are plugin assertions, not independent verification of installed files.

= 1.4.0 =
* Explicit Not spam / Spam feedback for held messages with an original check ID.
* Bind feedback to the configured site and keep delivery separate from rating.
* Reject unsafe feedback requests and suppress duplicate feedback counts.

= 1.3.0 =
* Browse all held messages with pagination, UTC date, form and status filters.
* Configure safe field roles from server-owned form definitions in the admin.
* Correct name-check examples and data-transmission information.

= 1.2.0 =
* Serialize held-message delivery and prevent repeated or ambiguous resend attempts.
* Map contact field roles from server-owned definitions; exclude hidden, file and password fields.

= 1.1.1 =
* Send registration usernames and comment authors for the optional name check.
* Serialize held-message admission and bound field count, entry size and total stored entries.

= 1.1.0 =
* Connect directly with your formconfirmed account using an expiring request and a server-side verifier.
* Clearer information about temporary message processing.

= 1.0.0 =
* Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Fluent Forms, Formidable, comments, registration (WordPress, WooCommerce) and login (optional).
* A switch for every single form of Contact Form 7, WPForms, Gravity Forms, Fluent Forms and Formidable; new forms are protected automatically.
* Shortcode `[formconfirmed]`, the Contact Form 7 form tag `[formconfirmed]` and the PHP functions `formconfirmed_widget()` and `formconfirmed_check()` for forms of your own.
* Backup check on your own server, so messages are not lost or let through unchecked while the service cannot be reached.
* Held messages: 14 days, delivered with one click.
* Takes over the settings of the formexposed form protection tab.

== Upgrade Notice ==

= 1.6.0 =
Adds stable form references for the account statistics and shows the delivery of the technical report. Keep the formconfirmed_form_namespace option when you move the site.

= 1.5.0 =
Sends a small signed technical report (plugin version, site key and hostname, no visitor data) so your account can show the integration status.

= 1.4.0 =
Adds explicit held-message feedback without transmitting message fields. Existing messages without check IDs remain readable and deliverable.

= 1.3.0 =
Adds held-message filters and per-form field mapping. Sensitive field types remain excluded.

= 1.2.0 =
Prevents duplicate held-message delivery and improves typed contact field selection.

= 1.1.1 =
Adds registration name checks and bounded, serialized held-message storage.

= 1.1.0 =
Adds direct account approval for securely connecting WordPress.

= 1.0.0 =
First version.
