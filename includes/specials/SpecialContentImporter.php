<?php
/**
 * ContentImporter special page.
 *
 * @file
 * @ingroup Extensions
 * @author Antoine Mercier-Linteau
 * @license GPL-3.0
 */

namespace MediaWiki\Extension\ContentImporter;

/**
 * Special page allowing a user to select a source and importing content from it.
 * @ingroup SpecialPage
 */
class SpecialContentImporter extends \FormSpecialPage
{
	
	/** @param string the action of the form. */
	protected $action = 'source';
	
	public $source;
	
	public $sourceTitle;
	
	public $category;
	
	/**
	 * @inheritdoc
	 **/
	public function __construct($name = 'ContentImporter') 
	{
		$this->mIncludable = false; // This page is not includable.
		
		$this->addHelpLink(\Title::newFromText('Importation automatisée de pages', NS_HELP)->getFullURL(), true);
		
		$this->checkReadOnly();
		
		$queryValues = $this->getRequest()->getQueryValues();
		
		$this->action = isset($queryValues['action']) && $queryValues['action'] == 'import' ? 'import': 'source';
		$this->sourceTitle = isset($queryValues['sourceTitle']) ? $queryValues['sourceTitle']: null;
		$this->category = isset($queryValues['category']) ? $queryValues['category']: null;
		
		// Done last.
		isset($queryValues['source']) ? $this->setSource($queryValues['source']): null;
		
		return parent::__construct('ContentImporter', 'edit');
	}
	
	/** 
	 * @inheritdoc 
	 **/
	public function getDescription() 
	{
		if($this->source)
		{
			switch($this->source->id)
			{
				case 'wikem':
					return 'Importer à partir de WikEM';
				case 'wikidoc':
					return 'Importer à partir de WikiDoc';
				case 'wikimsk':
					return 'Importer à partir de WikiMSK';
				case 'wikipedia_en':
					return 'Importer à partir de Wikipedia (en)';
				case 'wikipedia_fr':
				    return 'Importer à partir de Wikipedia (fr)';
				case 'statpearls':
				    return 'Importer à partir de StatPearls';
				//case 'doknosis_observation':
				    //return 'Importer à partir de Doknosis (observation)';
			}
		}
		
		return wfMessage('contentImporter-SpecialContentImporter-description')->text();
	}
	
	protected function setSource($name)
	{
		switch($name)
		{
			case 'wikem':
				$this->source = new WikEMContentSource();
				break;
			case 'wikimsk':
				$this->source = new WikiMSKContentSource();
				break;
			case 'wikidoc':
				$this->source = new WikiDocContentSource();
				break;
			case 'statpearls':
			    $this->source = new StatPearlsContentSource();
			    break;
			case 'wikipedia_en':
				if(!$this->category) { $this->category = 'Rare diseases'; }
				$this->source = new WikipediaENContentSource();
				break;
			case 'wikipedia_fr':
			    if(!$this->category) { $this->category = 'Médicament essentiel listé par l\'OMS'; }
			    $this->source = new WikipediaFRContentSource();
			    break;
			/*case 'doknosis_observation':
			    $this->source = new DoknosisObservationContentSource();
			    break;*/
			default:
				throw new \Exception('Invalid source');
		}
		
		$this->source->category = $this->category;
		
		ContentItem::$source = $this->source;
	}
	
	/**
	 * @inheritdoc
	 **/
	protected function getGroupName() 
	{
		return 'pagetools';
	}
	
	/**
	 * @inheritdoc
	 **/
	protected function getDisplayFormat() {	return 'ooui'; }
	
