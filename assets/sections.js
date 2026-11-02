/**
 * Sections dans l'éditeur. L'aperçu est le template Carlo rendu par le serveur, où les textes
 * sont des marqueurs {{carlo:chemin}} (cf. CarloWpDriver::loadValue) : on les remplace ici par des champs
 * saisissables. Les autres champs (images, choix, références…) sont dans le panneau Réglages,
 * généré depuis structure.yml
 * (window.carloSections, cf. inc/blocks.php). Écrit sans JSX pour se passer d'étape de build.
 */
(function (wp, sections) {
  const el = wp.element.createElement;
  const { createContext, createPortal, useContext, useEffect, useLayoutEffect, useMemo, useRef, useState } = wp.element;
  const { useBlockProps, InspectorControls, RichText, MediaUpload, MediaUploadCheck } = wp.blockEditor;
  const { useInstanceId } = wp.compose;
  const {
    PanelBody,
    TextControl,
    TextareaControl,
    ToggleControl,
    SelectControl,
    CheckboxControl,
    ComboboxControl,
    Button,
    Modal,
    Notice,
    Spinner,
  } = wp.components;
  const { useSelect } = wp.data;
  const { escapeHTML } = wp.escapeHtml;
  const { decodeEntities } = wp.htmlEntities;

  // Gabarit actuel des contrôles, sans avertissement de dépréciation.
  const next = { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true };

  const INLINE_TYPES = ['text_short', 'text', 'wysiwyg'];
  const MARKER = /\{\{carlo:([\w.]+)\}\}/;
  const MARKERS = new RegExp(MARKER.source, 'g');

  // Chemins des champs saisis dans l'aperçu : le panneau ne les répète pas.
  const InlinePaths = createContext(new Set());

  // Même règle que le rendu PHP : une section sans aucune valeur n'est pas affichée.
  function isFilled(value) {
    if (value && typeof value === 'object') return Object.values(value).some(isFilled);
    return Boolean(value);
  }

  // Valeurs imbriquées par chemin : "quote.author", "cards.0.title".
  const getAt = (values, path) => path.split('.').reduce((value, key) => value?.[key], values);

  function setAt(values, [key, ...rest], value) {
    const copy = Array.isArray(values) ? [...values] : { ...values };
    copy[key] = rest.length ? setAt(values?.[key] ?? (/^\d+$/.test(rest[0]) ? [] : {}), rest, value) : value;
    return copy;
  }

  function fieldAt(fields, path) {
    let field;
    for (const key of path.split('.')) {
      if (/^\d+$/.test(key)) continue; // élément de répéteur
      field = fields?.[key];
      fields = field?.fields;
    }
    return field;
  }

  // Ce dont dépend l'aperçu : tout sauf les textes, qui y sont des marqueurs.
  function withoutText(fields, values) {
    return Object.fromEntries(
      Object.entries(fields)
        .filter(([, field]) => !INLINE_TYPES.includes(field.type))
        .map(([key, field]) => [
          key,
          field.type === 'group'
            ? withoutText(field.fields, values?.[key])
            : field.type === 'repeater'
              ? (values?.[key] || []).map((item) => withoutText(field.fields, item))
              : values?.[key],
        ])
    );
  }

  /* ---------------------------------------------------------------------------------------- */
  /* Panneau Réglages                                                                          */
  /* ---------------------------------------------------------------------------------------- */

  // Champ composé de plusieurs contrôles (image, groupe, répéteur…).
  function Group({ label, help, children }) {
    return el(
      'fieldset',
      { className: 'carlo-field__group' },
      label && el('legend', { className: 'carlo-field__label' }, label),
      help && el('p', { className: 'carlo-field__help' }, help),
      children
    );
  }

  // Le champ a-t-il sa place dans le panneau ? Non s'il est saisi dans l'aperçu, ni pour un
  // groupe dont tous les champs le sont.
  function inPanel(field, path, inline) {
    if (inline.has(path)) return false;
    if (field.type === 'group') return Object.entries(field.fields).some(([key, sub]) => inPanel(sub, `${path}.${key}`, inline));
    return true;
  }

  function Fields({ fields, values, onChange, path = '' }) {
    const inline = useContext(InlinePaths);
    return Object.entries(fields)
      .filter(([key, field]) => inPanel(field, path + key, inline))
      .map(([key, field]) =>
        el(
          'div',
          { key, className: `carlo-field carlo-field--${field.type}` },
          el(Field, { field, path: path + key, value: values?.[key], onChange: (value) => onChange(key, value) })
        )
      );
  }

  function Field({ field, path, value, onChange }) {
    const { label, help } = field;
    switch (field.type) {
      case 'text_short':
      case 'url':
        return el(TextControl, { ...next, type: field.type === 'url' ? 'url' : 'text', label, help, value: value ?? '', onChange });
      case 'number':
        return el(TextControl, {
          ...next,
          type: 'number',
          label,
          help,
          value: value ?? '',
          onChange: (v) => onChange(v === '' ? undefined : Number(v)),
        });
      case 'text':
        return el(TextareaControl, { __nextHasNoMarginBottom: true, label, help, value: value ?? '', onChange });
      case 'boolean':
        return el(ToggleControl, { __nextHasNoMarginBottom: true, label, help, checked: Boolean(value), onChange });
      case 'wysiwyg':
        return el(WysiwygField, { field, value, onChange });
      case 'choices':
        return el(ChoicesField, { field, value, onChange });
      case 'image':
        return el(ImageField, { field, value, onChange });
      case 'reference':
        return el(ReferenceField, { field, value, onChange });
      case 'group':
        return el(
          Group,
          field,
          el(Fields, { fields: field.fields, path: `${path}.`, values: value, onChange: (key, v) => onChange({ ...value, [key]: v }) })
        );
      case 'repeater':
        return el(RepeaterField, { field, path, value, onChange });
      default:
        return el(
          Notice,
          { status: 'warning', isDismissible: false },
          `${label} : les champs « ${field.type} » ne sont pas encore pris en charge.`
        );
    }
  }

  // Éditeur classique (TinyMCE + onglet Code), comme le champ wysiwyg d'ACF : le RichText de
  // Gutenberg ne gère qu'un élément et fusionnerait paragraphes, listes et intertitres.
  function WysiwygField({ field, value, onChange }) {
    const id = useInstanceId(WysiwygField, 'carlo-wysiwyg');
    // Dernière valeur connue de l'éditeur, pour ne lui renvoyer que les changements externes (annuler…).
    const current = useRef(value);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    useEffect(() => {
      // API de l'éditeur classique, fusionnée par WordPress dans wp.editor (avec les réglages
      // imprimés par wp_enqueue_editor) ; wp.oldEditor n'a pas ces réglages.
      const editor = wp.editor;
      const notify = () => {
        current.current = editor.getContent(id);
        onChangeRef.current(current.current || undefined);
      };
      // Barres d'outils complètes du bloc Classique, avec les filtres du site (mce_buttons_2…).
      const { classic_block_editor, ...settings } = window.wpEditorL10n.tinymce.settings;
      editor.initialize(id, {
        quicktags: true,
        mediaButtons: false,
        tinymce: {
          ...settings,
          // « Lire la suite », médias et plein écran n'ont pas de sens dans une section.
          toolbar1: settings.toolbar1.replace(/,?(wp_more|wp_add_media|fullscreen)/g, ''),
          // La zone grandit avec le texte : pas de défilement dans un panneau qui défile déjà.
          wp_autoresize_on: true,
          min_height: 200,
          setup: (mce) => mce.on('change input undo redo', notify),
        },
      });
      document.getElementById(id).addEventListener('input', notify); // onglet Code
      return () => editor.remove(id);
    }, []);

    useEffect(() => {
      if (value === current.current) return;
      current.current = value;
      const mce = window.tinymce.get(id);
      if (mce && !mce.isHidden()) mce.setContent(wp.editor.autop(value || ''));
      else document.getElementById(id).value = value || '';
    }, [value]);

    return el(
      Group,
      field,
      // TinyMCE remplace le textarea par son propre balisage : React ne gère que l'enveloppe.
      el('div', null, el('textarea', { id, className: 'wp-editor-area', defaultValue: value ?? '' }))
    );
  }

  function ChoicesField({ field, value, onChange }) {
    const options = Object.entries(field.choices).map(([v, label]) => ({ value: v, label: String(label) }));
    if (field.multi) {
      const selected = value || [];
      return el(
        Group,
        field,
        options.map((option) =>
          el(CheckboxControl, {
            __nextHasNoMarginBottom: true,
            key: option.value,
            label: option.label,
            checked: selected.includes(option.value),
            onChange: (checked) =>
              onChange(checked ? [...selected, option.value] : selected.filter((v) => v !== option.value)),
          })
        )
      );
    }
    return el(SelectControl, {
      ...next,
      label: field.label,
      help: field.help,
      value: value ?? '',
      options: [{ value: '', label: '—' }, ...options],
      onChange: (v) => onChange(v || undefined),
    });
  }

  function ImageField({ field, value, onChange }) {
    const media = useSelect((select) => (value ? select('core').getMedia(value) : null), [value]);
    const src = media && (media.media_details?.sizes?.medium?.source_url || media.source_url);
    return el(
      Group,
      field,
      el(
        MediaUploadCheck,
        { fallback: el('p', null, "Vous n'avez pas les droits nécessaires pour choisir une image.") },
        el(MediaUpload, {
          allowedTypes: ['image'],
          value,
          onSelect: (selected) => onChange(selected.id),
          render: ({ open }) =>
            el(
              'div',
              { className: 'carlo-field__media' },
              src && el('img', { src, alt: media.alt_text || '' }),
              el(
                'div',
                { className: 'carlo-field__actions' },
                el(Button, { variant: 'secondary', onClick: open }, value ? "Remplacer l'image" : 'Choisir une image'),
                // Test explicite : une image vide d'un champ meta vaut 0, que React afficherait.
                value ? el(Button, { variant: 'tertiary', isDestructive: true, onClick: () => onChange(undefined) }, 'Retirer') : null
              )
            ),
        })
      )
    );
  }

  // Boutons Monter / Descendre / Supprimer d'une liste ordonnée (répéteur, références).
  function ItemActions({ items, index, label, min = 0, onChange }) {
    const move = (to) => {
      const moved = [...items];
      moved.splice(to, 0, moved.splice(index, 1)[0]);
      onChange(moved);
    };
    const button = (props) => el(Button, { size: 'small', showTooltip: true, accessibleWhenDisabled: true, ...props });
    return [
      button({ key: 'up', icon: 'arrow-up-alt2', label: `Monter ${label}`, disabled: index === 0, onClick: () => move(index - 1) }),
      button({
        key: 'down',
        icon: 'arrow-down-alt2',
        label: `Descendre ${label}`,
        disabled: index === items.length - 1,
        onClick: () => move(index + 1),
      }),
      button({
        key: 'remove',
        icon: 'trash',
        label: `Retirer ${label}`,
        isDestructive: true,
        disabled: items.length <= min,
        onClick: () => onChange(items.filter((_, j) => j !== index)),
      }),
    ];
  }

  function RepeaterField({ field, path, value, onChange }) {
    // Comme ACF : les éléments obligatoires (_min, _times) sont affichés d'emblée.
    const items = [...(value || [])];
    while (items.length < field.min) items.push({});

    return el(
      Group,
      field,
      items.map((item, i) =>
        el(
          'div',
          { key: i, className: 'carlo-field__item' },
          el(
            'div',
            { className: 'carlo-field__item-header' },
            el('span', null, `Élément ${i + 1}`),
            el(ItemActions, { items, index: i, label: `l'élément ${i + 1}`, min: field.min, onChange })
          ),
          el(Fields, {
            fields: field.fields,
            path: `${path}.${i}.`,
            values: item,
            onChange: (key, v) => onChange(items.map((it, j) => (j === i ? { ...it, [key]: v } : it))),
          })
        )
      ),
      (field.max === null || items.length < field.max) &&
        el(Button, { variant: 'secondary', onClick: () => onChange([...items, {}]) }, field.add_button)
    );
  }

  // Contenus référencés : recherche parmi les types autorisés (_ref_type), liste ordonnée.
  function ReferenceField({ field, value, onChange }) {
    const ids = value || [];
    const [search, setSearch] = useState('');
    const { selected, results } = useSelect(
      (select) => {
        // Libellé du type (« Marque », « Plat »…) plutôt que son nom technique.
        const typeLabel = (type) => select('core').getPostType(type)?.labels?.singular_name ?? type;
        const query = (args) =>
          field.post_types.flatMap((type) =>
            (select('core').getEntityRecords('postType', type, { per_page: 20, status: 'publish', _fields: 'id,title,type', ...args }) || []).map(
              (post) => ({ id: post.id, label: `${decodeEntities(post.title.rendered) || '(sans titre)'} · ${typeLabel(type)}` })
            )
          );
        return {
          selected: ids.length ? query({ include: ids }) : [],
          results: search ? query({ search }) : [],
        };
      },
      [ids.join(), search, field.post_types.join()]
    );
    const labels = Object.fromEntries(selected.map((post) => [post.id, post.label]));
    const full = field.max !== null && ids.length >= field.max;

    return el(
      Group,
      field,
      ids.length > 0 &&
        el(
          'ul',
          { className: 'carlo-field__references' },
          ids.map((id, i) =>
            el(
              'li',
              { key: id, className: 'carlo-field__item-header' },
              el('span', null, labels[id] ?? `#${id}`),
              el(ItemActions, { items: ids, index: i, label: labels[id] ?? `#${id}`, onChange: (v) => onChange(v.length ? v : undefined) })
            )
          )
        ),
      full
        ? el('p', { className: 'carlo-field__help' }, `Maximum atteint (${field.max}).`)
        : el(ComboboxControl, {
            ...next,
            label: 'Ajouter un contenu',
            hideLabelFromVision: ids.length > 0,
            placeholder: 'Rechercher par titre…',
            value: null,
            options: results.filter((post) => !ids.includes(post.id)).map((post) => ({ value: String(post.id), label: post.label })),
            onFilterValueChange: setSearch,
            onChange: (id) => id && onChange([...ids, Number(id)]),
          })
    );
  }

  /* ---------------------------------------------------------------------------------------- */
  /* Aperçu saisissable                                                                        */
  /* ---------------------------------------------------------------------------------------- */

  // Rendu serveur de la section, recalculé seulement quand un champ autre qu'un texte change.
  // Renvoie { html, values } (les valeurs rendues), null tant que le premier rendu n'est pas arrivé.
  function usePreview(name, fields, values) {
    const [preview, setPreview] = useState(null);
    const postId = useSelect((select) => select('core/editor')?.getCurrentPostId(), []);
    const key = JSON.stringify(withoutText(fields, values));
    const last = useRef(0);

    useEffect(() => {
      const request = ++last.current;
      const timer = setTimeout(
        () =>
          wp
            .apiFetch({
              path: wp.url.addQueryArgs(`/wp/v2/block-renderer/${name}`, { context: 'edit', post_id: postId }),
              method: 'POST',
              data: { attributes: values },
            })
            .then((response) => request === last.current && setPreview({ html: response.rendered, values }))
            .catch(
              (error) =>
                request === last.current &&
                setPreview({ html: `<p class="carlo-placeholder">Aperçu indisponible : ${escapeHTML(error.message || '')}</p>`, values })
            ),
        preview === null ? 0 : 400
      );
      return () => clearTimeout(timer);
    }, [key]);

    return preview;
  }

  // Textes que l'aperçu rendu ne montre pas encore (élément de répéteur ajouté, premier rendu) :
  // ils seront saisis dans l'aperçu, le panneau ne doit pas les proposer le temps du rendu,
  // sous peine de les voir disparaître en pleine frappe. `rendered` : valeurs de l'aperçu, ou undefined.
  function unrendered(fields, values, rendered, prefix = '') {
    return Object.entries(fields).flatMap(([key, field]) => {
      const path = prefix + key;
      if (INLINE_TYPES.includes(field.type)) return rendered ? [] : [path];
      if (field.type === 'group') return unrendered(field.fields, values?.[key], rendered && (rendered[key] ?? {}), `${path}.`);
      if (field.type === 'repeater') {
        return (values?.[key] || []).flatMap((item, i) => unrendered(field.fields, item, rendered?.[key]?.[i], `${path}.${i}.`));
      }
      return [];
    });
  }

  // Prépare le HTML de l'aperçu : marqueurs de texte → emplacements des champs, marqueurs dans
  // les attributs HTML → data-carlo-attrs (valeurs posées dans le DOM, sans reconstruire l'aperçu).
  function prepare(html, fields) {
    const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
    const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT | NodeFilter.SHOW_COMMENT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);

    nodes.forEach((node) => {
      if (node.nodeType === Node.COMMENT_NODE) return node.remove();
      if (node.nodeType === Node.ELEMENT_NODE) {
        const attrs = Object.fromEntries([...node.attributes].filter((a) => MARKER.test(a.value)).map((a) => [a.name, a.value]));
        if (Object.keys(attrs).length) node.dataset.carloAttrs = JSON.stringify(attrs);
        return;
      }
      if (!MARKER.test(node.data)) return;

      const parent = node.parentNode;
      const parts = node.data.split(MARKER); // texte, chemin, texte, chemin…
      // Wysiwyg seul dans son élément : l'élément lui-même devient la zone cliquable, pour garder
      // la structure attendue par le CSS du thème (.text > p…).
      if (parts.length === 3 && !parts[0].trim() && !parts[2].trim() && parent.childNodes.length === 1 && fieldAt(fields, parts[1])?.type === 'wysiwyg') {
        parent.dataset.carloWysiwyg = parts[1];
        node.remove();
        return;
      }
      const fragment = doc.createDocumentFragment();
      parts.forEach((part, i) => {
        if (i % 2 === 0) return part && fragment.append(part);
        const slot = doc.createElement(fieldAt(fields, part)?.type === 'wysiwyg' ? 'div' : 'span');
        slot.dataset[fieldAt(fields, part)?.type === 'wysiwyg' ? 'carloWysiwyg' : 'carloField'] = part;
        fragment.append(slot);
      });
      node.replaceWith(fragment);
    });
    return doc.body.innerHTML;
  }

  // Texte court ou long, saisi en place, sans mise en forme (comme le champ ACF).
  function InlineText({ field, value, onChange }) {
    return el(RichText, {
      tagName: 'span',
      className: 'carlo-inline',
      'aria-label': field.label,
      placeholder: field.label,
      value: escapeHTML(value ?? ''),
      onChange: (v) => onChange(decodeEntities(v) || undefined),
      allowedFormats: [],
      withoutInteractiveFormatting: true,
      disableLineBreaks: field.type === 'text_short',
      preserveWhiteSpace: field.type === 'text',
    });
  }

  function SectionEdit({ name, section, attributes, setAttributes }) {
    const { fields } = section;
    const keys = Object.keys(fields);
    const values = Object.fromEntries(keys.filter((key) => attributes[key] !== undefined).map((key) => [key, attributes[key]]));
    const update = (path, value) => {
      const [key, ...rest] = path.split('.');
      setAttributes({ [key]: rest.length ? setAt(attributes[key], rest, value) : value });
    };

    const rendered = usePreview(name, fields, values);
    const html = rendered && rendered.html;
    const markup = useMemo(() => html && prepare(html, fields), [html]);
    const ref = useRef();
    const [slots, setSlots] = useState({ texts: [], wysiwygs: [] });
    const [editing, setEditing] = useState(null); // chemin du wysiwyg ouvert dans la modale

    useLayoutEffect(() => {
      const root = ref.current;
      setSlots({
        texts: root ? [...root.querySelectorAll('[data-carlo-field]')] : [],
        wysiwygs: root ? [...root.querySelectorAll('[data-carlo-wysiwyg]')] : [],
      });
    }, [markup]);

    // Valeurs posées dans le DOM de l'aperçu : attributs HTML et contenu des wysiwyg.
    useLayoutEffect(() => {
      ref.current?.querySelectorAll('[data-carlo-attrs]').forEach((node) =>
        Object.entries(JSON.parse(node.dataset.carloAttrs)).forEach(([attr, template]) =>
          node.setAttribute(attr, template.replace(MARKERS, (_, path) => getAt(values, path) ?? ''))
        )
      );
      slots.wysiwygs.forEach((node) => {
        const path = node.dataset.carloWysiwyg;
        const label = fieldAt(fields, path).label;
        node.innerHTML = getAt(values, path) || `<p class="carlo-inline-placeholder">${escapeHTML(label)} : cliquer pour saisir le texte</p>`;
        node.setAttribute('role', 'button');
        node.setAttribute('tabindex', '0');
        node.setAttribute('aria-label', `Modifier : ${label}`);
      });
    }, [slots, values]);

    const inlinePaths = new Set([
      ...slots.texts.map((n) => n.dataset.carloField),
      ...slots.wysiwygs.map((n) => n.dataset.carloWysiwyg),
      ...unrendered(fields, values, rendered && rendered.values),
    ]);
    const hasInline = Boolean(markup && /data-carlo-(field|wysiwyg)/.test(markup));

    const openWysiwyg = (event) => {
      const node = event.target.closest('[data-carlo-wysiwyg]');
      if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
      if (node) {
        event.preventDefault();
        setEditing(node.dataset.carloWysiwyg);
      } else if (event.type === 'click' && event.target.closest('a')) {
        event.preventDefault(); // pas de navigation depuis l'aperçu
      }
    };

    const blockProps = useBlockProps();
    let preview;
    if (markup === null) {
      preview = el(Spinner);
    } else if (keys.length > 0 && !hasInline && !isFilled(values)) {
      // Rien à saisir dans l'aperçu (image seule…) : on oriente vers le panneau.
      preview = el('p', { className: 'carlo-placeholder' }, `${section.title} : à remplir dans le panneau Réglages.`);
    } else {
      preview = el('div', {
        ref,
        className: 'carlo-preview',
        onClick: openWysiwyg,
        onKeyDown: openWysiwyg,
        dangerouslySetInnerHTML: { __html: markup },
      });
    }

    const panelFields = keys.filter((key) => inPanel(fields[key], key, inlinePaths));

    return el(
      InlinePaths.Provider,
      { value: inlinePaths },
      panelFields.length > 0 &&
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: 'Contenu' },
            inlinePaths.size > 0 && el('p', { className: 'carlo-field__help' }, 'Les textes se saisissent directement dans le bloc.'),
            el(Fields, { fields, values, onChange: (key, value) => setAttributes({ [key]: value }) })
          )
        ),
      el('div', blockProps, preview),
      slots.texts.map((node) => {
        const path = node.dataset.carloField;
        return createPortal(
          el(InlineText, { field: fieldAt(fields, path), value: getAt(values, path), onChange: (v) => update(path, v) }),
          node,
          path
        );
      }),
      editing &&
        el(
          Modal,
          {
            title: fieldAt(fields, editing).label,
            size: 'large',
            shouldCloseOnClickOutside: false,
            onRequestClose: () => setEditing(null),
          },
          el(WysiwygField, { field: { label: '' }, value: getAt(values, editing), onChange: (v) => update(editing, v) }),
          el('div', { className: 'carlo-modal__actions' }, el(Button, { variant: 'primary', onClick: () => setEditing(null) }, 'Terminer'))
        )
    );
  }

  /* ---------------------------------------------------------------------------------------- */
  /* Champs propres au type de contenu (clé meta: de structure.yml)                            */
  /* ---------------------------------------------------------------------------------------- */

  function MetaFields({ config }) {
    const [meta, setMeta] = wp.coreData.useEntityProp('postType', config.postType, 'meta');
    return el(
      wp.editor.PluginDocumentSettingPanel,
      { name: 'carlo-meta', title: config.title, className: 'carlo-meta' },
      // Un champ vidé (undefined) part en null : l'API REST supprime alors la meta, au lieu d'ignorer la clé.
      el(Fields, { fields: config.fields, values: meta, onChange: (key, value) => setMeta({ ...meta, [key]: value ?? null }) })
    );
  }

  // Panneau Document : défini par PHP dans les réglages de l'éditeur (cf. src/wp/meta.php).
  function MetaPanel() {
    const config = useSelect((select) => select('core/editor').getEditorSettings().carloMeta, []);
    return config ? el(MetaFields, { config }) : null;
  }

  wp.plugins.registerPlugin('carlo-meta', { render: MetaPanel });

  Object.entries(sections).forEach(([name, section]) => {
    wp.blocks.registerBlockType(name, {
      edit: (props) => el(SectionEdit, { ...props, name, section }),
      save: () => null,
    });
  });
})(window.wp, window.carloSections || {});
