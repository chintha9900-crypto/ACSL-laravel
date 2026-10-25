import './bootstrap';

/*
 * Content images marked `data-fallback` that fail to load (file missing on
 * the server, storage not linked) are swapped for the same neutral
 * placeholder the pages use when no image was uploaded, instead of a broken
 * image icon. Error events do not bubble, so this listens in the capture
 * phase. The swap keeps the image's own size and shape classes.
 */
const placeholderIcon = '<svg class="h-10 w-10" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M4 9h16M9 4v16"/></svg>';

function replaceWithFallback(image) {
    if (image.dataset.fallbackApplied) {
        return;
    }

    image.dataset.fallbackApplied = 'true';

    const fallback = document.createElement('div');
    fallback.className = `${image.className} image-fallback`;
    fallback.setAttribute('role', 'img');
    fallback.setAttribute('aria-label', image.alt ? `${image.alt} (image unavailable)` : 'Image unavailable');
    fallback.innerHTML = placeholderIcon;
    image.replaceWith(fallback);
}

document.addEventListener('error', (event) => {
    const target = event.target;

    if (target instanceof HTMLImageElement && target.hasAttribute('data-fallback')) {
        replaceWithFallback(target);
    }
}, true);

// Images that already failed before this script ran.
document.querySelectorAll('img[data-fallback]').forEach((image) => {
    if (image.complete && image.naturalWidth === 0 && image.getAttribute('src')) {
        replaceWithFallback(image);
    }
});
