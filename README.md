# formconfirmed for WordPress

Invisible spam protection for WordPress forms, powered by [formconfirmed](https://formconfirmed.com): a small proof of work in the visitor's browser, timing and trap-field signals, an e-mail check and an optional check of the message text - hosted in the EU, no puzzles, no cookies.

- Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Fluent Forms, Formidable, comments, registration (WordPress, WooCommerce) and login (optional)
- A switch for every single form under Settings > formconfirmed > Forms; new forms are protected automatically
- `[formconfirmed]` shortcode, the Contact Form 7 form tag `[formconfirmed]` and the PHP functions `formconfirmed_widget()` / `formconfirmed_check()` for forms of your own
- Never a silent pass: if the service cannot be reached, a backup check on your own server decides; everything else is held for review (14 days, delivered with one click)

Needs a free account at [formconfirmed.com](https://formconfirmed.com/register) for the site key and secret key. Requires WordPress 6.2 and PHP 7.4. Details, including which data is sent to the service, in [readme.txt](readme.txt).

Integration examples for other languages (PHP, Node.js, Python, Ruby, Go, Java, .NET): [formconfirmed-examples](https://github.com/LW-IT-Solutions/formconfirmed-examples).

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
