module.exports = ( api ) => {
	api.cache( true );

	return {
		presets: [ '@wordpress/babel-preset-default' ],
		env: {
			production: {
				plugins: [
					[
						'emotion',
						{
							hoist: true,
						},
					],
				],
			},
			development: {
				plugins: [
					[ 'emotion', { sourceMap: true, autoLabel: true } ],
				],
			},
		},
	};
};
