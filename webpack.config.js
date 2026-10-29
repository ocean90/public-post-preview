const path = require( 'path' );
const defaultConfig = require( './node_modules/@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,

	entry: {
		'block-editor': './js/src/block-editor/index.js',
		'classic-editor': './js/src/classic-editor/index.js',
		'preview-updates': './js/src/preview-updates.js',
	},

	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'js/dist' ),
	},
};
