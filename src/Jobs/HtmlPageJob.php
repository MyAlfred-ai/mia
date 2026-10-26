<?php

declare(strict_types=1);

/**
 * Outil « Page HTML » / « Page HTML illustrée », en tâche de fond.
 *
 * Corps de l'ancien `ChatController::generateHtml()` après validation. Le
 * contrôleur ne fait plus qu'enregistrer le message utilisateur et enfiler le
 * job ; toute la génération (LLM + illustrations + facturation) se passe ici,
 * hors de la requête HTTP — d'où la possibilité de changer de conversation ou
 * de page pendant qu'une page se construit.
 *
 * Les droits sont RE-vérifiés à l'exécution (l'outil a pu être désactivé ou
 * retiré du groupe entre l'enfilage et le démarrage).
 */
class HtmlPageJob implements JobHandler
{
    private const FALLBACK_HTML = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Page HTML</title></head><body><main style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:24px;line-height:1.5"><h1 style="font-size:1.2rem;margin:0 0 12px">Aucun HTML reçu</h1><p style="margin:0;color:#444">Le modèle n\'a pas renvoyé de contenu HTML exploitable. Réessayez avec une consigne plus précise.</p></main></body></html>';

    public function run(array $payload, JobContext $ctx): array
    {
        $convId = (int)$ctx->conversationId();
        $userId = $ctx->userId();
        $prompt = trim((string)($payload['prompt'] ?? ''));

        $user = User::findById($userId);
        if (!$user || $prompt === '') {
            throw new \RuntimeException('Contexte de job HTML incomplet');
        }

        $scope    = UserGroup::membershipScopeForUser($userId);
        $groupIds = $scope['active_group_ids'];
        $hasAssignments = $scope['has_assignments'];

        $tool = ChatTool::findActiveByIdForUser(
            (int)($payload['tool_id'] ?? 0),
            (string)$user['role'],
            $groupIds,
            $hasAssignments
        );
        if (!$tool || !in_array($tool['type'], ['html', 'html_illustrated'], true)) {
            throw new \RuntimeException('Outil HTML introuvable ou non autorisé');
        }

        $config       = json_decode($tool['config_json'] ?? '{}', true) ?: [];
        $systemPrompt = $this->buildSystemPrompt($config, (string)$tool['type']);
        $temperature  = isset($config['temperature']) ? (float)$config['temperature'] : null;

        // --- Outil illustré : outil image + plafond + style graphique ---
        $isIllustrated = ($tool['type'] === 'html_illustrated');
        $imageTool  = null;
        $maxImages  = 0;
        $imageStyle = '';
        if ($isIllustrated) {
            $maxImages  = max(1, min(6, (int)($config['max_images'] ?? 3)));
            $imageStyle = $this->resolveImageStyle((string)($payload['image_style'] ?? ''), $config);
            $imageToolId = !empty($payload['image_tool_id'])
                ? (int)$payload['image_tool_id']
                : (int)($config['image_tool_id'] ?? 0);
            if ($imageToolId > 0) {
                $candidate = ChatTool::findActiveByIdForUser($imageToolId, (string)$user['role'], $groupIds, $hasAssignments);
                if ($candidate && ($candidate['type'] ?? '') === 'image' && (int)($candidate['last_test_ok'] ?? 1) !== 0
                    && !ChatTool::isRetired($candidate)) {
                    $imageTool = $candidate;
                }
            }
            $systemPrompt .= $this->illustrationDirective($maxImages, $imageStyle);
        }

        // --- Page existante : base de travail des demandes de modification ---
        // Brique « Création » d'un workflow : la base est la page du dossier
        // qu'elle lit (ou sa propre version précédente), jamais la dernière
        // page de la conversation — une autre brique a pu en produire une.
        $workflow = is_array($payload['workflow'] ?? null) ? $payload['workflow'] : null;
        if ($workflow !== null) {
            $systemPrompt .= "\n\nIMAGES DU DOSSIER : les balises <img src=\"/assets/img/generated/…\"> fournies dans les données sont autorisées — insère-les telles quelles (style au choix) là où elles servent la page.";
        }
        $previousHtml   = $workflow !== null
            ? WorkflowCreations::basePage($workflow, $userId)
            : GeneratedHtml::latestForConversation($convId);
        $isModification = ($previousHtml !== null && trim($previousHtml) !== '');
        $llmMessages    = [['role' => 'user', 'content' => $prompt]];
        if ($isModification) {
            $systemPrompt .= self::modificationDirective();

            $llmMessages = [
                ['role' => 'user', 'content' => "PAGE_HTML_EXISTANTE:\n" . $previousHtml],
                ['role' => 'user', 'content' => "DEMANDE_UTILISATEUR:\n" . $prompt],
            ];
        }

        $adapter = LLMFactory::make($tool['provider'] . '::' . $tool['model']);

        // --- Génération ---
        $ctx->setPhase('html');
        $fullResponse = '';
        // Page coupée en route : panne du fournisseur arrivée après le début
        // de la rédaction, ou arrêt anormal du modèle (longueur maximale).
        $interruption = null;

        // Le worker n'a pas de connexion HTTP : sans cette sonde, le bouton
        // Stop resterait sans effet (cf. LlmAdapterHelpers::setStopProbe).
        LlmAdapterHelpers::setStopProbe(static fn(): bool => $ctx->shouldStop());
        try {
            $tokens = $adapter->stream(
                $llmMessages,
                $systemPrompt,
                function (string $chunk) use (&$fullResponse, &$interruption, $ctx): void {
                    // Une erreur arrivée APRÈS le début de la page n'entre pas
                    // dans le document : elle s'y afficherait en toutes
                    // lettres, entre crochets, à la suite du HTML.
                    if ($fullResponse !== '') {
                        $broken = LlmErrorPresenter::parseMidStream($chunk);
                        if ($broken !== null) {
                            $interruption ??= $broken;
                            return;
                        }
                    }
                    $fullResponse .= $chunk;
                    $ctx->emit(['chunk' => $chunk]);
                },
                $temperature
            );
        } finally {
            LlmAdapterHelpers::setStopProbe(null);
        }
        $interruption ??= LlmErrorPresenter::stopDescriptor($tokens, (string)$tool['provider']);

        // Les adapters n'émettent pas d'exception : une panne d'API arrive sous
        // forme de chunk texte (« [Erreur OpenAI (503) : …] »). Sans ce filtre
        // elle finirait sanitisée en page HTML illisible, sans explication.
        $desc = LlmErrorPresenter::parse($fullResponse);
        if ($desc !== null) {
            throw new JobErrorException(LlmErrorPresenter::present($desc), 'flux LLM en erreur');
        }

        // Mode modification : le résumé des ajustements voyage après </html>
        // (marqueur RESUME_MODIFICATIONS) — extrait AVANT le nettoyage, sinon
        // il serait perdu avec le reste du texte hors document.
        [$fullResponse, $changeSummary] = GeneratedHtml::splitSummary($fullResponse);

        // Nettoyage strict : conserver uniquement un HTML affichable.
        $html = GeneratedHtml::sanitize($fullResponse);
        if (trim($fullResponse) === '') {
            $html = ($previousHtml !== null && trim($previousHtml) !== '')
                ? GeneratedHtml::sanitize($previousHtml)
                : self::FALLBACK_HTML;
        }

        // --- Illustrations : remplit les <img data-ai-image> ---
        $producedImages = [];
        if ($isIllustrated && stripos($html, 'data-ai-image') !== false) {
            $ctx->setPhase('images');
            // $imageTool peut être null (outil image non résolu) : les placeholders
            // sont alors tous convertis en visuel de secours.
            $illustration = HtmlIllustrator::run(
                $html,
                $imageTool,
                $maxImages,
                $imageStyle,
                function (int $imgDone, int $imgTotal) use ($ctx): void {
                    $ctx->emit(['image_progress' => ['done' => $imgDone, 'total' => $imgTotal]]);
                }
            );
            $html           = $illustration['html'];
            $producedImages = $illustration['images'];
        }

        // --- Persistance + facturation ---
        $conv = Conversation::findById($convId);
        $msg  = Message::create(
            $convId, 'assistant', $html,
            [['type' => 'html_page', 'name' => 'page.html']],
            !empty($tool['provider']) ? (string)$tool['provider'] : null,
            !empty($tool['model'])    ? (string)$tool['model']    : null
        );

        // Itération sur une page existante : court résumé des ajustements,
        // persisté comme message texte APRÈS la carte de page — visible en
        // direct (payload done) comme au rechargement. Pas de résumé si le
        // modèle n'a rien rendu (la page re-servie est l'ANCIENNE : affirmer
        // « mise à jour » serait mensonger).
        $summaryText = null;
        $summaryMsg  = null;
        if ($interruption !== null && trim($fullResponse) !== '' && !$ctx->shouldStop()) {
            // Page INCOMPLÈTE : le dire sous la carte, à la place du résumé —
            // affirmer « page mise à jour » serait mensonger, et une page
            // coupée sans explication passe pour un défaut de l'outil.
            $summaryText = LlmErrorPresenter::toPlainText(self::incompleteNotice($interruption));
            $summaryMsg  = Message::create($convId, 'assistant', $summaryText);
        } elseif ($isModification && trim($fullResponse) !== '') {
            $summaryText = $changeSummary ?? 'Page mise à jour selon votre demande.';
            $summaryMsg  = Message::create(
                $convId, 'assistant', $summaryText, [],
                !empty($tool['provider']) ? (string)$tool['provider'] : null,
                !empty($tool['model'])    ? (string)$tool['model']    : null
            );
        }

        // Grand livre : une ligne par image produite (paramètres réels du
        // rendu), puis les tokens de la rédaction (source html_tool) et le
        // compteur d'usage de l'outil. Tout rattaché au message de la page.
        $billingCtx = [
            'user_id'         => $userId,
            'conversation_id' => $convId,
            'profile_id'      => $conv['profile_id'] ?? null,
            'message_id'      => (int)$msg['id'],
        ];
        $imageCreditsTotal = 0;
        foreach ($imageTool !== null ? $producedImages : [] as $params) {
            $imageCreditsTotal += (int)UsageMeter::tool($imageTool, $params, $billingCtx)['total_credits'];
        }

        $llmKey       = (string)$tool['provider'] . '::' . (string)$tool['model'];
        $creditResult = UsageMeter::llm($llmKey, $tokens, UsageSource::HTML_TOOL, $billingCtx);
        UsageMeter::toolUse($tool, [
            'model'      => (string)$tool['model'],
            'tokens_in'  => (int)($tokens['input']  ?? 0),
            'tokens_out' => (int)($tokens['output'] ?? 0),
        ], $billingCtx);

        $done = [
            'done'    => true,
            'msg_id'  => (int)$msg['id'],
            'credits' => (int)$creditResult['total_credits'] + $imageCreditsTotal,
            'illustrated' => $isIllustrated,
            'summary'        => $summaryText,
            'summary_msg_id' => $summaryMsg ? (int)$summaryMsg['id'] : null,
            'quota_status' => CreditQuota::status($user),
            'conversation_credits_total' => UsageLog::conversationCreditsTotal($convId, $userId),
        ];
        $ctx->emit($done);

        // Re-servi tel quel à un client qui s'attache APRÈS la fin du job.
        return $done;
    }

