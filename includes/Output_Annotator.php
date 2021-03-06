<?php
/**
 * Output_Annotator Class.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/GoogleChromeLabs/wp-origination
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @copyright 2019 Google LLC
 */

namespace Google\WP_Origination;

/**
 * Class Output_Annotator.
 */
class Output_Annotator {

	/**
	 * Identifier used to signify annotation comments.
	 *
	 * @var string
	 */
	const ANNOTATION_TAG = 'origination';

	/**
	 * Identifier used to signify invocation annotation comments.
	 *
	 * @var string
	 */
	const INVOCATION_ANNOTATION_PLACEHOLDER_TAG = 'origination_invocation';

	/**
	 * Identifier used to signify dependency (scripts & styles) annotation comments.
	 *
	 * @var string
	 */
	const DEPENDENCY_ANNOTATION_PLACEHOLDER_TAG = 'origination_dependency';

	/**
	 * Identifier used to signify (static) block annotation comments.
	 *
	 * @var string
	 */
	const BLOCK_ANNOTATION_PLACEHOLDER_TAG = 'origination_block';

	/**
	 * Identifier used to signify oEmbed annotation comments.
	 *
	 * @var string
	 */
	const OEMBED_ANNOTATION_PLACEHOLDER_TAG = 'origination_oembed';

	/**
	 * Opening annotation type (start tag).
	 */
	const OPEN_ANNOTATION = 0;

	/**
	 * Closing annotation type (end tag).
	 */
	const CLOSE_ANNOTATION = 1;

	/**
	 * Empty annotation type (self-closing).
	 */
	const SELF_CLOSING_ANNOTATION = 2;

	/**
	 * Instance of Invocation_Watcher.
	 *
	 * @var Invocation_Watcher
	 */
	public $invocation_watcher;

	/**
	 * Instance of Dependencies.
	 *
	 * @var Dependencies
	 */
	public $dependencies;

	/**
	 * Instance of Incrementor.
	 *
	 * @var Incrementor
	 */
	public $incrementor;

	/**
	 * Instance of Block_Recognizer.
	 *
	 * @var Block_Recognizer
	 */
	public $block_recognizer;

	/**
	 * Callback to determine whether to show queries.
	 *
	 * This is called once at shutdown to populate `$show_queries`.
	 *
	 * @var callback
	 */
	public $can_show_queries_callback = '__return_false';

	/**
	 * Whether to show queries.
	 *
	 * @var bool
	 */
	protected $show_queries = false;

	/**
	 * Pending dependency annotations.
	 *
	 * @var array
	 */
	protected $pending_dependency_annotations = [];

	/**
	 * Pending (static) block annotations.
	 *
	 * @var array
	 */
	protected $pending_block_annotations = [];

	/**
	 * Pending oEmbed annotations.
	 *
	 * @var array
	 */
	protected $pending_oembed_annotations = [];

	/**
	 * Output_Annotator constructor.
	 *
	 * @param Dependencies     $dependencies     Dependencies.
	 * @param Incrementor      $incrementor      Incrementor.
	 * @param Block_Recognizer $block_recognizer Block recognizer.
	 * @param array            $options          Options.
	 */
	public function __construct( Dependencies $dependencies, Incrementor $incrementor, Block_Recognizer $block_recognizer, $options ) {
		foreach ( $options as $key => $value ) {
			$this->$key = $value;
		}
		$this->dependencies     = $dependencies;
		$this->incrementor      = $incrementor;
		$this->block_recognizer = $block_recognizer;
	}

	/**
	 * Set invocation watcher.
	 *
	 * @param Invocation_Watcher $invocation_watcher Invocation watcher.
	 */
	public function set_invocation_watcher( Invocation_Watcher $invocation_watcher ) {
		$this->invocation_watcher = $invocation_watcher;
	}

