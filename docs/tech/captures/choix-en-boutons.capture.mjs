/**
 * Une réponse qui se termine par des choix en boutons, pour la section « Répondre à un choix
 * proposé » de docs/user/fonctionnalites/conversations/mener-une-conversation.md
 * → docs/user/images/choix-en-boutons.png.
 *
 * La réponse et son bloc ```choix sont écrits par donnees-fictives.php (conversation 108).
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,
  // La conversation tient en haut de l'écran : pas besoin des 800 pixels habituels.
  hauteur: 700,

  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesdocumentation', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/chat/108`);
    await page.getByRole('button', { name: /Autre réponse/ }).waitFor();
  },

  masquer: ['#versionBtn'],
};
