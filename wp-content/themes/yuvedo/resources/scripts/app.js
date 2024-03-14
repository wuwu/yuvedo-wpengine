import domReady from '@roots/sage/client/dom-ready';
import Alpine from 'alpinejs'

/**
 * Application entrypoint
 */
domReady(async () => {
  window.Alpine = Alpine
  Alpine.start();
});

/**
 * @see {@link https://webpack.js.org/api/hot-module-replacement/}
 */
if (import.meta.webpackHot) import.meta.webpackHot.accept(console.error);


document.addEventListener('alpine:init', () => {
    Alpine.data('appState', () => ({
        menuOpen: false,

        toggleMenu() {
            this.menuOpen = ! this.menuOpen
        },
    }))
})
