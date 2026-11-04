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
function is_wp_error($response) { return is_array($response) && !empty($response['wp_error']); }
function wp_remote_retrieve_response_code($response) { return $response['status'] ?? 0; }
function wp_remote_retrieve_header($response, $name) { return $response['headers'][$name] ?? ''; }
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
assert_test(invoke_private('is_scoped_job', ['scope_mode'=>'rendered_urls_v1']), 'Current jobs use discovered-page scope');
assert_test(!invoke_private('is_scoped_job', ['phase'=>'dictionary']), 'Previous unscoped jobs must not resume');
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
class MockSeenDatabase {
    public $prefix = 'wp_';
    public $insert = '';
    public function prepare($query, $params) { return $query . ' /* ' . count($params) . ' params */'; }
    public function query($query) { $this->insert = $query; return 1; }
}
$wpdb = new MockSeenDatabase();
$GLOBALS['settings']['jff_trp_bulk_job'] = [
  'id'=>'job-uuid', 'scope_mode'=>'rendered_urls_v1',
  'status'=>'running', 'phase'=>'discover', 'languages'=>['es_ES']
];
$original_dictionary = ['Some example'=> (object) ['translated'=>'Example']];
$received = JFF_TRP_Manager::capture_rendered_strings($original_dictionary, '', ['Some example'], 'es_ES');
assert_test($received === $original_dictionary, 'String capture never modifies TranslatePress results');
assert_test(strpos($wpdb->insert, 'INSERT IGNORE') !== false && strpos($wpdb->insert, 'jff_trp_bulk_seen') !== false, 'Observed strings enter scoped hash queue');
$GLOBALS['settings']['jff_trp_bulk_job']['phase'] = 'warm';
$wpdb->insert = '';
JFF_TRP_Manager::capture_rendered_strings($original_dictionary, '', ['Some example'], 'es_ES');
assert_test($wpdb->insert === '', 'Warm passes do not add unobserved strings to discovery queue');
$response = ['status'=>200, 'headers'=>['content-type'=>'text/html', 'x-jff-trp-discovery'=>'1']];
assert_test(invoke_private('crawl_response_issue', $response, 'discover') === null,
    'Verified rendered page may contain no translatable strings');
$response['headers']['x-jff-trp-discovery'] = '';
assert_test(invoke_private('crawl_response_issue', $response, 'discover') === 'capture_not_confirmed',
    'Missing capture header is a recoverable page issue');
$response['headers']['x-litespeed-cache'] = 'hit';
assert_test(invoke_private('crawl_response_issue', $response, 'discover') === 'cached_page',
    'Cached page is not silently treated as discovered');
assert_test(invoke_private('crawl_response_issue', $response, 'warm') === null,
    'Cached page is normal during warming of translated content');
assert_test(invoke_private('crawl_response_issue', ['status'=>404, 'headers'=>[]], 'discover') === 'http_404',
    'One unavailable URL may be skipped without failing whole job');
assert_test(invoke_private('crawl_response_issue', ['status'=>200, 'headers'=>['content-type'=>'application/pdf']], 'discover') === 'non_html_response',
    'Non-HTML resources do not contribute discovery strings');
try {
    invoke_private('crawl_response_issue', ['status'=>503, 'headers'=>[]], 'discover');
    assert_test(false, 'Server errors must halt');
} catch (RuntimeException $e) {
    assert_test(strpos($e->getMessage(), 'HTTP 503') !== false, 'Systemic HTTP 503 pauses job');
}
$bad_page_job = ['url_index'=>1, 'phase'=>'discover', 'urls_skipped'=>0, 'page_issues'=>[], 'last_error'=>''];
$page_issue_method = new ReflectionMethod(JFF_TRP_Manager::class, 'record_page_issue');
$page_issue_method->setAccessible(true);
$page_issue_method->invokeArgs(null, [&$bad_page_job, 'es_ES',
    'https://example.test/catalog/?jff_trp_signature=secret', 'capture_not_confirmed']);
assert_test($bad_page_job['urls_skipped'] === 1 &&
    $bad_page_job['page_issues'][0]['path'] === '/catalog/' &&
    strpos(json_encode($bad_page_job), 'secret') === false,
    'Skipped page is recorded without private URL query parameters');
echo "All manager smoke tests passed.\n";