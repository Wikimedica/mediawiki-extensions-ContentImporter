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
		$this->contentItemQuery['action'] = 'expandtemplates';
		parent::__construct('wikem', 'WikEM');
		
		if(!$this->rules) // Default rules.
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
	
	public function getContentItem($title = null)
	{
	    $item = parent::getContentItem($title);
	    
	    if(!is_object($item)) { return $item; }
	    
	    // Expand templates.
	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_URL, $this->apiUrl.'?action=expandtemplates&redirects=true&format=json&prop=wikitext&text={{:'.urlencode($item->title).'}}');
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	    $json = curl_exec($ch);
	    curl_close($ch);
	    
	    if(!$json)
	    {
	        return false;
	    }
	    
	    $json = json_decode($json, true);
	    
	    $text = $json['expandtemplates']['wikitext'];
	    
	    $item->content = $text; // Replace the wikitext with it's expanded version.
	    
	    return $item;
	}
}
