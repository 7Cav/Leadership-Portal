// Sticky table headers, shared by every long table (the styles are in app.css under
// `[data-sticky-head]`). Put `v-sticky-head` on the element that wraps a <table>, or pass
// `sticky-header` to the ui <Table>.
//
// A header cell can only stick to the page when nothing between it and the page scrolls. A wide
// table needs its wrapper to scroll sideways, and that turns the wrapper into the scroller the
// header sticks to. So the wrapper takes one of two modes, set on `data-sticky-head`:
//   page: the table fits. The wrapper does not scroll, and the header sticks just below the app's
//         top bar (`--shell-top`, which grows while the view-as banner shows).
//   box:  the table is wider than its wrapper. The wrapper scrolls both ways, capped at the
//         viewport's height below the top bar, and the header sticks to the top of that box.
// `v-sticky-head="'box'"` keeps a table in box mode always (the grids that are built around their
// own scroller); `v-sticky-head="false"` turns it off. The mode is set straight on the element
// from a ResizeObserver, before paint, so switching it does not flash.

export function stickyHeadMode(el) {
    // Read in either mode: a wrapper that does not scroll still reports its overflowing width.
    // Once in box mode a vertical scrollbar narrows the wrapper, so the table only drops back to
    // page mode when it fits beside that scrollbar too, which keeps the mode from flapping.
    return el.scrollWidth > el.clientWidth + 1 ? 'box' : 'page';
}

function apply(el, forced) {
    const mode = forced === 'box' ? 'box' : stickyHeadMode(el);
    if (el.dataset.stickyHead !== mode) {
        el.dataset.stickyHead = mode;
    }
}

function observe(el, forced) {
    el._stickyHead?.disconnect();
    el._stickyHead = null;
    if (forced === false) {
        delete el.dataset.stickyHead;
        return;
    }
    apply(el, forced);
    if (forced === 'box' || typeof ResizeObserver === 'undefined') {
        return;
    }

    const observer = new ResizeObserver(() => apply(el, forced));
    observer.observe(el);
    const table = el.querySelector('table');
    if (table) {
        observer.observe(table);
    }
    el._stickyHead = observer;
}

export const vStickyHead = {
    mounted(el, binding) {
        observe(el, binding.value);
    },
    updated(el, binding) {
        if (binding.value !== binding.oldValue) {
            observe(el, binding.value);
        }
    },
    unmounted(el) {
        el._stickyHead?.disconnect();
        el._stickyHead = null;
    },
};
