// waypoint-live: connects every component on the page (<app-counter data-live>) to its server-side instance
// over one WebSocket, children placed by templates included. Events go up (data-on-click, -input, -change,
// -submit), the component's new HTML comes back and is patched into place. After a dropped connection the
// server hands back the same components for a while. Without the connection the page stays as rendered.
(() => {
    // Top-level components get an ID here; their children carry relative IDs (".0") from the server
    const roots = [...document.querySelectorAll('[data-live]')].filter((el) => !el.parentElement.closest('[data-live]'));
    if (roots.length === 0) return;
    roots.forEach((root, i) => {
        root.dataset.liveId = `c${i}`;
        for (const child of root.querySelectorAll('[data-live]')) child.dataset.liveId = `c${i}${child.dataset.liveId}`;
    });
    const queue = [];
    let socket = null;
    let ready = false;
    // The server's circuit for this tab: on a reconnect it hands back the components with their state
    let token = null;

    const send = (message) => {
        if (ready) socket.send(JSON.stringify(message));
        else queue.push(message);
    };

    const connect = () => {
        socket = new WebSocket(`${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/_live`);
        socket.onopen = () => socket.send(JSON.stringify({ t: 'hello', token }));
        socket.onmessage = (event) => {
            const message = JSON.parse(event.data);
            if (message.t === 'circuit') {
                token = message.token;
                // A new circuit (first connection, or the old one expired): the components start from the page's inputs
                if (!message.resumed) {
                    for (const root of roots) {
                        socket.send(JSON.stringify({ t: 'mount', id: root.dataset.liveId, c: root.localName, p: JSON.parse(root.dataset.inputs || '{}') }));
                    }
                }
                ready = true;
                for (const queued of queue.splice(0)) socket.send(JSON.stringify(queued));
                document.documentElement.removeAttribute('data-live-offline');
                return;
            }
            const el = document.querySelector(`[data-live-id="${CSS.escape(message.id)}"]`);
            if (!el) return;
            if (typeof message.html === 'string') patchChildren(el, message.html);
            el.toggleAttribute('data-live-error', message.error === true);
        };
        socket.onclose = () => {
            ready = false;
            document.documentElement.setAttribute('data-live-offline', '');
            setTimeout(connect, 1000);
        };
    };

    const call = (el, attribute, args) => {
        const root = el.closest('[data-live]');
        if (root) send({ t: 'call', id: root.dataset.liveId, m: el.getAttribute(attribute), a: args });
    };

    // data-on-click="select" data-arg="Berlin": the method, with an optional string argument
    Waypoint.directive('data-on-click', 'click', (el, event) => {
        event.preventDefault();
        call(el, 'data-on-click', el.hasAttribute('data-arg') ? [el.dataset.arg] : []);
    });

    const pending = new WeakMap();
    Waypoint.directive('data-on-input', 'input', (el) => {
        clearTimeout(pending.get(el));
        pending.set(el, setTimeout(() => call(el, 'data-on-input', [el.value]), 150));
    });

    Waypoint.directive('data-on-change', 'change', (el) => {
        call(el, 'data-on-change', [el.type === 'checkbox' ? el.checked : el.value]);
    });

    Waypoint.directive('data-on-submit', 'submit', (el, event) => {
        event.preventDefault();
        call(el, 'data-on-submit', [Object.fromEntries(new FormData(el))]);
    });

    const patchChildren = (target, html) => {
        const template = document.createElement('template');
        template.innerHTML = html;
        // Moving a keyed row drops the focus inside it: put it back where it was, caret included
        const focused = document.activeElement;
        const caret = focused instanceof HTMLInputElement || focused instanceof HTMLTextAreaElement ? [focused.selectionStart, focused.selectionEnd] : null;
        patch(target, template.content);
        if (focused && focused !== document.body && focused.isConnected && document.activeElement !== focused) {
            focused.focus();
            if (caret && caret[0] !== null) try { focused.setSelectionRange(...caret); } catch { /* type without a caret */ }
        }
    };

    // A child component's ID, or data-key="..." on any element: siblings with one are matched by it and moved,
    // the others by their order among the unkeyed ones
    const keyOf = (node) => node.nodeType === Node.ELEMENT_NODE ? node.getAttribute('data-key') ?? node.getAttribute('data-live-id') : null;

    const patch = (from, to) => {
        const keyed = new Map();
        const unkeyed = [];
        for (const child of from.childNodes) {
            const key = keyOf(child);
            if (key === null) unkeyed.push(child);
            else keyed.set(key, child);
        }
        let nextUnkeyed = 0;
        const next = [...to.childNodes];
        next.forEach((node, i) => {
            const key = keyOf(node);
            let old = key === null ? unkeyed[nextUnkeyed++] : keyed.get(key);
            if (key !== null) keyed.delete(key);
            if (old && old.nodeType === node.nodeType && old.nodeName === node.nodeName) {
                update(old, node);
            } else {
                old = node;
            }
            if (from.childNodes[i] !== old) from.insertBefore(old, from.childNodes[i] ?? null);
        });
        // Every new node now sits at its position; what is left behind them was not matched
        while (from.childNodes.length > next.length) from.lastChild.remove();
    };

    const update = (old, node) => {
        if (node.nodeType !== Node.ELEMENT_NODE) {
            if (old.nodeValue !== node.nodeValue) old.nodeValue = node.nodeValue;
            return;
        }
        for (const { name } of [...old.attributes]) if (!node.hasAttribute(name)) old.removeAttribute(name);
        for (const { name, value } of [...node.attributes]) if (old.getAttribute(name) !== value) old.setAttribute(name, value);
        // The server doesn't see what is being typed: the focused field keeps its value
        if (old instanceof HTMLInputElement && old !== document.activeElement) {
            old.value = node.getAttribute('value') ?? '';
            old.checked = node.hasAttribute('checked');
        }
        patch(old, node);
    };

    connect();
})();
