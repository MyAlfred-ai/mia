/**
 * La page Assistants d'une créatrice : la recherche, les filtres, les deux cartes de création, puis
 * les assistants de l'organisation et ses quatre assistants personnels, pour l'en-tête de
 * docs/user/fonctionnalites/assistants/index.md → docs/user/images/assistants.png.
 *
 * Les assistants viennent de donnees-fictives.php.
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,
  largeur: 1440,
  hauteur: 900,

  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesdocumentation', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/admin/profiles`);
    await page.locator('#asxSearch').waitFor();
  },

  masquer: ['#versionBtn'],
};
