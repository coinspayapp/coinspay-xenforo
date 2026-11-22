<?php
/**
 * Builds the release archive of the add-on: dist/coinspay-xenforo-<version>.zip, laid out as a
 * release of XenForo — upload/src/addons/CoinsPay/Payment/ with hashes.json (the file health check
 * of XenForo) — with README.md, LICENSE and docs/ beside upload/; and dist/build/ unpacked.
 *
 * Before packing it checks that the versions agree and that the code uses nothing XenForo 2.2
 * lacks — class names of 2.3 (*Repository), ::class lookups, Purchase::$updateUrl, Guzzle 6 or 7
 * only options and classes — and no cURL of its own: the resource standards of XenForo ask for its
 * HTTP client.
 *
 *   php bin/build.php   (PHP with the zip extension)
 */
$root = dirname(__DIR__);
$addon = $root . '/upload/src/addons/CoinsPay/Payment';
$dist = $root . '/dist';
$build = $dist . '/build';

function fail($message) {
	fwrite(STDERR, $message . "\n");
	exit(1);
}

function remove_tree($dir) {
	if (!is_dir($dir)) {
		return;
	}
	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($items as $item) {
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($dir);
}

/** Files under a folder, relative paths with forward slashes, sorted. */
function files_of($dir) {
	$paths = array();
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($files as $file) {
		$paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
	}
	sort($paths);
	return $paths;
}

$json = json_decode(file_get_contents($addon . '/addon.json'), true);
if (!is_array($json) || empty($json['version_string'])) {
	fail('addon.json is not readable');
}
$version = $json['version_string'];
preg_match("/const VERSION = '([^']+)'/", file_get_contents($addon . '/core/Settings.php'), $match);
if (!$match || $match[1] !== $version) {
	fail('The version of addon.json (' . $version . ') and of core/Settings.php (' . ($match ? $match[1] : '?') . ') differ');
}
// version_id of XenForo: 1.2.3 stable → 1020370.
$parts = array_map('intval', explode('.', $version));
if ((int)$json['version_id'] !== $parts[0] * 1000000 + $parts[1] * 10000 + $parts[2] * 100 + 70) {
	fail('version_id ' . $json['version_id'] . ' does not match ' . $version);
}

$forbidden = array(
	'/\\\\Repository\\\\\w+Repository\b/' => 'a class name of XenForo 2.3 (*Repository): use the short name',
	'/::class\b/' => 'a ::class lookup: XenForo 2.2 finds entities and repositories by short name only',
	'/->updateUrl\b/' => 'Purchase::$updateUrl came with XenForo 2.3',
	'/GuzzleHttp|[\x27"]exceptions[\x27"]\s*=>/' => 'Guzzle 6 in XenForo 2.2, Guzzle 7 in 2.3: only the options and classes both have',
	'/\bcurl_[a-z_]+\s*\(/' => 'no cURL of its own: the resource standards of XenForo ask for its HTTP client (XfTransport)',
	'/#\[\\\\?AllowDynamicProperties/' => 'no properties of its own on objects of XenForo',
);
foreach (files_of($addon) as $path) {
	if (substr($path, -4) !== '.php') {
		continue;
	}
	$code = file_get_contents($addon . '/' . $path);
	foreach ($forbidden as $pattern => $why) {
		if (preg_match($pattern, $code)) {
			fail($path . ': ' . $why);
		}
	}
}

remove_tree($build);
$zipFile = $dist . '/coinspay-xenforo-' . $version . '.zip';
@unlink($zipFile);
$target = $build . '/upload/src/addons/CoinsPay/Payment';
$hashes = array();
foreach (files_of($addon) as $path) {
	if ($path === 'hashes.json' || $path === '.mountpoint') {
		continue;
	}
	if (!is_dir(dirname($target . '/' . $path))) {
		mkdir(dirname($target . '/' . $path), 0755, true);
	}
	copy($addon . '/' . $path, $target . '/' . $path);
	$hashes['src/addons/CoinsPay/Payment/' . $path] = hash_file('sha256', $addon . '/' . $path);
}
file_put_contents($target . '/hashes.json', json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

mkdir($build . '/docs', 0755, true);
copy($root . '/docs/install-en.md', $build . '/docs/install-en.md');
copy($root . '/docs/install-ru.md', $build . '/docs/install-ru.md');
copy($root . '/LICENSE', $build . '/LICENSE');
copy($root . '/README.md', $build . '/README.md');

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
	fail('cannot write ' . $zipFile);
}
$paths = files_of($build);
foreach ($paths as $path) {
	$zip->addFile($build . '/' . $path, $path);
}
$zip->close();
printf("dist/%s: CoinsPay for XenForo %s, %d files, %d in hashes.json\n", basename($zipFile), $version, count($paths), count($hashes));
