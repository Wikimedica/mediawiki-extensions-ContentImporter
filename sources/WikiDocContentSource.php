<?php
/**
 * WikiDoc content source.
 *
 * @file
 * @ingroup Extensions
 * @author Antoine Mercier-Linteau
 * @license GPL-3.0
 */

namespace MediaWiki\Extension\ContentImporter;

/**
 * WikiDoc content source.
 */
class WikiDocContentSource extends MediaWikiContentSource
{	
	public $subPagesTitles = [
			'overview',
			'historical perspective',
			'classification',
			'pathophysiology',
			'causes',
			'differential diagnosis',
			'epidemiology and demographics',
			'risk factors',
			'screening',
			'natural history, complications and prognosis',
			'Diagnosis' => [
					'History and Symptoms' => 'history and symptoms',
					'Physical Examination' => 'physical examination',
					'Laboratory Findings' => 'laboratory findings',
					'CT' => 'CT',
					'MRI' => 'MRI',
					'Ultrasound' => 'ultrasound',
					'X Ray' => 'x ray',
					'Other Imaging Findings' => 'other imaging findings',
					'Other Diagnostic Studies' => 'other diagnostic studies'
			],
			'Treatment' => [
					'Medical Therapy' => 'medical therapy', 
					'Surgery' => 'surgery', 
					'Primary Prevention' => 'primary prevention', 
					'Secondary Prevention' => 'secondary prevention', 
					'Cost-effectiveness of Therapy' => 'cost-effectiveness of therapy', 
					'Future or Investigational Therapies' => 'future or investigational therapies'
			]
	];
	
	public function __construct()
	{
		$this->url = 'https://www.wikidoc.org/index.php';
		$this->apiUrl = 'https://www.wikidoc.org/api.php';
		parent::__construct('wikidoc');
		
		if(!$this->rules)
		{
			$this->rules = [
					"erase" => [],
					"classes" => [
							'Maladie' => [
									"name" => "Maladie",
									"equivalencies" => [
											"Overview" => [0, 'flatten' => true],
											"Risk Factors" => 'Facteurs de risque',
											"Causes" => 'Étiologies',
											"Epidemiology *" => 'Épidémiologie',
											"Classification" => ['Classification', 'position' => 3],
											'Pathophysiology' => 'Physiopathologie',
											'Differential Diagnosis of *' => 'Diagnostic différentiel',
											'Case Studies' => 'X',
											'Causes of *' => 'X',
											"Treatment" => 'Traitement',
											"Natural History, Complications and Prognosis" => "Évolution",
											"Causes in *" => 'X',
											"Diagnosis" => 'Diagnostic'
											
									]
							],
							'Signe clinique' => [
									"name" => "Signe clinique",
									"equivalencies" => [
											'Overview' => [0, 'flatten' => true],
											"Classification" => ['Classification', 'position' => 1],
											'Eponym' => 'X', 
											'Differential Diagnosis of *' => 'X',
											'Pathophysiology' => 'Physiopathologie',
											'Causes' => 'X', 
											'Historical Perspective' => 'X',
									]
							],
							'Symptôme' => [
									"name" => "Symptôme",
									"equivalencies" => [
											'Overview' => [0, 'flatten' => true],
											"Classification" => ['Classification', 'position' => 1],
											'Eponym' => 'X',
											'Differential Diagnosis of *' => 'X',
											'Pathophysiology' => 'Physiopathologie',
											'Causes' => 'X',
											'Historical Perspective' => 'X',
									]
							],
							'Concept' => [ ],
							"All" => ['equivalencies' => ['See Also' => 'X', 'Overview' => [0, 'flatten' => true], 'External Links' => 'X', 'Related Chapters' => 'X']]
					],
					'replace' => ['SSRI' => 'IRSS'],
					'specialties' => [
							'Cardiology' => 'Cardiologie',
							'Gastroenterology' => 'Gastroentérologie',
							'Laryngology' => 'Oto-rhino-laryngologie',
							'Pulmonology' => 'Pneumologie',
							'Psychiatry' => 'Psychiatrie'
							/*'Pathology' => 'Anatomo-pathologie',
							'Anesthésiologie',
							'Cardiologie',
							'Chirurgie cardiaque',
							'Chirurgie générale',
							'Chirurgie orthopédique',
							'Chirurgie plastique',
							'Chirurgie thoracique',
							'Chirurgie vasculaire',
							'Dermatologie',
							'Endocrinologie et métabolisme',
							'Gastroentérologie',
							'Génétique médicale',
							'Gériatrie',
							'Hématologie',
							'Immunologie clinique et allergie',
							'Médecine d’urgence (MU-5)',
							'Médecine familiale',
							'Médecine de soins intensifs',
							'Médecine du travail',
							'Médecine interne',
							'Médecine nucléaire',
							'Médecine physique et réadaptation',
							'Microbiologie médicale et infectiologie',
							'Néphrologie',
							'Neurochirurgie',
							'Neurologie',
							'Obstétrique et gynécologie',
							'Oncologie médicale',
							'Ophtalmologie',
							'Oto-rhino-laryngologie et chirurgie cervico-faciale',
							'Pédiatrie',
							'Pneumologie',
							'Psychiatrie',
							'Radio-oncologie',
							'Radiologie diagnostique',
							'Rhumatologie',
							'Santé publique et médecine préventive',
							'Urologie',*/
					]
			];
		}
	}
	
