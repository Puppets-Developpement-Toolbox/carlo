# Carlo

Carlo is a PHP templating engine whose content structures are described in YAML, and the WordPress plugin built
on it, developed by [Puppets](https://puppets.fr). A theme declares its page templates, regions and sections in a
`structure.yml` file; every section becomes a native block in the WordPress block editor, and is rendered by a
plain PHP template on the site.

| Path | Content |
| --- | --- |
| `src/carlo` | the engine, without WordPress: drivers, structure loading, fields, data loading, introspection |
| `src/templates` | generic sections and components (PHP template + YAML structure), see [these mockups](https://www.figma.com/design/YAGncUCOUdVUay7mKLAH2x/-LIB--Diapsodie?node-id=2-4&p=f&t=QDyYKzHqjUoKvxHK-0) |
| `wp.php`, `src/wp`, `assets` | the WordPress plugin: driver, blocks, regions, page templates, component catalogue, editor scripts |
| `library.css`, `src/css`, `src/js` | front-end styles and scripts (Tailwind 4) |
| `demo` | standalone demo of the templates, without WordPress |
| `dev` | WordPress development environment (Bedrock + Lando) and browser tests, see [dev/README.md](./dev/README.md) |

Further reading: [Carlo.md](./Carlo.md) (concepts, in French) and [README-AI.md](./README-AI.md) (code
architecture, for developers and AI agents changing the code).

## Requirements

- PHP 8.1+ and WordPress 6.5+ (block API v3, `wp_is_serving_rest_request()`).
- A theme with a `structure.yml` file at its root: without it, the plugin does nothing.
- Composer, through which the package and its dependency (`symfony/yaml`) are installed.
- `idleberg/wordpress-vite-assets` in the site's dependencies: it loads the theme's assets in production and is
  not declared by the package yet.

## Installation (Bedrock)

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/Puppets-Developpement-Toolbox/library.git" }
  ],
  "require": { "puppets/carlo": "<branch or version>" }
}
```

The package type is `wordpress-plugin`: it is installed in `web/app/plugins/carlo`. Then:

1. Activate the **Carlo** plugin.
2. In the theme, call `carlo_bootstrap()` from `index.php`: the plugin picks the template and renders the page.
3. Import the library styles in the theme's CSS: `@import "../../../plugins/carlo/library.css";`.

The theme's assets are loaded with Vite (see [Assets](#assets)).

## Elements

Sections (`sections/…`) and components (`components/…`) are **elements**. An element is identified by
`type/name:variant`, e.g. `sections/push:pictos`; the variant defaults to `base` (`sections/text` =
`sections/text:base`). Each element is made of files in a `templates/` folder:

```
templates/sections/quote/
├── base.php           # the template
├── base.yml           # its structure: the fields (optional for an element without data)
├── base.stories.yml   # examples for the component catalogue (optional)
└── dark.php           # a variant: dark.yml and dark.stories.yml fall back to base.* when absent
```

A missing file of a variant falls back to the `base` file, for the template, the structure and the stories
alike. A flat file (`templates/sections/quote.php`) is also accepted for the `base` variant.

Files are looked up, first found wins, in: the child theme, the parent theme, the plugin (`src/wp/templates`),
then the library (`src/templates`). A theme overrides any element of the library by providing the same file.
Elements of another library can be registered as a namespace:
`carlo_register_namespace('restaurant', $path)`, then `@restaurant:sections/menu`.

## structure.yml

```yaml
menus:
  main: Menu principal              # registered as a WordPress menu location

regions:                            # labels shown in the editor
  header: En-tête
  content: Contenu

templates:                          # page templates; `default` is used when none is chosen
  default:
    _label: Page éditoriale
    content:                        # a region and the sections it accepts
      - _blocs: [!load sections/text, !load sections/image, !load sections/quote]
        _max: 6                     # at most 6 sections in this region
  landing:
    _label: Page templatisée
    header: [!load sections/image]
    content: [!load sections/text, !load sections/quote]

types:                              # custom post types
  project:
    wp_args:                        # arguments of register_post_type()
      label: Projets
      supports: [title, thumbnail]
    template:                       # its template (or `templates:` with several, `default` first)
      _label: Fiche projet
      content: [!load sections/text]
```

- `!load sections/text` inserts the structure of an element. It is the only YAML tag: any other tag
  (e.g. a typo such as `!components`) raises an error naming the file.
- A region proposes every section it lists, in any order and number (up to `_max`).
- The template of a content is chosen when it is created (« Pages > Ajouter : Page templatisée ») and cannot
  change afterwards. The posts page uses the `archive` template.

## Fields

A field is declared in the element's `.yml`, by its type alone or with options:

```yaml
titre: text_short
content:
  _type: wysiwyg
  _label: Contenu
  _help: Texte affiché sous le titre.
