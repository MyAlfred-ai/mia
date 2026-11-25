/**
 * Les deux écrans de l'add-in Claude d'Anthropic dans PowerPoint, pour le pas-à-pas de
 * docs/user/fonctionnalites/connecteurs/claude-dans-office.md :
 *   docs/user/images/office-connexion.png   l'écran d'accueil du volet, « Cloud provider or gateway »
 *   docs/user/images/office-passerelle.png  l'écran Gateway, adresse et jeton collés
 *
 * Ces écrans ne sont pas ceux de MIA : l'outil de my-docs (tools/captures/) ne peut pas les ouvrir,
 * l'add-in ne s'affiche que dans Office. Ils se prennent à la main, puis ce script les recadre et y
 * pose les numéros, du même violet que l'outil. Voir README.md, « Les écrans de l'add-in ».
 *
 *   MY_DOCS=../../myDocs node docs/tech/captures/office-addin.mjs <connexion.png> <passerelle.png>
 *
 * Chaque source : la fenêtre de PowerPoint pour Mac à 1600 × 900 points, capturée seule en double
 * densité (`screencapture -o -l <fenêtre>`, 3200 × 1800 pixels), volet Claude ouvert à droite.
 * Si la fenêtre ou l'add-in changent, les coordonnées ci-dessous changent avec eux : relire chaque
 * image produite.
 */
import { createRequire } from 'node:module';
import { join, resolve } from 'node:path';

const myDocs = process.env.MY_DOCS;
if (!myDocs || process.argv.length < 4) {
  console.error('Usage : MY_DOCS=<clone de my-docs> node docs/tech/captures/office-addin.mjs <connexion.png> <passerelle.png>');
  process.exit(1);
}
// sharp vient de l'outil de captures de my-docs, déjà installé pour les autres captures.
const sharp = createRequire(join(resolve(myDocs), 'tools', 'captures', 'package.json'))('sharp');

const COULEUR = '#500182'; // les pastilles de tools/captures/capture.mjs
const DIAMETRE = 68;       // 34 points en double densité, comme l'outil

// Le ruban à partir de « Compléments » (sans la ligne des onglets, coupée), un bout de la
// diapositive, le volet en entier. Coordonnées en pixels de la fenêtre capturée.
const CADRE = { left: 1500, top: 130, width: 1700 };

// `x` : depuis le bord gauche du cadre ; `y` : depuis le haut de la fenêtre.
const IMAGES = [
  {
    source: process.argv[2],
    sortie: 'office-connexion.png',
    bas: 1500, // le volet s'arrête sous « Cloud provider or gateway »
    pastilles: [
      { n: 1, x: 1490, y: 205 },  // le bouton Claude du ruban
      { n: 2, x: 1032, y: 1408 }, // « Cloud provider or gateway »
    ],
  },
  {
    source: process.argv[3],
    sortie: 'office-passerelle.png',
    bas: 1800,
    pastilles: [
      { n: 1, x: 1256, y: 755 },  // « Gateway URL »
      { n: 2, x: 1162, y: 908 },  // « Token »
      { n: 3, x: 1250, y: 1594 }, // « Continue »
    ],
  },
];

const pastille = ({ n, x, y: yFenetre }) => {
  const y = yFenetre - CADRE.top;
  return `
  <circle cx="${x}" cy="${y}" r="${DIAMETRE / 2 - 3}" fill="${COULEUR}" stroke="#fff" stroke-width="6" filter="url(#ombre)"/>
  <text x="${x}" y="${y}" fill="#fff" font-family="Helvetica, Arial, sans-serif" font-weight="700"
        font-size="${DIAMETRE / 2}" text-anchor="middle" dominant-baseline="central">${n}</text>`;
};

const sortieDe = (nom) => join(resolve(import.meta.dirname, '..', '..', 'user', 'images'), nom);

for (const image of IMAGES) {
  const width = CADRE.width;
  const height = image.bas - CADRE.top;
  const calque = Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}">
    <defs><filter id="ombre" x="-50%" y="-50%" width="200%" height="200%">
      <feDropShadow dx="0" dy="4" stdDeviation="8" flood-color="#000" flood-opacity="0.35"/>
    </filter></defs>${image.pastilles.map(pastille).join('')}</svg>`);
  await sharp(resolve(image.source))
    .extract({ left: CADRE.left, top: CADRE.top, width, height })
    .composite([{ input: calque, top: 0, left: 0 }])
    .png({ palette: true, quality: 92, effort: 10, dither: 1 })
    .toFile(sortieDe(image.sortie));
  console.log(`✓ docs/user/images/${image.sortie} — ${width}×${height}, ${image.pastilles.length} repère(s)`);
}
