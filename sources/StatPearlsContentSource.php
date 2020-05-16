<?php
/**
 * StatPearls content source.
 *
 * @file
 * @ingroup Extensions
 * @author Antoine Mercier-Linteau
 * @license GPL-3.0
 */

namespace MediaWiki\Extension\ContentImporter;

/**
 * StatPearls content source.
 */
class StatPearlsContentSource extends Source
{
    public function __construct()
	{
		parent::__construct('statpearls', 'StatPearls');   
	}
	
	public function getList()
	{
	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.52 Safari/537.17');
	    curl_setopt($ch, CURLOPT_URL, 'https://www.ncbi.nlm.nih.gov/books/NBK430685');
	    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	    $html = curl_exec($ch);
	    curl_close($ch);
	    
	    if(!$html) { return false; }
	    
	    $dom = new \DOMDocument();
	    $dom->loadXML($html);
	    $a = $dom->getElementsByTagName('a');
	    $pages = [];
	    
	    // Check link item to get those that point to a StatPearls page.
	    foreach($a as $item)
	    {
	        if(!$attr= $item->attributes->getNamedItem('href')) { continue; }

	        if(strpos($attr->nodeValue, '/books/n/statpearls/article-') !== 0) { continue; }
	        
	        $pages[$attr->nodeValue] = $item->textContent;
	    }
	    
	    return $pages;
	}
	
	public function getContentItem($title = null)
	{
	    if(!$list = $this->getList()) { return false; } // Getting the list of available pages failed.
	    
	    if($title === null) // Pick the first available item from the list.
	    {
            if(!$available = $this->filterList($list))
            {
                return false; // No more elements to fetch.
            }
            
            $url = key($available);
            $title = reset($available);
            array_shift($available);
	    }
        else 
        {
            $list = array_flip($list);
            if(!isset($list[$title])) { return false; } // Title was not found.
            $url = $list[$title];
        }
        
        // Fetch the article's content.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.52 Safari/537.17');
        curl_setopt($ch, CURLOPT_URL, 'https://www.ncbi.nlm.nih.gov/'.$url.'?report=printable');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $html = curl_exec($ch);
        curl_close($ch);
        
        if(!$html) { return false; } // The article was not found (broken link?)
        
        $dom = new \DOMDocument();
        $dom->loadXML($html);
        // getElementById fails to get the id because validation fails, find it manually.
        foreach($dom->getElementsByTagName('div') as $e)
        {
            if($e->getAttribute('class') == 'body-content whole_rhythm')
            { 
                $content = $e;
                break;
            }
        }
        
        // Convert the DOM elements to Wikitext.
        $wikitext = '';
        foreach($content->childNodes as $section)
        {
            $nodes = $section->childNodes;
            
            if($nodes->count() == 0) { continue; } // Skip if this node has no children.
            
            // First node is an h2.
            $wikitext .= '=='.$nodes[0]->textContent."==\n";
            
            // Remaining nodes are paragraphs.
            for($i = 1; $i < $nodes->count(); $i++)
            {
                $wikitext .= $nodes[$i]->textContent."[0]\n\n"; // Also adds a [0] reference marker for the article itself.
            }
        }
        
        $references = [];
        
        // Extract references.
        foreach($content->getElementsByTagName('dd') as $i => $ref)
        {
            $ref = $ref->textContent;
            preg_match("/\[PubMed:([^\]]*)\]/", $ref, $match);
            $references[] = trim($match[1]);
        }
        
        // Extract the PMID of the article.
        foreach($dom->getElementsByTagName('a') as $a)
        {
            if($a->getAttribute('title') == 'PubMed record of this page')
            {
                $pmid = $a->textContent;
                array_unshift($references, $pmid);
                break;
            }
        }
        
        // Extract the last update date of the article.
        foreach($dom->getElementsByTagName('span') as $s)
        {
            if($s->getAttribute('itemprop') == 'dateModified')
            {
                $rev = $s->textContent;
                $revision = (new \DateTime($rev))->format('Y/m/d');
                break;
            }
        }
        // If no revision was found, use the current date as the revision.
        if(!isset($revision)) { $revision = date('Y/m/d', time()); }
        
        // Rebuild references.
        foreach($references as $i => $id)
        {
            /* Replace the first occurence with just an URL as the reference. The Visual Edition interface will allow users
             * to convert the url to a proper reference.*/
            $wikitext = preg_replace('/\['.$i.'\]/', '<ref name=":'.$i.'">https://www.ncbi.nlm.nih.gov/pubmed/'.$id.'</ref>', $wikitext, 1);
            $wikitext = str_replace('['.$i.']', '<ref name=":'.$i.'" />', $wikitext);
        }
        
        $item = new ContentItem($title, $wikitext);
        $item->sources = [
            'pmid' => isset($pmid) ? $pmid: null,
            'revision' => $revision,
            'name' => $title 
        ];
        
        return $item;
	}
	
	/**
	 * @inheritdoc
	 * */
	public function getImportedTemplate($item, $fields = [])
	{
	    return parent::getImportedTemplate($item, [
            'révision' => $item->sources->revision,
	        'pmid' => $item->sources->pmid,
	        'nom' => $item->sources->name
	    ]);
	}
}
