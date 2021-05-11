<?php
class SpecialGenerateRandomHash extends SpecialPage {
	public function __construct() {
		parent::__construct( 'GenerateRandomHash' );
	}

	public function execute( $subPage ) {
		$output = $this->getOutput();
		$this->setHeaders();

		$generatedHash = 'MirahezeGDPR_' . substr( sha1( random_bytes( 10 ) ), 0, 32 );
		$output->addHTML( $generatedHash );
	}
}