    /**
     * Avis posé sous une page dont la rédaction s'est arrêtée avant la fin :
     * titre + message, au format du présentateur d'erreurs. Statique pure.
     *
     * Le plafond de sortie est la cause la plus courante (une page complète
     * dépasse vite la longueur maximale de certains modèles) : elle a sa
     * propre phrase, avec un remède. Les autres causes reprennent le message
     * du présentateur — panne, surcharge, filtre.
     *
     * @param array{kind?:string,provider?:?string} $interruption
     * @return array{title:string, message:string}
     */
    public static function incompleteNotice(array $interruption): array
    {
        $message = ($interruption['kind'] ?? '') === 'truncated'
            ? t("Le modèle a atteint sa longueur maximale de réponse avant d'avoir terminé la page : la fin manque. Demandez une page plus courte ou plus simple, ou utilisez un outil de page basé sur un autre modèle.")
            : t("La rédaction de la page s'est arrêtée avant la fin.") . ' '
                . (string)(LlmErrorPresenter::present($interruption + ['kind' => 'generic', 'provider' => null, 'status' => null])['message'] ?? '');

        return ['title' => t('Page incomplète'), 'message' => trim($message)];
    }

    /**
     * Directive d'itération : une page existe déjà dans la conversation — le
     * modèle repart d'elle, ne touche que ce que la demande vise, et rend
     * compte de ses ajustements via SUMMARY_MARKER (extrait par splitSummary,
     * persisté en message de chat). Statique pure — testée sans exécuter le job.
     */
    public static function modificationDirective(): string
    {
        return "\n\nMODE MODIFICATION — une page HTML existante est fournie (PAGE_HTML_EXISTANTE) : c'est ta base de travail, ne repars JAMAIS de zéro (sauf si la demande exige explicitement une page entièrement nouvelle)."
            . "\nApplique uniquement les changements demandés (DEMANDE_UTILISATEUR) : conserve à l'identique la structure, les styles, les textes, les scripts et les images (balises <img> et leurs src actuels) que la demande ne vise pas."
            . "\nNe supprime jamais une section non mentionnée et rends toujours un document complet, de <!DOCTYPE html> à </html>."
            . "\nAPRÈS la balise de fermeture </html>, ajoute une dernière ligne, exactement au format suivant :"
            . "\n" . GeneratedHtml::SUMMARY_MARKER . " <résumé factuel en 1 à 3 phrases des ajustements effectués>"
            . "\nCe résumé est le SEUL texte autorisé hors du document HTML.";
    }

