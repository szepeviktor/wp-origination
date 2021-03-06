<?php
/**
 * Block_Invocation Class.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/GoogleChromeLabs/wp-origination
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @copyright 2019 Google LLC
 */

namespace Google\WP_Origination;

/**
 * Class Block_Invocation.
 */
class Block_Invocation extends Invocation {

	/**
	 * Block tag.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Block attributes.
	 *
	 * @var array
	 */
	public $attributes;

	/**
	 * Whether this invocation is expected to produce output (an action) vs a filter.
	 *
	 * @todo This may not make sense to be in the base class.
	 *
	 * @return bool Whether output is expected.
	 */
	public function can_output() {
		return false;
	}

	/**
	 * Get data for exporting.
	 *
	 * @return array Data.
	 */
	public function data() {
		$data  = parent::data();
		$index = $data['index'];
		unset( $data['index'] );

		$data = array_merge(
			compact( 'index' ),
			[
				'type'       => 'block',
				'name'       => $this->name,
				'dynamic'    => true,
				'attributes' => $this->attributes,
			],
			$data
		);

		return $data;
	}
}
