// waypoint-live: connects every [wp-live] component on the page to its server-side instance over one
// WebSocket. Events go up (wp-live-click, wp-live-input, wp-live-submit), the component's new HTML comes
// back and is patched into place. Without the connection the page stays as the server rendered it.
(() => {
    const roots = [...document.querySelectorAll('[wp-live]')];
    if (roots.length === 0) return;
    roots.forEach((root, i) => { root.dataset.liveId = `c${i}`; });
    const byId = new Map(roots.map((root) => [root.dataset.liveId, root]));
    const queue = [];
    let socket = null;

    const send = (message) => {
        if (socket?.readyState === WebSocket.OPEN) socket.send(JSON.stringify(message));
        else queue.push(message);
    };

    const connect = () => {
        socket = new WebSocket(`${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/_live`);
        socket.onopen = () => {
            // A new connection is a new circuit: the components start again from the page's props
            for (const root of roots) {
                socket.send(JSON.stringify({
                    t: 'mount',
                    id: root.dataset.liveId,
                    c: root.getAttribute('wp-live'),
                    p: JSON.parse(root.getAttribute('wp-live-props') || '{}'),
                }));
            }
            for (const message of queue.splice(0)) socket.send(JSON.stringify(message));
            document.documentElement.removeAttribute('wp-live-offline');
        };
        socket.onmessage = (event) => {
            const message = JSON.parse(event.data);
            const root = byId.get(message.id);
            if (!root) return;
            if (typeof message.html === 'string') patchChildren(root, message.html);
            root.toggleAttribute('wp-live-error', message.error === true);
        };
        socket.onclose = () => {
            document.documentElement.setAttribute('wp-live-offline', '');
            setTimeout(connect, 1000);
        };
    };

    const call = (el, attribute, args) => {
        const root = el.closest('[wp-live]');
        if (root) send({ t: 'call', id: root.dataset.liveId, m: el.getAttribute(attribute), a: args });
    };

    Waypoint.directive('wp-live-click', 'click', (el, event) => {
        event.preventDefault();
        call(el, 'wp-live-click', el.hasAttribute('wp-live-arg') ? [el.getAttribute('wp-live-arg')] : []);
    });

    const pending = new WeakMap();
    Waypoint.directive('wp-live-input', 'input', (el) => {
        clearTimeout(pending.get(el));
        pending.set(el, setTimeout(() => call(el, 'wp-live-input', [el.value]), 150));
    });

    Waypoint.directive('wp-live-submit', 'submit', (el, event) => {
        event.preventDefault();
        call(el, 'wp-live-submit', [Object.fromEntries(new FormData(el))]);
    });

    // ponytail: patches by position, no keys; a reordered list rewrites its rows instead of moving them
    const patchChildren = (target, html) => {
        const template = document.createElement('template');
        template.innerHTML = html;
        patch(target, template.content);
    };

    const patch = (from, to) => {
        const current = [...from.childNodes];
        const next = [...to.childNodes];
        next.forEach((node, i) => {
            const old = current[i];
            if (!old) {
                from.appendChild(node);
            } else if (old.nodeType !== node.nodeType || old.nodeName !== node.nodeName) {
                from.replaceChild(node, old);
            } else if (node.nodeType === Node.ELEMENT_NODE) {
                for (const { name } of [...old.attributes]) if (!node.hasAttribute(name)) old.removeAttribute(name);
                for (const { name, value } of [...node.attributes]) if (old.getAttribute(name) !== value) old.setAttribute(name, value);
                // The server doesn't see what is being typed: the focused field keeps its value
                if (old instanceof HTMLInputElement && old !== document.activeElement) {
                    old.value = node.getAttribute('value') ?? '';
                    old.checked = node.hasAttribute('checked');
                }
                patch(old, node);
            } else if (old.nodeValue !== node.nodeValue) {
                old.nodeValue = node.nodeValue;
            }
        });
        for (let i = current.length - 1; i >= next.length; i--) current[i].remove();
    };

    connect();
})();
