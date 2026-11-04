<?php
/**
 * Translation management for OpenAI for TranslatePress.
 *
 * Reset affects translations, not originals, language settings, or source content.
 * Background workers process one page or up to 8 dictionary strings per PHP request.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class JFF_TRP_Manager {
    const JOB_OPTION = 'jff_trp_bulk_job';
    const LOCK_OPTION = 'jff_trp_bulk_worker_lock';
    const CRON_HOOK = 'jff_trp_bulk_tick';
    const PAGE_SLUG = 'jff-trp-manager';
    const BATCH_SIZE = 4;
    const STRINGS_PER_TICK = 8;
    const SCOPE_MODE = 'rendered_urls_v1';
    const MAX_STRING_ATTEMPTS = 2;
    const MAX_REPORTED_ISSUES = 30;

    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('init', [__CLASS__, 'discovery_mode'], 1);
        add_action('wp_ajax_jff_trp_manage', [__CLASS__, 'ajax_manage']);
        add_action('wp_ajax_jff_trp_worker', [__CLASS__, 'ajax_worker']);
        add_action('wp_ajax_nopriv_jff_trp_worker', [__CLASS__, 'ajax_worker']);
        add_action(self::CRON_HOOK, [__CLASS__, 'cron_worker'], 10, 1);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('jff-trp tick', function () {
                $job = get_option(self::JOB_OPTION, []);
                if (!is_array($job) || empty($job['id'])) {
                    WP_CLI::error('No translation job has been started.');
                }
                self::run_once($job['id']);
                WP_CLI::log(wp_json_encode(self::public_job(get_option(self::JOB_OPTION, []))));
            });
        }
    }

    private static function languages() {
        $settings = get_option('trp_settings', []);
        if (!is_array($settings)) {
            return [];
        }
        $default = $settings['default-language'] ?? '';
        return array_values(array_filter(array_unique((array) ($settings['translation-languages'] ?? [])), function ($lang) use ($default) {
            return is_string($lang) && $lang !== '' && $lang !== $default && preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z]{2,4})?$/D', $lang);
        }));
    }

    /** Restrict the bulk dictionary to strings encountered on signed site crawls. */
    private static function seen_table_name() {
        global $wpdb;
        $table = $wpdb->prefix . 'jff_trp_bulk_seen';
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
            throw new RuntimeException('Unsafe bulk queue table name.');
        }
        return $table;
    }

    private static function ensure_seen_table() {
        global $wpdb;
        $table = self::seen_table_name();
        $collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            job_id varchar(36) NOT NULL,
            language varchar(16) NOT NULL,
            original_hash char(32) NOT NULL,
            PRIMARY KEY (job_id, language, original_hash)
        ) {$collate}";
        if ($wpdb->query($sql) === false ||
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            throw new RuntimeException('Could not create the scoped translation queue.');
        }
        return $table;
    }

    private static function clear_seen_queue() {
        global $wpdb;
        $table = self::ensure_seen_table();
        if ($wpdb->query("DELETE FROM {$table}") === false) {
            throw new RuntimeException('Could not initialize the scoped translation queue.');
        }
    }

    private static function is_scoped_job($job) {
        return is_array($job) && ($job['scope_mode'] ?? '') === self::SCOPE_MODE;
    }

    /**
     * This callback runs ONLY on a verified, signed discovery page request.
     * The filter receives actual strings TranslatePress found in rendered HTML,
     * before it inserts any previously unseen strings into its dictionary.
     * Store hashes, never source text, and do not change the filter return value.
     */
    public static function capture_rendered_strings($dictionary, $query, $strings, $lang, $block_type = null) {
        global $wpdb;
        $job = get_option(self::JOB_OPTION, []);
        if (!self::is_scoped_job($job) || ($job['status'] ?? '') !== 'running' ||
            ($job['phase'] ?? '') !== 'discover' ||
            !in_array($lang, (array) ($job['languages'] ?? []), true)) {
            return $dictionary;
        }
        $seen = self::seen_table_name();
        $values = [];
        $placeholders = [];
        foreach ((array) $strings as $original) {
            if (!is_string($original) || $original === '') {
                continue;
            }
            // The database stores some original values after sanitization.
            // Track both forms to make joining resilient without translating new content.
            $variants = [$original];
            if (function_exists('trp_sanitize_string')) {
                $variants[] = trp_sanitize_string($original, false);
            }
            foreach (array_unique($variants) as $value) {
                $placeholders[] = '(%s, %s, %s)';
                array_push($values, $job['id'], $lang, md5($value));
            }
        }
        if ($placeholders) {
            $sql = "INSERT IGNORE INTO {$seen} (job_id, language, original_hash) VALUES " .
                implode(', ', $placeholders);
            if ($wpdb->query($wpdb->prepare($sql, $values)) === false) {
                throw new RuntimeException('Failed to capture observed page strings.');
            }
        }
        return $dictionary;
    }

    private static function require_engine() {
        $mt = get_option('trp_machine_translation_settings', []);
        if (!class_exists('TRP_Translate_Press') || !is_array($mt) ||
            ($mt['translation-engine'] ?? '') !== JFF_TranslatePress_OpenAI::ENGINE ||
            ($mt['machine-translation'] ?? '') !== 'yes') {
            throw new RuntimeException('Activate OpenAI (JFF) and automatic translation in TranslatePress first.');
        }
        $trp = TRP_Translate_Press::get_trp_instance();
        $engine = $trp->get_component('machine_translator');
        if (!($engine instanceof JFF_TRP_OpenAI_Engine)) {
            throw new RuntimeException('The active engine is not OpenAI (JFF).');
        }
        return $trp;
    }

    public static function menu() {
        $hook = add_options_page('OpenAI TranslatePress Manager', 'OpenAI TranslatePress', 'manage_options', self::PAGE_SLUG, [__CLASS__, 'render']);
        if ($hook) {
            add_action('admin_enqueue_scripts', function ($page) use ($hook) {
                if ($page !== $hook) {
                    return;
                }
                wp_enqueue_script('jff-trp-manager', plugins_url('assets/jff-trp-manager.js', dirname(__DIR__) . '/jff-openai-for-translatepress.php'), [], JFF_TranslatePress_OpenAI::VERSION, true);
                wp_localize_script('jff-trp-manager', 'JFF_TRP_MANAGER', [
                    'ajax' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('jff_trp_manager'),
                ]);
            });
        }
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        $languages = self::languages();
        ?>
        <div class="wrap" id="jff-trp-manager">
            <h1>OpenAI for TranslatePress - Translation Manager</h1>
            <p>Translate publicly available pages without opening them manually, or reset stored translations by language.</p>
            <div class="notice notice-info inline"><p><strong>Before resetting:</strong> take a full database backup. Reset deletes manual and automatic translations. Optional URL slug resets can break indexed URLs and require redirects. Jobs use paid OpenAI API calls and respect TranslatePress's daily character quota.</p></div>
            <h2>Bulk translation</h2>
            <p>Automatic discovery includes the home page, published public post types, public term archives and post-type archives. JavaScript-only, private, or password-protected content may need additional URLs and manual review.</p>
            <p><label for="jff-extra-urls"><strong>Additional public URLs (optional, one per line)</strong></label><br>
            <textarea id="jff-extra-urls" rows="3" cols="85" placeholder="https://example.com/custom-landing-page/"></textarea></p>
            <p><button type="button" class="button button-primary" data-jff-action="start" data-lang="all">Translate entire website</button>
            <?php foreach ($languages as $lang): ?>
                <button type="button" class="button" data-jff-action="start" data-lang="<?php echo esc_attr($lang); ?>">Translate <?php echo esc_html($lang); ?></button>
            <?php endforeach; ?></p>
            <p><button type="button" class="button" data-jff-action="pause">Pause</button>
            <button type="button" class="button" data-jff-action="resume">Resume</button>
            <button type="button" class="button" data-jff-action="stop">Stop</button></p>
            <div id="jff-trp-status" role="status" aria-live="polite" style="border:1px solid #c3c4c7;padding:14px;max-width:760px;background:#fff;white-space:pre-wrap">Loading job status...</div>
            <p class="description">Runs in short isolated background HTTP requests, with WP-Cron as a fallback. If the host blocks self-requests or disables WP-Cron, run a server cron or <code>wp jff-trp tick</code>. The worker pauses rather than repeatedly charging for a failed batch.</p>
            <hr>
            <h2>Reset translations</h2>
            <p>Resets dictionary and gettext translations while preserving original strings and site content. To reset translated URL slugs too, tick the separate option; old localized links can then return 404 until you create redirects.</p>
            <p><label><input id="jff-confirm-backup" type="checkbox"> I have verified a restorable full database backup.</label></p>
            <p><label><input id="jff-reset-slugs" type="checkbox"> Also delete translated URL slugs (may break existing backlinks and SEO).</label></p>
            <div id="jff-trp-reset-counts"><p>Loading translation counts...</p></div>
            <p><?php foreach ($languages as $lang): ?>
                <button type="button" class="button" data-jff-action="reset" data-lang="<?php echo esc_attr($lang); ?>">Reset <?php echo esc_html($lang); ?></button>
            <?php endforeach; ?></p>
            <p><button type="button" class="button button-link-delete" data-jff-action="reset" data-lang="all">Reset all languages</button></p>
            <p class="description">Reset requires a new typed confirmation. No data is removed on page load, and the original-language content is never deleted.</p>
            <div id="jff-trp-feedback" role="alert" style="max-width:760px"></div>
        </div>
        <?php
    }

    public static function ajax_manage() {
        if (!current_user_can('manage_options') || !check_ajax_referer('jff_trp_manager', 'nonce', false)) {
            wp_send_json_error(['message' => 'Not authorized.'], 403);
        }
        $operation = sanitize_key(wp_unslash($_POST['operation'] ?? 'status'));
        try {
            if ($operation === 'status') {
                wp_send_json_success(self::status_payload());
            }
            $existing = get_option(self::JOB_OPTION, []);
            if (!is_array($existing)) {
                $existing = [];
            }
            if ($operation === 'start') {
                if (in_array($existing['status'] ?? '', ['running', 'waiting', 'paused'], true)) {
                    throw new RuntimeException('Another translation job exists. Stop it before starting a different job.');
                }
                self::require_engine();
                $lang = sanitize_text_field(wp_unslash($_POST['language'] ?? ''));
                $langs = $lang === 'all' ? self::languages() : [$lang];
                if (!$langs || array_diff($langs, self::languages())) {
                    throw new RuntimeException('Invalid translation language.');
                }
                $extra = (string) wp_unslash($_POST['extra_urls'] ?? '');
                $urls = self::collect_urls($extra);
                if (!$urls) {
                    throw new RuntimeException('No public URLs were found to translate.');
                }
                self::clear_seen_queue();
                $job = [
                    'id' => wp_generate_uuid4(), 'status' => 'running', 'phase' => 'discover',
                    'scope_mode' => self::SCOPE_MODE,
                    'languages' => $langs, 'urls' => $urls, 'url_count' => count($urls), 'lang_index' => 0, 'url_index' => 0,
                    'pass' => 0, 'passes' => 2, 'db_cursor' => 0, 'db_scanned' => 0,
                    'urls_done' => 0, 'strings_done' => 0, 'strings_unchanged' => 0,
                    'strings_skipped' => 0, 'failed' => 0, 'last_error' => '',
                    'retry_counts' => [], 'review_items' => [],
                    'created' => time(), 'updated' => time(), 'next_attempt' => 0,
                ];
                update_option(self::JOB_OPTION, $job, false);
                self::schedule($job);
                self::dispatch($job);
                wp_send_json_success(self::status_payload());
            }
            if (in_array($operation, ['pause', 'resume', 'stop'], true)) {
                if (empty($existing['id'])) {
                    throw new RuntimeException('No job exists.');
                }
                if ($operation === 'pause' && in_array($existing['status'], ['running', 'waiting'], true)) {
                    $existing['status'] = 'paused';
                } elseif ($operation === 'resume' && in_array($existing['status'], ['paused', 'waiting'], true)) {
                    if (!self::is_scoped_job($existing)) {
                        throw new RuntimeException('This job used unscoped dictionary scanning. Stop it, then start a new scoped job. Existing translations are preserved.');
                    }
                    self::require_engine();
                    $existing['status'] = 'running';
                    $existing['last_error'] = '';
                    $existing['next_attempt'] = 0;
                } elseif ($operation === 'stop' && !in_array($existing['status'], ['finished', 'stopped'], true)) {
                    $existing['status'] = 'stopped';
                } else {
                    throw new RuntimeException('This action is not valid for the current job status.');
                }
                $existing['updated'] = time();
                update_option(self::JOB_OPTION, $existing, false);
                if ($existing['status'] === 'running') {
                    self::schedule($existing);
                    self::dispatch($existing);
                }
                wp_send_json_success(self::status_payload());
            }
            if ($operation === 'reset') {
                if (in_array($existing['status'] ?? '', ['running', 'waiting', 'paused'], true)) {
                    throw new RuntimeException('Stop the current translation job before resetting.');
                }
                $lang = sanitize_text_field(wp_unslash($_POST['language'] ?? ''));
                $langs = $lang === 'all' ? self::languages() : [$lang];
                if (!$langs || array_diff($langs, self::languages())) {
                    throw new RuntimeException('Invalid reset language.');
                }
                if ((string) ($_POST['backup_checked'] ?? '') !== '1') {
                    throw new RuntimeException('A verified full database backup is required.');
                }
                $required = $lang === 'all' ? 'DELETE ALL' : 'DELETE ' . $lang;
                if (!hash_equals($required, (string) wp_unslash($_POST['confirmation'] ?? ''))) {
                    throw new RuntimeException('Typed confirmation does not match.');
                }
                $slugs = (string) ($_POST['include_slugs'] ?? '') === '1';
                $counts = self::reset_languages($langs, $slugs);
                self::purge_cache();
                wp_send_json_success(['message' => 'Reset completed.', 'deleted' => $counts, 'state' => self::status_payload()]);
            }
            throw new RuntimeException('Unknown action.');
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }

    private static function status_payload() {
        $counts = [];
        foreach (self::languages() as $lang) {
            try {
                $counts[$lang] = self::count_translations($lang);
            } catch (Throwable $e) {
                $counts[$lang] = ['error' => $e->getMessage()];
            }
        }
        return ['job' => self::public_job(get_option(self::JOB_OPTION, [])), 'counts' => $counts];
    }

    private static function public_job($job) {
        if (!is_array($job) || empty($job['id'])) {
            return null;
        }
        unset($job['urls'], $job['id'], $job['retry_counts']);
        $job['url_total'] = isset($job['languages']) ? count($job['languages']) * (int) ($job['passes'] ?? 1) * (int) ($job['url_count'] ?? 0) : 0;
        return $job;
    }

    private static function safe_table($table) {
        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
            throw new RuntimeException('Invalid database table.');
        }
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table ? $table : null;
    }

    private static function table_names($lang) {
        if (!in_array($lang, self::languages(), true)) {
            throw new RuntimeException('Unknown translation language.');
        }
        $trp = TRP_Translate_Press::get_trp_instance();
        $query = $trp->get_component('query');
        if (!$query) {
            throw new RuntimeException('TranslatePress query component is missing.');
        }
        global $wpdb;
        $dictionary = self::safe_table($query->get_table_name($lang));
        if (!$dictionary) {
            throw new RuntimeException('Dictionary table is missing for ' . $lang);
        }
        $gettext = method_exists($query, 'get_gettext_table_name')
            ? self::safe_table($query->get_gettext_table_name($lang)) : null;
        $slug_table = self::safe_table($wpdb->prefix . 'trp_slug_translations');
        return [$dictionary, $gettext, $slug_table];
    }

    public static function count_translations($lang) {
        global $wpdb;
        list($dictionary, $gettext, $slugs) = self::table_names($lang);
        $where = 'status <> 0 OR (translated IS NOT NULL AND translated <> \'\')';
        $dict_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$dictionary}` WHERE {$where}");
        $gettext_count = $gettext ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$gettext}` WHERE {$where}") : 0;
        $slugs_count = $slugs ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$slugs}` WHERE language = %s", $lang)) : 0;
        return ['dictionary' => $dict_count, 'gettext' => $gettext_count, 'slugs' => $slugs_count];
    }

    private static function reset_languages($langs, $include_slugs) {
        global $wpdb;
        // Validate every table and language before modifying any data.
        $validated = [];
        foreach ($langs as $lang) {
            $validated[$lang] = self::table_names($lang);
        }
        $out = [];
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException('Could not start the database reset transaction.');
        }
        try {
            foreach ($validated as $lang => $tables) {
                list($dictionary, $gettext, $slugs) = $tables;
                $out[$lang] = ['dictionary' => 0, 'gettext' => 0, 'slugs' => 0];
                foreach (['dictionary' => $dictionary, 'gettext' => $gettext] as $kind => $table) {
                    if (!$table) {
                        continue;
                    }
                    $num = $wpdb->query("UPDATE `{$table}` SET translated = '', status = 0 WHERE status <> 0 OR (translated IS NOT NULL AND translated <> '')");
                    if ($num === false) {
                        throw new RuntimeException('Could not reset ' . $kind . ' for ' . $lang . ': ' . $wpdb->last_error);
                    }
                    $out[$lang][$kind] = (int) $num;
                }
                if ($include_slugs && $slugs) {
                    $num = $wpdb->delete($slugs, ['language' => $lang], ['%s']);
                    if ($num === false) {
                        throw new RuntimeException('Could not reset translated URL slugs for ' . $lang . ': ' . $wpdb->last_error);
                    }
                    $out[$lang]['slugs'] = (int) $num;
                }
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('Could not commit the reset.');
            }
            return $out;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /** URLs are discovered from public WordPress content; never crawl external hosts. */
    private static function collect_urls($extra) {
        $urls = [home_url('/')];
        $post_types = get_post_types(['public' => true], 'names');
        if ($post_types) {
            $ids = get_posts([
                'post_type' => array_values($post_types), 'post_status' => 'publish',
                'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true,
            ]);
            foreach ($ids as $id) {
                if (post_password_required((int) $id)) {
                    continue;
                }
                $url = get_permalink($id);
                if ($url) {
                    $urls[] = $url;
                }
            }
            foreach ($post_types as $type) {
                $archive = get_post_type_archive_link($type);
                if ($archive) {
                    $urls[] = $archive;
                }
            }
        }
        $taxonomies = get_taxonomies(['public' => true], 'names');
        foreach ($taxonomies as $taxonomy) {
            $ids = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true, 'fields' => 'ids']);
            if (is_wp_error($ids) || !is_array($ids)) {
                continue;
            }
            foreach ($ids as $term_id) {
                $url = get_term_link((int) $term_id, $taxonomy);
                if (!is_wp_error($url) && $url) {
                    $urls[] = $url;
                }
            }
        }
        foreach (preg_split('/\r\n|\r|\n/', $extra) as $line) {
            if (trim($line) !== '') {
                $urls[] = trim($line);
            }
        }
        $urls = apply_filters('jff_trp_bulk_urls', $urls);
        if (!is_array($urls)) {
            throw new RuntimeException('The URL discovery filter must return an array.');
        }
        $allowed_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $clean = [];
        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }
            $url = esc_url_raw(trim($url));
            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            if (!in_array($scheme, ['http', 'https'], true) || $host !== $allowed_host) {
                continue;
            }
            $url = strtok($url, '#');
            $clean[$url] = true;
        }
        return array_keys($clean);
    }

    /** Signed loopback URLs discover text without calling OpenAI from the crawler request. */
    public static function discovery_mode() {
        if (empty($_GET['jff_trp_discover']) || empty($_GET['jff_trp_signature'])) {
            return;
        }
        $job = get_option(self::JOB_OPTION, []);
        $id = is_array($job) ? ($job['id'] ?? '') : '';
        $requested = sanitize_text_field(wp_unslash($_GET['jff_trp_discover']));
        $signature = sanitize_text_field(wp_unslash($_GET['jff_trp_signature']));
        if ($id !== '' && hash_equals($id, $requested) &&
            hash_equals(self::signature($id, 'discover'), $signature) &&
            ($job['status'] ?? '') === 'running' && self::is_scoped_job($job) &&
            ($job['phase'] ?? '') === 'discover') {
            add_filter('trp_disable_automatic_translations_due_to_error', '__return_true', PHP_INT_MAX);
            add_filter('trp_get_existing_translations', [__CLASS__, 'capture_rendered_strings'], 20, 5);
            if (!headers_sent()) {
                header('X-JFF-TRP-Discovery: 1');
            }
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            nocache_headers();
        }
    }

    private static function signature($id, $purpose) {
        return hash_hmac('sha256', $purpose . '|' . $id, wp_salt('auth'));
    }

    private static function schedule($job) {
        if (($job['status'] ?? '') !== 'running' && ($job['status'] ?? '') !== 'waiting') {
            return;
        }
        $id = $job['id'];
        if (!wp_next_scheduled(self::CRON_HOOK, [$id])) {
            $at = max(time() + 45, (int) ($job['next_attempt'] ?? 0));
            wp_schedule_single_event($at, self::CRON_HOOK, [$id]);
        }
    }

    private static function dispatch($job) {
        if (($job['status'] ?? '') !== 'running') {
            return;
        }
        wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout' => 0.01,
            'blocking' => false,
            'redirection' => 0,
            'sslverify' => true,
            'body' => [
                'action' => 'jff_trp_worker',
                'job' => $job['id'],
                'signature' => self::signature($job['id'], 'worker'),
            ],
        ]);
    }

    public static function ajax_worker() {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            status_header(405);
            exit;
        }
        $id = sanitize_text_field(wp_unslash($_POST['job'] ?? ''));
        $sig = sanitize_text_field(wp_unslash($_POST['signature'] ?? ''));
        $job = get_option(self::JOB_OPTION, []);
        if (!is_array($job) || empty($job['id']) || !hash_equals($job['id'], $id) ||
            !hash_equals(self::signature($id, 'worker'), $sig)) {
            status_header(403);
            exit;
        }
        self::run_once($id);
        wp_die('', '', ['response' => 200]);
    }

    public static function cron_worker($id) {
        self::run_once((string) $id);
    }

    public static function run_once($id) {
        $job = get_option(self::JOB_OPTION, []);
        if (!is_array($job) || ($job['id'] ?? '') !== $id) {
            return;
        }
        if (!self::is_scoped_job($job)) {
            $job['status'] = 'paused';
            $job['last_error'] = 'This job predates page-scoped translation. Stop it and start a new job; existing translations are preserved.';
            $job['updated'] = time();
            update_option(self::JOB_OPTION, $job, false);
            return;
        }
        if (($job['status'] ?? '') === 'waiting') {
            if (time() < (int) ($job['next_attempt'] ?? 0)) {
                self::schedule($job);
                return;
            }
            $job['status'] = 'running';
        }
        if (($job['status'] ?? '') !== 'running') {
            return;
        }
        // add_option is an atomic DB lock on the unique option_name index.
        $lock = (int) get_option(self::LOCK_OPTION, 0);
        if ($lock > 0 && $lock < time()) {
            delete_option(self::LOCK_OPTION);
        }
        if (!add_option(self::LOCK_OPTION, time() + 180, '', false)) {
            return;
        }
        try {
            self::require_engine();
            self::tick($job);
        } catch (Throwable $e) {
            $job['status'] = 'paused';
            $job['last_error'] = substr($e->getMessage(), 0, 450);
            $job['failed'] = (int) ($job['failed'] ?? 0) + 1;
        } finally {
            delete_option(self::LOCK_OPTION);
        }
        // A pause/stop received during a slow HTTP request must not be overwritten.
        $latest = get_option(self::JOB_OPTION, []);
        if (!is_array($latest) || ($latest['id'] ?? '') !== $id ||
            !in_array($latest['status'] ?? '', ['running', 'waiting'], true)) {
            return;
        }
        $job['updated'] = time();
        update_option(self::JOB_OPTION, $job, false);
        if (in_array($job['status'], ['finished', 'finished_with_issues'], true)) {
            self::purge_cache();
        } elseif ($job['status'] === 'running') {
            self::schedule($job);
            self::dispatch($job);
        } elseif ($job['status'] === 'waiting') {
            self::schedule($job);
        }
    }

    private static function tick(&$job) {
        $lang = $job['languages'][$job['lang_index']] ?? null;
        if ($lang === null) {
            if ($job['phase'] === 'discover') {
                $job['phase'] = 'dictionary';
                $job['lang_index'] = 0;
                $job['db_cursor'] = 0;
            } elseif ($job['phase'] === 'dictionary') {
                $job['phase'] = 'warm';
                $job['lang_index'] = 0;
                $job['url_index'] = 0;
            } else {
                $job['phase'] = 'finished';
                $job['status'] = !empty($job['review_items']) ? 'finished_with_issues' : 'finished';
            }
            return;
        }
        if (in_array($job['phase'], ['discover', 'warm'], true)) {
            if ($job['url_index'] >= count($job['urls'])) {
                $job['lang_index']++;
                $job['url_index'] = 0;
                return;
            }
            self::crawl_one($job, $lang);
            return;
        }
        if ($job['phase'] === 'dictionary') {
            self::translate_dictionary_tick($job, $lang);
            return;
        }
        throw new RuntimeException('Unknown job phase.');
    }

    private static function crawl_one(&$job, $lang) {
        $trp = self::require_engine();
        $converter = $trp->get_component('url_converter');
        if (!$converter || !method_exists($converter, 'get_url_for_language')) {
            throw new RuntimeException('TranslatePress URL converter is unavailable.');
        }
        $original_url = $job['urls'][$job['url_index']];
        $translated_url = $converter->get_url_for_language($lang, $original_url, '');
        $params = ['jff_trp_refresh' => wp_generate_password(12, false, false)];
        if ($job['phase'] === 'discover') {
            $params['jff_trp_discover'] = $job['id'];
            $params['jff_trp_signature'] = self::signature($job['id'], 'discover');
        }
        $url = add_query_arg($params, $translated_url);
        // Do not send loopbacks to an external domain (SSRF guard).
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host !== strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST))) {
            throw new RuntimeException('A localized URL points outside the website: ' . $lang);
        }
        $response = wp_safe_remote_get($url, [
            'timeout' => 80, 'redirection' => 3, 'sslverify' => true, 'reject_unsafe_urls' => true,
            'headers' => ['Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'],
            'user-agent' => 'Mozilla/5.0 JFF-TranslatePress-Internal-Worker',
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException('Could not fetch ' . $lang . ' page: ' . $response->get_error_code());
        }
        $http = (int) wp_remote_retrieve_response_code($response);
        if ($http < 200 || $http >= 300) {
            throw new RuntimeException('Localized page returned HTTP ' . $http . ' in ' . $lang . ' (' . $job['phase'] . ').');
        }
        if (strtolower((string) wp_remote_retrieve_header($response, 'x-litespeed-cache')) === 'hit') {
            throw new RuntimeException('LiteSpeed returned a cached page; discovery cannot be verified. Configure URL cache bypass.');
        }
        if ($job['phase'] === 'discover' &&
            (string) wp_remote_retrieve_header($response, 'x-jff-trp-discovery') !== '1') {
            throw new RuntimeException('Discovery page did not activate string capture; check page cache and server headers.');
        }
        $job['url_index']++;
        $job['urls_done']++;
    }

    private static function is_translatable($original) {
        if (!is_string($original) || $original === '' || strlen($original) > 6000) {
            return false;
        }
        $visible = trim(html_entity_decode(strip_tags($original), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($visible === '' || filter_var($visible, FILTER_VALIDATE_URL) !== false ||
            preg_match('~^(?:https?://|www\.)~i', $visible) ||
            preg_match('~^[A-Za-z][A-Za-z0-9+.-]{1,24}:\S+$~D', $visible)) {
            return false;
        }
        return preg_match('/\p{L}{2}/u', $visible) === 1;
    }

    private static function translate_dictionary_tick(&$job, $lang) {
        global $wpdb;
        $trp = self::require_engine();
        $query = $trp->get_component('query');
        $render = $trp->get_component('translation_render');
        $engine = $trp->get_component('machine_translator');
        list($table) = self::table_names($lang);
        $seen = self::seen_table_name();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id, d.original FROM `{$table}` AS d
             INNER JOIN `{$seen}` AS q
               ON q.job_id = %s AND q.language = %s
               AND q.original_hash = MD5(d.original)
             WHERE d.status = 0 AND d.id > %d
             ORDER BY d.id ASC LIMIT 160",
            $job['id'], $lang, (int) $job['db_cursor']
        ), ARRAY_A);
        if (!is_array($rows)) {
            throw new RuntimeException('Unable to fetch pending dictionary strings.');
        }
        if (!$rows) {
            $job['lang_index']++;
            $job['db_cursor'] = 0;
            return;
        }
        $selected = [];
        $last_scanned = (int) $job['db_cursor'];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $original = (string) $row['original'];
            if (!self::is_translatable($original)) {
                $job['strings_skipped']++;
                $last_scanned = $id;
                continue;
            }
            $existing = $query->get_existing_translations([$original], $lang);
            if ($existing === false) {
                throw new RuntimeException('TranslatePress could not read the translation dictionary.');
            }
            if (isset($existing[$original]->translated)) {
                $job['strings_skipped']++;
                $last_scanned = $id;
                continue;
            }
            $retry_key = $lang . ':' . $id;
            if (!empty($job['retry_counts'][$retry_key])) {
                // Retry one rejected item in a new PHP worker, never after two chunks.
                if ($selected) {
                    break;
                }
                $selected[] = ['id' => $id, 'original' => $original];
                break;
            }
            $selected[] = ['id' => $id, 'original' => $original];
            if (count($selected) >= self::STRINGS_PER_TICK) {
                break;
            }
        }
        if (!$selected) {
            $last_row = end($rows);
            $job['db_cursor'] = (int) $last_row['id'];
            $job['db_scanned'] += count($rows);
            return;
        }
        // Never advance beyond a pending selected row before its translation is saved.
        // Otherwise a failed HTTP request could permanently skip an earlier row if
        // later non-translatable rows were encountered during selection.
        $job['db_cursor'] = min($last_scanned, (int) $selected[0]['id'] - 1);
        $settings = get_option('trp_settings', []);
        $source = $settings['default-language'] ?? '';
        if (!$engine->is_available([$source, $lang]) ||
            !$engine->verify_request_parameters($lang, $source)) {
            throw new RuntimeException('TranslatePress translation validation failed for ' . $lang . '.');
        }
        $http = [];
        $watcher = function ($response, $context, $class, $args, $url) use (&$http) {
            if ($context !== 'response' || $url !== JFF_TRP_OpenAI_Engine::ENDPOINT) {
                return;
            }
            $http[] = is_wp_error($response) ? 'WP_ERROR:' . $response->get_error_code() : (int) wp_remote_retrieve_response_code($response);
        };
        add_action('http_api_debug', $watcher, 10, 5);
        try {
            foreach (array_chunk($selected, self::BATCH_SIZE) as $chunk) {
                $characters = array_sum(array_map(function ($row) { return strlen($row['original']); }, $chunk));
                $mt = get_option('trp_machine_translation_settings', []);
                $quota = (int) ($mt['machine_translation_limit'] ?? 1000000);
                $used = (int) get_option('trp_machine_translation_counter', 0);
                if ($quota > 0 && $used + $characters > $quota) {
                    // Continue automatically when TranslatePress resets its daily counter.
                    $tomorrow = (new DateTimeImmutable('tomorrow', wp_timezone()))->getTimestamp();
                    $job['status'] = 'waiting';
                    $job['next_attempt'] = $tomorrow + 60;
                    $job['last_error'] = 'Daily TranslatePress character quota reached; will resume tomorrow.';
                    break;
                }
                $before = count($http);
                $result = $render->process_strings(array_column($chunk, 'original'), $lang);
                $new_http = array_slice($http, $before);
                foreach ($new_http as $code) {
                    if ($code !== 200) {
                        throw new RuntimeException('Translation request failed (' . (is_int($code) ? 'HTTP ' . $code : $code) . ').');
                    }
                }
                $first_missing = null;
                foreach ($chunk as $row) {
                    $stored = $wpdb->get_row($wpdb->prepare(
                        "SELECT status, translated FROM `{$table}` WHERE id = %d", $row['id']
                    ), ARRAY_A);
                    if (is_array($stored) && (int) ($stored['status'] ?? 0) === 1 &&
                        is_string($stored['translated'] ?? null) && $stored['translated'] !== '') {
                        $job['strings_done']++;
                        if ($stored['translated'] === $row['original']) {
                            $job['strings_unchanged']++;
                        }
                        unset($job['retry_counts'][$lang . ':' . $row['id']]);
                        // Keep cursor behind an unresolved row, even if later rows saved.
                        if ($first_missing === null) {
                            $job['db_cursor'] = $row['id'];
                        }
                    } elseif ($first_missing === null) {
                        $first_missing = ['row' => $row, 'stored' => $stored];
                    }
                }
                if ($first_missing !== null) {
                    $item = $first_missing['row'];
                    if ($wpdb->last_error !== '') {
                        throw new RuntimeException('Dictionary database error in ' . $lang .
                            ' at ID ' . $item['id'] . '. Inspect TranslatePress SQL error logs.');
                    }
                    // Silent skips are per-item problems; the job must continue.
                    self::record_unstored_item($job, $lang, $item, $new_http, $result,
                        $first_missing['stored']);
                    // Stop worker; retry in a fresh request, not a third API batch.
                    break;
                }
                // If rows are already stored, no additional HTTP request is required.
            }
        } finally {
            remove_action('http_api_debug', $watcher, 10);
        }
        $job['db_scanned'] += count($selected);
    }

    /**
     * Retry an unsaved item in a new isolated worker, then retain it for review.
     * Never write source text back as a successful translation.
     */
    private static function record_unstored_item(&$job, $lang, $row, $http, $result, $stored) {
        $id = (int) $row['id'];
        $key = $lang . ':' . $id;
        $attempt = 1 + (int) ($job['retry_counts'][$key] ?? 0);
        $job['retry_counts'][$key] = $attempt;
        $silent_skip = !$http && !$result;
        $code = $silent_skip ? 'translatepress_no_api_call' : 'unknown';
        $state = $silent_skip ? 'skipped' : 'unknown';
        if (!$silent_skip) {
            $last = get_option('jff_openai_translation_activity', []);
            if (is_array($last) && !empty($last['time']) && time() - (int) $last['time'] <= 120) {
                $code = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($last['code'] ?? '')) ?: 'none';
                $state = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($last['status'] ?? '')) ?: 'unknown';
            }
        }
        $http_label = $http ? implode(',', array_map('strval', $http)) : 'none';
        $stored_status = is_array($stored) ? (int) ($stored['status'] ?? -1) : -1;
        $summary = $lang . ' ID ' . $id . ' not stored (HTTP ' . $http_label .
            ', engine ' . $state . '/' . $code . ', result_count ' . count((array) $result) .
            ', row_status ' . $stored_status . ', bytes ' . strlen($row['original']) . ').';
        if ($attempt < self::MAX_STRING_ATTEMPTS) {
            $job['status'] = 'waiting';
            $job['next_attempt'] = time() + 45;
            $job['last_error'] = $summary . ' Retrying once as a single string in a fresh request.';
            return;
        }
        // The row stays untranslated in the database; only this job skips its ID.
        unset($job['retry_counts'][$key]);
        $job['failed'] = (int) ($job['failed'] ?? 0) + 1;
        $job['strings_skipped'] = (int) ($job['strings_skipped'] ?? 0) + 1;
        $job['db_cursor'] = $id;
        $issue = [
            'language' => $lang, 'id' => $id, 'reason' => $code,
            'http' => $http_label, 'engine_status' => $state,
            'attempts' => $attempt, 'time' => time(),
        ];
        if (!isset($job['review_items']) || !is_array($job['review_items'])) {
            $job['review_items'] = [];
        }
        $job['review_items'][] = $issue;
        $job['review_items'] = array_slice($job['review_items'], -self::MAX_REPORTED_ISSUES);
        $job['last_error'] = $summary . ' Left pending for review; continuing with the next string.';
    }

    private static function purge_cache() {
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
        }
    }
}
