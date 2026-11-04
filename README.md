# OpenAI for TranslatePress (JFF)

A small standalone WordPress plugin that adds **one translation engine** to TranslatePress: OpenAI via the **Responses API**. This is not a fork of TranslatePress and does not replace its interface, language switcher, translation database, or visual translation editor.

## What it does

- Adds **OpenAI (own API key - JFF)** to TranslatePress > Automatic Translation.
- Accepts a free-text model identifier such as `gpt-6-luna` (no model-name whitelist).
- Uses `POST https://api.openai.com/v1/responses`, with `max_output_tokens` rather than legacy `max_tokens`.
- Deliberately omits `temperature` and `top_p`, avoiding common HTTP 400 errors on newer reasoning models.
- Optional reasoning effort: automatic (recommended for maximum compatibility), none, minimal, low, medium, high, xhigh, max. Not all models support all effort levels.
- Conservatively validates response JSON, original HTML tags/attributes, and protected placeholders before returning translations to TranslatePress. Invalid/incomplete responses are **not** saved as successful translations.
- Uses TranslatePress's daily character quota and translation storage. Existing translations remain untouched.
- Uses `store: false` on Responses API calls. Translation text still leaves your WordPress site and goes to OpenAI; review OpenAI data-handling terms before using customer information.

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
