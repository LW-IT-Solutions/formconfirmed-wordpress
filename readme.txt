=== formconfirmed – Invisible spam protection for forms ===
Contributors: lwitsolutions
Tags: spam, anti-spam, captcha, contact form 7, honeypot
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
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
* Optional: the message text (spam filter in every plan, an AI check where your plan includes it). Checked in memory, never stored.

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
* **On your server**, when a protected form is sent: the plugin sends the token from the browser, your secret key, the visitor's IP address, the content of the trap field, the form type, the first e-mail address entered in the form and - only if you switch on the message check - the message text (up to 2,000 characters) to `https://formconfirmed.com/v1/verify`. The service returns allow, flag or block with the reasons. The message text is checked in memory and not stored; IP addresses are only kept shortened and hashed.
* **In the admin**, when you click *Test the connection*: a request to `https://formconfirmed.com/v1/health` and a test request to `/v1/verify` with your secret key.
* **Only if the formexposed plugin is installed and connected** and you click *Get keys from my account*: a request to `https://formexposed.com/api/plugin/protect` with the site address and the connection keys of the formexposed plugin.

Terms of use: https://formconfirmed.com/en/legal/terms
Privacy policy: https://formconfirmed.com/en/legal/privacy

The backup check runs on your own server only and sends nothing anywhere.

== Installation ==

1. Install and activate the plugin.
2. Settings → formconfirmed: enter the site key and the secret key from your formconfirmed account.
3. Click *Test the connection*.

If a page cache or CDN serves your pages, clear it once after saving, so the forms carry the protection.

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

== Changelog ==

= 1.0.0 =
* Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Fluent Forms, Formidable, comments, registration (WordPress, WooCommerce) and login (optional).
* A switch for every single form of Contact Form 7, WPForms, Gravity Forms, Fluent Forms and Formidable; new forms are protected automatically.
* Shortcode `[formconfirmed]`, the Contact Form 7 form tag `[formconfirmed]` and the PHP functions `formconfirmed_widget()` and `formconfirmed_check()` for forms of your own.
* Backup check on your own server, so messages are not lost or let through unchecked while the service cannot be reached.
* Held messages: 14 days, delivered with one click.
* Takes over the settings of the formexposed form protection tab.
