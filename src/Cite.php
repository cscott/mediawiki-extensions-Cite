<?php

/**
 * A parser extension that adds two tags, <ref> and <references> for adding
 * citations to pages
 *
 * @ingroup Extensions
 *
 * Documentation
 * @link https://www.mediawiki.org/wiki/Extension:Cite/Cite.php
 *
 * <cite> definition in HTML
 * @link http://www.w3.org/TR/html4/struct/text.html#edef-CITE
 *
 * <cite> definition in XHTML 2.0
 * @link http://www.w3.org/TR/2005/WD-xhtml2-20050527/mod-text.html#edef_text_cite
 *
 * @bug https://phabricator.wikimedia.org/T6579
 *
 * @author Ævar Arnfjörð Bjarmason <avarab@gmail.com>
 * @copyright Copyright © 2005, Ævar Arnfjörð Bjarmason
 * @license GPL-2.0-or-later
 */

namespace Cite;

use Exception;
use Html;
use MediaWiki\MediaWikiServices;
use Parser;
use ParserOptions;
use ParserOutput;
use Sanitizer;
use StripState;

class Cite {

	private const DEFAULT_GROUP = '';

	/**
	 * Maximum storage capacity for the pp_value field of the page_props table. 2^16-1 = 65535 is
	 * the size of a MySQL 'blob' field.
	 * @todo Find a way to retrieve this information from the DBAL
	 */
	public const MAX_STORAGE_LENGTH = 65535;

	/**
	 * Key used for storage in parser output's ExtensionData and ObjectCache
	 */
	public const EXT_DATA_KEY = 'Cite:References';

	/**
	 * Version number in case we change the data structure in the future
	 */
	private const DATA_VERSION_NUMBER = 1;

	/**
	 * Cache duration when parsing a page with references, in seconds. 3,600 seconds = 1 hour.
	 */
	public const CACHE_DURATION_ONPARSE = 3600;

	/**
	 * Wikitext attribute name for Book Referencing.
	 */
	public const BOOK_REF_ATTRIBUTE = 'extends';

	/**
	 * Page property key for the Book Referencing `extends` attribute.
	 */
	public const BOOK_REF_PROPERTY = 'ref-extends';

	/**
	 * Datastructure representing <ref> input, in the format of:
	 * <code>
	 * [
	 * 	'user supplied' => [
	 *		'text' => 'user supplied reference & key',
	 *		'count' => 1, // occurs twice
	 * 		'number' => 1, // The first reference, we want
	 * 		               // all occourances of it to
	 * 		               // use the same number
	 *	],
	 *	0 => [
	 * 		'text' => 'Anonymous reference',
	 * 		'count' => -1,
	 * 	],
	 *	1 => [
	 * 		'text' => 'Another anonymous reference',
	 * 		'count' => -1,
	 * 	],
	 *	'some key' => [
	 *		'text' => 'this one occurs once'
	 *		'count' => 0,
	 * 		'number' => 4
	 *	],
	 *	3 => 'more stuff'
	 * ];
	 * </code>
	 *
	 * This works because:
	 * * PHP's datastructures are guaranteed to be returned in the
	 *   order that things are inserted into them (unless you mess
	 *   with that)
	 * * User supplied keys can't be integers, therefore avoiding
	 *   conflict with anonymous keys
	 *
	 * @var array[][]
	 */
	private $mRefs = [];

	/**
	 * Count for user displayed output (ref[1], ref[2], ...)
	 *
	 * @var int
	 */
	private $mOutCnt = 0;

	/**
	 * @var int[]
	 */
	private $mGroupCnt = [];

	/**
	 * The backlinks, in order, to pass as $3 to
	 * 'cite_references_link_many_format', defined in
	 * 'cite_references_link_many_format_backlink_labels
	 *
	 * @var string[]
	 */
	private $mBacklinkLabels;

	/**
	 * The links to use per group, in order.
	 *
	 * @var (string[]|false)[]
	 */
	private $mLinkLabels = [];

	/**
	 * @var Parser
	 */
	private $mParser;

	/**
	 * @var CiteErrorReporter
	 */
	private $errorReporter;

	/**
	 * True when the ParserAfterParse hook has been called.
	 * Used to avoid doing anything in ParserBeforeTidy.
	 *
	 * @var bool
	 */
	private $mHaveAfterParse = false;

	/**
	 * True when a <ref> tag is being processed.
	 * Used to avoid infinite recursion
	 *
	 * @var bool
	 */
	private $mInCite = false;

	/**
	 * @var null|string The current group name while parsing nested <ref> in <references>. Null when
	 *  parsing <ref> outside of <references>. Warning, an empty string is a valid group name!
	 */
	private $inReferencesGroup = null;

	/**
	 * Error stack used when defining refs in <references>
	 *
	 * @var string[]
	 */
	private $mReferencesErrors = [];

	/**
	 * <ref> call stack
	 * Used to cleanup out of sequence ref calls created by #tag
	 * See description of function rollbackRef.
	 *
	 * @var (array|false)[]
	 */
	private $mRefCallStack = [];

