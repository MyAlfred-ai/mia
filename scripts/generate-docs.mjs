#!/usr/bin/env node
// Met à jour docs/component-inventory.md (tableaux par section) via l'API
// OpenAI, à partir des fichiers PHP de src/ modifiés depuis le dernier run.
// Usage: node scripts/generate-docs.mjs
// Env requis: OPENAI_API_KEY
// Env optionnels:
//   DOCS_MODEL       modèle OpenAI (défaut: gpt-6-luna)
//   DOCS_MAX_FILES   plafond de fichiers envoyés par run (défaut: 80)
//   DOCS_FORCE_FULL  =1 pour ignorer le plafond (regénération volontaire)
//   DOCS_DRY_RUN     =1 pour lister les fichiers concernés sans appeler OpenAI
// Pas de dépendance npm : fetch natif (Node 18+), aucun impact sur composer.json.
//
// Point de départ du diff (dans l'ordre) :
//   1. le marqueur docs/.component-inventory-sha de la branche PR docs-auto/update ;
//   2. à défaut, la base de cette branche (merge-base avec HEAD) — cas d'une
//      branche produite par l'ancien script, sans marqueur ;
//   3. à défaut, le marqueur de HEAD (PR mergée) ;
//   4. à défaut, le dernier commit ayant touché docs/component-inventory.md.
// L'inventaire déjà mis à jour dans la branche PR est repris (fusion à 3 voies)
// avant d'ajouter les nouveautés : la PR s'accumule au lieu d'être recalculée.
// Historique : avant ce mécanisme, le point de départ ne bougeait que quand la
// PR était mergée. Jamais mergée → chaque push renvoyait TOUS les fichiers PHP
// modifiés depuis des mois (243 fichiers, 4,4 Mo, ~2,5 $ par run en gpt-4.1,
// jusqu'à 40 runs par semaine), d'où une facture OpenAI qui gonflait sans fin.

import { execSync } from "node:child_process";
import { readFileSync, writeFileSync, existsSync, mkdtempSync, rmSync } from "node:fs";
import { dirname, join, relative, basename } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const INVENTORY_FILE = join(ROOT, "docs", "component-inventory.md");
const MARKER_FILE = join(ROOT, "docs", ".component-inventory-sha");
const PR_BRANCH = "docs-auto/update";
const PR_REF = `origin/${PR_BRANCH}`;

const MODEL = process.env.DOCS_MODEL || "gpt-6-luna";
const MAX_FILES = Number(process.env.DOCS_MAX_FILES) || 80; // valeur non numérique → 80, jamais « sans plafond »
const FORCE_FULL = process.env.DOCS_FORCE_FULL === "1";
// `reasoning_effort: "none"` n'existe que pour gpt-5.1+ et la famille gpt-6 ;
// gpt-4.1 et gpt-4.1-mini (DOCS_MODEL) refusent le paramètre.
const REASONING_MODEL = /^gpt-(5\.\d|6)/.test(MODEL);

// Tarifs publics ($ / 1M jetons) pour l'estimation affichée en fin de run.
// Indicatif seulement : vérifier sur platform.openai.com en cas de doute.
const PRICES = {
  "gpt-6-luna": { input: 0.1, output: 0.5 },
  "gpt-4.1": { input: 2.0, output: 8.0 },
  "gpt-4.1-mini": { input: 0.4, output: 1.6 },
};

// Dossier src/<X> → mot-clé recherché dans le titre de section existant
// (ex: src/LLM → section "## 5. LLM Adapters").
const SECTION_KEYWORDS = {
  Auth: "Auth",
  Controllers: "Controllers",
  Helpers: "Helpers",
  LLM: "LLM",
  Models: "Models",
  RAG: "RAG",
  Security: "Security",
  Tools: "Tools",
};

function sh(cmd) {
  return execSync(cmd, { cwd: ROOT, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] }).trim();
}

function trySh(cmd) {
  try {
    return sh(cmd);
  } catch {
    return "";
  }
}

function rel(absPath) {
  return relative(ROOT, absPath);
}

function commitExists(sha) {
  return /^[0-9a-f]{7,40}$/.test(sha) && trySh(`git cat-file -t ${sha}`) === "commit";
}

