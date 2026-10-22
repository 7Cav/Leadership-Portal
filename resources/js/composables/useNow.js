import { getCurrentScope, onScopeDispose, readonly, ref } from 'vue';

// One clock for every relative time on the page ("35 min ago"). It ticks once a minute while at
// least one component uses it and stops when the last one goes, so twenty stamps share a single
// interval and nothing keeps running after a page is left.

const TICK = 60 * 1000;

const now = ref(Date.now());
let users = 0;
let timer = null;

function stop() {
    users -= 1;
    if (users === 0) {
        clearInterval(timer);
        timer = null;
    }
}

export function useNow() {
    // A stamp that mounts mid-minute reads the time it mounted at, not the last tick.
    now.value = Date.now();

    // Outside a component or effect scope there is nothing to stop the clock again, so hand back
    // the current time without starting it.
    if (!getCurrentScope()) return readonly(now);

    if (users === 0) {
        timer = setInterval(() => {
            now.value = Date.now();
        }, TICK);
    }
    users += 1;
    onScopeDispose(stop);

    return readonly(now);
}
