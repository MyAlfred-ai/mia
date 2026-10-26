# Tests fonctionnels (PHPUnit)

Suite de tests fonctionnels de myLLM. Pas de framework lourd : PHPUnit est
récupéré en `.phar` (CI + hook pre-push), donc **aucune modification de
`composer.json` / `composer.lock`** (le build prod `--no-dev` reste intact).

## Organisation

| Dossier | Contenu | Base requise |
|---|---|---|
| `tests/Unit/` | Logique pure : Router, catalogue LLM, préférences IA, validation avatars, **RAG assistants** (chunking, budget tokens, matrice fenêtre de contexte par API, extraction par type/taille de fichier) | non |
| `tests/Functional/` | Contrôle d'accès réel sur MySQL : visibilité assistants par groupe, quotas/LLM groupes, **pipeline RAG** (retrieval FULLTEXT + pertinence, budget, fallbacks, extraction asynchrone, cascade) | **oui (MySQL)** |
| `tests/Support/` | `DatabaseTestCase` (connexion + truncate + helpers de seed) ; `RagFixtures` (génère TXT/MD/DOCX/XLSX/PDF à la volée) | — |

Les tests `Functional` se **skippent automatiquement** si aucune base MySQL de
test n'est joignable (poste dev sans base) — ils ne tournent vraiment qu'en CI
ou si tu fournis une base.

## Lancer en local

```bash
# 1. PHPUnit (téléchargé une fois, gitignoré)
curl -fsSL https://phar.phpunit.de/phpunit-11.phar -o phpunit.phar

# 2a. Unit seulement (aucune base) :
php phpunit.phar --testsuite unit

# 2b. Tout, avec une base MySQL jetable :
docker run -d --name myllm_test_db -e MYSQL_ROOT_PASSWORD=root \
    -e MYSQL_DATABASE=myllm_test -p 3399:3306 mysql:8.4
TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3399 TEST_DB_NAME=myllm_test \
TEST_DB_USER=root TEST_DB_PASS=root php phpunit.phar
docker rm -f myllm_test_db
```

La base est migrée automatiquement par le singleton `Database` (mêmes
`database/migrations/*.sql` qu'en prod), exactement comme l'app au boot.

## Automatisation

- **Hook pre-push** (`scripts/hooks/pre-push`, actif via `core.hooksPath`
  après `./scripts/install-hooks.sh`) : lance la suite avant chaque push et
  **bloque si rouge**. Bypass : `git push --no-verify`.
- **CI** (`.github/workflows/tests.yml`) : à chaque push sur `develop` et sur
  les PR vers `main`, relance toute la suite avec un service MySQL 8.

## Ajouter un test

- Logique pure → `tests/Unit/<Sujet>Test.php` extends `PHPUnit\Framework\TestCase`.
- Accès DB → `tests/Functional/<Sujet>Test.php` extends `DatabaseTestCase` ;
  utilise les helpers `insertUser/insertProfile/insertGroup/linkGroupProfile/
  addMember/addGroupLlm`. Si tu touches une nouvelle table, ajoute-la à
  `DatabaseTestCase::TABLES` pour qu'elle soit vidée entre tests.
