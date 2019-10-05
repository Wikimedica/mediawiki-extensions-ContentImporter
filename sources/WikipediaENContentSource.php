<?php
/**
 * Wikipedia EN content source.
 *
 * @file
 * @ingroup Extensions
 * @author Antoine Mercier-Linteau
 * @license GPL-3.0
 */

namespace MediaWiki\Extension\ContentImporter;

/**
 * Wikipedia EN content source.
 */
class WikipediaENContentSource extends MediaWikiContentSource
{
	public function __construct()
	{
		$this->url = 'https://en.wikipedia.org/wiki';
		$this->apiUrl = 'https://en.wikipedia.org/w/api.php';
		parent::__construct('wikipedia_en', 'Wikipedia (en)');
		
		if(!$this->rules)
		{
			$this->rules = [
				"replaceBeforeTranslation" => [],
				"replaceAfterTranslation" => [],
				"classes" => [
						'Maladie' => [
								"name" => "Maladie",
								"equivalencies" => [
									"Overview" => [0, 'flatten' => true],
									"Risk Factors" => 'Facteurs de risque',
									"Causes" => 'Étiologies',
									"Epidemiology*" => 'Épidémiologie',
									"Classification" => ['Classification', 'position' => 3],
									'Pathophysiology' => 'Physiopathologie',
									'Causes of*' => 'X',
									"Cause" => 'Étiolgies',
									"Treatment" => 'Traitement',
									"Diagnosis" => 'Diagnostic',
									'Signs and symptoms' => 'Présentation clinique',
									'Screening' => 'Prévention',
									'Prevention' => 'Prévention',
									'Genetics' => 'Physiopathologie'
								]
						],
						'Concept' => [],
						"All" => ['equivalencies' => ['See Also' => 'X', 'External Links' => 'X', 'Name' => 'X', 'History' => 'X']]
				],
			];
		}
	}
}
