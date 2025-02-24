<?php
/**
 * A content item.
 *
 * @file
 * @ingroup Extensions
 * @author Antoine Mercier-Linteau
 * @license GPL-3.0
 */

namespace MediaWiki\Extension\ContentImporter;

use Google\Cloud\Translate\TranslateClient;
use WikiPage;
use MediaWiki\MediaWikiServices;

/**
 * This class represents a content item fetched from a source.
 * */
class ContentItem
{
	const MODIFICATION_TAG = 'contentImporter-imported';
	
	static public $source;
	
	static public $contentLanguage = 'en';
	
	public $title;
	
	public $translatedTitle;
	
	/** "var string|null, the page's content, set to null if the page does not exist. */
	public $content;
	
	public $processedContent;
	
	public $class;
	
	public $wikidataID;
	
	public $redirects;
	
	public $sections = [];
	
	public $tasks = [];
	
	public $sources = [];
	
	/** @var array stores citations in the format outputted by \Parser::extractTagsAndParams() */
	protected $citations = [];
	
	/**
	 * Build a ContentItem from POST data.
	 * @param array $data
	 * @param string $prefix prefix for the properties
	 * @return ContentItem
	 * */
	public static function fromData($data, $prefix = '')
	{
		$item = new self($data[$prefix.'sourceTitle'], $data[$prefix.'sourceContent']);
		$item->translatedTitle = isset($data[$prefix.'destinationTitle']) ? trim($data[$prefix.'destinationTitle']): null;
		$item->processedContent = isset($data[$prefix.'destinationContent']) ? $data[$prefix.'destinationContent']: null;
		$item->wikidataID = isset($data[$prefix.'wikidataID']) ? $data[$prefix.'wikidataID']: null;
		//$item->redirects[] = isset($data[$prefix.'redirect1']) ? $data[$prefix.'redirect1']: null;
		//$item->redirects[] = isset($data[$prefix.'redirect2']) ? $data[$prefix.'redirect2']: null;
		//$item->redirects[] = isset($data[$prefix.'redirect3']) ? $data[$prefix.'redirect3']: null;
		
		$item->tasks = isset($data[$prefix.'destinationToDo']) ? $data[$prefix.'destinationToDo']: [];
		$item->class = isset($data[$prefix.'destinationClass']) ? $data[$prefix.'destinationClass']: [];
		$item->sources = isset($data[$prefix.'sourceSources']) ? json_decode($data[$prefix.'sourceSources']): [];
		
		return $item;
	}
	
	/**
	 * Process a wikicode string into sections.
	 * @param string $content the wikicode to process
	 * @param int $depth the maximum depth of sections to process.
	 * @return [] the processed content 
	 * */
	public static function process($content, $depth = 2)
	{
		$sections = [];
		$matchStart = [];
		$matchEnd = [];
		//$pat = '^(={'.$depth.'}).+\1\s*$()';
		$pat = '^(={'.$depth.'})[^=]+\1\s*$()';
		
		$offset = 0;
		$previous = 0;
		$title = '';
		
		preg_match( "/$pat/im", $content, $matchStart, PREG_OFFSET_CAPTURE, $offset);
		
		if(empty($matchStart)) // There are no titles in this content.
		{
			return [0 => $content];
		}
		
		// This is the introduction.
		$previous = $offset;
		$offset = $matchStart[0][1] + strlen($matchStart[0][0]);
		$sections[0] = trim(substr($content, 0, $matchStart[0][1]), " \n\r");
		
		// Add a fake ending to capture all sections.
		$end = 'End';
		for($i = $depth; $i > 0; $i --) { $end = '='.$end.'='; }
		$content .= "\n".$end;
		
		preg_match( "/$pat/im", $content, $matchEnd, PREG_OFFSET_CAPTURE, $offset);
		
		while(true)
		{			
			$title = $matchStart[0][0];
			$offset = $matchStart[0][1] + strlen($title);
			$text = substr($content, $offset , $matchEnd[0][1] - $offset);
			$text = self::process($text, $depth + 1);
			if(count($text) === 1 && isset($text[0])) // If there are no sub-sections.
			{
				$text = trim($text[0], " \n\r");
			}
			
			$title = trim(str_replace(['[[', ']]', "\n", '=', "\r"], '', $title)); // remove links and line jumps from the title.

			if(strpos($title, '|')) // If a display name for the link was used ([[Link|Name]])
			{
				$parts = explode('|', $title);
				$title = $parts[1];	
			}
			
			if(is_array($text)) 
			{
				/* If a title in the section is the same as the name of the parent section, it is considered redundant and is brought up to be a parent. */
				foreach($text as $name => $section)
				{
					if($name === 0) { if($section) { break; } }
					if(strtolower($name) == strtolower($title)) { $text = $section; break; }
				}
			}
			
			$sections[$title] = $text;
			
			// Next block.
			$matchStart = $matchEnd;
			preg_match( "/$pat/im", $content, $matchEnd, PREG_OFFSET_CAPTURE, $matchEnd[0][1] + strlen($matchEnd[0][0]));
			
			if(empty($matchEnd)) // Reached the end of the content.
			{
				break;
			}
		}
		
		return $sections;
	}
	
