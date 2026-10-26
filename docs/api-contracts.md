# myLLM — API Contracts

Toutes les routes HTTP exposées par l'application, extraites de `public/index.php`.

**Base URL** : `https://<domain>` (prod) ou `http://localhost` (dev)

**Authentification** : Cookie session `myLLM_session` (sauf `/setup` et `/auth/*`)

**CSRF** : tout POST exige le token via :
- Champ form `csrf_token` (formulaires HTML), OU
- Header HTTP `X-CSRF-Token` (requêtes AJAX/fetch)

**Réponses JSON vs HTML** : `AuthMiddleware::wantsJson()` détecte SSE/AJAX/fetch et adapte le format des erreurs (401 JSON vs redirect HTML).

---

## 1. Auth

| Méthode | Route | Handler | Description |
|---|---|---|---|
| GET | `/auth/login` | `AuthController::login` | Page de connexion (formulaire local + bouton Google) |
| POST | `/auth/login` | `AuthController::localLogin` | Login email/mot de passe |
| GET | `/auth/callback` | `AuthController::callback` | Callback OAuth Google |
| GET | `/auth/logout` | `AuthController::logout` | Déconnexion + destruction session |
| GET | `/auth/session-status` | `AuthController::sessionStatus` | Ping AJAX : statut session (pour détection expiration) |
| GET | `/auth/csrf` | `AuthController::csrf` | Jeton CSRF frais (`{"token":"..."}`) — rejeu d'une requête rejetée en 403 |
| POST | `/auth/google-token` | `AuthController::googleTokenLogin` | App mobile : connexion par jeton Google natif (`{"id_token":"..."}`) |

---

## 2. Setup (premier démarrage)

| Méthode | Route | Handler | Description |
|---|---|---|---|
| GET | `/setup` | `SetupController::index` | Wizard `.env` (formulaire) |
| POST | `/setup` | `SetupController::save` | Génère `.env` + secrets crypto random |

**Activation** : si `.env` absent OU `APP_SECRET` vide → toutes les requêtes sont redirigées sur `/setup` jusqu'à finalisation. CSRF désactivé sur cette route uniquement.

---

## 3. Chat

| Méthode | Route | Handler | Description |
|---|---|---|---|
| GET | `/` | `ChatController::index` | Vue chat principale (liste conversations + selecteur LLM/profil) |
| GET | `/a-propos` | `AboutController::index` | Page À propos |
| GET | `/chat/{id}` | `ChatController::show` | Affiche conversation `id` (404 si pas owner) |
| POST | `/chat/new` | `ChatController::create` | Crée conversation, redirige vers `/chat/{id}` |
| POST | `/chat/{id}/send` | `ChatController::send` | **Stream SSE** : envoi message + réponse LLM streamée |
| POST | `/chat/{id}/regenerate` | `ChatController::regenerate` | Re-génère la dernière réponse |
| POST | `/chat/{id}/knowledge-bases` | `ChatController::updateKnowledgeBases` | Bases de connaissances cochées dans le composer (`kb_ids[]`, liste entière ; réponse `{ok, kb_ids, rejected, capped, max}` — `kb_ids` fait foi) |
| POST | `/chat/{id}/upload` | `ChatController::upload` | Upload fichier dans le chat (max 5 fichiers, 10 Mo) |
| POST | `/chat/{id}/imagine` | `ChatController::imagine` | Génération d'image (DALL-E/Imagen/GPT Image) |
| POST | `/chat/{id}/generate-html` | `ChatController::generateHtml` | Génération HTML (preview navigable) |
| POST | `/chat/{id}/generate-video` | `ChatController::generateVideo` | Génération vidéo (Sora) |
| GET | `/chat/message/{id}/html` | `ChatController::getMessageHtml` | Récupère HTML généré pour un message |
| GET | `/chat/message/{id}/video` | `ChatController::getMessageVideo` | Récupère vidéo générée |
| POST | `/chat/message/{id}/pdf` | `ChatController::exportMessagePdf` | Export message → PDF (Dompdf) |
| POST | `/chat/{id}/delete` | `ChatController::delete` | Supprime la conversation |
| GET | `/chat/{id}/json` | `ChatController::loadJson` | Charge la conversation en JSON (rechargement live) ; porte aussi `knowledge_bases: {selected[], profile[{id,name}]}` |

### Format `/chat/{id}/send`