	/**
	 * Get placeholder annotation pattern.
	 *
	 * Pattern assumes that regex delimiter will be '#'.
	 *
	 * Note that placeholder annotations do not include self-closing annotations because those are only added at the end
	 * in `\Google\WP_Origination\Output_Annotator::finish()` if it is determined that they have not already been annotated.
	 *
	 * @return string Pattern.
	 */
	public function get_placeholder_annotation_pattern() {
		return sprintf(
			'<!-- (?P<closing>/)?(?P<type>%s) (?P<index>\d+) -->',
			static::INVOCATION_ANNOTATION_PLACEHOLDER_TAG . '|' . static::DEPENDENCY_ANNOTATION_PLACEHOLDER_TAG . '|' . static::BLOCK_ANNOTATION_PLACEHOLDER_TAG . '|' . static::OEMBED_ANNOTATION_PLACEHOLDER_TAG
		);
	}

	/**
	 * Start.
	 *
	 * @param bool $lock_buffer Whether buffer is locked (can be flushed/erased/cancelled).
	 */
	public function start( $lock_buffer = true ) {

		// Output buffer so that Server-Timing headers can be sent, and prevent plugins from flushing it.
		ob_start(
			[ $this, 'finish' ],
			null,
			$lock_buffer ? 0 : PHP_OUTPUT_HANDLER_STDFLAGS
		);

		// Prevent PHP Notice: ob_end_flush(): failed to send buffer.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

		/*
		 * Note that the PHP_INT_MAX-1 priority is used to prevent type annotations from being corrupted by user filters.
		 * The filter annotations will still appear inside of any filter annotations because they get added at PHP_INT_MAX.
		 */
		$priority = PHP_INT_MAX - 1; // One less than max so that \Google\WP_Origination\Invocation_Watcher::after_all_hook_callbacks() can run after.
		add_filter( 'script_loader_tag', [ $this, 'add_enqueued_script_annotation' ], $priority, 2 );
		add_filter( 'style_loader_tag', [ $this, 'add_enqueued_style_annotation' ], $priority, 2 );
		add_filter( 'render_block', [ $this, 'add_static_block_annotation' ], $priority, 2 );
		add_filter( 'embed_handler_html', [ $this, 'add_oembed_annotation' ], $priority, 3 );
		add_filter( 'embed_oembed_html', [ $this, 'add_oembed_annotation' ], $priority, 3 );
	}

	/**
	 * Print annotation placeholder before an printing invoked callback.
	 *
	 * @param Invocation $invocation Invocation.
	 * @return string Before placeholder annotation HTML comment.
	 */
	public function get_before_invocation_placeholder_annotation( Invocation $invocation ) {
		return sprintf( '<!-- %s %d -->', static::INVOCATION_ANNOTATION_PLACEHOLDER_TAG, $invocation->index );
	}

	/**
	 * Print annotation placeholder after an printing invoked callback.
	 *
	 * @param Invocation $invocation Invocation.
	 * @return string After placeholder annotation HTML comment.
	 */
	public function get_after_invocation_placeholder_annotation( Invocation $invocation ) {
		return sprintf( '<!-- /%s %d -->', static::INVOCATION_ANNOTATION_PLACEHOLDER_TAG, $invocation->index );
	}

	/**
	 * Add annotation for an enqueued dependency that was printed.
	 *
	 * @param string $tag      HTML tag.
	 * @param string $handle   Handle.
	 * @param string $type     Type, such as 'enqueued_script' or 'enqueued_style'.
	 * @param string $registry Registry name, such as 'wp_scripts' or 'wp_styles'.
	 * @return string HTML tag with annotation.
	 */
	public function add_enqueued_dependency_annotation( $tag, $handle, $type, $registry ) {
		// Abort if filter has been applied without passing all required arguments.
		if ( ! $handle ) {
			return $tag;
		}

		/*
		 * Abort if this is a stylesheet that has conditional comments, as adding comments will cause them to be nested,
		 * which is not allowed for comments. Also, styles that are inside conditional comments are mostly pointless
		 * to identify the source of since they area dead and won't impact the page for any modern browsers.
		 */
		if ( 'wp_styles' === $registry && wp_styles()->get_data( $handle, 'conditional' ) ) {
			return $tag;
		}

		$index = $this->incrementor->next();

		$this->pending_dependency_annotations[ $index ] = compact( 'handle', 'type', 'registry' );

		return (
			sprintf( '<!-- %s %d -->', static::DEPENDENCY_ANNOTATION_PLACEHOLDER_TAG, $index )
			. $tag
			. sprintf( '<!-- /%s %d -->', static::DEPENDENCY_ANNOTATION_PLACEHOLDER_TAG, $index )
		);
	}

