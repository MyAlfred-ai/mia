/**
 * L'écran principal de MIA, pour la section « Se repérer dans MIA » de
 * docs/user/premiers-pas.md → docs/user/images/ecran-principal.png.
 *
 * Les numéros suivent l'ordre du tableau de la page : un repère ajouté, retiré ou
 * renuméroté change la ligne du tableau dans le même commit.
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,

  // Une conversation ouverte : l'écran qui montre le plus de MIA. La session de Camille Martin
  // est écrite par donnees-fictives.php ; le cookie la désigne, sans mot de passe.
  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesdocumentation', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/chat/101`);
    await page.locator('#chatMessages table').waitFor();
  },

  // Le numéro de version change à chaque commit : la capture vieillirait avec lui.
  masquer: ['#versionBtn'],

  reperes: [
    // 1. Nouvelle conversation
    { n: 1, ou: 'droite', cible: (p) => p.locator('#newChatBtn') },
    // 2. La liste des conversations
    { n: 2, ou: 'droite', cible: (p) => p.locator('#convList .conv-group-label').first() },
    // 3. Le bandeau du modèle
    { n: 3, ou: 'bas', cible: (p) => p.locator('#toolbarModelLogoWrap') },
    // 4. La réponse
    { n: 4, ou: 'gauche', cible: (p) => p.locator('#chatMessages .msg-content').last() },
    // 5. « + » : fichiers, outils et connecteurs
    { n: 5, ou: 'gauche', cible: (p) => p.getByTitle('Fichiers, outils et connecteurs', { exact: true }) },
    // 6. Les assistants
    { n: 6, ou: 'bas', cible: (p) => p.getByTitle('Démarrer une conversation avec un assistant', { exact: true }) },
    // 7. Les bases de connaissances
    { n: 7, ou: 'droite', cible: (p) => p.locator('#kbPickerBtn') },
    // 8. Le modèle
    { n: 8, ou: 'haut', cible: (p) => p.getByTitle('Choisir le modèle LLM', { exact: true }) },
    // 9. Le compte et les crédits
    { n: 9, ou: 'droite', cible: (p) => p.locator('#userMenuBtn') },
    // 10. La langue
    { n: 10, ou: 'bas', cible: (p) => p.locator('.lang-switcher') },
  ],
};
