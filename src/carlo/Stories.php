<?php

namespace carlo;

use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Éléments du catalogue des composants (équivalent Storybook) et leurs exemples, sans dépendre de
 * WordPress : le catalogue d'un environnement (cf. src/wp/stories.php) s'appuie dessus.
 *
 * Les éléments sont découverts par l'introspection de la library (carlo_get_available_sections() /
 * carlo_get_available_components()). Les exemples sont rédigés dans <variante>.stories.yml, à côté du
 * template, dans un des dossiers de recherche du driver (thème, plugin, library). Un élément sans
 * exemple est affiché avec un contenu généré d'après ses champs (cf. Placeholders).
 *
 * Chaque élément a une origine, pour filtrer le catalogue : `carlo` (fourni par ce paquet), `project` (écrit
 * par le projet : thème, namespace enregistré) ou `override` (fourni par ce paquet et surchargé par le projet).
 */
class Stories
{
    private static ?array $elements = null;

    /**
     * Éléments du catalogue, groupés (sections, composants) et indexés par id d'introspection.
     */
    public static function elements(): array
    {
        if (self::$elements !== null) {
            return self::$elements;
        }

        self::$elements = [];
        foreach (['sections' => carlo_get_available_sections(true), 'components' => carlo_get_available_components(true)] as $group => $items) {
            foreach ($items as $id => $metadata) {
                [$type, $name, $variant] = carlo_explode_id($id);
                $element = [
                    'id' => $id,
                    'name' => $name,
                    'variant' => $variant,
                    'title' => carlo_section_title($id, $metadata),
                    'fields' => [],
                    'stories' => [],
                    'stories_from_base' => false,
                    'stories_file' => "templates/{$type}/{$name}/{$variant}.stories.yml",
                    'origin' => self::origin($id),
                    'errors' => [],
                ];
                // Une définition invalide ne doit pas empêcher d'afficher le reste du catalogue.
                try {
                    $element['fields'] = Fields::of($metadata);
                } catch (Throwable $e) {
                    $element['errors'][] = "Champs : {$e->getMessage()}";
                }
                try {
                    [$element['stories'], $element['stories_from_base']] = self::load($id);
                } catch (Throwable $e) {
                    $element['errors'][] = "Exemples : {$e->getMessage()}";
                }
                self::$elements[$group][$id] = $element;
            }
        }
        return self::$elements;
    }

    public static function find(string $id): ?array
    {
        foreach (self::elements() as $items) {
            if (isset($items[$id])) {
                return $items[$id];
            }
        }
        return null;
    }

    /**
     * Exemples d'un élément, résolus par getFile() comme la structure et le template : <variante>, puis base.
     * Renvoie les exemples et si ceux-ci sont hérités de base (variante sans .stories.yml propre).
     */
    public static function load(string $id): array
    {
        [$type, $name, $variant, $namespace] = carlo_explode_id($id);
        try {
            $file = carlo_driver()->getFile('stories', "{$type}/{$name}", $variant, $namespace);
        } catch (FileNotFoundException $e) {
            return [[], false];
        }
        $stories = array_filter((array) Yaml::parseFile($file), 'is_array');
        return [$stories, $variant !== 'base' && basename($file) !== "{$variant}.stories.yml"];
    }

    /**
     * Origine d'un élément (cf. la classe). Il appartient au projet dès que son template ou sa structure, tels
     * que le driver les résout, est hors de ce paquet ; il est surchargé si ce paquet en fournit aussi un fichier.
     */
    public static function origin(string $id): string
    {
        [$type, $name, $variant, $namespace] = carlo_explode_id($id);
        $driver = carlo_driver();
        $root = realpath(dirname(__DIR__, 2)) . DIRECTORY_SEPARATOR;
        // realpath : le dossier du paquet peut être un lien symbolique (cf. README-AI, pièges).
        $in_carlo = fn(string $path): bool => str_starts_with((realpath($path) ?: $path) . DIRECTORY_SEPARATOR, $root);

        $project = false;
        foreach (['template', 'structure'] as $role) {
            try {
                $project = $project || !$in_carlo($driver->getFile($role, "{$type}/{$name}", $variant, $namespace));
            } catch (FileNotFoundException $e) {
                // Élément sans .yml (ou sans template) : l'autre fichier décide.
            }
        }
        if (!$project) {
            return 'carlo';
        }

        // Fichiers de cette variante seulement : une variante ajoutée par le projet à un élément du paquet
        // n'en surcharge aucune.
        $files = ["{$type}/{$name}/{$variant}.php", "{$type}/{$name}/{$variant}.yml"];
        if ($variant === 'base') {
            $files[] = "{$type}/{$name}.php";
        }
        foreach (array_filter($driver->getSearchPaths($namespace), $in_carlo) as $dir) {
            foreach ($files as $file) {
                if (file_exists("{$dir}/{$file}")) {
                    return 'override';
                }
            }
        }
        return 'project';
    }

    /**
     * Champs à plat pour le tableau du catalogue : chemin (quote.author, cards.*.title) => champ.
     */
    public static function fieldRows(array $fields, string $path = ''): array
    {
        $rows = [];
        foreach ($fields as $key => $field) {
            $rows[$path . $key] = $field;
            if (isset($field['fields'])) {
                $rows += self::fieldRows($field['fields'], $path . $key . ($field['type'] === 'repeater' ? '.*.' : '.'));
            }
        }
        return $rows;
    }
}
