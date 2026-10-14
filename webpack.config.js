/**
 * wp-scripts webpack configuration.
 *
 * Two bundles: the block editor script and the wp-admin app. WordPress
 * packages stay externals; react-query is compiled in.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

const bundle = ( name ) => ( {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, `assets/${ name }/src/index.js` ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, `assets/${ name }/build` ),
	},
} );

module.exports = [ bundle( 'blocks' ), bundle( 'admin' ) ];
