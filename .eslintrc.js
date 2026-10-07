module.exports = {
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	env: {
		browser: true,
		node: true,
	},
	overrides: [
		{
			files: [ '**/__tests__/**/*.js', '**/*.test.js' ],
			env: {
				jest: true,
			},
			rules: {
				'import/no-extraneous-dependencies': 'off',
			},
		},
	],
};