	/**
	 * Checks if a title matches a pattern.
	 * @param string $pattern
	 * @param string $title
	 * @return bool true if the title matches the pattern.
	 * */
	public static function titleMatch($pattern, $title)
	{
		$title = str_replace(["\n", "\r"], '', $title);
		
		foreach(explode('||', $pattern) as $p)
		{
			if(fnmatch(strtolower($p), strtolower($title)))
			{
				return true;
			}
		}
			
		return false;
	}
	
	/** Converts an array of sections to a string.
	 * @param array $sections
	 * @param int $level the section level to start from 
	 * @param string 
	 * */
	public static function sectionsToText($sections, $level = 2)
	{
		$text = '';
		
		foreach($sections as $name => $content)
		{
			if($name !== 0)
			{
				for($i = $level; $i > 0; $i--) { $name = '='.$name.'='; }
			}
			
			$content = trim(is_array($content) ? self::sectionsToText($content, $level + 1): $content, "\n\r");
			$text .= ($name === 0 ? '': "\n\n$name").($content ? "\n\n$content": "\n");
		}
		
		return $text;
	}

    /**
     * Class constructor.
     * @param string $title the title of the item.
     * @param string $content the textual content of the item.
     * */	
	public function __construct($title, $content)
	{
		$this->title = $title;
		$this->content = $content;
	}
	
	/**
	 * Translate some text using the GoodleTranslate service.
	 * @param string $text
	 * @return string*/
	public static function translate($text)
	{		
	    // Do not translate French.
	    if(self::$contentLanguage == 'fr') { return $text; }
		
		// return $text;
		
		putenv('GOOGLE_APPLICATION_CREDENTIALS='.__DIR__.'/../vendors/GoogleTranslateAPIKey.json');
		
		//Simplify the UNIQ QINU internat markers, otherwise Google seems to screw with it.
		// Example: '"`UNIQ--!---00000001-QINU`"'
		$text = str_replace(chr(0x7F)."'\"`UNIQ--ref-", 'UNIQref', $text);
		$text = str_replace(chr(0x7F)."'\"`UNIQ--!---", 'UNIQcom', $text);
		$text = str_replace("-QINU`\"'".chr(0x7F), 'QINU', $text);
		
		// Instantiates a client.
		$translate = new TranslateClient(['projectId' => 'wikimedica-conte-1560481017558']);
		
		// The API cannot process more that 30000 characters so split the requests
		$start = 0;
		$end = 19000;
		$parts = ceil(strlen($text) / $end );
		$blocks = [];
		
		// Split the text into blocks (ignoring UTF-8 encoding seems fine).
		for($i = 0; $i < $parts; $i++)
		{
			// Make sure we split the text along phrases.
			while($end < strlen($text) && // While we have not reached the end of the string.
				$text[$end] != '.' && // While we have not reached a period.
				$end - $start < 20000 // While we have not reached the hard coded limit for blocks.
			)
			{
				$end++;
			}
			
			$blocks[] = substr($text, $start, $end + 1);
			$start = $end + 1;
			$end += 19000;
			if($start >= strlen($text) - 1) { break; }
		}
		
		$text = '';
		
		foreach($blocks as $b) // Translate each block.
		{
			$result = $translate->translate($b, [
				'source' => 'en',
				'target' => 'fr',
				'format' => 'text'
			]);
			
			$text .= $result['text'];
		}

		// Restore the UNIQ QINU markers.
		$text = str_replace('UNIQref', chr(0x7F)."'\"`UNIQ--ref-", $text);
		$text = str_replace('UNIQcom', chr(0x7F)."'\"`UNIQ--!---", $text);
		$text = str_replace('QINU', "-QINU`\"'".chr(0x7F), $text);
		
		// Google screws with some format, so restore it.
		if(isset(self::$source->getRules()['replaceAfterTranslation']))
		{
			$corrections = self::$source->getRules()['replaceAfterTranslation'];
			$text = str_replace(array_keys($corrections), array_values($corrections), $text);
		}
		
		return $text;
	}
	