	/**
	 * @var bool
	 */
	private $mBumpRefData = false;

	/**
	 * @param Parser $parser
	 */
	private function rememberParser( Parser $parser ) {
		if ( $parser !== $this->mParser ) {
			$this->mParser = $parser;
			$this->errorReporter = new CiteErrorReporter(
				$parser->getOptions()->getUserLangObj(),
				$parser
			);
		}
	}

	/**
	 * Callback function for <ref>
	 *
	 * @param string|null $text Raw content of the <ref> tag.
	 * @param string[] $argv Arguments
	 * @param Parser $parser
	 *
	 * @return string|false False in case a <ref> tag is not allowed in the current context
	 */
	public function ref( $text, array $argv, Parser $parser ) {
		if ( $this->mInCite ) {
			return false;
		}

		$this->rememberParser( $parser );

		$this->mInCite = true;
		$ret = $this->guardedRef( $text, $argv, $parser );
		$this->mInCite = false;

		// new <ref> tag, we may need to bump the ref data counter
		// to avoid overwriting a previous group
		$this->mBumpRefData = true;

		return $ret;
	}

	/**
	 * @param string|null $text Raw content of the <ref> tag.
	 * @param string[] $argv Arguments
	 * @param Parser $parser
	 *
	 * @throws Exception
	 * @return string
	 */
	private function guardedRef(
		$text,
		array $argv,
		Parser $parser
	) {
		# The key here is the "name" attribute.
		list( $key, $group, $follow, $dir, $extends ) = $this->refArg( $argv );
		// empty string indicate invalid dir
		if ( $dir === '' && $text !== '' ) {
			$text .= $this->errorReporter->wikitext( 'cite_error_ref_invalid_dir', $argv['dir'] );
		}
		# Split these into groups.
		if ( $group === null ) {
			$group = $this->inReferencesGroup ?? self::DEFAULT_GROUP;
		}

		// Tag every page where Book Referencing has been used.  This code and the properties
		// will be removed once the feature is stable.  See T237531.
		if ( $extends ) {
			$parser->getOutput()->setProperty( self::BOOK_REF_PROPERTY, true );
		}

		if ( $this->inReferencesGroup !== null ) {
			$isSectionPreview = $parser->getOptions()->getIsSectionPreview();
			$this->inReferencesGuardedRef( $key, $text, $group, $isSectionPreview );
			return '';
		}

		// @phan-suppress-next-line PhanImpossibleTypeComparison false positive
		if ( $text !== null && trim( $text ) === '' ) {
			# <ref ...></ref>.  This construct is  invalid if
			# it's a contentful ref, but OK if it's a named duplicate and should
			# be equivalent <ref ... />, for compatability with #tag.
			if ( is_string( $key ) && $key !== '' ) {
				$text = null;
			} else {
				$this->mRefCallStack[] = false;
				return $this->errorReporter->html( 'cite_error_ref_no_input' );
			}
		}

		if ( $key === false ) {
			# Invalid attribute in the tag like <ref no_valid_attr="foo" />
			# or name and follow attribute used both in one tag checked in
			# Cite::refArg that returns false for the key then.
			$this->mRefCallStack[] = false;
			return $this->errorReporter->html( 'cite_error_ref_too_many_keys' );
		}

		if ( $text === null && $key === null ) {
			# Something like <ref />; this makes no sense.
			$this->mRefCallStack[] = false;
			return $this->errorReporter->html( 'cite_error_ref_no_key' );
		}

		if ( ctype_digit( $key ) || ctype_digit( $follow ) ) {
			# Numeric names mess up the resulting id's, potentially produ-
			# cing duplicate id's in the XHTML.  The Right Thing To Do
			# would be to mangle them, but it's not really high-priority
			# (and would produce weird id's anyway).

			$this->mRefCallStack[] = false;
			return $this->errorReporter->html( 'cite_error_ref_numeric_key' );
		}

		if ( preg_match(
			'/<ref\b[^<]*?>/',
			preg_replace( '#<([^ ]+?).*?>.*?</\\1 *>|<!--.*?-->#', '', $text )
		) ) {
			# (bug T8199) This most likely implies that someone left off the
			# closing </ref> tag, which will cause the entire article to be
			# eaten up until the next <ref>.  So we bail out early instead.
			# The fancy regex above first tries chopping out anything that
			# looks like a comment or SGML tag, which is a crude way to avoid
			# false alarms for <nowiki>, <pre>, etc.

			# Possible improvement: print the warning, followed by the contents
			# of the <ref> tag.  This way no part of the article will be eaten
			# even temporarily.

			$this->mRefCallStack[] = false;
			return $this->errorReporter->html( 'cite_error_included_ref' );
		}

		if ( is_string( $key ) || is_string( $text ) ) {
			# We don't care about the content: if the key exists, the ref
			# is presumptively valid.  Either it stores a new ref, or re-
			# fers to an existing one.  If it refers to a nonexistent ref,
			# we'll figure that out later.  Likewise it's definitely valid
			# if there's any content, regardless of key.

			return $this->stack( $text, $key, $group, $follow, $argv, $dir, $parser->getStripState() );
		}

		# Not clear how we could get here, but something is probably
		# wrong with the types.  Let's fail fast.
		throw new Exception( 'Invalid $text and/or $key: ' . serialize( [ $text, $key ] ) );
	}

