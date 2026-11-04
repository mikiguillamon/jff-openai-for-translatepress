# OpenAI for TranslatePress (JFF)

A small standalone WordPress plugin that adds **one translation engine** to TranslatePress: OpenAI via the **Responses API**. This is not a fork of TranslatePress and does not replace its interface, language switcher, translation database, or visual translation editor.

## Translation Manager (0.2.0-beta.1)

Open **Settings → OpenAI TranslatePress** after activating the plugin. The manager is separate from TranslatePress's own automatic-translation settings.

### Reset a language or all languages

- Use **Reset es_ES / ca / de_DE / fr_FR** to clear that target language's stored regular dictionary and gettext translations, including manual edits. **Reset all languages** performs this for every target language. The original content and WordPress posts are preserved.
- A restorable database backup and an exact typed confirmation are required. Reset does not run automatically on activation or plugin update.
- URL slugs are **preserved by default**. An optional slug reset is offered only if the plugin recognizes a compatible language-scoped table schema. Removing translated slugs can break links and indexing.
- Resetting translations is irreversible without restoring a backup. Validate carefully in staging first.

### Translate the website or one language

- Select **Translate entire website** or a target language. The manager discovers the homepage, published public posts/pages, relevant public archive pages and optional same-site URLs. It requests the localized URLs without requiring you to visit them manually.
- It then processes eligible pending dictionary strings in independent background requests with a maximum of eight strings (in two API groups of four) per request. Existing translated strings and manual translations are not overwritten.
- **Pause, Resume, Stop** controls and a progress/status panel are available. It waits until the next day if the TranslatePress daily character limit would be exceeded.
- WP-Cron and non-blocking loopback requests drive the job. Where hosting disables either, use a server cron or the WP-CLI command \`wp jff-trp tick\`, with \`--path\` set to your WordPress directory.
- A final page pass warms content; the manager purges LiteSpeed's page cache on successful completion when that integration is active.

This is a **beta** feature: test in staging before resetting any live language or running a large paid OpenAI translation job. Background processing depends on working WordPress loopbacks, TranslatePress's current database schema, and WP-Cron. JavaScript-only, private or unusual dynamically generated content may not be included. Gettext translations and SEO slugs have separate storage and are not guaranteed to be covered by bulk dictionary translation; manually verify these where relevant.

Run isolated automated checks with \`php tests/test-engine.php\` and \`php tests/test-manager.php\`.

## What it does

- Adds **OpenAI (own API key - JFF)** to TranslatePress > Automatic Translation.
- Accepts a free-text model identifier such as `gpt-6-luna` (no model-name whitelist).
- Uses `POST https://api.openai.com/v1/responses`, with `max_output_tokens` rather than legacy `max_tokens`.
- Deliberately omits `temperature` and `top_p`, avoiding common HTTP 400 errors on newer reasoning models.
- Optional reasoning effort: automatic (recommended for maximum compatibility), none, minimal, low, medium, high, xhigh, max. Not all models support all effort levels.
- Conservatively validates response JSON, original HTML tags/attributes, and protected placeholders before returning translations to TranslatePress. Invalid/incomplete responses are **not** saved as successful translations.
- Uses TranslatePress's daily character quota and translation storage. Existing translations remain untouched.
- Uses `store: false` on Responses API calls. Translation text still leaves your WordPress site and goes to OpenAI; review OpenAI data-handling terms before using customer information.

## Update from 0.1.0 (important)

Version **0.1.0** had a settings-save bug: TranslatePress drops unrecognized settings before passing the sanitized array to the custom engine filter. Our old callback did not accept the second, original-form argument, so the API key and custom settings were discarded on save. **Version 0.1.1 fixes this.**

After updating, **re-enter the API key and save**, as 0.1.0 may never have stored it. Make sure the engine is selected and automatic translation is enabled, then use the secondary-language URL of a page with untranslated content. If the API key field says "Key saved - leave blank to keep" after reloading the settings page, it was retained. You should not need to reset any prior TranslatePress translations.

## Install

1. Make a backup and test first in a staging site.
2. Activate **TranslatePress** (free or paid).
3. Disable **Rankscale**, **HHG**, and other overlapping OpenAI translation engines while testing. They are not required.
4. WordPress > Plugins > Add New > Upload Plugin > select the ZIP > Install Now > Activate.
5. Settings > TranslatePress > **Automatic Translation**. Enable automatic translation and select **OpenAI (own API key - JFF)**.
6. Enter your API key and an exact model ID, for example `gpt-6-luna`. Keep reasoning on **Automatic** initially.
7. Set a modest TranslatePress daily character limit, save, then click **Test API credentials**.
8. Open an untranslated page in the secondary language. Monitor usage at https://platform.openai.com/usage.

The plugin adds its settings to the normal TranslatePress page, not a separate admin page. It does not include bulk translation jobs; TranslatePress determines when content is translated and cached.

## Secure key configuration

You can put the following in `wp-config.php`, above the line that loads WordPress, instead of storing the key in the WordPress database:

```php
define('JFF_TRP_OPENAI_API_KEY', 'sk-proj-YOUR_NEW_KEY');
```

If present, that constant overrides the saved UI key. Otherwise the key is stored in the `trp_machine_translation_settings` WordPress option, like other TranslatePress engine credentials. The settings page does not reprint the saved key in the password field. Leaving the field blank preserves the previous key; use the explicit checkbox to erase it.

