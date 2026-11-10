(() => {
    const root = document.getElementById('scr-admin');
    if (!root) return;

    const config = window.scrAdmin;
    const { __, sprintf } = window.wp.i18n;
    const copy = (value) => JSON.parse(JSON.stringify(value));
    const split = (value) => value.split(',').map((part) => part.trim()).filter(Boolean);
    const listTypes = ['ids', 'keys', 'coupons', 'methods', 'key', 'code'];
    const scopeKeys = ['products', 'categories'];
    const moduleLabels = {
        payment: __('Payment methods', 'smart-checkout-rules'), shipping: __('Shipping restrictions', 'smart-checkout-rules'),
        limits: __('Order limits', 'smart-checkout-rules'), validation: __('Checkout validation', 'smart-checkout-rules'),
    };
    const operatorLabels = {
        eq: __('Equals', 'smart-checkout-rules'), neq: __('Does not equal', 'smart-checkout-rules'), lt: __('Less than', 'smart-checkout-rules'), lte: __('At most', 'smart-checkout-rules'),
        gt: __('Greater than', 'smart-checkout-rules'), gte: __('At least', 'smart-checkout-rules'), in: __('Matches any of', 'smart-checkout-rules'),
        not_in: __('Matches none of', 'smart-checkout-rules'), empty: __('Is empty', 'smart-checkout-rules'), not_empty: __('Is present', 'smart-checkout-rules'),
    };
    const actionLabels = {
        hide_gateway: __('Hide gateway', 'smart-checkout-rules'), allow_gateway: __('Allow eligible gateway', 'smart-checkout-rules'),
        hide_rate: __('Hide shipping rates', 'smart-checkout-rules'), quote_only: __('Keep only quote method', 'smart-checkout-rules'),
        minimum: __('Minimum', 'smart-checkout-rules'), maximum: __('Maximum', 'smart-checkout-rules'), required: __('Require a field', 'smart-checkout-rules'),
        length: __('Check field length', 'smart-checkout-rules'), email_allow: __('Allow email domains', 'smart-checkout-rules'),
        email_block: __('Block email domains', 'smart-checkout-rules'), pattern: __('Match a pattern', 'smart-checkout-rules'),
        postcode: __('Validate postcode', 'smart-checkout-rules'), phone: __('Validate phone', 'smart-checkout-rules'), reject: __('Block checkout', 'smart-checkout-rules'),
    };
    const metricLabels = {
        subtotal_after_discount_ex_tax: __('Subtotal after discounts, excluding tax', 'smart-checkout-rules'),
        subtotal_before_discount_ex_tax: __('Subtotal before discounts, excluding tax', 'smart-checkout-rules'),
        subtotal_after_discount_inc_tax: __('Subtotal after discounts, including tax', 'smart-checkout-rules'),
        subtotal_before_discount_inc_tax: __('Subtotal before discounts, including tax', 'smart-checkout-rules'),
        quantity: __('Item quantity', 'smart-checkout-rules'),
    };
    let snapshot;
    let dirty = false;
    let busy = false;
    let tab = 'rules';
    let search = '';
    let filter = '';
    let activeRule;
    let selected = new Set();
    const status = node('p', '', { role: 'status', 'aria-live': 'polite', tabindex: '-1' });
    const content = node('div');
    const datalist = node('div');
    const scenario = { values: {}, fields: {}, gateways: Object.keys(config.gateways), rates: Object.keys(config.shipping), lines: [] };
    let result;

    function node(tag, text = '', attributes = {}) {
        const element = document.createElement(tag);
        if (text) element.textContent = text;
        Object.entries(attributes).forEach(([name, value]) => element.setAttribute(name, value));
        return element;
    }

    function button(label, callback, primary = false) {
        const element = node('button', label, { type: 'button', class: primary ? 'button button-primary' : 'button' });
        element.addEventListener('click', callback);
        return element;
    }

    function row(...items) {
        const element = node('p', '', { class: 'scr-row' });
        element.append(...items);
        return element;
    }

    function label(name, control) {
        const element = node('label');
        element.append(node('span', name + ' '), control);
        return element;
    }

    function input(name, value, callback, attributes = {}) {
        const element = node('input', '', { type: 'text', ...attributes });
        element.value = value ?? '';
        element.addEventListener('input', () => callback(element.value));
        return label(name, element);
    }

    function checkbox(name, value, callback) {
        const element = node('input', '', { type: 'checkbox' });
        element.checked = Boolean(value);
        element.addEventListener('change', () => callback(element.checked));
        const wrapper = node('label');
        wrapper.append(element, document.createTextNode(' ' + name));
        return wrapper;
    }

    function dropdown(name, choices, value, callback) {
        const element = node('select');
        Object.entries(choices).forEach(([id, text]) => {
            element.append(node('option', text, { value: id }));
        });
        element.value = value;
        element.addEventListener('change', () => callback(element.value));
        return label(name, element);
    }

    function id() {
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const value = Array.from(bytes, (part) => part.toString(16).padStart(2, '0')).join('');
        return [value.slice(0, 8), value.slice(8, 12), value.slice(12, 16), value.slice(16, 20), value.slice(20)].join('-');
    }

    function changed() {
        dirty = true;
        status.textContent = __('Unsaved changes.', 'smart-checkout-rules');
    }

    function edit(object, key, value) {
        if (key === '__proto__' || key === 'constructor' || key === 'prototype') return;
        Object.defineProperty(object, key, { value, writable: true, enumerable: true, configurable: true });
        changed();
    }

    function request(path, method = 'GET', data) {
        return window.wp.apiFetch({ path: '/scr/v1/' + path, method, data, headers: { 'X-WP-Nonce': config.nonce } });
    }

    function hydrate(configuration) {
        configuration.rules.forEach((rule) => rule.actions.forEach((action) => {
            if (rule.module === 'limits') action.scope = { ...action.scope };
        }));
        return configuration;
    }

    async function save() {
        if (busy) return;
        busy = true;
        const sent = JSON.stringify({ rules: snapshot.rules, settings: snapshot.settings });
        try {
            const response = await request('configuration', 'PUT', { ...JSON.parse(sent), revision: snapshot.revision });
            if (JSON.stringify({ rules: snapshot.rules, settings: snapshot.settings }) === sent) {
                snapshot = hydrate(response);
                dirty = false;
                render();
                status.textContent = __('Rules and settings saved.', 'smart-checkout-rules');
            } else {
                snapshot.revision = response.revision;
                status.textContent = __('Saved the submitted version. Newer changes are still unsaved.', 'smart-checkout-rules');
            }
        } catch (error) {
            status.textContent = error.message || __('The rules could not be saved.', 'smart-checkout-rules');
        } finally {
            busy = false;
            status.focus();
        }
    }

    function baseAction(module) {
        if (module === 'payment') return { type: 'hide_gateway', gateway_id: '' };
        if (module === 'shipping') return { type: 'hide_rate', method_id: '' };
        if (module === 'limits') return { type: 'minimum', metric: config.metrics[1], amount: '', currency: 'store', scope: {}, progress: false };
        return { type: 'required', field: 'billing_company' };
    }

    function makeRule(module = 'payment') {
        return {
            schema_version: 1, id: id(), module, enabled: false, name: '',
            priority: 100, stop_processing: false, message: '', conditions: { all: [] },
            actions: [baseAction(module)],
        };
    }

    function addRule(rule = makeRule()) {
        if (snapshot.rules.length >= config.maxRules) {
            status.textContent = __('The rule limit has been reached.', 'smart-checkout-rules');
            return;
        }
        snapshot.rules.push(rule);
        search = '';
        filter = '';
        activeRule = rule.id;
        changed();
        render();
        root.querySelector('[data-active-rule] input')?.focus();
    }

    function fieldName(field) {
        const name = field.split('.').pop();
        return metricLabels[name] ? field.split('.')[0] + ' / ' + metricLabels[name] : field.replaceAll('_', ' ').replace('.', ' / ');
    }

    function newCondition(field) {
        const type = config.fields[field];
        const operator = config.operators[type][0];
        const value = type === 'boolean' ? true : listTypes.includes(type) ? [] : '';
        return { field, operator, value };
    }

    function conditionUI(rule, group, depth = 0) {
        const panel = node('fieldset', '', { class: 'scr-panel' });
        panel.append(node('legend', depth ? __('Condition group', 'smart-checkout-rules') : __('Conditions', 'smart-checkout-rules')));
        const key = Object.hasOwn(group, 'all') ? 'all' : 'any';
        const children = group[key];
        panel.append(row(dropdown(__('Match', 'smart-checkout-rules'), { all: __('All conditions (AND)', 'smart-checkout-rules'), any: __('Any condition (OR)', 'smart-checkout-rules') }, key, (next) => {
            delete group[key];
            edit(group, next, children);
            render();
        })));
        const choices = Object.fromEntries(Object.keys(config.fields)
            .filter((field) => rule.module === 'shipping' ? config.shippingFields.includes(field) : !field.startsWith('package.'))
            .filter((field) => rule.module !== 'payment' || config.paymentFields.includes(field))
            .map((field) => [field, fieldName(field)]));
        children.forEach((condition, index) => {
            const remove = button(__('Remove condition', 'smart-checkout-rules'), () => { children.splice(index, 1); changed(); render(); });
            if (condition.all || condition.any) {
                panel.append(conditionUI(rule, condition, depth + 1), row(remove));
                return;
            }
            const type = config.fields[condition.field];
            const fields = dropdown(__('Field', 'smart-checkout-rules'), choices, condition.field, (next) => { edit(children, index, newCondition(next)); render(); });
            const operations = dropdown(__('Operator', 'smart-checkout-rules'), Object.fromEntries(config.operators[type].map((op) => [op, operatorLabels[op]])), condition.operator, (next) => {
                edit(condition, 'operator', next);
                if (['empty', 'not_empty'].includes(next)) condition.value = null;
                else if (condition.value === null) condition.value = newCondition(condition.field).value;
                render();
            });
            const controls = [fields, operations];
            if (condition.value !== null) {
                if (type === 'boolean') {
                    controls.push(checkbox(__('True', 'smart-checkout-rules'), condition.value, (value) => edit(condition, 'value', value)));
                } else if (listTypes.includes(type)) {
                    controls.push(input(__('Values, comma separated', 'smart-checkout-rules'), condition.value.join(', '), (value) => edit(condition, 'value', split(value))));
                } else {
                    controls.push(input(__('Value', 'smart-checkout-rules'), condition.value, (value) => edit(condition, 'value', value), { inputmode: 'decimal' }));
                }
            }
            panel.append(row(...controls, remove));
        });
        if (depth < 4) {
            const actions = [button(__('Add condition', 'smart-checkout-rules'), () => { children.push(newCondition(Object.keys(choices)[0])); changed(); render(); })];
            if (depth < 3) actions.push(button(__('Add nested group', 'smart-checkout-rules'), () => { children.push({ all: [] }); changed(); render(); }));
            panel.append(row(...actions));
        }
        return panel;
    }

    function actionUI(rule, action, index) {
        const panel = node('fieldset', '', { class: 'scr-panel' });
        panel.append(node('legend', sprintf(__('Action %d', 'smart-checkout-rules'), index + 1)));
        panel.append(row(dropdown(__('Action', 'smart-checkout-rules'), Object.fromEntries(config.actions[rule.module].map((type) => [type, actionLabels[type]])), action.type, (type) => {
            const next = { ...baseAction(rule.module), type };
            if (type === 'length') Object.assign(next, { min: 1, max: 120 });
            if (type === 'pattern') next.pattern = '';
            if (type === 'postcode') next.field = 'billing_postcode';
            if (type === 'phone') next.field = 'billing_phone';
            if (type === 'reject') delete next.field;
            if (type.startsWith('email_')) Object.assign(next, { field: 'billing_email', domains: [] });
            edit(rule.actions, index, next);
            render();
        })));
        if (rule.module === 'payment') {
            panel.append(row(input(__('Gateway ID', 'smart-checkout-rules'), action.gateway_id, (value) => edit(action, 'gateway_id', value), { list: 'scr-gateways', maxlength: 128 })));
        } else if (rule.module === 'shipping') {
            panel.append(row(input(__('Rate or method ID', 'smart-checkout-rules'), action.method_id, (value) => edit(action, 'method_id', value), { list: 'scr-shipping', maxlength: 128 })));
        } else if (rule.module === 'limits') {
            panel.append(
                row(dropdown(__('Measure', 'smart-checkout-rules'), metricLabels, action.metric, (value) => { edit(action, 'metric', value); render(); }),
                    input(__('Threshold', 'smart-checkout-rules'), action.amount, (value) => edit(action, 'amount', value), { inputmode: 'decimal' })),
                row(input(__('Currency: store or three-letter code', 'smart-checkout-rules'), action.currency, (value) => edit(action, 'currency', value)),
                    checkbox(__('Show remaining amount', 'smart-checkout-rules'), action.progress, (value) => edit(action, 'progress', value))),
                row(...scopeKeys.map((key) => input(key === 'products' ? __('Qualifying product IDs', 'smart-checkout-rules') : __('Qualifying category IDs', 'smart-checkout-rules'), (action.scope[key] || []).join(', '), (value) => {
                    const ids = split(value);
                    if (ids.length) edit(action.scope, key, ids);
                    else { delete action.scope[key]; changed(); }
                })))
            );
        } else if (action.type !== 'reject') {
            const fields = Object.fromEntries(Object.entries(config.checkoutFields).filter(([field]) => {
                if (action.type === 'postcode' || action.type === 'phone') return field.endsWith('_' + action.type);
                if (action.type.startsWith('email_')) return field === 'billing_email';
                return true;
            }));
            panel.append(row(dropdown(__('Checkout field', 'smart-checkout-rules'), fields, action.field, (value) => edit(action, 'field', value))));
            if (action.type === 'length') {
                panel.append(row(input(__('Minimum length', 'smart-checkout-rules'), action.min, (value) => edit(action, 'min', Number(value)), { type: 'number', min: '0', max: '1024' }),
                    input(__('Maximum length', 'smart-checkout-rules'), action.max, (value) => edit(action, 'max', Number(value)), { type: 'number', min: '0', max: '1024' })));
            }
            if (action.type === 'pattern') panel.append(row(input(__('Pattern without delimiters', 'smart-checkout-rules'), action.pattern, (value) => edit(action, 'pattern', value), { maxlength: 160 })));
            if (action.type.startsWith('email_')) panel.append(row(input(__('Domains, comma separated', 'smart-checkout-rules'), action.domains.join(', '), (value) => edit(action, 'domains', split(value)))));
        }
        if (rule.actions.length > 1) {
            panel.append(row(button(__('Remove action', 'smart-checkout-rules'), () => { rule.actions.splice(index, 1); changed(); render(); })));
        }
        return panel;
    }

    function ruleUI(rule) {
        const item = node('details', '', { class: 'scr-panel' });
        if (rule.id === activeRule) {
            item.open = true;
            item.dataset.activeRule = 'true';
        }
        const title = node('summary');
        const heading = node('strong', rule.name || __('New rule', 'smart-checkout-rules'));
        title.append(heading, document.createTextNode(' — ' + moduleLabels[rule.module] + ' · ' +
            (rule.enabled ? __('Enabled', 'smart-checkout-rules') : __('Disabled', 'smart-checkout-rules')) + ' · ' + rule.priority));
        if (rule.updated_at) title.append(document.createTextNode(' · ' + new Date(rule.updated_at).toLocaleDateString()));
        item.append(title);
        item.addEventListener('toggle', () => {
            if (item.open) activeRule = rule.id;
            else if (activeRule === rule.id) activeRule = undefined;
        });
        item.append(
            row(input(__('Name', 'smart-checkout-rules'), rule.name, (value) => { edit(rule, 'name', value); heading.textContent = value || __('New rule', 'smart-checkout-rules'); }, { maxlength: 120 }),
                dropdown(__('Module', 'smart-checkout-rules'), moduleLabels, rule.module, (value) => {
                    if (!window.confirm(__('Changing the module resets this rule’s conditions and actions. Continue?', 'smart-checkout-rules'))) { render(); return; }
                    rule.conditions = { all: [] };
                    rule.actions = [baseAction(value)];
                    edit(rule, 'module', value);
                    render();
                }),
                input(__('Priority', 'smart-checkout-rules'), rule.priority, (value) => edit(rule, 'priority', Number(value)), { type: 'number', min: '0', max: '100000' })),
            row(checkbox(__('Enabled', 'smart-checkout-rules'), rule.enabled, (value) => edit(rule, 'enabled', value)),
                checkbox(__('Stop after this rule matches', 'smart-checkout-rules'), rule.stop_processing, (value) => edit(rule, 'stop_processing', value))),
            row(input(__('Customer message', 'smart-checkout-rules'), rule.message, (value) => edit(rule, 'message', value), { maxlength: 480, size: 45 })),
            conditionUI(rule, rule.conditions),
            ...rule.actions.map((action, index) => actionUI(rule, action, index))
        );
        if (rule.actions.length < 8) item.append(row(button(__('Add action', 'smart-checkout-rules'), () => { rule.actions.push(baseAction(rule.module)); changed(); render(); })));
        item.append(row(button(__('Duplicate', 'smart-checkout-rules'), () => addRule({ ...copy(rule), id: id(), enabled: false, name: (rule.name + ' ' + __('(copy)', 'smart-checkout-rules')).slice(0, 120) })),
            button(__('Delete', 'smart-checkout-rules'), () => {
                if (!window.confirm(__('Delete this rule from your draft?', 'smart-checkout-rules'))) return;
                snapshot.rules = snapshot.rules.filter((entry) => entry.id !== rule.id);
                selected.delete(rule.id);
                changed();
                render();
            })));
        return item;
    }

    function ruleList() {
        const list = node('div');
        const searchInput = input(__('Search rules', 'smart-checkout-rules'), search, (value) => { search = value; updateList(); });
        const filterInput = dropdown(__('Module', 'smart-checkout-rules'), { '': __('All modules', 'smart-checkout-rules'), ...moduleLabels }, filter, (value) => { filter = value; updateList(); });
        function updateList() {
            list.replaceChildren();
            const ordered = snapshot.rules.slice().sort((a, b) => a.priority - b.priority || a.id.localeCompare(b.id));
            ordered.filter((rule) => (!filter || rule.module === filter) && rule.name.toLowerCase().includes(search.toLowerCase()))
                .forEach((rule) => {
                    const item = node('div');
                    item.append(checkbox(sprintf(__('Select %s', 'smart-checkout-rules'), rule.name || __('new rule', 'smart-checkout-rules')), selected.has(rule.id), (value) => {
                        if (value) selected.add(rule.id);
                        else selected.delete(rule.id);
                    }), ruleUI(rule));
                    list.append(item);
                });
            if (!list.children.length) list.append(node('p', __('No rules match.', 'smart-checkout-rules')));
        }
        const bulk = (enabled) => {
            snapshot.rules.forEach((rule) => { if (selected.has(rule.id)) rule.enabled = enabled; });
            if (selected.size) changed();
            render();
        };
        updateList();
        content.append(row(button(__('Add rule', 'smart-checkout-rules'), () => addRule()), button(__('Enable selected', 'smart-checkout-rules'), () => bulk(true)),
            button(__('Disable selected', 'smart-checkout-rules'), () => bulk(false)), searchInput, filterInput), list);
    }

    function scenarioPanel() {
        const panel = node('fieldset', '', { class: 'scr-panel' });
        panel.append(node('legend', __('Hypothetical cart and one shipping package', 'smart-checkout-rules')));
        panel.append(node('p', __('Use fictional checkout details. If you add simulated line items, their quantities and subtotals replace the cart totals below.', 'smart-checkout-rules')));
        Object.entries(config.fields).forEach(([field, type]) => {
            if (field === 'checkout.company_present') return;
            const value = scenario.values[field];
            const callback = (next) => { scenario.values[field] = next; };
            if (type === 'boolean') panel.append(row(checkbox(fieldName(field), value, callback)));
            else if (listTypes.includes(type) && !['key', 'code'].includes(type)) {
                panel.append(row(input(fieldName(field), (value || []).join(', '), (next) => callback(split(next)))));
            } else {
                panel.append(row(input(fieldName(field), value, (next) => {
                    if (next === '') delete scenario.values[field];
                    else callback(next);
                })));
            }
        });
        panel.append(row(input(__('Available gateways', 'smart-checkout-rules'), scenario.gateways.join(', '), (value) => { scenario.gateways = split(value); }),
            input(__('Available rate IDs', 'smart-checkout-rules'), scenario.rates.join(', '), (value) => { scenario.rates = split(value); })));
        Object.entries(config.checkoutFields).forEach(([field, name]) => panel.append(row(input(name, scenario.fields[field], (value) => { scenario.fields[field] = value; }))));
        scenario.lines.forEach((line, index) => {
            const item = node('fieldset', '', { class: 'scr-panel' });
            item.append(node('legend', sprintf(__('Line %d', 'smart-checkout-rules'), index + 1)));
            ['products', 'categories', 'shipping_classes'].forEach((key) => item.append(row(input(key.replaceAll('_', ' '), (line[key] || []).join(', '), (value) => { line[key] = split(value); }))));
            config.metrics.forEach((key) => item.append(row(input(metricLabels[key], line[key], (value) => { line[key] = value || '0'; }))));
            item.append(row(button(__('Remove line', 'smart-checkout-rules'), () => { scenario.lines.splice(index, 1); render(); })));
            panel.append(item);
        });
        if (scenario.lines.length < 50) panel.append(row(button(__('Add simulated line', 'smart-checkout-rules'), () => { scenario.lines.push({}); render(); })));
        content.append(panel, row(button(__('Run simulation', 'smart-checkout-rules'), async () => {
            try {
                result = await request('simulate', 'POST', { rules: snapshot.rules, settings: snapshot.settings, scenario });
                render();
                document.getElementById('scr-result')?.focus();
            } catch (error) {
                status.textContent = error.message || __('Simulation failed.', 'smart-checkout-rules');
                status.focus();
            }
        }, true)));
        if (result) {
            const section = node('section', '', { id: 'scr-result', tabindex: '-1', class: 'scr-panel' });
            section.append(node('h2', result.blocked ? __('Rules would block checkout', 'smart-checkout-rules') : __('Rules would allow checkout', 'smart-checkout-rules')));
            section.append(node('p', __('This result evaluates your rules. WooCommerce and other plugins may enforce additional requirements.', 'smart-checkout-rules')));
            section.append(node('p', __('Available gateways', 'smart-checkout-rules') + ': ' + result.payment.available.join(', ')),
                node('p', __('Available rates', 'smart-checkout-rules') + ': ' + result.shipping.available.join(', ')));
            Object.entries(result.matched).forEach(([module, matches]) => {
                if (!matches.length) return;
                section.append(node('h3', moduleLabels[module]));
                const list = node('ul');
                matches.forEach((match) => list.append(node('li', match.name + ' (' + match.id + ')')));
                section.append(list);
            });
            [...result.limits, ...result.validation].forEach((error) => section.append(node('p', error.message + (error.progress ? ' ' + error.progress : ''))));
            content.append(section);
        }
    }

    function exportRules() {
        const data = new Blob([JSON.stringify({ schema_version: 1, rules: snapshot.rules, settings: snapshot.settings }, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(data);
        const link = node('a', '', { href: url, download: 'smart-checkout-rules.json' });
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    function settingsPanel() {
        const settings = snapshot.settings;
        const file = node('input', '', { type: 'file', accept: '.json,application/json' });
        const upload = async () => {
            try {
                const source = file.files[0];
                if (!source || source.size > 1500000) throw new Error(__('Select a JSON file under 1.5 MB.', 'smart-checkout-rules'));
                const data = JSON.parse(await source.text());
                if (data?.schema_version !== 1 || !Array.isArray(data.rules) || typeof data.settings !== 'object' ||
                    data.rules.length > config.maxRules || Object.keys(data).some((key) => !['schema_version', 'rules', 'settings'].includes(key))) {
                    throw new Error(__('Invalid checkout rules file.', 'smart-checkout-rules'));
                }
                const validated = hydrate(await request('import', 'POST', data));
                if (!window.confirm(__('Replace the draft rules and settings with this file? Save to apply the import.', 'smart-checkout-rules'))) return;
                snapshot.rules = validated.rules;
                snapshot.settings = validated.settings;
                selected.clear();
                changed();
                render();
            } catch (error) {
                status.textContent = error.message || __('Import failed.', 'smart-checkout-rules');
            }
            status.focus();
        };
        content.append(node('h2', __('Enabled modules', 'smart-checkout-rules')), ...Object.entries(moduleLabels).map(([module, name]) =>
            row(checkbox(name, settings.modules[module], (value) => edit(settings.modules, module, value)))));
        content.append(node('h2', __('Diagnostics and uninstall', 'smart-checkout-rules')),
            row(checkbox(__('Log blocked payment and shipping events with rule IDs', 'smart-checkout-rules'), settings.logging, (value) => edit(settings, 'logging', value))),
            row(checkbox(__('Delete plugin configuration on uninstall', 'smart-checkout-rules'), settings.delete_on_uninstall, (value) => edit(settings, 'delete_on_uninstall', value))),
            node('h2', __('Import and export', 'smart-checkout-rules')),
            row(button(__('Export current draft', 'smart-checkout-rules'), exportRules)),
            row(label(__('Rules JSON file', 'smart-checkout-rules'), file), button(__('Validate and import', 'smart-checkout-rules'), upload)));
    }

    function dashboard() {
        const enabled = snapshot.rules.filter((rule) => rule.enabled && snapshot.settings.modules[rule.module]);
        content.append(node('h2', __('Overview', 'smart-checkout-rules')), node('p', sprintf(__('%d active rules.', 'smart-checkout-rules'), enabled.length)));
        if (snapshot.invalid) content.append(node('p', sprintf(__('%d invalid stored rules were skipped. Review the existing rules before saving.', 'smart-checkout-rules'), snapshot.invalid)));
        if (snapshot.last_event) content.append(node('pre', JSON.stringify(snapshot.last_event, null, 2)));
        content.append(node('h2', __('Example rules', 'smart-checkout-rules')));
        const cod = makeRule();
        cod.name = __('Hide cash on delivery on large orders', 'smart-checkout-rules');
        cod.conditions.all.push({ field: 'cart.subtotal_after_discount_ex_tax', operator: 'gte', value: '500' });
        cod.actions[0].gateway_id = 'cod';
        const minimum = makeRule('limits');
        minimum.name = __('Require a merchandise subtotal of 50', 'smart-checkout-rules');
        minimum.actions[0].amount = '50';
        const company = makeRule('validation');
        company.name = __('Require a company for business customers', 'smart-checkout-rules');
        company.conditions.all.push({ field: 'customer.roles', operator: 'in', value: ['business'] });
        const shipping = makeRule('shipping');
        shipping.name = __('Hide flat rate for oversized packages', 'smart-checkout-rules');
        shipping.conditions.all.push({ field: 'package.shipping_classes', operator: 'in', value: ['oversized'] });
        shipping.actions[0].method_id = 'flat_rate';
        [cod, minimum, company, shipping].forEach((rule) => {
            content.append(row(button(rule.name, () => { tab = 'rules'; addRule(rule); })));
        });
    }

    function render() {
        const nav = node('nav', '', { 'aria-label': __('Checkout Rules sections', 'smart-checkout-rules') });
        const tabs = { dashboard: __('Dashboard', 'smart-checkout-rules'), rules: __('Rules', 'smart-checkout-rules'), simulator: __('Simulator', 'smart-checkout-rules'), settings: __('Settings', 'smart-checkout-rules') };
        Object.entries(tabs).forEach(([key, name]) => {
            const control = button(name, () => { tab = key; render(); root.querySelector('[aria-current]')?.focus(); });
            if (key === tab) control.setAttribute('aria-current', 'page');
            nav.append(control);
        });
        content.replaceChildren();
        root.replaceChildren(nav, row(button(__('Save rules and settings', 'smart-checkout-rules'), save, true)), status, datalist, content);
        ({ dashboard, rules: ruleList, simulator: scenarioPanel, settings: settingsPanel })[tab]();
    }

    function fillDatalist(id, entries) {
        const element = node('datalist', '', { id });
        Object.entries(entries).forEach(([value, title]) => element.append(node('option', title, { value })));
        datalist.append(element);
    }

    fillDatalist('scr-gateways', config.gateways);
    fillDatalist('scr-shipping', config.shipping);
    window.addEventListener('beforeunload', (event) => {
        if (dirty) event.preventDefault();
    });
    root.append(status);
    status.textContent = __('Loading checkout rules…', 'smart-checkout-rules');
    request('configuration').then((response) => {
        snapshot = hydrate(response);
        status.textContent = '';
        render();
    }).catch((error) => {
        status.textContent = error.message || __('Checkout rules could not be loaded.', 'smart-checkout-rules');
    });
})();
