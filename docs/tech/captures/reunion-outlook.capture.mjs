/**
 * Une réunion Outlook préparée par MIA, qui attend l'approbation — invités nommés, salle réservée,
 * réunion Teams, répétition —, pour docs/user/fonctionnalites/connecteurs/organiser-une-reunion.md
 * → docs/user/images/reunion-outlook.png.
 *
 * La demande, la réponse, la pile d'actions et la carte d'approbation sont écrites par
 * donnees-fictives.php (conversation 110) : l'aperçu de la carte est celui que calcule
 * UserOutlookCalendarService, à jour avec l'application.
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,
  // La demande, la réponse et toute la carte, salle comprise : plus haut que l'écran habituel.
  hauteur: 1080,

  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesdocumentation', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/chat/110`);
    const carte = page.locator('.drive-confirm-card');
    await carte.waitFor();
    // Le bas de la carte (Approuver, Annuler) en vue, le haut de la conversation au-dessus.
    await carte.evaluate((el) => el.scrollIntoView({ block: 'end' }));
  },

  masquer: ['#versionBtn'],
};
