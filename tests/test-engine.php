<?php
// Run: php tests/test-engine.php (no WordPress installation required).
define('ABSPATH', __DIR__);
$GLOBALS['mock_http'] = [];
$GLOBALS['mock_last_request'] = null;
$GLOBALS['mock_options'] = [];
$GLOBALS['mock_hooks'] = [];

class WP_Error {
    private $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class Mock_Translator_Logger {
    public $calls = 0;
    public function count_towards_quota($strings) { $this->calls++; }
    public function quota_exceeded() { return false; }
}
class TRP_Translate_Press {}
class TRP_Machine_Translator {
    protected $settings;
    protected $machine_translation_codes;
    protected $machine_translator_logger;
    public function __construct($settings) {
        $this->settings = $settings;
        $this->machine_translation_codes = ['en_US' => 'en', 'es_ES' => 'es', 'ca' => 'ca'];
        $this->machine_translator_logger = new Mock_Translator_Logger();
    }
    public function verify_request_parameters($to, $from) { return true; }
    public function logger_calls() { return $this->machine_translator_logger->calls; }
}
function add_action($hook, $callback, $priority = 10) { $GLOBALS['mock_hooks'][$hook][] = $callback; }
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['mock_hooks'][$hook][] = ['callback' => $callback, 'accepted_args' => $accepted_args]; }
function esc_html__($value) { return $value; }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_textarea($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function selected($a, $b) { if ($a === $b) { echo 'selected="selected"'; } }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_textarea_field($value) { return trim((string) $value); }
function get_option($key, $fallback = null) { return $GLOBALS['mock_options'][$key] ?? $fallback; }
function is_wp_error($x) { return $x instanceof WP_Error; }
function wp_json_encode($value, $options = 0) { return json_encode($value, $options); }
function wp_remote_post($url, $args) {
    $GLOBALS['mock_last_request'] = ['url' => $url, 'args' => $args];
    return array_shift($GLOBALS['mock_http']);
}
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function assert_true($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
function mock_response($translations, $status = 'completed') {
    return ['response' => ['code' => 200], 'body' => json_encode([
        'status' => $status,
        'output' => [[ 'type' => 'message', 'content' => [[
            'type' => 'output_text', 'text' => json_encode(['translations' => $translations]),
        ]]]],
    ])];
}

require dirname(__DIR__) . '/jff-openai-for-translatepress.php';
require dirname(__DIR__) . '/includes/class-jff-openai-engine.php';

$GLOBALS['mock_options']['trp_machine_translation_settings'] = [
    'jff_openai_key' => 'sk-existing-secret',
    'jff_openai_model' => 'gpt-prior',
    'jff_openai_batch' => 3,
];
// Simulate TranslatePress' actual whitelist. Custom form fields only survive when
// the filter reads the SECOND argument passed to trp_machine_translation_sanitize_settings.
$raw = [
    'machine-translation' => 'yes',
    'translation-engine' => 'jff_openai_responses',
    'jff_openai_key' => 'sk-new-credential',
    'jff_openai_model' => 'gpt-6-luna',
    'jff_openai_reasoning' => 'none',
    'jff_openai_batch' => 50,
    'jff_openai_tokens' => -20,
    'jff_openai_context' => 'Technical equipment translation',
];
$whitelisted = ['machine-translation' => 'yes', 'translation-engine' => 'jff_openai_responses'];
$opts = JFF_TranslatePress_OpenAI::sanitize_settings($whitelisted, $raw);
assert_true($opts['jff_openai_key'] === 'sk-new-credential', 'TranslatePress raw submitted API key survives its whitelist');
assert_true($opts['jff_openai_model'] === 'gpt-6-luna' && $opts['jff_openai_context'] === 'Technical equipment translation', 'Custom model and terminology saved from raw form fields');
assert_true($opts['jff_openai_batch'] === 20 && $opts['jff_openai_tokens'] === 256, 'Numeric bounds enforced');
assert_true($opts['jff_openai_reasoning'] === 'none', 'Raw reasoning setting saved');
$opts = JFF_TranslatePress_OpenAI::sanitize_settings($whitelisted, ['jff_openai_key' => '', 'jff_openai_model' => 'bad/model']);
assert_true($opts['jff_openai_key'] === 'sk-existing-secret', 'Blank key does not erase existing key');
assert_true($opts['jff_openai_model'] === 'gpt-6-luna', 'Invalid model ID rejected');
$opts = JFF_TranslatePress_OpenAI::sanitize_settings($whitelisted, ['jff_openai_clear_key' => '1']);
assert_true($opts['jff_openai_key'] === '', 'Explicit clear removes stored key');
$opts = JFF_TranslatePress_OpenAI::sanitize_settings($whitelisted, []);
assert_true($opts['jff_openai_model'] === 'gpt-prior' && $opts['jff_openai_batch'] === 3, 'Absent custom form fields do not erase prior preferences');

$config = ['default-language' => 'en_US', 'trp_machine_translation_settings' => [
    'jff_openai_key' => 'sk-example-secret',
    'jff_openai_model' => 'gpt-6-luna',
    'jff_openai_reasoning' => 'auto',
]];
$engine = new JFF_TRP_OpenAI_Engine($config);
$GLOBALS['mock_http'][] = mock_response(['Hola <strong>mundo</strong>!', 'Hola {name}!']);
$translated = $engine->translate_array([
    'node-7' => 'Hello <strong>world</strong>!',
    'node-18' => 'Hello {name}!',
], 'es_ES', 'en_US');
assert_true($translated === ['node-7' => 'Hola <strong>mundo</strong>!', 'node-18' => 'Hola {name}!'], 'Translations preserve original associative keys and markup');
assert_true($engine->logger_calls() === 1, 'Quota only counts validated translation batches');
$last = $GLOBALS['mock_last_request'];
$payload = json_decode($last['args']['body'], true);
assert_true($last['url'] === 'https://api.openai.com/v1/responses', 'Uses official Responses API endpoint');
assert_true($payload['model'] === 'gpt-6-luna' && isset($payload['max_output_tokens']), 'Selected model and current token parameter used');
assert_true(!isset($payload['temperature']) && !isset($payload['max_tokens']) && !isset($payload['top_p']), 'Never sends unsupported sampling or legacy token parameters');
assert_true(!isset($payload['reasoning']), 'Automatic reasoning mode omits the API field');
assert_true($payload['store'] === false, 'API response storage explicitly disabled');
assert_true($last['args']['redirection'] === 0 && $last['args']['sslverify'] === true, 'HTTPS request does not redirect credentials');

assert_true(!JFF_TRP_OpenAI_Engine::preserves_protected_content('Hello', 'Hola <script>alert(1)</script>'), 'Injected HTML blocked');
assert_true(!JFF_TRP_OpenAI_Engine::preserves_protected_content('<b class="a">Hi</b>', '<b class="b">Hola</b>'), 'Modified HTML attributes blocked');
assert_true(!JFF_TRP_OpenAI_Engine::preserves_protected_content('Hello {name}', 'Hola {nombre}'), 'Modified placeholders blocked');
assert_true(JFF_TRP_OpenAI_Engine::preserves_protected_content('Use %1$s &amp; 1TP2', 'Usar %1$s &amp; 1TP2'), 'Protected tokens remain intact');

$before = $engine->logger_calls();
$GLOBALS['mock_http'][] = mock_response(['Hola <strong>mundo</em>!']);
$out = $engine->translate_array(['node-x' => 'Hello <strong>world</strong>!'], 'es_ES', 'en_US');
assert_true($out === [] && $engine->logger_calls() === $before, 'Malformed HTML is not stored or counted');

$incomplete = $engine->parse_response(mock_response(['Hola'], 'incomplete'), ['Hello']);
assert_true(is_wp_error($incomplete) && $incomplete->get_error_code() === 'jff_openai_incomplete_response', 'Incomplete responses are rejected');
$bad_status = $engine->parse_response(['response' => ['code' => 400], 'body' => json_encode(['error' => ['type' => 'invalid_request_error', 'param' => 'temperature']])], ['Hello']);
assert_true(is_wp_error($bad_status) && strpos($bad_status->get_error_message(), 'temperature') !== false, 'API errors retain safe diagnostic codes');

$config['trp_machine_translation_settings']['jff_openai_reasoning'] = 'none';
$config['trp_machine_translation_settings']['jff_openai_model'] = 'gpt-7-future';
$future_engine = new JFF_TRP_OpenAI_Engine($config);
$GLOBALS['mock_http'][] = mock_response(['Hola']);
$future_engine->translate_array(['Hello'], 'es_ES', 'en_US');
$future_payload = json_decode($GLOBALS['mock_last_request']['args']['body'], true);
assert_true($future_payload['model'] === 'gpt-7-future', 'Future custom model IDs work without code changes');
assert_true($future_payload['reasoning']['effort'] === 'none', 'Explicit reasoning effort is serialized correctly');

JFF_TranslatePress_OpenAI::boot();
assert_true(isset($GLOBALS['mock_hooks']['trp_machine_translation_engines']), 'Registers TranslatePress engine hook');
$engines = JFF_TranslatePress_OpenAI::register_engine([]);
assert_true(count($engines) === 1 && $engines[0]['value'] === JFF_TranslatePress_OpenAI::ENGINE, 'Registers only one translation engine');
assert_true($GLOBALS['mock_hooks']['trp_machine_translation_sanitize_settings'][0]['accepted_args'] === 2, 'Settings hook registers both callback arguments');
$classes = JFF_TranslatePress_OpenAI::register_engine_class([]);
assert_true($classes[JFF_TranslatePress_OpenAI::ENGINE] === 'JFF_TRP_OpenAI_Engine', 'Engine class mapped into TranslatePress');
ob_start();
JFF_TranslatePress_OpenAI::render_settings(['jff_openai_key' => 'sk-private-should-not-appear', 'jff_openai_model' => 'gpt-6-luna']);
$settings_html = ob_get_clean();
assert_true(strpos($settings_html, 'sk-private-should-not-appear') === false, 'Admin settings never print saved API keys');
assert_true(strpos($settings_html, 'gpt-6-luna') !== false, 'Model ID displayed in admin settings');

echo "All tests passed.\n";
