/**
 * Réglages → Comptes connectés, la carte « Claude dans Office » juste après « Générer mon jeton » :
 * le menu, l'adresse, le jeton montré une seule fois, et leurs boutons Copier, pour l'étape 1 du
 * pas-à-pas de docs/user/fonctionnalites/connecteurs/claude-dans-office.md (et du guide que lit
 * l'assistant d'aide) → docs/user/images/office-jeton.png.
 *
 * Claude dans Office est ouvert par jeton personnel dans donnees-fictives.php. Le clic crée un vrai
 * jeton sur la base jetable du banc : il ne vaut nulle part ailleurs. L'adresse de la passerelle
 * serait celle du banc (http://127.0.0.1:8190/office) : on montre celle d'une instance
 * vraisemblable, au domaine réservé example.com — la même que sur les captures de l'add-in
 * (office-addin.mjs).
 */
const ORIGINE = 'http://127.0.0.1:8190';

export default {
  adresse: `${ORIGINE}/`,
  largeur: 1280,
  hauteur: 720,

  async preparer(page) {
    await page.context().addCookies([{ name: 'myLLM_session', value: 'capturesdocumentation', url: ORIGINE }]);
    await page.goto(`${ORIGINE}/settings/connections`);
    const carte = page.locator('#office-addin');
    await carte.waitFor();
    // La capture montre une première fois : le jeton d'une capture précédente est révoqué (la page se
    // recharge), sinon la carte afficherait « Remplacer mon jeton » et une date de création.
    page.on('dialog', (d) => d.accept());
    const revoquer = carte.getByRole('button', { name: 'Révoquer', exact: true });
    if (await revoquer.count()) {
      await Promise.all([page.waitForEvent('load'), revoquer.click()]);
      await carte.getByRole('button', { name: 'Générer mon jeton', exact: true }).waitFor();
    }
    await page.locator('#officeAddinGateway').evaluate((el) => { el.textContent = 'https://mia.example.com/office'; });
    await carte.getByRole('button', { name: 'Générer mon jeton', exact: true }).click();
    await page.locator('#officeAddinNewToken').waitFor({ state: 'visible' });
  },

  masquer: ['#versionBtn'],

  // Dans l'ordre de l'étape 1 de la page.
  reperes: [
    { n: 1, ou: 'droite', cible: (page) => page.getByRole('link', { name: 'Comptes connectés', exact: true }) },
    { n: 2, ou: 'gauche', cible: (page) => page.locator('#office-addin').getByRole('button', { name: 'Générer mon jeton', exact: true }) },
    { n: 3, ou: 'droite', cible: (page) => page.locator('#office-addin button[data-copy-target="officeAddinGateway"]') },
    { n: 4, ou: 'droite', cible: (page) => page.locator('#office-addin button[data-copy-target="officeAddinTokenValue"]') },
  ],
};
