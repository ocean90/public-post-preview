const config = require( '@wearerequired/eslint-config' );

module.exports = [
	{
		ignores: [ 'js/dist/**', 'vendor/**' ],
	},
	...config,
	{
		rules: {
			'@wordpress/i18n-text-domain': [
				'error',
				{
					allowedTextDomain: [ 'public-post-preview' ],
				},
			],
		},
	},
];
