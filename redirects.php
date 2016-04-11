<?php

function redirects_show_usage() {
  print "Usage: \n";
  print "cat site_redirects.txt | redirects [--test <base_url>]";
  print "\n";
}

function redirects_generators() {
  return array(
    'apache' => array(
      'generator_function' => 'redirects_generate_apache',
    ),
  );
}

function redirects_generate_apache($redirects, $options, &$result) {
  $indent = isset($options['indent']) ? $options['indent'] : "\t";
  $count = count($redirects);

  $result['output'][] = '<IfModule mod_rewrite.c>';
  $result['output'][] = 'RewriteEngine On';

  foreach ($redirects as $index => $redirect) {
    if (isset($redirect['src_parsed']['scheme']) && $redirect['src_parsed']['scheme'] == 'https') {
      $result['output'][] = $indent . 'RewriteCond %%{HTTPS} =on';
    }

    if (isset($redirect['src_parsed']['host'])) {
      $result['output'][] = $indent . sprintf('RewriteCond %%{{HTTP_HOST}} =%s', $redirect['src_parsed']['host']);
    }

    $result['output'][] = $indent . sprintf('RewriteCond %%{{REQUEST_URI}} =%s', $redirect['src_parsed']['path']);
    $result['output'][] = $indent . sprintf('RewriteRule .* %s [R=%s,L]', $redirect['dest'], $redirect['options']['code']);

    if ($index < $count - 1) {
      $result['output'][] = '';
    }
  }

  $result['output'][] = '</IfModule>';
}

function redirects_default_generator() {
  return key(redirects_generators());
}

function redirects_generator_call($generator, $redirects, $options, &$result) {
  $generators = redirects_generators();

  $fn = $generators[$generator]['generator_function'];

  $fn($redirects, $options, $result);
}

function redirects_generate($redirects, $options = array()) {
  redirects_preprocess($redirects);

  $options += array('generator' => redirects_default_generator());

  $result = array();
  $result['output'] = array();

  redirects_generator_call($options['generator'], $redirects, $options, $result);

  print join("\n", $result['output']) . "\n";
}

function redirects_preprocess(&$redirects) {
  foreach ($redirects as $index => &$redirect) {
    if (!isset($redirect['src_parsed'])) {
      $redirect['src_parsed'] = parse_url($redirect['src']);
    }

    $redirect += array('options' => array());
    $redirect['options'] += array('code' => '301');
  }
}

function redirects_test($redirects, $options = array()) {
  redirects_preprocess($redirects);

  $result = array();
  $result['output'] = array();
  $result['errors_count'] = 0;
  $result['total'] = count($redirects);

  foreach ($redirects as $redirect) {
    $src = $redirect['src'];
    $dest = $redirect['dest'];

    if ($redirect['src'][0] == '/' && isset($options['base_url'])) {
      $src = $options['base_url'] . $redirect['src'];
    }

    if ($redirect['dest'][0] == '/') {
      if (isset($redirect['src_parsed']['host'])) {
        $dest = $redirect['src_parsed']['scheme'] . '://' . $redirect['src_parsed']['host'] . $redirect['dest'];
      }
      else if (isset($options['base_url'])) {
        $dest = $options['base_url'] . $redirect['dest']; 
      }
    }

    $result['output'][] = sprintf('Does %s go to %s?', $src, $dest);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HEADER, TRUE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $src);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 1);

    $data = curl_exec($ch);
    $curl_errno = curl_errno($ch);  

    switch ($curl_errno) {
      case 0:
        $last_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($last_url == $dest) {
          $result['output'][] = sprintf('Yes');
        }
        else if ($http_code != '200') {
          $result['output'][] = sprintf('No, %s returns %s', $src, $http_code);
          $result['errors_count'] += 1;
        }
        else {
          $result['output'][] = sprintf('No, %s goes to %s', $src, $last_url);
          $result['errors_count'] += 1;
        }

        break;

      default:
        $result['output'][] = sprintf('Error: %s', curl_error($ch));
        $result['errors_count'] += 1;
        break;
    }

    $result['output'][] = '';
  }

  $result['output'][] = sprintf('Errors: %d / %d', $result['errors_count'], $result['total']);

  print join("\n", $result['output']) . "\n";
}

function redirects_parse_input_line($line) {
  $line_parts = array_map('trim', preg_split('/\t/', $line));

  if (count($line_parts) != 2) {
    return FALSE;
  }

  $redirect = array(
    'src' => $line_parts[0],
    'dest' => $line_parts[1],
  );

  return $redirect;
}

// Call the routine only if we are in the *MAIN* script.
// Otherwise we are including "redirects" as a library.
if (count(debug_backtrace()) > 0) {
  return;
}

ini_set('display_errors', 1);

// Avoid annoying php warnings saying default tz was not set.
date_default_timezone_set('UTC');

$input = array(
  'redirects' => array(),
);

while (($line = trim(fgets(STDIN)))) {
  $redirect = redirects_parse_input_line($line);

  if ($redirect !== FALSE) {
    $input['redirects'][] = $redirect;
  }
}

$input['cmd'] = 'generate';

if (in_array('--test', $argv)) {
  $input['cmd'] = 'test';
  $input['test_options'] = array();

  if (isset($argv[2])) {
    $input['test_options']['base_url'] = $argv[2];
  }
}

if ($input['cmd'] == 'generate') {
  redirects_generate($input['redirects']);
}
else if ($input['cmd'] == 'test') {
  redirects_test($input['redirects'], $input['test_options']);
}
else {
  redirects_show_usage();
}