If you have pasted an API key into a screenshot, revoke it and create a new one.

## Model compatibility

The plugin is intentionally conservative and sends only widely documented Responses API fields. New model IDs can be entered without a plugin update, but **future model support cannot be guaranteed** if OpenAI changes API contracts or a particular model requires additional parameters. A new model must be available to your API project, support Responses API and accept the selected reasoning configuration.

For initial testing with `gpt-6-luna`, leave reasoning on **Automatic** to omit the parameter. You can test **None** afterwards if the model supports it and want to reduce reasoning overhead. The plugin will report a normal API error instead of silently changing models.

## Settings

| Setting | Default | Notes |
| --- | --- | --- |
| Model ID | `gpt-6-luna` | Any model ID composed of letters, numbers, `.`, `_`, `-` |
| Reasoning effort | Automatic | Omit optional reasoning parameter |
| Strings per request | 8 | Range 1-20; lower values reduce formatting errors |
| Max output tokens | 8192 | Range 256-32768; actual model limits vary |
| Site terminology | Empty | Optional instructions about tone/industry, sent on every request |

TranslatePress's own daily quota limits characters, not actual OpenAI token spending. Keep an appropriate account-level OpenAI budget and usage alerts.

## Troubleshooting

- **No OpenAI engine:** activate TranslatePress and confirm its Automatic Translation tab works. Update TranslatePress if the engine-class hook is unavailable.
- **HTTP 400:** confirm the model ID and available project access; retry with reasoning set to Automatic and a lower output token cap. Unlike Rankscale, this plugin never sends `temperature`.
- **HTTP 401/403:** invalid/restricted key or model access.
- **HTTP 429:** quota/rate limit or billing. Check the OpenAI Platform.
- **No translation saved:** if the output was incomplete, had invalid JSON, altered HTML, or changed protected placeholders, the plugin rejects the batch rather than corrupting translations. Try 2-4 strings per request.
- **Previously translated text unchanged:** TranslatePress caches old translations. This plugin only generates translations for content not already translated by TranslatePress.

## Technical notes

Requires PHP 7.4+, WordPress 6.0+, and a working TranslatePress installation. The plugin uses WordPress HTTP APIs, one fixed OpenAI HTTPS endpoint, no extra PHP dependencies, and no remote code. It has no telemetry. Diagnostic logs are limited to error codes when `WP_DEBUG_LOG` is enabled. Its engine key (`jff_openai_responses`) is deliberately distinct from Rankscale's (`openai_translate`).

Because the test suite mocks WordPress and OpenAI, passing tests **does not replace a live staging-site compatibility test**.

## Credits and license

Original implementation by Just For Fun, inspired by the public integration strategy used in [Rankscale AI for TranslatePress](https://github.com/XuanRanL/Rankscale-AI-For-TranslatePress). No source files from Rankscale were bundled. License: GPL-2.0-or-later, in keeping with WordPress plugin distribution.

## Diagnosing missing automatic translations (v0.1.2)

1. Open **Settings > TranslatePress > Automatic Translation**. Set Enable Automatic Translation to **Yes**, choose **OpenAI (own API key - JFF)** and save the settings.
2. Check **Translation diagnostics** and select **Test full translation (English to Spanish)**. Unlike the standard credential check, this validates the translated text as well as the HTTP status.
3. Open a page with untranslated text in the secondary language, bypassing full-page cache while diagnosing. Return to the settings and reload.
4. Review **Last frontend translation event**:
   - **No event:** TranslatePress did not call the engine; check whether there are untranslated strings and whether caching or another plugin is short-circuiting the request.
   - **skipped / translatepress_validation:** TranslatePress rejected the request before it went to OpenAI. Check API key, source/target languages, daily character limit and crawler blocking.
   - **started:** The engine entered an OpenAI request but did not record completion. Check PHP timeouts and server logs.
   - **error / code:** The model or API response was rejected. HTTP failures show \`jff_openai_http_error\`, malformed JSON \`jff_openai_bad_json\`, missing segments \`jff_openai_bad_count\`, and changed HTML/placeholders \`jff_openai_unsafe_translation\`.
   - **success / partial_success:** The engine returned validated translated text to TranslatePress. If the page still displays source text, investigate its translation database and page cache.

The activity record stores a timestamp, status, sanitized error code, and source/target languages only. It does not store translated content, page text or API credentials. When a batch returns a malformed model response, individual segments may be retried. This can consume additional API tokens.

**Important:** TranslatePress's own API credentials test page may display the API key. Never share screenshots of that page. Prefer the plugin's full translation test.

## Changelog

### 0.2.0-beta.3
- Skip URI-like technical references such as tel: and mailto: before contacting OpenAI.
- Retry any other silently skipped string once in a fresh request; then leave it pending for review and continue translating.
- Record `translatepress_no_api_call` rather than misattributing a previous successful response.


### 0.1.2
- Add administrator-only full translation test with WordPress nonce validation.
- Record the last frontend translation activity and a safe error code.
- Validate actual API response content during the built-in test, not merely the HTTP status.
- Retry individual segments when model formatting invalidates a batch, without retrying HTTP errors.


### 0.1.1
- Fix missing API credentials and model settings on save: correctly receive the raw TranslatePress settings as the filter's second argument.
- Preserve settings if another engine's form omits our inputs.
- Add regression tests reproducing TranslatePress's settings whitelist.