	/**
	 * Extract all citations from the text and replace them with patterns.
	 * @param boolean $noRefs allow citation references, if set to true, references will be resolved (useful if a section which
	 * contained the original citation gets removed during processing).
	 * */
	public function extractCitations($noRefs = false)
	{
		$citations;
		// HTML Comments are always extracted.
		$this->processedContent = \Parser::extractTagsAndParams(['ref'], $this->processedContent, $citations);
		$this->citations = [];
		
		// Clean up the citations to remove duplicates.
		foreach($citations as $id1 => $c1)
		{
			// This citation has no name or is a comment, store it as is.
			if(!isset($c1[2]['name'])) { $this->citations[$id1] = $c1; continue; }
			
			$ref = false; // If the citation is a reference.
			
			foreach($this->citations as $id2 => $c2) // Check for duplicates.
			{
				// This citation has no name, it cannot be a duplicate.
				if(!isset($c2[2]['name'])) { continue; }
				
				// If the citation have the same name and $c2 is not a reference.
				if($c1[2]['name'] == $c2[2]['name'] && $c2[1])
				{
					// These two citations refer to the same source.
					$ref = true;
					break;
				}
			}
			
			// First time we encounter the citation.
			if(!$ref)
			{ 
				$this->citations[$id1] = $c1;
			}
			else if($noRefs) // Resolve the reference.
			{
				$this->citations[$id1] = $c2;
			}
			else 
			{ 
				// This citation is a reference.
				$c1[1] = null; // Remove the citation's content;
				$c1[3] = '<ref name="'.$c1[2]['name'].'" />'; // Set the tag as self closing.
				$this->citations[$id1] = $c1;
			}
			$ref = false;
		}
		
		return;
	}
	
	/**
	 * Restores extracted citations into the content.
	 * */
	public function restoreCitations()
	{
		$empties = [];
		
		foreach($this->citations as $id => $c)
		{
			$t = $c[3];
			$name = isset($c[2]['name']) ? $c[2]['name']: null;
			
			// This citation was empty (it probably used an invalid citation template).
			if($t == '<ref></ref>') { $t = ''; }
			
			// Translate citation parameters.
			$t = str_ireplace(['{{Cite journal', '{{Cite book', '{{Cite web'], ['{{Citation d\'un article', '{{Citation d\'un ouvrage', '{{Citation d\'un lien web'], $t);
			$t = str_replace('|vauthors=','|auteurs=', $t);
			
			if($name && strpos($this->processedContent, $id) === false && $t != '<ref name="'.$name.'" />')
			{
				// If the citation marker was not found (Google Translate sometimes deletes them).
				$empties[$name] = $t;
			}
			else
			{
				// If the citation is a reference to a citation deleted by Google.
				if(isset($empties[$name])) 
				{ 
					$t = $empties[$name]; 
					unset($empties[$name]); // Later references should work normally. 
				}
				
				$this->processedContent = str_replace($id, $t, $this->processedContent);
			}
		}
	}
	
	/* @var string|array caches the match result. **/
	private $_match;
	
	/**
	 * @return string|array the matching class or an array of classes if many match.
	 * */
	public function getClassMatch()
	{
		if($this->_match) { return $this->_match; }
		
		$sections = self::process($this->content);
		
		$class = 'Concept';
		$equalMatches = [];
		$mostMatches = 0;
		
		foreach(self::$source->getRules()['classes'] as $name => $rule)
		{	
			if($name == 'All') { continue; }
			if(!isset($rule['equivalencies'])) { continue; } // No equivalencies for this rule.
			
			$rules = $this->buildRules($name, false);
			
			$matches = 0;
			
			foreach($rules as $match => $equivalent)
			{	
				if($equivalent[0] === 0 || $equivalent[0] === '0' ) { continue; } // Skip the introduction.
				
				foreach($sections as $section => $text)
				{
					$matches += self::titleMatch($match, $section) ? 1: 0;
				}
			}
			
			// Matches with the least number of sections are considered better matches.
			// TODO: use real sections instead of rules.
			$matches = $matches / (count($rule['equivalencies']) + 1);
			
			if($mostMatches < $matches) 
			{
				$mostMatches = $matches;
				$class = $name;
			}
			/* Classes can be equally matched, unless none have been matched so far,
			 * in which case "concept" is used. */
			else if($mostMatches == $matches && $mostMatches !== 0)
			{
				$equalMatches[] = $name;
			}
		}
		
		return $this->_match = $equalMatches ? array_merge($equalMatches, [$class]): $class;
	}
	
