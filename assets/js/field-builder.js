/**
 * <scheduler-field-builder> ist der visuelle Formbuilder für Custom Fields.
 *
 * Links die Palette, in der Mitte die Formularfläche mit Drag-and-drop (und Tasten-Buttons für
 * dieselben Aktionen), rechts die Eigenschaften des gewählten Elements, darunter eine Vorschau,
 * die der Server mit demselben Renderer wie im Editor erzeugt.
 *
 * Das Ergebnis landet als JSON in den versteckten Feldern "definition" und "renames" des Formulars.
 */
import { Api, ask, debounce, enhancePickers, h, icon, t, toast, uniqueId } from './util.js';

const STRUCTURE = {
  tab: { label: t('fb_tab'), icon: 'fa-folder', help: t('fb_tab_help') },
  fieldset: { label: t('fb_fieldset'), icon: 'fa-object-group', help: t('fb_fieldset_help') },
  columns: { label: t('fb_columns'), icon: 'fa-table-columns', help: t('fb_columns_help') },
  column: { label: t('fb_column'), icon: 'fa-grip-lines-vertical' },
  repeater: { label: t('fb_repeater'), icon: 'fa-layer-group', help: t('fb_repeater_help') },
};
const CONTAINERS = new Set(Object.keys(STRUCTURE));

class SchedulerFieldBuilder extends HTMLElement {
  connectedCallback() {
    if (this.dataset.ready) return;
    this.dataset.ready = '1';

    this.api = new Api(this.getAttribute('api'));
    this.target = this.getAttribute('target');
    this.types = JSON.parse(this.getAttribute('types'));
    this.reserved = new Set(JSON.parse(this.getAttribute('reserved') || '[]'));
    this.definitionField = this.querySelector('input[name="definition"]');
    this.renamesField = this.querySelector('input[name="renames"]');

    let definition = {};
    try {
      definition = JSON.parse(this.definitionField.value || '{}');
    } catch {
      // Eine kaputte Definition wird wie ein leeres Schema behandelt.
    }
    this.nodes = (definition.nodes ?? []).map((node) => this.#hydrate(node));
    this.selected = null;
    this.drag = null;

    this.palette = h('div', { class: 'scheduler-fb-palette', 'aria-label': t('fb_elements') });
    this.canvas = h('div', { class: 'scheduler-fb-canvas' });
    this.properties = h('div', { class: 'scheduler-fb-properties', 'aria-live': 'polite' });
    this.previewBody = h('div', { class: 'scheduler-fb-preview-body' });
    this.preview = h('details', { class: 'scheduler-fb-preview', ontoggle: () => this.preview.open && this.#loadPreview() },
      h('summary', {}, t('fb_preview')), this.previewBody);
    this.append(h('div', { class: 'scheduler-fb-layout' }, this.palette, this.canvas, this.properties), this.preview);

    this.refreshPreview = debounce(() => this.preview.open && this.#loadPreview(), 500);
    this.#renderPalette();
    this.#render();
  }

  /* ---------- Datenmodell ---------- */

  #hydrate(node, isExisting = true) {
    return {
      _id: uniqueId('node'),
      _orig: isExisting && node.name ? node.name : null,
      type: node.type,
      name: node.name ?? '',
      label: node.label ?? '',
      help: node.help ?? '',
      required: Boolean(node.required),
      translatable: Boolean(node.translatable),
      filterable: Boolean(node.filterable),
      ical: Boolean(node.ical),
      options: { ...(node.options ?? {}) },
      visibleIf: node.visibleIf ? { ...node.visibleIf } : null,
      children: (node.children ?? []).map((child) => this.#hydrate(child, isExisting)),
    };
  }

  #serialize(nodes = this.nodes) {
    return nodes.map((node) => {
      const data = { type: node.type };
      for (const key of ['name', 'label', 'help']) if (node[key]) data[key] = node[key];
      for (const key of ['required', 'translatable', 'filterable', 'ical']) if (node[key]) data[key] = true;
      const options = Object.fromEntries(Object.entries(node.options).filter(([, value]) => value !== '' && value !== null && value !== false));
      if (Object.keys(options).length > 0) data.options = options;
      if (node.visibleIf?.field) data.visibleIf = node.visibleIf;
      if (CONTAINERS.has(node.type)) data.children = this.#serialize(node.children);
      return data;
    });
  }

  #commit() {
    const renames = {};
    this.#walk((node) => {
      if (node._orig && node.name && node._orig !== node.name) renames[node._orig] = node.name;
    });
    this.definitionField.value = JSON.stringify({ format: 1, nodes: this.#serialize() });
    this.renamesField.value = JSON.stringify(renames);
    this.refreshPreview();
  }

  #walk(callback, nodes = this.nodes, parent = null) {
    for (const node of nodes) {
      callback(node, parent, nodes);
      this.#walk(callback, node.children, node);
    }
  }