	/**
	 * Deals with references defined in the reference section
	 * <references>
	 * <ref name="foo"> BAR </ref>
	 * </references>
	 *
	 * @param string|false|null $key
	 * @param string|null $text Content from the <ref> tag
	 * @param string $group
	 * @param bool $isSectionPreview
	 */
	private function inReferencesGuardedRef( $key, $text, $group, $isSectionPreview ) {
		if ( $group !== $this->inReferencesGroup ) {
			# <ref> and <references> have conflicting group attributes.
			$this->mReferencesErrors[] =
				$this->errorReporter->html(
					'cite_error_references_group_mismatch',
					Sanitizer::safeEncodeAttribute( $group )
				);
		} elseif ( $text !== '' ) {
			if ( !$isSectionPreview && !isset( $this->mRefs[$group] ) ) {
				# Called with group attribute not defined in text.
				$this->mReferencesErrors[] =
					$this->errorReporter->html(
						'cite_error_references_missing_group',
						Sanitizer::safeEncodeAttribute( $group )
					);
			} elseif ( $key === null || $key === '' ) {
				# <ref> calls inside <references> must be named
				$this->mReferencesErrors[] =
					$this->errorReporter->html( 'cite_error_references_no_key' );
			} elseif ( !$isSectionPreview && !isset( $this->mRefs[$group][$key] ) ) {
				# Called with name attribute not defined in text.
				$this->mReferencesErrors[] = $this->errorReporter->html(
					'cite_error_references_missing_key', Sanitizer::safeEncodeAttribute( $key ) );
			} else {
				if (
					isset( $this->mRefs[$group][$key]['text'] ) &&
					$text !== $this->mRefs[$group][$key]['text']
				) {
					// two refs with same key and different content
					// add error message to the original ref
					$this->mRefs[$group][$key]['text'] .= ' ' . $this->errorReporter->wikitext(
							'cite_error_references_duplicate_key', $key
						);
				} else {
					# Assign the text to corresponding ref
					$this->mRefs[$group][$key]['text'] = $text;
				}
			}
		} else {
			# <ref> called in <references> has no content.
			$this->mReferencesErrors[] = $this->errorReporter->html(
				'cite_error_empty_references_define', Sanitizer::safeEncodeAttribute( $key ) );
		}
	}

	/**
	 * Parse the arguments to the <ref> tag
	 *
	 *  "name" : Key of the reference.
	 *  "group" : Group to which it belongs. Needs to be passed to <references /> too.
	 *  "follow" : If the current reference is the continuation of another, key of that reference.
	 *  "dir" : set direction of text (ltr/rtl)
	 *  "extends": Points to a named reference which serves as the context for this reference.
	 *
	 * @param string[] $argv The argument vector
	 * @return (string|false|null)[] An array with exactly four elements, where each is a string on
	 *  valid input, false on invalid input, or null on no input.
	 * @return-taint tainted
	 */
	private function refArg( array $argv ) {
		global $wgCiteBookReferencing;

		$group = null;
		$key = null;
		$follow = null;
		$dir = null;
		$extends = null;

		if ( isset( $argv['dir'] ) ) {
			// compare the dir attribute value against an explicit whitelist.
			$dir = '';
			$isValidDir = in_array( strtolower( $argv['dir'] ), [ 'ltr', 'rtl' ] );
			if ( $isValidDir ) {
				$dir = Html::expandAttributes( [ 'class' => 'mw-cite-dir-' . strtolower( $argv['dir'] ) ] );
			}

			unset( $argv['dir'] );
		}

		if ( $argv === [] ) {
			// No key
			return [ null, null, false, $dir, null ];
		}

		if ( isset( $argv['follow'] ) &&
			( isset( $argv['name'] ) || isset( $argv[self::BOOK_REF_ATTRIBUTE] ) )
		) {
			return [ false, false, false, false, false ];
		}

		if ( isset( $argv['name'] ) ) {
			// Key given.
			$key = trim( $argv['name'] );
			unset( $argv['name'] );
		}
		if ( isset( $argv['follow'] ) ) {
			// Follow given.
			$follow = trim( $argv['follow'] );
			unset( $argv['follow'] );
		}
		if ( isset( $argv['group'] ) ) {
			// Group given.
			$group = $argv['group'];
			unset( $argv['group'] );
		}
		if ( $wgCiteBookReferencing && isset( $argv[self::BOOK_REF_ATTRIBUTE] ) ) {
			$extends = trim( $argv[self::BOOK_REF_ATTRIBUTE] );
			unset( $argv[self::BOOK_REF_ATTRIBUTE] );
		}

		if ( $argv !== [] ) {
			// Unexpected invalid attribute.
			return [ false, false, false, false, false ];
		}

		return [ $key, $group, $follow, $dir, $extends ];
	}

