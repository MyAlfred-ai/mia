/**
 * La page Fournisseurs et modèles de l'administration, pour la section « Configurer » de
 * docs/user/admin/modeles/index.md → docs/user/images/fournisseurs-et-modeles.png.
 *
 * Vue par Antoine Lefèvre, l'administrateur du jeu de données (session `capturesadministration`).
 * Les clés factices, les modèles activés, le défaut et le secours viennent de donnees-fictives.php.
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,
  // Les fournisseurs tiennent sur quatre colonnes : la carte du LLM local passe juste.
  largeur: 1600,
  hauteur: 1040,

  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesadministration', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/admin/llms`);
    await page.getByText('Qui répond par défaut', { exact: true }).waitFor();
  },

  masquer: ['#versionBtn'],
};
