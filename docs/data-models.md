# myLLM — Data Models

Schéma MySQL complet du projet.

**Engine** : InnoDB
**Charset** : `utf8mb4_unicode_ci`
**Migrations** : `database/migrations/NNN_*.sql` jouées au boot par `Database::migrate()`. Chaque migration enregistrée dans `schema_migrations`.

---

## Diagramme de relations

```
                  ┌───────────────┐
                  │  user_groups  │
                  │───────────────│
                  │ id (PK)       │
                  │ name          │
                  │ credit_quota_k│
                  │ token_quota*  │   * legacy
                  └───┬───┬───┬───┘
                      │   │   │
       ┌──────────────┘   │   └────────────────┐
       │                  │                    │
       ▼                  ▼                    ▼
 user_group_       user_group_         user_group_
   members          profiles              tools
       │                  │                    │
       │                  │                    │
       ▼                  ▼                    ▼
   ┌──────┐          ┌─────────┐          ┌──────────┐
   │users │          │profiles │          │chat_tools│
   │──────│          │─────────│          │──────────│
   │id PK │          │id PK    │          │id PK     │
   │email │          │vector_  │          │type      │
   │role  │          │…        │  │       │provider  │
   │group_│          │         │  │       │model     │
   │ id   │          └────┬────┘  │       │api_key_  │
   └─┬────┘               │       │       │ enc      │
     │                    │       │       └────┬─────┘
     │                    │       ▼            │
     │              ┌─────┴───┐ ┌──────────────┴──┐
     │              │profile_ │ │knowledge_bases  │
     │              │documents│ │─(N-N profils via│
     │              └─────────┘ │ profile_know…)  │
     │                           │documents+chunks │
     │                           └─────────────────┘
     │
     ├──────────────► conversations ──┐
     │                  │             │
     │                  ▼             │
     │              messages ─────────┤
     │                  │             │
     │                  ▼             │
     │       tool_usage_backfill_     │
     │           markers              │
     │                                │
     ├──► usage_logs ──────────────── │
     │                                │
     ├──► tool_usage_logs ──────────  ┘
     │
     └──► api_keys (table indépendante, lookup par provider)

settings (key/value, sans relations)
llm_models (instances LLM admin, sans relations)
schema_migrations (tracking interne)
```

---

## Tables

### `users`

| Colonne | Type | Notes |
|---|---|---|
| id | INT UNSIGNED PK | AUTO_INCREMENT |
| google_id | VARCHAR(255) UNIQUE | Synthétique `pw_<hex>` pour comptes locaux |
| email | VARCHAR(255) UNIQUE | |
| name | VARCHAR(255) | |
| avatar_url | TEXT | |
| role | VARCHAR(20) | `admin` / `creator` / `user` |
| is_active | TINYINT(1) | 1er user = admin actif auto ; les suivants créés inactifs (0) jusqu'à validation admin |
| password_hash | VARCHAR(255) NULL | bcrypt si auth locale (migration 011) |
| token_quota | INT UNSIGNED NULL | **Legacy** — millions de tokens (migration 008) |
| credit_quota_k | INT UNSIGNED NULL | Milliers de crédits / mois (migration 018). NULL = illimité |
| group_id | INT UNSIGNED NULL FK | → `user_groups.id` ON DELETE SET NULL (migration 015) |
| created_at | DATETIME | |
| last_login | DATETIME NULL | |

**Indexes** : `idx_users_group_id` (group_id)

---

### `user_groups`

| Colonne | Type | Notes |
|---|---|---|
| id | INT UNSIGNED PK | |
| name | VARCHAR(255) UNIQUE | |
| token_quota | INT UNSIGNED NULL | **Legacy** |
| credit_quota_k | INT UNSIGNED NULL | Quota hérité par les membres |
| is_active | TINYINT(1) | |

### `user_group_members` (M2M)

| Colonne | Type |
|---|---|
| user_id | INT UNSIGNED FK CASCADE |
| group_id | INT UNSIGNED FK CASCADE |

