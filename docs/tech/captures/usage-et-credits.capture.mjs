/**
 * La page Usage & crédits de Camille Martin, pour la section « Le détail — page « Usage & crédits » »
 * de docs/user/fonctionnalites/credits/suivre-sa-consommation.md → docs/user/images/usage-et-credits.png.
 *
 * Le quota du groupe et la consommation du mois viennent de donnees-fictives.php.
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,

  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesdocumentation', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/settings/usage`);
    await page.getByText('Ce qui a consommé vos crédits', { exact: true }).waitFor();
  },

  masquer: ['#versionBtn'],
};
