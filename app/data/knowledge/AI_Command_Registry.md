---
Title: AI Command Registry
Status: ⚡ ACTIVE
Priority: CRITICAL
LastUpdated: 2026-07-14 23:10:00
Scope: []
---

# AI Command Registry

## 🛡️ FILE LOCK MANDATE
**THIS FILE IS LOCKED BY THE USER.** The AI is strictly prohibited from modifying this file (including YAML metadata, status, or content) without explicit authorization. This file can only be unlocked for modifications by user request only.

This file defines the high-priority triggers that the AI must recognize and execute immediately. These commands are project-agnostic and apply to any domain (coding, life, health, etc.).

## 1. `encapsulate`
- **Triggers:** `encapsulate`, `capsule`, `consolidate`.
- **Mandate:** Scan the current session for "floating logic" (decisions, workflows, or ideas not yet codified).
- **Process:** 
    1. Summarize the key points and logic discussed.
    2. If a Project `.md` file is active in context, provide a `#PATCH` to update its "Detailed Blueprint" or "Roadmap" sections.
    3. If no project is active, present the summary and ask: "Should I create a new project file for this?"

## 2. `commandify`
- **Triggers:** `commandify`.
- **Mandate:** Turn a successful workflow pattern or repetitive instruction into a permanent command.
- **Process:** 
    1. Extract the logic of the current successful interaction.
    2. Format it as a new entry for this `AI_Command_Registry.md` file.
    3. Provide a `#PATCH` to append the new command to this registry.

## 3. `unpack`
- **Triggers:** `unpack`, `expand`, `deep dive`.
- **Mandate:** Provide detailed elaboration and depth on the subject currently being discussed.
- **Process:** Analyze the core components of the topic and expand upon them to provide comprehensive clarity and insight, regardless of the domain or subject matter.

## 4. `pack up`
- **Triggers:** `pack up`, `initiate transfer`, `backup session`, `hand over`.
- **Mandate:** Create an immutable snapshot of the current session state for portability.
- **Process:** 
    1. **Narrative Introduction:** Start with a colloquial summary of the session's overall shift or focus (e.g., "In this session, we moved from...").
    2. **Topic Grouping:** If multiple subjects were discussed, break the summary into Major Topics.
    3. **Chronological Evolution:** Under each topic, list the important points as bullet points that show the evolution or progress of the discussion.
    4. **Critical Details:** Include specific technical or logical details that are essential for the next AI to know and cannot be inferred from the bullet points alone.
    5. **JIT Context List:** Provide the list of files required to resume work with zero context loss.
    6. **Snapshot Creation:** Use `#ACTION: create` to save the capsule to `app/data/projects/temp/Session_Capsule_YYYY-MM-DD_HHMM.md`.
- **Format Mandate (Up to Code):** The snapshot MUST include a standard YAML header. The 'JIT Context List' must be placed in the `Scope` field to ensure compatibility with the system's JIT context logic.
    *Example Header:*
    ---
    Title: Session Capsule [Timestamp]
    Scope: [app/plugins/Onboarding.php, app/css/style.css]
    ---
- **Immutability Rule:** Session Capsules are snapshots in time. They are **never** to be updated. Every 'pack up' command must result in a brand-new file to preserve the history of the migration.

