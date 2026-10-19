<?php
/** WordPress API fixture: never sends network requests or installs a plugin. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('CONZENT_SERVER_URL', 'https://app.getconzent.com');
$GLOBALS['options'] = ['conzent_server_url' => 'http://127.0.0.1:8080'];
$GLOBALS['calls'] = [];
$GLOBALS['can_manage'] = true;
$GLOBALS['remote_get_mode'] = 'success';
class FixtureHTTPError {}
function add_action(...$args) {}
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'https://client.example/wp-content/plugins/chilli-consent/'; }
function plugin_basename($file) { return 'chilli-consent/' . basename($file); }
function get_plugin_data($file) { return ['Version' => '2.2.4.1']; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function add_option($key, $value, ...$args) { if (array_key_exists($key,$GLOBALS['options'])) return false; $GLOBALS['options'][$key]=$value; return true; }
function update_option($key, $value) { $GLOBALS['options'][$key]=$value; }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function wp_clear_scheduled_hook(...$args) {}
function wp_schedule_single_event(...$args) {}
function wp_rand($min,$max) { return 0; }
function home_url($path = '') { return 'https://client.example' . $path; }
function wp_parse_url($url, $component=-1) { return parse_url($url,$component); }
function add_query_arg($key,$value,$url) { return $url . '?' . rawurlencode($key) . '=' . rawurlencode($value); }
function wp_json_encode($data) { return json_encode($data); }
function __($text,...$args) { return $text; }
function esc_html__($text,...$args) { return htmlspecialchars((string)$text, ENT_QUOTES); }
function esc_html_e($text,...$args) { echo esc_html__($text); }
function esc_attr_e($text,...$args) { echo esc_html__($text); }
function esc_attr($text) { return esc_html__($text); }
function esc_html($text) { return esc_html__($text); }
function esc_url($text) { return $text; }
function esc_url_raw($text) { return $text; }
function sanitize_key($text) { return preg_replace('/[^a-z0-9_\-]/','',strtolower($text)); }
function sanitize_text_field($text) { return strip_tags(trim($text)); }
function wp_unslash($text) { return $text; }
function current_user_can($cap) { return $GLOBALS['can_manage']; }
function wp_die(...$args) { throw new RuntimeException('Permission denied'); }
function wp_verify_nonce($nonce,$action) { return $nonce === 'fixture-valid'; }
function check_admin_referer($action) { if (($_POST['_wpnonce'] ?? '') !== 'fixture-valid') throw new RuntimeException('Nonce denied'); }
function wp_nonce_field(...$args) { return '<input name="nonce" />'; }
function admin_url($path='') { return 'https://client.example/wp-admin/' . $path; }
function is_wp_error($response) { return $response instanceof FixtureHTTPError; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_retrieve_header($response,$header) { return $response['headers'][$header] ?? ''; }
function wp_safe_remote_post($url,$args) {
    $GLOBALS['calls'][]=['method'=>'POST','url'=>$url,'args'=>$args];
    return ['response'=>['code'=>404],'body'=>json_encode(['error'=>['code'=>'no_site','message'=>'Create site first']])];
}
function wp_safe_remote_get($url,$args) {
    $GLOBALS['calls'][]=['method'=>'GET','url'=>$url,'args'=>$args];
    if ($GLOBALS['remote_get_mode'] === 'unreachable') return new FixtureHTTPError();
    return ['response'=>['code'=>200],'body'=>json_encode(['domain'=>'client.example','website_key'=>'fixture-key'])];
}
function is_admin() { return false; }
function is_network_admin() { return false; }
function wp_doing_cron() { return false; }
function wp_doing_ajax() { return false; }
function did_action($name) { return false; }
function is_user_logged_in() { return false; }
function apply_filters($name,$value,...$args) { return $value; }
function human_time_diff($from,$to) { return '5 minutes'; }
require __DIR__ . '/../chilli-consent/chilli-consent.php';
function expect(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); echo "PASS: $message\n"; }
expect(getconzent_cmp_get_server_url() === 'https://consent.chillimedia.se', 'legacy constant and malicious option cannot override pinned server');
getconzent_cmp_activate();
expect(count($GLOBALS['calls']) === 0,'activation schedules setup without making a network request');
getconzent_cmp_claim_run(true);
expect($GLOBALS['calls'][0]['url'] === 'https://consent.chillimedia.se/api/v1/plugin/claim','automatic setup uses agency endpoint');
expect($GLOBALS['calls'][0]['args']['redirection'] === 0,'automatic setup does not follow redirects');
$body=json_decode($GLOBALS['calls'][0]['args']['body'],true);
expect($body['domain']==='client.example' && preg_match('/^[0-9a-f]{64}$/',$body['token'])===1,'claim sends public domain and random challenge token');
$GLOBALS['can_manage']=false;
foreach (['getconzent_cmp_render_settings_page','getconzent_cmp_render_overview_page','getconzent_cmp_claim_retry_action','getconzent_cmp_claim_dismiss_action'] as $handler) {
    $denied=false; try { $handler(); } catch (RuntimeException $e) { $denied=true; }
    expect($denied, "$handler rejects users without manage_options");
}
$GLOBALS['can_manage']=true;
$_POST=['_wpnonce'=>'invalid'];
foreach (['getconzent_cmp_claim_retry_action','getconzent_cmp_claim_dismiss_action'] as $handler) {
    $denied=false; try { $handler(); } catch (RuntimeException $e) { $denied=true; }
    expect($denied, "$handler rejects invalid CSRF nonce");
}
$GLOBALS['options']['conzent_claim_state']='manual';
$_POST=['gc_settings_nonce'=>'invalid','conzent_website_key'=>'wrong-key','conzent_server_url'=>'https://evil.example'];
$before=$GLOBALS['options']; ob_start(); getconzent_cmp_render_settings_page(); ob_end_clean();
expect($GLOBALS['options']===$before,'settings with an invalid nonce do not alter options');
$_POST=['gc_settings_nonce'=>'fixture-valid','conzent_website_key'=>'key & x','conzent_server_url'=>'https://evil.example'];
ob_start(); getconzent_cmp_render_settings_page(); $html=ob_get_clean();
$last=end($GLOBALS['calls']);
expect($last['url']==='https://consent.chillimedia.se/api/v1/verify?website_id=key%20%26%20x','manual key verification is encoded and pinned despite submitted URL');
expect($last['args']['redirection']===0 && $last['args']['limit_response_size']===65536,'verification disables redirects and bounds response size');
expect(get_option('chilli_consent_key_verified')==='yes' && getconzent_cmp_key_verification_status()==='Verified with Chilli Consent','valid API response marks key verified independently of loader flag');
expect(strpos($html,'Chilli Consent Settings')!==false && strpos($html,'https://consent.chillimedia.se')!==false,'settings retain agency branding and read-only service');
$GLOBALS['remote_get_mode']='unreachable';
$_POST=['gc_settings_nonce'=>'fixture-valid','conzent_website_key'=>'offline-key'];
ob_start(); getconzent_cmp_render_settings_page(); $offline_html=ob_get_clean();
expect(get_option('conzent_website_key')==='offline-key' && get_option('conzent_verified')==='yes','unreachable API preserves entered key and enables loader');
expect(get_option('chilli_consent_key_verified')==='pending' && getconzent_cmp_key_verification_status()==='Configured — verification pending','unreachable API never marks key verified');
expect(strpos($offline_html,'Configured — verification pending')!==false && strpos($offline_html,'Verification is pending or failed')!==false,'offline settings display pending status and useful warning');
getconzent_cmp_claim_apply(['outcome'=>'success','site'=>['website_key'=>'claimed-key','domain'=>'client.example']]);
expect(get_option('chilli_consent_key_verified')==='yes','successful automatic claim marks actual verification');
unset($GLOBALS['options']['chilli_consent_key_verified']);
getconzent_cmp_update_check();
expect(get_option('chilli_consent_key_verified')==='pending','legacy configured flag is not inherited as proof of verification');
$GLOBALS['options']['conzent_verified']='yes'; $GLOBALS['options']['conzent_website_key']='fixture-key';
ob_start(); getconzent_cmp_print_loader(); $loader=ob_get_clean();
expect(strpos($loader,'src="https://consent.chillimedia.se/c/consent.js"')!==false,'visitor loader uses agency endpoint');
expect(strpos($loader,'data-key="fixture-key"')!==false && strpos($loader,'data-plugin="wordpress/2.2.4.1"')!==false,'visitor loader carries site key and fork version');
expect(preg_match('/\s(?:async|defer)(?:\s|=|>)/',$loader)===0 && strpos($loader,'data-cfasync="false"')!==false,'loader remains parser-blocking and retains cache exclusion');
echo "Connector fixture checks passed.\n";
