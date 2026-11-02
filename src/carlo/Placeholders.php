<?php

namespace carlo;

/**
 * Pour un driver du catalogue : une image peut être une dimension (1200x800), rendue par une image de
 * remplacement locale.
 *
 * Avec $placeholders (élément sans exemple), les champs non renseignés reçoivent un contenu généré
 * d'après leur type. Sans, ils restent vides : un exemple qui omet un champ le veut vide.
 *
 * Un champ `reference` d'exemple accepte, outre des identifiants, des contenus qui n'existent pas dans le CMS :
 * un nombre (autant de contenus générés d'après leur type) ou une liste de contenus décrits (cf. storyReferences()).
 *
 * Le driver qui l'utilise garde sa mise en forme (WordPress, démo…) : le trait s'insère devant.
 */
trait Placeholders
{
    public bool $placeholders = false;

    /**
     * Images de remplacement désignées par un identifiant (image d'un contenu fictif) : identifiant => « WxH ».
     */
    protected array $placeholderImages = [];

    protected function loadValue(array $field, mixed $value, LoadContext $ctx): mixed
    {
        if ($this->placeholders && $value === null) {
            $value = $this->placeholder($field);
        } elseif ($field['type'] === 'reference' && $value !== null) {
            $value = $this->storyReferences($field, $value);
        }
        return parent::loadValue($field, $value, $ctx);
    }

    /**
     * Valeur d'exemple d'un champ `reference`, en identifiants : un identifiant reste tel quel ; un nombre N donne N
     * contenus générés (tous leurs champs remplis) ; un tableau décrit un contenu (champs omis vides). Les contenus
     * fictifs sont créés par le driver (fakeReference()), sans toucher aux données du CMS.
     */
    private function storyReferences(array $field, mixed $value): array
    {
        if (is_int($value)) {
            // null : contenu entièrement généré.
            $value = array_fill(0, max(0, $field['max'] ? min($value, $field['max']) : $value), null);
        }
        $ids = [];
        foreach ((array) $value as $item) {
            $ids[] = match (true) {
                $item === null => $this->fakeReference($field, [], true),
                is_array($item) => $this->fakeReference($field, $item, false),
                default => $item,
            };
        }
        return array_values(array_filter($ids));
    }

    /**
     * Contenu fictif d'un champ `reference`, décrit par $item (avec $generate, ses champs vides sont générés) :
     * son identifiant, ou null si le CMS n'en crée pas (par défaut).
     */
    protected function fakeReference(array $field, array $item, bool $generate): int|string|null
    {
        return null;
    }

    // Les éléments obligatoires (min), complétés jusqu'à trois pour voir la mise en page se répéter, dans la limite de max.
    protected function loadRepeater(array $field, mixed $value, LoadContext $ctx): array
    {
        if ($this->placeholders && $value === null) {
            $count = max($field['min'], 3);
            $value = array_fill(0, $field['max'] ? min($count, $field['max']) : $count, []);
        }
        return parent::loadRepeater($field, $value, $ctx);
    }

    /**
     * Contenu existant que peut référencer un champ `reference` (identifiants), selon le CMS : rien par défaut.
     */
    protected function placeholderReferences(array $field): array
    {
        return [];
    }

    /**
     * Contenu généré d'un champ ; null laisse la valeur par défaut du type (booléen, types non
     * éditables, groupes et répéteurs, dont les sous-champs sont générés à leur tour).
     */
    private function placeholder(array $field): mixed
    {
        // Court pour les champs texte (souvent des titres), long pour les champs riches.
        $lorem_short = 'Lorem ipsum dolor sit amet';
        $lorem = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.';

        return match ($field['type']) {
            'text_short' => $field['label'],
            'text' => $lorem_short,
            'wysiwyg' => $lorem,
            'url' => '#',
            'number' => 1,
            'image' => '1200x800',
            'choices' => $field['multi'] ? [(string) array_key_first($field['choices'])] : (string) array_key_first($field['choices']),
            'reference' => $this->placeholderReferences($field),
            default => null,
        };
    }

    public function img(string $key, string $default_size, array $source_sizes, $mobile_key, array $mobile_source_sizes, array $imgAttrs)
    {
        // Un identifiant, une dimension passée telle quelle (valeur d'un argument), sinon le nom d'un champ.
        $value = match (true) {
            is_numeric($key) => $this->placeholderImages[$key] ?? $key,
            (bool) preg_match('/^\d+x\d+$/', $key) => $key,
            default => $this->get($key),
        };
        if (is_string($value) && preg_match('/^(\d+)x(\d+)$/', $value, $size)) {
            [, $width, $height] = $size;
            $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}"><rect width="100%" height="100%" fill="#d6d6d6"/><text x="50%" y="50%" fill="#555" font-family="sans-serif" font-size="32" text-anchor="middle" dominant-baseline="middle">{$width} × {$height}</text></svg>
SVG;
            $attrs = '';
            foreach ($imgAttrs as $name => $attr) {
                $attrs .= ' ' . htmlspecialchars($name, ENT_QUOTES) . '="' . htmlspecialchars((string) $attr, ENT_QUOTES) . '"';
            }
            return '<img src="data:image/svg+xml,' . rawurlencode($svg) . "\" width=\"{$width}\" height=\"{$height}\" alt=\"\"{$attrs}>";
        }
        return parent::img($key, $default_size, $source_sizes, $mobile_key, $mobile_source_sizes, $imgAttrs);
    }
}