function isAncestorOfHead(sha) {
  try {
    sh(`git merge-base --is-ancestor ${sha} HEAD`);
    return true;
  } catch {
    return false;
  }
}

// Lit un fichier dans un commit/branche (chaîne vide s'il n'existe pas).
// Contenu brut, sans trim : un saut de ligne final retiré ici ferait diverger
// l'ancêtre de la copie de travail, et la fusion à 3 voies tomberait en conflit
// dès que la PR a ajouté des lignes en fin de fichier.
function gitShow(ref, relPath) {
  try {
    return execSync(`git show ${ref}:"${relPath}"`, {
      cwd: ROOT,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
      maxBuffer: 64 * 1024 * 1024,
    });
  } catch {
    return "";
  }
}

function prBranchExists() {
  // En CI (checkout fetch-depth 0) la branche est déjà là ; en local, on la
  // récupère au besoin. Échec silencieux : pas de branche = pas de PR ouverte.
  trySh(`git fetch --no-tags --quiet origin ${PR_BRANCH}:refs/remotes/${PR_REF}`);
  return commitExists(trySh(`git rev-parse --verify --quiet ${PR_REF}`));
}

// Détermine le commit de départ et d'où vient l'inventaire déjà mis à jour.
function resolveBase() {
  const markerRel = rel(MARKER_FILE);

  if (prBranchExists()) {
    const marker = gitShow(PR_REF, markerRel).trim();
    if (commitExists(marker) && isAncestorOfHead(marker)) {
      return { sha: marker, docsFrom: PR_REF, how: `marqueur de ${PR_REF}` };
    }
    const mergeBase = trySh(`git merge-base ${PR_REF} HEAD`);
    if (commitExists(mergeBase)) {
      return { sha: mergeBase, docsFrom: PR_REF, how: `base de ${PR_REF}` };
    }
  }

  const headMarker = existsSync(MARKER_FILE) ? readFileSync(MARKER_FILE, "utf8").trim() : "";
  if (commitExists(headMarker) && isAncestorOfHead(headMarker)) {
    return { sha: headMarker, docsFrom: null, how: "marqueur de HEAD" };
  }

  // Ancien comportement : dernier commit ayant touché l'inventaire.
  const lastDocsCommit = trySh(`git log -1 --format=%H -- "${rel(INVENTORY_FILE)}"`);
  if (commitExists(lastDocsCommit)) {
    return { sha: lastDocsCommit, docsFrom: null, how: "dernier commit de component-inventory.md" };
  }
  return { sha: "", docsFrom: null, how: "aucun (première génération)" };
}

// Reprend, depuis la branche PR, l'inventaire mis à jour lors des runs
// précédents. L'inventaire est aussi retouché à la main (compteurs, récapitulatif),
// d'où une fusion à 3 voies ; en cas de conflit, la version de develop est
// conservée : les lignes de la PR sont perdues, chaque classe sera redécrite à
// la prochaine modification de son fichier.
function restorePreviousInventory(base) {
  if (!base.docsFrom) return;
  const relPath = rel(INVENTORY_FILE);
  const theirs = gitShow(base.docsFrom, relPath);
  if (!theirs) return;
  const ours = existsSync(INVENTORY_FILE) ? readFileSync(INVENTORY_FILE, "utf8") : "";
  const ancestor = base.sha ? gitShow(base.sha, relPath) : "";

  if (ours === theirs) return;
  if (ours === ancestor) {
    // Rien n'a bougé sur develop depuis la génération : on reprend la PR telle quelle.
    writeFileSync(INVENTORY_FILE, theirs);
    return;
  }
  const tmp = mkdtempSync(join(tmpdir(), "docs-auto-"));
  try {
    const fOurs = join(tmp, "ours.md");
    const fBase = join(tmp, "base.md");
    const fTheirs = join(tmp, "theirs.md");
    writeFileSync(fOurs, ours);
    writeFileSync(fBase, ancestor);
    writeFileSync(fTheirs, theirs);
    const merged = execSync(`git merge-file -p "${fOurs}" "${fBase}" "${fTheirs}"`, {
      cwd: ROOT,
      encoding: "utf8",
    });
    writeFileSync(INVENTORY_FILE, merged);
  } catch {
    console.warn(`Conflit de fusion sur ${relPath} : version de develop conservée.`);
  } finally {
    rmSync(tmp, { recursive: true, force: true });
  }
}