	/**
	 * Merges sections together.
	 * @param $s1
	 * @param $s2
	 * @return string|array the merged sections.
	 * */
	private static function _mergeSections($s1, $s2)
	{
		if(is_string($s1) && is_string($s2)) { return $s1."\n".$s2; }
		if(is_array($s1) && is_string($s2)) { $s1[0] .= "\n".$s2; return $s1; }
		if(is_string($s1) && is_array($s2)) { $s1 .= "\n".$s2[0]; $s2[0] = $s1; return $s2; }
		if(is_array($s1) && is_array($s2)) { $s1[0] .= "\n".$s2[0]; unset($s2[0]); return array_merge($s1, $s2); }
	}
	
	/**
	 * Build import rules for a class. 
	 * @param null|string $class the class to build rules for, null if for the current class.
	 * @param bool $includeAll include rules that apply to all classes.
	 * @return array normalized import rules.*/
	public function buildRules($class = null, $includeAll = true)
	{
		$class = !$class ? $this->class: $class;
		$rules = isset(self::$source->getRules()['classes'][$class]['equivalencies']) ? self::$source->getRules()['classes'][$class]['equivalencies']: [];
		
		if($includeAll)
		{
			$rules = array_merge(self::$source->getRules()['classes']['All']['equivalencies'], $rules); // Merge the rules that apply to all classes.
		}
		
		foreach($rules as $pattern => &$rule)
		{
			// Build the rules.
			if(is_string($rule) || is_int($rule)) {$rule = [$rule]; } // Makes it easier to handle.
			$rule['flatten']= isset($rule['flatten']) ? $rule['flatten']: false;
			$rule['position']= isset($rule['position']) ? $rule['position']: false;
		}
		
		return $rules;
	}
	
