<?php
/** Insert proxy settings ONLY into the fresh scratch-volume wp-config.php. */
$config = '/var/www/html/wp-config.php';
$text = file_get_contents($config);
if (!is_string($text) || !preg_match("/DB_NAME['\"]\\s*,\\s*['\"]chilli_wp_qa_[a-z0-9_]+['\"]/", $text)) {
    throw new RuntimeException('Expected a disposable chilli_wp_qa_ database config.');
}
$fragment = <<<'CONFIG'

/* Chilli disposable QA proxy adapter; never install on customer websites. */
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
if (isset($_SERVER['HTTP_X_ORIGINAL_URI']) && strpos($_SERVER['HTTP_X_ORIGINAL_URI'], '/__wordpress_qa/') === 0) {
    $_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_ORIGINAL_URI'];
}

CONFIG;
$text = preg_replace('/^<\?php/', '<?php' . $fragment, $text, 1, $count);
if ($count !== 1) {
    throw new RuntimeException('Could not identify WordPress config opening tag.');
}
file_put_contents($config, $text);
