<?php

namespace Miraheze\RemovePII;

use ConfigFactory;
use FormSpecialPage;
use Html;

class SpecialGenerateRandomHash extends FormSpecialPage {
	/** @var ConfigFactory */
	private $config;

	/**
	 * @param ConfigFactory $config
	 */
	public function __construct( ConfigFactory $config ) {
		parent::__construct( 'GenerateRandomHash', 'generate-random-hash' );

		$this->config = $config->makeConfig( 'RemovePII' );
	}

	/**
	 * @return array
	 */
	protected function getFormFields() {
		$formDescriptor = [];

		$formDescriptor['HashLength'] = [
			'type' => 'int',
			'default' => 32,
			'max' => 32,
			'min' => 8,
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

	/**
	 * @param array $formData
	 */
	public function onSubmit( array $formData ) {
		$output = $this->getOutput();
		$this->setHeaders();

		$generatedHash = ( $formData['HashPrefix'] ?? $this->config->get( 'RemovePIIHashPrefix' ) ) . substr( sha1( random_bytes( 10 ) ), 0, $formData['HashLength'] );
		$output->addHTML( Html::successBox( $generatedHash ) );
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}
}
