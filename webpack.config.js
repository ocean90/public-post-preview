const path = require( 'path' );
const defaultConfig = require( './node_modules/@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,

	entry: {
		'gutenberg-integration': './js/src/index.js',
	},

	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'js/dist' ),
		filename: '[name].js',
	},
};
