# MIA — Application mobile (iOS / Android)

Wrapper **Capacitor** : la WebView native charge directement l'app web déployée
(`server.url` dans `capacitor.config.ts`). **Toute évolution web (déploiement
staging/prod) est donc répercutée instantanément dans l'app, sans re-publication
sur les stores.** Seuls les changements natifs (plugins, permissions, icônes,
config Capacitor) nécessitent un nouveau build.

## Architecture

- `capacitor.config.ts` — config : URL prod/staging, User-Agent marqué
  (`myLLM-App/ios|android`), `CapacitorHttp` désactivé (OBLIGATOIRE : sinon le
  streaming SSE du chat est cassé), `errorPath` vers la page d'erreur locale.
- `www/` — bundle local minimal : splash de secours + page « connexion
  impossible ». En fonctionnement normal, jamais affiché.
- `ios/`, `android/` — projets natifs générés par Capacitor, commités.
- Le pont côté web est `public/assets/js/native-bridge.js` (repo racine),
  servi par le serveur **uniquement** quand la page est demandée par l'app
  (détection User-Agent, `src/Helpers/NativeApp.php`). En mode `server.url`,
  la WebView **injecte elle-même `capacitor.js` et les proxys des plugins**
  dans les pages du domaine autorisé : ne jamais servir capacitor.js depuis
  le serveur web. Détection côté JS : `window.Capacitor?.isNativePlatform?.()`.

## URLs des environnements

L'URL du serveur doit être fournie à la compilation via `MIA_SERVER_URL`.
`MIA_STAGING_URL` est facultative ; sans elle, l'application reste sur le même
serveur et le geste de bascule prod/recette est désactivé.

```bash
MIA_SERVER_URL=https://mia.example.test \
MIA_STAGING_URL=https://mia-staging.example.test \
npm run sync
```

## Prérequis

- Node 20+, puis `npm install` dans `mobile/`.
- **Android** : Android Studio (SDK 36+), JDK 21 (Capacitor 7 compile en Java 21).
- **iOS** : macOS + Xcode 15+, CocoaPods (`sudo gem install cocoapods`).

## Développement

```bash
npm run sync            # recopie www/ + config vers ios/ et android/
npm run run:android     # build + lance sur émulateur/appareil
npm run open:ios        # ouvre Xcode (build/run depuis Xcode)
```

Bascule d'environnement en app (active seulement quand prod ≠ recette) :
**7 taps sur le logo de la page de connexion** → bascule prod/staging
(persistée, badge « STAGING » affiché).

## Google Sign-In natif

Le login Google web est interdit en WebView (`disallowed_useragent`) : l'app
passe par le plugin `@capgo/capacitor-social-login`, puis le serveur vérifie
l'`idToken` (`POST /auth/google-token`) et ouvre la session PHP.

Configuration dans la console Google Cloud (projet OAuth existant) :

| Client OAuth | Usage | Notes |
|---|---|---|
| **Web** (existant, `GOOGLE_CLIENT_ID`) | `serverClientId` Android + audience de vérification | L'`aud` des idTokens **Android** est ce client web |
| **iOS** (à créer) | app iOS | Bundle ID `ai.myalfred.myllm` ; renseigner `GOOGLE_CLIENT_ID_IOS` dans le `.env` serveur |
| **Android** (à créer) | app Android | Package `ai.myalfred.myllm` + empreintes SHA-1 des keystores **debug ET release** ; jamais utilisé comme audience côté PHP |

Pour Google Sign-In sur Android, déclarez dans votre projet OAuth le package
de l'application et les empreintes SHA-1 de vos propres keystores debug et
release. Ne partagez ni ne versionnez les fichiers de keystore ; générez-les
pour votre environnement et gérez leurs mots de passe comme des secrets.

Les client IDs sont publics : ils sont exposés à la page de connexion via
`window.GOOGLE_NATIVE_CLIENT_IDS` (émis par `templates/auth/login.php` quand
la requête vient de l'app).

## Build de distribution

### Installation par lien — page publique `/app`

`https://<serveur>/app` (hors connexion) : page d'installation avec les moyens
de distribution configurés par l'administrateur de l'instance. Les binaires
signés et leur publication sont gérés séparément du dépôt public du code.

### Android (APK interne / Play internal testing)

```bash
# Keystore (une fois) :
keytool -genkey -v -keystore mia-release.keystore -alias mia \
        -keyalg RSA -keysize 2048 -validity 10000

export ANDROID_KEYSTORE_PATH=/chemin/mia-release.keystore
export ANDROID_KEYSTORE_PASSWORD=… ANDROID_KEY_ALIAS=mia ANDROID_KEY_PASSWORD=…
npm run build:android    # → android/app/build/outputs/apk/release/ + bundle/release/
```

Sans variables d'environnement keystore, le build release est non signé.
La signature des builds distribués et leur publication doivent être réalisées
dans un environnement privé dédié ; ne stockez jamais de keystore dans ce dépôt.

### iOS (TestFlight)

Build local Xcode (pas de CI macOS pour l'instant) :

1. `npm run sync:ios` puis `npm run open:ios`.
2. Dans Xcode : cible **App** → Signing & Capabilities → équipe Apple
   Developer ; vérifier le bundle ID `ai.myalfred.myllm`.
3. Ajouter l'URL scheme du client Google iOS (Info → URL Types → l'ID client
   iOS **inversé**, ex. `com.googleusercontent.apps.123-abc`) — requis par le
   plugin Google Sign-In.
4. Product → Archive → Distribute App → TestFlight & App Store → Upload.
5. App Store Connect → TestFlight → ajouter les testeurs internes.

> Publication App Store **publique** (plus tard) : la guideline 4.8 impose
> d'ajouter « Sign in with Apple » dès qu'un login Google est proposé.
> L'endpoint serveur (`/auth/google-token`) est prévu extensible.

## Pièges connus

- **`CapacitorHttp` doit rester désactivé** — il patche `window.fetch` et
  bufferise les réponses : le streaming SSE du chat (fetch +
  `response.body.getReader()`) serait cassé.
- **Ne jamais ajouter `accounts.google.com` à `allowNavigation`** : le login
  Google passe par le plugin natif ; la page Google en WebView serait bloquée.
- Les téléchargements web (`a.download` + blob) ne fonctionnent pas en
  WKWebView iOS : le pont `NativeBridge.saveAndShare` (native-bridge.js)
  prend le relais (feuille de partage native).
- Une page web cassée casse l'app (revers du mode `server.url`) : filets =
  page d'erreur locale (`errorPath`) + bascule staging par geste caché.
