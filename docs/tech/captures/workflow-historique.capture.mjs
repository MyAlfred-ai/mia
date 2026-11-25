/**
 * L'historique des exécutions, le lancement « Trier les demandes reçues par email » ouvert sur son
 * journal, pour la section « Ce que montre le journal » de
 * docs/user/fonctionnalites/workflows/revoir-l-historique.md → docs/user/images/workflow-historique.png.
 *
 * Les trois lancements, leurs passages et leurs pièces viennent de donnees-fictives.php.
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,
  // Assez haut pour voir, sous l'en-tête du lancement, le début de son déroulé.
  largeur: 1600,
  hauteur: 1200,

  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesdocumentation', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/workflows?vue=historique`);
    await page.locator('a.wf-hist-run', { hasText: 'Trier les demandes' }).click();
    await page.getByText('Voie choisie', { exact: false }).first().waitFor();
  },

  masquer: ['#versionBtn'],
};
