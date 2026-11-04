<?php
// php tests/test-manager.php (isolated WordPress mock; no production changes).
define('ABSPATH', __DIR__);
$GLOBALS['settings'] = [
    'trp_settings' => [
        'default-language' => 'en_GB',
        'translation-languages' => ['en_GB', 'es_ES', 'ca', 'de_DE', 'fr_FR']
    ]
];
$GLOBALS['mock_hooks'] = [];
function get_option($key, $fallback = []) { return $GLOBALS['settings'][$key] ?? $fallback; }
function add_action($name, $callback, $priority = 10, $args = 1) { $GLOBALS['mock_hooks'][$name][] = $callback; }
function add_filter($name, $callback, $priority = 10, $args = 1) { $GLOBALS['mock_hooks'][$name][] = $callback; }
function sanitize_text_field($value) { return $value; }
function wp_unslash($value) { return $value; }
function wp_salt($purpose) { return 'testing-secret'; }
function home_url($path = '/') { return 'https://example.test' . $path; }
function wp_parse_url($url, $component) { return parse_url($url, $component); }
function esc_url_raw($url) { return trim($url); }
function get_post_types($args, $format = 'names') { return ['page' => 'page']; }
function get_posts($args) { return [17, 18]; }
function post_password_required($id) { return (int) $id === 18; }
function get_permalink($id) { return 'https://example.test/about/'; }
function get_post_type_archive_link($type) { return false; }
function get_taxonomies($args, $format = 'names') { return []; }
function get_terms($args) { return []; }
function is_wp_error($response) { return false; }
class TRP_Translate_Press {
    public static function get_trp_instance() { return new self(); }
    public function get_component($part) { return null; }
}
require dirname(__DIR__) . '/includes/class-jff-openai-manager.php';
function assert_test($condition, $description) {
    if (!$condition) { fwrite(STDERR, "FAIL: $description\n"); exit(1); }
    echo "PASS: $description\n";
}
function invoke_private($name, ...$args) {
    $method = new ReflectionMethod(JFF_TRP_Manager::class, $name);
    $method->setAccessible(true);
    return $method->invokeArgs(null, $args);
}
assert_test(invoke_private('languages') === ['es_ES', 'ca', 'de_DE', 'fr_FR'],
    'Reset actions exclude the default language');
assert_test(JFF_TRP_Manager::STRINGS_PER_TICK === 8 &&
    JFF_TRP_Manager::BATCH_SIZE === 4, 'Bulk translation uses bounded independent jobs');
$urls = invoke_private('collect_urls', "https://example.test/contact/\nhttps://evil.test/private");
assert_test($urls === [
    'https://example.test/',
    'https://example.test/about/',
    'https://example.test/contact/'
], 'Discovery excludes external and password-protected URLs');
assert_test(invoke_private('is_translatable', 'A machine can handle this task.'),
    'Natural language may be translated');
assert_test(!invoke_private('is_translatable', 'https://example.test/test'),
    'Pure URLs are excluded');
foreach (['tel:+34936921362', 'mailto:info@example.com', 'sms:+34936921362', 'geo:41.1,2.1', 'custom+app:record123'] as $technical) {
    assert_test(!invoke_private('is_translatable', $technical), 'Technical reference excluded: ' . $technical);
}
assert_test(invoke_private('is_translatable', 'Note: this is a sentence'), 'Natural-language text with colon retained');
assert_test(!invoke_private('is_translatable', '<div></div>'),
    'Markup-only strings are excluded');
$visible = invoke_private('public_job', [
    'id' => 'private-id', 'urls' => ['https://example.test/private'],
    'url_count' => 1, 'languages' => ['ca'], 'passes' => 2
]);
assert_test(!isset($visible['id'], $visible['urls']) && $visible['url_total'] === 2,
    'Public status does not expose job ID or URL queue');

$job = [
    'db_cursor' => 148, 'failed' => 0, 'strings_skipped' => 0,
    'retry_counts' => [], 'review_items' => [], 'status' => 'running',
    'last_error' => ''
];
$GLOBALS['settings']['jff_openai_translation_activity'] = [
    'status' => 'error', 'code' => 'jff_openai_unsafe_translation', 'time' => time()
];
$retry_method = new ReflectionMethod(JFF_TRP_Manager::class, 'record_unstored_item');
$retry_method->setAccessible(true);
$retry_method->invokeArgs(null, [&$job, 'es_ES',
    ['id' => 149, 'original' => 'A problematic markup example'],
    [200], [], ['status' => 0, 'translated' => '']]);
assert_test($job['status'] === 'waiting' &&
    $job['retry_counts']['es_ES:149'] === 1 &&
    $job['db_cursor'] === 148, 'First failed string schedules an isolated retry');
$job['status'] = 'running';
$retry_method->invokeArgs(null, [&$job, 'es_ES',
    ['id' => 149, 'original' => 'A problematic markup example'],
    [200], [], ['status' => 0, 'translated' => '']]);
assert_test($job['status'] === 'running' &&
    $job['db_cursor'] === 149 &&
    $job['failed'] === 1 &&
    $job['review_items'][0]['reason'] === 'jff_openai_unsafe_translation',
    'Repeated failure stays pending but does not block the remaining job');
assert_test(!isset(invoke_private('public_job', $job)['retry_counts']),
    'Job status does not disclose internal per-item retries');

$no_http = ['db_cursor'=>148,'status'=>'running','retry_counts'=>[],'review_items'=>[],'failed'=>0,'strings_skipped'=>0,'last_error'=>''];
$GLOBALS['settings']['jff_openai_translation_activity'] = ['status'=>'success','code'=>'','time'=>time()];
$retry_method->invokeArgs(null, [&$no_http,'es_ES',['id'=>149,'original'=>'Unusual data'],[],[],['status'=>0,'translated'=>'']]);
assert_test($no_http['status']==='waiting' && $no_http['db_cursor']===148,'Silent skip gets bounded retry');
$no_http['status']='running';
$retry_method->invokeArgs(null, [&$no_http,'es_ES',['id'=>149,'original'=>'Unusual data'],[],[],['status'=>0,'translated'=>'']]);
assert_test($no_http['db_cursor']===149 && $no_http['status']==='running' && $no_http['review_items'][0]['reason']==='translatepress_no_api_call','Repeated silent skip continues and reports accurate reason');
echo "All manager smoke tests passed.\n";