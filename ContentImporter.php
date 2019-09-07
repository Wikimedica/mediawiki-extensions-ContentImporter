<?php
/**
 * ContentImporter provides content import facilities to Wikimedica.
 * 
 * This PHP entry point is deprecated. Please use wfLoadExtension() and the extension.json file
 * instead. See https://www.mediawiki.org/wiki/Manual:Extension_registration for more details.
 * 
 * @file
 * @author Antoine Mercier-Linteau
 * @license GPL-3.0
 */

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'ContentImporter' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['ContentImporter'] = __DIR__ . '/i18n';
	wfWarn(
		'Deprecated PHP entry point used for ContentImporter extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die( 'This version of the ContentImporter extension requires MediaWiki 1.31+' );
}

if ( !defined( 'MEDIAWIKI' ) ) {
	die();
}