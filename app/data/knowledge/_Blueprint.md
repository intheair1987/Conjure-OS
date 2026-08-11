---
Title: Project Plan Blueprint
Status: 🛠️ TEMPLATE
Priority: Normal
LastUpdated: 2026-01-19
Scope: [app/paths.php]
---

# Project Plan Blueprint

## 0. TEMPLATE PROTECTION
**THIS IS A REFERENCE FILE.** Do not update this file unless you are explicitly instructed to modify the structural template for all future project plans.

## 1. Executive Summary
This file serves as the structural reference for all project plans in WhisperLog. When creating a new project, follow this exact formatting to ensure the UI renders correctly.

## 2. Metadata (YAML)
- **Title**: The display name in the grid.
- **Status**: Use emojis (🏗️ ACTIVE, ✅ COMPLETE, 🛠️ TEMPLATE).
- **Scope**: A comma-separated list of files `[index.php, app/app.php]`. This is used by the AI Context Exporter to prioritize files.

## 3. Interaction Syntax
- **Task Lists**: Use `- [ ]` for pending and `- [x]` for completed.
- **Semantic Badges**: `[DONE]`, `[TODO]`, `[CRITICAL]`, `[BUG]`.

## 4. Example Roadmap Structure
### Phase 1: Planning
- [x] [DONE] Define project scope.
- [ ] [PENDING] Outline core features.

### Phase 2: Implementation
- [ ] [TODO] Build initial prototype.

## 5. Mobile Layout Rules
- **No Wide Tables:** If a table is necessary, keep columns to a minimum. The system will wrap them in a horizontal scroll container, but vertical lists are preferred.
- **Chunking:** Keep sections under H2 headers concise to minimize scrolling.

## 5. AI Maintenance Mandate
When this file (or a derivative project plan) is active in the AI Context:
1. **Groundedness:** You must adhere to the steps defined in the plan.
2. **Auto-Update:** You must provide a `#PATCH` for the active `.md` file whenever progress is made.
3. **Metadata:** Always update the `Status` and `LastUpdated` fields in the YAML header.