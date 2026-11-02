# Carlo - code architecture (for AI agents and developers)

How the code is organised, how a request flows through it, and the rules to keep when changing it. For
usage (structure.yml, fields, templates), read [README.md](./README.md) first.

## Invariants

1. **One field normalizer**: `carlo\Fields`. The editor (`window.carloSections`), the block attribute schema,
   data loading and the catalogue all use its output. Do not add another one.
2. **Data is loaded once, completed always.** `load()` turns source data (block attributes, a story, demo
   data) into template data and formats it; formatting is not idempotent (`nl2br`, `wpautop`), so `load()` is
   called exactly once, where data enters. `render()` only calls `complete()`, which is idempotent and never
   formats. Never call `load()` on data that was already loaded, nor format inside a template.
3. **No ACF, no nested sections.** Sections are native blocks; a section never contains other sections. The
   only blocks with children are regions (`carlo/region`).
4. **The engine (`src/carlo`) knows nothing about WordPress.** WordPress code lives in `wp.php`, `src/wp` and
   `assets`.

## File map

| File | Role |
| --- | --- |
| `src/carlo/carlo.php` | global functions: `carlo_driver`, `carlo_register`, `carlo_register_namespace`, `carlo_structure`, `carlo_explode_id`, `carlo_render`, `carlo_get`, `carlo_load`, `carlo_load_data`, `carlo_component`, `carlo_img`, context and introspection helpers |
| `src/carlo/DriverInterface.php` | the driver contract |
| `src/carlo/BaseDriver.php` | rendering, structure loading (`!load`), file resolution, `fields()`, `load()`, `complete()` |
| `src/carlo/Fields.php` | field normalization (`Fields::of()`, `Fields::field()`) |
| `src/carlo/LoadContext.php` | context of a value during `load()`: field path, element, options |
| `src/carlo/YmlDriver.php` | standalone driver: demo data from a YAML file, picsum images |
| `src/carlo/Introspection.php` | discovery of elements and variants in the driver's search paths |
| `src/carlo/Stories.php` | component catalogue model: elements, their `*.stories.yml` examples, fields table (no WordPress) |
| `src/carlo/Placeholders.php` | trait for a catalogue driver: generated content for empty fields, `WxH` placeholder images, fictitious references of an example (`storyReferences()`, created by the driver in `fakeReference()`) |
| `src/templates/**` | generic sections and components (`.php`, `.yml`, `.stories.yml`) |
| `wp.php` | plugin entry: loads the package autoload if needed, then `src/wp/*.php` |
| `src/wp/carlo.php` | `CarloWpDriver` (file lookup, WordPress formatting, images), driver instance, post types, `carlo_menu`, `carlo_render_region`, `carlo_bootstrap` |
| `src/wp/blocks.php` | one dynamic block per section: names, attribute schema, render callback, editor data |
| `src/wp/meta.php` | `meta:` of a type: post meta registration, panel definition for the editor (`carloMeta` in the editor settings) |
| `src/wp/regions.php` | the `carlo/region` block and its editor data (`window.carloRegions`) |
| `src/wp/templates.php` | page/post templates: choice at creation, regions kept in the content, editor lock, admin column |
| `src/wp/assets.php` | theme assets through Vite (dev server or manifest), theme CSS in the editor canvas |
| `src/wp/feature.php`, `media.php`, `menus.php`, `errors.php`, `archive.php` | WordPress defaults, image sizes, menu locations, error handler, archive AJAX |
| `src/wp/templates/` | WordPress versions of `global/html_start`, `global/html_end`; `layout.php`, `error.php`, `archive.php`, `catalogue.php`, `frame.php` |
| `src/wp/stories.php` | component catalogue in WordPress (not loaded in production): route, admin menu, `CarloStoriesDriver` (fictitious posts in the object cache, IDs from 2000000001) |
| `assets/sections.js`, `sections.css` | section block editor (no build step, no JSX) |
| `assets/region.js`, `region.css` | region block editor |
| `assets/catalogue.css` | catalogue page style |
| `demo/` | standalone demo (`index.php`, `data.yml`, `structure.yml`) |
| `dev/` | Bedrock + Lando environment, test theme `carlo-test`, browser tests (`dev/tests/browser`) |

## Request flows

**Site page.** The theme's `index.php` calls `carlo_bootstrap()` → `src/wp/templates/layout.php` picks the
template name, runs the `carlo_prerender` action, renders `global/html_start`, then either the theme's
`templates/<template>.php` or `carlo_render_region()` for each region of the content's template →
`render_block()` of each section block → its `render_callback` (`src/wp/blocks.php`):