  #find(id) {
    let found = null;
    this.#walk((node, parent, siblings) => {
      if (node._id === id) found = { node, parent, siblings };
    });
    return found;
  }

  #isField(type) {
    return !CONTAINERS.has(type);
  }

  /** Darf ein Element dieses Typs in den Container? (parent === null ist die oberste Ebene) */
  #accepts(parent, type, movingNode = null) {
    if (type === 'tab') return parent === null;
    if (type === 'column') return parent?.type === 'columns';
    if (parent?.type === 'columns') return false;
    const inRepeater = parent !== null && this.#ancestors(parent).some((node) => node.type === 'repeater');
    if (inRepeater) {
      if (!this.#isField(type)) return false;
      return this.types[type]?.allowedInRepeater !== false;
    }
    if (type === 'repeater' && movingNode === null) return true;
    return true;
  }

  #ancestors(node) {
    const chain = [node];
    let current = this.#find(node._id)?.parent;
    while (current) {
      chain.push(current);
      current = this.#find(current._id)?.parent;
    }
    return chain;
  }

  #contains(node, candidate) {
    return node === candidate || node.children.some((child) => this.#contains(child, candidate));
  }

  #uniqueName(base) {
    const names = new Set();
    this.#walk((node) => node.name && names.add(node.name));
    let name = base;
    for (let i = 2; names.has(name) || this.reserved.has(name); i += 1) name = `${base}_${i}`;
    return name;
  }

  #slug(text) {
    const slug = text.toLowerCase()
      .replaceAll('ä', 'ae').replaceAll('ö', 'oe').replaceAll('ü', 'ue').replaceAll('ß', 'ss')
      .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'f_$1');
    return slug.slice(0, 50);
  }

  /* ---------- Aktionen ---------- */

  #create(type) {
    const isField = this.#isField(type);
    const label = isField ? this.types[type].label : STRUCTURE[type].label;
    const node = this.#hydrate({ type, label, name: isField || type === 'repeater' ? this.#uniqueName(this.#slug(label)) : '' }, false);
    node._autoName = true;
    if (type === 'columns') node.children = [this.#hydrate({ type: 'column', options: { width: 6 } }, false), this.#hydrate({ type: 'column', options: { width: 6 } }, false)];
    if (type === 'column') node.options.width = 6;
    return node;
  }

  #add(type) {
    // Ziel: der gewählte Container, sonst der Container des gewählten Elements, sonst die oberste Ebene.
    const selected = this.selected ? this.#find(this.selected) : null;
    const candidates = [];
    if (selected) {
      if (CONTAINERS.has(selected.node.type)) candidates.push({ parent: selected.node, index: selected.node.children.length });
      candidates.push({ parent: selected.parent, index: selected.siblings.indexOf(selected.node) + 1 });
    }
    candidates.push({ parent: null, index: this.nodes.length });

    const place = candidates.find((candidate) => this.#accepts(candidate.parent, type));
    if (!place) {
      toast(t('fb_not_allowed_here'), 'error');
      return;
    }
    const node = this.#create(type);
    (place.parent ? place.parent.children : this.nodes).splice(place.index, 0, node);
    this.#select(node._id);
  }

  #moveBy(id, offset) {
    const { node, siblings } = this.#find(id);
    const index = siblings.indexOf(node);
    const target = index + offset;
    if (target < 0 || target >= siblings.length) return;
    siblings.splice(index, 1);
    siblings.splice(target, 0, node);
    this.#render();
    this.querySelector(`[data-node="${id}"] [data-action="${offset < 0 ? 'up' : 'down'}"]`)?.focus();
  }

  async #remove(id) {
    const { node, siblings } = this.#find(id);
    const stored = [];
    this.#walk((child) => child._orig && stored.push(child._orig), [node]);

    let used = 0;
    for (const name of stored) {
      try {
        used += (await this.api.get('fieldUsage', { target: this.target, field: name })).count;
      } catch {
        // Ohne Zahl wird trotzdem nachgefragt.
      }
    }
    if (stored.length > 0 || node.children.length > 0) {
      const answer = await ask({
        title: t('fb_remove_title'),
        text: used > 0
          ? t('fb_remove_used', used)
          : t('fb_remove_text'),
        buttons: [{ label: t('cancel'), value: null }, { label: t('remove'), value: 'yes', class: 'btn-delete' }],
      });
      if (answer === null) return;
    }
    siblings.splice(siblings.indexOf(node), 1);
    this.selected = null;
    this.#render();
  }

  #duplicate(id) {
    const { node, siblings } = this.#find(id);
    const copy = this.#hydrate(this.#serialize([node])[0], false);
    this.#walk((child) => { if (child.name) child.name = this.#uniqueName(child.name); }, [copy]);
    siblings.splice(siblings.indexOf(node) + 1, 0, copy);
    this.#select(copy._id);
  }

  #select(id) {
    this.selected = id;
    this.#render();
  }

  /* ---------- Darstellung ---------- */

  #renderPalette() {
    const item = (type, meta) => h('button', {
      type: 'button', class: 'scheduler-fb-palette-item', draggable: 'true', title: meta.help ?? '',
      onclick: () => this.#add(type),
      ondragstart: (event) => {
        this.drag = { type };
        event.dataTransfer.effectAllowed = 'copy';
        event.dataTransfer.setData('text/plain', type);
      },
      ondragend: () => this.#endDrag(),
    }, icon(meta.icon), ' ', meta.label);

    this.palette.replaceChildren(
      h('h3', {}, t('fb_structure')),
      ...['tab', 'fieldset', 'columns', 'repeater'].map((type) => item(type, STRUCTURE[type])),
      h('h3', {}, t('fb_fields')),
      ...Object.entries(this.types).map(([type, meta]) => item(type, meta)),
    );
  }

  #render() {
    // replaceChildren() würde null als Text "null" einfügen, deshalb nur vorhandene Knoten übergeben.
    const emptyHint = this.nodes.length === 0
      ? [h('p', { class: 'scheduler-fb-empty' }, t('fb_empty'))]
      : [];
    this.canvas.replaceChildren(...emptyHint, this.#renderChildren(null, this.nodes));
    this.#renderProperties();
    this.#commit();
  }

  #renderChildren(parent, nodes) {
    const zone = h('div', {
      class: 'scheduler-fb-children', dataset: { parent: parent?._id ?? '' },
      ondragover: (event) => this.#onDragOver(event, zone, parent),
      ondragleave: (event) => { if (!zone.contains(event.relatedTarget)) zone.querySelector(':scope > .scheduler-fb-indicator')?.remove(); },
      ondrop: (event) => this.#onDrop(event, zone, parent),
    }, nodes.map((node) => this.#renderNode(node)));
    return zone;
  }

  #renderNode(node) {
    const meta = this.#isField(node.type) ? (this.types[node.type] ?? { label: node.type, icon: 'fa-circle-question' }) : STRUCTURE[node.type];
    const title = node.label || node.name || meta.label;
    const flags = [node.required && t('fb_flag_required'), node.translatable && t('fb_flag_translatable'), node.filterable && t('fb_flag_filterable'), node.ical && 'iCal', node.visibleIf?.field && t('fb_flag_conditional')].filter(Boolean);
    const button = (action, iconName, label, handler) => h('button', {
      type: 'button', class: 'scheduler-fb-button', dataset: { action }, title: label, 'aria-label': `${label}: ${title}`,
      onclick: (event) => { event.stopPropagation(); handler(); },
    }, icon(iconName));

    const header = h('div', { class: 'scheduler-fb-node-head' },
      h('span', { class: 'scheduler-fb-grip', 'aria-hidden': 'true' }, icon('fa-grip-vertical')),
      h('button', { type: 'button', class: 'scheduler-fb-node-title', onclick: () => this.#select(node._id), 'aria-pressed': String(this.selected === node._id) },
        icon(meta.icon), ' ', h('strong', {}, title), ' ',
        node.name ? h('code', {}, node.name) : null, ' ',
        h('span', { class: 'scheduler-fb-type' }, meta.label),
        flags.map((flag) => h('span', { class: 'scheduler-fb-flag' }, flag))),
      h('span', { class: 'scheduler-fb-node-actions' },
        button('up', 'fa-arrow-up', t('move_up'), () => this.#moveBy(node._id, -1)),
        button('down', 'fa-arrow-down', t('move_down'), () => this.#moveBy(node._id, 1)),
        node.type === 'column' ? null : button('duplicate', 'fa-clone', t('duplicate'), () => this.#duplicate(node._id)),
        button('remove', 'fa-trash', t('remove'), () => this.#remove(node._id))));

    return h('div', {
      class: `scheduler-fb-node scheduler-fb-node-${CONTAINERS.has(node.type) ? 'container' : 'field'}${this.selected === node._id ? ' is-selected' : ''}`,
      dataset: { node: node._id, type: node.type }, draggable: 'true',
      ondragstart: (event) => {
        event.stopPropagation();
        this.drag = { node };
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', node._id);
        requestAnimationFrame(() => event.target.classList.add('is-dragging'));
      },
      ondragend: () => this.#endDrag(),
    }, header, CONTAINERS.has(node.type) ? this.#renderChildren(node, node.children) : null);
  }

  /* ---------- Drag-and-drop ---------- */

  #dropIndex(zone, clientY) {
    const items = [...zone.querySelectorAll(':scope > .scheduler-fb-node:not(.is-dragging)')];
    const index = items.findIndex((item) => clientY < item.getBoundingClientRect().top + item.getBoundingClientRect().height / 2);
    return { index: index === -1 ? items.length : index, before: index === -1 ? null : items[index] };
  }

  #canDrop(parent) {
    if (!this.drag) return false;
    if (this.drag.node) {
      if (parent && this.#contains(this.drag.node, parent)) return false;
      if (!this.#isField(this.drag.node.type) && parent && this.#ancestors(parent).some((node) => node.type === 'repeater')) return false;
      return this.#accepts(parent, this.drag.node.type, this.drag.node);
    }
    return this.#accepts(parent, this.drag.type);
  }

  #onDragOver(event, zone, parent) {
    if (!this.#canDrop(parent)) return;
    event.preventDefault();
    event.stopPropagation();
    event.dataTransfer.dropEffect = this.drag.node ? 'move' : 'copy';
    this.querySelectorAll('.scheduler-fb-indicator').forEach((indicator) => indicator.remove());
    const { before } = this.#dropIndex(zone, event.clientY);
    zone.insertBefore(h('div', { class: 'scheduler-fb-indicator' }), before);
  }

  #onDrop(event, zone, parent) {
    if (!this.#canDrop(parent)) return;
    event.preventDefault();
    event.stopPropagation();
    const { before } = this.#dropIndex(zone, event.clientY);
    const list = parent ? parent.children : this.nodes;
    const beforeNode = before ? this.#find(before.dataset.node).node : null;

    let node = this.drag.node;
    if (node) {
      const from = this.#find(node._id).siblings;
      from.splice(from.indexOf(node), 1);
    } else {
      node = this.#create(this.drag.type);
    }
    const index = beforeNode ? list.indexOf(beforeNode) : list.length;
    list.splice(index === -1 ? list.length : index, 0, node);
    this.#endDrag();
    this.#select(node._id);
  }

  #endDrag() {
    this.drag = null;
    this.querySelectorAll('.scheduler-fb-indicator').forEach((indicator) => indicator.remove());
    this.querySelectorAll('.is-dragging').forEach((element) => element.classList.remove('is-dragging'));
  }

  /* ---------- Eigenschaften ---------- */

  #renderProperties() {
    const found = this.selected ? this.#find(this.selected) : null;
    if (!found) {
      this.properties.replaceChildren(h('p', { class: 'scheduler-fb-empty' }, t('fb_select_element')));
      return;
    }
    const { node, parent } = found;
    const isField = this.#isField(node.type);
    const hasName = isField || node.type === 'repeater';
    const inRepeater = parent !== null && this.#ancestors(parent).some((ancestor) => ancestor.type === 'repeater');
    const meta = isField ? this.types[node.type] : STRUCTURE[node.type];

    // Eingaben ändern das Modell sofort; neu gezeichnet wird die Fläche erst beim Verlassen des Felds,
    // damit der Fokus beim Tippen nicht verloren geht.
    const row = (label, control, help = null) => {
      const id = uniqueId('fb-prop');
      control.id = id;
      return h('div', { class: 'form-group' }, h('label', { for: id }, label), control, help ? h('p', { class: 'help-block' }, help) : null);
    };
    const text = (label, get, set, { help = null, type = 'text', attrs = {} } = {}) => row(label, h('input', {
      type, class: 'form-control', value: get() ?? '', ...attrs,
      oninput: (event) => { set(event.target.value); this.#commit(); },
      onchange: () => this.#render(),
    }), help);
    const area = (label, get, set, help = null) => row(label, h('textarea', {
      class: 'form-control', rows: 4, oninput: (event) => { set(event.target.value); this.#commit(); }, onchange: () => this.#render(),
    }, get() ?? ''), help);
    const check = (label, get, set, help = null) => h('div', { class: 'form-group' },
      h('label', { class: 'scheduler-switch' }, h('input', { type: 'checkbox', role: 'switch', checked: Boolean(get()), onchange: (event) => { set(event.target.checked); this.#render(); } }), ' ', h('span', {}, label)),
      help ? h('p', { class: 'help-block' }, help) : null);
    const select = (label, choices, get, set) => row(label, h('select', { class: 'form-control', onchange: (event) => { set(event.target.value); this.#render(); } },
      Object.entries(choices).map(([value, text_]) => h('option', { value, selected: String(get() ?? '') === value }, text_))));

    const controls = [h('h3', {}, icon(meta.icon), ' ', meta.label)];

    if (node.type !== 'column' && node.type !== 'columns') {
      controls.push(text(t('fb_label'), () => node.label, (value) => {
        node.label = value;
        if (hasName && node._autoName && !node._orig) node.name = this.#uniqueNameFor(node, this.#slug(value) || node.type);
      }));
    }
    if (hasName) {
      const nameError = this.#nameError(node, inRepeater);
      controls.push(text(t('fb_name'), () => node.name, (value) => { node.name = value.trim(); node._autoName = false; }, {
        attrs: { pattern: '[a-z][a-z0-9_]*', spellcheck: 'false', autocapitalize: 'off', 'aria-invalid': nameError ? 'true' : null },
        help: nameError ?? (node._orig && node._orig !== node.name
          ? t('fb_name_renamed', node._orig)
          : t('fb_name_help', `$event->custom('${node.name || 'name'}')`)),
      }));
    }
    if (isField) {
      controls.push(
        text(t('fb_help'), () => node.help, (value) => { node.help = value; }),
        check(t('fb_required'), () => node.required, (value) => { node.required = value; }),
      );
      if (!inRepeater) {
        controls.push(
          check(t('fb_translatable'), () => node.translatable, (value) => { node.translatable = value; if (value) node.filterable = false; }, t('fb_translatable_help')),
          check(t('fb_filterable'), () => node.filterable, (value) => { node.filterable = value; if (value) node.translatable = false; }, t('fb_filterable_help')),
        );
        if (this.target === 'event') controls.push(check(t('fb_ical'), () => node.ical, (value) => { node.ical = value; }, t('fb_ical_help')));
      }
      for (const option of meta.options ?? []) {
        const get = () => node.options[option.name];
        const set = (value) => { node.options[option.name] = value; };
        if (option.type === 'checkbox') controls.push(check(option.label, get, set, option.help));
        else if (option.type === 'textarea') controls.push(area(option.label, get, set, option.help));
        else if (option.type === 'select') controls.push(select(option.label, option.choices, get, set));
        else controls.push(text(option.label, get, set, { type: option.type === 'number' ? 'number' : 'text', help: option.help }));
      }
    }
    if (node.type === 'column') {
      controls.push(text(t('fb_width'), () => node.options.width ?? 6, (value) => { node.options.width = Math.min(12, Math.max(1, Number(value) || 6)); }, { type: 'number', attrs: { min: 1, max: 12 } }));
    }
    if (node.type === 'columns') {
      controls.push(h('button', { type: 'button', class: 'btn btn-default btn-sm', onclick: () => { node.children.push(this.#create('column')); this.#render(); } }, icon('fa-plus'), ` ${t('fb_add_column')}`));
    }
    if (node.type === 'repeater') {
      controls.push(
        text(t('fb_min'), () => node.options.min, (value) => { node.options.min = value; }, { type: 'number', attrs: { min: 0 } }),
        text(t('fb_max'), () => node.options.max, (value) => { node.options.max = value; }, { type: 'number', attrs: { min: 0 }, help: t('fb_max_help') }),
        text(t('fb_add_label'), () => node.options.add_label, (value) => { node.options.add_label = value; }),
      );
    }

    if (!inRepeater && node.type !== 'column') controls.push(this.#visibilityControls(node, select, text));
    this.properties.replaceChildren(...controls);
  }

  #visibilityControls(node, select, text) {
    const fields = { '': t('fb_always_visible') };
    this.#walk((other, parent) => {
      const insideRepeater = parent && this.#ancestors(parent).some((ancestor) => ancestor.type === 'repeater');
      if (this.#isField(other.type) && other.name && other !== node && !insideRepeater && !other.translatable && !this.#contains(node, other)) {
        fields[other.name] = t('fb_depends_on', other.label || other.name);
      }
    });
    const rule = node.visibleIf ?? { field: '', operator: '=', value: '' };
    const update = (changes) => {
      const next = { ...rule, ...changes };
      node.visibleIf = next.field ? next : null;
    };

    return h('fieldset', { class: 'scheduler-fb-visibility' }, h('legend', {}, t('fb_visibility')),
      select(t('fb_show'), fields, () => rule.field, (value) => update({ field: value })),
      rule.field ? select(t('fb_condition'), { '=': t('fb_op_equals'), '!=': t('fb_op_not_equals'), not_empty: t('fb_op_not_empty'), empty: t('fb_op_empty') }, () => rule.operator, (value) => update({ operator: value })) : null,
      rule.field && ['=', '!='].includes(rule.operator) ? text(t('fb_value'), () => rule.value, (value) => update({ value }), { help: t('fb_value_help') }) : null);
  }

  #uniqueNameFor(node, base) {
    const previous = node.name;
    node.name = '';
    const name = this.#uniqueName(base);
    node.name = previous;
    return name;
  }

  #nameError(node, inRepeater) {
    if (!/^[a-z][a-z0-9_]{0,62}$/.test(node.name)) return t('fb_name_error_format');
    if (!inRepeater && this.reserved.has(node.name)) return t('fb_name_error_reserved');
    const scope = inRepeater ? this.#find(node._id).siblings : null;
    let duplicates = 0;
    if (scope) duplicates = scope.filter((other) => other.name === node.name).length;
    else this.#walk((other, parent) => { if (other.name === node.name && !(parent && this.#ancestors(parent).some((a) => a.type === 'repeater'))) duplicates += 1; });
    return duplicates > 1 ? t('fb_name_error_duplicate') : null;
  }

  async #loadPreview() {
    try {
      const result = await this.api.post('schemaPreview', { target: this.target, definition: { nodes: this.#serialize() } });
      // Das HTML stammt vom eigenen Server-Renderer, der alle Werte maskiert.
      this.previewBody.innerHTML = result.html || '<p class="scheduler-fb-empty">Das Formular hat noch keine Felder.</p>';
      // Die Vorschau liegt im Builder-Formular: Pflichtangaben dürfen dessen Absenden nicht blockieren.
      for (const field of this.previewBody.querySelectorAll('[required]')) field.required = false;
      enhancePickers(this.previewBody);
    } catch (error) {
      this.previewBody.textContent = error.message;
    }
  }
}

customElements.define('scheduler-field-builder', SchedulerFieldBuilder);
