# Installer MIA

Document de référence pour l'installation. Il fait autorité : en cas de
divergence avec un autre fichier, c'est celui-ci qui est à jour.

Pour l'exploitation courante (mise à jour, sauvegarde, restauration), voir
[EXPLOITATION.md](EXPLOITATION.md).

---

## En bref

```bash
git clone https://github.com/MyAlfred-ai/myLLM.git mia
cd mia
./install.sh
```

L'installeur vérifie les prérequis **avant** d'écrire quoi que ce soit, pose
quelques questions, génère le fichier `.env`, construit les images, attend que
l'application réponde, lance un diagnostic complet et crée le compte
administrateur. En cas d'échec, il s'arrête en expliquant quoi corriger.

L'adresse du premier administrateur n'a **pas de valeur par défaut** : elle est
redemandée tant qu'elle est refusée — il la faut complète, `vous@exemple.fr`,
selon la même règle que `scripts/admin-create.php` —, et le mot de passe l'est
s'il fait moins de 8 caractères. Sans questions (`--non-interactive`, ou sans
terminal), **`MIA_ADMIN_EMAIL` est obligatoire** : absente ou refusée, comme
un `MIA_ADMIN_PASSWORD` trop court, elle arrête l'installeur avant qu'il
n'écrive quoi que ce soit.

Compter dix minutes, dont l'essentiel en construction d'images.

---

## 1. Prérequis

### Sur la machine hôte

| Élément | Version | Remarque |
|---|---|---|
| Docker Engine | 24 ou plus | |
| Docker Compose | **v2** (`docker compose`, sans tiret) | `docker-compose` v1 n'est pas supporté |
| `git` | — | l'application se met à jour par `git` |
| `openssl` | — | génération des secrets ; à défaut, `/dev/urandom` |
| `curl` | — | vérifications post-installation |
| Espace disque | 5 Go libres | les images pèsent environ 2 Go |

`./install.sh --check` contrôle tout cela sans rien modifier.

### Installation native (sans Docker)

Possible, mais non couverte par l'installeur. Il vous faudra :

- **PHP 8.3 ou plus** (plancher imposé par `phpspreadsheet`), build 64 bits — l'image Docker est en **8.5** ;
- extensions **requises** : `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl`,
  `fileinfo`, `session` ;
- extensions exigées par les dépendances : `gd`, `zip`, `dom`, `xml`,
  `simplexml`, `xmlreader`, `xmlwriter`, `iconv`, `ctype`, `zlib` — sans elles,
  `composer install` refuse de s'exécuter ;
- extensions recommandées : `intl`, `exif`, `opcache`, `pcntl` (arrêt propre du
  worker) ;
- **MySQL 8.0 ou plus** — `FOR UPDATE SKIP LOCKED` et `GET_LOCK` sont utilisés ;
- un serveur web pointant sur `public/`, avec réécriture d'URL ;
- `composer install --no-dev --optimize-autoloader`.

`php scripts/doctor.php` vérifie chacun de ces points et nomme ce qui manque.

---

## 2. Choisir son mode

MIA se publie de quatre façons. Le mode est mémorisé dans le `.env`
(`COMPOSE_FILE`) ; toute commande `docker compose …` en tient compte ensuite,
sans option supplémentaire.

| Mode | Quand | `COMPOSE_FILE` |
|---|---|---|
| **Autonome** | serveur dédié à MIA, aucun proxy en place | `docker-compose.yml:docker/compose/db.yml:docker/compose/ports.yml` |
| **Traefik** | un Traefik tourne déjà sur la machine | `docker-compose.yml:docker/compose/db.yml:docker/compose/traefik.yml` |
| **Proxy tiers** | un nginx / Caddy / HAProxy est déjà en place | comme *autonome*, avec `APP_BIND=127.0.0.1` et `TRUSTED_PROXY=1` |
| **Développement** | poste de travail | `…:docker/compose/ports.yml:docker/compose/dev.yml` |

Ne combinez **jamais** `ports.yml` et `traefik.yml` : le port 80 de l'hôte est
celui de Traefik, et publier le conteneur l'exposerait hors TLS.

### Autonome

```bash
MIA_MODE=standalone MIA_PORT=8080 ./install.sh
```

MIA écoute sur `http://<serveur>:8080`. Pour du TLS, placez un proxy devant
(voir ci-dessous) — MIA ne termine pas le TLS elle-même.

### Derrière Traefik