	/**
	 * Add annotation for an enqueued script that was printed.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @return string Script tag.
	 */
	public function add_enqueued_script_annotation( $tag, $handle = null ) {
		return $this->add_enqueued_dependency_annotation( $tag, $handle, 'enqueued_script', 'wp_scripts' );
	}

	/**
	 * Add annotation for an enqueued style that was printed.
	 *
	 * @param string $tag    Style tag.
	 * @param string $handle Handle.
	 * @return string Style tag.
	 */
	public function add_enqueued_style_annotation( $tag, $handle = null ) {
		return $this->add_enqueued_dependency_annotation( $tag, $handle, 'enqueued_style', 'wp_styles' );
	}

	/**
	 * Annotate static block.
	 *
	 * @param string $block_content The block content about to be appended.
	 * @param array  $block         The full block, including name and attributes.
	 * @return string Block content.
	 */
	public function add_static_block_annotation( $block_content, $block ) {
		$is_registered = \WP_Block_Type_Registry::get_instance()->is_registered( $block['blockName'] );

		if ( empty( $block['blockName'] ) ) {
			return $block_content;
		}

		// Skip annotating dynamic blocks since they'll be annotated separately.
		if ( $is_registered && \WP_Block_Type_Registry::get_instance()->get_registered( $block['blockName'] )->is_dynamic() ) {
			return $block_content;
		}

		$index = $this->incrementor->next();

		unset( $block['innerHTML'], $block['innerBlocks'], $block['innerContent'] );
		$this->pending_block_annotations[ $index ] = $block;

		// @todo How do we determine the source for static blocks that lack render callbacks? Try using the namespace to see if it matches a plugin slug?
		return (
			sprintf( '<!-- %s %d -->', static::BLOCK_ANNOTATION_PLACEHOLDER_TAG, $index )
			. $block_content
			. sprintf( '<!-- /%s %d -->', static::BLOCK_ANNOTATION_PLACEHOLDER_TAG, $index )
		);
	}

	/**
	 * Annotate oEmbed responses.
	 *
	 * @param string $output     Embed output.
	 * @param string $url        URL.
	 * @param array  $attributes Attributes.
	 *
	 * @return string Output.
	 */
	public function add_oembed_annotation( $output, $url, $attributes ) {
		$index = $this->incrementor->next();

		$this->pending_oembed_annotations[ $index ] = array_merge(
			compact( 'url', 'attributes' ),
			[
				'internal' => current_filter() === 'embed_handler_html',
			]
		);

		return (
			sprintf( '<!-- %s %d -->', static::OEMBED_ANNOTATION_PLACEHOLDER_TAG, $index )
			. $output
			. sprintf( '<!-- /%s %d -->', static::OEMBED_ANNOTATION_PLACEHOLDER_TAG, $index )
		);
	}

	/**
	 * Purge annotations in start tag.
	 *
	 * @param array $start_tag_matches Start tag matches.
	 * @return string Start tag.
	 */
	public function purge_annotations_in_start_tag( $start_tag_matches ) {
		$attributes = preg_replace_callback(
			'#' . static::get_placeholder_annotation_pattern() . '#',
			'__return_empty_string',
			$start_tag_matches['attrs']
		);

		return '<' . $start_tag_matches['name'] . $attributes . '>';
	}

