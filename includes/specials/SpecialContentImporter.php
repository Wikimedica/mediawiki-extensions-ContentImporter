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
	
	/** @var the action of the form. */
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
			switch($this->source->name)
			{
				case 'wikem':
					return 'Importer à partir de WikEM';
				case 'wikidoc':
					return 'Importer à partir de WikiDoc';
			}
		}
		
		return wfMessage('contentImporter-SpecialContentImporter-description')->text();
	}
	
	protected function setSource($name)
	{
		switch($name)
		{
			/*case 'wikem':
				$this->source = new WikEMContentSource();
				break;*/
			case 'wikidoc':
				$this->source = new WikiDocContentSource();
				break;
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
	protected function getLoginSecurityLevel()
	{
		global $wgUser;
		
		if($wgUser->loggedIn())
		{
			return; // Do not increase the security level for users that are not logged in.
		}
		
		return $this->name; // Reauthentify the user for this kind of operation.
	}
	
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
					'options' => ['WikiDoc' => 'wikidoc', 'WikEM' => 'wikem',], //'HPO' => 'hpo', 'Disease Ontology' => 'do'],
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
			$sucess = true;
			$form['success'] = [
					'type' => 'info',
					'default' => wfMessage('contentImporter-success-save', [$t])->parse(),
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
				
				$item = new ContentItem($this->sourceTitle, '');
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
				
				$item = new ContentItem($this->sourceTitle, '');
			}
			else 
			{
				$item->translatedTitle = $item->translate($item->title);
				$item->getWikiDataID();
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
		
		$form['sourceContent'] = [
			'type' => 'textarea',
			'section' => 'source',
			'readonly' => true,
			'label' => 'Source',
			'default' => $item->content,
			'rows' => 53
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
			'default' => $item->translatedTitle,	
			'required' => true
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
		
		$form['destinationClass'] = [
			'section' => 'destination',
			'type' => 'radio',
			'required' => true,
			'label' => 'Classe ontologique',
			'options' => [
				'Maladie' => 'maladie',
				'Signe clinique' => 'signe clinique',
				'Symptôme' => 'symptôme',
				'Médicament' => 'médicament',
				'Concept' => 'concept'
			],
			'default' => false
		];
		
		// If the item was found and the item is not being saved. Otherwise, modifications a user made before saving get erased.
		if($item->content && $this->getRequest()->getVal('wpdestinationSave') === null)
		{
			if($match = $this->getRequest()->getVal('wpdestinationClass'))
			{
				// The user has selected a destination class.
				$form['destinationClass']['default'] = strtolower($match);
				$match = array_flip($form['destinationClass']['options'])[$match];
				$this->getRequest()->unsetVal('wpdestinationContent');
			}
			else 
			{
				$match = $item->getClassMatch();
			
				if(is_array($match))
				{
					$form['destinationClassError'] = ['type' => 'info', 'default' => 'Plus d\'une classe trouvée', 'cssclass' => 'error', 'section' => 'destination'];
				}
				else
				{
					$form['destinationClass']['default'] = strtolower($match);
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
			]
		];
		
		$form['destinationContent'] = [
			'section' => 'destination',
			'type' => 'textarea',
			'label' => 'Destination',
			'default' => $processedText,
			'readonly' => false
			//'required' => true
		];
		
		if($item->content) // If the item was found.
		{
			// Display the control buttons.
			
			$form['destinationPreview'] = [
				'type' => 'submit',
				'buttonlabel' => 'Rafrâchir',
				'section' => 'buttons',
				'flags' => ['normal']
			];
			
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
		}
		
		if($item->content) // If there is content to preview.
		{
			$parserOptions = new \ParserOptions();
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
		
		return $form;
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
		
		// If this item is being saved for lated.
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
			$item = ContentItem::fromData($data);
			//$item->translatedContent = $data['destinationContent'];
			$this->source->save($item, [$data['redirect1'], $data['redirect2'], $data['redirect3']]);
			
			$session->set('contentImporter-success-save', $item->translatedTitle);
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
		
		$this->getOutput()->addInlineStyle('
			div.oo-ui-panelLayout-padded:nth-of-type(1)
			{
				width: 47%;
				float: left;
			}

			div.oo-ui-panelLayout-padded:nth-of-type(2)
			{
				width: 47%;
				float: right;
			}

			div.oo-ui-panelLayout-padded:nth-of-type(3)
			{
				clear: both;
			}

			.oo-ui-fieldsetLayout-header:nth-of-type(3)
			{
				display:none;
			}

			.mw-htmlform-field-HTMLSubmitField
			{
				display: inline-block;
				margin-right: 1em;
			}

			.success {font-size: 2em; color: green; }
		');
		
		return $form;
	}
	
	/**
	 * @return the URL matching the current state of the form.
	 **/
	protected function getURL()
	{
		$args = ['action' => $this->action];
		
		if($this->source) { $args['source'] = $this->source->name; }
		
		if($this->sourceTitle) { $args['sourceTitle'] = $this->sourceTitle; }
		
		if($this->category) { $args['category'] = $this->category; }
		
		return $this->getPageTitle()->getLinkURL($args);
	}
}
