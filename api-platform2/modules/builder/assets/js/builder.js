(function(){
    'use strict';

    const data = window.APIPlatformBuilderData || { components: {}, i18n: {} };
    const components = data.components || {};
    const hiddenInput = document.getElementById('apiplatform-component-tree');
    const canvas = document.getElementById('apiplatform-component-canvas');
    const picker = document.getElementById('apiplatform-component-picker');
    const addButton = document.getElementById('apiplatform-add-component');
    const clearButton = document.getElementById('apiplatform-clear-components');
    const form = document.getElementById('apiplatform-builder-form');
    const preview = document.getElementById('apiplatform-response-preview');
    const componentCount = document.getElementById('apiplatform-component-count');

    if (!hiddenInput || !canvas || !picker || !addButton || !form) {
        return;
    }

    let tree = parseTree(hiddenInput.value);

    function parseTree(value){
        try {
            const parsed = JSON.parse(value || '{}');
            if (!parsed || typeof parsed !== 'object') {
                return { version: 1, components: [] };
            }
            if (!parsed.components || !Array.isArray(parsed.components)) {
                parsed.components = [];
            }
            if (!parsed.version) {
                parsed.version = 1;
            }
            parsed.components = parsed.components
                .filter(function(component){
                    return component && typeof component === 'object' && component.type;
                })
                .map(function(component){
                    return {
                        type: String(component.type),
                        props: component.props && typeof component.props === 'object' && !Array.isArray(component.props) ? component.props : {}
                    };
                });
            return parsed;
        } catch (error) {
            return { version: 1, components: [] };
        }
    }

    function saveTree(){
        hiddenInput.value = JSON.stringify(tree);
    }

    function getDefaultProps(definition){
        const props = {};
        (definition.fields || []).forEach(function(field){
            props[field.name] = field.default || '';
        });
        return props;
    }

    function replaceTokens(value, props){
        if (typeof value === 'string') {
            return value.replace(/\{\{\s*([a-zA-Z0-9_\-]+)\s*\}\}/g, function(match, token){
                return Object.prototype.hasOwnProperty.call(props, token) ? props[token] : '';
            });
        }

        if (Array.isArray(value)) {
            return value.map(function(item){
                return replaceTokens(item, props);
            });
        }

        if (value && typeof value === 'object') {
            return Object.keys(value).reduce(function(output, key){
                output[key] = replaceTokens(value[key], props);
                return output;
            }, {});
        }

        return value;
    }

    function buildPreviewPayload(){
        const rendered = [];

        tree.components.forEach(function(component){
            const definition = components[component.type];
            if (!definition || !definition.output) {
                return;
            }

            rendered.push(replaceTokens(definition.output, component.props || {}));
        });

        if (rendered.length === 0) {
            return {};
        }

        if (rendered.length === 1) {
            return rendered[0];
        }

        return { components: rendered };
    }

    function updatePreview(){
        if (!preview) {
            return;
        }

        preview.textContent = JSON.stringify(buildPreviewPayload(), null, 2);
    }

    function updateCount(){
        if (!componentCount) {
            return;
        }

        const count = tree.components.length;
        componentCount.textContent = count === 1 ? '1 component configured.' : count + ' components configured.';
    }

    function createInput(field, value, componentIndex){
        const wrapper = document.createElement('label');
        wrapper.className = 'apiplatform-field';

        const title = document.createElement('span');
        title.textContent = field.label || field.name;
        wrapper.appendChild(title);

        let input;
        if (field.type === 'textarea') {
            input = document.createElement('textarea');
        } else {
            input = document.createElement('input');
            input.type = field.type === 'number' ? 'number' : (field.type || 'text');
        }

        input.value = value || field.default || '';
        input.placeholder = field.placeholder || '';
        input.addEventListener('input', function(){
            if (!tree.components[componentIndex].props) {
                tree.components[componentIndex].props = {};
            }
            tree.components[componentIndex].props[field.name] = input.value;
            saveTree();
            updatePreview();
        });

        wrapper.appendChild(input);
        return wrapper;
    }

    function createButton(label, className, disabled, callback){
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = label;
        button.disabled = !!disabled;
        button.addEventListener('click', callback);
        return button;
    }

    function moveComponent(fromIndex, toIndex){
        if (toIndex < 0 || toIndex >= tree.components.length || fromIndex === toIndex) {
            return;
        }

        const item = tree.components.splice(fromIndex, 1)[0];
        tree.components.splice(toIndex, 0, item);
        saveTree();
        render();
    }

    function render(){
        canvas.innerHTML = '';

        if (!tree.components.length) {
            const empty = document.createElement('p');
            empty.className = 'apiplatform-empty-state';
            empty.textContent = 'No components yet. Choose a component and click Add Component.';
            canvas.appendChild(empty);
            saveTree();
            updatePreview();
            updateCount();
            return;
        }

        tree.components.forEach(function(component, index){
            const definition = components[component.type];
            if (!definition) {
                return;
            }

            if (!tree.components[index].props) {
                tree.components[index].props = getDefaultProps(definition);
            }

            const card = document.createElement('div');
            card.className = 'apiplatform-component-card';
            card.dataset.componentType = component.type;

            const header = document.createElement('div');
            header.className = 'apiplatform-component-header';

            const titleWrap = document.createElement('div');
            const title = document.createElement('h3');
            title.textContent = (index + 1) + '. ' + (definition.label || component.type);
            titleWrap.appendChild(title);

            if (definition.description) {
                const description = document.createElement('p');
                description.className = 'description';
                description.textContent = definition.description;
                titleWrap.appendChild(description);
            }

            header.appendChild(titleWrap);

            const actions = document.createElement('div');
            actions.className = 'apiplatform-component-actions';
            actions.appendChild(createButton('Up', 'button button-small', index === 0, function(){ moveComponent(index, index - 1); }));
            actions.appendChild(createButton('Down', 'button button-small', index === tree.components.length - 1, function(){ moveComponent(index, index + 1); }));
            actions.appendChild(createButton((data.i18n && data.i18n.remove) || 'Remove', 'button button-link-delete', false, function(){
                tree.components.splice(index, 1);
                saveTree();
                render();
            }));
            header.appendChild(actions);

            card.appendChild(header);

            const fields = definition.fields || [];
            fields.forEach(function(field){
                if (!Object.prototype.hasOwnProperty.call(tree.components[index].props, field.name)) {
                    tree.components[index].props[field.name] = field.default || '';
                }
                card.appendChild(createInput(field, tree.components[index].props[field.name], index));
            });

            canvas.appendChild(card);
        });

        saveTree();
        updatePreview();
        updateCount();
    }

    addButton.addEventListener('click', function(){
        const type = picker.value;
        const definition = components[type];
        if (!definition) {
            return;
        }

        tree.components.push({ type: type, props: getDefaultProps(definition) });
        saveTree();
        render();
    });

    if (clearButton) {
        clearButton.addEventListener('click', function(){
            if (!tree.components.length) {
                return;
            }

            if (!window.confirm('Clear all components from this response stack?')) {
                return;
            }

            tree.components = [];
            saveTree();
            render();
        });
    }

    form.addEventListener('submit', saveTree);
    render();
})();