	/**
	 * Populate $this->mRefs based on input and arguments to <ref>
	 *
	 * @param string|null $text Content from the <ref> tag
	 * @param string|null $key Argument to the <ref> tag as returned by $this->refArg()
	 * @param string $group
	 * @param string|null $follow
	 * @param string[] $call
	 * @param string $dir ref direction
	 * @param StripState $stripState
	 *
	 * @throws Exception
	 * @return string
	 */
	private function stack( $text, $key, $group, $follow, array $call, $dir, StripState $stripState ) {
		if ( !isset( $this->mRefs[$group] ) ) {
			$this->mRefs[$group] = [];
		}
		if ( !isset( $this->mGroupCnt[$group] ) ) {
			$this->mGroupCnt[$group] = 0;
		}
		if ( $follow != null ) {
			if ( isset( $this->mRefs[$group][$follow] ) ) {
				// add text to the note that is being followed
				$this->mRefs[$group][$follow]['text'] .= ' ' . $text;
			} else {
				// insert part of note at the beginning of the group
				$groupsCount = count( $this->mRefs[$group] );
				for ( $k = 0; $k < $groupsCount; $k++ ) {
					if ( !isset( $this->mRefs[$group][$k]['follow'] ) ) {
						break;
					}
				}
				array_splice( $this->mRefs[$group], $k, 0, [ [
					'count' => -1,
					'text' => $text,
					'key' => ++$this->mOutCnt,
					'follow' => $follow,
					'dir' => $dir
				] ] );
				array_splice( $this->mRefCallStack, $k, 0,
					[ [ 'new', $call, $text, $key, $group, $this->mOutCnt ] ] );
			}
			// return an empty string : this is not a reference
			return '';
		}

		if ( $key === null ) {
			$this->mRefs[$group][] = [
				'count' => -1,
				'text' => $text,
				'key' => ++$this->mOutCnt,
				'dir' => $dir
			];
			$this->mRefCallStack[] = [ 'new', $call, $text, $key, $group, $this->mOutCnt ];

			return $this->linkRef( $group, $this->mOutCnt );
		}
		if ( !is_string( $key ) ) {
			throw new Exception( 'Invalid stack key: ' . serialize( $key ) );
		}

		// Valid key with first occurrence
		if ( !isset( $this->mRefs[$group][$key] ) ) {
			$this->mRefs[$group][$key] = [
				'text' => $text,
				'count' => -1,
				'key' => ++$this->mOutCnt,
				'number' => ++$this->mGroupCnt[$group],
				'dir' => $dir
			];
			$action = 'new';
		} elseif ( $this->mRefs[$group][$key]['text'] === null && $text !== '' ) {
			// If no text was set before, use this text
			$this->mRefs[$group][$key]['text'] = $text;
			// Use the dir parameter only from the full definition of a named ref tag
			$this->mRefs[$group][$key]['dir'] = $dir;
			$action = 'assign';
		} else {
			if ( $text != null && $text !== ''
				// T205803 different strip markers might hide the same text
				&& $stripState->unstripBoth( $text )
					!== $stripState->unstripBoth( $this->mRefs[$group][$key]['text'] )
			) {
				// two refs with same key and different text
				// add error message to the original ref
				$this->mRefs[$group][$key]['text'] .= ' ' . $this->errorReporter->wikitext(
					'cite_error_references_duplicate_key', $key
				);
			}
			$action = 'increment';
		}
		$this->mRefCallStack[] = [ $action, $call, $text, $key, $group,
			$this->mRefs[$group][$key]['key'] ];
		return $this->linkRef(
			$group,
			$key,
			$this->mRefs[$group][$key]['key'] . "-" . ++$this->mRefs[$group][$key]['count'],
			$this->mRefs[$group][$key]['number'],
			"-" . $this->mRefs[$group][$key]['key']
		);
	}

	/**
	 * Partially undoes the effect of calls to stack()
	 *
	 * Called by guardedReferences()
	 *
	 * The option to define <ref> within <references> makes the
	 * behavior of <ref> context dependent.  This is normally fine
	 * but certain operations (especially #tag) lead to out-of-order
	 * parser evaluation with the <ref> tags being processed before
	 * their containing <reference> element is read.  This leads to
	 * stack corruption that this function works to fix.
	 *
	 * This function is not a total rollback since some internal
	 * counters remain incremented.  Doing so prevents accidentally
	 * corrupting certain links.
	 *
	 * @param string $type
	 * @param string|null $key
	 * @param string $group
	 * @param int $index
	 */
	private function rollbackRef( $type, $key, $group, $index ) {
		if ( !isset( $this->mRefs[$group] ) ) {
			return;
		}

		if ( $key === null ) {
			foreach ( $this->mRefs[$group] as $k => $v ) {
				if ( $this->mRefs[$group][$k]['key'] === $index ) {
					$key = $k;
					break;
				}
			}
		}

		// Sanity checks that specified element exists.
		if ( $key === null ||
			!isset( $this->mRefs[$group][$key] ) ||
			$this->mRefs[$group][$key]['key'] !== $index
		) {
			return;
		}

		switch ( $type ) {
		case 'new':
			# Rollback the addition of new elements to the stack.
			unset( $this->mRefs[$group][$key] );
			if ( $this->mRefs[$group] === [] ) {
				unset( $this->mRefs[$group] );
				unset( $this->mGroupCnt[$group] );
			}
			break;
		case 'assign':
			# Rollback assignment of text to pre-existing elements.
			$this->mRefs[$group][$key]['text'] = null;
			# continue without break
		case 'increment':
			# Rollback increase in named ref occurrences.
			$this->mRefs[$group][$key]['count']--;
			break;
		}
	}

