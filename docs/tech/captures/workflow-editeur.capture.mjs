/**
 * L'éditeur d'un workflow, « Trier les demandes reçues par email » et ses quatre voies, pour la
 * section « Régler le workflow dans l'éditeur » de docs/user/fonctionnalites/workflows/creer-un-workflow.md
 * → docs/user/images/workflow-editeur.png.
 *
 * Le workflow est le modèle de MIA du même nom, publié par donnees-fictives.php.
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,
  // Les quatre voies côte à côte, sans que leurs briques se tronquent davantage.
  largeur: 1600,
  hauteur: 900,

  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesdocumentation', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/workflows`);
    // L'identifiant change à chaque lancement du jeu de données : on passe par la liste.
    const lien = page.locator('a[href$="/edit"]', { hasText: 'Trier les demandes' }).first();
    await page.goto(new URL(await lien.getAttribute('href'), ORIGINE).href);
    await page.getByText('Aiguiller la demande', { exact: true }).first().waitFor();
  },

  masquer: ['#versionBtn'],
};