    /**
     * Partie éditoriale (admin, ou défaut de ToolPrompts) puis contrat de
     * sortie, jamais éditable : sans lui la « page » revient en prose et
     * l'aperçu n'affiche rien.
     */
    private function buildSystemPrompt(array $config, string $type): string
    {
        return ToolPrompts::resolve($type, $config)
            . "\n\nRÈGLE ABSOLUE: réponds uniquement avec du HTML exécutable, sans aucun texte explicatif, sans markdown et sans balises ```."
            . "\nLa page finale doit être responsive et contenir la meta viewport (<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">)."
            . "\nContrainte de rendu: la page doit s'afficher proprement dans un panneau latéral étroit (environ 360 à 700px) sans débordement horizontal."
            . "\nQualité visuelle attendue: hiérarchie claire, typographie soignée, espacements cohérents, composants harmonisés, contrastes lisibles."
            . "\nRESSOURCES (important): privilégie une page 100% autonome — CSS et JS inline, icônes en SVG inline — c'est le rendu le plus fiable et portable. Si tu utilises un framework CSS ou une police web, SEULS ces CDN sont accessibles dans l'aperçu : Tailwind (cdn.tailwindcss.com), jsDelivr (cdn.jsdelivr.net), cdnjs (cdnjs.cloudflare.com), unpkg (unpkg.com) et Google Fonts (fonts.googleapis.com / fonts.gstatic.com). Tout autre hôte externe est bloqué, de même que fetch/XHR/WebSocket et l'envoi de formulaires. Les images doivent rester en SVG inline / dégradés CSS / data-URI (ou les balises data-ai-image prévues) — pas d'image par URL http(s) externe."
            . "\nCSS: pas de largeurs fixes non responsives, limiter les éléments full-screen en position fixed."
            . "\nLANGUE : rédige les textes de la page en " . I18n::promptLanguage() . " (la langue choisie par l'utilisateur), "
            . "sauf si sa demande est écrite dans une autre langue ou en réclame une autre.";
    }

    /**
     * « Aucun » choisi au chat (`__none__`) écrase le défaut admin ; pas de
     * choix au chat (chaîne vide) retombe dessus.
     */
    private function resolveImageStyle(string $fromChat, array $config): string
    {
        $imageStyle = trim($fromChat);
        if ($imageStyle === '__none__') {
            $imageStyle = '';
        } elseif ($imageStyle === '') {
            $imageStyle = trim((string)($config['image_style'] ?? ''));
        }
        return mb_strlen($imageStyle) > 200 ? mb_substr($imageStyle, 0, 200) : $imageStyle;
    }

    private function illustrationDirective(int $maxImages, string $imageStyle): string
    {
        $directive = "\n\nIMAGES À GÉNÉRER (impératif): aux emplacements visuels pertinents (héro, sections, illustrations), insère des balises <img> portant un attribut data-ai-image qui décrit précisément l'image à produire (sujet, style, ambiance, couleurs), un attribut alt, et un style CSS de placement (width/height/object-fit). Laisse le src VIDE — il sera rempli automatiquement par une image générée. Exemple: <img data-ai-image=\"façade de boulangerie artisanale au lever du soleil, vitrine garnie, photo réaliste chaleureuse\" alt=\"Notre boulangerie\" style=\"width:100%;height:260px;object-fit:cover;border-radius:12px\">. Maximum " . $maxImages . " balises data-ai-image, aux endroits les plus utiles. Cette consigne prime sur toute règle d'image inline.";
        if ($imageStyle !== '') {
            $directive .= "\nStyle graphique souhaité pour TOUTES les images : " . $imageStyle . ". Décris chaque data-ai-image dans ce style.";
        }
        return $directive;
    }
}
