const path = require('path');

module.exports = ( env, argv ) => ( {
	name: 'js_bundle',
	context: path.resolve(__dirname, 'src'),
	entry: {
		'builder.editor.js': './jet-form-builder-action.js',
		'post.editor.js': './post-editor.js',
	},
	output: {
		path: path.resolve( __dirname, 'js' ),
		filename: '[name]'
	},
	devtool: argv.mode === 'development' ? 'eval-source-map' : false,
	resolve: {
		modules: [
			path.resolve( __dirname, 'src' ),
			'node_modules'
		],
		extensions: [ '.js' ],
		alias: {
			'@': path.resolve( __dirname, 'src' )
		}
	},
	module: {
		rules: [
			{
				test: /\.js$/,
				loader: 'babel-loader',
				exclude: /node_modules/
			},
		]
	}
} );
