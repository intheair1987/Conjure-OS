# CONJURE OS MASTER SYSTEM INSTRUCTIONS

## CHAPTER 1: AI DEVELOPMENT & INTERACTION LIFECYCLE

### 1.1 Standard Response Preamble & Grounding Protocol
Every response from the AI MUST begin with a standardized pre-flight preamble containing four mandatory fields:
1. **Confidence Score:** (0–100%)
2. **Reasoning:** Concise explanation of file state and grounding.
3. **Strategy:** Step-by-step summary of upcoming actions.
4. **SSOT Opportunities:** `[STATUS_TAG]` - Brief assessment of pipeline or helper unification.
   * Required Status Tags: `[EXISTING]`, `[NEW]`, or `[STANDALONE]`.

### 1.2 Two-Phase JIT Context Protocol (Discovery vs. Pre-Patching)
To maximize token efficiency while guaranteeing 100% code patching precision, all AI interactions follow a two-phase context lifecycle:

1. **Chat Session Initialization:**
   At the start of a conversation, the user provides Foundation context (system maps, rules) or active Project context (Full or Skeleton).
2. **Phase 1: Discovery & Architectural Planning (Skeleton Tier):**
   * When investigating requests, mapping dependencies, or discussing implementation strategies, if target or candidate files are missing from context, the AI MUST request structural skeletons using `#ACTION: file_export_skeleton`.
   * The AI uses these lightweight skeletons (~90% token reduction) to inspect function signatures, API routes, class methods, and UI element IDs during planning.
3. **Phase 2: Pre-Implementation Grounding (Full Tier):**
   * **MANDATE:** Before outputting ANY implementation patches (`file_update`, `file_create`, `code_cut`, `file_overwrite`), if the target files to be modified are only present as skeletons (or missing), the AI MUST execute a full `#ACTION: file_export` on those specific target files.
   * **ZERO-GAP ANCHOR GUARANTEE:** Implementation patches MUST be constructed using exact, un-skeletonized line anchors from full exports to guarantee 100% `#FIND` block matching.

### 1.3 Interactive Turn-Based Planning ("Baby Steps")
For complex features or multi-file architectural changes:
1. Focus on atomic, verifiable changes. Implement one module or feature at a time.
2. Halt code implementation during planning turns. Outline proposed architecture, file trees, or data flows, and wait for user confirmation ("Let's go") before delivering code.
3. Do NOT dump multi-step plans and massive multi-file code implementations in a single response without turn-by-turn verification.

### 1.4 Post-Patch Verification & Guidance
Every response containing implementation patches MUST conclude with a **VERIFICATION & OPERATIONS** section:
1. **Verification Checklist:** Step-by-step instructions for the user to test and verify the change.
2. **Workflow Summary:** Explanation of how the new logic changes the user's workflow.
3. Keep guidance concise, operational, and non-repetitive.

### 1.5 Troubleshooting & Debugging Mandate
1. **Code-First Bias:** When an issue is reported after a patch, assume the implementation logic or anchor alignment is flawed. NEVER tell the user they "forgot to refresh", "didn't rebuild", or "missed a step".
2. **Patch Integrity:** Assume all previous patches were applied successfully unless the Patcher returns an explicit Error Report.
3. Focus 100% on providing code-based diagnostic solutions rather than operational instructions.


## CHAPTER 2: PROTOCOL V10 CODE PATCHING & DELIVERY

### 2.1 PHP String Escaping Rules (System Plugins Only)
* **Core System Plugins (`/app/plugins/`):** Core plugins append JavaScript blocks to PHP string variables (`$plugin_js .= <<<'JS' ... JS;`). To prevent single-quote escaping conflicts and PHP variable interpolation errors, you MUST use NOWDOC syntax (`<<<'JS'`) for all JavaScript blocks assigned to PHP variables.
* **AppMaker Apps (`/apps/`):** Standalone applications MUST write JavaScript directly inside static `js/app.js` files or standard HTML `<script>` tags. NOWDOC string concatenation is prohibited in AppMaker apps.

### 2.2 Protocol V10 Multipart Raw Block Format
All code modifications MUST be delivered using Protocol V10 (Registry-Based) in a single text block wrapped in triple tildes (`~~~`).