quote: !load components/quote       # a component: a group of fields with its own template
items:
  _type: repeater
  _min: 1                           # or _times for an exact number
  _max: 6
  _add_button: Ajouter une carte
  _repeat:
    title: text_short
    image: image
pages:
  _type: reference
  _ref_type: [page, project]
  _max: 3
style:
  _type: choices
  _choices: { light: Clair, dark: Sombre }
```

| Type | Editor | Value in the template |
| --- | --- | --- |
| `text_short` | typed in the block | string |
| `text` | typed in the block | string, line breaks as `<br />` |
| `wysiwyg` | typed in the block (TinyMCE dialog) | HTML, paragraphs added (`wpautop`) |
| `url`, `number` | Settings panel | string, number |
| `boolean` | Settings panel | `bool` |
| `image` | Settings panel (media library) | attachment ID, for `carlo_img()` |
| `choices` | Settings panel (`_multi` for several) | the chosen key(s) |
| `reference` | Settings panel (search, ordered list) | published `WP_Post` objects |
| `group` | its fields, in the block or the panel | array, with the component's `_id` |
| `repeater` | Settings panel, items added and ordered | list of arrays |

A text field used inside an HTML attribute (e.g. `id="<?= carlo_get('ancre') ?>"`) is typed in the
Settings panel instead of the block. `select` is a deprecated name of `choices`; `image` with `_multi` is a `gallery`. Other types (`gallery`,
`color`, `date`, `datetime`, `embed`, `file`, `link`, `taxonomy`) are stored but not editable yet: the editor
shows a notice in their place.

Every declared field reaches the template, with a default value when it is empty (`''`, `false` for a
boolean, `[]` for a group, repeater, gallery or reference): templates do not need `isset()`.

### Reading data elsewhere: `_expose` and `carlo_get_exposed()`

A field is typed in its section, but a template may need it elsewhere (the opening hours of a restaurant in
a card, in the header…). Name it with `_expose`, on a section (all its data) or on a field:

```yaml
# templates/sections/opening_hours/base.yml
_label: Horaires d'ouverture
_expose: opening_hours          # the whole section
hours:
  _type: repeater
  _expose: hours_list           # or one field
```

```php
carlo_get_exposed('opening_hours');            // the displayed content
carlo_get_exposed('opening_hours', $post);     // another one: an ID or a WP_Post
```

The value is loaded like in the section's own template (same formatting), with default values when the
section is not in the content. Rules, checked when the structure is read:

- a name designates one piece of data, the same name is accepted only for the variants of one section
  (`hero:slider` and `hero:pic`);
- `_expose` is not allowed on a field inside a repeater (which item?);
- a section present several times in a content: the first one is read.

### Fields of a content type: `meta:`

Data that belongs to no section (an identifier used for API calls…) is declared on the type, with the same
syntax as a section's fields:

```yaml
types:
  restaurant:
    wp_args: { label: Restaurants, supports: [title, thumbnail] }
    template: { _label: Fiche restaurant, content: [!load sections/text] }
    meta:
      _label: Informations du restaurant   # title of the panel (default: Informations)
      google_place_id:
        _type: text_short
        _label: Identifiant Google My Business
