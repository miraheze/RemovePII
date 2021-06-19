<?php

namespace Miraheze\RemovePII;

use Config;
use ConfigFactory;
use FormSpecialPage;
use Html;

class SpecialGenerateRandomHash extends FormSpecialPage {
	/** @var Config */
	private $config;

	/**
	 * @param ConfigFactory $configFactory
	 */
	public function __construct( ConfigFactory $configFactory ) {
		parent::__construct( 'GenerateRandomHash', 'generate-random-hash' );

		$this->config = $configFactory->makeConfig( 'RemovePII' );
	}

	/**
	 * @return array
	 */
	protected function getFormFields() {
		$formDescriptor = [];

		$formDescriptor['length'] = [
			'type' => 'int',
			'default' => 32,
			'max' => 32,
			'min' => 8,
			'required' => true,
			'help-message' => 'hash-length-help',
		];

		if ( $this->config->get( 'RemovePIIHashPrefixOptions' ) ) {
			$formDescriptor['prefix'] = [
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
		$out = $this->getOutput();

		$generatedHash = ( $formData['prefix'] ?? $this->config->get( 'RemovePIIHashPrefix' ) ) . substr( sha1( random_bytes( 10 ) ), 0, $formData['length'] );
		$out->addHTML( Html::successBox( $generatedHash ) );
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}
}