Le réseau Docker de Traefik doit **préexister** ; l'installeur le vérifie et
refuse de continuer sinon.

```bash
docker network ls | grep ubuntu_default    # le réseau de votre Traefik
MIA_MODE=traefik MIA_DOMAIN=mia.exemple.fr ./install.sh
```

Les entrypoints (`web`, `web-secure`), le resolver ACME (`le`) et le nom du
routeur ont des valeurs par défaut ; surchargez-les par `TRAEFIK_ENTRYPOINT_HTTP`,
`TRAEFIK_ENTRYPOINT_HTTPS`, `TRAEFIK_CERTRESOLVER`, `TRAEFIK_ROUTER` et
`TRAEFIK_NETWORK` si votre installation s'en écarte.

### Derrière nginx ou Caddy

```bash
MIA_MODE=proxy MIA_PORT=8080 MIA_URL=https://mia.exemple.fr ./install.sh
```

MIA n'écoute alors que sur `127.0.0.1:8080`, et `TRUSTED_PROXY=1` autorise la
dérivation du cookie « Secure » depuis `X-Forwarded-Proto`. **Votre proxy doit
réécrire cet en-tête**, sinon un client pourrait le forger.

nginx :

```nginx
server {
    listen 443 ssl http2;
    server_name mia.exemple.fr;
    # ssl_certificate … ;

    client_max_body_size 50m;   # dépôts de fichiers

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # Flux de réponse des assistants (Server-Sent Events) : sans ces trois
        # lignes, nginx met la réponse en tampon et le texte n'apparaît qu'à la
        # fin de la génération, ou pas du tout.
        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 300s;
    }
}
```

Caddy :

```caddy
mia.exemple.fr {
    reverse_proxy 127.0.0.1:8080 {
        flush_interval -1        # indispensable pour le flux des réponses
    }
    request_body {
        max_size 50MB
    }
}
```

### Développement local

```bash
MIA_MODE=dev MIA_PORT=8080 ./install.sh
./scripts/install-hooks.sh      # une fois : versioning et changelog automatiques
```

Ce mode monte le code source de l'hôte dans les conteneurs : une modification
est visible au rechargement, sans reconstruire l'image. Lancez `composer install`
sur l'hôte au préalable — le montage recouvre aussi `vendor/`.

### Changer de mode, réinstaller

```bash
./install.sh --force
```

Repose les questions et réécrit le `.env`, après l'avoir copié dans
`.env.bak.<date>`. Il **reprend** de l'ancien `.env` `APP_SECRET`,
`ENCRYPTION_KEY` et, d'une base embarquée à une base embarquée, `DB_PASS` et
`DB_ROOT_PASS` : la base s'ouvre toujours, les clés API restent lisibles. Le
reste vient des réponses données ; ce que l'ancien `.env` portait d'autre
(variables ajoutées à la main) reste dans la copie.

MIA peut rester en service : son propre port n'est pas un conflit, et
`docker compose up -d` ne recrée que ce qui change. Un compte existant à
l'adresse donnée est laissé tel quel, rôle et mot de passe compris —
`scripts/admin-create.php --reset` les redéfinit.

---

## 3. Base de données externe

Pour une base managée plutôt que le conteneur MySQL embarqué :

```bash
MIA_DB_HOST=mysql.exemple.fr MIA_DB_PORT=3306 \
MIA_DB_NAME=mia MIA_DB_USER=mia MIA_DB_PASS='…' \
  ./install.sh
```

L'installeur retire alors `docker/compose/db.yml` de `COMPOSE_FILE`. La base doit
exister et l'utilisateur doit avoir les droits de création de tables : MIA
applique ses migrations elle-même au démarrage.

---

## 4. Architecture ARM

`mysql:8.4` et `qdrant/qdrant` publient des images arm64. L'image applicative est
construite localement, donc native. Le seul point d'attention est la base PHP,
épinglée **par empreinte** dans le `Dockerfile` : une empreinte désigne une
architecture précise.

```bash
docker manifest inspect php:8.5-apache | grep -c arm64   # 0 = mono-architecture
```

Si la construction échoue sur ARM, remplacez la ligne `FROM` du `Dockerfile` par
le tag simple `php:8.5-apache` (au prix de la stabilité du cache de couches).

---

## 5. Variables d'environnement

Liste exhaustive. `install.sh` écrit les indispensables ; `.env.example`
documente les autres.

### Obligatoires

