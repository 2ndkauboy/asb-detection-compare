<?php
/**
 * Plugin Name: Antispam Bee language API override
 *
 * Points LangSpam at the local franc service instead of the public API, so a
 * comparison run neither depends on nor hammers an external endpoint.
 *
 * Two filters are needed:
 *
 * 1. `antispam_bee_lang_api_url` — the endpoint itself.
 * 2. `http_request_host_is_external` — LangSpam calls the endpoint through
 *    wp_safe_remote_post(), and wp_http_validate_url() rejects loopback and
 *    private addresses unless a filter declares the host external.
 *
 * Everything else in LangSpam::verify() — the length guard, error handling and
 * language mapping — runs unchanged, which is the point: the rule is compared as
 * it ships, only the transport is redirected.
 *
 * @package AntispamBee\Comparison
 */

add_filter(
	'antispam_bee_lang_api_url',
	static function () {
		return 'http://127.0.0.1:8080/';
	}
);

add_filter(
	'http_request_host_is_external',
	static function ( $is_external, $host ) {
		return '127.0.0.1' === $host ? true : $is_external;
	},
	10,
	2
);
