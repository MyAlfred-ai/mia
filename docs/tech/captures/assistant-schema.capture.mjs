/**
 * La page de « Fiches produits », descendue jusqu'à sa vue d'ensemble : le schéma de l'assistant
 * (son modèle, l'assistant et sa consigne, sa base et son outil), pour la section « Lire le schéma
 * d'un assistant » de docs/user/fonctionnalites/assistants/creer-un-assistant.md
 * → docs/user/images/assistant-schema.png.
 *
 * Les quatre assistants personnels de Camille Martin viennent de donnees-fictives.php.
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,
  largeur: 1440,
  hauteur: 900,

  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesdocumentation', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/admin/profiles`);
    // La ligne entière mène à la page de l'assistant.
    await page.locator('.asx-ln', { hasText: 'Fiches produits' }).locator('.asx-ln-link').click();
    const schema = page.locator('#asxShowDiagram');
    await schema.locator('.sd-head').first().waitFor();
    // Le schéma se dessine en plusieurs temps (flèches posées après les nœuds).
    await page.waitForTimeout(1200);
    await page.locator('#asxEnsembleTitle').evaluate((el) => el.scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(400);
  },

  masquer: ['#versionBtn'],
};
