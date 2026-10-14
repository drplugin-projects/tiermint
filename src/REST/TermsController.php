<?php
/**
 * REST controller for the product Taxonomy (terms) management resource.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Products\ProductTaxonomy;
use DrPlugin\WP\TierMint\Products\Term;
use DrPlugin\WP\TierMint\Products\TermException;
use DrPlugin\WP\TierMint\Products\TermNotFoundException;
use DrPlugin\WP\TierMint\Products\TermPersistenceException;
use DrPlugin\WP\TierMint\Products\TermService;
use DrPlugin\WP\TierMint\Products\TermValidationException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST routes for product categories and tags.
 *
 * The service owns term rules. Taxonomy lists stay unpaginated.
 *
 * @since 1.0.0
 */
final class TermsController extends AbstractRestController {

	/**
	 * Write fields sent to the service.
	 *
	 * Taxonomy is set at create time and cannot change.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE_FIELDS = array(
		'name',
		'slug',
		'parent',
		'description',
	);

	/**
	 * The term domain service this controller delegates to.
	 *
	 * @var TermService
	 */
	private TermService $terms;

	/**
	 * Set the term service.
	 *
	 * @since 1.0.0
	 *
	 * @param TermService $terms The term domain service.
	 */
	public function __construct( TermService $terms ) {
		$this->terms = $terms;
	}

	/**
	 * Register the Terms collection and single-item routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/terms',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->collectionArgs(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->createArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/terms/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->idArgs(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => array_merge( $this->idArgs(), $this->writeArgs() ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->idArgs(),
				),
			)
		);
	}

	/**
	 * List terms for one taxonomy.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response The taxonomy listing.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		// The schema constrains `taxonomy` to the enum and marks it required, so a
		// value is always present and always resolves here.
		$taxonomy = ProductTaxonomy::from( (string) $request->get_param( 'taxonomy' ) );

		$data = array_map( array( $this, 'presentTerm' ), $this->terms->listByTaxonomy( $taxonomy ) );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Create a term.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The created term, or an error envelope.
	 */
	public function create( WP_REST_Request $request ) {
		try {
			$term = $this->terms->create( $this->payload( $request, true ) );
		} catch ( TermValidationException $e ) {
			return $this->invalidParams( $e->getErrors() );
		} catch ( TermPersistenceException $e ) {
			// A database write failed: a server fault, not client input. Report a
			// generic 500 and keep the exception's internal detail out of the response.
			return $this->serverError( __( 'The term could not be saved. Please try again.', 'tiermint' ) );
		} catch ( TermException $e ) {
			// A non-validation, non-persistence domain error is still a client input
			// problem, not a server fault.
			return $this->invalidParams( array( $e->getMessage() ) );
		}

		return new WP_REST_Response( $this->presentTerm( $term ), 201 );
	}

	/**
	 * Get one term.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The term, or an error envelope.
	 */
	public function show( WP_REST_Request $request ) {
		$term = $this->terms->get( (int) $request->get_param( 'id' ) );

		if ( null === $term ) {
			return $this->notFound();
		}

		return new WP_REST_Response( $this->presentTerm( $term ), 200 );
	}

	/**
	 * Update one term.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The updated term, or an error envelope.
	 */
	public function update( WP_REST_Request $request ) {
		try {
			$term = $this->terms->update( (int) $request->get_param( 'id' ), $this->payload( $request, false ) );
		} catch ( TermNotFoundException $e ) {
			return $this->notFound();
		} catch ( TermValidationException $e ) {
			return $this->invalidParams( $e->getErrors() );
		} catch ( TermPersistenceException $e ) {
			// A database write failed: a server fault, not client input. Report a
			// generic 500 and keep the exception's internal detail out of the response.
			return $this->serverError( __( 'The term could not be saved. Please try again.', 'tiermint' ) );
		} catch ( TermException $e ) {
			return $this->invalidParams( array( $e->getMessage() ) );
		}

		return new WP_REST_Response( $this->presentTerm( $term ), 200 );
	}

	/**
	 * Delete one term.
	 *
	 * The service reparents deleted category children.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The deletion result, or an error envelope.
	 */
	public function delete( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$term = $this->terms->get( $id );

		if ( null === $term ) {
			return $this->notFound();
		}

		$previous = $this->presentTerm( $term );

		try {
			$this->terms->delete( $id );
		} catch ( TermNotFoundException $e ) {
			// Lost a race with a concurrent delete; report it as already gone.
			return $this->notFound();
		}

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			),
			200
		);
	}

	/**
	 * Return the required taxonomy query schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function collectionArgs(): array {
		return array(
			'taxonomy' => $this->taxonomyArg( true ),
		);
	}

	/**
	 * Return the create schema with its immutable taxonomy.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function createArgs(): array {
		return array_merge(
			array( 'taxonomy' => $this->taxonomyArg( true ) ),
			$this->writeArgs()
		);
	}

	/**
	 * Return the mutable term-field schema.
	 *
	 * The service reports missing business fields together.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function writeArgs(): array {
		return array(
			'name'        => array(
				'description'       => __( 'Term display name.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'slug'        => array(
				'description'       => __( 'Term URL slug (unique within its taxonomy).', 'tiermint' ),
				'type'              => 'string',
				// Wrapped: REST sanitize callbacks receive ($value, $request, $param),
				// so bare sanitize_title() would take the request object as its
				// fallback title and an empty submitted value would store the
				// request object itself (fatal downstream).
				'sanitize_callback' => static fn ( $value ): string => sanitize_title( (string) $value ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'parent'      => array(
				'description'       => __( 'Parent term id (categories only; tags are flat).', 'tiermint' ),
				'type'              => 'integer',
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'description' => array(
				'description'       => __( 'Long term description.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * The `args` schema for the `{id}` path parameter.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function idArgs(): array {
		return array(
			'id' => array(
				'description'       => __( 'Unique term identifier.', 'tiermint' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Return the product-taxonomy parameter schema.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $required Whether the parameter is required.
	 * @return array<string, mixed> The parameter schema.
	 */
	private function taxonomyArg( bool $required ): array {
		return array(
			'description'       => __( 'Which taxonomy the term belongs to.', 'tiermint' ),
			'type'              => 'string',
			'required'          => $required,
			'enum'              => array( ProductTaxonomy::Category->value, ProductTaxonomy::Tag->value ),
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Collect fields sent by the client.
	 *
	 * Include taxonomy for creates.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request      The incoming request.
	 * @param bool            $withTaxonomy Whether to include the `taxonomy` field.
	 * @return array<string, mixed> The service input array.
	 */
	private function payload( WP_REST_Request $request, bool $withTaxonomy ): array {
		$data = array();

		if ( $withTaxonomy ) {
			$data['taxonomy'] = $request->get_param( 'taxonomy' );
		}

		foreach ( self::WRITABLE_FIELDS as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		return $data;
	}

	/**
	 * Shape a term and its parent for JSON.
	 *
	 * @since 1.0.0
	 *
	 * @param Term $term The term to present.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentTerm( Term $term ): array {
		return array(
			'id'          => (int) $term->id(),
			'taxonomy'    => $term->taxonomy()->value,
			'name'        => $term->name(),
			'slug'        => $term->slug(),
			'parent'      => $term->parent(),
			'description' => $term->description(),
			'created_at'  => $term->createdAt(),
			'updated_at'  => $term->updatedAt(),
		);
	}
}