	private function _isPageEmpty($content)
	{
		return (!isset($content[0]) || strpos($content[0], "Please help WikiDoc by adding content here") !== false) && count($content) < 3;
	}
	
	/**
	 * @inheritdoc
	 * */
	public function getContentItem($title = null)
	{
		$item = parent::getContentItem($title);
		
		if(!is_object($item)) { return $item; }
		
		$class = $item->getClassMatch();
		
		if($class !== 'Maladie' && (is_array($class) && !in_array('Maladie', $class)))
		{
			return $item;
		}
		
		// If a title contains a link, the page is made from subpages.
		if(!preg_match('/^==.*\[{2}.*$/m', $item->content))
		{
			return $item;
		}
			
		$subs = [];
		array_walk_recursive($this->subPagesTitles, function($a) use (&$subs) { $subs[] = $a; }); // Flatten array.
		
		$query = $this->apiUrl.'?action=query&format=json&prop=revisions&rvprop=content|ids&titles=';
		
		foreach($subs as $sub) {$query .= $item->title.' '.$sub.'|'; }
		$query = substr($query, 0, strlen($query) - 1); // Remove last pipe;
		$query = str_replace(' ', '_', $query);
		
		$json = file_get_contents($query);
		$pages = json_decode($json, true);
		$pages = $pages['query']['pages'];
		
		$sections = ContentItem::process($item->content);
		
		foreach($pages as $page) // Rebuild the disease page using its sub pages.
		{
			$realTitle = $page['title'];
			$title = str_replace($item->title.' ', '', $page['title']);
			
			if(isset($page['missing'])) { continue; } // The page does not exist.
			
			// DDx pages are named differently.
			if($title == 'differential diagnosis') { $title = strtolower('Differentiating '.$item->title.' from other Diseases'); }
			
			foreach($sections as $section => $content)
			{	
				if(str_replace('=', '', strtolower($section)) == $title)
				{	
					$content = $page['revisions'][0]['*'];
					$content = ContentItem::process($content);
					
					if(self::_isPageEmpty($content)) { continue; }
					
					// Overview section becomes the introduction.
					if(isset($content['Overview'])) { $content[0] = $content['Overview']; unset($content['Overview']); }
					unset($content['References']);
					
					$sections[$section] = $content;
					$item->sources[$realTitle] = $page['revisions'][0]['revid'];
					break;
				}
			}
		}
		
		// Some sections are actually built using many pages.
		foreach($this->subPagesTitles as $name => $subPage)
		{
			if(!is_array($subPage)) { continue; }
			
			$sections[$name] = [];
			
			foreach($subPage as $subSubPage => $subSubPageName)
			{				
				foreach($pages as $page)
				{
					$realTitle = $page['title'];
					$title = str_replace($item->title.' ', '', $page['title']);
					
					if(isset($page['missing'])) { continue; } // The pages does not exist.
					
					if(strtolower($subSubPageName) == $title)
					{
						$c = $page['revisions'][0]['*'];
						$c = ContentItem::process($c);
						
						if(self::_isPageEmpty($c)) { continue; }
						
						// Overview section becomes the introduction.
						if(isset($c['Overview'])) { $c[0] = $c['Overview']; unset($c['Overview']); }
						unset($c['References']);
						
						$sections[$name][$subSubPage] = $c;
						$item->sources[$realTitle] = $page['revisions'][0]['revid'];
						
						break;
					}
				}
			}
		}
		
		$item->content = ContentItem::sectionsToText($sections);
			
		return $item;
	}
	
	/**
	 * @inheritdoc
	 * */
	public function filterList($list)
	{
		$filteredList = []; 
		
		// Skip pages that are really subpages of a subject.
		
		foreach(parent::filterList($list) as $item)
		{
			foreach($this->subPagesTitles as $title)
			{
				if(!is_array($title))
				{
					if(strpos($item, $title) > 0) { continue 2; }
				}
				else
				{
					foreach($title as $section)
					{
						if(strpos($item, $section) > 0) { continue 3; }
					}
				}
			}
			
			$filteredList[] = $item;
		}
		
		return $filteredList;
	}
	
	public function getImportedTemplate($item)
	{
		$text = "\n{{Article importé d'une source\n";
		$text .= "| accès = ".date('Y/m/d', time())."\n";
		$text .= "| source = WikiDoc\n";
		$text .= "| version_outil_d'importation = ".\ExtensionRegistry::getInstance()->getAllThings()['ContentImporter']['version']."\n";
		$text .= "| révisé = 0\n";
		
		$first = true;
		$id = '';
		foreach($item->sources as $name => $rev)
		{
			$text .= "| nom$id = $name\n";
			$text .= "| révision$id = $rev\n";
			if($first) { $id = 0; $first = false; }
			else { $id++; }
		}
		
		return $text."}}";
	}
}