	/**
	 * @inheritdoc
	 **/
	protected function getFormFields()
	{	
		$form = [];
		
		if($this->action == 'source')
		{
			$form['source'] = [
					'type' => 'select',
					'options' => [
					    'StatPearls' => 'statpearls',
					    'WikiDoc' => 'wikidoc', 
					    'Wikipedia (en)' => 'wikipedia_en', 
					    'Wikipedia (fr)' => 'wikipedia_fr', 
					    'WikEM' => 'wikem',
						'WikiMSK' => 'wikimsk',
					    //'Doknosis observation' => 'doknosis_observation'
					], //'HPO' => 'hpo', 'Disease Ontology' => 'do'],
					'autofocus' => true,
					'label' => 'Sélectionnez une source'
			];
			
			$form['category'] = [
				'type' => 'text',
				'label' => 'Catégorie'
			];
			
			return $form;
		}
		
		$session = $this->getRequest()->getSession();
		$success = false; // if the last importation was a success.
		
		if($t = $session->get('contentImporter-success-save'))
		{
			$success = true;
			$t = \Title::newFromText($t);
			$form['success'] = [
					'type' => 'info',
					'default' => wfMessage('contentImporter-success-save', [$t->getFullUrl(), $t->getSubpageText()])->text(),
					'cssclass' => 'success',
					'section' => 'source',
					'raw' => true
			];
			$session->remove('contentImporter-success-save');
		}
		elseif($session->get('contentImporter-success-skip'))
		{
			$form['success'] = [
					'type' => 'info',
					'default' => wfMessage('contentImporter-success-skip')->text(),
					'cssclass' => 'success',
					'section' => 'source'
			];
			$session->remove('contentImporter-success-skip');
		}
		elseif($session->get('contentImporter-success-postpone'))
		{
			$form['success'] = [
					'type' => 'info',
					'default' => wfMessage('contentImporter-success-postpone')->text(),
					'cssclass' => 'success',
					'section' => 'source'
			];
			$session->remove('contentImporter-success-postpone');
		}
		
		if($this->getRequest()->getMethod() == 'POST') // If this is a post request and the requested title hasn't changed.
		{
			// If this is a post request, populate the item with the content of the request.
			$request = $this->getRequest();
			
			$item = ContentItem::fromData($this->getRequest()->getValues(), 'wp');
		}
		else
		{
			$item = $this->source->getContentItem($this->sourceTitle);
			
			// If this is a request for new item or the source title has changed.
			if($item === false)
			{
				// The requested item does not exist.
				$form['itemDoesNotExist'] = [
					'type' => 'info',
					'default' => 'Le titre n\'existe pas',
					'cssclass' => 'error',
					'section' => 'source'
				];
				
				$item = new ContentItem($this->sourceTitle, null);
			}
			else if($item === 0)
			{
				// The requested item does not exist.
				$form['sourceEmpty'] = [
					'type' => 'info',
					'default' => 'La source n\'a pas retourné d\'item',
					'cssclass' => 'error',
					'section' => 'source'
				];
				
				$item = new ContentItem($this->sourceTitle, null);
			}
			else 
			{
				$item->translatedTitle = $item->translate($item->title);
				$item->getWikiDataID();
				
				// Google will sometimes add determinants to the title.
				if(strpos($item->translatedTitle, 'Le ') === 0 || strpos($item->translatedTitle, 'La ') === 0)
				{
					$item->translatedTitle = ucfirst(substr($item->translatedTitle, 3));
				}
			}
		}
		
		if($item === false) // No more pages to import.
		{
			$form['noMoreItemsToImport'] = [
				'type' => 'info',
				'default' => 'Aucun contenu à importer de cette source',
				'cssclass' => 'error',
				'section' => 'source'
			];
		}
		
		$form['sourceCategory'] = [
			'section' => 'source',
			'type' => 'text',
			'label' => 'Catégorie',
			'readonly' => true,
			'default' => $this->category ? $this->category : 'Aucune catégorie spécifiée',
		];
		
		$form['sourceTitle'] = [
			'section' => 'source',
			'type' => 'text',
			'label' => 'Titre',
			'default' => $item->title,
			'required' => true
		];
		
		$form['sourceCustomTitle'] = [
			'section' => 'source',
			'type' => 'submit',
			'buttonlabel' => 'Récupérer le titre'
		];
		
		$form['sourceSources'] = [ // Save the sources used to build the text.
			'section' => 'source',
			'type' => 'hidden',
			'default' => json_encode($item->sources)
		];
		
		if($item->content === '')
		{
			$form['sourceContentError'] = [
				'type' => 'info',
				'section' => 'source',
				'raw' => true,
				'default' => 'Cette page est vide',
				'cssclass' => 'error'
			];
		}
		
		$form['sourceContent'] = [
			'type' => 'hidden',
			'section' => 'source',
			'default' => $item->content
		];

		// If the page already exists.
		if(!$success && $item->translatedTitle && \Title::newFromText($item->translatedTitle)->exists())
		{
			$form['pageExists'] = [
				'section' => 'destination',
				'type' => 'info',
				'raw' => true,
				'default' => wfMessage('contentImporter-page-exists', [$item->translatedTitle])->parse(),
				'cssclass' => 'error'
			];
		}
		
		$form['destinationTitle'] = [
			'section' => 'destination',
			'type' => 'text',
			'label' => 'Titre',
			'default' => $item->translatedTitle ?: ' ', // Put something in the field so it does not throw an error when the requested item does not exist.	
			'required' => !isset($_POST['wpsourceTitle']), // This field is not required if a new title is being fetched.
		];
		
		for($i = 1; $i < 4; $i++)
		{
			$form['redirect'.$i] = [
				'section' => 'destination',
				'type' => 'text',
				'label' => 'Redirection '.$i
			];
		}
		
		$form['wikidataID'] = [
			'section' => 'destination',
			'type' => 'text',
			'label' => 'Wikidata ID',
			'default' => $item->wikidataID,
			'placeholder' => 'wikidataID non trouvable'
		];
		
		// Build the list of available classes using the source's import rules.
		$destinationClassOptions = [];
		foreach(array_keys($this->source->getRules()['classes']) as $class) { $destinationClassOptions[$class] = $class; }
		unset($destinationClassOptions['All']);
		
		$form['destinationClass'] = [
			'section' => 'destination',
			'type' => 'select',
			'required' => !isset($_POST['wpsourceTitle']), // This field is not required if a new title is being fetched.
			'label' => 'Classe ontologique (Appliquer les changements pour visualiser le résultat)',
			'options' => array_merge(["Sélectionnez une classe" => null], $destinationClassOptions),
			'default' => null,
		];
		
		if(!isset($_POST['wpsourceCustomTitle'])) // If a custom title is being fetched, do not validate.
		{
			$form['destinationClass']['validation-callback'] = [$this, 'validateDestinationClass'];
		}

		$form['destinationPreview'] = [
				'type' => 'submit',
				'buttonlabel' => 'Appliquer',
				'section' => 'destination',
				'flags' => ['normal']
		];
		
		// If the item was found and the item is not being saved. Otherwise, modifications a user made before saving get erased.
		if($item->content !== null && $this->getRequest()->getVal('wpdestinationSave') === null)
		{
			if($match = $this->getRequest()->getVal('wpdestinationClass'))
			{
				// The user has selected a destination class.
				$form['destinationClass']['default'] = $match;
				$options = 
				$match = array_flip($destinationClassOptions)[$match];
				$this->getRequest()->unsetVal('wpdestinationContent');
			}
			else 
			{
				$match = $item->getClassMatch();
				
				if(!is_array($match)) // If match is an array, more than one class was found.
				{
					$form['destinationClass']['default'] = $match; // Only one match, good to go.
				}
			}
			
			$item->class = is_string($match)? $match: null;
			$item->processText();
			$item->postProcessText();
			$item->extractCitations(); $item->restoreCitations(); // Will clean up citation duplicates.
			$processedText = $item->processedContent;
		}
		else { $processedText = '';}
		
		
		//$form['translatedContent'] = ['type' => 'hidden', 'default' => $item->translatedContent];
		
		$form['destinationToDo'] = [
			'section' => 'destination',
			'type' => 'multiselect',
			'label' => 'Améliorations',
			'options' => [
				'Structure de la classe' => 'struct',
				'Ajouter des liens' => 'links',
				'Ajouter des références' => 'refs',
				'Ajouter des balises sémantiques' => 'semantics'
			],
			'default' => ['struct', 'semantics']
		];
		
		$form['destinationContent'] = [
			'section' => 'destination',
			'type' => 'textarea',
			'label' => 'Destination',
			'default' => $processedText,
			'readonly' => false,
			// 'cols' => 180 // Does not work...
			//'required' => true
		];
		
		// Display the control buttons.		
		
		$form['destinationPostpone'] = [
			'type' => 'submit',
			'buttonlabel' => 'Reporter',
			'section' => 'buttons',
			'flags' => ['normal']
		];
		
		$form['destinationSkip'] = [
				'type' => 'submit',
				'buttonlabel' => 'Sauter',
				'section' => 'buttons',
				'flags' => ['destructive']
		];
		
		$form['destinationSave'] = [
				'type' => 'submit',
				'buttonlabel' => 'Ajouter',
				'section' => 'buttons',
		];

		if($item->content) // If there is content to preview.
		{
			$parserOptions = new \ParserOptions($this->getUser());
			$parserOptions->setIsPreview(true);
			$title = \Title::newFromText($item->translatedTitle);
			$content = \ContentHandler::makeContent( $processedText, $title );
			$pstContent = $content->preSaveTransform($title, $this->getUser(), $parserOptions);
			$parserOutput = $pstContent->getParserOutput( $title, null, $parserOptions );
			
			$form['preview'] = [
				'type' => 'info',
				'section' => 'preview',
				'raw' => true,
				'default' => $parserOutput->getText(['enableSectionEditLinks' => false])
			];
		}
		else 
		{
			// Disable buttons.
			$form['destinationPreview']['disabled'] = true;
			$form['destinationPostpone']['disabled'] = true;
			$form['destinationSkip']['disabled'] = true;
			$form['destinationSave']['disabled'] = true;
		}
		
		return $form;
	}
	