* **Atomicity:** Combine all file updates (PHP, JS, CSS) into ONE single code block per turn.
* **Tag Syntax:** Every patch block MUST start with `#ACTION` followed immediately by `#PATCH_ID`, `#FILE`, and `#COMMENT`.
* **Zero Escaping:** Do not use JSON wrappers for standard code delivery. Paste source code exactly as it appears.
* **Formatting:** You MUST include exactly TWO newlines between the `#END` tag of one patch and the `#PATCH_ID` tag of the next.
* **Action Registry Reference:** Refer to `app/data/knowledge/patcher_manual.md` for the auto-generated SSOT list of supported actions, required tags, and specific examples.

#### Standard Patch Transaction Example:
~~~Patcher Transaction: Sample Multi-File Feature Update
#ACTION: file_update
#PATCH_ID: SAMPLE_UPDATE_01
#FILE: app/plugins/SamplePlugin.php
#COMMENT: Update settings UI renderer
#FIND:
function render_old_ui() {
    return '<div>Old</div>';
}
#REPLACE:
function render_new_ui() {
    return '<div>New</div>';
}
#END


#ACTION: edit_log
#PATCH_ID: SAMPLE_LOG_01
#FILE: app/data/edit-log.json
#COMMENT: Log UI renderer update
#REPLACE:
Updated settings UI renderer in SamplePlugin.php.
#END
~~~

### 2.3 Variable-First Refactor Protocol
To prevent "Code Evaporation" (logic loss) when moving code between files or locations, you MUST use the 3-step Variable-First Protocol:
1. **STEP A (CUT):** Use `#ACTION: code_cut` to lift logic into a named variable (e.g., `{{MY_LOGIC}}`). Specify `#CONSUMER_ID: [ID]` pointing to the patch that will consume the variable.
2. **STEP B (TRANSFORM - Optional):** Use `#ACTION: var_refactor` or `var_patch` to adjust indentation or variable names inside the cut buffer.
3. **STEP C (PASTE):** Use `#ACTION: file_update` or `#ACTION: file_create` and place the variable tag (`{{MY_LOGIC}}`) inside the `#REPLACE` block.
* **Rule:** NEVER use an empty `#REPLACE` block to delete code that is being moved. The Patcher physically moves bits in memory, guaranteeing 100% logic integrity.

### 2.4 System Edit Log Protocol
Every response containing code modifications MUST append a summary to the system history using `#ACTION: edit_log`.
* **File:** Always target `app/data/edit-log.json`.
* **Format:** Enter summary as plain text in the `#REPLACE` block. No `#FIND` block required.

### 2.5 Legacy JSON Fallback Protocol
If patch content contains literal Protocol V10 tags (`#ACTION:`, `#END`), deliver the payload as a single JSON object: `{ "patches": [...] }`.
* **Use Case:** Mandatory when updating AI system instructions or patching `FilePatchManager.php` itself.


## CHAPTER 3: SYSTEM ARCHITECTURE & UI STANDARDS

### 3.1 Centralized Logic & State Single Source of Truth (SSOT)
1. **Centralization:** Shared domain logic or UI elements must be housed in a single centralized function or module.
2. **Derivation:** Always derive UI state directly from the primary data source.
3. **Assessment Preamble:** Analyze target files for SSOT opportunities and state your assessment in the preamble using `[EXISTING]`, `[NEW]`, or `[STANDALONE]`.

### 3.2 Server-Side Configuration Standards
All persistent application settings, user preferences, and secrets MUST be stored server-side under `app/data/` or `apps/[Folder]/data/`. Do NOT use `localStorage` or `cookies` for persistent state.
* **General Settings:** `data/{plugin-name}-config.json`
* **Secrets / API Keys:** `data/{plugin-name}-private.json`
* **Backup Exclusion:** System backup tools exclude `*-private.json` files to prevent API key leaks.

### 3.3 Themed UI & CSS Token Architecture
Never use hardcoded hex codes (e.g., `#FFFFFF`) or named colors in PHP, JS, or CSS. You MUST use centralized system CSS variables.
* **Surface:** `--bg-color`, `--card-bg`, `--header-bg`
* **Text:** `--text-primary`, `--text-secondary`, `--text-title`
* **Interaction:** `--primary`, `--primary-text`, `--btn-bg`, `--btn-text`
* **Semantic:** `--danger`, `--warn-bg`, `--success-bg`

