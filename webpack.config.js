const externals = {
	jquery: 'jQuery',
};

// Define WordPress dependencies
const wpDependencies = [
	'components',
	'editor',
	'element',
	'i18n',
	'utils',
	'data',
	'plugins',
	'edit-post',
];

/**
 * Given a string, returns a new string with dash separators converedd to
 * camel-case equivalent. This is not as aggressive as `_.camelCase` in
 * converting to uppercase, where Lodash will convert letters following
 * numbers.
 *
 * @param {string} string Input dash-delimited string.
 *
 * @return {string} Camel-cased string.
 */
function camelCaseDash( string ) {
	return string.replace(
		/-([a-z])/,
		( match, letter ) => letter.toUpperCase()
	);
}

wpDependencies.forEach( ( name ) => {
	externals[ `@wordpress/${ name }` ] = {
		this: [ 'wp', camelCaseDash( name ) ],
	};
} );

const config = {
	mode: process.env.NODE_ENV === 'production' ? 'production' : 'development',

	entry: './js/src/index.js',

	// https://webpack.js.org/configuration/output/
	output: {
		path: __dirname,
		filename: 'js/gutenberg-integration.js',
		library: [ 'publicPostPreview', '[name]' ],
		libraryTarget: 'this',
	},

	// https://webpack.js.org/configuration/externals/
	externals,

	// https://github.com/babel/babel-loader#usage
	module: {
		rules: [
			{
				test: /\.js$/,
				exclude: /node_modules/,
				use: 'babel-loader',
			},
		],
	},
};

module.exports = config;