	/**
	 * Process the ContentItem's content according to the rules defined for the class.
	 * */
	public function processText()
	{
		$this->processedContent = $this->content;
		$this->extractCitations(true); /* Remove citations so they do not get erased (and resolve references in case the section
		defining the citation gets deleted onwards. */
		
		// Remove all templates.
		$delimiter_wrap  = '~';
		$delimiter_left  = '{{';/* put YOUR left delimiter here.  */
		$delimiter_right = '}}';/* put YOUR right delimiter here. */
		
		$delimiter_left  = preg_quote( $delimiter_left,  $delimiter_wrap );
		$delimiter_right = preg_quote( $delimiter_right, $delimiter_wrap );
		$pattern = $delimiter_wrap . $delimiter_left
		. '((?:[^' . $delimiter_left . $delimiter_right . ']++|(?R))*)'
				. $delimiter_right . $delimiter_wrap;
		$matches = [];
		preg_match_all($pattern, $this->processedContent, $matches);
		
		foreach($matches[0] as $match)
		{
			if(strpos($match, '{{columns-list') !== false) // Leave that template in place.
			{
				continue;
			}
			
			// Remove that template.
			$this->processedContent = str_replace($match, '', $this->processedContent);
		}
		
		$this->restoreCitations();
		
		// Replace patterns.
		foreach(isset(self::$source->getRules()['replaceBeforeTranslation']) ? self::$source->getRules()['replaceBeforeTranslation']: [] as $search => $replace)
		{
			$this->processedContent = str_replace($search, $replace, $this->processedContent);
		}
		
		$sections = self::process($this->processedContent);
		unset($sections['References']); // Always remove.
		
		if($this->class != null) // If we should match to an existing class.
		{
			$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle(\Title::newFromText($this->class.'/Prototype', NS_CLASS), \RequestContext::getMain());
		
			$content = $page->getContent( \MediaWiki\Revision\RevisionRecord::RAW );
			try { $prototype = \ContentHandler::getContentText( $content ); } 
			catch ( \Exception $e ) { $prototype = ''; }
			

			$prototype = str_replace('<includeonly></includeonly>', '', $prototype);
			$prototype = self::process($prototype);
			unset($prototype['Notes']); // This is not used by other sources.
		
			// Convert the source text's sections to the class' sections using equivalency rules.
			foreach($this->buildRules() as $pattern => $rule)
			{				
				// Get the section matching the pattern.
				$section = '';
				foreach($sections as $name => $content)
				{
					if(self::titleMatch($pattern, $name)) { $section = $name; break; }	
				}
				if($section === '') { continue; } // Skip that rule because it could not be applied.
				
				if($rule['flatten'] && is_array($sections[$section]))
				{
					$flat = '';
					array_walk_recursive($sections[$section], function($a) use (&$flat) { $flat .= $a; }); // Flatten array.
					$sections[$section] = $flat;
				}
				
				if($rule[0] === 0) // This is the introduction.
				{
					$prototype[0] = self::_mergeSections($prototype[0], $sections[$section]);
					unset($sections[$section]);
					unset($sections[0]);
				}
				else if($rule[0] === 'X') // Remove section.
				{
					unset($sections[$section]);
				}
				else // Add the section under a different title.
				{
				    $title = $rule[0];
				    $destinationSection = &$prototype;
				    
				    if(strpos($title, '/')) // If this section is a subsection of another.
				    {
				        $parts = explode('/', $title);
				        for($i = 0; $i < count($parts) - 1; $i++) // Create the intermediate sections down to the subsection.
				        {
				            $t = $parts[$i];
				            // If that section does not exist, add it.
				            if(!isset($destinationSection[$t])) { $destinationSection[$t] = [0 => '']; }
				            $title = $parts[$i + 1];
				            // If that section is string (meaning it has no sub sections, wrap it in an array.
				            if(is_string($destinationSection[$t])) { $destinationSection[$t] = [0 => $destinationSection[$t]]; }
				            $destinationSection = &$destinationSection[$t];
				        }
				    }
					
					/* If this section has subsections, merge the two sections to prevent the subsections from
					 * being overwritten, as ContentImporter does not yet support subsections. */
					if((isset($destinationSection[$title]) && is_array($destinationSection[$title]) && strpos($destinationSection[$title][0], '===') !== false) ||
					(isset($destinationSection[$title]) && is_string($destinationSection[$title]) && strpos($destinationSection[$title], '===') !== false)
					)
					{
						// Merge the two sections.
				        $s = $this->_mergeSections($destinationSection[$title]."\n", $sections[$section]);
					}
				    else { 
						$s = $this->_mergeSections(
							isset($destinationSection[$title]) ? $destinationSection[$title]: '', 
							$sections[$section]
						); 
					}
				    
				    if($rule['position'] !== false) // Insert item at position.
					{
					    $p1 = $p2 = $destinationSection;
					    $destinationSection = array_merge(
								array_splice($p1, 0, $rule['position']),
								[$title => $s],
								array_splice($p2, $rule['position'])
						);
					}
					else { $destinationSection[$title] = $s; }
					unset($sections[$section]); // Done, unset.
				}
			}
		}
		else { $prototype = [0 => '']; }
		
		// Sections that were not matched using a rule are added to the end.
		if(isset($sections[0])) // If there is still an introduction.
		{
			$prototype[0] = str_replace(['{{Section ontologique|classe='.$this->class.'|nom=Introduction}}', '{{Section ontologique|nom=Introduction|classe='.$this->class.'}}'], '', $prototype[0]);
			$prototype[0] = isset($prototype[0]) ? str_replace('Introduction', '', $prototype[0]): '';
			$prototype[0] = self::_mergeSections($prototype[0], $sections[0]);
			unset($sections[0]);
		}
		
		foreach($sections as $name => $section)
		{
			if($section) // If this section has content.
			{
				$prototype[$name] = $section;
			}
		}
		
		$prototype = array_merge($prototype, $sections); // Add the sections to the end of the prototype. 
		
		if($this->class && $prototype) 
		{ 
		    $refs = $prototype['Références'];
		    unset($prototype['Références']); // Remove the reference section provided
		    $prototype['Références'] = $refs; // Add them back to the end.
			
			// If the text has citations, make sure there is no banner telling otherwise.
			foreach($this->citations as $k => $v) 
			{
				if(strpos($k, 'ref') !== false)
				{
					$prototype['Références'] = '<references />';
					break;
				}
			} 
		}
		
		$text = self::sectionsToText($prototype);
		
		$this->processedContent = trim($text, "\n ");
	}
	