| Variable | Rôle |
|---|---|
| `APP_URL` | adresse publique complète. Sert aux redirections OAuth, aux liens absolus et au manifeste PWA |
| `APP_SECRET` | signature des sessions — `openssl rand -hex 32` |
| `ENCRYPTION_KEY` | chiffrement des clés API en base — `openssl rand -hex 32`. **Ne plus la changer** une fois des clés enregistrées ; `install.sh --force` la reprend |
| `DB_PASS` | mot de passe de l'utilisateur MySQL. Celui de la base embarquée est fixé à la création de son volume : le modifier dans `.env` ne le change pas dans la base, qui refuse alors l'application |
| `DB_ROOT_PASS` | mot de passe root du MySQL embarqué (inutile avec une base externe) |
| `DOMAIN` | nom d'hôte sans schéma — **mode Traefik uniquement** |

### Structure et publication

| Variable | Défaut | Rôle |
|---|---|---|
| `COMPOSE_FILE` | — | fichiers compose à assembler : c'est lui qui définit le mode |
| `APP_IMAGE` | `mia-app:latest` | nom de l'image ; à changer si deux instances cohabitent |
| `APP_PORT` | `8080` | port publié sur l'hôte |
| `APP_BIND` | `0.0.0.0` | interface d'écoute ; `127.0.0.1` derrière un proxy local |
| `QDRANT_PORT` | `6333` | tableau de bord Qdrant, **mode développement uniquement** |
| `TRUSTED_PROXY` | `0` | à `1`, accepte `X-Forwarded-Proto` pour le cookie « Secure » |

### Base de données

| Variable | Défaut |
|---|---|
| `DB_HOST` | `db` (le service compose) |
| `DB_PORT` | `3306` |
| `DB_NAME` | `myllm` |
| `DB_USER` | `myllm` |

### Connexion Google (facultatif — repli)

> Ces identifiants se saisissent désormais **dans l'application** : *Administration → Organisation → Authentification*. C'est l'endroit recommandé — on peut les changer sans redéploiement, et les traitements en arrière-plan les voient forcément. La **connexion Microsoft** ne se configure que là, sans aucune variable d'environnement.
>
> Les variables ci-dessous restent lues **en repli**, pour ne rien casser sur une installation existante : une valeur saisie en administration l'emporte.

| Variable | Rôle |
|---|---|
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | connexion Google **et** connecteurs personnels Drive, Gmail, Agenda |
| `GOOGLE_REDIRECT_URI` | `<APP_URL>/auth/callback` |
| `GOOGLE_CLIENT_ID_IOS` | client OAuth iOS de l'application mobile |

### Base vectorielle

| Variable | Défaut | Rôle |
|---|---|---|
| `QDRANT_URL` | `http://qdrant:6333` | vide = service embarqué |
| `QDRANT_DATA_PATH` | `/qdrant-data` | mesure exacte de l'empreinte disque |

### Traefik (défauts fournis)

`TRAEFIK_NETWORK` (`ubuntu_default`), `TRAEFIK_ROUTER` (`myllm`),
`TRAEFIK_ENTRYPOINT_HTTP` (`web`), `TRAEFIK_ENTRYPOINT_HTTPS` (`web-secure`),
`TRAEFIK_CERTRESOLVER` (`le`).

### Héritées

| Variable | Remarque |
|---|---|
| `LOCAL_PASSWORD_HASH` | connexion de secours avec un e-mail vide. L'installeur ne la produit plus : préférez `php scripts/admin-create.php`. Si vous y tenez, **doublez tous les `$` du hash** dans le `.env`, sinon `docker compose` l'interpole et le tronque |
| `APP_ENV` | `production`. `local` affiche la trace complète des erreurs à l'écran |

---

## 6. Après l'installation

L'écran de fin, comme la page **Administration → Maintenance → Diagnostic**, liste ces points
avec leur état réel.

1. **Clés API des modèles** — Administration → Modèles IA → Clés API.
   *Seule étape indispensable* : sans clé, l'assistant ne peut rien répondre.
2. **Modèle par défaut et modèle de secours** — même page.
3. **Vectorisation** — une clé OpenAI, Mistral ou Gemini suffit. Sans elle, les
   bases de connaissances fonctionnent en recherche lexicale.
4. **Outils image, vidéo, présentation** — Administration → Capacités → Outils.
5. **Nom, logo, couleurs, identité de l'éditeur** — Administration → Organisation → Marque & apparence.
6. **Assistants et bases de connaissances** — Espaces → Assistants.

