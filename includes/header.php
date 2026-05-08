<?php
require_once __DIR__ . '/../includes/auth_helper.php';
checkLogin();
$assetBase = strpos($_SERVER['REQUEST_URI'], '/ustadz/') !== false ? '../' : '';
?>
<!DOCTYPE html>
<html lang="id" x-data="{ sidebarOpen: false, aboutOpen: false, canInstall: false }"
    @pwa-install-available.window="canInstall = true" @pwa-installed.window="canInstall = false">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo $pageTitle ?? 'Dashboard'; ?> - Presensi Tahsin
    </title>
    <link rel="manifest" href="<?php echo $assetBase; ?>manifest.json">
    <meta name="theme-color" content="#2563eb">
    <link rel="apple-touch-icon" href="<?php echo $assetBase; ?>icon-512.png">
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/app.css">
    <script defer src="<?php echo $assetBase; ?>assets/js/alpine.min.js"></script>
    <script>
        // Use a dynamic base path for assets based on whether we are in a subdirectory
        const swPath = '<?php echo $assetBase; ?>sw.js';
        const manifestPath = '<?php echo $assetBase; ?>manifest.json';

        // Update manifest link dynamically if needed (already set in HTML but good to be sure)
        document.querySelector('link[rel="manifest"]').setAttribute('href', manifestPath);

        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register(swPath)
                    .then(reg => console.log('SW Registered', reg))
                    .catch(err => console.log('SW Register Error', err));
            });
        }

        // PWA Install Logic
        window.deferredPrompt = null;
        window.addEventListener('beforeinstallprompt', (e) => {
            // Prevent the mini-infobar from appearing on mobile
            e.preventDefault();
            // Stash the event so it can be triggered later.
            window.deferredPrompt = e;
            // Update UI to show the install button
            window.dispatchEvent(new CustomEvent('pwa-install-available'));
        });

        async function installPWA() {
            if (window.deferredPrompt) {
                window.deferredPrompt.prompt();
                const {
                    outcome
                } = await window.deferredPrompt.userChoice;
                if (outcome === 'accepted') {
                    console.log('User accepted the install prompt');
                } else {
                    console.log('User dismissed the install prompt');
                }
                window.deferredPrompt = null;
                window.dispatchEvent(new CustomEvent('pwa-installed'));
            }
        }
    </script>
    <style>
        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        [x-cloak] {
            display: none !important;
        }
    </style>
</head>

<body class="bg-slate-50 text-slate-800">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar and Content will continue here -->
