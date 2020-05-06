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
	        
	        $pages[$item->textContent] = $attr->nodeValue;
	    }
	    
	    return $pages;
	}
	
	public function getContentItem($title = null)
	{
	    if(!$list = $this->getList()) { return false; } // Getting the list of available pages failed.
	    
	    if($title === null) // Pick the first available item from the list.
	    {
            $available = array_diff(array_keys($list), $this->imported);
            $title = array_shift($available);
	    }
        else 
        {
            if(!isset($list[$title])) { return false; } // Title was not found.
        }
        
        $url = $list[$title];
        
        // Fetch the article's content.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.52 Safari/537.17');
        curl_setopt($ch, CURLOPT_URL, 'https://www.ncbi.nlm.nih.gov/'.$url.'?report=printable');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $html = curl_exec($ch);
        curl_close($ch);
        
        if(!$html) { return false; } // The artitle was not found (broken link?)
        
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
        $h2s = $content->getElementsByTagName('h2'); // Section titles.
        $ps = $content->getElementsByTagName('p'); // Section content.
        
        // Convert the DOM elements to Wikitext.
        $wikitext = '';
        foreach($content->childNodes as $section)
        {
            $nodes = $section->childNodes;
            // First node is an h2.
            $wikitext .= '=='.$nodes[0]->textContent."==\n";
            
            // Remaining nodes are paragraphs.
            for($i = 1; $i < $nodes->count(); $i++)
            {
                $wikitext .= $nodes[$i]->textContent."\n\n";
            }
        }
        
        // Rebuild references.
        foreach($content->getElementsByTagName('dd') as $i => $ref)
        {
            $ref = $ref->textContent;
            preg_match("/\[PubMed:([^\]]*)\]/", $ref, $match);
            $pmid = trim($match[1]);
            
            /* Replace the first occurence with just an URL as the reference. The Visual Edition interface will allow users
             * to convert the url to a proper reference.*/
            $wikitext = preg_replace('/\['.($i + 1).'\]/', '<ref name=":'.($i + 1).'">https://www.ncbi.nlm.nih.gov/pubmed/'.$pmid.'</ref>', $wikitext, 1);
            $wikitext = str_replace('['.($i + 1).']', '<ref name=":'.($i + 1).'" />', $wikitext);
        }
        
        return new ContentItem($title, $wikitext);
	}
}
