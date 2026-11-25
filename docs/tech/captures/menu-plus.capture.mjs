/**
 * Le menu « + » ouvert, volet Connecteurs déplié et Gmail allumé, pour
 * docs/user/fonctionnalites/connecteurs/utiliser-un-connecteur.md → docs/user/images/menu-plus.png.
 *
 * Les outils et les comptes Google reliés viennent de donnees-fictives.php ; le client Google
 * factice, de lancer.sh.
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,

  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesdocumentation', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/chat/101`);
    await page.locator('#chatMessages table').waitFor();
    await page.locator('#plusMenuBtn').click();
    await page.locator('#plusMenuFoldBtn').click();
    await page.locator('#plusMenuGmailRow .ios-switch').click();
    // Volet déplié, le menu dépasse sa hauteur et défile jusqu'en bas : on le remonte, pour
    // montrer « Joindre des fichiers » en tête.
    await page.locator('#plusMenuPanel').evaluate((panneau) => { panneau.scrollTop = 0; });
  },

  masquer: ['#versionBtn'],
};
