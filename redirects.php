<?php

function redirects_show_usage($fatal_error = NULL) {
  if (isset($fatal_error)) {
    print "Fatal: $fatal_error";
    print "\n";
    print "\n";
  }

  print "Usage: \n";
  print "cat site_redirects.txt | redirects [--generator=<generator> --test --base_url=<base_url> --separator=<generator>]\n";
  print "  --test: Test redirects instead of generate them.\n";
  print "  --base_url: \n";
  print "  --generator: Set the generator to use to generate redirects. Choose from the list: " . join(', ', array_keys(redirects_generators())) . "\n";
  print "  --separator: Set the character used to separate source and destination inside the input lines. Default: \\t.\n";
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
    $result['output'][] = $indent . sprintf('RewriteRule .* %s [R=%s,L,QSA]', $redirect['dest'], $redirect['options']['code']);

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
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);

    $data = curl_exec($ch);
    $curl_errno = curl_errno($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $headers_string = substr($data, 0, $headers_size);
    $headers = redirects_parse_http_response_headers($headers_string);
    $data = substr($data, $headers_size);
    $is_redirect = (in_array($code, array(301, 302, 303, 307)));

    switch ($curl_errno) {
      case 0:
        if ($is_redirect) {
          $redirect_url = $headers['location'][0];

          if ($redirect_url == $dest) {
            $result['output'][] = sprintf('Yes');
          }
          else {
            $result['output'][] = sprintf('No, %s goes to %s', $src, $redirect_url);
            $result['errors_count'] += 1;
          }
        }
        else if ($code != '200') {
          $result['output'][] = sprintf('No, %s returns %s', $src, $code);
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

function redirects_parse_http_response_headers($headers_string) {
  $default_headers = array('location' => array());
  $headers = $default_headers;
  $lines = preg_split('/\r\n/', trim($headers_string));
  $first = array_shift($lines);

  foreach ($lines as $line) {
    if (preg_match('/^(.*?): (.*)/', $line, $matches)) {
      $header_name = strtolower($matches[1]);
      $header_val = $matches[2];
      if (!isset($headers[$header_name])) {
        $headers[$header_name] = array();
      }
      $headers[$header_name][] = $header_val;
    }
  }

  if (!isset($headers['status'])) {
    $headers['status'] = array($first);
  }

  return $headers;
}

function redirects_parse_input_line($line, $separator) {
  $line_parts = array_map('trim', preg_split('/' . $separator . '/', $line));

  if (count($line_parts) != 2) {
    return FALSE;
  }

  $redirect = array(
    'src' => $line_parts[0],
    'dest' => $line_parts[1],
  );

  return $redirect;
}

function redirects_is_absolute_url($url) {
  $parsed = parse_url($url);
  return $parsed !== FALSE && isset($parsed['scheme']) && isset($parsed['host']);
}

// Call the routine only if we are in the *MAIN* script.
// Otherwise we are including "redirects" as a library.
if (count(debug_backtrace()) > 0) {
  return;
}

ini_set('display_errors', 1);

// Avoid annoying php warnings saying default tz was not set.
date_default_timezone_set('UTC');

// This script can only accept redirects from standard input.
// posix_isatty will return FALSE if STDIN is a pipe (normal case).
if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
  redirects_show_usage();
  exit;
}

$input = array(
  'redirects' => array(),
  'cmd' => 'generate',
  'read_options' => array('separator' => '\t'),
);

$cli_args = $argv;

while ((($arg = array_shift($cli_args)) !== NULL)) {
  // Long options could be passed in the form --LONG_OPT=VALUE.
  if (preg_match('/(--[^=]+)=(.+)/', $arg, $matches)) {
    $arg = $matches[1];
    array_unshift($cli_args, $matches[2]);
  }

  $next_arg = current($cli_args);

  switch ($arg) {
    case '--test':
      $input['cmd'] = 'test';
      break;

    case '--base_url':
      if ($input['cmd'] != 'test') {
        redirects_show_usage('Base url is valid option only for test mode.');
        exit;
      }
      else if (redirects_is_absolute_url($next_arg)) {
        array_shift($args);
        $input['test_options']['base_url'] = $next_arg;
      }
      else {
        redirects_show_usage('Base url is not a valid url.');
        exit;
      }

      break;

    case '--generator':
      if ($input['cmd'] != 'generate') {
        redirects_show_usage('Useless --generator option found.');
        exit;
      }

      if (!in_array($next_arg, array_keys(redirects_generators()))) {
        redirects_show_usage(sprintf('Unknown generator \'%s\'', $next_arg));
        exit;
      }

      $input['generate_options']['generator'] = $next_arg;
      break;

    case '--separator':
      $input['read_options']['separator'] = $next_arg;
      break;
  }
}

while (($line = trim(fgets(STDIN)))) {
  $redirect = redirects_parse_input_line($line, $input['read_options']['separator']);

  if ($redirect !== FALSE) {
    $input['redirects'][] = $redirect;
  }
}

if ($input['cmd'] == 'generate') {
  $input += array('generate_options' => array());
  redirects_generate($input['redirects'], $input['generate_options']);
}
else if ($input['cmd'] == 'test') {
  $input += array('test_options' => array());
  redirects_test($input['redirects'], $input['test_options']);
}
