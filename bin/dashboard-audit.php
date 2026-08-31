<?php
/**
 * Audit dashboard translation calls for the HealthLens text domain.
 *
 * @package HealthLens
 */

declare( strict_types=1 );

$root      = dirname( __DIR__ );
$files     = array(
	$root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Presentation' . DIRECTORY_SEPARATOR . 'Admin' . DIRECTORY_SEPARATOR . 'DashboardPage.php',
	$root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Presentation' . DIRECTORY_SEPARATOR . 'Admin' . DIRECTORY_SEPARATOR . 'SettingsPage.php',
);
$functions = array( '__', '_e', '_x', '_ex', '_n', '_nx', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e' );
$errors    = array();
$checked   = 0;

foreach ( $files as $file ) {
	$source = file_get_contents( $file );
	if ( false === $source ) {
		$errors[] = "Unable to read {$file}.";
		continue;
	}

	$tokens = token_get_all( $source );
	$count  = count( $tokens );
	for ( $index = 0; $index < $count; $index++ ) {
		$token = $tokens[ $index ];
		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! in_array( $token[1], $functions, true ) ) {
			continue;
		}

		$open = $index + 1;
		while ( $open < $count && is_array( $tokens[ $open ] ) && T_WHITESPACE === $tokens[ $open ][0] ) {
			++$open;
		}
		if ( $open >= $count || '(' !== $tokens[ $open ] ) {
			continue;
		}

		$depth  = 1;
		$comma  = false;
		$domain = null;
		for ( $cursor = $open + 1; $cursor < $count; $cursor++ ) {
			$part = $tokens[ $cursor ];
			if ( '(' === $part ) {
				++$depth;
				continue;
			}
			if ( ')' === $part ) {
				--$depth;
				if ( 0 === $depth ) {
					break;
				}
				continue;
			}
			if ( 1 === $depth && ',' === $part ) {
				$comma = true;
				continue;
			}
			if ( ! $comma || ( is_array( $part ) && T_WHITESPACE === $part[0] ) ) {
				continue;
			}

			if ( is_array( $part ) && T_CONSTANT_ENCAPSED_STRING === $part[0] ) {
				$domain = trim( $part[1], "'\"" );
			}
			break;
		}

		++$checked;
		if ( 'healthlens' !== $domain ) {
			$line      = $token[2];
			$displayed = null === $domain ? 'missing' : $domain;
			$errors[]  = "{$file}:{$line} {$token[1]}() uses {$displayed} instead of healthlens.";
		}
	}
}

if ( ! empty( $errors ) ) {
	fwrite( STDERR, implode( PHP_EOL, $errors ) . PHP_EOL );
	exit( 1 );
}

fwrite( STDOUT, "Dashboard localization audit passed: {$checked} calls use the healthlens text domain." . PHP_EOL );
