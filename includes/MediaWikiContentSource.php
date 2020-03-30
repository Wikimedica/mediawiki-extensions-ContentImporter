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

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->apiUrl.'?action=query&redirects=true&format=json&prop=revisions&rvprop=content|ids&titles='.urlencode($title));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$json = curl_exec($ch);
		curl_close($ch);      
		
		if(!$json)
		{
			return false;
		}
		
		$json = json_decode($json, true);
		
		$page = array_pop($json['query']['pages']);
		
		if(isset($page['missing'])) { return false; } // The requested pages does not exist.
		 
		$item = new ContentItem(str_replace('_', ' ', $title), $page['revisions'][0]['*']);
		$item->sources[$title] = $page['revisions'][0]['revid'];
		
		return $item;
	}
	
	private $_continue = null;
	
	public function getList()
	{
		if($this->category) // If a specific category is wanted.
		{
			$url = '?action=query&apfilterredir=nonredirects&cmlimit=5000'.($this->_continue ? 'cmcontinue='.$this->_continue['cmcontinue'].'&': '').'&cmtype=page&list=categorymembers&cmtitle=Category:'.urlencode($this->category).'&format=json';
		}
		else
		{
			$url = '?action=query&apfilterredir=nonredirects&'.($this->_continue ? 'apcontinue='.$this->_continue['apcontinue'].'&': '').'aplimit=5000&list=allpages&format=json';
		}
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->apiUrl.$url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$content = curl_exec($ch);
		curl_close($ch);  
		
		if(!$content)
		{
			throw new \Exception('curl failed');
		}
		
		$content = json_decode($content, true);
		
		$pages = [];
		$list = $this->category ? 'categorymembers': 'allpages';
		
		if(!isset($content['query'][$list])) { return []; } // No more pages.
		
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
		
	public function getImportedTemplate($item, $fields = [])
	{
		$sources = [];
		$first = true;
		$id = '';
		foreach($item->sources as $name => $rev)
		{
			$sources["nom$id"] = $name;
			$sources["révision$id"] = $rev;
			if($first) { $id = 0; $first = false; }
			else { $id++; }
		}
		
		return parent::getImportedTemplate($item, $sources) ;
	}
}