```php
carlo_render($id, carlo_load($id, $attributes, ['preview' => carlo_is_editor_preview()]));
```

A section without any filled attribute renders nothing on the site.

**Editor preview.** `assets/sections.js` asks the REST block renderer for the section's HTML. There,
`carlo_is_editor_preview()` is true: text fields (`CARLO_INLINE_TYPES`) are replaced by `{{carlo:path}}`
markers, which the script turns into editable fields; repeaters show their `_min` items. Other fields are
edited in the Settings panel, generated from `window.carloSections`.

**Catalogue frame.** `/?carlo-stories=frame&id=…&story=…` (`src/wp/stories.php`) swaps the global driver
for a `CarloStoriesDriver` (`carlo\Placeholders` over `CarloWpDriver`: placeholder images for `WxH` values; for an element
without stories, empty fields get generated content from their type: `$placeholders`), then `frame.php` renders
`carlo_render($id, carlo_load($id, $story without _label))`.

**Standalone demo.** `demo/index.php` uses `YmlDriver` and renders each section of a template with
`carlo_render($id, carlo_load($id))`.

## Data contract

`BaseDriver::load(string $id, array $raw = [], array $options = []): array` walks `fields($id)` once
(`loadFields()`):

- keys absent from the structure pass through unchanged (arguments of a parent, element without `.yml`);
- `group`: `['_id' => <component id from the structure>] + <its fields loaded>`; a raw `_id` is never trusted;
- `repeater`: `loadRepeater()`, each item loaded;
- any other type: `loadValue()`.

Extension points, overridden by drivers:

| Method | BaseDriver | YmlDriver | CarloWpDriver |
| --- | --- | --- | --- |
| `loadValue($field, $value, LoadContext $ctx)` | `$value ?? $field['default']` | absent value looked up in the data file | `text` → `nl2br`, `wysiwyg` → `wpautop`, `reference` → published `WP_Post[]`, `boolean`, `gallery`; with `options['preview']`, inline types → `{{carlo:a.b.0.c}}` |
| `loadRepeater($field, $value, LoadContext $ctx)` | loads each item | absent value → `min(max, (min ?: 2) + 3)` empty items | with `options['preview']`, padded to `min` |

`LoadContext` is immutable: `path` (field keys and repeater indexes), `element` (name of the loaded element,
the demo data prefix), `options` (only interpreted by drivers). `child($key)` returns the context of a sub-field.

`render()` calls `complete(fields($tpl), $args)` for every element except menus: every declared field is
present (its `default`), every group gets the `_id` of its structure, recursively in groups and repeater
items. No formatting, safe to run on already completed data: templates re-render components with
`carlo_get('cta')`, often merged with extra keys.

Public functions: `carlo_load($id, $raw, $options)` (pure) and `carlo_load_data($id, $raw, $options)` (loads,
merges the data into the current level for `carlo_get()`, returns it). `BaseDriver::$tplArgs` starts with a root
level, so both work outside any render. `render()` pops its level in a `finally`.

**Exposed data.** `carlo_get_exposed($name, $source)` → `BaseDriver::getExposed()`: `exposed()` indexes once the
names declared by `_expose` (on a section, read from its structure, or on a field, kept by `Fields::field()` as
`expose`) and by the `meta:` keys of the types; a name is unique, except between the variants of one section; a
field inside a repeater cannot be exposed. The raw values come from the driver (`exposedSource()`: attributes of
the section's block in the content `$source`, `exposedMeta()`: post meta; `[]` by default, so the demo and
catalogue drivers get their usual mock or placeholder values), then `load()` formats them: same data as the
section's template, never loaded twice.

`carlo_get($key)` reads the current level (`a.b` for nested values) and falls back to the global context
(`carlo_context_add()`) when the value is `""`.

## Fields

`Fields::of(?array $definition)` normalizes every key not starting with `_`; `BaseDriver::fields($id)` caches it
for an element. Output of `Fields::field($key, $definition)`:

```php
['type', 'label', 'help'?, 'default']
 + group:     ['id' => '<component _id>', 'fields']
 + repeater:  ['fields', 'min' => _min ?? _times ?? 0, 'max' => _max ?? _times ?? null, 'add_button']
 + choices:   ['choices', 'multi']
 + reference: ['post_types' => (array) _ref_type, 'max']
```

Type: a string definition is the type; an array uses `_type`, or is a `group` when it has an `_id` (a `!load`ed
component); anything else throws. `select` → `choices`, `image` + `_multi` → `gallery`. `default`: `false` for
`boolean`; `[]` for `group`, `repeater`, `gallery`, `reference` and multiple `choices`; `''` otherwise.
Since `render()` normalizes the fields of every element, an invalid definition breaks its rendering.

