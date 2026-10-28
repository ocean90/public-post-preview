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
			// Core's own sidebar is built from these, and the spec chooses them on purpose.
			'@wordpress/no-unsafe-wp-apis': [
				'error',
				{
					'@wordpress/block-editor': [ '__experimentalInspectorPopoverHeader' ],
					'@wordpress/components': [
						'__experimentalConfirmDialog',
						'__experimentalHStack',
						'__experimentalInputControl',
						'__experimentalInputControlSuffixWrapper',
						'__experimentalItem',
						'__experimentalItemGroup',
						'__experimentalText',
						'__experimentalToggleGroupControl',
						'__experimentalToggleGroupControlOption',
						'__experimentalVStack',
					],
				},
			],
		},
	},
];
