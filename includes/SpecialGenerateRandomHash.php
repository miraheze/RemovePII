<?php

class SpecialGenerateRandomHash extends FormSpecialPage {
    
	private $config;
    
	public function __construct( ConfigFactory $config ) {
		parent::__construct( 'GenerateRandomHash', 'generate-random-hash' );
		
		$this->config = $config->makeConfig( 'RemovePII' );
	}
	
	protected function getFormFields() {
		$formDescriptor = [];

		$formDescriptor['HashLength'] = [
			'type' => 'int',
			'default' => 32,
			'required' => true,
			'help-message' => 'hash-length-help',
		];

		if ( $this->config->get( 'RemovePIIHashPrefixOptions' ) ) {
			$formDescriptor['HashPrefix'] = [
				'type' => 'select',
				'options' => $this->config->get( 'RemovePIIHashPrefixOptions' ),
				'required' => true,
				'help-message' => 'hash-prefix-help',
			];
		}
		
		return $formDescriptor;
	}

	public function onSubmit( array $formData ) {
		$output = $this->getOutput();
		$this->setHeaders();

		$generatedHash = $formData['HashPrefix'] . substr( sha1( random_bytes( 10 ) ), 0, $formData['HashLength'] );
		$output->addHTML( Html::successBox( $generatedHash ) );
	}
	
	protected function getDisplayFormat() {
		return 'ooui';
	}
}
