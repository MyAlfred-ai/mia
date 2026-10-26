# Politique de sécurité

## Signaler une vulnérabilité

Ne publiez pas de vulnérabilité dans une issue publique.

Utilisez le signalement privé de GitHub : onglet **Security**, puis **Report a vulnerability**. Le signalement n'est visible que des mainteneurs de MIA.

Indiquez si possible :
- la version concernée (`VERSION` ou tag d'image) ;
- les étapes pour reproduire ;
- l'impact que vous avez constaté.

## Délais

- Accusé de réception : sous 5 jours ouvrés.
- Évaluation et plan de correction : sous 15 jours ouvrés.
- Publication d'un correctif, puis d'un avis de sécurité une fois les installations connues mises à jour.

## Versions prises en charge

Seule la dernière version mineure publiée reçoit les correctifs de sécurité.

## Vérifier une image

Les images publiées sont signées avec cosign (signature sans clé, GitHub OIDC). Le script `install/update.sh` vérifie la signature avant de démarrer une nouvelle version.
