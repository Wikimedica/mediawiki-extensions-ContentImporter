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
	
	public $name;
	
	public $blacklist;
	
	public $imported;
	
	public $rules;
	
	public $skipped;
	
	public $postponed;
	
	public function __construct($name)
	{
		$this->name = $name;
		
		foreach(['blacklist', 'imported', 'rules', 'skipped', 'postponed'] as $page)
		{
			$article = \Article::newFromTitle(\Title::newFromText(self::PREFIX.'-'.$name.'-'.$page.'.json', NS_MEDIAWIKI), \RequestContext::getMain());

			$this->$page = $article->getRevision() ? $article->getRevision()->getContent()->getNativeData(): [];
			
			if($this->$page)
			{
				$this->$page = json_decode($this->$page, true);
			}
		}
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
	
	public function save(ContentItem $item, $redirects = [])
	{
		$this->imported[$item->title] = $item->translatedTitle;
		
		$item->save();
		
		foreach($redirects as $r)
		{
			if(!$r) { continue; }
			
			global $wgUser;
			
			$title = \Title::newFromText($r);
			$page = \Article::newFromTitle($title, \RequestContext::getMain());
			
			// Save the content.
			$status = $page->doEditContent( \ContentHandler::makeContent('#REDIRECTION [['.$r.']]', $title),
				'Création de la redirection',
				0, // Flags
				false, // OriginalRevId
				$wgUser,
				null,
				[self::MODIFICATION_TAG]
			);
		}
		
		return $this->saveArrayToPage('imported', $this->imported, 'Item importé');
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
	
	public abstract function getImportedTemplate($item);
	
	protected function saveArrayToPage($page, $array, $message)
	{
		global $wgUser;
		
		$title = \Title::newFromText(self::PREFIX.'-'.$this->name.'-'.$page.'.json', NS_MEDIAWIKI);
		$page = \Article::newFromTitle($title, \RequestContext::getMain());
		
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
