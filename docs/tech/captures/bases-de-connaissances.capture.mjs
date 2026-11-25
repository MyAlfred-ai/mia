/**
 * La liste des bases de connaissances : la recherche en tête, les cartes de création, puis les bases
 * avec leur source, leur état et l'icône de leur visibilité (privée, commune, publique), pour l'en-tête
 * de docs/user/fonctionnalites/bases-de-connaissances/index.md et l'étape 1 de
 * creer-une-base-de-connaissances.md → docs/user/images/bases-de-connaissances.png.
 *
 * Les quatre bases, leurs documents et leurs extraits viennent de donnees-fictives.php ; Qdrant,
 * vide mais en service, de lancer.sh.
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,
  largeur: 1440,
  hauteur: 900,

  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesdocumentation', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/admin/knowledge-bases`);
    await page.getByText('Catalogue produits 2026', { exact: false }).first().waitFor();
  },

  masquer: ['#versionBtn'],
};