PK composite `(user_id, group_id)`. Migration 016 a backfillé depuis `users.group_id`.

### `user_group_profiles` (M2M)

Restreint quels profils sont accessibles à un groupe. PK `(group_id, profile_id)`.

### `user_group_tools` (M2M)

Restreint quels outils sont accessibles à un groupe. PK `(group_id, tool_id)`.

---

### `profiles`

Assistants conversationnels.

| Colonne | Type | Notes |
|---|---|---|
| id | INT UNSIGNED PK | |
| name | VARCHAR(255) | |
| description | TEXT | |
| system_prompt | LONGTEXT | Injecté en début de conversation |
| default_llm | VARCHAR(100) | Slug `provider::model` |
| color | VARCHAR | Migration 002 |
| temperature | FLOAT NULL | Migration 003 |
| avatar_url | TEXT NULL | Migration 005 |
| is_active | TINYINT(1) | |

### `profile_access` (par rôle)

Si non vide : seul ce rôle a accès. Sinon : tous les users actifs.

### `profile_documents` (multi-fichier)

Documents de référence par assistant — texte extrait + tokens.

---

### `conversations`

| Colonne | Type | Notes |
|---|---|---|
| id | INT UNSIGNED PK | |
| user_id | INT UNSIGNED FK CASCADE | Isolation stricte |
| profile_id | INT UNSIGNED NULL FK | ON DELETE SET NULL |
| llm_used | VARCHAR(100) | Slug LLM |
| title | VARCHAR(500) | Auto-généré ou édité |

**Indexes** : `idx_conversations_user`

---

### `messages`

| Colonne | Type | Notes |
|---|---|---|
| id | INT UNSIGNED PK | |
| conversation_id | INT UNSIGNED FK CASCADE | |
| role | VARCHAR(20) | `user` / `assistant` / `system` |
| content | LONGTEXT | |
| file_name | VARCHAR(500) NULL | |
| file_type | VARCHAR(100) NULL | |
| file_extracted | LONGTEXT NULL | Texte extrait |
| files_json | TEXT NULL | Migration 006 — multi-fichiers JSON |
| tokens_used | INT | |

**Indexes** : `idx_messages_conversation`

---

### `api_keys`

Clés API LLM chiffrées.

| Colonne | Type | Notes |
|---|---|---|
| id | INT UNSIGNED PK | |
| provider | VARCHAR(50) UNIQUE | `openai`, `anthropic`, `mistral`, `gemini`, `perplexity`, `deepseek`, `moonshot`, `zai`, `local` |
| key_enc | TEXT | base64(IV ‖ AES-256-CBC ciphertext) |
| model | VARCHAR | Default model legacy (migration 007) |
| base_url | TEXT NULL | Pour `local` (migration 009) |
| display_name | VARCHAR | (migration 009) |
| is_active | TINYINT(1) | |

---

### `llm_models` (migration 010)

Instances LLM activées par l'admin (override du catalogue).

UNIQUE `(provider, model)`.

---

### `knowledge_bases` (migration 044)

Bases de connaissances vectorisées, partagées entre assistants. Vecteurs dans Qdrant embarqué (collection `kb_{id}`), chunks en MySQL (source de vérité + fallback lexical).

| Colonne | Type |
|---|---|
| id | PK |
| name / description | |
| embedding_provider / embedding_model / embedding_dims | Figés à la création (dims = taille collection Qdrant) |
| is_active | TINYINT(1) |
| created_by | FK users ON DELETE SET NULL |

