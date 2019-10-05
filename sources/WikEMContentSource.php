<?php
/**
 * WikEM content source.
 *
 * @file
 * @ingroup Extensions
 * @author Antoine Mercier-Linteau
 * @license GPL-3.0
 */

namespace MediaWiki\Extension\ContentImporter;

/**
 * WikEM content source.
 */
class WikEMContentSource extends MediaWikiContentSource
{
	public function __construct()
	{
		$this->url = 'https://wikem.org/wiki';
		$this->apiUrl = 'https://wikem.org/w/api.php';
		parent::__construct('wikem', 'WikEM');
	}
}