---

## 7. Vérifier une installation

```bash
docker compose exec app php scripts/doctor.php
```

Une vingtaine de contrôles : version de PHP, extensions, dépendances,
inscriptibilité des répertoires, secrets, base, migrations, worker, Qdrant,
modèles disponibles, sortie Internet. **Chaque ligne en défaut porte son
remède.** Sortie `0` s'il n'y a aucun échec — les avertissements n'échouent pas.

La même chose dans l'interface : **Administration → Maintenance → Diagnostic**.

Sonde automatique : `GET /healthz` renvoie du JSON et sert de `HEALTHCHECK`
Docker. Elle est publique et volontairement muette sur les détails.

```bash
curl -fsS https://mia.exemple.fr/healthz
# {"status":"ok","version":"1.2.0","checks":{…},"ts":"…"}
```

---

## 8. Dépannage

Avant tout : `php scripts/doctor.php`. Il couvre la grande majorité des cas
ci-dessous et donne le remède exact.

| Symptôme | Cause habituelle |
|---|---|
| Page « MIA n'est pas encore configurée » | `APP_SECRET` ou `ENCRYPTION_KEY` absent du `.env`. La page nomme la variable manquante |
| Page « MIA ne peut pas joindre sa base de données » | service `db` arrêté, ou `DB_HOST`/`DB_PORT` erronés. `docker compose logs db` |
| `[entrypoint] ÉCHEC : MySQL n'a pas répondu` | même cause. Base externe : `docker/compose/db.yml` doit être retiré de `COMPOSE_FILE` |
| `[migrate] ERREUR : La base de données a refusé les identifiants`, après `./install.sh --force` | si `DB_PASS` diffère entre `.env` et `.env.bak.<date>`, un installeur ancien a tiré de nouveaux secrets. La base n'a rien perdu : recopiez `DB_PASS`, `DB_ROOT_PASS`, `APP_SECRET` et `ENCRYPTION_KEY` depuis `.env.bak.<date>`, puis `docker compose up -d` |
| « le compte n'a pas pu être créé » en fin d'installation | adresse et mot de passe sont contrôlés dès les questions : reste un incident, que le message juste au-dessus nomme. Créez le compte sans attendre — `docker compose exec -T app php scripts/admin-create.php --email=…`, mot de passe sur l'entrée standard —, sinon l'écran « Bienvenue dans MIA » le crée pour quiconque y arrive le premier |
| Erreur interne avec un identifiant d'incident | cherchez cet identifiant dans `storage/logs/errors.log` : la trace complète y est |
| 404 de Traefik | `DOMAIN` ne correspond pas au DNS, ou le conteneur n'est pas sur le bon réseau. `docker network inspect ubuntu_default` |
| « Mot de passe invalide » sur la connexion de secours | `LOCAL_PASSWORD_HASH` tronqué par l'interpolation `$` de compose. Le diagnostic le signale |
| Les générations échouent toutes, mais les tests d'administration sont au vert | le **worker** n'a pas d'accès Internet sortant. Les tests partent du conteneur web, pas de lui |
| Les connecteurs Drive / Gmail / Agenda ne sont jamais proposés | Identifiants OAuth Google absents. Renseignez-les dans *Administration → Organisation → Authentification* : stockés en base, ils sont vus par l'app **et** par le worker. (Si vous passez par le `.env`, la variable doit être présente dans les DEUX conteneurs : `docker compose up -d --force-recreate worker`.) |
| Un déploiement ne change rien | l'image n'a pas été reconstruite. `docker compose build && docker compose up -d` |
| Les réponses n'arrivent qu'à la fin, ou pas du tout | le proxy met le flux en tampon. `proxy_buffering off` (nginx), `flush_interval -1` (Caddy) |

---

## 9. Ce que l'installation ne fait pas

- **Pas de TLS** : MIA sert du HTTP. Le chiffrement est la responsabilité de
  Traefik ou du proxy placé devant.
- **Pas de sauvegarde programmée** : `backup.sh` existe, mais il n'est appelé
  qu'au déploiement. Voir [EXPLOITATION.md](EXPLOITATION.md).
- **Pas d'envoi d'e-mails** : MIA n'en envoie aucun. Les comptes se créent en
  administration ou par connexion Google.
- **Pas de haute disponibilité** : une seule instance applicative. Les workers,
  eux, se comptent par `MIA_WORKERS` dans le `.env` (4 par défaut).