	/**
	 * Callback function for <references>
	 *
	 * @param string|null $text Raw content of the <references> tag.
	 * @param string[] $argv Arguments
	 * @param Parser $parser
	 *
	 * @return string|false False in case a <references> tag is not allowed in the current context
	 */
	public function references( $text, array $argv, Parser $parser ) {
		if ( $this->mInCite || $this->inReferencesGroup !== null ) {
			return false;
		}

		$this->rememberParser( $parser );
		$ret = $this->guardedReferences( $text, $argv, $parser );
		$this->inReferencesGroup = null;

		return $ret;
	}

	/**
	 * Must only be called from references(). Use that to prevent recursion.
	 *
	 * @param string|null $text Raw content of the <references> tag.
	 * @param string[] $argv
	 * @param Parser $parser
	 *
	 * @return string
	 */
	private function guardedReferences(
		$text,
		array $argv,
		Parser $parser
	) {
		global $wgCiteResponsiveReferences;

		$group = $argv['group'] ?? self::DEFAULT_GROUP;
		unset( $argv['group'] );
		$this->inReferencesGroup = $group;

		if ( strval( $text ) !== '' ) {
			# Detect whether we were sent already rendered <ref>s.
			# Mostly a side effect of using #tag to call references.
			# The following assumes that the parsed <ref>s sent within
			# the <references> block were the most recent calls to
			# <ref>.  This assumption is true for all known use cases,
			# but not strictly enforced by the parser.  It is possible
			# that some unusual combination of #tag, <references> and
			# conditional parser functions could be created that would
			# lead to malformed references here.
			$count = substr_count( $text, Parser::MARKER_PREFIX . "-ref-" );
			$redoStack = [];

			# Undo effects of calling <ref> while unaware of containing <references>
			for ( $i = 1; $i <= $count; $i++ ) {
				if ( !$this->mRefCallStack ) {
					break;
				}

				$call = array_pop( $this->mRefCallStack );
				$redoStack[] = $call;
				if ( $call !== false ) {
					list( $type, $ref_argv, $ref_text,
						$ref_key, $ref_group, $ref_index ) = $call;
					$this->rollbackRef( $type, $ref_key, $ref_group, $ref_index );
				}
			}

			# Rerun <ref> call now that mInReferences is set.
			for ( $i = count( $redoStack ) - 1; $i >= 0; $i-- ) {
				$call = $redoStack[$i];
				if ( $call !== false ) {
					list( $type, $ref_argv, $ref_text,
						$ref_key, $ref_group, $ref_index ) = $call;
					$this->guardedRef( $ref_text, $ref_argv, $parser );
				}
			}

			# Parse $text to process any unparsed <ref> tags.
			$parser->recursiveTagParse( $text );

			# Reset call stack
			$this->mRefCallStack = [];
		}

		if ( isset( $argv['responsive'] ) ) {
			$responsive = $argv['responsive'] !== '0';
			unset( $argv['responsive'] );
		} else {
			$responsive = $wgCiteResponsiveReferences;
		}

		// There are remaining parameters we don't recognise
		if ( $argv ) {
			return $this->errorReporter->html( 'cite_error_references_invalid_parameters' );
		}

		$s = $this->referencesFormat( $group, $responsive );

		# Append errors generated while processing <references>
		if ( $this->mReferencesErrors ) {
			$s .= "\n" . implode( "<br />\n", $this->mReferencesErrors );
			$this->mReferencesErrors = [];
		}
		return $s;
	}

	/**
	 * Make output to be returned from the references() function.
	 *
	 * If called outside of references(), caller is responsible for ensuring
	 * `mInReferences` is enabled before the call and disabled after call.
	 *
	 * @param string $group
	 * @param bool $responsive
	 * @return string HTML ready for output
	 */
	private function referencesFormat( $group, $responsive ) {
		if ( !isset( $this->mRefs[$group] ) ) {
			return '';
		}

		// Add new lines between the list items (ref entires) to avoid confusing tidy (T15073).
		// Note: This builds a string of wikitext, not html.
		$parserInput = "\n";
		foreach ( $this->mRefs[$group] as $key => $value ) {
			$parserInput .= $this->referencesFormatEntry( $key, $value ) . "\n";
		}
		$parserInput = Html::rawElement( 'ol', [ 'class' => [ 'references' ] ], $parserInput );

		// Live hack: parse() adds two newlines on WM, can't reproduce it locally -ævar
		$ret = rtrim( $this->mParser->recursiveTagParse( $parserInput ), "\n" );

		if ( $responsive ) {
			// Use a DIV wrap because column-count on a list directly is broken in Chrome.
			// See https://bugs.chromium.org/p/chromium/issues/detail?id=498730.
			$wrapClasses = [ 'mw-references-wrap' ];
			if ( count( $this->mRefs[$group] ) > 10 ) {
				$wrapClasses[] = 'mw-references-columns';
			}
			$ret = Html::rawElement( 'div', [ 'class' => $wrapClasses ], $ret );
		}

		if ( !$this->mParser->getOptions()->getIsPreview() ) {
			// save references data for later use by LinksUpdate hooks
			$this->saveReferencesData( $this->mParser->getOutput(), $group );
		}

		// done, clean up so we can reuse the group
		unset( $this->mRefs[$group] );
		unset( $this->mGroupCnt[$group] );

		return $ret;
	}

