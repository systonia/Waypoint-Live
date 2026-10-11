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
    let attempt = 0;

    const send = (message) => {
        if (ready) socket.send(JSON.stringify(message));
        else queue.push(message);
    };

    const connect = () => {
        socket = new WebSocket(`${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/_live`);
        socket.onopen = () => socket.send(JSON.stringify({ t: 'hello', token }));
        socket.onmessage = (event) => {
            const message = JSON.parse(event.data);
            // An action sent the browser elsewhere: a path on this site, the server made sure
            if (message.t === 'navigate') {
                location.assign(message.url);
                return;
            }
            if (message.t === 'circuit') {
                token = message.token;
                attempt = 0;
                // A new circuit (first connection, or the old one expired): the components start from the page's
                // inputs, and what was clicked offline was meant for the old ones
                if (!message.resumed) {
                    queue.length = 0;
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
            setTimeout(connect, Math.min(1000 * 2 ** attempt++, 30000));
        };
    };

    // Events go out in the order they happened, even when one first has to read a file: each takes its place
    // right away and is sent once its arguments are ready. Component and method are read at that moment too,
    // a patch in between can't change them.
    let order = Promise.resolve();
    const enqueue = (el, attribute, args) => {
        const root = el.closest('[data-live]');
        if (!root) return;
        const id = root.dataset.liveId;
        const method = el.getAttribute(attribute);
        order = order
            .then(async () => send({ t: 'call', id, m: method, a: await args }))
            .catch((error) => {
                // A file the browser couldn't read: the component shows it like a failed call
                root.setAttribute('data-live-error', '');
                console.error(error);
            });
    };

    // Typing waits 150 ms; any other event sends what was typed first, so a quick click doesn't overtake it
    const pending = new Map();
    const flush = () => {
        for (const [el, timer] of pending) {
            clearTimeout(timer);
            pending.delete(el);
            enqueue(el, 'data-on-input', [el.value]);
        }
    };

    const call = (el, attribute, args) => {
        flush();
        enqueue(el, attribute, args);
    };

    // data-on-click="select" data-arg="Berlin": the method, with an optional string argument
    Waypoint.directive('data-on-click', 'click', (el, event) => {
        event.preventDefault();
        call(el, 'data-on-click', el.hasAttribute('data-arg') ? [el.dataset.arg] : []);
    });

    Waypoint.directive('data-on-input', 'input', (el) => {
        clearTimeout(pending.get(el));
        pending.set(el, setTimeout(() => {
            pending.delete(el);
            enqueue(el, 'data-on-input', [el.value]);
        }, 150));
    });

    // A file goes along base64-encoded; the server hands the method an UploadedFile
    const encode = (file) => new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            const comma = reader.result.indexOf(',');
            resolve({ $file: { name: file.name, type: file.type, data: comma < 0 ? '' : reader.result.slice(comma + 1) } });
        };
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(file);
    });

    // A form as an object: "photos[]" collects a list, an empty file input is left out. The fields are taken
    // when the form is submitted; only reading the files waits.
    const formObject = async (form) => {
        const object = {};
        for (const [name, value] of [...new FormData(form)]) {
            if (value instanceof File && value.name === '' && value.size === 0) continue;
            const entry = value instanceof File ? await encode(value) : value;
            if (!name.endsWith('[]')) {
                object[name] = entry;
                continue;
            }
            const key = name.slice(0, -2);
            object[key] = [...(Array.isArray(object[key]) ? object[key] : key in object ? [object[key]] : []), entry];
        }
        return object;
    };

    Waypoint.directive('data-on-change', 'change', (el) => {
        if (el.type === 'file') {
            const files = Promise.all([...el.files].map(encode));
            call(el, 'data-on-change', files.then((list) => [el.multiple ? list : list[0] ?? null]));
            return;
        }
        call(el, 'data-on-change', [el.type === 'checkbox' ? el.checked : el.value]);
    });

    Waypoint.directive('data-on-submit', 'submit', (el, event) => {
        event.preventDefault();
        call(el, 'data-on-submit', formObject(el).then((form) => [form]));
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
    // the others by their order among the unkeyed ones of the same tag, so an element showing up in front of an
    // input doesn't take the input's place
    const keyOf = (node) => node.nodeType === Node.ELEMENT_NODE ? node.getAttribute('data-key') ?? node.getAttribute('data-live-id') : null;

    const patch = (from, to) => {
        const keyed = new Map();
        const unkeyed = new Map();
        for (const child of from.childNodes) {
            const key = keyOf(child);
            if (key !== null) keyed.set(key, child);
            else if (unkeyed.has(child.nodeName)) unkeyed.get(child.nodeName).push(child);
            else unkeyed.set(child.nodeName, [child]);
        }
        const next = [...to.childNodes];
        next.forEach((node, i) => {
            const key = keyOf(node);
            let old = key === null ? unkeyed.get(node.nodeName)?.shift() : keyed.get(key);
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

    // What the server last rendered into a form field; null for anything else
    const rendered = (el) => {
        if (el instanceof HTMLInputElement && el.type !== 'file') return { value: el.getAttribute('value') ?? '', checked: el.hasAttribute('checked') };
        if (el instanceof HTMLTextAreaElement) return { value: el.defaultValue, checked: false };
        if (el instanceof HTMLSelectElement) return { value: [...el.options].map((o) => o.defaultSelected).join(), checked: false };
        return null;
    };

    const update = (old, node) => {
        if (node.nodeType !== Node.ELEMENT_NODE) {
            if (old.nodeValue !== node.nodeValue) old.nodeValue = node.nodeValue;
            return;
        }
        // data-live-ignore: the element is the browser's once it's on the page (an editor, a map, a widget)
        if (old.hasAttribute('data-live-ignore') && node.hasAttribute('data-live-ignore')) return;
        const before = rendered(old);
        for (const { name } of [...old.attributes]) if (!node.hasAttribute(name)) old.removeAttribute(name);
        for (const { name, value } of [...node.attributes]) if (old.getAttribute(name) !== value) old.setAttribute(name, value);
        patch(old, node);
        if (before === null) return;
        // A field keeps what the user typed or picked until the server renders a different value. A focused
        // field takes it only if the server had what is in it: a render lagging behind the typing loses nothing.
        const after = rendered(old);
        if (after.value !== before.value && (old instanceof HTMLSelectElement || old !== document.activeElement || old.value === before.value)) {
            if (old instanceof HTMLSelectElement) for (const option of old.options) option.selected = option.defaultSelected;
            else old.value = after.value;
        }
        if (after.checked !== before.checked) old.checked = after.checked;
    };

    connect();
})();
