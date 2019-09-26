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

class ContentItem
{
	const MODIFICATION_TAG = 'contentImporter-imported';
	
	static public $source;
	
	public $title;
	
	public $translatedTitle;
	
	public $content;
	
	public $processedContent;
	
	public $class;
	
	public $wikidataID;
	
	public $redirects;
	
	public $sections = [];
	
	public $tasks = [];
	
	public $sources = [];
	
	/** @var array stores citations in the format outputted by \Parser::extractTagsAndParams() */
	public $citations = [];
	
	public static function fromData($data, $prefix = '')
	{
		$item = new self($data[$prefix.'sourceTitle'], $data[$prefix.'sourceContent']);
		$item->translatedTitle = isset($data[$prefix.'destinationTitle']) ? $data[$prefix.'destinationTitle']: null;
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
	
	public function __construct($title, $content)
	{
		$this->title = $title;
		$this->content = $content;
	}
	
	public static function translate($text)
	{		
		//return $text;
		
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
		if(isset(self::$source->rules['correctAfterTranslate']))
		{
			$corrections = self::$source->rules['correctAfterTranslate'];
			$text = str_replace(array_keys($corrections), array_values($corrections), $text);
		}
		
		return $text;
	}
	
	public function extractCitations()
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
	
	public function restoreCitations()
	{
		foreach($this->citations as $id => $c)
		{
			$c = $c[3];
			
			// This citation was empty (it probably used an invalid citation template).
			if($c == '<ref></ref>') { $c = ''; }
			
			// Translate citation parameters.
			$c = str_ireplace(['{{Cite journal', '{{Cite book', '{{Cite web'], ['{{Citation d\'un article', '{{Citation d\'un ouvrage', '{{Citation d\'un lien web'], $c);
			$c = str_replace('|vauthors=','|auteurs=', $c);
			
			$this->processedContent = str_replace($id, $c, $this->processedContent);
		}
	}
	
	/*$classArticle = \Article::newFromTitle('Wikimedica:Ontologie/'.$name, \RequestContext::getMain());
	 $class = $classArticle->getRevision() ? $classArticle->getRevision()->getContent()->getNativeData(): false;
	 $classSections = self::process($class, true);*/
	
	private $_match;
	
	/**
	 * @return string|array the matching class or an array of classes if many match.
	 * */
	public function getClassMatch()
	{
		if($this->_match) { return $this->_match; }
		
		/*$sections = [];
		$func = function($k, $v) use (&$sections) 
		{ 
			if(!isset($sections[$k])) { $sections[$k] = $v; }
			if(is_array($k)) { $sections[$k] = $v; }
		};
		array_walk_recursive(self::process($this->content), $func); // Flatten array.*/
		$sections = self::process($this->content);
		
		$class = 'Concept';
		$equalMatches = [];
		$mostMatches = 0;
		
		foreach(self::$source->rules['classes'] as $name => $rule)
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
			$matches = $matches / count($rule['equivalencies']);
			
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
	
	private static function _mergeSections($s1, $s2)
	{
		if(is_string($s1) && is_string($s2)) { return $s1.$s2; }
		if(is_array($s1) && is_string($s2)) { $s1[0] .= $s2; return $s1; }
		if(is_string($s1) && is_array($s2)) { $s1 .= $s2[0]; $s2[0] = $s1; return $s2; }
		if(is_array($s1) && is_array($s2)) { $s1[0] .= $s2[0]; unset($s2[0]); return array_merge($s1, $s2); }
	}
	
	public function buildRules($class = null, $includeAll = true)
	{
		$class = !$class ? $this->class: $class;
		$rules = isset(self::$source->rules['classes'][$class]['equivalencies']) ? self::$source->rules['classes'][$class]['equivalencies']: [];
		
		if($includeAll)
		{
			$rules = array_merge(self::$source->rules['classes']['All']['equivalencies'], $rules); // Merge the rules that apply to all classes.
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
	
	/** @var boolean if the prototype defines a semantic sections template. It might get overwritten so this variable will tell
	 * if a check is needed during post processing. */
	private $_hasSemanticSections = false;
	
	public function processText()
	{
		$this->processedContent = $this->content;
		$this->extractCitations(); // Remove citations so they do not get erased.
		
		// Remove all templates.
		$delimiter_wrap  = '~';
		$delimiter_left  = '{{';/* put YOUR left delimiter here.  */
		$delimiter_right = '}}';/* put YOUR right delimiter here. */
		
		$delimiter_left  = preg_quote( $delimiter_left,  $delimiter_wrap );
		$delimiter_right = preg_quote( $delimiter_right, $delimiter_wrap );
		$pattern = $delimiter_wrap . $delimiter_left
		. '((?:[^' . $delimiter_left . $delimiter_right . ']++|(?R))*)'
				. $delimiter_right . $delimiter_wrap;
		$this->processedContent = preg_replace($pattern, '', $this->processedContent);
				
		$this->restoreCitations();
		
		// Replace patterns.
		foreach(isset(self::$source->rules['replace']) ? self::$source->rules['replace']: [] as $search => $replace)
		{
			$this->processedContent = str_replace($search, $replace, $this->processedContent);
		}
		
		$sections = self::process($this->processedContent);
		//$lastSection = array_pop($sections); // Whatever is last (usually References of External Links) gets removed.
		unset($sections['References']);
		
		if($this->class != null) // If we should match to an existing class.
		{
			$article = \Article::newFromTitle(\Title::newFromText('Wikimedica:Ontologie/'.$this->class.'/Prototype', NS_PROJECT), \RequestContext::getMain());
			$prototype = $article->getRevision() ? $article->getRevision()->getContent()->getNativeData(): '';
			$prototype = str_replace('<includeonly></includeonly>', '', $prototype);
			$this->_hasSemanticSections = strpos($prototype, '{{Sections sémantiques/') !== false;
			$prototype = self::process($prototype);
			unset($prototype['Notes']); // This is not used by other wikis.
		
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
					$prototype[0] = str_replace(['{{Section ontologique|classe='.$this->class.'|nom=Introduction}}', '{{Section ontologique|nom=Introduction|classe='.$this->class.'}}'], '', $prototype[0]);
					$prototype[0] = str_replace('Introduction', '', $prototype[0]);
					
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
					if($rule['position'] !== false) // Insert item at position.
					{
						$p1 = $p2 = $prototype;
						$prototype = array_merge(
								array_splice($p1, 0, $rule['position']),
								[$rule[0] => $sections[$section]],
								array_splice($p2, $rule['position'])
						);
					}
					else { $prototype[$rule[0]] = $sections[$section]; }
					unset($sections[$section]);
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
		
		if($this->class && $prototype) { $refs = array_pop($prototype); } // Remove the references from the prototype to add them back later.
		
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
			$prototype['Références'] = $refs;
			
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
	
	public function postProcessText()
	{
		$text = $this->processedContent;
		
		if($this->tasks) // Add the task banner at the end.
		{
			$text .= "\n\n{{".wfMessage('contentImporter-task-template');
			foreach($this->tasks as $t)
			{
				$prop = '';
				switch($t)
				{
					case 'links': $prop = 'ajouter_liens'; break;
					case 'struct': $prop = 'corriger_structures'; break;
					case 'refs': $prop = 'ajouter_références'; break;
					case 'semantics': $prop = 'ajouter_propriétés_sémantiques'; break;
					default: $prop = $t;
				}
				
				$text .= "\n".'|'.$prop.'=1';
			}
			$text .= "\n}}";
		}
		
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
		
		// This translated item needs to be checked.
		//$text .= "\n[[Catégorie:".wfMessage('contentImporter-translation-check-category')."]]";
		
		// Add specialties detected from the categories in the source.
		$specialties = [];
		foreach(self::$source->rules['specialties'] as $en => $fr)
		{
			if(strrpos($this->content, "[[Category:$en]]") !== false)
			{
				$specialties[] = $fr;
			}
		}
		$text = str_replace("| spécialités =", "| spécialités = ".implode(', ', $specialties), $text);
		
		// If the semantic sections was erased.
		if($this->_hasSemanticSections && strpos($text, '{{Sections sémantiques/') === false)
		{
			foreach(['Notes', 'Références'] as $section)
			{
				foreach(['==', '== '] as $eq)
				{
					$count = 0;
					$text = str_replace($eq.$section, "{{Sections sémantiques/$this->class}}\n\n== $section" ,$text, $count);
					
					if($count) { break 2; } // Something was replaced
				}
			}
		}
		
		$this->processedContent = $text;
	}
	
	public function getWikiDataID()
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://en.wikipedia.org/w/api.php?action=query&redirects&prop=pageprops&ppprop=wikibase_item&titles='.urlencode($this->title).'&formatversion=2&format=json');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($ch);
		curl_close($ch);  
		
		if(!$result)
		{
			throw new \Exception('curl failed');
		}
		
		$result = json_decode($result, true);
		
		return $this->wikidataID =  isset($result['query']['pages'][0]['pageprops']['wikibase_item']) ? $result['query']['pages'][0]['pageprops']['wikibase_item']: false;
	}
	
	public function save()
	{
		$this->extractCitations();
		$this->processedContent = $this->translate($this->processedContent);
		$this->restoreCitations();
		// Do this after translation otherwise the content gets modified.
		$this->processedContent .= self::$source->getImportedTemplate($this);
		
		global $wgUser;
		
		$title = \Title::newFromText($this->translatedTitle);
		//$page = \Article::newFromTitle($title, \RequestContext::getMain());
		
		$page = new WikiPage($title);
		
		// Save the content to the skipped page
		$status = $page->doEditContent( \ContentHandler::makeContent($this->processedContent, $title),
			'Importé depuis '.ucfirst(self::$source->name),
			0, // Flags
			false, // OriginalRevId
			$wgUser,
			null,
			[self::MODIFICATION_TAG]
		);
		
		return $status;
	}
}