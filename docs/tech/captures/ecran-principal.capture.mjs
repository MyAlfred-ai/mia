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
    // Le bandeau est replié par défaut : on le déplie, il montre alors ce que sait faire le modèle.
    await page.locator('#toolbarCollapseBtn').click();
    await page.locator('#toolbarMetaBar').waitFor();
  },

  // Le numéro de version change à chaque commit : la capture vieillirait avec lui.
  masquer: ['#versionBtn'],

  reperes: [
    // 1. Nouvelle conversation
    { n: 1, ou: 'droite', cible: (p) => p.locator('#newChatBtn') },
    // 2. Les tuiles Espaces
    { n: 2, ou: 'droite', cible: (p) => p.locator('.sidebar-spaces-grid') },
    // 3. La liste des conversations
    { n: 3, ou: 'droite', cible: (p) => p.locator('#convList .conv-group-label').first() },
    // 4. Le bandeau du modèle
    { n: 4, ou: 'droite', cible: (p) => p.locator('#toolbarMetaBar .toolbar-meta-history') },
    // 5. La réponse
    { n: 5, ou: 'gauche', cible: (p) => p.locator('#chatMessages .msg-content').last() },
    // 6. « + » : fichiers, outils et connecteurs
    { n: 6, ou: 'gauche', cible: (p) => p.getByTitle('Fichiers, outils et connecteurs', { exact: true }) },
    // 7. Les assistants
    { n: 7, ou: 'bas', cible: (p) => p.getByTitle('Démarrer une conversation avec un assistant', { exact: true }) },
    // 8. Les bases de connaissances
    { n: 8, ou: 'droite', cible: (p) => p.locator('#kbPickerBtn') },
    // 9. Le modèle
    { n: 9, ou: 'haut', cible: (p) => p.getByTitle('Choisir le modèle LLM', { exact: true }) },
    // 10. La dictée et la conversation vocale
    { n: 10, ou: 'haut', cible: (p) => p.getByTitle('Dicter le message', { exact: true }) },
    // 11. Le compte et les crédits
    { n: 11, ou: 'droite', cible: (p) => p.locator('#userAccountCard') },
    // 12. La langue
    { n: 12, ou: 'bas', cible: (p) => p.locator('.lang-switcher') },
  ],
};
