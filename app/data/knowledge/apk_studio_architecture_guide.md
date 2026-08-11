# ApkStudio Web-Sovereign Architecture Guide

This guide establishes the master system design paradigm, directory conventions, and compile-time boundaries for creating signed Android applications within the Conjure OS environment using ApkStudio. 

All future AI development agents must strictly follow these rules to maintain layout Single Source of Truth (SSOT), compiler stability, and performance.

---

## 1. Directory Boundary & Isolation Mandate

To prevent compilation failures and codebase fragmentation, the system enforces a strict boundary between web-based tools and compilable Android source code:

*   **Centralized Projects Folder ONLY:** Every single Android/APK compilation target (such as standard Java/XML apps, web wrappers, or custom system helpers like `BuildKernel`, `FloatAssist`, `Conjure`, and `Hello`) must reside **strictly** inside the centralized directory:
    `apps/ApkStudio/data/projects/[ProjectName]/`
*   **The `/apps/` Directory Exclusivity:** The top-level `/apps/` directory is reserved strictly for standalone, web-sovereign AppMaker applications (such as `apps/ApkStudio`, `apps/AtlasTrack`, or helper utility panels). No Android compilation files (like `src/`, `res/`, or `AndroidManifest.xml`) may ever live directly in a subfolder under `/apps/`.
*   **Cross-App Compilation SSOT:** If a specialized AppMaker application (such as an automated wrapper creator) generates or configures an APK, it must stage and write the resulting project directory directly into the shared projects path:
    `apps/ApkStudio/data/projects/[ProjectName]/`
    This ensures that any generated project remains instantly visible, editable, and compilable by both `ApkStudio` and the unified background `BuildKernel` daemon.

---

## 2. Core Architectural Paradigm

ApkStudio enforces the **Web-Sovereign Architecture (WebView Wrapper Stack)**:
*   **The Frontend Layer (`assets/`)**: Written strictly in standard **HTML5, CSS3, and JavaScript**. This is the **Single Source of Truth (SSOT)** for all UI layout, dimensions, colors, rounded corners, and animations.
*   **The Host Shells (`src/`)**: Written in native Android **Java**. These are purely mechanical containers (Activities or Services) responsible for window lifecycle, mounting borderless, transparent WebViews, and capturing raw system events (such as hardware touches or system broadcasts).
*   **The JS Bridge (`src/WebBridge.java` / `src/ServiceBridge.java`)**: A translator layer utilizing Android's `@JavascriptInterface` to expose mechanical Java functions as a global `window.Android` or `window.Service` object inside the browser sandbox.

---

## 2. Directory Structure Conventions

A standard Web-Sovereign ApkStudio project must be organized as follows:

```txt
[ProjectName]/
├── AndroidManifest.xml             # Declares package, permissions, Activities, and Services
├── build.sh                        # Project-level shell compiler (automatically bundles assets/)
├── signing.conf                    # Keystore configurations (debug or custom production keys)
├── assets/                         # <--- FRONTEND CORE (HTML/CSS/JS)
│   ├── index.html                  # Main settings dashboard or single-screen application
│   ├── css/
│   │   └── style.css               # Clean styling utilizing custom variables
│   └── js/
│       └── app.js                  # Frontend controllers, event handlers, and JS-Bridge calls
└── src/com/company/project/        # <--- SYSTEM INTERFACES (JAVA)
    ├── MainActivity.java           # Instantiates full-screen, hardware-accelerated WebView
    └── WebBridge.java              # Java functions mapped to window.Android
```

---

## 3. The Reusability & Isolation Model

*   **Global Master Blueprints**: Master templates reside in the read-only directory `apps/ApkStudio/templates/` (e.g., `MainActivity_wrapper.java.tpl`, `build.sh.tpl`).
*   **Isolated Private Copies**: When a project is created, ApkStudio copies these blueprints directly into the project's private source folders.
*   **The Safety Guarantee**: Modifying a Java file (like `WebBridge.java`) inside Project A **will never affect or break** Project B, as they are completely isolated, independent copies.
*   **Zero-Config Compilation**: The compilation script compiles Java sources dynamically using a wildcard: `$(find src -name "*.java")`. The mere physical presence of a Java file in the project's private `src/` directory is the sole trigger for its compilation.

