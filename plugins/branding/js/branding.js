/*
 * ---------------------------------------------------------------------
 *
 * GLPI Branding plugin - favicon.
 *
 * GLPI hardcodes `<link rel="shortcut icon" href="/pics/favicon.ico">`
 * in its <head>, after the tags contributed by plugins, so a favicon
 * added with the `add_header_tag` hook would be ignored by the browser.
 * Removing the existing icon links and appending ours at the very end of
 * <head> reliably makes the custom logo win.
 *
 * The URL is deduced from this script's own src, so it works whatever the
 * GLPI installation path is.
 *
 * ---------------------------------------------------------------------
 */

(() => {
    const script = document.currentScript;
    if (!script || !script.src) {
        return;
    }

    // /plugins/branding/js/branding.js -> /plugins/branding
    const base = script.src.replace(/\/js\/branding\.js.*$/, '');
    const href = base + '/front/logo.php?kind=favicon';

    document
        .querySelectorAll('link[rel~="icon"], link[rel="shortcut icon"]')
        .forEach((link) => link.remove());

    const icon = document.createElement('link');
    icon.rel = 'icon';
    icon.href = href;
    document.head.appendChild(icon);
})();