**Request** (POST form-data avec `X-CSRF-Token`) :
```
message=...
llm=openai::gpt-5.5
profile_id=12          # optionnel
web_search=1           # optionnel
```

**Response** : `Content-Type: text/event-stream`
```
data: {"chunk":"Hel"}\n\n
data: {"chunk":"lo"}\n\n
data: {"done":true,"tokens_in":123,"tokens_out":45,"credits":7}\n\n
```

---

## 4. Settings utilisateur (`/settings`)

| Méthode | Route | Handler | Description |
|---|---|---|---|
| GET | `/settings` | `UserSettingsController::profile` | Page profil (nom, email, avatar, mot de passe) |
| GET | `/settings/ai` | `UserSettingsController::aiPreferences` | Préférences IA (style, contexte personnel) |
| GET | `/settings/usage` | `UserSettingsController::usage` | Usage mensuel + breakdown |
| POST | `/settings/profile` | `UserSettingsController::updateProfile` | Met à jour nom + avatar |
| POST | `/settings/ai` | `UserSettingsController::updateAiPreferences` | Met à jour `ai_style`, `ai_user_context` |
| POST | `/settings/password` | `UserSettingsController::updatePassword` | Change mot de passe (vérifie l'ancien) |

---

## 5. Profils (lecture publique)

| Méthode | Route | Handler | Description |
|---|---|---|---|
| GET | `/profiles` | `ProfileController::index` | Liste profils accessibles à l'utilisateur courant |

---

## 6. Administration (`/admin`) — `requireAdmin`

### Index & Settings

| Méthode | Route | Handler | Description |
|---|---|---|---|
| GET | `/admin` | `AdminController::index` | Dashboard admin |
| GET | `/admin/settings` | `AdminController::settings` | Paramètres globaux (nom, logos, thème, accent, LLM par défaut) |
| POST | `/admin/settings` | `AdminController::saveSettings` | Sauvegarde paramètres |

### Utilisateurs & Groupes

| Méthode | Route | Handler |
|---|---|---|
| GET | `/admin/users` | `AdminController::users` |
| POST | `/admin/users/new` | `AdminController::createUser` |
| POST | `/admin/users/{id}` | `AdminController::updateUser` |
| POST | `/admin/users/{id}/delete` | `AdminController::deleteUser` |
| POST | `/admin/users/{id}/password` | `AdminController::resetPassword` |
| GET | `/admin/user-groups` | `AdminController::userGroups` |
| POST | `/admin/user-groups` | `AdminController::createUserGroup` |
| POST | `/admin/user-groups/{id}` | `AdminController::updateUserGroup` |
| POST | `/admin/user-groups/{id}/delete` | `AdminController::deleteUserGroup` |
| POST | `/admin/user-groups/{id}/members` | `AdminController::addUserToGroup` |
| POST | `/admin/user-groups/{id}/members/{userId}/delete` | `AdminController::removeUserFromGroup` |

### Profils (assistants)

| Méthode | Route | Handler |
|---|---|---|
| GET | `/admin/profiles` | `AdminController::profiles` |
| POST | `/admin/profiles` | `AdminController::createProfile` |
| POST | `/admin/profiles/avatar/upload` | `AdminController::uploadProfileAvatar` |
| POST | `/admin/profiles/avatar/delete` | `AdminController::deleteProfileAvatar` |
| POST | `/admin/profiles/{id}` | `AdminController::updateProfile` |
| POST | `/admin/profiles/{id}/delete` | `AdminController::deleteProfile` |
| POST | `/admin/profiles/{id}/documents` | `AdminController::uploadProfileDocument` |
| POST | `/admin/profiles/{id}/documents/{docId}/delete` | `AdminController::deleteProfileDocument` |

### Modèles IA (page fusionnée : clés, instances, défaut/secours, tests)

| Méthode | Route | Handler |
|---|---|---|
| GET | `/admin/llms` | `AdminController::llms` |
| POST | `/admin/llms/defaults` | `AdminController::saveLlmDefaults` |
| POST | `/admin/api-keys` | `AdminController::saveApiKey` |
| POST | `/admin/api-keys/{id}/delete` | `AdminController::deleteApiKey` |
| POST | `/admin/api-keys/{id}/test` | `AdminController::testApiKey` |
| POST | `/admin/llm-models` | `AdminController::saveLlmModel` |
| POST | `/admin/llm-models/{id}/delete` | `AdminController::deleteLlmModel` |
| POST | `/admin/tests/run` | `AdminController::runTest` |

Anciennes pages `GET /admin/api-keys` et `GET /admin/tests` → **301** vers `/admin/llms` (closures dans `public/index.php`).

### Bases de connaissances (RAG vectoriel partagé)

| Méthode | Route | Handler |
|---|---|---|
| GET | `/admin/knowledge-bases` | `AdminController::knowledgeBases` |
| POST | `/admin/knowledge-bases` | `AdminController::createKnowledgeBase` |
| POST | `/admin/knowledge-bases/{id}` | `AdminController::updateKnowledgeBase` |
| POST | `/admin/knowledge-bases/{id}/delete` | `AdminController::deleteKnowledgeBase` |
| POST | `/admin/knowledge-bases/{id}/reindex` | `AdminController::reindexKnowledgeBase` |
| POST | `/admin/knowledge-bases/{id}/documents` | `AdminController::uploadKnowledgeBaseDocument` |
| POST | `/admin/knowledge-bases/{id}/documents/{docId}/delete` | `AdminController::deleteKnowledgeBaseDocument` |
| GET | `/admin/knowledge-bases/{id}/documents/{docId}/status` | `AdminController::knowledgeBaseDocumentStatus` |
| GET | `/admin/knowledge-bases/sync` | `AdminController::knowledgeBaseSyncSettings` (identifiants globaux Drive / SharePoint / Agenda) |
| POST | `/admin/knowledge-bases/sync/settings` | `AdminController::saveKnowledgeBaseSyncSettings` |
| POST | `/admin/knowledge-bases/sync/test-credentials` | `AdminController::testSyncConnection` |

### Usage & Crédits

| Méthode | Route | Handler |
|---|---|---|
| GET | `/admin/usage` | `AdminController::usage` |
| POST | `/admin/usage/backfill-tools` | `AdminController::usageBackfillTools` |
| GET | `/admin/credits` | `AdminController::credits` |
| POST | `/admin/credits` | `AdminController::saveCredits` |
| POST | `/admin/credits/refresh-prices` | `AdminController::refreshCreditsPrices` |

### Outils (image / vidéo / HTML)

| Méthode | Route | Handler |
|---|---|---|
| GET | `/admin/tools` | `AdminController::tools` |
| GET | `/admin/tools/tests/latest` | `AdminController::latestToolsTestRun` |
| GET | `/admin/tools/tests/{id}` | `AdminController::toolsTestRunStatus` |
| POST | `/admin/tools/tests/start` | `AdminController::startToolsTestRun` |
| POST | `/admin/tools` | `AdminController::createTool` |
| POST | `/admin/tools/{id}` | `AdminController::updateTool` |
| POST | `/admin/tools/{id}/test` | `AdminController::testTool` |
| POST | `/admin/tools/{id}/delete` | `AdminController::deleteTool` |

### Logs

| Méthode | Route | Handler | Description |
|---|---|---|---|
| GET | `/admin/logs` | `LogsController::index` | Journaux : `storage/logs/errors.log` analysé (problèmes, flux, frise) ; `?export=1` = texte filtré |
| GET | `/admin/logs/feed` | `LogsController::feed` | JSON : suite du Flux (`offset`) ou entrées écrites depuis une position (`after`, suivi en direct) |

---

## 7. Codes d'erreur standards

| Code | Cas | Réponse |
|---|---|---|
| 401 | Non authentifié (AJAX/SSE) | `{"error":"session_expired","message":"...","login_url":"...","authenticated":false}` |
| 401 | Non authentifié (page) | Redirect 302 → `/auth/login` |
| 403 | CSRF invalide | `{"error":"Requête invalide (CSRF).","code":"csrf"}` (AJAX) ou HTML "Requête invalide" — sur `POST /auth/login` : redirect 302 → `/auth/login?error=expired` (page fraîche, jeton neuf) |
| 403 | Non admin | `{"error":"Accès réservé aux administrateurs."}` (AJAX) ou template `403.php` |
| 404 | Route inconnue | template `404.php` |

---

## 8. Statistiques

- **Total routes** : ~64
- **GET** : 22
- **POST** : 42
- **Avec param `{id}`** : ~30
- **Authentifiées** : toutes sauf `/auth/*` et `/setup`
- **Admin uniquement** : toutes les routes `/admin/*` (~36)