## Structure and files

- `BaseDriver::structure($type, $name, $variant, $namespace)` parses the project's `structure.yml` once, then
  each element's `.yml` on demand, resolving `!load` recursively (cached, with an `_id` added). Any other YAML
  tag throws with the file name. An element without `.yml` gets `['_label' => …, '_id' => …]`.
- `BaseDriver::getPathsToTest($ext, "$type/$name", $variant, $namespace)`: `name/variant`, then `name/base`,
  then `name.ext` (for `base`: `name.ext`, then `name/base`), each first with the `@namespace/` prefix.
  `extension()` gives the extension by role: `structure` → `yml`, `stories` → `stories.yml`, else `php`.
- `CarloWpDriver::getFile()`: `locate_template()` (child theme, parent theme, path by path), then
  `src/wp/templates`, then `parent::getFile()` (the namespace folder, `src/templates` for `default`).
  `getSearchPaths()` returns the same folders for `Introspection`, which lists variants from the `.php` and
  `.yml` files it finds (`*.stories.yml` excluded).

## WordPress integration

- **Block names**: `carlo_block_name('sections/push:pictos')` → `carlo/sections-push-pictos` (`:base`
  dropped). `carlo_sections()` lists the sections proposed by the regions of every template, by `_id`.
- **Attributes**: `carlo_block_attributes()` maps each top-level field to a JSON type: string for
  `text_short`, `text`, `wysiwyg`, `url`, `color`, `date`, `datetime`, `embed`; integer for `image`, `file`;
  `boolean`; `number`; object for `group`; array for `repeater`, `gallery`, `reference`, multiple `choices`;
  any type otherwise.
- **Templates and regions** (`src/wp/templates.php`): `carlo_templates($post_type)`,
  `carlo_post_template($post)`, `carlo_region($definition)` (allowed sections and `_max`),
  `carlo_template_content()` (one `carlo/region` block per region of the template, in order; nothing removed),
  applied to new posts (`default_content`) and to the editor's REST response. The editor gets
  `templateLock: insert`, no template selector, no block locking.
- **Hooks registered**: `init` (post types, blocks, region block), `enqueue_block_editor_assets`
  (`window.carloSections`, `window.carloRegions`, TinyMCE), `admin_body_class`, `load-post-new.php`,
  `admin_menu` (« Ajouter : … » entries, catalogue), `theme_{$post_type}_templates`, `rest_prepare_{$post_type}`,
  `block_editor_settings_all`, `manage_pages_columns`, `after_setup_theme`, `template_redirect` (catalogue),
  `wp_enqueue_scripts`, `enqueue_block_assets`, plus the defaults of `feature.php`. Action fired:
  `carlo_prerender($template)`.

## Recipes

**Add a section.** Create `templates/sections/<name>/base.php` and `base.yml` (theme or `src/templates`), list it
with `!load sections/<name>` in a region of `structure.yml`, add `base.stories.yml` for the catalogue.

**Add a field type.** Update, in order: `Fields::field()` (type, options, `default`), `carlo_block_attributes()`
(JSON type), `Field` in `assets/sections.js` (editor control), `CarloWpDriver::loadValue()` (formatting), and
`CARLO_INLINE_TYPES` if it is typed in the preview.

**Add a driver.** Extend `BaseDriver`; override `getFile()`/`getSearchPaths()` for other folders,
`loadValue()`/`loadRepeater()` for another data source or formatting, `img()` for images.

## Verification

From `/app` in the dev environment (`dev/`, see [dev/README.md](./dev/README.md)):

```bash
/opt/browser/bin/python tests/browser/editor.py    # regions, allowed sections, _max, typing, site rendering
/opt/browser/bin/python tests/browser/stories.py   # every story of the catalogue, JS errors, failed resources
wp eval 'ob_start(); carlo_render("sections/quote", []); ob_end_clean(); echo "ok";'   # renders without data
```

To check a refactoring, compare the HTML of the site pages, of the catalogue frames and of the editor preview
(`render_block()` with `REST_REQUEST` defined and a `block-renderer` request URI) before and after.

## Pitfalls

- Calling `load()` twice, or passing loaded data to `carlo_load()`, formats twice.
- `carlo_get()` returns `""` for a missing key; `carlo_img()` needs a size (its default size is not nullable).
- PHP resolves symlinks in `__DIR__`: when the plugin folder is a symlink, URLs are built with `plugins_url()`
  from `wp.php`, which WordPress maps back to the plugin folder.
- The editor preview is detected from the REST URI (`block-renderer`): rendering a block elsewhere in a REST
  request is not a preview.
- The catalogue is public outside production; it must only render discovered elements.
