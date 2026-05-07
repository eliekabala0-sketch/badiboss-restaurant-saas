<?php

declare(strict_types=1);
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? config('app.name', 'Badiboss Restaurant SaaS')) ?></title>
    <style>
        :root {
            --bg: #0b0b0b;
            --bg-soft: #121212;
            --panel: #171717;
            --panel-2: #1d1d1d;
            --panel-3: #242424;
            --ink: #f5f2ea;
            --muted: #b9b1a3;
            --line: rgba(212, 175, 55, 0.14);
            --line-strong: rgba(212, 175, 55, 0.3);
            --brand: #d4af37;
            --brand-2: #8b0000;
            --brand-3: #d97706;
            --danger: #dc2626;
            --success: #15803d;
            --info: #2563eb;
            --neutral: #6b7280;
            --shadow: 0 26px 60px rgba(0, 0, 0, 0.42);
            --radius-xl: 24px;
            --radius-lg: 18px;
            --radius-md: 14px;
        }
        * { box-sizing: border-box; }
        html { color-scheme: dark; }
        body {
            margin: 0;
            font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(212, 175, 55, 0.12), transparent 26%),
                radial-gradient(circle at top right, rgba(139, 0, 0, 0.18), transparent 24%),
                radial-gradient(circle at bottom, rgba(212, 175, 55, 0.08), transparent 30%),
                linear-gradient(180deg, #080808 0%, #111111 45%, #0d0d0d 100%);
            color: var(--ink);
        }
        a {
            color: var(--brand);
        }
        h1, h2, h3, h4 {
            font-family: Georgia, "Times New Roman", serif;
            letter-spacing: 0.01em;
        }
        .shell {
            min-height: 100vh;
            padding: 34px 18px 48px;
        }
        .container {
            max-width: 1120px;
            margin: 0 auto;
        }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
            backdrop-filter: blur(8px);
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }
        .brand h1 {
            margin: 0;
            font-size: clamp(2rem, 3vw, 2.6rem);
            color: #fff8e7;
        }
        .brand p {
            margin: 8px 0 0;
            color: var(--muted);
            max-width: 760px;
            line-height: 1.55;
        }
        .grid {
            display: grid;
            gap: 20px;
        }
        .stats {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            margin-bottom: 24px;
        }
        .stat {
            position: relative;
            overflow: hidden;
            padding: 22px;
            background:
                linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01)),
                linear-gradient(135deg, rgba(212,175,55,0.08), rgba(139,0,0,0.12));
        }
        .stat::after {
            content: "";
            position: absolute;
            inset: auto -28px -28px auto;
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.18), transparent 70%);
        }
        .stat span {
            display: block;
            margin-bottom: 12px;
            color: var(--muted);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.14em;
        }
        .stat strong {
            display: block;
            position: relative;
            z-index: 1;
            font-size: clamp(2rem, 4vw, 2.7rem);
            color: var(--brand);
            line-height: 1;
        }
        .table-wrap {
            overflow-x: auto;
            border-radius: calc(var(--radius-lg) - 2px);
        }
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        th, td {
            padding: 16px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            text-align: left;
            vertical-align: top;
        }
        th {
            color: var(--muted);
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            background: rgba(255, 255, 255, 0.025);
            position: sticky;
            top: 0;
            z-index: 1;
        }
        tbody tr {
            background: transparent;
            transition: background 0.18s ease, transform 0.18s ease;
        }
        tbody tr:hover {
            background: rgba(212, 175, 55, 0.06);
        }
        .pill {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #f3ead0;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }
        .pill.badge-neutral {
            background: rgba(107, 114, 128, 0.16);
            border-color: rgba(107, 114, 128, 0.28);
            color: #d1d5db;
        }
        .pill.badge-waiting {
            background: rgba(107, 114, 128, 0.18);
            border-color: rgba(148, 163, 184, 0.3);
            color: #e5e7eb;
        }
        .pill.badge-progress {
            background: rgba(217, 119, 6, 0.18);
            border-color: rgba(217, 119, 6, 0.34);
            color: #fcd34d;
        }
        .pill.badge-ready {
            background: rgba(37, 99, 235, 0.18);
            border-color: rgba(59, 130, 246, 0.34);
            color: #bfdbfe;
        }
        .pill.badge-closed {
            background: rgba(21, 128, 61, 0.18);
            border-color: rgba(34, 197, 94, 0.3);
            color: #bbf7d0;
        }
        .pill.badge-urgent,
        .pill.badge-bad {
            background: rgba(139, 0, 0, 0.26);
            border-color: rgba(220, 38, 38, 0.38);
            color: #fecaca;
        }
        .pill.badge-gold,
        .pill.badge-off {
            background: rgba(212, 175, 55, 0.16);
            border-color: rgba(212, 175, 55, 0.34);
            color: #f5deb3;
        }
        .actions a, button, .nav a {
            display: inline-block;
            padding: 11px 16px;
            border: 1px solid transparent;
            border-radius: 999px;
            background: linear-gradient(135deg, #8b0000 0%, #5b0000 100%);
            color: #fff7ed;
            text-decoration: none;
            cursor: pointer;
            font: inherit;
            font-weight: 700;
            box-shadow: 0 10px 24px rgba(139, 0, 0, 0.26);
            transition: transform 0.16s ease, box-shadow 0.16s ease, background 0.16s ease, border-color 0.16s ease;
        }
        .actions a:hover, button:hover, .nav a:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.34);
        }
        button[name="workflow_stage"][value="EN_PREPARATION"],
        button[name="workflow_stage"][value="EN_COURS_TRAITEMENT"] {
            background: linear-gradient(135deg, #3b3b3b 0%, #272727 100%);
            border-color: rgba(255, 255, 255, 0.08);
            color: #f5f5f5;
            box-shadow: none;
        }
        button[name="status"][value="FOURNI_TOTAL"],
        button[name="workflow_stage"][value="PRET_A_SERVIR"] {
            background: linear-gradient(135deg, #d4af37 0%, #b8860b 100%);
            color: #171717;
            box-shadow: 0 10px 24px rgba(212, 175, 55, 0.24);
        }
        button[name="status"][value="FOURNI_PARTIEL"] {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            color: #fff8eb;
        }
        button[name="status"][value="NON_FOURNI"] {
            background: linear-gradient(135deg, #7f1d1d 0%, #450a0a 100%);
            color: #fee2e2;
        }
        form.auth {
            max-width: 540px;
            margin: 80px auto;
            padding: 28px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
        }
        input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid rgba(212, 175, 55, 0.18);
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            font: inherit;
            color: var(--ink);
            background: rgba(255, 255, 255, 0.045);
        }
        select, textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid rgba(212, 175, 55, 0.18);
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            background: rgba(255, 255, 255, 0.045);
            font: inherit;
            color: var(--ink);
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: rgba(212, 175, 55, 0.5);
            box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.12);
        }
        textarea { min-height: 100px; resize: vertical; }
        .nav {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 26px;
        }
        .context-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            padding: 16px 18px;
            margin-bottom: 18px;
            background:
                linear-gradient(120deg, rgba(212,175,55,0.12), rgba(139,0,0,0.14)),
                rgba(255,255,255,0.02);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
        }
        .context-identity {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }
        .context-logo {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            object-fit: cover;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(255,255,255,0.04);
            flex-shrink: 0;
        }
        .context-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .nav a {
            background: rgba(255,255,255,0.04);
            border-color: rgba(212, 175, 55, 0.12);
            color: #f8f5ee;
            box-shadow: none;
        }
        .nav a[href="/logout"] {
            background: linear-gradient(135deg, #3a3a3a 0%, #252525 100%);
            border-color: rgba(255, 255, 255, 0.08);
        }
        details.compact-card {
            border: 1px solid var(--line);
            border-radius: 16px;
            background: rgba(255,255,255,0.03);
            padding: 14px 16px;
        }
        details.compact-card > summary {
            cursor: pointer;
            list-style: none;
        }
        .quantity-stepper {
            display: inline-grid;
            grid-template-columns: 42px minmax(70px, 1fr) 42px;
            gap: 6px;
            align-items: center;
        }
        .quantity-stepper button {
            padding: 10px 0;
            text-align: center;
        }
        .quantity-stepper input {
            text-align: center;
            margin: 0;
        }
        .split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .muted { color: var(--muted); }
        .flash-ok {
            background: rgba(21, 128, 61, 0.14);
            border: 1px solid rgba(34, 197, 94, 0.34);
            color: #dcfce7;
            padding: 14px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 18px;
        }
        .flash-bad, .error {
            background: rgba(127, 29, 29, 0.22);
            border: 1px solid rgba(248, 113, 113, 0.36);
            color: #fee2e2;
            padding: 14px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 18px;
        }
        .flash-note {
            background: rgba(217, 119, 6, 0.16);
            border: 1px solid rgba(251, 191, 36, 0.3);
            color: #fde68a;
            padding: 14px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 18px;
        }
        .status-banner {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            padding: 18px 20px;
            margin-bottom: 20px;
            border-radius: var(--radius-lg);
            border: 1px solid rgba(255,255,255,0.08);
        }
        .status-banner strong {
            display: block;
            margin-bottom: 6px;
        }
        .status-banner.status-warning {
            background: rgba(217, 119, 6, 0.12);
            border-color: rgba(251, 191, 36, 0.26);
        }
        .status-banner.status-danger {
            background: rgba(127, 29, 29, 0.22);
            border-color: rgba(248, 113, 113, 0.3);
        }
        .section-stack {
            display: grid;
            gap: 18px;
        }
        .fold-card {
            padding: 0;
            overflow: hidden;
        }
        .fold-card summary {
            cursor: pointer;
            list-style: none;
            padding: 18px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
        }
        .fold-card summary::-webkit-details-marker {
            display: none;
        }
        .fold-body {
            padding: 0 22px 22px;
        }
        .compact-empty {
            padding: 14px 18px;
            border-radius: var(--radius-md);
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.08);
            color: var(--muted);
        }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }
        .toolbar-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .button-muted {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.08);
            color: #f5f2ea;
            box-shadow: none;
        }
        .color-field {
            display: grid;
            gap: 8px;
            margin-bottom: 16px;
        }
        .color-picker-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .color-picker-row input[type="color"] {
            width: 64px;
            min-width: 64px;
            height: 44px;
            margin-bottom: 0;
            padding: 4px;
            border-radius: 12px;
        }
        .color-chip {
            width: 22px;
            height: 22px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.16);
            box-shadow: inset 0 0 0 1px rgba(0,0,0,0.2);
        }
        .media-preview-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            margin: 10px 0 18px;
        }
        .media-preview {
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            background: rgba(255,255,255,0.03);
            overflow: hidden;
        }
        .media-preview img {
            display: block;
            width: 100%;
            height: 140px;
            object-fit: cover;
            background: rgba(255,255,255,0.04);
        }
        .media-preview small, .media-preview strong {
            display: block;
            padding: 10px 12px 0;
        }
        .media-preview .muted {
            padding: 0 12px 12px;
        }
        .brand-visual {
            position: relative;
            overflow: hidden;
            min-height: 220px;
            background-size: cover;
            background-position: center;
        }
        .brand-visual::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(8, 8, 8, 0.3), rgba(8, 8, 8, 0.82));
        }
        .brand-visual-body {
            position: relative;
            z-index: 1;
            display: flex;
            gap: 18px;
            align-items: center;
            padding: 22px;
        }
        .brand-visual-logo {
            width: 110px;
            height: 110px;
            border-radius: 26px;
            object-fit: cover;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.08);
            box-shadow: 0 20px 36px rgba(0, 0, 0, 0.28);
            flex-shrink: 0;
        }
        .brand-visual-copy {
            min-width: 0;
        }
        .brand-visual-copy p {
            max-width: 720px;
        }
        .menu-thumb {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .menu-thumb img {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            object-fit: cover;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            flex-shrink: 0;
        }
        .menu-preview-large {
            width: 100%;
            max-width: 180px;
            height: 140px;
            border-radius: 18px;
            object-fit: cover;
            display: block;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
        }
        .inline-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .inline-list label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 0;
            padding: 10px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }
        .link-box {
            display: grid;
            gap: 10px;
            padding: 14px 16px;
            border-radius: var(--radius-md);
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--line);
        }
        .repeat-list {
            display: grid;
            gap: 10px;
            margin-bottom: 14px;
        }
        .repeat-item {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .repeat-item input {
            margin-bottom: 0;
        }
        .repeat-item button {
            white-space: nowrap;
        }
        .role-panel {
            padding: 16px 18px;
            border-radius: var(--radius-md);
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.03);
        }
        .swatch-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .swatch-card {
            min-width: 100px;
            padding: 10px 12px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
        }
        .hidden {
            display: none !important;
        }
        details summary::-webkit-details-marker {
            display: none;
        }
        details[open] summary {
            color: #fff8e7;
        }
        section.card > div:first-child h2,
        article.card > h2:first-child {
            color: #fff8e7;
        }
        .brand .pill {
            margin-bottom: 10px;
        }
        .kpi-accent-red .stat strong,
        .kpi-accent-red strong.kpi-value {
            color: #f87171;
        }
        .kpi-accent-gold .stat strong,
        .kpi-accent-gold strong.kpi-value {
            color: var(--brand);
        }
        .kpi-accent-blue .stat strong,
        .kpi-accent-blue strong.kpi-value {
            color: #93c5fd;
        }
        .kpi-accent-green .stat strong,
        .kpi-accent-green strong.kpi-value {
            color: #86efac;
        }
        @media (max-width: 700px) {
            .topbar { flex-direction: column; align-items: flex-start; }
            .split { grid-template-columns: 1fr; }
            .shell { padding: 20px 12px 32px; }
            th, td { padding: 14px 12px; }
            .brand-visual-body { flex-direction: column; align-items: flex-start; }
            .card { border-radius: 18px; }
            details.compact-card { padding: 12px; }
            .toolbar-actions, .nav, .context-meta { width: 100%; }
            .actions a, button, .nav a { width: 100%; text-align: center; }
            .quantity-stepper { width: 100%; grid-template-columns: 40px minmax(64px, 1fr) 40px; }
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="container">
        <?php if (current_user() !== null): ?>
            <?php $restaurantNotice = flash('restaurant_notice'); ?>
            <?php if ((current_user()['scope'] ?? null) !== 'super_admin' && !empty($current_restaurant_context ?? null)): ?>
                <section class="context-bar">
                    <div class="context-identity">
                        <img src="<?= e(restaurant_media_url_or_default($current_restaurant_context['logo_url'] ?? null, 'logo')) ?>" alt="Logo restaurant" class="context-logo">
                        <div>
                        <strong><?= e($current_restaurant_context['public_name'] ?? $current_restaurant_context['name'] ?? current_user()['restaurant_name'] ?? 'Restaurant') ?></strong>
                        <div class="muted">
                            Code <?= e($current_restaurant_context['restaurant_code'] ?? current_user()['restaurant_code'] ?? '-') ?>
                            · /portal/<?= e($current_restaurant_context['slug'] ?? current_user()['restaurant_slug'] ?? '-') ?>
                            · <?= e(restaurant_role_label(current_user()['role_code'] ?? null)) ?>
                        </div>
                        </div>
                    </div>
                    <div class="context-meta">
                        <span class="pill badge-gold"><?= e(subscription_status_label($current_subscription_context['status'] ?? current_user()['subscription_status'] ?? null)) ?></span>
                        <span class="pill badge-off"><?= e(subscription_payment_label($current_subscription_context['payment_status'] ?? current_user()['subscription_payment_status'] ?? null)) ?></span>
                        <?php if (!empty($current_subscription_context['message'] ?? null)): ?>
                            <span class="pill badge-bad"><?= e($current_subscription_context['message']) ?></span>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
            <?php if (!empty($current_restaurant_context ?? null) && restaurant_status_blocks_operations($current_restaurant_context['status'] ?? null)): ?>
                <section class="status-banner status-<?= e(restaurant_status_severity($current_restaurant_context['status'] ?? null)) ?>">
                    <div>
                        <strong><?= e(status_label($current_restaurant_context['status'] ?? null)) ?></strong>
                        <div><?= e(restaurant_status_message($current_restaurant_context['status'] ?? null) ?? 'L’accès à ce restaurant est limité.') ?></div>
                    </div>
                    <span class="pill <?= restaurant_status_severity($current_restaurant_context['status'] ?? null) === 'danger' ? 'badge-bad' : 'badge-progress' ?>">
                        <?= e($current_restaurant_context['public_name'] ?? $current_restaurant_context['name'] ?? 'Restaurant') ?>
                    </span>
                </section>
            <?php endif; ?>
            <?php if (!empty($restaurantNotice)): ?><div class="flash-note"><?= e($restaurantNotice) ?></div><?php endif; ?>
            <nav class="nav">
                <?php if ((current_user()['scope'] ?? null) === 'super_admin'): ?>
                    <?php if (can_access('platform.admin.view')): ?><a href="/super-admin">Tableau de bord</a><?php endif; ?>
                    <?php if (can_access('platform.restaurants.manage')): ?><a href="/super-admin/restaurants">Restaurants</a><?php endif; ?>
                    <?php if (can_access('platform.users.manage')): ?><a href="/super-admin/users">Utilisateurs</a><?php endif; ?>
                    <?php if (can_access('platform.restaurants.manage')): ?><a href="/super-admin/menu">Menu</a><?php endif; ?>
                    <?php if (can_access('platform.settings.manage')): ?><a href="/super-admin/settings">Paramètres</a><?php endif; ?>
                    <?php if (can_access('platform.audit.view')): ?><a href="/super-admin/audit">Journal d’audit</a><?php endif; ?>
                <?php else: ?>
                    <?php if (can_access('tenant.dashboard.view')): ?><a href="/owner">Tableau de bord</a><?php endif; ?>
                    <?php if (can_access('menu.view')): ?><a href="/owner/menu">Menu</a><?php endif; ?>
                    <?php if (can_access('tenant.access.manage')): ?><a href="/owner/access">Rôles et accès</a><?php endif; ?>
                    <?php if (can_access('stock.view')): ?><a href="/stock">Stock</a><?php endif; ?>
                    <?php if (can_access('kitchen.view')): ?><a href="/cuisine">Cuisine</a><?php endif; ?>
                    <?php if (can_access('sales.view')): ?><a href="/ventes">Ventes</a><?php endif; ?>
                    <?php if (can_access('cash.view')): ?><a href="/caisse">Caisse</a><?php endif; ?>
                    <?php if (can_access('reports.view')): ?><a href="/rapport">Rapports</a><?php endif; ?>
                <?php endif; ?>
                <a href="/logout">Déconnexion</a>
            </nav>
        <?php endif; ?>
        <?php require $viewFile; ?>
    </div>
</div>
<script>
document.querySelectorAll('[data-history-toggle]').forEach(function (button) {
    var syncLabel = function () {
        var groupId = button.getAttribute('data-history-toggle');
        var hiddenRows = document.querySelectorAll('[data-history-group="' + groupId + '"].history-extra');
        var expanded = button.getAttribute('data-expanded') === '1';
        var hiddenCount = hiddenRows.length;

        if (expanded) {
            button.textContent = 'Voir moins';
            return;
        }

        button.textContent = hiddenCount > 0 ? 'Voir plus (' + hiddenCount + ')' : 'Voir plus';
    };

    syncLabel();

    button.addEventListener('click', function () {
        var groupId = button.getAttribute('data-history-toggle');
        var hiddenRows = document.querySelectorAll('[data-history-group="' + groupId + '"].history-extra');
        var expanded = button.getAttribute('data-expanded') === '1';

        hiddenRows.forEach(function (row) {
            row.style.display = expanded ? 'none' : '';
        });

        button.setAttribute('data-expanded', expanded ? '0' : '1');
        syncLabel();
    });
});

document.querySelectorAll('[data-copy-target]').forEach(function (button) {
    button.addEventListener('click', function () {
        var targetSelector = button.getAttribute('data-copy-target');
        var target = document.querySelector(targetSelector);
        if (!target) {
            return;
        }

        var text = target.getAttribute('data-copy-value') || target.textContent || '';
        navigator.clipboard.writeText(text.trim()).then(function () {
            var originalText = button.textContent;
            button.textContent = 'Lien copié';
            window.setTimeout(function () {
                button.textContent = originalText;
            }, 1800);
        });
    });
});

document.querySelectorAll('[data-file-preview-input]').forEach(function (input) {
    input.addEventListener('change', function () {
        var previewId = input.getAttribute('data-file-preview-input');
        var img = document.querySelector('[data-file-preview-image="' + previewId + '"]');
        var nameBox = document.querySelector('[data-file-preview-name="' + previewId + '"]');
        if (!img || !input.files || !input.files[0]) {
            return;
        }

        var reader = new FileReader();
        reader.onload = function (event) {
            img.src = String(event.target.result);
            img.classList.remove('hidden');
            if (nameBox) {
                nameBox.textContent = input.files[0].name;
            }
        };
        reader.readAsDataURL(input.files[0]);
    });
});

document.querySelectorAll('[data-repeat-list]').forEach(function (list) {
    var addButton = document.querySelector('[data-repeat-add="' + list.getAttribute('data-repeat-list') + '"]');
    if (!addButton) {
        return;
    }

    addButton.addEventListener('click', function () {
        var template = list.querySelector('[data-repeat-template]');
        if (!template) {
            return;
        }

        var clone = template.cloneNode(true);
        clone.removeAttribute('data-repeat-template');
        clone.querySelectorAll('input').forEach(function (input) {
            input.value = '';
        });
        list.appendChild(clone);
    });
});

document.addEventListener('click', function (event) {
    var removeButton = event.target.closest('[data-repeat-remove]');
    if (!removeButton) {
        return;
    }

    var item = removeButton.closest('.repeat-item');
    var list = removeButton.closest('[data-repeat-list]');
    if (!item || !list) {
        return;
    }

    if (list.querySelectorAll('.repeat-item').length <= 1) {
        item.querySelectorAll('input').forEach(function (input) {
            input.value = '';
        });
        return;
    }

    item.remove();
});

document.addEventListener('click', function (event) {
    var stepButton = event.target.closest('[data-stepper-minus], [data-stepper-plus]');
    if (!stepButton) {
        return;
    }

    var stepper = stepButton.closest('[data-quantity-stepper]');
    var input = stepper ? stepper.querySelector('input') : null;
    if (!stepper || !input) {
        return;
    }

    var min = function () {
        var raw = input.getAttribute('min');
        return raw === null || raw === '' ? 0 : Number(raw);
    };
    var step = function () {
        var raw = input.getAttribute('step');
        return raw === null || raw === '' ? 1 : Number(raw);
    };

    if (stepButton.matches('[data-stepper-minus]')) {
        var current = input.value === '' ? min() : Number(input.value);
        input.value = String(Math.max(min(), current - step()));
        input.dispatchEvent(new Event('input', { bubbles: true }));
        return;
    }

    if (stepButton.matches('[data-stepper-plus]')) {
        var current = input.value === '' ? 0 : Number(input.value);
        input.value = String(current + step());
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }
});

document.querySelectorAll('[data-autoclose-details]').forEach(function (details) {
    /* Ne pas forcer la fermeture : respecter l'attribut HTML `open` (file cuisine, refus, etc.). */
    details.addEventListener('toggle', function () {
        if (!details.open) {
            return;
        }

        document.querySelectorAll('[data-autoclose-details]').forEach(function (other) {
            if (other !== details) {
                other.open = false;
            }
        });
    });
});
</script>
</body>
</html>
