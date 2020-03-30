<?php
/**
 * Wikipedia FR content source.
 *
 * @file
 * @ingroup Extensions
 * @author Antoine Mercier-Linteau
 * @license GPL-3.0
 */

namespace MediaWiki\Extension\ContentImporter;

/**
 * Wikipedia FR content source.
 */
class WikipediaFRContentSource extends MediaWikiContentSource
{
	public function __construct()
	{
		ContentItem::$contentLanguage = 'fr';
		
	    $this->url = 'https://fr.wikipedia.org/wiki';
		$this->apiUrl = 'https://fr.wikipedia.org/w/api.php';
		parent::__construct('wikipedia_fr', 'Wikipedia (fr)');
		
		if(!$this->rules)
		{
			$this->rules = [
				"replaceBeforeTranslation" => [],
				"replaceAfterTranslation" => [],
				"classes" => [
						'Maladie' => [
								"name" => "Maladie",
								"equivalencies" => [
								]
						],
						'Concept' => [],
						"All" => ['equivalencies' => ['Voir aussi' => 'X', 'Liens externes' => 'X', 'Nom' => 'X', 'Histoire' => 'X']]
				],
			];
		}
	}
}