	/**
	 * Do some post processing on the processed content.
	 * This includes dealing with extra line jumps, filling some template fields, etc.
	 * */
	public function postProcessText()
	{
		$text = $this->processedContent;
		
		$text = str_replace("\n\n\n", "\n\n", $text); // Clean up line jumps.
		if($this->wikidataID)
		{
			if(strpos($text, 'wikidata_id') !== false)
			{
				// The template already contains a wikidata_id parameter.
				$text = str_replace("| wikidata_id =", "| wikidata_id = $this->wikidataID", $text); // Add the Wikidata ID.
			} 
			else // Add wikidata_id to the template. 
			{
				$text = str_replace("| version_de_classe", "| wikidata_id = ".$this->wikidataID."\n| version_de_classe", $text); // Add the Wikidata ID.
			}
		}
		else
		{
			$text = str_replace("terme_anglais =", "terme_anglais = ".$this->title, $text);
		}
		
		$text = str_replace('| image = Besoin d\'une image.svg', '| image =', $text); // Remove the default image.
		
		// Add specialties detected from the categories in the source.
		$specialties = [];
		foreach(self::$source->getRules()['specialties'] as $en => $fr)
		{
			if(strrpos($this->content, "[[Category:$en]]") !== false)
			{
				$specialties[] = $fr;
			}
		}
		$text = str_replace("| spécialités =", "| spécialités = ".implode(', ', $specialties), $text);
		
		$this->processedContent = $text;
	}
	
	/** 
	 * Attempts to fetch the WikiData ID for this item.
	 * @return string|false the WikiData ID or false if it was not found. */
	public function getWikiDataID()
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://en.wikipedia.org/w/api.php?action=query&redirects&prop=pageprops&ppprop=wikibase_item&titles='.urlencode($this->title).'&formatversion=2&format=json');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($ch);
		curl_close($ch);  
		
		if(!$result)
		{
			throw new \Exception('Curl failed while querying for the WikiData ID.');
		}
		
		$result = json_decode($result, true);
		
		return $this->wikidataID =  isset($result['query']['pages'][0]['pageprops']['wikibase_item']) ? $result['query']['pages'][0]['pageprops']['wikibase_item']: false;
	}
	
	/**
	 * Save the item as a new wiki page.
	 * @return Title the title object of the saved page.
	 * */
	public function save()
	{
		$this->extractCitations();
		$this->processedContent = $this->translate($this->processedContent);
		$this->restoreCitations();
		
		// Do this after translation otherwise the content gets modified.
		// Adds a template that tells where the article was imported from.
		$this->processedContent = str_replace(['<references />', '<references/>'], self::$source->getImportedTemplate($this)."\n<references />", $this->processedContent);
		
		if($this->tasks) // Add the task banner at the end.
		{
			$this->processedContent.= "\n\n{{".wfMessage('contentImporter-task-template');
			foreach($this->tasks as $t)
			{
				$prop = '';
				switch($t)
				{
					case 'links': $prop = 'ajouter_liens'; break;
					case 'struct': $prop = 'corriger_structure'; break;
					case 'refs': $prop = 'ajouter_références'; break;
					case 'semantics': $prop = 'ajouter_propriétés_sémantiques'; break;
					default: $prop = $t;
				}
				
				$this->processedContent.= "\n".'|'.$prop.'=1';
			}
			$this->processedContent.= "\n}}";
		}
		
		global $wgUser;
		
		// Save the page in the user's drafts.
		$title = \Title::newFromText($wgUser->getUserPage()->getFullText().'/Brouillons/'.$this->translatedTitle);
		
		$page = new WikiPage($title);
		
		// Save the imported page.
		$status = $page->doUserEditContent( \ContentHandler::makeContent($this->processedContent, $title),
			$wgUser,	
			'Importé depuis '.ucfirst(self::$source->name),
			0, // Flags
			false, // OriginalRevId
			[self::MODIFICATION_TAG]
		);

		return $title;
	}
}