const path = require( 'path' );
const { CleanWebpackPlugin } = require( 'clean-webpack-plugin' );
const defaultConfig = require( './node_modules/@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,

	entry: {
		'gutenberg-integration': './js/src/index.js',
	},

	output: {
		path: path.resolve( __dirname, 'js/dist' ),
		filename: '[name].js',
	},

	// TODO: Remove once @wordpress/scripts with CleanWebpackPlugin is released
	// https://github.com/WordPress/gutenberg/pull/23135
	plugins: [ new CleanWebpackPlugin(), ...defaultConfig.plugins ],
};