	/**
	 * Makes sure a class was selected in the destinationClass field.
	 * @param string $destinationClass the name of the destination class
	 * @param array $data all the data submitted to the form
	 * @return true|string true if validation succeeded
	 */
	public static function validateDestinationClass( $destinationClass, $data ) 
	{
		/* If the class of the destination content does not match the submitted destination class, this
		means the user forgot to apply their changes. */
		if(stripos($data['destinationContent'], '{{Information '.strtolower($destinationClass)) === false)
		{
			return "Vous avez oublié d'appliquer votre changement de classe.";
		}
		
		return $destinationClass == "" ? 'Plus d\'une classe trouvée': true;
	}

	/**
	 * @inheritdoc
	 **/
	public function onSubmit( array $data )
	{
		if(isset($data['source']) && $this->action == 'source')
		{
			$this->action = 'import';
			
			$this->category = $data['category'];
			$this->setSource($data['source']);
			
			$this->getOutput()->redirect($this->getURL());
			
			return;
		}
		
		$session = $this->getRequest()->getSession();
		
		// If this item is being skipped.
		if(isset($data['destinationSkip']) && $data['destinationSkip'] === true)
		{
			$item = ContentItem::fromData($data);
			$this->source->skip($item);
			
			$session->set('contentImporter-success-skip', 1);
			$this->getOutput()->redirect($this->getURL());
			return;
		}
		
		// If this item is being saved for later.
		if(isset($data['destinationPostpone']) && $data['destinationPostpone'] === true)
		{
			$item = ContentItem::fromData($data);
			$this->source->postpone($item);
			
			$session->set('contentImporter-success-postpone', 1);
			$this->getOutput()->redirect($this->getURL());
			return;
		}
		
		// If a custom title is being fetched.
		if(isset($data['sourceCustomTitle']) && $data['sourceCustomTitle'] === true)
		{
			$this->sourceTitle = $data['sourceTitle'];
			$this->getOutput()->redirect($this->getURL());
			return;
		}
		
		if(isset($data['destinationSave']) && $data['destinationSave'] === true)
		{
			$title = $this->source->save(
			    ContentItem::fromData($data), 
			    [$data['redirect1'], $data['redirect2'], $data['redirect3']]
		    );
			
			$session->set('contentImporter-success-save', $title->getFullText());
			$this->getOutput()->redirect($this->getURL());
			return;
		}
	}
	
	/**
	 * @inheritdoc
	 * */
	protected function preText()
	{
		return $this->source ? wfMessage('contentImporter-return')->parse(): '';
	}
	
	/**
	 * @inheritdoc
	 */
	protected function alterForm(\HTMLForm $form )
	{
		if($this->action == 'source')
		{	
			return;
		}
		
		$form->suppressDefaultSubmit();
		
		$form->setAction($this->getURL());
		
		return $form;
	}
	
	/**
	 * @return the URL matching the current state of the form.
	 **/
	protected function getURL()
	{
		$args = ['action' => $this->action];
		
		if($this->source) { $args['source'] = $this->source->id; }
		
		if($this->sourceTitle) { $args['sourceTitle'] = $this->sourceTitle; }
		
		if($this->category) { $args['category'] = $this->category; }
		
		return $this->getPageTitle()->getLinkURL($args);
	}
}
