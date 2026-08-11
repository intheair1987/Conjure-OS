# Conjure OS 🔮

> **A sovereign, mobile-native micro-app operating system and AI development sandbox.**

🌐 **Website & Documentation:** [Conjure-OS.com](https://conjure-os.com)

Conjure OS is a lightweight, zero-dependency personal web operating system and development environment designed for total ownership, privacy, and mobile sovereignty. Directed and evolved entirely by an English teacher with zero formal coding background—and built 100% on a phone using AI—Conjure OS eliminates heavy desktop toolchains, monthly SaaS subscriptions, and fragile node module dependencies in favor of plain PHP, SQLite, standard CSS tokens, and raw JavaScript.

---

## ✨ Core Pillars & Philosophy

* 📱 **Built 100% on Mobile:** Engineered from day one to run natively on mobile devices. Create, edit, compile, and deploy full applications while walking, taking public transit, or sitting in a coffee shop.
* ⚡ **Zero Build-Chain Complexity:** No Node.js, no `npm install`, no Webpack/Vite bundlers, and no framework rot. Pure PHP backends, HTML5/CSS3/JS frontends, and SQLite databases that load instantly and last forever.
* 🚀 **Zero-Config Mobile Hosting:** Ships with **Conjure Runtime**—a complete, 1-tap Android environment containing an embedded multi-worker PHP engine, Nginx server, and watchdog daemon with zero setup required.
* 🌐 **Run Anywhere:** In addition to Conjure Runtime, Conjure OS runs on any environment capable of serving PHP 8.1+ and SQLite3 (such as KSWEB on Android, local desktop development servers, custom Termux environments, or standard web hosts).
* 🤖 **AI-Native Surgical Development:** Designed specifically for AI-assisted evolution using the **Protocol V10 Multipart Raw Patcher**, **Two-Phase JIT Skeleton Context Exporter** (~90% token compression), and an **Immutable System Edit Log** for cross-session AI memory continuity.
* 🛡️ **Absolute Data Sovereignty:** 100% server-side persistence under local `data/` directories, SQLite file databases, and isolated secret keys (`*-private.json`) that are automatically excluded from clean system exports to prevent credential leaks.

---

## 🛠️ Architecture Overview

```txt
conjure-os/
├── app/                        # CORE SYSTEM KERNEL
│   ├── api/                    # Core backend routes & checkpoint workers
│   ├── css/                    # Central CSS variable tokens & theme engine
│   ├── modules/                # Core UI layouts & dynamic header controls
│   ├── plugins/                # Core System Plugins (AI Chat, Backup, Patcher, etc.)
│   └── data/                   # Server-side configuration, logs, & knowledge base
├── apps/                       # STANDALONE MICRO-APP ECOSYSTEM
│   ├── AgentStudio/            # Local & OpenRouter AI Agent Kernel studio
│   ├── ApkStudio/              # Native Android APK compilation & wrapper suite
│   ├── AtlasTrack/             # Private health & workout analytics logger
│   ├── ConjureBoy/             # Full GameBoy / GBA emulator micro-app
│   ├── Orbit/                  # Sovereign VPS & Cloudflare DNS deployment manager
│   ├── PeerDrop/               # Bidirectional P2P WebRTC local file conduit
│   ├── Portal/                 # Web bookmark & application portal launcher
│   ├── PWAStudio/              # Instant PWA manifest & icon generator
│   ├── TowerBloxx/             # Tower building physics game
│   ├── TravelPacker/           # Packing list & travel checklist organizer
│   └── Whiteboard/             # Full vector drawing canvas with stylus tilt tools
├── conjure.db                  # Primary system SQLite database
├── index.php                   # Master runtime entry point & plugin router
├── patcher.php                 # Protocol V10 file patch manager
└── recovery.php                # Emergency system restoration bunker
```

---

## 🚀 Key Features

### 1. The Surgical AI Development Suite
* **Protocol V10 Raw Patcher (`patcher.php`):** Executes atomic, multi-file code modifications using exact line anchors, eliminating hallucinated code loss or partial file overwrites.
* **JIT Context Skeletonizer (`ContextExporter`):** Compresses complete application structures by ~90% into functional skeletons (function signatures, routes, variable declarations) so AI models maintain total architectural awareness within token limits.
* **Immutable Edit Log (`EditLog`):** Every code mutation is recorded in a local SQLite ledger, serving as an immutable memory bridge between AI chat sessions.
* **Recovery Bunker & System Checkpoints:** Live differential database fingerprinting (`WAL`/`SHM` tracking) allows instant 1-tap system rollbacks if an experimental change strays.

### 2. Standalone Micro-App Architecture
Every app inside `/apps/` follows a strict sovereign contract:
* **Host-Agnostic Isolation:** Apps live strictly within `apps/[AppName]/` and never reference external host framework paths.
* **Manifest Contract:** Standardized `manifest.json` declaring app metadata, theme colors, and icons.
* **Asset Fingerprinting:** Built-in `md5_file` content hashing for `style.css` and `app.js` with instant cache-busting.
* **Zero Native Popups:** Local custom modal, select, and dialog modules replace native browser alerts.

### 3. Native Android Companion Integration
* **Conjure Runtime:** The turnkey mobile runner. Packages a complete background PHP-CLI worker pool (`PHP_CLI_SERVER_WORKERS=16`), Nginx web server, watchdog daemon, shake-to-refresh gestures, and JNI bridges into a single 1-tap Android application.
* **FloatAssist & KeyMapper:** Floating system pod overlay and hardware key trigger mapping for 1-tap OS access.
* **ApkWrapper & BuildKernel:** In-device compiler engine that packages and signs standalone native Android APKs directly on device.

---

## 💻 Running Conjure OS

### Method 1: Conjure Runtime (Recommended for Android)
The official **Conjure Runtime** Android app provides a complete, automated out-of-the-box hosting solution.
1. Install and launch the **Conjure Runtime** APK on your Android device.
2. The runtime automatically spins up the internal PHP and Nginx servers and mounts Conjure OS in a hardware-accelerated, borderless view. Zero server configuration or command-line setup required.

### Method 2: Custom Web / PHP Hosting Environment
Conjure OS can also be hosted on any environment that supports **PHP 8.1+** and **SQLite3**:
* **Mobile Apps (e.g. KSWEB):** Point the document root to the Conjure OS directory.
* **Desktop / Local Server (PC / Mac / Linux):** Execute PHP's built-in web server command in your terminal inside the Conjure OS root directory:
  ```bash
  php -S 0.0.0.0:8080 -t .
  ```
  *(This launches an instant, built-in PHP web server on port 8080 accessible via `http://localhost:8080` or your local Wi-Fi IP address).*
* **Custom Termux / Web Host:** Configure Nginx or Apache with PHP-FPM targeting `index.php`.

For complete setup guides, tutorials, and documentation, visit **[Conjure-OS.com](https://conjure-os.com)**.

---

## 🔒 Security & Privacy

* **Zero Cloud Lock-in:** All data stays local in SQLite databases (`.db`) or structured JSON files.
* **Clean System Exports:** Built-in backup utilities automatically exclude private API keys (`*-private.json`), database WAL files, and media vaults when generating clean distribution ZIPs for open-sourcing or migration.

---

## 📜 License

Distributed under the MIT License. See `LICENSE` for more information.

---

<p align="center">
  <i>Directed by human intuition. Built by AI. Owned entirely by you.</i>
</p>