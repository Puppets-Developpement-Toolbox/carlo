<?php

/**
 * Catalogue des composants dans WordPress (la logique est dans carlo\Stories) : public, donc jamais chargé en production.
 *
 * - /?carlo-stories : catalogue, avec &id=<élément>&story=<exemple> pour en afficher un ;
 * - /?carlo-stories=frame&id=<élément>&story=<exemple> : rendu seul d'un exemple (iframe du catalogue).
 */

if ((defined('WP_ENV') ? WP_ENV : wp_get_environment_type()) === 'production') {
    return;
}

/**
 * Driver des exemples : la mise en forme de WordPress, avec le contenu généré de carlo\Placeholders.
 */
class CarloStoriesDriver extends CarloWpDriver
{
    use carlo\Placeholders;

    // Identifiants des contenus fictifs : positifs (absint() les garde) et au-delà de tout identifiant réel.
    private int $fakeId = 2000000000;
    private array $fakeCount = [];

    /**
     * Contenu fictif d'un exemple (cf. carlo\Placeholders::storyReferences()) : un post publié, connu du cache objet
     * seulement, avec ses meta. get_post(), get_the_title(), get_post_meta() et get_post_thumbnail_id() le lisent
     * comme un vrai : les templates n'ont rien à prévoir. Rien n'est écrit en base.
     *
     * Clés de $item : post_type (parmi ceux du champ, le premier par défaut), post_title, post_excerpt,
     * post_content, _thumbnail (dimension « WxH » d'une image de remplacement, ou identifiant d'un média) et les
     * champs `meta:` du type. Une autre clé est une erreur, affichée dans l'aperçu.
     */
    protected function fakeReference(array $field, array $item, bool $generate): int
    {
        $post_type = $item['post_type'] ?? $field['post_types'][0] ?? 'post';
        if (!in_array($post_type, $field['post_types'], true)) {
            throw new Exception("contenu d'exemple : le type {$post_type} n'est pas accepté par ce champ");
        }
        $meta_fields = carlo_meta_fields($post_type);
        $unknown = array_diff(array_keys($item), ['post_type', 'post_title', 'post_excerpt', 'post_content', '_thumbnail'], array_keys($meta_fields));
        if ($unknown) {
            throw new Exception("contenu d'exemple de type {$post_type} : clé inconnue " . implode(', ', $unknown));
        }

        $id = ++$this->fakeId;
        $number = $this->fakeCount[$post_type] = ($this->fakeCount[$post_type] ?? 0) + 1;
        $type = get_post_type_object($post_type);

        $meta = [];
        foreach ($meta_fields as $key => $meta_field) {
            $value = $item[$key] ?? ($generate ? $this->placeholder($meta_field) : null);
            if ($value !== null) {
                $meta[$key] = [is_bool($value) ? (string) (int) $value : (string) $value];
            }
        }
        $thumbnail = $item['_thumbnail'] ?? ($generate && post_type_supports($post_type, 'thumbnail') ? '1200x800' : null);
        if ($thumbnail !== null) {
            if (!is_numeric($thumbnail)) {
                $this->placeholderImages[++$this->fakeId] = (string) $thumbnail;
                $thumbnail = $this->fakeId;
            }
            $meta['_thumbnail_id'] = [(string) $thumbnail];
        }

        wp_cache_set($id, (object) [
            'ID' => $id,
            'post_type' => $post_type,
            'post_status' => 'publish',
            'post_name' => "carlo-story-{$id}",
            'post_title' => (string) ($item['post_title'] ?? ($generate ? ($type->labels->singular_name ?? $post_type) . " {$number}" : '')),
            'post_excerpt' => (string) ($item['post_excerpt'] ?? ''),
            'post_content' => (string) ($item['post_content'] ?? ''),
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', true),
            'filter' => 'raw',
        ], 'posts');
        wp_cache_set($id, $meta, 'post_meta');
        return $id;
    }

    protected function placeholderReferences(array $field): array
    {
        return get_posts([
            'post_type' => $field['post_types'],
            'numberposts' => min($field['max'] ?: 3, 3),
            'fields' => 'ids',
        ]);
    }
}

/**
 * URL du catalogue ou d'un rendu seul.
 */
function carlo_stories_url($args = [])
{
    return add_query_arg(array_map('rawurlencode', $args), home_url('/?carlo-stories'));
}

function carlo_stories_frame($element, $story)
{
    // Driver des exemples, avec les namespaces enregistrés par le thème.
    $driver = new CarloStoriesDriver();
    $driver->placeholders = !$element['stories'];
    foreach (carlo_driver()->getNamespaces() as $namespace => $path) {
        $driver->registerNamespace($namespace, $path);
    }
    $driver->register(get_stylesheet_directory() . '/structure.yml');
    carlo_driver($driver);

    show_admin_bar(false);
    include __DIR__ . '/templates/frame.php';
}

// Accès depuis l'admin : Apparence > Catalogue. Une URL complète en slug sert directement de lien.
add_action('admin_menu', function () {
    add_submenu_page('themes.php', 'Catalogue des composants', 'Catalogue', 'edit_theme_options', carlo_stories_url());
});

add_action('template_redirect', function () {
    if (!isset($_GET['carlo-stories'])) {
        return;
    }
    header('X-Robots-Tag: noindex, nofollow');

    // Seuls les éléments découverts peuvent être rendus : pas de template arbitraire depuis l'URL.
    $element = carlo\Stories::find(wp_unslash($_GET['id'] ?? ''));
    $story_key = wp_unslash($_GET['story'] ?? '');

    if ($_GET['carlo-stories'] === 'frame') {
        if (!$element) {
            status_header(404);
            exit('Élément inconnu.');
        }
        // Sans exemple demandé (ou inconnu) : le premier, comme dans le catalogue.
        carlo_stories_frame($element, $element['stories'][$story_key] ?? reset($element['stories']) ?: []);
    } else {
        include __DIR__ . '/templates/catalogue.php';
    }
    exit;
});
