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
    // In dev, the server process the page came from: another one means PHP was reloaded, and the page with it
    let boot = null;
    // In dev, how a file opens in the editor
    let editor = null;
    // localStorage.liveDebug = '1' or ?live-debug: every action in the console, with what it cost
    let debug = false;
    try { debug = localStorage.getItem('liveDebug') === '1' || new URLSearchParams(location.search).has('live-debug'); } catch { /* storage blocked */ }
    // When the oldest unanswered call left, and how long each component's last patch took
    const calls = [];
    const patched = new Map();
    // In dev: the state of every top-level component and its children, to give it back after PHP reloaded
    const states = new Map();

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
                // In dev, PHP ran again: only a component changed, so the components come back with their state;
                // anything else, the page loads again
                const restarted = message.boot && boot !== null && message.boot !== boot && !message.resumed;
                if (restarted && message.reload !== 'state') {
                    location.reload();
                    return;
                }
                boot = message.boot ?? null;
                editor = message.editor ?? null;
                // In dev: live.inspect('c0') prints what the component holds on the server
                if (boot) window.live = { inspect: (id) => send({ t: 'inspect', id }) };
                token = message.token;
                attempt = 0;
                // A new circuit (first connection, or the old one expired): the components start from the page's
                // inputs, and what was clicked offline was meant for the old ones
                if (!message.resumed) {
                    queue.length = 0;
                    for (const root of roots) {
                        const id = root.dataset.liveId;
                        const s = restarted ? states.get(id) : undefined;
                        socket.send(JSON.stringify({ t: 'mount', id, c: root.localName, p: JSON.parse(root.dataset.inputs || '{}'), s }));
                    }
                }
                ready = true;
                for (const queued of queue.splice(0)) socket.send(JSON.stringify(queued));
                document.documentElement.removeAttribute('data-live-offline');
                return;
            }
            if (message.t === 'trace') {
                const started = message.cause.startsWith('call') ? calls.shift() : undefined;
                for (const warning of message.warnings) console.warn(`live: ${warning}`);
                if (!debug) return;
                const sent = message.renders.filter((r) => r[3] !== null);
                const bytes = sent.reduce((sum, r) => sum + r[3], 0);
                console.groupCollapsed(`live ${message.cause} → ${message.ms} ms server${started !== undefined ? ` · ${Math.round(performance.now() - started)} ms round trip` : ''} · ${sent.length} of ${message.renders.length} sent · ${(bytes / 1024).toFixed(1)} KB`);
                for (const [id, selector, ms, size] of message.renders) {
                    console.log(`${id.padEnd(6)} <${selector}> rendered ${ms} ms  ${size === null ? 'unchanged' : `sent ${(size / 1024).toFixed(1)} KB, patched ${patched.get(id)?.toFixed(1) ?? '?'} ms`}`);
                }
                console.groupEnd();
                return;
            }
            if (message.t === 'inspection') {
                console.log(`live.inspect('${message.id}')`, message.state ?? 'no such component');
                return;
            }
            // In dev: a saved template that does not compile, until it does
            if (message.t === 'problem') {
                overlay.show(message.key, message.error);
                return;
            }
            const el = document.querySelector(`[data-live-id="${CSS.escape(message.id)}"]`);
            if (!el) return;
            if (typeof message.html === 'string') {
                const before = performance.now();
                patchChildren(el, message.html);
                patched.set(message.id, performance.now() - before);
                // Its children's errors too: only top-level components get HTML of their own
                overlay.clear(`${message.id}.`);
                // The whole top-level component with its children, by their paths (c0/app-row=Berlin)
                if (message.state) states.set(message.id, message.state);
                overlay.show(message.id, null);
            }
            el.toggleAttribute('data-live-error', Boolean(message.error));
            // In dev the error comes with what happened
            if (typeof message.error === 'object' && message.error !== null) overlay.show(message.id, message.error);
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
            .then(async () => {
                const message = { t: 'call', id, m: method, a: await args };
                // Only dev sends traces that answer them
                if (boot) calls.push(performance.now());
                send(message);
            })
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

    // A form as an object: "photos[]" collects a list, an empty file input is left out, the button that
    // submitted it is in with its name and value. The fields are taken when the form is submitted; only reading
    // the files waits.
    const formObject = async (form, submitter) => {
        const object = {};
        for (const [name, value] of [...new FormData(form, submitter)]) {
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
        call(el, 'data-on-submit', formObject(el, event.submitter).then((form) => [form]));
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

    // In dev: what failed, with the template's lines and the stack, in a shadow root the page's CSS can't reach.
    // An entry goes when its component renders again; the same error again counts up instead of stacking.
    const overlay = (() => {
        const entries = new Map();
        let root = null;
        const escape = (text) => String(text).replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
        const link = (file, line) => {
            const text = `${escape(file.split('/').slice(-2).join('/'))}:${line}`;
            return editor ? `<a href="${escape(editor.replace('{file}', file).replace('{line}', line))}">${text}</a>` : text;
        };
        const render = (error, count) => {
            const frames = (vendor) => error.trace.filter((f) => f.vendor === vendor).map((f) => `<li>${escape(f.call)} ${link(f.file, f.line)}</li>`).join('');
            const vendor = error.trace.filter((f) => f.vendor).length;
            return `<header>${link(error.file, error.line)}${count > 1 ? ` <b>×${count}</b>` : ''}<button title="Close">×</button></header>`
                + `<p><strong>${escape(error.class)}</strong>: ${escape(error.message)}</p>`
                + `<p class="where">${error.selector ? `&lt;${escape(error.selector)}&gt; · ` : ''}${escape(error.cause)}`
                + `${error.template && error.template[0] !== error.file ? ` · template ${link(error.template[0], error.template[1])}` : ''}</p>`
                + (error.snippet.length ? `<pre>${error.snippet.map(([n, text]) => `<span${n === error.line ? ' class="at"' : ''}>${String(n).padStart(4)} | ${escape(text)}</span>`).join('\n')}</pre>` : '')
                + `<ul>${frames(false)}</ul>${vendor ? `<details><summary>${vendor} ${vendor === 1 ? 'frame' : 'frames'} of Live and vendor</summary><ul>${frames(true)}</ul></details>` : ''}`;
        };
        const mount = () => {
            const host = document.createElement('live-errors');
            document.body.append(host);
            root = host.attachShadow({ mode: 'open' });
            root.innerHTML = `<style>
                :host { position: fixed; inset: auto 1rem 1rem 1rem; z-index: 2147483647; max-height: 70vh; overflow: auto; font: 13px/1.45 ui-monospace, monospace; }
                section { background: #1e1e24; color: #eee; border-left: 4px solid #e5484d; border-radius: 6px; padding: .75rem 1rem; margin-top: .5rem; box-shadow: 0 4px 24px #0006; }
                header { display: flex; gap: .5rem; align-items: center; color: #ffb4b4; }
                button { margin-left: auto; background: none; border: 0; color: inherit; font-size: 18px; cursor: pointer; }
                a { color: #8ab4ff; } p { margin: .35rem 0; } .where { color: #aaa; }
                pre { background: #0003; padding: .5rem; overflow: auto; margin: .5rem 0; } .at { background: #e5484d44; display: inline-block; min-width: 100%; }
                ul { margin: .25rem 0; padding-left: 1.25rem; } details { color: #aaa; }
            </style>`;
        };
        return {
            // Every entry whose key starts with prefix
            clear(prefix) {
                for (const key of [...entries.keys()]) if (key.startsWith(prefix)) this.show(key, null);
            },
            show(key, error) {
                const entry = entries.get(key);
                if (!error) {
                    entry?.el.remove();
                    entries.delete(key);
                    return;
                }
                if (!root) mount();
                const same = entry && entry.error.message === error.message && entry.error.file === error.file && entry.error.line === error.line;
                const count = same ? entry.count + 1 : 1;
                const el = entry?.el ?? document.createElement('section');
                el.innerHTML = render(error, count);
                el.querySelector('header button').onclick = () => { el.remove(); entries.delete(key); };
                if (!entry) root.append(el);
                entries.set(key, { el, error, count });
                if (!same) console.error(`live: ${error.class}: ${error.message}\n    at ${error.file}:${error.line} (${error.cause})`, error);
            },
        };
    })();

    connect();
})();
