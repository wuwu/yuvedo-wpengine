<?php

namespace DeliciousBrains\WPMDB\Common\Transfers\Files;

/**
 * Class Excludes
 *
 * @package WPMDB\Transfers\Excludes
 */
class Excludes {

	const MEDIA_FILES = [
        '*.sql',
        '*.log',
        '*backup*/',
        '*cache*/',
        '.htaccess*',
        '*.DS_Store*',
		'pp/static/'
    ];
    const THEMES = [
        '.git',
        'node_modules',
        '.htaccess*',
        '*.DS_Store*'
    ];
    const PLUGINS = [
        '.git',
        'node_modules',
        '.htaccess*',
        '*.DS_Store*'
    ];
    const MU_PLUGINS = [
        '.git',
        'node_modules',
        '.htaccess*',
        '*.DS_Store*'
    ];
    const OTHERS = [
        '.git',
        'node_modules',
        '*.sql',
        '*.log',
        '*backup*/',
        '*cache*/',
        'wflogs/',
        'updraft/',
        '.htaccess*',
        '*.DS_Store*',
    ];
    const CORE = [
        '.git',
        'node_modules',
        '.htaccess*',
        '*.DS_Store*'
    ];

	public $excludes;

	public function __construct() {}

	/**
	 *
	 * Given an array of paths, check if $filePath matches
	 *
	 *
	 * @param string $filePath
	 * @param array  $excludes
	 *
	 * @return bool
	 */
	public static function shouldExcludeFile( $filePath, $excludes ) {
		$matches = [];

		if ( empty( $excludes ) || ! is_array( $excludes ) ) {
			return false;
		}

		foreach ( $excludes as $pattern ) {
			$include = false;

			if ( empty( $pattern ) ) {
				continue;
			}

			// If pattern starts with an exclamation mark remove exclamation mark and check if pattern matches current file path
			if ( 0 === strpos( $pattern, '!' ) ) {
				$pattern = ltrim( $pattern, '!' );
				$include = true;
			}

			if ( self::pathMatches( $filePath, $pattern ) ) {
				$type                            = $include ? 'include' : 'exclude';
				$matches[ $type ][ $filePath ][] = $pattern;
			}
		}

		// If the file should be included (based on the '!' character) none of the matched exclusion patterns matter
		if ( ! empty( $matches['include'] ) ) {
			$matches['exclude'] = [];
		}

		return count($matches) > 0;
	}

	/**
	 *
	 * Convert glob pattern to regex
	 * https://stackoverflow.com/a/13914119/130596
	 *
	 * @param      $path
	 * @param      $pattern
	 * @param bool $ignoreCase
	 *
	 * @return bool
	 */
	public static function pathMatches( $path, $pattern, $ignoreCase = false ) {

		$expr = preg_replace_callback( '/[\\\\^$.[\\]|()?*+{}\\-\\/]/', function ( $matches ) {
			switch ( $matches[0] ) {
				case '*':
					return '.*';
				case '?':
					return '.';
				default:
					return '\\' . $matches[0];
			}
		}, $pattern );

		$expr = '/' . $expr . '/';
		if ( $ignoreCase ) {
			$expr .= 'i';
		}

		return (bool) preg_match( $expr, $path );
	}

	/**
	 * Get the excludes for each stage
	 *
	 * @param string $stage Description
	 * @return array
	 **/
	public static function get_excludes_for_stage($stage)
    {
		$base_excludes = [];
        switch($stage) {
            case 'media_files':
                $base_excludes = self::MEDIA_FILES;
				break;
            case 'themes':
                $base_excludes = self::THEMES;
				break;
            case 'plugins':
                $base_excludes = self::PLUGINS;
				break;
            case 'muplugins':
                $base_excludes = self::MU_PLUGINS;
				break;
            case 'others':
                $base_excludes = self::OTHERS;
				break;
            case 'core':
                $base_excludes = self::CORE;
				break;
        }
		return $base_excludes;
    }
}
