<?php

/**
 * Page lockdown
 *
 * Copyright © 2005 Brion Vibber <brion@pobox.com>
 * https://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Extension\AspaklaryaLockDown;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Context\IContextSource;
use Language;
use LogEventsList;
use LogPage;
use MediaWiki\CommentStore\CommentStore;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Request\WebRequest;
use MediaWiki\Title\Title;
use MediaWiki\Watchlist\WatchlistManager;
use WikiPage;
use Xml;

/**
 * Handles the page lockdown UI and backend
 */
class ALockDownForm {

	/** The custom/additional lockdown reason */
	protected string $mReason = '';

	/** The reason selected from the list, blank for other/additional */
	protected string $mReasonSelection = '';

	/** Permissions errors for the lockdown action */
	protected PermissionStatus $mPermStatus;

	protected Title $mTitle;
	protected bool $disabled;
	private IContextSource $mContext;
	private WebRequest $mRequest;
	private Authority $mPerformer;
	private Language $mLang;
	private OutputPage $mOut;
	private WatchlistManager $watchlistManager;
	private string $mCurrent;
	private Main $main;

	/**
	 * @param WikiPage $article
	 * @param IContextSource $context
	 */
	public function __construct( WikiPage $article, IContextSource $context ) {
		$this->mTitle = $article->getTitle();
		$this->mContext = $context;
		$this->mRequest = $this->mContext->getRequest();
		$this->mPerformer = $this->mContext->getAuthority();
		$this->mOut = $this->mContext->getOutput();
		$this->mLang = $this->mContext->getLanguage();

		$services = MediaWikiServices::getInstance();

		$this->watchlistManager = $services->getWatchlistManager();

		// Check if the form should be disabled.
		// If it is, the form will be available in read-only to show levels.
		$this->mPermStatus = PermissionStatus::newEmpty();
		if ( $this->mRequest->wasPosted() ) {
			$this->mPerformer->authorizeWrite( 'aspaklarya_lockdown', $this->mTitle, $this->mPermStatus );
		} else {
			$this->mPerformer->authorizeRead( 'aspaklarya_lockdown', $this->mTitle, $this->mPermStatus );
		}
		$readOnlyMode = $services->getReadOnlyMode();
		if ( $readOnlyMode->isReadOnly() ) {
			$this->mPermStatus->fatal( 'readonlytext', $readOnlyMode->getReason() );
		}
		$this->disabled = !$this->mPermStatus->isGood();

		$this->mReason = $this->mRequest->getText( 'aLockdown-reason' );
		$this->mReasonSelection = $this->mRequest->getText( 'aLockdownReasonSelection' );
		$loadBalancer = $services->getDBLoadBalancer();
		$mainObjectCache = $services->getMainWANObjectCache();
		$this->main = new Main( $loadBalancer, $mainObjectCache, $this->mTitle, $this->mPerformer->getUser() );
		$this->mCurrent = $this->main->getLevel();
	}

	/**
	 * Main entry point for action=protect and action=unprotect
	 */
	public function execute() {
		if ( $this->mRequest->wasPosted() ) {
			if ( $this->save() ) {
				$q = $this->mTitle->isRedirect() ? 'redirect=no' : '';
				$this->mOut->redirect( $this->mTitle->getFullURL( $q ) );
			}
		} else {
			$this->show();
		}
	}

