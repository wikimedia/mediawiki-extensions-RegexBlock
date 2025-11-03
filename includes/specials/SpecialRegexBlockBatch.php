<?php

use MediaWiki\Block\BlockUser;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Special page that allows the user to enter a list of regexes to block.
 *
 * Forked from the BulkBlock extension written by WikiTeq on 20 April 2025.
 *
 * @file
 * @date 20 April 2025
 * @author Jack Phoenix
 */
class SpecialRegexBlockBatch extends FormSpecialPage {

	private Language $language;

	public function __construct(
		Language $language
	) {
		parent::__construct( 'RegexBlockBatch', 'regexblock' );
		$this->language = $language;
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		return true;
	}

	/** @inheritDoc */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'users';
	}

	/**
	 * Get an HTMLForm descriptor array
	 *
	 * @return array[]
	 */
	protected function getFormFields(): array {
		return [
			'regexes' => [
				'type' => 'textarea',
				'label-message' => 'regexblock-batch-regexes',
				'rows' => 10,
				'required' => true,
				// Note: filter-callback is weird on HTMLForm::filter, it pretends it allows you
				// to process input into something else than just a string, but in reality the value
				// being later supplied to form inputs directly, thus you only can have it as a string
				//'filter-callback' => [ $this, 'preprocessRegexes' ],
				'validation-callback' => [ $this, 'validateRegexes' ],
				'default' => ''
			],
			'reason' => [
				'type' => 'text',
				'label-message' => 'regexblock-form-reason',
				'required' => true,
				'default' => ''
			],
			'expiry' => [
				'type' => 'select',
				'label-message' => 'block-expiry',
				'required' => true,
				'options' => $this->language->getBlockDurations( false ),
				'default' => 'infinite'
			],
		];
	}

	/**
	 * Sets custom form details
	 *
	 * This is only called when the form is actually visited directly
	 *
	 * @param HTMLForm $form
	 */
	protected function alterForm( HTMLForm $form ) {
		$form->setPreHtml( $this->msg( 'regexblock-batch-intro' )->parse() );
		$form->setId( 'regexblock-batch' );
		$form->setSubmitTextMsg( 'htmlform-submit' ); // @todo FIXME: not the best msg, eh, but whatever
		$form->setSubmitDestructive();
	}

	/**
	 * Process the form on POST submission.
	 * @param array $data
	 *
	 * @return bool
	 */
	public function onSubmit( array $data ): bool {
		return $this->handleFormSubmission( $data );
	}

	/** @inheritDoc */
	public function onSuccess() {
		$this->getOutput()->addWikiMsg( 'returnto', '[[' . SpecialPage::getTitleFor( 'RegexBlockBatch' ) . ']]' );
	}

	/** @inheritDoc */
	public function execute( $par ) {
		parent::execute( $par );
		$this->getOutput()->setPageTitle( $this->msg( 'regexblock-batch' )->text() );
	}

	/**
	 * Handles the form submission, called as submit callback by HTMLForm
	 *
	 * @param array $formData
	 *
	 * @return bool
	 */
	public function handleFormSubmission( array $formData ): bool {
		$output = $this->getOutput();

		// Fetch form data submitted
		$regexes = $formData['regexes'];
		$reason = $formData['reason'];
		$expiry = $formData['expiry'];

		// Process the regexes' list
		$regexes = $this->preprocessRegexes( $regexes );

		// Block the users.
		$successCount = 0;
		$errors = [];

		foreach ( $regexes as $regex ) {
			// Block the regular expression
			$blockResult = $this->doBlock( $regex, $reason, $expiry );
			if ( $blockResult !== true ) {
				$errors[] = $blockResult;
				continue;
			}

			// Increment the success count.
			$successCount++;
		}

		// Show a success message if any
		if ( $successCount ) {
			$output->addHTML(
				Html::rawElement(
					'div',
					[ 'class' => 'successbox' ],
					$this->msg( 'regexblock-batch-success', $successCount )->escaped()
				)
			);
		}

		// Show an errors list if any
		// Note: the $form->formatErrors does not support parametrized messages,
		// so we have to use raw syntax
		if ( !empty( $errors ) ) {
			$output->addHTML(
				Html::rawElement(
					'div',
					[ 'class' => 'errorbox' ],
					$this->msg( 'regexblock-batch-errors' )->escaped() .
					Html::rawElement(
						'ul',
						[],
						Html::rawElement(
							'li',
							[],
							implode( '</li><li>', $errors )
						)
					)
				)
			);
		}

		return true;
	}

	/**
	 * Blocks target regular expression
	 *
	 * @param string $regex
	 * @param string $reason
	 * @param string $expiry
	 *
	 * @return string|true True on success, error message string on failure
	 */
	private function doBlock( $regex, string $reason, string $expiry ) {
		// Create a new block.
		$block = new RegularExpressionDatabaseBlock();
		$block->setTarget( $regex );
		$block->setBlocker( $this->getUser() );
		$block->setReason( $reason );
		$block->setExpiry( BlockUser::parseExpiryInput( $expiry ) );
		$block->isCreateAccountBlocked( true );

		// Save the block to the database.
		try {
			$blockResult = $block->insert();
		} catch ( MWException $e ) {
			return $this->msg( 'regexblock-batch-failed', $regex )->escaped();
		}

		// Unsuccessful block
		if ( $blockResult === false ) {
			return $this->msg( 'regexblock-batch-failed', $regex )->escaped();
		}

		return true;
	}

	/**
	 * Processes the list of regular expressions, removing empty lines and comments.
	 * Converts into an array of strings
	 *
	 * @param string $regexStr
	 *
	 * @return string[] List of regular expressions
	 */
	public function preprocessRegexes( string $regexStr ): array {
		$regexes = trim( $regexStr );
		$regexes = explode( "\n", $regexes );
		$regexes = array_map( 'trim', $regexes );
		$regexes = array_map( 'ucfirst', $regexes );
		return $regexes;
	}

	/**
	 * Validates regexes data input
	 *
	 * @param string $regexes List of regular expressions as a string
	 * @param array $allData All the form data
	 * @param HTMLForm $parent Form object
	 *
	 * @return array|string|true
	 */
	public function validateRegexes( string $regexes, array $allData, HTMLForm $parent ) {
		// HTMLForm logic is weird sometimes
		if ( !$parent->wasSubmitted() ) {
			return true;
		}

		$errors = [];
		$regexes = $this->preprocessRegexes( $regexes );

		// Check if there are any regular expressions to block.
		if ( empty( $regexes ) ) {
			return $this->msg( 'regexblock-batch-no-regexes' )->escaped();
		}

		// Validate out empty or invalid regular expressions.
		foreach ( $regexes as $regex ) {
			// Check if the regex is valid
			if ( !RegexBlockData::isValidRegex( $regex ) ) {
				$errors[] = $this->msg( 'regexblock-batch-invalid-regex', $regex )->escaped();
				continue;
			}

			// Check if the regex is already blocked.
			$block = RegularExpressionDatabaseBlock::newFromTarget( $regex );
			if ( $block && !$block->isExpired() ) {
				$errors[] = $this->msg( 'bulkblock-already-blocked', $regex )->escaped();
			}
		}

		if ( !empty( $errors ) ) {
			return $errors;
		}

		return true;
	}

}
