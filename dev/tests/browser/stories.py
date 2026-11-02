"""Scénario de test du catalogue des composants (/?carlo-stories), en visiteur non connecté.

Usage : lando browser tests/browser/stories.py
Chaque exemple de chaque élément est rendu et vérifié (pas d'erreur de rendu ni d'erreur JS) ;
les captures sont dans tests/browser/shots/.
"""
import sys
from urllib.parse import parse_qs, urlparse

from wp import SITE, public_page, shot

CATALOGUE = f"{SITE}/?carlo-stories"
failures = []


def check(label, ok, detail=None):
    print(("✓ " if ok else "✗ ") + label + (f" : {detail}" if detail is not None and not ok else ""))
    if not ok:
        failures.append(label)


def real_errors(page):
    return [e for e in page.errors if "404" not in e]


with public_page() as page:
    # Toute ressource en échec compte, sauf les polices du thème (problème connu du serveur Vite).
    failed = []
    page.on("response", lambda r: r.status >= 400 and "woff" not in r.url and "wp-config" not in r.url and failed.append(f"{r.status} {r.url}"))
    page.goto(CATALOGUE, wait_until="domcontentloaded")
    links = page.locator("nav[aria-label='Composants'] a").evaluate_all("as => as.map(a => a.href)")
    check("le catalogue liste les éléments découverts", len(links) > 0, len(links))
    check("sections et composants présents", page.locator(".sidebar h2").count() == 2)
    shot(page, "stories-accueil")

    # Filtre : seuls les éléments correspondants restent visibles.
    page.fill("#filtre", "quote")
    visible = page.locator("nav[aria-label='Composants'] [data-search]:visible").all_text_contents()
    check("le filtre réduit la liste", 0 < len(visible) < len(links) and all("quote" in v for v in visible), visible)
    page.fill("#filtre", "")

    # Thème seulement : plus aucun élément de Carlo, les compteurs suivent, le choix est mémorisé.
    page.check("#theme-seulement")
    origins = page.locator("nav[aria-label='Composants'] a[data-origin]:visible").evaluate_all("as => as.map(a => a.dataset.origin)")
    counted = sum(int(c) for c in page.locator("[data-count]").all_text_contents())
    check("Thème seulement : éléments du thème uniquement", 0 < len(origins) < len(links) and "carlo" not in origins, origins)
    check("Thème seulement : compteurs à jour", counted == len(origins), (counted, len(origins)))
    page.reload(wait_until="domcontentloaded")
    check("Thème seulement : mémorisé", page.is_checked("#theme-seulement"))
    page.uncheck("#theme-seulement")

    documented, missing = 0, 0
    for url in links:
        element = parse_qs(urlparse(url).query)["id"][0]
        page.goto(url, wait_until="domcontentloaded")
        stories = page.locator("nav.stories a").evaluate_all("as => as.map(a => a.href)")
        if not stories:
            missing += 1
            check(f"{element} : « aucun exemple » signalé", page.locator(".notice", has_text="Aucun exemple").count() == 1)
            continue
        documented += 1
        for story_url in stories:
            story = parse_qs(urlparse(story_url).query)["story"][0]
            page.goto(story_url, wait_until="load")
            frame = page.frame_locator("#apercu")
            errors = frame.locator("pre", has_text="Erreur de rendu").count()
            html = frame.locator("main").inner_html()
            check(f"{element} / {story} : rendu sans erreur",
                  errors == 0 and "Warning" not in html and "Fatal" not in html and html.strip() != "",
                  frame.locator("main").inner_text()[:200])
            shot(page, f"stories-{element.replace('/', '-').replace(':', '-')}-{story}", full_page=True)

    check("des éléments documentés et d'autres non", documented > 0 and missing > 0, (documented, missing))

    # Largeur de l'aperçu (sur le dernier exemple ouvert).
    page.goto(f"{CATALOGUE}&id=sections%2Fquote", wait_until="load")
    page.get_by_role("button", name="Mobile").click()
    page.wait_for_timeout(500)
    width = page.locator("#apercu").evaluate("f => f.getBoundingClientRect().width")
    check("le bouton Mobile passe l'aperçu à 375 px", round(width) == 375, width)
    check("bouton Mobile signalé comme actif", page.get_by_role("button", name="Mobile").get_attribute("aria-pressed") == "true")
    shot(page, "stories-mobile", full_page=True)
    page.get_by_role("button", name="Bureau").click()

    response = page.goto(f"{SITE}/?carlo-stories=frame&id=..%2F..%2Fwp-config", wait_until="domcontentloaded")
    check("élément inconnu refusé (404)", response.status == 404, response.status)

    check("aucune erreur JS", not real_errors(page), real_errors(page))
    check("aucune ressource en échec (hors polices)", not failed, failed)

print(f"\n{len(failures)} échec(s)" if failures else "\nTout est vert.")
sys.exit(1 if failures else 0)