	/**
	 * Show the input form with optional error message
	 *
	 * @param string|string[]|null $err Error message or null if there's no error
	 * @phan-param string|non-empty-array|null $err
	 */
	private function show( $err = null ) {
		$out = $this->mOut;
		$out->setRobotPolicy( 'noindex,nofollow' );
		$out->addBacklinkSubtitle( $this->mTitle );

		if ( is_array( $err ) ) {
			$out->addHTML( Html::errorBox( $out->msg( ...$err )->parse() ) );
		} elseif ( is_string( $err ) ) {
			$out->addHTML( Html::errorBox( $err ) );
		}

		# Show an appropriate message if the user isn't allowed or able to change
		# the lockdown settings at this time
		if ( $this->disabled ) {
			$out->setPageTitle(
				$this->mContext->msg(
					'aspaklarya_lockdown-title-notallowed',
					$this->mTitle->getPrefixedText()
				)
			);
			$out->addWikiTextAsInterface(
				$out->formatPermissionStatus( $this->mPermStatus, 'aspaklarya_lockdown' )
			);
		} else {
			$out->setPageTitle(
				$this->mContext->msg( 'aspaklarya_lockdown-title', $this->mTitle->getPrefixedText() )
			);
			$out->addWikiMsg(
				'aspaklarya_lockdown-text',
				wfEscapeWikiText( $this->mTitle->getPrefixedText() )
			);
		}

		$out->addHTML( $this->buildForm() );
		$this->showLogExtract();
	}

	/**
	 * Save submitted protection form
	 *
	 * @return bool Success
	 */
	private function save() {
		# Permission check!
		if ( $this->disabled ) {
			$this->show();
			return false;
		}

		$token = $this->mRequest->getVal( 'wpEditToken' );
		$legacyUser = MediaWikiServices::getInstance()
			->getUserFactory()
			->newFromAuthority( $this->mPerformer );
		if ( !$legacyUser->matchEditToken( $token, [ 'aspaklarya_lockdown', $this->mTitle->getPrefixedDBkey() ] ) ) {
			$this->show( [ 'sessionfailure' ] );
			return false;
		}

		# Create reason string. Use list and/or custom string.
		$reasonstr = $this->mReasonSelection;
		if ( $reasonstr != 'other' && $this->mReason != '' ) {
			// Entry from drop down menu + additional comment
			$reasonstr .= $this->mContext->msg( 'colon-separator' )->text() . $this->mReason;
		} elseif ( $reasonstr == 'other' ) {
			$reasonstr = $this->mReason;
		}

		$status = $this->main->doUpdateRestrictions(
			$this->mRequest->getText( 'mwProtect-level-aspaklarya' ),
			"$reasonstr",
		);

		if ( !$status->isOK() ) {
			$this->show( $this->mOut->parseInlineAsInterface(
				$status->getWikiText( false, false, $this->mLang )
			) );
			return false;
		}

		$this->watchlistManager->setWatch(
			$this->mRequest->getCheck( 'aLockdownWatch' ),
			$this->mPerformer,
			$this->mTitle
		);

		return true;
	}