	/**
	 * Format a single entry for the referencesFormat() function
	 *
	 * @param string $key The key of the reference
	 * @param array $val A single reference as documented at {@see $mRefs}
	 * @return string Wikitext, wrapped in a single <li> element
	 */
	private function referencesFormatEntry( $key, array $val ) {
		$text = $this->referenceText( $key, $val['text'] );

		// Fallback for a broken, and therefore unprocessed follow="…". Note this returns a <p>, not
		// an <li> as expected!
		if ( isset( $val['follow'] ) ) {
			return wfMessage(
				'cite_references_no_link',
				$this->normalizeKey(
					self::getReferencesKey( $val['follow'] )
				),
				$text
			)->inContentLanguage()->plain();
		}

		if ( !isset( $val['count'] ) ) {
			// this handles the case of section preview for list-defined references
			return wfMessage(
				'cite_references_link_many',
				$this->normalizeKey(
					self::getReferencesKey( $key . "-" . ( $val['key'] ?? '' ) )
				),
				'',
				$text
			)->inContentLanguage()->plain();
		}

		// This counts the number of reuses. 0 means the reference appears only 1 time.
		if ( $val['count'] < 1 ) {
			// Anonymous, auto-numbered references can't be reused and get marked with a -1.
			if ( $val['count'] < 0 ) {
				$id = $val['key'];
				$backlinkId = $this->refKey( $val['key'] );
			} else {
				$id = $key . '-' . $val['key'];
				$backlinkId = $this->refKey( $key, $val['key'] . '-' . $val['count'] );
			}
			return wfMessage(
				'cite_references_link_one',
				$this->normalizeKey( self::getReferencesKey( $id ) ),
				$this->normalizeKey( $backlinkId ),
				$text,
				$val['dir']
			)->inContentLanguage()->plain();
		}

		// Named references with >1 occurrences
		$backlinks = [];
		// for group handling, we have an extra key here.
		for ( $i = 0; $i <= $val['count']; ++$i ) {
			$backlinks[] = wfMessage(
				'cite_references_link_many_format',
				$this->normalizeKey(
					$this->refKey( $key, $val['key'] . "-$i" )
				),
				$this->referencesFormatEntryNumericBacklinkLabel( $val['number'], $i, $val['count'] ),
				$this->referencesFormatEntryAlternateBacklinkLabel( $i )
			)->inContentLanguage()->plain();
		}

		return wfMessage( 'cite_references_link_many',
			$this->normalizeKey(
				self::getReferencesKey( $key . "-" . $val['key'] )
			),
			$this->listToText( $backlinks ),
			$text,
			$val['dir']
		)->inContentLanguage()->plain();
	}

	/**
	 * Returns formatted reference text
	 * @param string $key
	 * @param string|null $text
	 * @return string
	 */
	private function referenceText( $key, $text ) {
		if ( trim( $text ) === '' ) {
			if ( $this->mParser->getOptions()->getIsSectionPreview() ) {
				return $this->errorReporter->wikitext( 'cite_warning_sectionpreview_no_text', $key );
			}
			return $this->errorReporter->wikitext( 'cite_error_references_no_text', $key );
		}
		return '<span class="reference-text">' . rtrim( $text, "\n" ) . "</span>\n";
	}

	/**
	 * Generate a numeric backlink given a base number and an
	 * offset, e.g. $base = 1, $offset = 2; = 1.2
	 * Since bug #5525, it correctly does 1.9 -> 1.10 as well as 1.099 -> 1.100
	 *
	 * @param int $base
	 * @param int $offset
	 * @param int $max Maximum value expected.
	 * @return string
	 */
	private function referencesFormatEntryNumericBacklinkLabel( $base, $offset, $max ) {
		$scope = strlen( $max );
		$ret = MediaWikiServices::getInstance()->getContentLanguage()->formatNum(
			sprintf( "%s.%0{$scope}s", $base, $offset )
		);
		return $ret;
	}

	/**
	 * Generate a custom format backlink given an offset, e.g.
	 * $offset = 2; = c if $this->mBacklinkLabels = [ 'a',
	 * 'b', 'c', ...]. Return an error if the offset > the # of
	 * array items
	 *
	 * @param int $offset
	 *
	 * @return string
	 */
	private function referencesFormatEntryAlternateBacklinkLabel( $offset ) {
		if ( !isset( $this->mBacklinkLabels ) ) {
			$this->genBacklinkLabels();
		}
		return $this->mBacklinkLabels[$offset]
			?? $this->errorReporter->wikitext( 'cite_error_references_no_backlink_label', null );
	}