	/**
	 * Hydrate an placeholder annotation.
	 *
	 * @param int    $index   Index.
	 * @param string $type    Type.
	 * @param bool   $closing Closing.
	 * @return string Hydrated annotation.
	 */
	public function hydrate_placeholder_annotation( $index, $type, $closing ) {
		if ( self::DEPENDENCY_ANNOTATION_PLACEHOLDER_TAG === $type ) {
			return $this->hydrate_dependency_placeholder_annotation( $index, $closing );
		} elseif ( self::INVOCATION_ANNOTATION_PLACEHOLDER_TAG === $type ) {
			return $this->hydrate_invocation_placeholder_annotation( $index, $closing );
		} elseif ( self::BLOCK_ANNOTATION_PLACEHOLDER_TAG === $type ) {
			return $this->hydrate_block_placeholder_annotation( $index, $closing );
		} elseif ( self::OEMBED_ANNOTATION_PLACEHOLDER_TAG === $type ) {
			return $this->hydrate_oembed_placeholder_annotation( $index, $closing );
		}
		return '';
	}

	/**
	 * Hydrate an dependency placeholder annotation.
	 *
	 * @param int  $index   Index.
	 * @param bool $closing Closing.
	 * @return string Hydrated annotation.
	 */
	protected function hydrate_dependency_placeholder_annotation( $index, $closing ) {
		if ( ! isset( $this->pending_dependency_annotations[ $index ] ) ) {
			return '';
		}

		// Determine invocations for this dependency and store for the closing annotation comment.
		if ( ! isset( $this->pending_dependency_annotations[ $index ]['invocations'] ) ) {
			$this->pending_dependency_annotations[ $index ]['invocations'] = wp_list_pluck(
				$this->dependencies->get_dependency_enqueueing_invocations(
					$this->invocation_watcher,
					$this->pending_dependency_annotations[ $index ]['registry'],
					$this->pending_dependency_annotations[ $index ]['handle']
				),
				'index'
			);
		}

		// Remove annotation entirely if there are no invocations (which shouldn't happen).
		if ( empty( $this->pending_dependency_annotations[ $index ]['invocations'] ) ) {
			unset( $this->pending_dependency_annotations[ $index ] );
			return '';
		}

		$data = compact( 'index' );
		if ( ! $closing ) {
			$data = array_merge(
				$data,
				[
					'type'        => $this->pending_dependency_annotations[ $index ]['type'],
					'invocations' => $this->pending_dependency_annotations[ $index ]['invocations'],
				]
			);
		}

		return $this->get_annotation_comment( $data, $closing ? self::CLOSE_ANNOTATION : self::OPEN_ANNOTATION );
	}

	/**
	 * Hydrate a (static) block placeholder annotation.
	 *
	 * Note that dynamic blocks are annotated as invocations.
	 *
	 * @param int  $index   Index.
	 * @param bool $closing Closing.
	 * @return string Hydrated annotation.
	 */
	protected function hydrate_block_placeholder_annotation( $index, $closing ) {
		if ( ! isset( $this->pending_block_annotations[ $index ] ) ) {
			return '';
		}

		$block = $this->pending_block_annotations[ $index ];

		$data = compact( 'index' );
		if ( ! $closing ) {
			$data = array_merge(
				$data,
				[
					'type'    => 'block',
					'name'    => $block['blockName'],
					'dynamic' => false,
				]
			);
			if ( ! empty( $block['attrs'] ) ) {
				$data['attributes'] = $block['attrs'];
			}

			$source = $this->block_recognizer->identify( strtok( $block['blockName'], '/' ) );
			if ( $source ) {
				$data['source'] = $source;
			}
		}

		return $this->get_annotation_comment( $data, $closing ? self::CLOSE_ANNOTATION : self::OPEN_ANNOTATION );
	}

	/**
	 * Hydrate an oEmbed placeholder annotation.
	 *
	 * @param int  $index   Index.
	 * @param bool $closing Closing.
	 * @return string Hydrated annotation.
	 */
	protected function hydrate_oembed_placeholder_annotation( $index, $closing ) {
		if ( ! isset( $this->pending_oembed_annotations[ $index ] ) ) {
			return '';
		}

		$data = compact( 'index' );
		if ( ! $closing ) {
			$data = array_merge(
				$data,
				[ 'type' => 'oembed' ],
				$this->pending_oembed_annotations[ $index ]
			);
		}

		return $this->get_annotation_comment( $data, $closing ? self::CLOSE_ANNOTATION : self::OPEN_ANNOTATION );
	}