Tables sœurs : `knowledge_base_documents` (double statut `extraction_status` + `vector_status`, `chunk_count`), `knowledge_base_chunks` (FULLTEXT `ft_kbc_content`, id = point Qdrant), `profile_knowledge_bases` (N-N assistants, PK composite, CASCADE), `conversation_knowledge_bases` (migration 069 — bases choisies par l'utilisateur pour UNE conversation, PK composite `(conversation_id, kb_id)`, CASCADE des deux côtés ; elles s'ajoutent à celles de l'assistant).

---

### `chat_tools` (migration 012)

Outils de génération attachés au chat.

| Colonne | Type | Notes |
|---|---|---|
| type | VARCHAR(50) | `image` / `video` / `html` |
| provider | VARCHAR(50) | `openai`, `gemini`, `sora`, etc. |
| model | VARCHAR(100) | |
| config_json | TEXT | Paramètres spécifiques (size, quality, …) |
| api_key_enc | TEXT NULL | Clé dédiée si différente de `api_keys` |
| test_status | (migration 013) |
| sort_order | |

### `tool_test_runs` (migration 014)

Résultats des tests admin batch sur les outils.

---

### `usage_logs`

| Colonne | Type | Notes |
|---|---|---|
| id | PK | |
| user_id | FK SET NULL | |
| conversation_id | FK SET NULL | |
| profile_id | FK SET NULL | |
| llm_provider | VARCHAR(100) | Slug ou nom |
| tokens_input | INT | |
| tokens_output | INT | |
| credits_used | INT | Migration 018 |

**Indexes** : `idx_usage_user`, `idx_usage_created`, `idx_usage_user_created`

### `tool_usage_logs` (migration 017)

| Colonne | Type | Notes |
|---|---|---|
| tool_id | FK CASCADE | |
| tool_type | VARCHAR(50) | |
| credits_used | INT | Migration 018 |
| quantity_units | DOUBLE | Secondes vidéo, nb images |

### `tool_usage_backfill_markers` (migration 018b)

Tracking de re-attribution post-hoc des outils utilisés (script admin `usageBackfillTools`).

---

### `settings`

Configuration globale clé/valeur (migration 004).

| Colonne | Type |
|---|---|
| key | VARCHAR PK |
| value | TEXT |

**Clés notables** :
- `theme` : `dark` / `light`
- `accent_color` : `#hex`
- `app_name`, `logo_url`, `logo_hover_url` (migration 019)
- `default_llm` : slug `provider::model`
- `credits_config_json` : config tarification (cf. `CreditService::config()`)

---

### `schema_migrations` (interne)

Tracking des migrations appliquées. PK = `filename`. Auto-géré par `Database::migrate()`.

---

## Statistiques

- **16 tables métier** + 1 table interne (`schema_migrations`)
- **19 migrations** (avec doublon `018_*`)
- **Foreign keys** : ~15 contraintes, mix `CASCADE` / `SET NULL` selon sémantique
- **Indexes secondaires** : ~12 indexes performance (lookups par user_id, created_at)

---

## Contraintes & invariants implicites

1. **Isolation user** : `Conversation::belongsToUser($id, $userId)` à appeler AVANT toute lecture/écriture sur une conversation
2. **Premier user = admin** : enforced à la création (count rows == 0)
3. **Quota cascade** : `User::effectiveCreditQuotaK()` lit user → groupe → null. Migration 018 préserve `token_quota` legacy
4. **Migrations idempotentes** : `CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE` quand applicable. **Ne JAMAIS modifier une migration appliquée** — créer un nouveau numéro
5. **Chiffrement** : `api_keys.key_enc`, `chat_tools.api_key_enc` toujours via `Encryption::encrypt/decrypt`
6. **Pas de soft-delete** : `DELETE` direct partout. Cascades configurées pour propagation cohérente.

---

## Dette schéma

- ⚠️ `users.token_quota` et `user_groups.token_quota` toujours présents — code lit `credit_quota_k` en priorité, fallback `token_quota`. Pourrait être supprimé après migration de données.
- ⚠️ Doublon de préfixe `018` dans les migrations (cf. source-tree-analysis.md).
- ⚠️ `profiles.color` / `profiles.temperature` / `profiles.avatar_url` ajoutés migration par migration (002, 003, 005) → consolidable.
