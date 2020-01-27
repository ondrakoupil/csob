<?php

$isTest = function($filePath, $projectPath) {

	return preg_match('~\.phpt$~', $filePath);

};


$isSource = function($filePath, $projectPath) use ($isTest) {

	return !$isTest($filePath, $projectPath);

};