function changedFiles(base) {
  if (!base.sha) {
    return sh(`git ls-files src`).split("\n").filter((f) => f.endsWith(".php"));
  }
  const diff = sh(`git diff --name-only --diff-filter=ACMR ${base.sha} HEAD -- src`);
  return diff.split("\n").filter((f) => f.endsWith(".php"));
}

function topFolder(relPath) {
  // src/Auth/Foo.php → "Auth"
  const parts = relPath.split("/");
  return parts[0] === "src" ? parts[1] : null;
}

function extractClassName(content, relPath) {
  const m = content.match(/\b(?:abstract\s+class|final\s+class|class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/);
  if (m) return m[1];
  return basename(relPath, ".php");
}

const usage = { input: 0, output: 0, calls: 0 };

async function askDescription({ apiKey, relPath, className, content }) {
  const prompt = `Tu maintiens un inventaire de composants PHP pour myLLM (app de chat multi-LLM). Décris en UNE phrase courte et factuelle le rôle de la classe \`${className}\` (fichier ${relPath}), dans le même style que ces exemples existants :
- "Routeur regex maison, params nommés {id}, dispatch sur tuple [Class, method] ou closure"
- "CRUD users, rôles, quota, recherche par Google ID / email, effectiveCreditQuotaK()"
- "Flow OAuth 2.0 Google (auth URL, callback, exchange code → token → userinfo)"

Réponds UNIQUEMENT la phrase (pas de guillemets, pas de markdown, pas de point final obligatoire, pas de préambule).

Code:
\`\`\`php
${content}
\`\`\``;

  // gpt-4.1 est retiré par OpenAI le 2027-01-14 ; gpt-6-luna est le remplaçant
  // désigné (20× moins cher en entrée). Sans raisonnement, comme gpt-4.1 : des
  // jetons de raisonnement mangeraient le budget de sortie. La famille gpt-6
  // refuse `max_tokens`.
  const res = await fetch("https://api.openai.com/v1/chat/completions", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${apiKey}`,
    },
    body: JSON.stringify({
      model: MODEL,
      ...(REASONING_MODEL && { reasoning_effort: "none" }),
      max_completion_tokens: 200,
      messages: [{ role: "user", content: prompt }],
    }),
  });
  if (!res.ok) {
    throw new Error(`OpenAI API error ${res.status}: ${await res.text()}`);
  }
  const data = await res.json();
  usage.calls += 1;
  usage.input += data.usage?.prompt_tokens ?? 0;
  usage.output += data.usage?.completion_tokens ?? 0;
  return (data.choices[0]?.message?.content ?? "").trim().replace(/^["'`]|["'`]$/g, "");
}

function upsertRow(lines, { folder, relPath, className, description }) {
  const keyword = SECTION_KEYWORDS[folder];
  if (!keyword) return false; // dossier hors scope connu, on ignore

  const headingRe = new RegExp(`^## \\d+\\.\\s.*\\b${keyword}\\b`, "i");
  const startIdx = lines.findIndex((l) => headingRe.test(l));
  if (startIdx === -1) {
    console.warn(`Section pour "${folder}" introuvable dans component-inventory.md — ligne ignorée (${relPath}).`);
    return false;
  }

  let endIdx = lines.findIndex((l, i) => i > startIdx && /^## /.test(l));
  if (endIdx === -1) endIdx = lines.length;

  const row = `| \`${className}\` | \`${relPath}\` | ${description} |`;
  const pathNeedle = `\`${relPath}\``;

  let rowIdx = -1;
  for (let i = startIdx; i < endIdx; i++) {
    if (lines[i].includes(pathNeedle)) {
      rowIdx = i;
      break;
    }
  }

  if (rowIdx !== -1) {
    lines[rowIdx] = row;
  } else {
    // Insère après la dernière ligne de tableau (`|...`) de la section.
    let lastTableRow = -1;
    for (let i = startIdx; i < endIdx; i++) {
      if (lines[i].trim().startsWith("|")) lastTableRow = i;
    }
    const insertAt = lastTableRow !== -1 ? lastTableRow + 1 : endIdx;
    lines.splice(insertAt, 0, row);
  }
  return true;
}

function writeMarker() {
  writeFileSync(MARKER_FILE, sh("git rev-parse HEAD") + "\n");
}

function reportUsage() {
  const price = PRICES[MODEL];
  const cost = price
    ? ` ≈ $${((usage.input * price.input + usage.output * price.output) / 1e6).toFixed(3)}`
    : "";
  console.log(
    `OpenAI (${MODEL}) : ${usage.calls} appels, ${usage.input} jetons en entrée, ${usage.output} en sortie${cost}.`
  );
}

async function main() {
  const apiKey = process.env.OPENAI_API_KEY;
  if (!apiKey) {
    console.error("OPENAI_API_KEY manquant — abandon.");
    process.exit(1);
  }
  if (!existsSync(INVENTORY_FILE)) {
    console.error(`Fichier cible introuvable: ${INVENTORY_FILE}`);
    process.exit(1);
  }

  const base = resolveBase();
  console.log(`Point de départ : ${base.sha ? base.sha.slice(0, 10) : "(aucun)"} — ${base.how}.`);

  // Fichiers supprimés exclus : on laisse la ligne existante. Dossiers hors
  // sections connues exclus : ils n'auraient produit aucune ligne.
  const files = changedFiles(base).filter((f) => existsSync(join(ROOT, f)) && SECTION_KEYWORDS[topFolder(f)]);
  if (files.length === 0) {
    console.log("Aucun fichier PHP changé sous src/ depuis la dernière doc — rien à faire.");
    // La PR est recréée depuis develop à chaque run : sans ce report, un run sans
    // nouveauté (push hors .php, relance manuelle) la viderait, et delete-branch
    // la fermerait avec tout l'inventaire déjà généré.
    if (base.docsFrom && process.env.DOCS_DRY_RUN !== "1") {
      restorePreviousInventory(base);
      writeMarker();
    }
    return;
  }

  // Garde-fou : un point de départ qui n'avance plus se voit ici, avant la facture.
  if (files.length > MAX_FILES && !FORCE_FULL) {
    console.error(
      `${files.length} fichiers à décrire, plafond DOCS_MAX_FILES=${MAX_FILES} dépassé — abandon.\n` +
        `Si c'est voulu (regénération complète), relancer le workflow avec l'option « tout » ` +
        `(DOCS_FORCE_FULL=1).`
    );
    process.exit(1);
  }
  console.log(`${files.length} fichier(s) à décrire avec ${MODEL}.`);
  if (process.env.DOCS_DRY_RUN === "1") {
    const bytes = files.reduce((n, f) => n + readFileSync(join(ROOT, f)).length, 0);
    console.log(files.map((f) => `  - ${f}`).join("\n"));
    console.log(`DOCS_DRY_RUN=1 : ${bytes} octets seraient envoyés, aucun appel OpenAI.`);
    return;
  }

  restorePreviousInventory(base);

  let lines = readFileSync(INVENTORY_FILE, "utf8").split("\n");
  let changed = false;

  for (const relPath of files) {
    const absPath = join(ROOT, relPath);
    const folder = topFolder(relPath);
    const content = readFileSync(absPath, "utf8");
    const className = extractClassName(content, relPath);
    const description = await askDescription({ apiKey, relPath, className, content });

    const ok = upsertRow(lines, { folder, relPath, className, description });
    if (ok) {
      changed = true;
      console.log(`Ligne mise à jour: ${className} (${relPath})`);
    }
  }

  if (changed) {
    writeFileSync(INVENTORY_FILE, lines.join("\n"));
  } else {
    console.log("Aucune ligne modifiée (fichiers hors sections connues).");
  }
  writeMarker();
  reportUsage();
}

main().catch((err) => {
  console.error(err);
  reportUsage();
  process.exit(1);
});
