<?php
/**
 * Habillage commun des pages d'erreur d'infrastructure.
 *
 * CSS entièrement en ligne, aucune feuille externe, AUCUN appel à la base :
 * ces pages s'affichent précisément quand main.css, Settings::theme() ou MySQL
 * ne répondent pas. Le thème est figé (sombre) pour la même raison — le lire en
 * base rejouerait la panne qu'on est en train d'afficher.
 */
?>
<style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #0d0d14;
        color: #e2e8f0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem 1rem;
        line-height: 1.55;
    }

    .err-card {
        background: #161625;
        border: 1px solid #2d2d4e;
        border-radius: 16px;
        padding: 2.5rem;
        width: 100%;
        max-width: 680px;
        box-shadow: 0 24px 64px rgba(0, 0, 0, .6);
    }

    .err-badge {
        display: inline-block;
        font-size: .75rem;
        font-weight: 600;
        letter-spacing: .06em;
        text-transform: uppercase;
        padding: .25rem .6rem;
        border-radius: 999px;
        background: rgba(251, 191, 36, .12);
        color: #fbbf24;
        border: 1px solid rgba(251, 191, 36, .3);
        margin-bottom: 1rem;
    }

    .err-card h1 {
        font-size: 1.5rem;
        font-weight: 700;
        color: #a78bfa;
        margin-bottom: .75rem;
    }

    .err-lead { color: #cbd5e1; margin-bottom: 1.5rem; }

    .err-item {
        border-left: 3px solid #a78bfa;
        padding: .75rem 0 .75rem 1rem;
        margin-bottom: 1rem;
        background: rgba(167, 139, 250, .05);
        border-radius: 0 8px 8px 0;
    }

    .err-item strong { color: #e2e8f0; }
    .err-item p { color: #94a3b8; font-size: .925rem; margin-top: .25rem; }

    code, .err-code {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: .875rem;
        background: #0d0d14;
        border: 1px solid #2d2d4e;
        border-radius: 6px;
        padding: .1rem .4rem;
        color: #7dd3fc;
        word-break: break-all;
    }

    .err-block {
        display: block;
        padding: .75rem 1rem;
        margin-top: .5rem;
        white-space: pre-wrap;
        overflow-x: auto;
        color: #7dd3fc;
    }

    .err-foot {
        margin-top: 1.75rem;
        padding-top: 1.25rem;
        border-top: 1px solid #2d2d4e;
        font-size: .875rem;
        color: #64748b;
    }

    .err-foot a { color: #a78bfa; }

    details { margin-top: 1.25rem; }
    summary { cursor: pointer; color: #94a3b8; font-size: .875rem; }
    details pre {
        margin-top: .75rem;
        padding: 1rem;
        background: #0d0d14;
        border: 1px solid #2d2d4e;
        border-radius: 8px;
        overflow-x: auto;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: .8rem;
        color: #cbd5e1;
        white-space: pre-wrap;
    }
</style>