	/**
	 * Generate a custom format link for a group given an offset, e.g.
	 * the second <ref group="foo"> is b if $this->mLinkLabels["foo"] =
	 * [ 'a', 'b', 'c', ...].
	 * Return an error if the offset > the # of array items
	 *
	 * @param int $offset
	 * @param string $group The group name
	 * @param string $label The text to use if there's no message for them.
	 *
	 * @return string
	 */
	private function getLinkLabel( $offset, $group, $label ) {
		$message = "cite_link_label_group-$group";
		if ( !isset( $this->mLinkLabels[$group] ) ) {
			$this->genLinkLabels( $group, $message );
		}
		if ( $this->mLinkLabels[$group] === false ) {
			// Use normal representation, ie. "$group 1", "$group 2"...
			return $label;
		}

		return $this->mLinkLabels[$group][$offset - 1]
			?? $this->errorReporter->wikitext( 'cite_error_no_link_label_group', [ $group, $message ] );
	}

	/**
	 * Return an id for use in wikitext output based on a key and
	 * optionally the number of it, used in <references>, not <ref>
	 * (since otherwise it would link to itself)
	 *
	 * @param string $key
	 * @param int|null $num The number of the key
	 * @return string A key for use in wikitext
	 */
	private function refKey( $key, $num = null ) {
		$prefix = wfMessage( 'cite_reference_link_prefix' )->inContentLanguage()->text();
		$suffix = wfMessage( 'cite_reference_link_suffix' )->inContentLanguage()->text();
		if ( $num !== null ) {
			$key = wfMessage( 'cite_reference_link_key_with_num', $key, $num )
				->inContentLanguage()->plain();
		}

		return "$prefix$key$suffix";
	}

	/**
	 * Return an id for use in wikitext output based on a key and
	 * optionally the number of it, used in <ref>, not <references>
	 * (since otherwise it would link to itself)
	 *
	 * @param string $key
	 * @return string A key for use in wikitext
	 */
	public static function getReferencesKey( $key ) {
		$prefix = wfMessage( 'cite_references_link_prefix' )->inContentLanguage()->text();
		$suffix = wfMessage( 'cite_references_link_suffix' )->inContentLanguage()->text();

		return "$prefix$key$suffix";
	}

	/**
	 * Generate a link (<sup ...) for the <ref> element from a key
	 * and return XHTML ready for output
	 *
	 * @suppress SecurityCheck-DoubleEscaped
	 * @param string $group
	 * @param string $key The key for the link
	 * @param int|null $count The index of the key, used for distinguishing
	 *                   multiple occurrences of the same key
	 * @param int|null $label The label to use for the link, I want to
	 *                   use the same label for all occourances of
	 *                   the same named reference.
	 * @param string $subkey
	 *
	 * @return string
	 */
	private function linkRef( $group, $key, $count = null, $label = null, $subkey = '' ) {
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();

		if ( $label === null ) {
			$label = ++$this->mGroupCnt[$group];
		}

		return $this->mParser->recursiveTagParse(
				wfMessage(
					'cite_reference_link',
					$this->normalizeKey(
						$this->refKey( $key, $count )
					),
					$this->normalizeKey(
						self::getReferencesKey( $key . $subkey )
					),
					Sanitizer::safeEncodeAttribute(
						$this->getLinkLabel( $label, $group,
							( ( $group === self::DEFAULT_GROUP ) ? '' : "$group " ) . $contLang->formatNum( $label ) )
					)
				)->inContentLanguage()->plain()
			);
	}

	/**
	 * Normalizes and sanitizes a reference key
	 *
	 * @param string $key
	 * @return string
	 */
	private function normalizeKey( $key ) {
		$ret = Sanitizer::escapeIdForAttribute( $key );
		$ret = preg_replace( '/__+/', '_', $ret );
		$ret = Sanitizer::safeEncodeAttribute( $ret );

		return $ret;
	}

	/**
	 * This does approximately the same thing as
	 * Language::listToText() but due to this being used for a
	 * slightly different purpose (people might not want , as the
	 * first separator and not 'and' as the second, and this has to
	 * use messages from the content language) I'm rolling my own.
	 *
	 * @param string[] $arr The array to format
	 * @return string
	 */
	private function listToText( array $arr ) {
		$lastElement = array_pop( $arr );

		if ( $arr === [] ) {
			return (string)$lastElement;
		}

		$sep = wfMessage( 'cite_references_link_many_sep' )->inContentLanguage()->plain();
		$and = wfMessage( 'cite_references_link_many_and' )->inContentLanguage()->plain();
		return implode( $sep, $arr ) . $and . $lastElement;
	}

	/**
	 * Generate the labels to pass to the
	 * 'cite_references_link_many_format' message, the format is an
	 * arbitrary number of tokens separated by whitespace.
	 */
	private function genBacklinkLabels() {
		$text = wfMessage( 'cite_references_link_many_format_backlink_labels' )
			->inContentLanguage()->plain();
		$this->mBacklinkLabels = preg_split( '/\s+/', $text );
	}

