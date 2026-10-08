import { defineConfig } from 'vitest/config';

// JS-Tests der Verwaltung: jsdom als Browser, je Test frische Mocks und Globals.
export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/setup.js'],
        restoreMocks: true,
        unstubGlobals: true,
    },
});
