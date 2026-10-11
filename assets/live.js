// waypoint-live: connects every component on the page (<app-counter data-live>) to its server-side instance
// over one WebSocket, children placed by templates included. Events go up (data-on-click, -input, -change, -submit), the component's new HTML comes
// back and is patched into place. Without the connection the page stays as the server rendered it.
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

    const send = (message) => {
        if (socket?.readyState === WebSocket.OPEN) socket.send(JSON.stringify(message));
        else queue.push(message);
    };

    const connect = () => {
        socket = new WebSocket(`${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/_live`);
        socket.onopen = () => {
            // A new connection is a new circuit: the components start again from the page's inputs
            for (const root of roots) {
                socket.send(JSON.stringify({
                    t: 'mount',
                    id: root.dataset.liveId,
                    c: root.localName,
                    p: JSON.parse(root.dataset.inputs || '{}'),
                }));
            }
            for (const message of queue.splice(0)) socket.send(JSON.stringify(message));
            document.documentElement.removeAttribute('data-live-offline');
        };
        socket.onmessage = (event) => {
            const message = JSON.parse(event.data);
            const el = document.querySelector(`[data-live-id="${CSS.escape(message.id)}"]`);
            if (!el) return;
            if (typeof message.html === 'string') patchChildren(el, message.html);
            el.toggleAttribute('data-live-error', message.error === true);
        };
        socket.onclose = () => {
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