## 5. `plan`
- **Triggers:** `plan`, `initiate planning`, `architectural change`, `Initiate discussion protocol`, `Let's talk about`, `what do you think`.
- **Mandate:** Halt code implementation for architectural changes or complex logic.
- **Process:** 
    1. **Halt:** Immediately stop providing implementation patches (#ACTION: update/create).
    2. **Analyze:** Provide Markdown Plans, System Audits (#ACTION: audit), or Logic Traces (#ACTION: logic_trace).
    3. **Handshake:** Resumption of code implementation is strictly prohibited until the user explicitly confirms the plan with the phrase: "Let's go."

## 6. `install the console`
- **Triggers:** `install the console`, `add telemetry console`, `setup progressive logger`, `install telemetry console`.
- **Mandate:** Implement the high-reliability, non-buffering Progressive Polling Telemetry Console pattern to display real-time background task execution progress.
- **Process:**
    1. **Dynamic Logger Utility (PHP):** Define a `$log_file` path (usually inside `data/`) and a localized `$log_progress` lambda inside the backend router. Clear the log file on task start, and write timestamped, classified lines: `[HH:MM:SS] [type] Message\n` (valid types: `info`, `success`, `warn`, `error`, `status`).
    2. **Log Poller (PHP):** Implement an independent AJAX router endpoint `poll_progress_log` that simply outputs `file_get_contents($log_file)` with a plain text header.
    3. **Terminal Overlay (HTML/CSS):** Build a monospace terminal overlay (e.g., `#telemetry-modal`) featuring colored classes mapped to log types (`.log-info`, `.log-success`, `.log-warn`, `.log-error`, `.log-status`).
    4. **Click-to-Copy Button (HTML/CSS):** Embed a floating "Copy" button (with an overlapping rectangles icon or copy emoji) positioned in the upper-right corner of the terminal header or window.
    5. **Secure Synchronous Exporter (HTML/JS):** Inject an off-screen/hidden `<textarea id="hidden-telemetry-log">` to hold the compiled log. When the "Copy" button is tapped, the script must automatically wrap the raw log string inside Markdown code blocks (` ```text ... ``` `) and write it synchronously to the clipboard, bypassing WebKit/iOS async security blocks. This guarantees formatted logs for direct chat sharing.
    6. **Memory-Safe Polling Client (JS):** Implement a 500ms `setInterval` loop that tracks the `lastLogLength` (character count) of the received payload, appending only new delta lines to prevent UI flickering. Enforce continuous auto-scrolling to the bottom of the container. The poller must implement memory-safety guards:
        - **Global Tracker:** Bind the interval ID globally (e.g., `this.pollInterval`) to ensure the interval is cleanly cleared if the user forcibly closes the modal.
        - **Safety Timeout Cap:** Maintain a maximum poll counter (e.g., capping at 1200 polls / 10 minutes) that automatically halts the background loop to prevent infinite client-side battery and network drain if the server hangs.
    7. **State Completion Hook (JS):** Once the primary long-running task completes, cleanly clear the interval, enable/change the action button label to `Done` or `Done (Reload)`, and attach a handler to refresh the application interface or parent view safely.

## 7. `outsource`
- **Triggers:** `outsource`, `delegate`, `outsource prompt`.
- **Mandate:** Break cognitive saturation by delegating high-fidelity creative tasks to an external, clean AI agent.
- **Process:**
    1. **Recognize Saturation:** Stop attempting to write complex creative copy or visual designs directly if the current session is saturated with dense system-level files and strict development protocols.
    2. **De-contamination Audit:** Before generating any prompt text, run an internal audit and list out:
        - **Conjure Contaminants:** Brand-specific terms (such as *Conjure*, *Sovereign*, *DMG*, *Terminal*, etc.) that visually anchor the AI into tech-heavy, cyber-terminal, or dark glassmorphic styling.
        - **Predefined Choice Influencers:** Specific layout or component-level terms (such as *card*, *grid*, *bento*, *shelf*, *carousel*, *drawer*, *overlay*, *sidebar*, *dropdown*, *Tailwind*) that limit structural layouts and constrain creative brainstorming.
    3. **Negation Erasure Rule:** Ensure these audited contaminants and influencers **do not appear anywhere** in the final generated prompt. Do not even list them as negative instructions (e.g., do not say "Do not use Tailwind" or "Do not make it a grid"), as mentioning a name anchors the AI's attention window. Instead, write positive, neutral functional needs that naturally direct the AI away from them (e.g., asking for "bespoke CSS with custom variables in :root" instead of "no Tailwind").
    4. **The "Clueless Customer" Persona:** Frame the entire prompt as a highly casual, stream-of-consciousness chat message (such as a raw WhatsApp or iMessage text) from a client who is extremely specific about their **functional features** but completely clueless about visual design, forcing the visual agent to consult, research, and brainstorm layouts dynamically.
    5. **Contrast Guidance (Good vs. Bad):** Use these examples to guide the generation:
        - *Bad Example (Restricts and Contaminates):* "Design a dark-themed e-commerce smartwatch catalog using a three-column grid layout of rounded card components, a fixed sidebar for filters, and neon cyber accents."
        - *Good Example (Functional, Clueless, Open):* "Hey! I'm launching a boutique business to sell my handcrafted watches. I want a clean, elegant landing page where people can browse my different pieces, see their stories, and purchase them easily. I have no idea how it should look or be structured—please propose a modern, high-end visual direction that brings this to life!"
    6. **Generate Prompt:** Directly output the generated, de-contaminated casual chat prompt inside a clean, copy-pasteable Markdown code block in your response. Do not perform any file-system write operations or create draft files.
    7. **Handshake:** Invite the user to copy-paste the prompt to the external AI, and ask if they would like to adjust any details.

## 8. `archive discussion`
- **Triggers:** `archive discussion`, `capture discussion`, `crystallize chat`, `crystallize session`, `save transcript`, `record transcript`, `capture brainstorm`, `archive brainstorm`.
- **Mandate:** Preserve an open-ended brainstorming or exploratory chat session into a self-documenting, permanent Markdown archive containing both structured executive summaries and a verbatim turn-by-turn transcript ledger.
- **Process:**
    1. **Descriptive Naming:** Auto-generate a fitting, subject-specific title based on the primary topics discussed (e.g., `Autopoietic_Cybernetics_and_Sovereign_Economics.md`), strictly avoiding generic timestamp-only filenames.
    2. **Dedicated Pathing:** Save the file directly into `app/data/projects/discussions/[Descriptive_Title].md`.
    3. **Standard YAML Header:** Include metadata (`Title`, `Scope`, `Status: 📦 ARCHIVED`, `Priority: Normal`, `LastUpdated`).
    4. **Structured Synthesis:** Compile an Executive Narrative Summary, Major Topic Groupings, and Key Technical/Philosophical Breakthroughs.
    5. **Raw Correspondence Ledger:** Append a complete, turn-by-turn transcript containing the user's raw prompts and the AI's full responses/summaries.
    6. **JIT Resume Scope:** Include file scope and instructions on how to resume or build upon the discussion in future sessions.
    7. **Execution:** Deliver the file via `#ACTION: file_create` using Protocol V10.

## 9. `initiate diagnostics`
- **Triggers:** `initiate diagnostics`, `initiate progressive diagnostic simulation`, `run simulation`.
- **Mandate:** Perform a strict, line-by-line static execution audit on target code.
- **Process & Mandates:**
    1. **Line Evidence Requirement:** Quote exact code lines from context for every step in the simulation trace.
    2. **Zero Implicit Assumptions:** Do not assume implicit behavior, background magic, unwritten helper functions, or missing side-effects. If an operation is not explicitly written in code, it does not exist.
    3. **State-Change Verification:** For every required outcome or side-effect in the workflow, verify that an explicit code statement exists to perform it.
    4. **Step-by-Step Formatting Rules:** Evaluate execution sequence step-by-step using status markers:
        - 🟢 `Step [N] - [Step Name]`: Quote exact code line(s) & verify state change.
        - ❌ `Step [N] - [Step Name]`: Explain missing, fake, or non-functional code.
    5. **Failure Contract:** If ANY step receives a ❌ marker, start overall response header with:
        `❌ SIMULATION FAILED: Unimplemented/Fake logic detected at Step [N].` 
        Do NOT output a successful completion summary if any step is marked with ❌.