	/**
	 * Hydrate a dependency placeholder annotation.
	 *
	 * @param int  $index   Index.
	 * @param bool $closing Closing.
	 * @return string Hydrated annotation.
	 */
	protected function hydrate_invocation_placeholder_annotation( $index, $closing ) {
		$invocation = $this->invocation_watcher->get_invocation_by_index( $index );
		if ( ! $invocation ) {
			return '';
		}

		$data = compact( 'index' );
		if ( ! $closing ) {
			$data = array_merge( $data, $invocation->data() );

			// Include queries if requested.
			if ( $this->show_queries ) {
				$queries = $invocation->queries( true );
				if ( ! empty( $queries ) ) {
					$data['queries'] = $queries;
				}
			}
		}

		return $this->get_annotation_comment( $data, $closing ? self::CLOSE_ANNOTATION : self::OPEN_ANNOTATION );
	}

	/**
	 * Get annotation comment.
	 *
	 * @param array $data    Data.
	 * @param int   $type    Comment type. Either OPEN_ANNOTATION, CLOSE_ANNOTATION, EMPTY_ANNOTATION.
	 * @return string HTML comment.
	 */
	public function get_annotation_comment( array $data, $type = self::OPEN_ANNOTATION ) {

		if ( ! in_array( $type, [ self::OPEN_ANNOTATION, self::CLOSE_ANNOTATION, self::SELF_CLOSING_ANNOTATION ], true ) ) {
			_doing_it_wrong( __METHOD__, esc_html__( 'Wrong annotation type.', 'origination' ), '0.1' );
		}

		// Escape double-hyphens in comment content.
		$json = str_replace(
			'--',
			'\u2D\u2D',
			wp_json_encode( $data, JSON_UNESCAPED_SLASHES )
		);

		return sprintf(
			'<!-- %s%s %s %s-->',
			self::CLOSE_ANNOTATION === $type ? '/' : '',
			static::ANNOTATION_TAG,
			$json,
			self::SELF_CLOSING_ANNOTATION === $type ? '/' : ''
		);
	}

