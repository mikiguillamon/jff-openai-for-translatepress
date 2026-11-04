<?php
/**
 * Plugin Name: OpenAI for TranslatePress (JFF)
 * Description: Adds an independent OpenAI Responses API translation engine to TranslatePress. Use your own API key and model ID.
 * Version: 0.1.0
 * Author: Just For Fun
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: translatepress-multilingual
 * Text Domain: jff-openai-for-translatepress
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

final class JFF_TranslatePress_OpenAI {
    const ENGINE = 'jff_openai_responses';
    const VERSION = '0.1.0';
    const PREFIX = 'jff_openai_';

    public static function boot() {
        add_action('admin_notices', [__CLASS__, 'dependency_notice']);
        add_filter('trp_machine_translation_engines', [__CLASS__, 'register_engine'], 20);
        add_filter('trp_automatic_translation_engines_classes', [__CLASS__, 'register_engine_class'], 20);
        add_action('trp_machine_translation_extra_settings_middle', [__CLASS__, 'render_settings'], 20);
        add_filter('trp_machine_translation_sanitize_settings', [__CLASS__, 'sanitize_settings'], 20);
    }

    public static function dependency_notice() {
        if (class_exists('TRP_Translate_Press') || !current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' . esc_html__('OpenAI for TranslatePress requires the TranslatePress plugin to be active.', 'jff-openai-for-translatepress') . '</p></div>';
    }

    public static function register_engine($engines) {
        $engines[] = [
            'value' => self::ENGINE,
            'label' => 'OpenAI (own API key - JFF)',
        ];
        return $engines;
    }

    public static function register_engine_class($classes) {
        if (!class_exists('TRP_Machine_Translator')) {
            return $classes;
        }
        require_once __DIR__ . '/includes/class-jff-openai-engine.php';
        $classes[self::ENGINE] = 'JFF_TRP_OpenAI_Engine';
        return $classes;
    }

    public static function sanitize_settings($settings) {
        if (!is_array($settings)) {
            return $settings;
        }

        $previous = get_option('trp_machine_translation_settings', []);
        if (!is_array($previous)) {
            $previous = [];
        }

        $key_field = self::PREFIX . 'key';
        if (!empty($settings[self::PREFIX . 'clear_key'])) {
            $settings[$key_field] = '';
        } else {
            $new_key = isset($settings[$key_field]) && is_string($settings[$key_field])
                ? trim(sanitize_text_field(wp_unslash($settings[$key_field]))) : '';
            $settings[$key_field] = ($new_key !== '') ? $new_key : ($previous[$key_field] ?? '');
        }
        unset($settings[self::PREFIX . 'clear_key']);

        $model = isset($settings[self::PREFIX . 'model']) && is_string($settings[self::PREFIX . 'model'])
            ? trim(sanitize_text_field(wp_unslash($settings[self::PREFIX . 'model']))) : '';
        $settings[self::PREFIX . 'model'] = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $model)
            ? $model : 'gpt-6-luna';

        $allowed = ['auto', 'none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];
        $reasoning = $settings[self::PREFIX . 'reasoning'] ?? 'auto';
        $settings[self::PREFIX . 'reasoning'] = in_array($reasoning, $allowed, true) ? $reasoning : 'auto';
        $settings[self::PREFIX . 'batch'] = self::clamp_int($settings[self::PREFIX . 'batch'] ?? 8, 1, 20, 8);
        $settings[self::PREFIX . 'tokens'] = self::clamp_int($settings[self::PREFIX . 'tokens'] ?? 8192, 256, 32768, 8192);
        $context = isset($settings[self::PREFIX . 'context']) && is_string($settings[self::PREFIX . 'context'])
            ? sanitize_textarea_field(wp_unslash($settings[self::PREFIX . 'context'])) : '';
        $settings[self::PREFIX . 'context'] = substr($context, 0, 800);
        return $settings;
    }

    private static function clamp_int($value, $min, $max, $fallback) {
        if (!is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $fallback;
        }
        return max($min, min($max, (int) $value));
    }

    public static function render_settings($settings) {
        if (!is_array($settings)) {
            $settings = [];
        }
        $model = $settings[self::PREFIX . 'model'] ?? 'gpt-6-luna';
        $reasoning = $settings[self::PREFIX . 'reasoning'] ?? 'auto';
        $batch = $settings[self::PREFIX . 'batch'] ?? 8;
        $tokens = $settings[self::PREFIX . 'tokens'] ?? 8192;
        $context = $settings[self::PREFIX . 'context'] ?? '';
        $has_constant = defined('JFF_TRP_OPENAI_API_KEY') && is_string(JFF_TRP_OPENAI_API_KEY) && JFF_TRP_OPENAI_API_KEY !== '';
        $has_saved_key = !empty($settings[self::PREFIX . 'key']);
        ?>
        <div class="trp-engine trp-automatic-translation-engine__container" id="<?php echo esc_attr(self::ENGINE); ?>">
            <span class="trp-primary-text-bold">OpenAI API key</span>
            <div class="trp-automatic-translation-api-key-container">
                <input type="password" class="trp-text-input" id="jff-trp-key"
                       name="trp_machine_translation_settings[<?php echo esc_attr(self::PREFIX . 'key'); ?>]"
                       value="" autocomplete="new-password"
                       placeholder="<?php echo esc_attr($has_constant ? 'Configured in wp-config.php' : ($has_saved_key ? 'Key saved - leave blank to keep' : 'sk-proj-...')); ?>" />
            </div>
            <span class="trp-description-text">
                <?php echo esc_html($has_constant ? 'The wp-config.php constant takes precedence over the saved key.' : 'Leave blank to preserve an existing key.'); ?>
                <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">OpenAI Platform</a>
            </span>
            <?php if ($has_saved_key && !$has_constant) : ?>
                <label style="display:block;margin-top:7px;">
                    <input type="checkbox" name="trp_machine_translation_settings[<?php echo esc_attr(self::PREFIX . 'clear_key'); ?>]" value="1" />
                    Delete saved API key
                </label>
            <?php endif; ?>

            <div style="margin-top:16px;">
                <label class="trp-primary-text-bold" for="jff-trp-model">OpenAI model ID</label>
                <input type="text" id="jff-trp-model" class="trp-text-input" style="display:block;max-width:440px;margin-top:5px;"
                       name="trp_machine_translation_settings[<?php echo esc_attr(self::PREFIX . 'model'); ?>]"
                       value="<?php echo esc_attr($model); ?>" placeholder="gpt-6-luna" spellcheck="false" />
                <span class="trp-description-text">Free-text model ID. No outdated dropdown or plugin update required.</span>
            </div>

            <div style="margin-top:16px;">
                <label class="trp-primary-text-bold" for="jff-trp-reasoning">Reasoning effort</label>
                <select id="jff-trp-reasoning" class="trp-select" style="display:block;margin-top:5px;"
                        name="trp_machine_translation_settings[<?php echo esc_attr(self::PREFIX . 'reasoning'); ?>]">
                    <?php foreach (['auto' => 'Automatic (safest)', 'none' => 'None (when supported)', 'minimal' => 'Minimal', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'xhigh' => 'Extra high', 'max' => 'Maximum'] as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($reasoning, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="trp-description-text">Automatic omits the parameter. Not every model accepts every effort level.</span>
            </div>

            <div style="margin-top:16px;">
                <label class="trp-primary-text-bold" for="jff-trp-batch">Strings per API request</label>
                <input type="number" id="jff-trp-batch" min="1" max="20" class="trp-text-input" style="display:block;width:110px;margin-top:5px;"
                       name="trp_machine_translation_settings[<?php echo esc_attr(self::PREFIX . 'batch'); ?>]"
                       value="<?php echo esc_attr($batch); ?>" />
                <span class="trp-description-text">8 is a good starting point. Smaller batches reduce formatting failures.</span>
            </div>

            <div style="margin-top:16px;">
                <label class="trp-primary-text-bold" for="jff-trp-tokens">Maximum output tokens</label>
                <input type="number" id="jff-trp-tokens" min="256" max="32768" step="256" class="trp-text-input" style="display:block;width:130px;margin-top:5px;"
                       name="trp_machine_translation_settings[<?php echo esc_attr(self::PREFIX . 'tokens'); ?>]"
                       value="<?php echo esc_attr($tokens); ?>" />
                <span class="trp-description-text">Includes reasoning tokens. Subject to model-specific limits.</span>
            </div>

            <div style="margin-top:16px;">
                <label class="trp-primary-text-bold" for="jff-trp-context">Site terminology (optional)</label>
                <textarea id="jff-trp-context" class="trp-text-input" rows="3" maxlength="800" style="display:block;max-width:650px;width:100%;margin-top:5px;"
                          name="trp_machine_translation_settings[<?php echo esc_attr(self::PREFIX . 'context'); ?>]"><?php echo esc_textarea($context); ?></textarea>
                <span class="trp-description-text">For example: industry, formal/informal tone, terms not to translate. Sent with each translation request.</span>
            </div>
            <p class="trp-description-text" style="margin-top:16px;">Uses only OpenAI Responses API (HTTPS). No temperature, top_p or legacy max_tokens. Already saved TranslatePress translations are not overwritten by this engine.</p>
        </div>
        <?php
    }
}

add_action('plugins_loaded', ['JFF_TranslatePress_OpenAI', 'boot'], 1);
