---
Title: System Methodology Library
Status: 🛠️ ACTIVE
Priority: High
LastUpdated: 2026-05-20
Scope: []
---

# Conjure Methods

This file is a repository of "How-To" tools. These are systematic procedures used to solve complex architectural, synchronization, or process-related problems within the Conjure ecosystem.

## 1. Logic Parity Registry (Sync & Drift Tracker)
**The Problem:** Maintaining 100% functional parity across multiple platforms (e.g., Android/Bash vs. Windows/PowerShell) or ensuring logic integrity during structural migrations.

**The Method:**
1. **Anchor Tagging:** Wrap specific logic blocks in stable, unique anchors: `## [CJ_STEP:ID:TITLE] ##`.
2. **Hashing Engine (The Radar):** A script scans target files and generates MD5 hashes of the content between anchors. The engine detects movement but cannot verify functional correctness.
3. **Isolated Silos (Separate Files):** To ensure architectural cleanliness and prevent cross-contamination, the system maintains separate files for each platform:
    - **Registry Files:** Platform-specific human-readable logs (e.g., `registry_android.txt`).
    - **Baseline Files:** Platform-specific data stores for hashes and statuses (e.g., `baseline_android.json`).
4. **The "Sticky" Status Machine:** The status of a logic block follows a strict authority model:
    - **Engine Authority (Revocation):** The engine can only **demote** a status. If a block is `SYNCED` but the code changes, the engine detects the hash mismatch and revokes the status, changing it to `CHANGED`.
    - **AI Authority (Promotion):** The engine **never** auto-promotes a block to `SYNCED`. Only the AI can grant the `[SYNCED]` status by manually editing the platform's registry `.txt` file.
    - **Sticky States:** Once a status is `NEW`, `CHANGED`, or `MISSING`, it is "Sticky." It will remain in that state regardless of further code changes or hash updates until the AI performs the manual handshake.
5. **Status Definitions:**
    - `NEW`: Found in the script but never before certified by the AI.
    - `CHANGED`: The code has drifted from the version the AI last certified as `SYNCED`.
    - `MISSING`: This logic ID exists on a sibling platform but is absent here.
    - `SYNCED`: **AI-Certified.** The logic is manually verified by the AI as functionally identical to its counterparts.
6. **Ongoing Synchronization:** When Platform A is updated, the engine automatically flags Platform B as `CHANGED` or `MISSING` (via the shared ID registry), forcing a new AI audit to restore the `SYNCED` handshake.

**Example:** Used in `apps/ConjureDeployer/index.php` to keep Android and Windows deployment scripts in perfect sync.

**Example:** Used in `apps/ConjureDeployer/index.php` to keep Android and Windows deployment scripts in perfect sync.

**Example:** Used in `apps/ConjureDeployer/index.php` to keep Android and Windows deployment scripts in perfect sync.

---

## 2. Ship of Theseus (Incremental Structural Refactor)
**The Problem:** Performing a fundamental refactor of a core system (e.g., changing a data structure from nested arrays to a relational database) without breaking the system or losing data during the transition.

**The Method:**
1. **The Bridge Interface:** Create a temporary management UI with toggles for specific modules or "parts" of the system.
2. **Dual-Path Implementation:** Implement the new logic (Method B) alongside the existing logic (Method A) without removing the old code.
3. **Module-by-Module Toggling:** Divide the refactor into verifiable segments. Complete the new logic for "Part 1" and switch its toggle to Method B.
4. **Verifiable Parity:** Use the toggles to switch back and forth between Method A and Method B in the live UI to ensure the output and behavior remain identical.
5. **The Pruning:** Once all modules have been switched to Method B and verified as stable over time, delete the Method A code and remove the Bridge Interface.

**Example:** Used in the **Lesson Planner** migration from nested JavaScript dictionaries to a SQLite relational database.

---

## 3. The Surgical Hard-Stop (Isolation & Reality Check)
**The Problem:** "Ghost Failures" in complex scripts where multiple variables (timing, environment, or automated cleanup) mask the root cause, leading to AI hallucination and repetitive failed attempts.

**The Method:**
1.  **Scope Reduction:** Stop attempting to solve the entire chain. Isolate the first failing link. If the task involves multiple parallel instances, reduce the scope to **exactly one** instance to eliminate noise.
2.  **State Freezing (The Abort):** Inject a "Hard Stop" (e.g., `exit`, `abort`, or `die`) immediately after the isolated logic block. This freezes the system state and prevents subsequent logic or automated cleanup routines from altering the evidence.
3.  **Reality Check:** Shift focus from the "Console/Log Output" to the actual state of the system (physical or abstract), depending on what the task calls for.
    *   **Physical:** Monitoring the filesystem for file creation, persistence, or bit-counts.
    *   **Abstract:** Verifying variable states, database entries, or network payloads before they are processed or cleared.
    *   **Integrity Audit:** Checking for "invisible" breakers like encoding mismatches, hidden characters, or transient data that only exists for a few milliseconds.
4.  **AI Grounding:** Force the AI to work strictly up to the Hard Stop. This prevents "thinking ahead" and forces the model to reconcile its logic with the user's direct observations.
5.  **Step-wise Release:** Only remove or relocate the Hard Stop further down the script once the current segment is 100% verified through direct observation of the results.

---

### **Method 3: Example Library**

**Case A: Encoding & Instance Collisions (Windows Port)**
*   **Context:** A script intended to launch multiple background services was failing silently; windows would open and immediately close.
*   **Application:** The scope was reduced to a single service, and a hard stop was placed immediately after the launch command.
*   **Discovery:** Physical monitoring revealed that the shell was crashing due to non-ASCII characters (emojis) in the script and malformed path instructions that were only visible when the script was prevented from exiting/cleaning up.

**Case B: Transient Asset Verification (Sovereign Cache)**
*   **Context:** A complex installation script was failing to find cached dependencies, despite the AI insisting the download logic was correct.
*   **Application:** A hard stop was placed immediately after the download/caching phase, aborting the rest of the installation.
*   **Discovery:** By monitoring the folder "bit-by-bit," it was discovered that the system's native package manager was aggressively cleaning up "trash" files immediately after download. The hard stop allowed the team to verify the file count and adjust the retention logic before the cleanup could trigger.