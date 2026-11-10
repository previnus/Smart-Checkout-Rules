<?php
namespace SmartCheckoutRules\Admin;

use SmartCheckoutRules\Rules\RuleRepository;
use SmartCheckoutRules\Rules\RuleSchema;

defined( 'ABSPATH' ) || exit;

final class RulesRestController {
	public function __construct( private RuleRepository $repository ) {}

	public function register(): void {
		register_rest_route(
			'scr/v1',
			'/configuration',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( $this, 'permission' ),
					'callback'            => array( $this, 'read' ),
				),
				array(
					'methods'             => 'PUT',
					'permission_callback' => array( $this, 'permission' ),
					'callback'            => array( $this, 'save' ),
				),
			)
		);
		register_rest_route(
			'scr/v1',
			'/simulate',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'simulate' ),
			)
		);
		register_rest_route(
			'scr/v1',
			'/import',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'import' ),
			)
		);
	}

	public function permission( \WP_REST_Request $request ): bool {
		return current_user_can( 'manage_woocommerce' ) &&
			( 'GET' === $request->get_method() || (bool) wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) );
	}

	public function read(): array {
		return $this->repository->snapshot() + array( 'last_event' => get_transient( 'scr_last_diagnostic' ) ?: null );
	}

	public function save( \WP_REST_Request $request ) {
		try {
			$input    = $this->payload( $request, array( 'revision', 'rules', 'settings' ) );
			$revision = $input['revision'] ?? '';
			if ( ! is_string( $revision ) || ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) ) {
				throw new \InvalidArgumentException( 'revision' );
			}
			$before   = $this->repository->snapshot();
			$old      = array_column( $before['rules'], null, 'id' );
			$rules    = $this->rules( $input['rules'] ?? null, $old );
			$settings = RuleRepository::settings( $input['settings'] ?? null );
			if ( ! $this->repository->save( $revision, $rules, $settings ) ) {
				return new \WP_Error( 'scr_conflict', __( 'The rules changed or could not be saved. Reload the page before trying again.', 'smart-checkout-rules' ), array( 'status' => 409 ) );
			}
			return $this->read();
		} catch ( \InvalidArgumentException $exception ) {
			return $this->invalid( $exception );
		}
	}

	public function simulate( \WP_REST_Request $request ) {
		try {
			$input = $this->payload( $request, array( 'rules', 'settings', 'scenario' ) );
			return Simulator::run(
				$this->rules( $input['rules'] ?? null ),
				RuleRepository::settings( $input['settings'] ?? null ),
				$input['scenario'] ?? null
			);
		} catch ( \InvalidArgumentException $exception ) {
			return $this->invalid( $exception );
		}
	}

	public function import( \WP_REST_Request $request ) {
		try {
			$input = $this->payload( $request, array( 'schema_version', 'rules', 'settings' ) );
			if ( 1 !== ( $input['schema_version'] ?? null ) ) {
				throw new \InvalidArgumentException( 'schema_version' );
			}
			return array(
				'rules'    => $this->rules( $input['rules'] ?? null ),
				'settings' => RuleRepository::settings( $input['settings'] ?? null ),
			);
		} catch ( \InvalidArgumentException $exception ) {
			return $this->invalid( $exception );
		}
	}

	private function payload( \WP_REST_Request $request, array $keys ): array {
		if ( strlen( $request->get_body() ) > 1500000 ) {
			throw new \InvalidArgumentException( 'size' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), $keys ) ) {
			throw new \InvalidArgumentException( 'payload' );
		}
		return $input;
	}

	private function rules( $input, array $previous = array() ): array {
		if ( ! RuleSchema::is_list( $input ) || count( $input ) > RuleSchema::MAX_RULES ) {
			throw new \InvalidArgumentException( 'rules' );
		}
		$rules = array();
		$now   = gmdate( 'c' );
		foreach ( $input as $i => $row ) {
			try {
				$rule = RuleSchema::rule( $row );
			} catch ( \InvalidArgumentException $exception ) {
				throw new \InvalidArgumentException( esc_html( ( $i + 1 ) . '.' . $exception->getMessage() ) );
			}
			$id = $rule['id'] ?: wp_generate_uuid4();
			if ( isset( $rules[ $id ] ) ) {
				throw new \InvalidArgumentException( esc_html( ( $i + 1 ) . '.id' ) );
			}
			$rule['id']         = $id;
			$old                = $previous[ $id ] ?? array();
			$rule['created_at'] = ( $old['created_at'] ?? '' ) ?: $now;
			$rule['updated_at'] = $old['updated_at'] ?? '';
			if ( ! $rule['updated_at'] || $old !== $rule ) {
				$rule['updated_at'] = $now;
			}
			$rules[ $id ] = $rule;
		}
		return array_values( $rules );
	}

	private function invalid( \InvalidArgumentException $exception ): \WP_Error {
		/* translators: %s: path of an invalid rule setting. */
		return new \WP_Error( 'scr_invalid', sprintf( __( 'Check this setting: %s.', 'smart-checkout-rules' ), $exception->getMessage() ), array( 'status' => 400 ) );
	}
}
