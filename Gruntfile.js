/*global module:false*/
module.exports = function(grunt) {

	//Project configuration.
	grunt.initConfig({

		//Metadata.
		pkg: grunt.file.readJSON('package.json'),

		//PHP REVIEW
		blobphp: {
			check: {
				src: process.cwd(),
				options: {
					colors: true,
					warnings: true
				}
			},
			fix: {
				src: process.cwd(),
				options: {
					fix: true
				},
			}
		},

		//WATCH
		watch: {
			php: {
				files: [
					'**/*.php',
					'.wp-cli/**/*.php'
				],
				tasks: ['php'],
				options: {
					spawn: false
				},
			}
		}
	});

	//These plugins provide necessary tasks.
	grunt.loadNpmTasks('grunt-contrib-watch');
	grunt.loadNpmTasks('grunt-notify');
	grunt.loadNpmTasks('grunt-blobfolio');

	grunt.registerTask('php', ['blobphp:check']);

	grunt.event.on('watch', function(action, filepath, target) {
		grunt.log.writeln(target + ': ' + filepath + ' has ' + action);
	});
};
