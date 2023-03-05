<?php

$environmentSource = __DIR__ . '/../vendor/nette/tester/src/Framework/Environment.php';

$source = file_get_contents($environmentSource);

if (strpos($source, '/* patched */') === false) {

	$source = preg_replace('~namespace Tester;~i', "namespace Tester; \n/* patched */", $source);
	$source = preg_replace(
		'~register_shutdown_function\(function \(\) use \(\$error\) {~',
		'register_shutdown_function(function () use ($error) {  if (!$error) { return; } ',
		$source
	);

	file_put_contents($environmentSource, $source);

}


$patchFiles = __DIR__ . '/fixed-tester/src/Framework/*';
$targetDir = __DIR__ . '/../vendor/nette/tester/src/Framework';
foreach (glob($patchFiles) as $patch) {
	copy($patch, $targetDir . '/' . basename($patch));
}
