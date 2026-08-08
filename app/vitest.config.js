const path = require('path')

/** @type {import('vitest').UserConfig} */
module.exports = {
    resolve: {
        alias: {
            '@hotwired/stimulus': path.resolve(__dirname, 'node_modules/@hotwired/stimulus'),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['assets/tests/**/*.test.js'],
    },
}
