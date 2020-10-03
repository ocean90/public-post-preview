const defaultConfig = require( './node_modules/@wordpress/scripts/config/.eslintrc.js' );

module.exports = {
	...defaultConfig,
	rules: {
		...defaultConfig.rules,
		'@wordpress/i18n-text-domain': [
			'error',
			{ allowedTextDomain: [ 'public-post-preview' ] },
		],
	},
};
