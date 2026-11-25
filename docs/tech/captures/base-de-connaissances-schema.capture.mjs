/**
 * La page d'une base, « Catalogue produits 2026 », descendue jusqu'à sa vue d'ensemble : le schéma
 * de la base (ce qui l'alimente, comment elle est cherchée, la base, qui s'en sert et qui la voit),
 * pour la section « Lire le schéma de la base » de
 * docs/user/fonctionnalites/bases-de-connaissances/creer-une-base-de-connaissances.md
 * → docs/user/images/base-de-connaissances-schema.png.
 *
 * Données : donnees-fictives.php ; Qdrant, vide mais en service : lancer.sh.
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,
  largeur: 1440,
  hauteur: 900,

  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesdocumentation', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/admin/knowledge-bases`);
    await page.getByRole('link', { name: /Catalogue produits 2026/ }).first().click();
    const schema = page.locator('#kbShowDiagram');
    await schema.locator('.sd-head').first().waitFor();
    // Le schéma se dessine en plusieurs temps (flèches posées après les nœuds).
    await page.waitForTimeout(1200);
    await page.locator('#kbEnsembleTitle').evaluate((el) => el.scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(400);
  },

  masquer: ['#versionBtn'],
};
