<?php
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

$error = '';
$warning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($username && $password) {
        // Rate Limiting: Cek apakah login diblokir
        $rate_check = checkLoginAttempts($pdo, $username, $client_ip);

        if ($rate_check['blocked']) {
            $error = "Terlalu banyak percobaan login gagal. Coba lagi dalam {$rate_check['retry_after_minutes']} menit.";
            addLog($pdo, 'LOGIN_BLOCKED', "Login diblokir untuk user '{$username}' dari IP {$client_ip} (rate limit).");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Login berhasil: hapus catatan percobaan gagal
                clearLoginAttempts($pdo, $username, $client_ip);

                // Security: Regenerate session ID on login
                regenerateSession($remember);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                $_SESSION['role'] = $user['role'];

                addLog($pdo, 'LOGIN', "User {$_SESSION['username']} berhasil masuk.");

                if ($user['role'] === 'ustadz') {
                    header("Location: ustadz/dashboard.php");
                } else {
                    header("Location: dashboard.php");
                }
                exit();
            } else {
                // Login gagal: catat percobaan
                recordFailedLogin($pdo, $username, $client_ip);

                $remaining = $rate_check['remaining_attempts'] - 1;
                $error = "Username atau password salah!";

                if ($remaining <= 2 && $remaining > 0) {
                    $warning = "Peringatan: Sisa {$remaining} percobaan sebelum akun dikunci sementara.";
                } elseif ($remaining <= 0) {
                    $warning = "Akun dikunci selama " . LOGIN_LOCKOUT_MINUTES . " menit karena terlalu banyak percobaan gagal.";
                }

                addLog($pdo, 'LOGIN_FAILED', "Login gagal untuk user '{$username}' dari IP {$client_ip}. Sisa percobaan: {$remaining}.");
            }
        }
    } else {
        $error = "Silakan isi semua bidang!";
    }
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'ustadz') {
        header("Location: ustadz/dashboard.php");
    } else {
        header("Location: dashboard.php");
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="id" x-data="{ canInstall: false, showPass: false, loading: false }"
    @pwa-install-available.window="canInstall = true" @pwa-installed.window="canInstall = false">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Presensi Tahsin</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#2563eb">
    <link rel="apple-touch-icon" href="icon-512.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js')
                    .then(reg => console.log('SW Registered', reg))
                    .catch(err => console.log('SW Register Error', err));
            });
        }

        // PWA Install Logic
        window.deferredPrompt = null;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            window.deferredPrompt = e;
            window.dispatchEvent(new CustomEvent('pwa-install-available'));
        });

        async function installPWA() {
            if (window.deferredPrompt) {
                window.deferredPrompt.prompt();
                const {
                    outcome
                } = await window.deferredPrompt.userChoice;
                window.deferredPrompt = null;
                window.dispatchEvent(new CustomEvent('pwa-installed'));
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap');

        body {
            font-family: 'Outfit', sans-serif;
            background: radial-gradient(circle at top right, #3b82f6 0%, transparent 40%),
                radial-gradient(circle at bottom left, #6366f1 0%, transparent 40%),
                #f8fafc;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.4);
        }

        .loader-dots div {
            animation-timing-function: cubic-bezier(0, 1, 1, 0);
        }
    </style>
</head>

<body
    class="flex items-center justify-center min-h-screen p-4 sm:p-6 bg-slate-50 relative overflow-x-hidden antialiased">
    <!-- Fixed background layer to prevent overflow -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div
            class="absolute top-[-10%] left-[-10%] w-[60%] sm:w-[40%] h-[40%] bg-blue-100/60 rounded-full blur-[80px] sm:blur-[120px] animate-pulse">
        </div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[60%] sm:w-[40%] h-[40%] bg-indigo-100/60 rounded-full blur-[80px] sm:blur-[120px] animate-pulse"
            style="animation-delay: 2s"></div>
        <!-- Additional gradient overlay for smoothness -->
        <div class="absolute inset-0 bg-gradient-to-tr from-slate-50/50 via-transparent to-blue-50/30"></div>
    </div>

    <div class="w-full max-w-[420px] relative z-10">
        <div class="glass-card rounded-[2.2rem] sm:rounded-[2.5rem] shadow-2xl shadow-blue-900/10 overflow-hidden transform transition-all duration-700 translate-y-0 opacity-100"
            x-init="setTimeout(() => { $el.classList.remove('translate-y-10', 'opacity-0'); $el.classList.add('translate-y-0', 'opacity-100') }, 100)">

            <!-- Top Section -->
            <div class="p-8 sm:p-10 pb-4 sm:pb-6 text-center">
                <div
                    class="inline-flex p-4 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl sm:rounded-3xl shadow-xl shadow-blue-500/30 mb-5 sm:mb-6 group transition-transform hover:rotate-6">
                    <svg class="w-7 h-7 sm:w-8 sm:h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253">
                        </path>
                    </svg>
                </div>
                <h1 class="text-2xl sm:text-3xl font-[900] text-slate-800 tracking-tight leading-none uppercase">
                    Presensi <span class="text-blue-600">Tahsin</span></h1>
                <p class="text-slate-400 mt-2 sm:mt-3 font-medium text-xs sm:text-sm">Kelompok Tahfidz Griya Qur'an
                </p>
            </div>

            <!-- Form Error -->
            <?php if ($error): ?>
                <div
                    class="mx-6 sm:mx-10 px-4 py-3 bg-red-50 text-red-600 rounded-2xl border border-red-100 text-[11px] sm:text-xs font-bold italic flex items-center shadow-sm mb-2">
                    <svg class="w-4 h-4 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($warning)): ?>
                <div
                    class="mx-6 sm:mx-10 px-4 py-3 bg-amber-50 text-amber-700 rounded-2xl border border-amber-100 text-[11px] sm:text-xs font-bold italic flex items-center shadow-sm mb-2 mt-2">
                    <svg class="w-4 h-4 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z">
                        </path>
                    </svg>
                    <?php echo $warning; ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="" class="p-8 sm:p-10 pt-2 sm:pt-4" @submit="loading = true">
                <?php csrfField(); ?>

                <!-- Username -->
                <div class="mb-4 sm:mb-5 relative group">
                    <label
                        class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-4 mb-2">Username</label>
                    <div class="relative">
                        <span
                            class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </span>
                        <input type="text" name="username" required autocomplete="username"
                            class="w-full pl-12 pr-6 py-3.5 sm:py-4 rounded-2xl border border-slate-100 bg-white/50 focus:bg-white focus:outline-none focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all font-semibold text-slate-700 placeholder:text-slate-300 placeholder:font-normal text-sm sm:text-base"
                            placeholder="Masukkan username">
                    </div>
                </div>

                <!-- Password -->
                <div class="mb-6 sm:mb-8 relative group">
                    <label
                        class="block text-[10px] font-black text-slate-400 uppercase tracking-widest ml-4 mb-2">Password</label>
                    <div class="relative">
                        <span
                            class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
                                </path>
                            </svg>
                        </span>
                        <input :type="showPass ? 'text' : 'password'" name="password" required
                            autocomplete="current-password"
                            class="w-full pl-12 pr-12 py-3.5 sm:py-4 rounded-2xl border border-slate-100 bg-white/50 focus:bg-white focus:outline-none focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all font-semibold text-slate-700 placeholder:text-slate-300 text-sm sm:text-base"
                            placeholder="••••••••">
                        <button type="button" @click="showPass = !showPass"
                            class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-300 hover:text-blue-600 transition-colors">
                            <svg x-show="!showPass" class="w-5 h-5" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                </path>
                            </svg>
                            <svg x-show="showPass" class="w-5 h-5" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18">
                                </path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Auto-persistent Login Notice -->
                <div class="mb-6 flex justify-center">
                    <p
                        class="text-[10px] font-bold text-slate-400 italic flex items-center gap-1.5 bg-slate-50 px-4 py-2 rounded-full border border-slate-100">
                        <svg class="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z">
                            </path>
                        </svg>
                        Login tetap aktif selama 30 hari.
                    </p>
                </div>

                <button type="submit" :disabled="loading"
                    class="w-full bg-gradient-to-r from-blue-600 to-indigo-700 hover:from-blue-700 hover:to-indigo-800 text-white font-black py-4 rounded-2xl shadow-xl shadow-blue-500/20 transition-all active:scale-[0.98] disabled:opacity-70 flex items-center justify-center gap-2 uppercase tracking-widest text-[11px] sm:text-xs">
                    <span x-show="!loading">Masuk Sekarang</span>
                    <span x-show="loading" class="flex gap-1 animate-pulse">
                        <span class="w-1.5 h-1.5 bg-white rounded-full"></span>
                        <span class="w-1.5 h-1.5 bg-white rounded-full"></span>
                        <span class="w-1.5 h-1.5 bg-white rounded-full"></span>
                    </span>
                </button>
            </form>

            <div class="px-8 sm:px-10 py-6 sm:py-8 bg-slate-50/50 border-t border-slate-100 text-center">
                <div x-show="canInstall" x-cloak class="mb-4">
                    <button @click="installPWA()"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-blue-50 text-blue-600 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-blue-100 transition-colors border border-blue-100">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                        </svg>
                        Install ke Perangkat
                    </button>
                </div>
                <p class="text-[9px] sm:text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-1 italic">
                    &copy; 2026
                    Presensi Tahsin - Wali Santri</p>
                <p class="text-[9px] sm:text-[10px] text-slate-400 font-medium tracking-wide">Developed by <a
                        href="https://sistemflow.com" target="_blank"
                        class="text-blue-600 font-bold hover:underline transition-all">SistemFlow</a></p>
            </div>
        </div>

        <!-- Helpful text below -->
        <p
            class="text-center text-slate-400 text-[9px] sm:text-[10px] font-bold mt-6 sm:mt-8 uppercase tracking-[0.3em]">
            Bapak Tahsin
            Presensi System v2.0</p>
    </div>
</body>

</html>