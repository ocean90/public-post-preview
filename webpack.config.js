const defaultConfig = require("./node_modules/@wordpress/scripts/config/webpack.config");

module.exports = {
	...defaultConfig,

	entry: {
		'gutenberg-integration': './js/src/index.js',
	},

	output: {
		path: __dirname,
		filename: 'js/[name].js',
	},
};
