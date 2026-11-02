<?php
/**
 * Catalogue : navigation des sections et composants, aperçu d'un exemple dans une iframe, champs.
 *
 * @var array|null $element   élément sélectionné (cf. carlo\Stories::elements)
 * @var string     $story_key exemple demandé
 */

$groups = ['sections' => 'Sections', 'components' => 'Composants'];
$elements = carlo\Stories::elements();
$all = array_merge(...array_values($elements));
$documented = count(array_filter($all, fn($item) => $item['stories']));

if ($element && $element['stories'] && !isset($element['stories'][$story_key])) {
    $story_key = array_key_first($element['stories']);
}
$story_label = fn($key, $story) => $story['_label'] ?? ucfirst(str_replace('_', ' ', $key));
// Badge d'origine dans la navigation : seule la surcharge est signalée, le filtre « Thème seulement » fait le reste.
$origin_badge = fn($item) => $item['origin'] === 'override' ? ' <span class="badge">surchargé</span>' : '';
$origin_label = ['carlo' => 'Carlo', 'project' => 'Thème', 'override' => 'Carlo, surchargé par le thème'];
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= esc_html(($element ? "{$element['title']} · " : '') . 'Catalogue · ' . get_bloginfo('name')) ?></title>
  <link rel="stylesheet" href="<?= esc_url(plugins_url('assets/catalogue.css', dirname(__DIR__, 3) . '/wp.php')) ?>">
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>

  <aside class="sidebar">
    <p class="sidebar__title"><a href="<?= esc_url(carlo_stories_url()) ?>">Catalogue</a> <span><?= esc_html(get_bloginfo('name')) ?></span></p>
    <label class="search">
      <span class="visually-hidden">Filtrer les composants</span>
      <input type="search" id="filtre" placeholder="Filtrer…" autocomplete="off">
    </label>
    <label class="toggle">
      <input type="checkbox" id="theme-seulement">
      Thème seulement <span>(avec les surcharges)</span>
    </label>
    <nav aria-label="Composants">
      <?php foreach ($groups as $group => $label): ?>
        <h2><?= esc_html($label) ?> <span class="count" data-count="<?= esc_attr($group) ?>"><?= count($elements[$group] ?? []) ?></span></h2>
        <ul data-group="<?= esc_attr($group) ?>">
          <?php
          $by_name = [];
          foreach ($elements[$group] ?? [] as $item) {
              $by_name[$item['name']][] = $item;
          }
          foreach ($by_name as $name => $variants):
              $search = strtolower($name . ' ' . implode(' ', array_column($variants, 'title')) . ' ' . implode(' ', array_column($variants, 'variant')));
          ?>
            <li data-search="<?= esc_attr($search) ?>">
              <?php if (count($variants) === 1 && $variants[0]['variant'] === 'base'): ?>
                <?php $item = $variants[0]; ?>
                <a href="<?= esc_url(carlo_stories_url(['id' => $item['id']])) ?>" data-origin="<?= esc_attr($item['origin']) ?>" <?= $element && $element['id'] === $item['id'] ? 'aria-current="page"' : '' ?>>
                  <?= esc_html($name) ?><?= $origin_badge($item) ?><?php if (!$item['stories']): ?> <span class="badge">aucun exemple</span><?php endif; ?>
                </a>
              <?php else: ?>
                <span class="nav__name"><?= esc_html($name) ?></span>
                <ul>
                  <?php foreach ($variants as $item): ?>
                    <li>
                      <a href="<?= esc_url(carlo_stories_url(['id' => $item['id']])) ?>" data-origin="<?= esc_attr($item['origin']) ?>" <?= $element && $element['id'] === $item['id'] ? 'aria-current="page"' : '' ?>>
                        <?= esc_html($item['variant']) ?><?php if ($item['stories_from_base']): ?> (base)<?php endif; ?><?= $origin_badge($item) ?><?php if (!$item['stories']): ?> <span class="badge">aucun exemple</span><?php endif; ?>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    </nav>
  </aside>

  <main id="contenu" class="main">
    <?php if (!$element): ?>
      <h1>Catalogue des composants</h1>
      <p class="lead">
        <?= count($elements['sections'] ?? []) ?> sections et <?= count($elements['components'] ?? []) ?> composants,
        dont <strong><?= $documented ?> avec des exemples</strong>.
      </p>
      <p>Choisissez un élément dans la liste. Les composants marqués « aucun exemple » restent à documenter.</p>
      <h2>Ajouter des exemples</h2>
      <p>
        Créez un fichier <code>&lt;variante&gt;.stories.yml</code> à côté du template, par exemple
        <code>templates/sections/quote/base.stories.yml</code> dans le thème. Chaque clé est un exemple ;
        ses valeurs ont le même format que les attributs du bloc. Une image peut être une dimension
        (<code>image: 1200x800</code>).
      </p>
    <?php else: ?>
      <header class="main__header">
        <h1><?= esc_html($element['title']) ?></h1>
        <code><?= esc_html($element['id']) ?></code>
        <span class="badge"><?= esc_html($origin_label[$element['origin']]) ?></span>
      </header>

      <?php foreach ($element['errors'] as $error): ?>
        <p class="notice notice--error"><?= esc_html($error) ?></p>
      <?php endforeach; ?>

      <?php $frame_url = carlo_stories_url(['carlo-stories' => 'frame', 'id' => $element['id'], 'story' => $story_key]); ?>
      <?php if ($element['stories_from_base']): ?>
        <p class="notice">
          Exemples hérités de la variante base. Pour des exemples propres à cette variante, créez
          <code><?= esc_html($element['stories_file']) ?></code>.
        </p>
      <?php elseif (!$element['stories']): ?>
        <p class="notice">
          Aucun exemple : l'aperçu est un contenu généré d'après les champs. Pour des exemples réalistes,
          créez <code><?= esc_html($element['stories_file']) ?></code> dans le thème (ou dans la library pour un
          composant partagé).
        </p>
      <?php endif; ?>
      <?php if ($element['stories']): ?>
        <nav class="stories" aria-label="Exemples">
          <ul>
            <?php foreach ($element['stories'] as $key => $story): ?>
              <li>
                <a href="<?= esc_url(carlo_stories_url(['id' => $element['id'], 'story' => $key])) ?>" <?= $key === $story_key ? 'aria-current="page"' : '' ?>>
                  <?= esc_html($story_label($key, $story)) ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </nav>
      <?php endif; ?>

      <div class="toolbar">
        <div class="viewports" role="group" aria-label="Largeur de l'aperçu">
          <button type="button" data-viewport="375px" aria-pressed="false">Mobile</button>
          <button type="button" data-viewport="768px" aria-pressed="false">Tablette</button>
          <button type="button" data-viewport="100%" aria-pressed="true">Bureau</button>
        </div>
        <a href="<?= esc_url($frame_url) ?>" target="_blank" rel="noopener">Ouvrir seul<span class="visually-hidden"> (nouvel onglet)</span></a>
      </div>

      <div class="frame">
        <iframe id="apercu" src="<?= esc_url($frame_url) ?>"
                title="<?= esc_attr("Aperçu : {$element['title']}, " . ($element['stories'] ? $story_label($story_key, $element['stories'][$story_key]) : 'contenu généré')) ?>"></iframe>
      </div>

      <h2>Champs</h2>
      <?php $rows = carlo\Stories::fieldRows($element['fields']); ?>
      <?php if ($rows): ?>
        <div class="table">
          <table>
            <thead><tr><th scope="col">Champ</th><th scope="col">Libellé</th><th scope="col">Type</th><th scope="col">Aide</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $path => $field): ?>
                <tr>
                  <td><code><?= esc_html($path) ?></code></td>
                  <td><?= esc_html($field['label']) ?></td>
                  <td><?= esc_html($field['type']) ?><?= isset($field['items']) ? ' : ' . esc_html(implode(', ', $field['items'])) : '' ?></td>
                  <td><?= esc_html($field['help'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p>Aucun champ : cet élément s'affiche sans contenu à saisir.</p>
      <?php endif; ?>
    <?php endif; ?>
  </main>

  <script>
    // Filtres de la navigation : texte, et origine (« Thème seulement », mémorisé pour ce navigateur).
    // Un nom reste affiché s'il garde au moins une variante ; les compteurs suivent.
    const search = document.getElementById('filtre');
    const themeOnly = document.getElementById('theme-seulement');
    const filter = () => {
      const query = search.value.trim().toLowerCase();
      document.querySelectorAll('[data-search]').forEach((item) => {
        let kept = 0;
        item.querySelectorAll('a[data-origin]').forEach((link) => {
          const keep = !themeOnly.checked || link.dataset.origin !== 'carlo';
          const variant = link.closest('li');
          if (variant !== item) variant.hidden = !keep;
          kept += keep;
        });
        item.hidden = kept === 0 || (query !== '' && !item.dataset.search.includes(query));
      });
      document.querySelectorAll('[data-count]').forEach((count) => {
        const links = document.querySelectorAll(`[data-group="${count.dataset.count}"] a[data-origin]`);
        count.textContent = [...links].filter((link) => !link.closest('[hidden]')).length;
      });
    };
    try { themeOnly.checked = localStorage.getItem('carlo-stories-theme-only') === '1'; } catch (e) {}
    search.addEventListener('input', filter);
    themeOnly.addEventListener('change', () => {
      try { localStorage.setItem('carlo-stories-theme-only', themeOnly.checked ? '1' : ''); } catch (e) {}
      filter();
    });
    filter();

    const frame = document.getElementById('apercu');
    if (frame) {
      // Largeur de l'aperçu, mémorisée pour ce navigateur.
      const buttons = document.querySelectorAll('[data-viewport]');
      const setViewport = (width) => {
        frame.style.width = width;
        buttons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.viewport === width)));
        try { localStorage.setItem('carlo-stories-viewport', width); } catch (e) {}
      };
      buttons.forEach((button) => button.addEventListener('click', () => setViewport(button.dataset.viewport)));
      try {
        const saved = localStorage.getItem('carlo-stories-viewport');
        if (saved && [...buttons].some((button) => button.dataset.viewport === saved)) setViewport(saved);
      } catch (e) {}

      // Hauteur ajustée au contenu : pas de double barre de défilement.
      // Hauteur remise à zéro avant la mesure : sinon scrollHeight ne descend jamais sous la hauteur
      // actuelle et l'aperçu ne rétrécit plus (passage de mobile à bureau).
      const fit = () => {
        frame.style.height = '0px';
        frame.style.height = frame.contentDocument.documentElement.scrollHeight + 'px';
      };
      frame.addEventListener('load', () => {
        fit();
        new ResizeObserver(fit).observe(frame.contentDocument.body);
      });
    }
  </script>
</body>
</html>
