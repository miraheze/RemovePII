<?php

namespace Miraheze\RemovePII;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\FormSpecialPage;

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
			'help-message' => 'removepii-hash-length-help',
		];

		if ( $this->config->get( 'RemovePIIHashPrefixOptions' ) ) {
			$formDescriptor['prefix'] = [
				'type' => 'select',
				'options' => $this->config->get( 'RemovePIIHashPrefixOptions' ),
				'required' => true,
				'help-message' => 'removepii-hash-prefix-help',
			];
		}

		return $formDescriptor;
	}

	/**
	 * @param array $formData
	 * @return bool
	 */
	public function onSubmit( array $formData ) {
		$out = $this->getOutput();

		$hashPrefix = $formData['prefix'] ?? $this->config->get( 'RemovePIIHashPrefix' );
		$generatedHash = $hashPrefix . substr( sha1( random_bytes( 10 ) ), 0, $formData['length'] );

		$out->addHTML( Html::successBox( $generatedHash ) );

		return false;
	}

	/**
	 * @return bool
	 */
	public function isListed() {
		return $this->userCanExecute( $this->getUser() );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'wikimanage';
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}
}