```

Each field is a post meta (`register_post_meta`, exposed to the REST API: **never put a secret in it**), edited
in a panel of the document sidebar of the block editor and read with `carlo_get_exposed('google_place_id')`,
like any exposed data. Supported types: `text_short`, `text`, `number`, `boolean`, `choices` (single), `url`.
The key is the exposed name: it must be unique among all exposed names.

## The block editor

- Each region of the content's template is a locked block, which only accepts the sections it lists, up to
  its `_max` (a counter shows it).
- Each section is a block (`carlo/sections-text`, `carlo/sections-push-pictos`…). Its preview is the real
  template rendered by the server, with the theme's CSS; text fields are typed directly in it, the other
  fields are in the Settings panel. Change `structure.yml`, reload the editor: the blocks follow.
- A section left empty is not rendered on the site; the editor shows a message in its place.

## Templates

```php
<?= carlo_get('titre') ?>                              // a value; 'quote.author' for a nested one
<?php carlo_component('quote') ?>                      // a group field, rendered by its own template
<?php carlo_render('components/cta', carlo_get('cta')) ?>
<?= carlo_img('image', '630x394') ?>                   // responsive image (see below)
<?php carlo_menu('main') ?>                            // renders templates/menus/main.php with `items`
```

Values arrive formatted (see the table above). Data that does not come from a block, such as a query in a
custom template, is loaded with `carlo_load_data($id, $values)`: it is formatted like block data and becomes
readable with `carlo_get()`.

`carlo_bootstrap()` picks the template: `templates/<template>.php` of the theme when it exists (`archive` for
the posts page, `error` for a 404, the page template slug or `default`, `type_<post_type>` for a single post),
otherwise the regions of the content's template, in their order. The `carlo_prerender` action runs first,
with the template name.

### Images

```php
carlo_img('image', '630x394');                                          // one size
carlo_img('image', ['1200x800', '(max-width: 767px)' => '600x400']);    // default size + <source> per media
carlo_img('image', '630x394', ['class' => 'cover']);                    // attributes (with a `class` key)
```

Sizes are WordPress image sizes, registered in the theme with `carlo_register_img_size('630x394')` (cropped)
or `carlo_register_img_size('630x394:0')` (not cropped; the size is then named `630x394:0`), several at once
with an array.

### Assets

The plugin loads the theme's `js/main.js` and `css/global.css`: from the Vite server in development
(`WP_ENV=development`, `ASSET_BASE_URL`), from the theme's `dist/.vite/manifest.json` otherwise. The same CSS is
loaded in the editor's canvas, so that previews look like the site.

## Component catalogue

A catalogue of every section and component (library, plugin and theme), each rendered in isolation with the
theme's CSS and JS: `/?carlo-stories`, or Appearance > Catalogue.

- Not loaded in production (`WP_ENV`). Elsewhere (development, staging) it is public, without login, and sends
  `X-Robots-Tag: noindex`. Only discovered elements can be rendered.
- « Thème seulement » keeps the elements of the project: those whose template or structure is resolved outside
  this package (theme, registered namespace), including the package's elements overridden by the theme, marked
  « surchargé ». The choice is remembered by the browser. Origin: `carlo\Stories::origin()` (`carlo`, `project`,
  `override`).
- Examples are written in `<variant>.stories.yml`, next to the template. Each key is an example; values use
  the block attributes format and get the same formatting as on the site:

```yaml
complete:
  _label: Citation complète
  titre: Une < phrase > qui compte
  quote:
    quote: <p>La simplicité est la sophistication suprême.</p>
    author: Léonard de Vinci
image_only:
  image: 1600x900          # a placeholder image (or a media ID)
```

A `reference` field takes post IDs, but IDs differ from one database to another. An example can instead use
fictitious posts, which only exist in memory while the example is rendered (nothing is written to the database):

```yaml
generated:
  dishes: 8                # 8 posts generated from the type: title, every meta: field, placeholder image
described:
  dishes:                  # posts described; omitted fields stay empty
    - post_title: Œuf mayo
      dish_description: Le classique du bistrot.   # a meta: field of the type
      _thumbnail: 600x960                          # placeholder image (or a media ID)
```

Keys of a described post: `post_type` (one of the field's types, the first by default), `post_title`,
`post_excerpt`, `post_content`, `_thumbnail` and the `meta:` fields of the type; any other key is an error shown
in the preview. Templates read these posts like real ones (`get_post_meta()`, `get_post_thumbnail_id()`…).

A variant without its own examples uses those of `base`, marked « (base) »; an element without any is listed
as « aucun exemple », with its fields.

## Standalone demo

`demo/` renders the library's sections without WordPress (`YmlDriver`), with sample data from
`demo/data.yml` (looked up by `<section>--<field>`, then by field name, then by type). From a clone:
`composer install`, then `composer start` (http://localhost:8001) or `lando start` at the repository root.
Installed as a plugin, the package has no `vendor/` of its own and the demo answers 404; `demo/` and `dev/` are
not part of the Composer archive.

## WordPress defaults

The plugin also: disables comments and pings; adds `title-tag`, `post-thumbnails` and `editor-styles` theme
support; adds a « Large paragraphe » style to TinyMCE; removes the emoji scripts; adds a Template column to the
pages list; redirects `/app/uploads` requests to `SFP_URL` when that variable is set; enables the Symfony
error handler when `WP_DEBUG_DISPLAY` is on and the package is available.

## Development

The development environment (Bedrock + Lando, test theme `carlo-test`, browser tests) is documented in
[dev/README.md](./dev/README.md). The repository is mounted live in it as `plugins/carlo`.

## Known issues

- `src/wp/templates/archive.php` and the `archive_content` AJAX action call templates that do not exist.
- `src/css/config/webfonts.css` points to `src/css/webfonts/` instead of `src/webfonts/`: Poppins is not loaded.
- `wp.php` still declares `Requires at least: 6.1` and `Requires PHP: 8.0`.

## License

Proprietary - Puppets Development