---

## 4. Mandatory Performance & Security Rules

To prevent performance bottlenecks, interface lagging, or dynamic security crashes on modern Android 13/14+ devices, all code must follow these boundaries:

### A. The JNI Execution Boundary (Throttling)
Evaluating JavaScript (`evaluateJavascript`) across the Java-to-browser boundary or sending inter-process broadcasts (`sendBroadcast`) during continuous, high-frequency user gestures (such as dragging the floating orb at 120Hz) will saturate the CPU and cause severe frame drops.
*   **JavaScript Updates**: Must be throttled to a maximum frequency of **60fps (16ms)**:
    ```java
    long now = System.currentTimeMillis();
    if (now - lastJsUpdateTime > 16) {
        webView.evaluateJavascript("syncSettings();", null); // Correct: NO 'javascript:' prefix
        lastJsUpdateTime = now;
    }
    ```
*   **IPC Broadcasts**: Must be throttled to a maximum frequency of **10fps (100ms)**:
    ```java
    if (now - lastTelemetryTime > 100) {
        sendBroadcast(intent);
        lastTelemetryTime = now;
    }
    ```

### B. Secure Intent Binding
Modern Android security policies block dynamic broadcast receivers registered with the `RECEIVER_NOT_EXPORTED` (`4`) flag if the incoming intent does not explicitly match the app's target.
*   **Rule**: Every custom `Intent` broadcasted inside the application must explicitly declare its target package:
    ```java
    Intent intent = new Intent("com.conjure.floatassist.UPDATE_SETTINGS");
    intent.setPackage(getPackageName()); // Mandatory on Android 13+
    sendBroadcast(intent);
    ```

### C. WebView Javascript Evaluation
*   **Rule**: Never prefix `evaluateJavascript` statements with `javascript:`. Modern Android engines execute raw JavaScript strings directly:
    *   *Incorrect*: `mWebView.evaluateJavascript("javascript:updateUI();", null);`
    *   *Correct*: `mWebView.evaluateJavascript("updateUI();", null);`

### D. Multi-Device API Compatibility (Reflection)
When compiling using lightweight, Termux-based standard build libraries, modern API classes (such as Android 12+ wallpaper blurs) do not exist in the compile-time `android.jar`.
*   **Rule**: Use **Java Reflection** to call APIs introduced in API Level 30+ to keep the code compatible across older compiler environments while executing safely at runtime:
    ```java
    if (Build.VERSION.SDK_INT >= 31) {
        try {
            java.lang.reflect.Method setBlur = WindowManager.LayoutParams.class.getMethod("setBlurBehindRadius", int.class);
            setBlur.invoke(layoutParams, 80);
        } catch (Exception e) { e.printStackTrace(); }
    }
    ```

---

## 5. Web-Sovereign Dynamic Vector Generator Pattern

To facilitate the generation of launcher and design icons dynamically inside sandboxed client-side environments (where binary conversion tools like `imagemagick` or `inkscape` are unavailable), standard applications must implement the **HTML5 Canvas SVG-to-PNG Compiler** pattern:

### A. The Core Blueprint:
1.  **CORS-Compliant Index Fetching:** Fetch index catalog files directly via structured JSON endpoints (e.g. `tags.json` on CDN unpkg) rather than raw directories to bypass browser security sandboxes.
2.  **XML Serialization & Composite Construction:** Construct a unified master viewport in standard SVG strings, mapping background shapes, customizable hex colors, and coordinate translations.
3.  **Blob URL Stream Processing:** Rather than using `btoa()` which can trigger `DOMException` failures on non-ASCII unicode characters, stream the composite SVG code using `Blob` URL buffers.
4.  **Hardware-Accelerated Rasterization:** Paint the resulting Blob URL onto an off-screen HTML5 `<canvas>` and extract the output as a high-density, standardized 512x512 `.png` representation.