	/**
	 * Parse data out of origination annotation comment text.
	 *
	 * @param string|\DOMComment $comment Comment.
	 * @return null|array {
	 *     Parsed comment. Returns null on parse error.
	 *
	 *     @type bool  $closing Closing.
	 *     @type array $data    Data.
	 * }
	 */
	public function parse_annotation_comment( $comment ) {
		if ( $comment instanceof \DOMComment ) {
			$comment = $comment->nodeValue;
		}
		$pattern = sprintf(
			'#^ (?P<closing>/)?%s (?P<json>{.+}) (?P<self_closing>/)?$#s',
			preg_quote( static::ANNOTATION_TAG, '#' )
		);
		if ( ! preg_match( $pattern, $comment, $matches ) ) {
			return null;
		}
		$closing      = ! empty( $matches['closing'] );
		$self_closing = ! empty( $matches['self_closing'] );
		$data         = json_decode( $matches['json'], true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return compact( 'closing', 'data', 'self_closing' );
	}

	/**
	 * Get the annotation stack for a given DOM node.
	 *
	 * @param \DOMNode $node Target DOM node.
	 * @return array[] Stack of annotation comment data.
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function get_node_annotation_stack( \DOMNode $node ) {
		$stack = [];
		$xpath = new \DOMXPath( $node->ownerDocument );

		$open_prefix  = sprintf( ' %s ', self::ANNOTATION_TAG );
		$close_prefix = sprintf( ' /%s ', self::ANNOTATION_TAG );

		$expr = sprintf(
			'preceding::comment()[ starts-with( ., "%s" ) or starts-with( ., "%s" ) ]',
			$open_prefix,
			$close_prefix
		);

		foreach ( $xpath->query( $expr, $node ) as $comment ) {
			$parsed_comment = $this->parse_annotation_comment( $comment );
			if ( ! is_array( $parsed_comment ) ) {
				continue;
			}

			if ( $parsed_comment['closing'] ) {
				$popped = array_pop( $stack );
				if ( $popped['index'] !== $parsed_comment['data']['index'] ) {
					throw new \Exception( sprintf( 'Comment stack mismatch: saw closing comment %1$d but expected %2$d.', $parsed_comment['data']['index'], $popped['index'] ) );
				}
			} else {
				array_push( $stack, $parsed_comment['data'] );
			}
		}
		return $stack;
	}

	/**
	 * Finalize annotations.
	 *
	 * Given this HTML in the buffer:
	 *
	 *     <html data-first="B<A" <!-- origination 128 --> data-hello=world <!-- /origination 128--> data-second="A>B">.
	 *
	 * The returned string should be:
	 *
	 *     <html data-first="B<A"  data-hello=world  data-second="A>B">.
	 *
	 * @param string $buffer Buffer.
	 * @return string Processed buffer.
	 */
	public function finish( $buffer ) {
		$placeholder_annotation_pattern = static::get_placeholder_annotation_pattern();

		$this->show_queries = call_user_func( $this->can_show_queries_callback );

		// Make sure that all open invocations get closed, which can happen when an exit is done in a hook callback.
		while ( true ) {
			$invocation = $this->invocation_watcher->pop_invocation_stack();
			if ( ! $invocation ) {
				break;
			}
			if ( $invocation->can_output() ) {
				$buffer .= $this->get_after_invocation_placeholder_annotation( $invocation );
			}
		}

		// Match all start tags that have attributes.
		$pattern = join(
			'',
			[
				'#<',
				'(?P<name>[a-zA-Z0-9_\-]+)',
				'(?P<attrs>\s',
				'(?:' . $placeholder_annotation_pattern . '|[^<>"\']+|"[^"]*+"|\'[^\']*+\')*+', // Attribute tokens, plus annotations.
				')>#s',
			]
		);

		$buffer = preg_replace_callback(
			$pattern,
			[ $this, 'purge_annotations_in_start_tag' ],
			$buffer
		);

		if ( ! preg_match_all( '#' . $placeholder_annotation_pattern . '#', $buffer, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return $buffer;
		}

		// Determine all invocations that have been annotated.
		$closing_pos = 1;
		$type_pos    = 2;
		$index_pos   = 3;
		foreach ( $matches as $match ) {
			$type = $match[ $type_pos ][0];
			if ( self::INVOCATION_ANNOTATION_PLACEHOLDER_TAG === $type ) {
				$index = $match[ $index_pos ][0];
				$this->invocation_watcher->get_invocation_by_index( $index )->annotated = true;
			}
		}

		// Now hydrate the matching placeholder annotations.
		$offset_differential = 0;
		while ( ! empty( $matches ) ) {
			$match  = array_shift( $matches );
			$length = strlen( $match[0][0] );
			$offset = $match[0][1];

			$hydrated_annotation = $this->hydrate_placeholder_annotation(
				intval( $match[ $index_pos ][0] ),
				$match[ $type_pos ][0],
				! empty( $match[ $closing_pos ][0] )
			);

			// Splice the hydrated annotation into the buffer to replace the placeholder annotation.
			$buffer = substr_replace(
				$buffer,
				$hydrated_annotation,
				$offset + $offset_differential,
				$length
			);

			// Update the offset differential based on the difference in length of the hydration.
			$offset_differential += ( strlen( $hydrated_annotation ) - $length );
		}

		// Finally, amend the response with all remaining invocations that have not been annotated. These do not wrap any output.
		foreach ( $this->invocation_watcher->get_invocations() as $invocation ) {
			if ( ! $invocation->annotated ) {
				$invocation->annotated = true;

				$buffer .= $this->get_annotation_comment( $invocation->data(), self::SELF_CLOSING_ANNOTATION );
			}
		}

		return $buffer;
	}

}
