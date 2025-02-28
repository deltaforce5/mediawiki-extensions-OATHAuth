<?php

namespace MediaWiki\Extension\OATHAuth\HTMLForm;

use MediaWiki\Extension\OATHAuth\Module\TOTP;
use MediaWiki\Logger\LoggerFactory;
use Message;
use MWException;

class TOTPDisableForm extends OATHAuthOOUIHTMLForm {
	/**
	 * Add content to output when operation was successful
	 */
	public function onSuccess() {
		$this->getOutput()->addWikiMsg( 'oathauth-disabledoath' );
	}

	/**
	 * @return array
	 */
	protected function getDescriptors() {
		return [
			'token' => [
				'type' => 'text',
				'label-message' => 'oathauth-entertoken',
				'name' => 'token',
				'required' => true,
				'autofocus' => true,
				'dir' => 'ltr',
				'autocomplete' => 'one-time-code',
				'spellcheck' => false,
			]
		];
	}

	/**
	 * @param array $formData
	 * @return array|bool
	 * @throws MWException
	 */
	public function onSubmit( array $formData ) {
		// Don't increase pingLimiter, instead check for the limit being exceeded.
		if ( $this->oathUser->getUser()->pingLimiter( 'badoath', 0 ) ) {
			// Arbitrary duration given here
			LoggerFactory::getInstance( 'authentication' )->info(
				'OATHAuth {user} rate limited while disabling 2FA from {clientip}', [
					'user' => $this->getUser()->getName(),
					'clientip' => $this->getRequest()->getIP(),
				]
			);
			return [ 'oathauth-throttled', Message::durationParam( 60 ) ];
		}

		$module = $this->oathUser->getModule();
		if (
			( $module instanceof TOTP ) &&
			!$module->verify( $this->oathUser, [ 'token' => $formData['token'] ] )
		) {
			LoggerFactory::getInstance( 'authentication' )->info(
				'OATHAuth {user} failed to provide a correct token while disabling 2FA from {clientip}', [
					'user' => $this->getUser()->getName(),
					'clientip' => $this->getRequest()->getIP(),
				]
			);

			// Increase rate limit counter for failed request
			$this->getUser()->pingLimiter( 'badoath' );

			return [ 'oathauth-failedtovalidateoath' ];
		}

		$this->oathUser->setKeys();
		$this->oathRepo->remove( $this->oathUser, $this->getRequest()->getIP(), true );

		return true;
	}
}