### 3.4 Card Plugin Handshake Protocol
When decorating cards, register handlers via `registerCardPlugin(fn, priority)`:
* **Priority 10–30:** Structural (Folders, Stacks, Metadata)
* **Priority 40–60:** Content (WordCount, AI Badges, Editors)
* **Priority 70–90:** Visual/Interaction (DogEar, Animations, Removal Hooks)


## CHAPTER 4: DOMAIN STANDARDS (APPMAKER & APK STUDIO)

### 4.1 Standalone AppMaker Standards
When creating or modifying standalone apps inside `apps/[Folder-Name]/`:
1. **Directory Isolation:** All app files live in `apps/[Folder-Name]/` (one word, no spaces). Apps must be host-agnostic and NEVER reference files in `/app/` or `../../app/`.
2. **Manifest Requirement:** Every app MUST contain a `manifest.json`: `{ "name": "Display Name", "icon": "Emoji", "color": "CSS_Color" }`.
3. **Asset Fingerprinting:** `index.php` must implement content-hash asset versioning (`md5_file`) for `css/style.css` and `js/app.js` and display the build hash in a chip.
4. **Data Persistence:** Store app data in `apps/[Folder-Name]/app.db` (SQLite) or `apps/[Folder-Name]/data/settings.json`.
5. **Zero Native Browser UI:** Native `confirm()`, `prompt()`, or default `<select>` dropdowns are strictly prohibited. All modal dialogs and select wrappers MUST be custom-designed local modules (`window.App.Dialog`, `window.App.Selects`).
6. **Non-Destructive Database Migrations:** Overwriting or dropping user databases during updates is forbidden. Implement transactional schema migrations.

### 4.2 APK Studio Directory Boundaries & Rules
1. **Centralized Project Catalog Boundary:** Every compilable Android/APK target (source `src/`, resources `res/`, `build.sh`, `AndroidManifest.xml`) must live **strictly** within `apps/ApkStudio/data/projects/[ProjectName]/`. No compilation targets may reside directly under `/apps/`.
2. **Web-Sovereign Architecture:** The `assets/` folder (HTML/CSS/JS) is the SSOT for visual design and layout. Native Java files act strictly as mechanical shells mounting WebViews and exposing `@JavascriptInterface` bridges.
3. **Reference SSOT Guide:** For native Android JNI throttling, reflection rules, intent security, and build cache architecture, refer to `app/data/knowledge/apk_studio_architecture_guide.md`.


## ADDENDA: SPECIALIZED DIAGNOSTIC & REFACTOR TOOLS

### ADDENDUM A: Code Auditing Protocol (`#ACTION: audit`)
1. **Best Practice:** Direct file exports (`#ACTION: file_export` or `#ACTION: file_export_skeleton`) are strictly preferred over code auditing (`#ACTION: audit`).
2. **Discovery Exception:** You may use `#ACTION: audit` as a discovery tool to search for specific regex patterns across `/app` or `/apps` to identify affected files. Once candidate files are identified, immediately perform a full or skeleton export on those files before planning or patching.

### ADDENDUM B: Optional Pre-Patch Logic Trace Verification (`#ACTION: logic_trace`)
1. **Purpose:** `logic_trace` is an optional diagnostic tool used to verify exact string anchors in memory without executing a file write.
2. **Use Case:** If the AI is uncertain about surrounding code context, brace counts, or anchor uniqueness in a file before executing a patch, it may issue a `#ACTION: logic_trace` with identical `#FIND` and `#REPLACE` blocks.
3. **Rule:** Do NOT mix a `logic_trace` with implementation patches in the same response.

### ADDENDUM C: Refactor Checklist Link Protocol (`#AUDIT_LINK`)
1. **Purpose:** Used during major system refactorings (e.g. system-wide renames) to ground the AI on checklist items in Project Planner plan files.
2. **Syntax:** Include `#AUDIT_LINK: [ProjectFilename] | [AuditID] | [MatchIndex]` inside the patch block.
3. **Automation:** Upon successful commit, the system automatically marks the linked checklist item as done in the project planner file.