	/**
	 * Build the input form
	 *
	 * @return string HTML form
	 */
	private function buildForm() {
		$this->mOut->enableOOUI();
		$out = '';
		$fields = [];
		$levels = Main::getApplicableTypes( $this->mTitle->getId() > 0 );

		$section = 'restriction-aspaklarya';
		$id = 'mwProtect-level-aspaklarya';
		$options = [];
		foreach ( $levels as $key ) {
			$options[$this->getOptionLabel( $key )] = $key;
		}

		$fields[$id] = [
			'type' => 'select',
			'name' => $id,
			'default' => $options[$this->getOptionLabel( $this->mCurrent )],
			'id' => $id,
			'size' => count( $levels ),
			'options' => $options,
			'disabled' => $this->disabled,
			'section' => $section,
		];

		# Add manual and custom reason field/selects as well as submit
		if ( !$this->disabled ) {
			// HTML maxlength uses "UTF-16 code units", which means that characters outside BMP
			// (e.g. emojis) count for two each. This limit is overridden in JS to instead count
			// Unicode codepoints.
			// Subtract arbitrary 75 to leave some space for the autogenerated null edit's summary
			// and other texts chosen by dropdown menus on this page.
			$maxlength = CommentStore::COMMENT_CHARACTER_LIMIT - 75;
			$fields['aLockdownReasonSelection'] = [
				'type' => 'select',
				'cssclass' => 'aLockdown-reason',
				'label' => $this->mContext->msg( 'aLockdowncomment' )->text(),
				'tabindex' => 4,
				'id' => 'aLockdownReasonSelection',
				'name' => 'aLockdownReasonSelection',
				'flatlist' => true,
				'options' => Html::listDropDownOptions(
					$this->mContext->msg( 'aLockdown-dropdown' )->inContentLanguage()->text(),
					[ 'other' => $this->mContext->msg( 'aLockdown-otherreason-op' )->inContentLanguage()->text() ]
				),
				'default' => $this->mReasonSelection,
			];
			$fields['aLockdown-reason'] = [
				'type' => 'text',
				'id' => 'aLockdown-reason',
				'label' => $this->mContext->msg( 'aLockdown-otherreason' )->text(),
				'name' => 'aLockdown-reason',
				'size' => 60,
				'maxlength' => $maxlength,
				'default' => $this->mReason,
			];
			# Disallow watching if user is not logged in
			if ( $this->mPerformer->getUser()->isRegistered() ) {
				$fields['aLockdownWatch'] = [
					'type' => 'check',
					'id' => 'aLockdownWatch',
					'label' => $this->mContext->msg( 'watchthis' )->text(),
					'name' => 'aLockdownWatch',
					'default' => (
						$this->watchlistManager->isWatched( $this->mPerformer, $this->mTitle )
						|| MediaWikiServices::getInstance()->getUserOptionsLookup()->getOption(
							$this->mPerformer->getUser(),
							'watchdefault'
						)
					),
				];
			}
		}

		if ( $this->mPerformer->isAllowed( 'editinterface' ) ) {
			$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
			$link = $linkRenderer->makeKnownLink(
				$this->mContext->msg( 'aLockdown-dropdown' )->inContentLanguage()->getTitle(),
				$this->mContext->msg( 'aLockdown-edit-reasonlist' )->text(),
				[],
				[ 'action' => 'edit' ]
			);
			$out .= '<p class="mw-aLockdown-editreasons">' . $link . '</p>';
		}

		$htmlForm = HTMLForm::factory( 'ooui', $fields, $this->mContext );
		$htmlForm
			->setMethod( 'post' )
			->setId( 'mw-ALockdown-Form' )
			->setTableId( 'mw-aLockdown-table2' )
			->setAction( $this->mTitle->getLocalURL( 'action=aspaklarya_lockdown' ) )
			->setSubmitID( 'mw-ALockdown-submit' )
			->setSubmitTextMsg( 'confirm' )
			->setTokenSalt( [ 'aspaklarya_lockdown', $this->mTitle->getPrefixedDBkey() ] )
			->suppressDefaultSubmit( $this->disabled )
			->setWrapperLegendMsg( 'aLockdown-legend' )
			->prepareForm();

		return $htmlForm->getHTML( false ) . $out;
	}

	/**
	 * Prepare the label for a lockdown selector option
	 *
	 * @param string $limit limit required
	 * @return string
	 */
	private function getOptionLabel( $limit ) {
		if ( $limit == '' ) {
			return $this->mContext->msg( 'aspaklarya-default' )->text();
		}
		$msg = $this->mContext->msg( "aspaklarya-level-{$limit}" );
		if ( $msg->exists() ) {
			return $msg->text();
		}
		return $this->mContext->msg( 'aspaklarya-fallback', $limit )->text();
	}

	/**
	 * Show aspaklarya long extracts for this page
	 */
	private function showLogExtract() {
		# Show relevant lines from the protection log:
		$aLockdownLogPage = new LogPage( 'aspaklarya' );
		$this->mOut->addHTML( Xml::element( 'h2', null, $aLockdownLogPage->getName()->text() ) );
		/** @phan-suppress-next-line PhanTypeMismatchPropertyByRef */
		LogEventsList::showLogExtract( $this->mOut, 'aspaklarya', $this->mTitle );
	}

}