### B. Standard Code Implementation:
```javascript
async function compileVectorToPng(activeIconName, bgColor, fgColor) {
    // 1. Fetch raw SVG content from CDN
    const res = await fetch(`https://unpkg.com/lucide-static@latest/icons/${activeIconName}.svg`);
    const svgText = await res.text();
    const innerContent = svgText.match(/<svg[^>]*>([\s\S]*?)<\/svg>/i)[1];

    // 2. Construct composite layered SVG
    const compositeSvg = `
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
            <rect width="512" height="512" fill="${bgColor}" rx="112" />
            <g transform="translate(128, 128) scale(10.66)" stroke="${fgColor}" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                ${innerContent}
            </g>
        </svg>
    `;

    // 3. Rasterize to standard 512x512 PNG using Blob URL
    const blob = new Blob([compositeSvg], {type: 'image/svg+xml;charset=utf-8'});
    const url = URL.createObjectURL(blob);
    const img = new Image();
    
    img.onload = () => {
    const canvas = document.createElement('canvas');
    canvas.width = 512;
    canvas.height = 512;
    canvas.getContext('2d').drawImage(img, 0, 0);
            
    const dataUrl = canvas.toDataURL('image/png');
    const base64Png = dataUrl.split(',')[1]; // Ready for native Java compilation
            
    URL.revokeObjectURL(url);
};
img.src = url;
    }

    ---

    ## 6. Single Source of Truth (SSOT) Template Propagation

    To eliminate template replication debt and prevent logic drift across complex wrapper applications (such as `ApkWrapper`), the system utilizes a centralized JIT (Just-In-Time) Template Propagation Engine:

    *   **Declarative Synchronization Mapping (`data/template_sync.json`)**: All replication rules are declared inside a central config file mapping master templates to their destination child copies:
```json
{
    "templates/MainActivity_wrapper.java.tpl": [
        "data/projects/ApkWrapper/assets/templates/MainActivity_child.java.tpl"
    ]
}
```
    *   **The JIT Replication Hook**: On every workspace API request (`api.php`), the engine scans this config, parses changes from the master files, prepends strict AI safety warning comments, and automatically overwrites destination targets.
    *   **Dynamic Warning Prepend**: Sub-copies in downstream project assets are dynamically prefixed with a highly visible warning block preventing direct human/AI edits.

---

## 7. Tiered Build Cache & Self-Healing Dependency Pipeline Pattern

To maintain hyper-fast build times (~2 seconds) and ensure build scripts remain resilient and self-healing across fresh or reinstalled Termux environments, all standalone native build scripts must adhere to the **Tiered Build Cache Architecture**:

### A. Core Architectural Contracts:
1. **Persistent Binary & Package Caching (`$PACKAGE_CACHE` & `$LIB_CACHE`):**
   - Downloaded `.deb` packages (PHP, Nginx, OpenSSL) and pre-compiled native `.so` libraries (including Go `libtsnet.so` or C shared libraries) must be stored persistently under `$HOME/.apkstudio/conjure-runtime-packages/$ABI` and `$HOME/.apkstudio/conjure-runtime-libs/$ABI`.
   - Build scripts must perform pre-flight checks (`has_cached_debs()` and `has_cached_libs()`) before initiating network operations (`pkg update`) or expensive toolchain passes (`go build`, `readelf`, `patchelf`).
2. **Hash-Based Incremental Compilations:**
   - Track native source file signatures (e.g. `md5sum src_go/ts_proxy.go`) inside a local hash marker (`ts_proxy.hash`).
   - Native Go or C shared libraries are re-compiled ONLY when source hashes differ or when the library cache is missing.
3. **Self-Healing Environment Guarantee:**
   - Build scripts must be 100% non-destructive and self-healing. If Termux is reinstalled, app data is cleared, or the script executes on a fresh device, cache misses automatically trigger dependency bootstrapping (`pkg install`), package downloads (`apt download`), and full binary extraction/ELF patching (`patchelf`), automatically populating the local cache for all subsequent builds.
4. **Console Hygiene & Asset Sanitization:**
   - Packaging commands must use quiet flags (`zip -q -ur`) to eliminate terminal verbosity and frame drops during live terminal streaming.
   - Temporary build-time package list artifacts (such as `termux-main-*-Packages`) must be stripped from `assets/` prior to final APK alignment and signing.```