	/**
	 * Generate the labels to pass to the
	 * 'cite_reference_link' message instead of numbers, the format is an
	 * arbitrary number of tokens separated by whitespace.
	 *
	 * @param string $group
	 * @param string $message
	 */
	private function genLinkLabels( $group, $message ) {
		$text = false;
		$msg = wfMessage( $message )->inContentLanguage();
		if ( $msg->exists() ) {
			$text = $msg->plain();
		}
		$this->mLinkLabels[$group] = $text ? preg_split( '/\s+/', $text ) : false;
	}

	/**
	 * Gets run when Parser::clearState() gets run, since we don't
	 * want the counts to transcend pages and other instances
	 *
	 * @param string $force Set to "force" to interrupt parsing
	 */
	public function clearState( $force = '' ) {
		if ( $force === 'force' ) {
			$this->mInCite = false;
			$this->inReferencesGroup = null;
		} elseif ( $this->mInCite || $this->inReferencesGroup !== null ) {
			// Don't clear when we're in the middle of parsing a <ref> or <references> tag
			return;
		}

		$this->mGroupCnt = [];
		$this->mOutCnt = 0;
		$this->mRefs = [];
		$this->mReferencesErrors = [];
		$this->mRefCallStack = [];
	}

	/**
	 * Called at the end of page processing to append a default references
	 * section, if refs were used without a main references tag. If there are references
	 * in a custom group, and there is no references tag for it, show an error
	 * message for that group.
	 * If we are processing a section preview, this adds the missing
	 * references tags and does not add the errors.
	 *
	 * @param bool $afterParse True if called from the ParserAfterParse hook
	 * @param ParserOptions $parserOptions
	 * @param ParserOutput $parserOutput
	 * @param string &$text
	 */
	public function checkRefsNoReferences(
		$afterParse,
		ParserOptions $parserOptions,
		ParserOutput $parserOutput,
		&$text
	) {
		global $wgCiteResponsiveReferences;

		if ( $afterParse ) {
			$this->mHaveAfterParse = true;
		} elseif ( $this->mHaveAfterParse ) {
			return;
		}

		if ( !$parserOptions->getIsPreview() ) {
			// save references data for later use by LinksUpdate hooks
			if ( $this->mRefs && isset( $this->mRefs[self::DEFAULT_GROUP] ) ) {
				$this->saveReferencesData( $parserOutput );
			}
			$isSectionPreview = false;
		} else {
			$isSectionPreview = $parserOptions->getIsSectionPreview();
		}

		$s = '';
		foreach ( $this->mRefs as $group => $refs ) {
			if ( !$refs ) {
				continue;
			}
			if ( $group === self::DEFAULT_GROUP || $isSectionPreview ) {
				$this->inReferencesGroup = $group;
				$s .= $this->referencesFormat( $group, $wgCiteResponsiveReferences );
				$this->inReferencesGroup = null;
			} else {
				$s .= "\n<br />" .
					$this->errorReporter->html(
						'cite_error_group_refs_without_references',
						Sanitizer::safeEncodeAttribute( $group )
					);
			}
		}
		if ( $isSectionPreview && $s !== '' ) {
			// provide a preview of references in its own section
			$text .= "\n" . '<div class="mw-ext-cite-cite_section_preview_references" >';
			$headerMsg = wfMessage( 'cite_section_preview_references' );
			if ( !$headerMsg->isDisabled() ) {
				$text .= '<h2 id="mw-ext-cite-cite_section_preview_references_header" >'
				. $headerMsg->escaped()
				. '</h2>';
			}
			$text .= $s . '</div>';
		} else {
			$text .= $s;
		}
	}

	/**
	 * Saves references in parser extension data
	 * This is called by each <references/> tag, and by checkRefsNoReferences
	 * Assumes $this->mRefs[$group] is set
	 *
	 * @param ParserOutput $parserOutput
	 * @param string $group
	 */
	private function saveReferencesData( ParserOutput $parserOutput, $group = self::DEFAULT_GROUP ) {
		global $wgCiteStoreReferencesData;
		if ( !$wgCiteStoreReferencesData ) {
			return;
		}
		$savedRefs = $parserOutput->getExtensionData( self::EXT_DATA_KEY );
		if ( $savedRefs === null ) {
			// Initialize array structure
			$savedRefs = [
				'refs' => [],
				'version' => self::DATA_VERSION_NUMBER,
			];
		}
		if ( $this->mBumpRefData ) {
			// This handles pages with multiple <references/> tags with <ref> tags in between.
			// On those, a group can appear several times, so we need to avoid overwriting
			// a previous appearance.
			$savedRefs['refs'][] = [];
			$this->mBumpRefData = false;
		}
		$n = count( $savedRefs['refs'] ) - 1;
		// save group
		$savedRefs['refs'][$n][$group] = $this->mRefs[$group];

		$parserOutput->setExtensionData( self::EXT_DATA_KEY, $savedRefs );
	}

}