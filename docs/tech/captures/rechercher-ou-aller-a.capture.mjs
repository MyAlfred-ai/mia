/**
 * La barre « Rechercher ou aller à… » ouverte sur la recherche « cre », pour la section du même nom
 * de docs/user/fonctionnalites/conversations/gerer-ses-conversations.md
 * → docs/user/images/rechercher-ou-aller-a.png.
 *
 * « cre » trouve des actions, une page de l'espace de travail, une page de réglages et une
 * conversation (celle que donnees-fictives.php titre « Créer le support du salon… ») : les trois
 * groupes de la barre d'un coup.
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,

  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesdocumentation', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/chat/101`);
    await page.locator('#chatMessages table').waitFor();
    // Le bouton du bloc de départ, sous « Nouvelle conversation » (la tuile « Tout » des Espaces a
    // disparu avec le volet repliable).
    await page.getByRole('button', { name: /^Rechercher ou aller à…/ }).first().click();
    await page.keyboard.type('cre');
    await page.locator('.cmdp').getByText('Créer le support du salon de la logistique').waitFor();
  },

  masquer: ['#versionBtn'],
};
