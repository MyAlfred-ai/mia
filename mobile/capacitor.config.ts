import type { CapacitorConfig } from '@capacitor/cli';

/**
 * Configuration de l'app mobile MIA (wrapper Capacitor).
 *
 * Mode `server.url` distant : la WebView charge directement l'app web
 * déployée. Chaque déploiement web (staging/prod) est donc répercuté
 * instantanément dans l'app, sans re-publication sur les stores.
 *
 * L'URL de production est obligatoire à la compilation. L'URL de recette,
 * facultative, est autorisée pour la bascule d'environnement.
 */
const URL_PROD = process.env.MIA_SERVER_URL;
if (!URL_PROD) {
  throw new Error('MIA_SERVER_URL doit être renseignée pour compiler l’application mobile.');
}

const URL_STAGING = process.env.MIA_STAGING_URL || URL_PROD;

const config: CapacitorConfig = {
  appId: 'ai.myalfred.myllm',
  appName: 'MIA',
  webDir: 'www',
  server: {
    url: URL_PROD,
    // Staging autorisé pour la bascule via geste caché (cf. native-bridge.js).
    // NE JAMAIS ajouter accounts.google.com ici : le login Google passe par
    // le plugin natif, et la page Google serait bloquée en WebView.
    allowNavigation: [new URL(URL_PROD).hostname, new URL(URL_STAGING).hostname]
        .filter((host, i, hosts) => hosts.indexOf(host) === i),
    // Page locale affichée si le serveur est injoignable au chargement.
    errorPath: 'error.html',
    cleartext: false,
  },
  ios: {
    // Marqueur User-Agent : le serveur détecte l'app (NativeApp.php) et
    // charge le pont natif. Ne pas renommer sans mettre à jour NativeApp.php.
    appendUserAgent: 'myLLM-App/ios',
    contentInset: 'never',
  },
  android: {
    appendUserAgent: 'myLLM-App/android',
    allowMixedContent: false,
  },
  plugins: {
    // EXPLICITE : ne jamais activer. CapacitorHttp patche window.fetch et
    // bufferise les réponses → casserait le streaming SSE du chat
    // (fetch + response.body.getReader() dans chat.js).
    CapacitorHttp: { enabled: false },
    SplashScreen: {
      launchAutoHide: true,
      launchShowDuration: 800,
      backgroundColor: '#212121',
    },
  },
};

export default config;
