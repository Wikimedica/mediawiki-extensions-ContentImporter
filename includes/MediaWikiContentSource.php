<?php
/**
 * MediaWiki content source.
 *
 * @file
 * @ingroup Extensions
 * @author Antoine Mercier-Linteau
 * @license GPL-3.0
 */

namespace MediaWiki\Extension\ContentImporter;

/**
 * MediaWiki content source.
 */
abstract class MediaWikiContentSource extends Source
{	
	public $url;
	
	public $apiUrl;
	
	public $category;
	
	public function getContentItem($title = null)
	{
		if($title === null)
		{
			while(true)
			{
				if(($list = $this->getList()) === false)
				{
					return 0;
				}
				
				if($list === []) { continue; }
			
				$title = array_shift($list); // Get the first item.
				
				break;
			}
		}
		
		
		/*if(!$json = @file_get_contents($query = $this->apiUrl.'?action=query&format=json&prop=revisions&rvprop=content|ids&titles='.str_replace(' ', '%20', $title)))
		{
			return false;
		}*/
		
		if(!$json = @file_get_contents($query = $this->apiUrl.'?action=query&format=json&prop=revisions&rvprop=content|ids&titles='.urlencode($title)))
		{
			return false;
		}
		
		$json = json_decode($json, true);
		
		$page = array_pop($json['query']['pages']);
		
		if(isset($page['missing'])) { return; } // The requested pages does not exist.
		 
		
		$item = new ContentItem(str_replace('_', ' ', $title), $page['revisions'][0]['*']);
		$item->sources[$title] = $page['revisions'][0]['revid'];
		
		return $item;
	}
	
	private $_continue = null;
	
	public function getList()
	{
		if($this->category) // If a specific category is wanted.
		{
			$url = '?action=query&apfilterredir=nonredirects&cmlimit=5000'.($this->_continue ? 'cmcontinue='.$this->_continue['cmcontinue']: '').'&cmtype=page&list=categorymembers&cmtitle=Category:'.urlencode($this->category).'&format=json';
		}
		else
		{
			$url = '?action=query&apfilterredir=nonredirects&'.($this->_continue ? 'apcontinue='.$this->_continue['apcontinue']: '').'aplimit=5000&list=allpages&format=json';
		}
		
		if(!($content = file_get_contents($this->apiUrl.$url)))
		{
			throw new \Exception('file_get_contents failed');
		}
		
		$content = json_decode($content, true);
		
		$pages = [];
		$list = $this->category ? 'categorymembers': 'allpages';
		
		foreach($content['query'][$list] as $page)
		{
			$pages[] = $page['title'];
		}
		
		if(empty($pages)) { return false; } // The query did not return any pages.
		
		$pages = $this->filterList($pages);
		
		if(empty($pages)) // All the pages returned were filtered out.
		{
			if(isset($content['continue']))
			{
				$this->_continue = $content['continue']; // Save the continue datagram given by the API.
			}
			
			return self::getList(); // Redo the query with the continue settings.
		}
		
		return $pages;
	}
}
