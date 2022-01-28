<?php
/**
 * Abstract class for a content source.
 *
 * @file
 * @ingroup Extensions
 * @author Antoine Mercier-Linteau
 * @license GPL-3.0
 */

namespace MediaWiki\Extension\ContentImporter;

/**
 * Abstract class for a content source.
 */
abstract class Source
{
	const PREFIX = "contentImporter";
	
	const MODIFICATION_TAG = 'ContentImporter-tag-modification';
	
	public $id;
	
	public $name;
	
	public $blacklist;
	
	public $imported;
	
	protected $rules;
	
	public $skipped;
	
	public $postponed;
	
	protected $globalRules = [
		"replaceBeforeTranslation" => [
			'style="background:#4479BA; color: #FFFFFF;" + ' => '',
			'align="center"' => '',
			//' style="padding: 5px 5px; background: #F5F5F5;" ' => '',
			'SSRI' => 'IRSS',
			'| -' => '| Ø' // Google screws with |-, which means jumb row and | -, which is a dash inside a cell.
		],
		"replaceAfterTranslation" => [
			"{{{" => "{{",
			"}}}" => "}}",
			"Concept d'information" => 'Information concept',
			'> {' => '>{',
			'} <' => '}<',
			'</ ' => '</',
			'sémantiques / ' => 'sémantiques/',
			'<références /' => '<references /',
			"'' '" => "'''",
			"' ''" => "'''",
			"classe = Symptômes" => "class = Symptôme",
			'[[[' => '[[',
			'[ [' => '[[',
			']]]' => ']]',
			'] ]' => ']]',
			'| -' => '|-',
			'</ noinclude>' => '</noinclude>'
		],
		'classes' => ['Concept' => [], 'All' => ['equivalencies' => []]],
		'specialties' => []
	];
	
	/**
	 * Decodes a page to JSON.
	 * @param string $name the name of the page
	 * @return the JSON decoded content of the page or [] if there was none. 
	 * */
	protected static function getJSONPage($name)
	{
		$article = \Article::newFromTitle(\Title::newFromText(self::PREFIX.'-'.$name.'.json', NS_MEDIAWIKI), \RequestContext::getMain());
		
		$page = $article->getRevision() ? $article->getRevision()->getContent()->getNativeData(): [];
		
		if(!$page) { return []; } // There was not content.
		
		$page = json_decode($page, true);
		
		if($page === null) // The JSON could not be decoded.
		{
			throw new Exception(self::PREFIX.'-'.$name.'.json could not be decoded to JSON, please fix the file.');
		}
		
		return $page;
	}
	
	public function __construct($id, $name)
	{
		$this->id = $id;
		$this->name = $name;
		
		foreach(['blacklist', 'imported', 'rules', 'skipped', 'postponed'] as $page)
		{
			$this->$page = self::getJSONPage($id.'-'.$page);
		}
		
		// Retrieve the global rules.
		if($rules = self::getJSONPage('global-rules'))
		{
			$this->globalRules = array_merge($this->globalRules, $rules);
		}
	}
	
	
	public function getRules()
	{
		return array_merge_recursive($this->globalRules, $this->rules);
	}
	
	public abstract function getContentItem();
	
	public function filterList($list)
	{
		$list = array_diff($list, $this->skipped, array_keys($this->imported), $this->postponed);
		
		foreach($list as $i => $name)
		{
			foreach($this->blacklist as $pattern)
			{
				if(fnmatch($pattern, $name))
				{
					unset($list[$i]);
				}
			}
		}
		
		return $list;
	}
	
	/** 
	 * Save a ContentItem coming from a source and it's redirects. 
	 * @param ContentItem $item
	 * @param array $redirects
	 * @return Title the title object of the saved item.$this
	 * */
	public function save(ContentItem $item, $redirects = [])
	{
		global $wgUser;
		
		// Save the item in the imported list for that source.
		$this->imported[$item->title] = $item->translatedTitle;
		
		$saved = $item->save();
		
		$watchlist = \MediaWiki\MediaWikiServices::getInstance()->getWatchedItemStore();
		
		$watchlist->addWatch($wgUser, $saved);
		
		// Make sure the translatedTitle is actually used in case the content is not saved in the main namespace.
		$redirects[] = $item->translatedTitle;
		
		foreach($redirects as $r)
		{
			if(!$r) { continue; }
			if($r == $saved->getFullText()) { continue; } // Do not add a redirect that has the same name as the parent.
			
			$title = \Title::newFromText($r);
			$page = \Article::newFromTitle($title, \RequestContext::getMain())->getPage();
			
			// Save the content.
			$status = $page->doEditContent( \ContentHandler::makeContent('#REDIRECTION [['.$saved->getFullText().']]', $title),
				'Création de la redirection',
				0, // Flags
				false, // OriginalRevId
				$wgUser,
				null,
				[self::MODIFICATION_TAG]
			);
			
			$watchlist->addWatch($wgUser, $title);
		}
		
		$this->saveArrayToPage('imported', $this->imported, 'Item importé');
		
		return $saved;
	}
	
	public function skip(ContentItem $item)
	{
		$this->skipped[] = $item->title;
		
		return $this->saveArrayToPage('skipped', $this->skipped, 'Ajout item à sauter');
	}
	
	public function postpone(ContentItem $item)
	{
		$this->postponed[] = $item->title;
		
		return $this->saveArrayToPage('postponed', $this->postponed, 'Ajout item reporté');
	}
	
	public function getImportedTemplate($item, $fields = []) 
	{
		$text = "\n{{Article importé d'une source\n";
		$text .= "| accès = ".date('Y/m/d', time())."\n";
		$text .= "| source = $this->name\n";
		$text .= "| version_outil_d'importation = ".\ExtensionRegistry::getInstance()->getAllThings()['ContentImporter']['version']."\n";
		$text .= "| révisé = 0\n";
		
		foreach($fields as $k => $v) { $text .= "| $k = $v\n"; }

		return $text."}}";
	}
	
	protected function saveArrayToPage($page, $array, $message)
	{
		global $wgUser;
		
		$title = \Title::newFromText(self::PREFIX.'-'.$this->id.'-'.$page.'.json', NS_MEDIAWIKI);
		$page = \Article::newFromTitle($title, \RequestContext::getMain())->getPage();
		
		// Save the content.
		$status = $page->doEditContent( \ContentHandler::makeContent(json_encode($array, JSON_PRETTY_PRINT), $title),
			$message,
			0, // Flags
			false, // OriginalRevId
			$wgUser,
			null,
			[self::MODIFICATION_TAG]
		);
		
		return $status;
	}
}
