<?php
/**
 * Plugin Name: A8C Fragment Cache
 * Version: 0.1.0
 *
 * @package WooCommerce
 */

/**
 * Experimental plugin to see if we cache individual fragments. Based on the react's algorithm to have each component generate a key, and then have it cached against that key.
 *
 * Example Usage:
 *
// cache the product api request, as long as there hasn't been any update to products.
mark_fragment_cacheable( 'api', '/wc/store/v1/products', function ( WP_REST_Request $request ) {
	$key = array_filter( $_GET );
	return 'all-products-' . md5( serialize( $key ) . WC_Cache_Helper::get_cache_prefix( 'products') );
} );

// cache the cart request, as long as the cart has not changed.
mark_fragment_cacheable( 'api', '/wc/store/v1/cart', function ( WP_REST_Request $request ) {
	// Example of data leakage, this does not check against session key which is a big and will leak address info to other users.
	// Key must depend on user's session if personal data is returned.
	if ( is_null( WC()->cart ) ) {
		return 'cart-empty';
	}
	return 'cart-' .WC()->cart->get_cart_hash();
} );
 *
 */

global $cacheable_fragments;
$cacheable_fragments = array(
	'blocks' => array(),
	'api'    => array(),
);

function mark_fragment_cacheable( string $type, string $name, callable $callback ) {
	global $cacheable_fragments;
	switch ( $type ) {
		case 'block':
			$cacheable_fragments['blocks'][ $name ] = $callback;
			break;
		case 'api':
			$cacheable_fragments['api'][ $name ] = $callback;
			break;
	}
}

add_action( 'pre_render_block', function ( $pre_render, $block, $parent_block ) {
	global $cacheable_fragments;
	if ( isset( $cacheable_fragments['blocks'][ $block['blockName'] ] ) ) {
		$cache_key      = $cacheable_fragments['blocks'][ $block['blockName'] ]( $block, $parent_block );
		$cached_content = wp_cache_get( $cache_key, 'a8c-super-cache' );
		if ( $cached_content ) {
			js_console_log( 'loaded from cache' . $cache_key );

			return $cached_content; // all-products-ee2044e0776c51c5797f7debc86fd845
		} else {
			add_filter( 'render_block_' . $block['blockName'], 'cache_block_output', 99, 3 );
		}
	}

	return $pre_render;
}, 10, 3 );

function cache_block_output( $block_content, $parsed_block, $block ) {
	global $cacheable_fragments;
	if ( isset( $cacheable_fragments['blocks'][ $parsed_block['blockName'] ] ) ) {
		$cache_key = $cacheable_fragments['blocks'][ $parsed_block['blockName'] ]( $parsed_block, $block );
		wp_cache_set( $cache_key, $block_content, 'a8c-super-cache' );
	}

	return $block_content;
}

add_action( 'rest_pre_dispatch', 'rest_pre_serve_cached_request', 10, 4 );

function rest_pre_serve_cached_request( $result, $server, $request  ) {
	global $cacheable_fragments;
	if ( $result !== null ) {
		return $result;
	}
	if ( isset( $cacheable_fragments['api'][ $request->get_route() ] ) ) {
		$cache_key      = $cacheable_fragments['api'][ $request->get_route() ]( $request );
		$cached_content = wp_cache_get( $cache_key, 'a8c-super-cache' );
		if ( $cached_content ) {
			header( 'X-WP-A8C-Cache: HIT' );
			return json_decode( $cached_content );
		} else {
			header('X-WP-A8C-Cache-MISS: ' . $cache_key );
		}
	}

	return $result;
}

add_filter( 'rest_pre_echo_response', 'cache_api_response', 99, 3 );

function cache_api_response( $result, $server, $request ) {
	global $cacheable_fragments;
	if ( isset( $cacheable_fragments['api'][ $request->get_route() ] ) ) {
		$cache_key = $cacheable_fragments['api'][ $request->get_route() ]( $request );
		$cache_set = wp_cache_get( $cache_key );
		if ( $cache_set !== false ) {
			header('X-WP-A8C-Cache-SET: ' . $cache_key );
			wp_cache_set( $cache_key, json_encode( $result ), 'a8c-super-cache' );
		}
	}

	return $result;
}

mark_fragment_cacheable( 'api', '/wc/store/v1/products', function ( WP_REST_Request $request ) {
	$key = array_filter( $_GET );
	return 'all-products-' . md5( serialize( $key ) . WC_Cache_Helper::get_cache_prefix( 'products') );
} );


mark_fragment_cacheable( 'api', '/wc/store/v1/cart', function ( WP_REST_Request $request ) {
	if ( is_null( WC()->cart ) ) {
		return 'cart-empty';
	}
	return 'cart-' .WC()->cart->get_cart_hash();
} );