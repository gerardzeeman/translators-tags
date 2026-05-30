/** @type {import('vitest').UserConfig} */
module.exports = {
    resolve: {
        alias: {
            '@hotwired/stimulus': '/tmp/node_modules/@hotwired/stimulus',
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['assets/tests/**/*.test.js'],
    },
}
