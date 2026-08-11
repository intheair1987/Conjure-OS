const App = {
    activeThreadId: null,
    isProcessing: false,

    activeAbortController: null,

    async init() {
        this.bindEvents();
        this.bindCreditPillInteractions();
        this.refreshCredits(false);
        await this.loadThreads();

        // Listen for Browser Back Gesture / Hardware Back button (popstate)
        window.addEventListener('popstate', (e) => this.handlePopState(e));

        // Restore active mission thread state from URL deep link on page refresh
        const urlParams = new URLSearchParams(window.location.search);
        const threadParam = urlParams.get('thread') || urlParams.get('thread_id');

        let targetThread = null;
        if (threadParam) {
            targetThread = (this.allThreads || []).find(t => t.id == threadParam);
        }

        if (targetThread) {
            const baseUrl = window.location.pathname;
            window.history.replaceState({ home: true }, '', baseUrl);
            window.history.pushState({ thread: targetThread.id }, '', `${baseUrl}?thread=${targetThread.id}`);

            await this.selectThread(targetThread.id, targetThread.title, false);
        } else {
            window.history.replaceState({ home: true }, '', window.location.pathname);
        }
    },

    handlePopState(e) {
        const activeModal = document.querySelector('.modal-overlay.active');
        if (activeModal) {
            activeModal.classList.remove('active');
            return;
        }

        const urlParams = new URLSearchParams(window.location.search);
        const threadParam = urlParams.get('thread') || urlParams.get('thread_id');

        if (threadParam) {
            const targetThread = (this.allThreads || []).find(t => t.id == threadParam);
            if (targetThread) {
                this.selectThread(targetThread.id, targetThread.title, false);
                return;
            }
        }

        this.closeActiveThreadUI();
    },

    closeActiveThreadUI() {
        this.activeThreadId = null;
        document.getElementById('chat-workspace').style.display = 'none';
        document.getElementById('empty-state').style.display = 'flex';

        const appContainer = document.querySelector('.app-container');
        if (appContainer) appContainer.classList.remove('mobile-chat-active');

        this.renderThreads(this.allThreads);
    },

    bindCreditPillInteractions() {
        const pill = document.getElementById('credit-pill');
        if (!pill) return;

        let lpTimer = null;
        let isLongPress = false;

        pill.addEventListener('pointerdown', (e) => {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            isLongPress = false;
            lpTimer = setTimeout(() => {
                isLongPress = true;
                if (navigator.vibrate) navigator.vibrate(25);
                this.openCreditTopUpPage();
            }, 600);
        });

        pill.addEventListener('pointerup', () => {
            if (lpTimer) {
                clearTimeout(lpTimer);
                lpTimer = null;
            }
        });

        pill.addEventListener('pointerleave', () => {
            if (lpTimer) {
                clearTimeout(lpTimer);
                lpTimer = null;
            }
        });

        pill.addEventListener('contextmenu', (e) => {
            e.preventDefault();
        });

        pill.addEventListener('click', (e) => {
            if (isLongPress) {
                e.preventDefault();
                e.stopPropagation();
                isLongPress = false;
                return;
            }
            this.refreshCredits(true);
        });
    },

    openCreditTopUpPage() {
        this.showToast('Opening OpenRouter top-up page...');
        window.open('https://openrouter.ai/credits', '_blank');
    },

    async refreshCredits(showToast = false) {
        const valEl = document.getElementById('credit-val');
        const iconEl = document.getElementById('btn-refresh-icon');
        if (iconEl) iconEl.style.transform = 'rotate(180deg)';

        try {
            const res = await fetch('index.php?action=get_credits');
            const data = await res.json();
            if (data.success && data.remaining !== undefined) {
                const formatted = `$${data.remaining.toFixed(2)}`;
                if (valEl) valEl.textContent = formatted;
                if (showToast) {
                    this.showToast(`OpenRouter credit refreshed (${formatted}). Long press to top up.`);
                }
            } else {
                if (valEl) valEl.textContent = 'Key Error';
                if (showToast) this.showToast('Failed to refresh OpenRouter credit', 'error');
            }
        } catch (e) {
            console.error(e);
            if (valEl) valEl.textContent = 'Error';
            if (showToast) this.showToast('Error refreshing OpenRouter credit', 'error');
        } finally {
            if (iconEl) setTimeout(() => iconEl.style.transform = 'none', 300);
        }
    },

    stopAgent() {
        if (this.activeAbortController) {
            this.activeAbortController.abort();
            this.activeAbortController = null;
        }
        this.hideThinkingIndicator();
        this.isProcessing = false;

        const sendBtn = document.getElementById('btn-send-message');
        const stopBtn = document.getElementById('btn-stop-agent');
        if (sendBtn) sendBtn.style.display = 'flex';
        if (stopBtn) stopBtn.style.display = 'none';

        this.showToast('Agent run stopped', 'error');
    },

    renderMarkdown(text) {
        if (!text) return "";
        let trimmed = text.trim();

        if (typeof marked !== 'undefined') {
            try {
                marked.setOptions({
                    gfm: true,
                    breaks: false
                });
                let html = marked.parse(trimmed);

                html = html.replace(/<pre><code class="(?:language-)?([a-z0-9_]*)">([\s\S]*?)<\/code><\/pre>/gi, (match, lang, code) => {
                    const langLabel = lang ? lang.toUpperCase() : 'CODE';
                    return `<div class="code-block-card"><div class="code-block-header"><span>${langLabel}</span><button type="button" class="btn-copy-code" onclick="App.copyCodeBlock(this)">Copy</button></div><pre class="code-block-body"><code>${code}</code></pre></div>`;
                });
                html = html.replace(/<pre><code>([\s\S]*?)<\/code><\/pre>/gi, (match, code) => {
                    return `<div class="code-block-card"><div class="code-block-header"><span>CODE</span><button type="button" class="btn-copy-code" onclick="App.copyCodeBlock(this)">Copy</button></div><pre class="code-block-body"><code>${code}</code></pre></div>`;
                });

                return html;
            } catch (e) {
                console.error("Marked parse error", e);
            }
        }

        return this.escapeHtml(trimmed);
    },

    bindEvents() {
        const textarea = document.getElementById('chat-input');
        if (textarea) {
            textarea.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
        }
    },

    showToast(msg, type = 'success') {
        let toast = document.getElementById('agent-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'agent-toast';
            toast.className = 'toast-notification';
            document.body.appendChild(toast);
        }
        toast.textContent = msg;
        toast.className = `toast-notification ${type} active`;
        setTimeout(() => toast.classList.remove('active'), 3000);
    },

    openModal(id) {
        document.getElementById(id).classList.add('active');
    },

    closeModal(id) {
        document.getElementById(id).classList.remove('active');
    },

    manualPatchResultText: '',

    showManualPatchResult(data) {
        const overlay = document.getElementById('modal-manual-patch-result');
        const title = document.getElementById('manual-patch-result-title');
        const status = document.getElementById('manual-patch-result-status');
        const summary = document.getElementById('manual-patch-result-summary');
        const body = document.getElementById('manual-patch-result-body');

        if (!overlay || !title || !status || !summary || !body) return;

        const payload = data || {};
        const rawStatus = String(payload.status || 'error').toLowerCase();
        const normalizedStatus = rawStatus === 'committed'
            ? 'COMMITTED'
            : (rawStatus === 'mismatch' ? 'MISMATCH' : 'ERROR');

        let detail = '';
        if (rawStatus === 'committed') {
            const files = Array.isArray(payload.files_updated) ? payload.files_updated : [];
            detail = files.length
                ? `Updated files: ${files.join(', ')}`
                : (payload.message || 'Manual patch committed successfully.');
        } else if (rawStatus === 'mismatch') {
            detail = payload.error_count !== undefined
                ? `${payload.error_count} patch(es) failed preflight.`
                : 'The patch failed preflight and no changes were committed.';
        } else {
            detail = payload.message || payload.error || 'Manual patch execution failed.';
        }

        const responseText = rawStatus === 'mismatch'
            ? (payload.diagnostic_report || JSON.stringify(payload, null, 2))
            : (rawStatus === 'committed'
                ? JSON.stringify(payload, null, 2)
                : JSON.stringify(payload, null, 2));

        this.manualPatchResultText = responseText;
        title.textContent = 'Manual Patch Result';
        status.textContent = normalizedStatus;
        status.className = `manual-patch-result-status is-${rawStatus === 'committed' ? 'committed' : (rawStatus === 'mismatch' ? 'mismatch' : 'error')}`;
        summary.textContent = detail;
        body.textContent = responseText;
        overlay.classList.add('active');
    },

    closeManualPatchResult() {
        const overlay = document.getElementById('modal-manual-patch-result');
        if (overlay) overlay.classList.remove('active');
    },

    async copyManualPatchResult() {
        if (!this.manualPatchResultText) return;

        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(this.manualPatchResultText);
            } else {
                this.fallbackCopyText(this.manualPatchResultText);
            }
            this.showToast('Manual patch result copied');
        } catch (e) {
            this.showToast('Copy failed', 'error');
        }
    },

    allThreads: [],

    async loadThreads() {
        try {
            const res = await fetch('index.php?action=get_threads');
            const data = await res.json();
            if (data.success) {
                this.allThreads = data.threads || [];
                this.renderThreads(this.allThreads);
            }
        } catch (e) {
            console.error('Failed to load threads', e);
        }
    },

    formatThreadDate(dateStr) {
        if (!dateStr) return '';
        try {
            const isoStr = dateStr.includes('Z') || dateStr.includes('+') ? dateStr : dateStr.replace(' ', 'T') + 'Z';
            const d = new Date(isoStr);
            if (isNaN(d.getTime())) return dateStr.split(' ')[0] || dateStr;

            const now = new Date();
            const isToday = d.toDateString() === now.toDateString();
            const timeFormatted = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true });

            if (isToday) {
                return timeFormatted;
            }

            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const month = monthNames[d.getMonth()];
            const day = d.getDate();

            if (d.getFullYear() === now.getFullYear()) {
                return `${month} ${day}, ${timeFormatted}`;
            }

            return `${month} ${day}, ${d.getFullYear()}`;
        } catch (e) {
            return dateStr;
        }
    },

    renderThreads(threads) {
        this.allThreads = threads || [];
        const list = document.getElementById('thread-list');
        if (threads.length === 0) {
            list.innerHTML = '<div style="padding:12px; font-size:12px; color:var(--text-secondary); text-align:center;">No active missions</div>';
            return;
        }

        list.innerHTML = threads.map(t => `
            <div class="thread-item ${t.id == this.activeThreadId ? 'active' : ''}" onclick="App.selectThread(${t.id}, '${this.escapeHtml(t.title).replace(/'/g, "\\'")}')">
                <div style="min-width: 0; flex: 1; display: flex; flex-direction: column; gap: 2px;">
                    <span class="thread-title">${this.escapeHtml(t.title)}</span>
                    <span class="thread-date">${this.formatThreadDate(t.updated_at || t.created_at)}</span>
                </div>
                <button class="btn-icon" onclick="event.stopPropagation(); App.deleteThread(${t.id})" title="Delete Mission" style="flex-shrink:0;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                </button>
            </div>
        `).join('');
    },

    async selectThread(id, title, pushHistory = true) {
        this.activeThreadId = id;
        document.getElementById('chat-header-title').textContent = title || 'Mission Thread';
        document.getElementById('empty-state').style.display = 'none';
        document.getElementById('chat-workspace').style.display = 'flex';
        
        const appContainer = document.querySelector('.app-container');
        if (appContainer) appContainer.classList.add('mobile-chat-active');
        
        if (pushHistory !== false) {
            const url = new URL(window.location);
            url.searchParams.set('thread', id);
            window.history.pushState({ thread: id }, '', url);
        }

        this.renderThreads(this.allThreads);
        await this.loadMessages();
    },

    showSidebarMobile() {
        if (window.history.state && window.history.state.thread) {
            window.history.back();
        } else {
            this.closeActiveThreadUI();
        }
    },

    getNextDefaultThreadTitle() {
        const existingTitles = (this.allThreads || []).map(t => (t.title || '').trim().toLowerCase());
        const base = "New Mission";

        if (!existingTitles.includes(base.toLowerCase())) {
            return base;
        }

        let counter = 1;
        while (existingTitles.includes(`${base} ${counter}`.toLowerCase())) {
            counter++;
        }

        return `${base} ${counter}`;
    },

    createThread() {
        document.getElementById('inp-thread-title').value = this.getNextDefaultThreadTitle();
        this.openModal('modal-new-thread');
    },

    async submitNewThread() {
        const title = document.getElementById('inp-thread-title').value.trim() || 'New Mission';

        const fd = new FormData();
        fd.append('action', 'create_thread');
        fd.append('title', title);

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                this.closeModal('modal-new-thread');
                await this.loadThreads();
                this.selectThread(data.thread_id, data.title, true);
            }
        } catch (e) {
            console.error('Failed to create thread', e);
        }
    },

    deleteThread(id) {
        document.getElementById('confirm-title').textContent = 'Delete Mission';
        document.getElementById('confirm-message').textContent = 'Are you sure you want to delete this mission thread and its history?';
        
        const okBtn = document.getElementById('btn-confirm-ok');
        okBtn.onclick = async () => {
            this.closeModal('modal-confirm');
            const fd = new FormData();
            fd.append('action', 'delete_thread');
            fd.append('thread_id', id);

            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    if (this.activeThreadId == id) {
                        this.closeActiveThreadUI();
                        window.history.replaceState({ home: true }, '', window.location.pathname);
                    }
                    await this.loadThreads();
                    this.showToast('Mission deleted');
                }
            } catch (e) {
                console.error('Failed to delete thread', e);
            }
        };

        this.openModal('modal-confirm');
    },

    async loadMessages() {
        if (!this.activeThreadId) return;
        try {
            const res = await fetch(`index.php?action=get_messages&thread_id=${this.activeThreadId}`);
            const data = await res.json();
            if (data.success) {
                this.renderMessages(data.messages);
            }
        } catch (e) {
            console.error('Failed to load messages', e);
        }
    },

    activeMsgTarget: null,
    longPressTimer: null,
    currentMessages: [],
    pollTimer: null,
    stagedAttachments: [],

    renderMessages(messages) {
        this.currentMessages = messages || [];
        const container = document.getElementById('messages-container');
        container.innerHTML = this.currentMessages.map(m => {
            const isTool = m.content.startsWith('SYSTEM TOOL RESPONSE');
            const isErr = m.content.startsWith('⚠️') || m.content.includes('Error from OpenRouter');
            
            let cls = m.role === 'user' ? (isTool ? 'msg-tool' : 'msg-user') : 'msg-assistant';
            if (isErr) cls += ' msg-error-bubble';

            let formattedContent = "";
            if (isTool) {
                formattedContent = this.renderToolMessageContent(m.content);
            } else if (m.role === 'assistant') {
                formattedContent = this.renderMarkdown(m.content);
            } else {
                formattedContent = this.renderUserMessageContent(m.content);
            }

            return `<div class="msg-bubble ${cls}" data-msg-id="${m.id}" onpointerdown="App.startMsgLongPress(event, ${m.id}, '${m.role}', this)" onpointerup="App.endMsgLongPress()" onpointerleave="App.endMsgLongPress()" oncontextmenu="event.preventDefault()">${formattedContent}</div>`;
        }).join('');

        if (this.isProcessing) {
            this.showThinkingIndicator(this.currentThinkingStatus || 'Agent is working');
        }

        this.updateChatTokenCount(this.currentMessages);

        container.scrollTop = container.scrollHeight;
    },

    renderToolMessageContent(content) {
        if (!content) return "";

        // Extract turn footer if present in tool message content
        let turnFooterHtml = '';
        const turnMatch = content.match(/⏱️\s*Turn\s*(\d+)\s*Complete\s*\|\s*(\d+)\s*Turn\(s\)\s*Remaining/i);
        if (turnMatch) {
            const currentTurn = turnMatch[1];
            const remainingTurns = turnMatch[2];
            turnFooterHtml = `<div class="tool-card-turn-footer">⏱️ Turn ${currentTurn} Complete | ${remainingTurns} Turn(s) Remaining</div>`;
        }

        // 1. FILE EXPORTS
        if (content.includes('SYSTEM TOOL RESPONSE: EXPORTED FILE CONTENTS')) {
            const fileHeaderRegex = /^FILE:\s*([^\n]+)$/gm;
            let match;
            let filePaths = [];

            while ((match = fileHeaderRegex.exec(content)) !== null) {
                filePaths.push(match[1].trim());
            }

            let filesInfo = [];
            let totalChars = 0;
            const fileHeaders = [];
            const fileHeaderScanner = /^FILE:\s*([^\n]+)$/gm;

            while ((match = fileHeaderScanner.exec(content)) !== null) {
                fileHeaders.push({
                    path: match[1].trim(),
                    start: match.index,
                    bodyStart: fileHeaderScanner.lastIndex
                });
            }

            fileHeaders.forEach((header, index) => {
                const bodyEnd = index + 1 < fileHeaders.length
                    ? fileHeaders[index + 1].start
                    : content.length;
                const fileBody = content.slice(header.bodyStart, bodyEnd).trim();
                const sizeKb = (fileBody.length / 1024).toFixed(1) + ' KB';
                totalChars += fileBody.length;

                filesInfo.push({
                    path: header.path,
                    name: header.path.split('/').pop(),
                    size: sizeKb
                });
            });

            if (filesInfo.length === 0) {
                filesInfo = filePaths.map(p => ({ path: p, name: p.split('/').pop(), size: 'Exported' }));
            }

            const totalKb = (totalChars / 1024).toFixed(1);

            let fileListHtml = filesInfo.map(f => `
                <div class="tool-file-row">
                    <div style="display: flex; align-items: center; gap: 6px; min-width: 0; flex: 1;">
                        <span style="font-size: 12px;">📄</span>
                        <span class="tool-file-path" title="${this.escapeHtml(f.path)}">${this.escapeHtml(f.path)}</span>
                    </div>
                    <span class="tool-file-size-badge">${this.escapeHtml(f.size)}</span>
                </div>
            `).join('');

            return `
                <div class="tool-card tool-card-export">
                    <div class="tool-card-header">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 14px;">📤</span>
                            <span style="font-weight: 700; color: var(--text-primary);">Exported File Contents</span>
                        </div>
                        <span class="badge" style="background: rgba(99, 102, 241, 0.15); color: #a5b4fc; border-color: rgba(99, 102, 241, 0.3);">${filesInfo.length} Files (${totalKb} KB)</span>
                    </div>
                    <div class="tool-card-file-list">
                        ${fileListHtml}
                    </div>
                    ${turnFooterHtml}
                </div>
            `;
        }

        // 2. CODE AUDITOR RESULTS
        if (content.includes('SYSTEM TOOL RESPONSE: CODE AUDITOR RESULTS')) {
            const rawAuditContent = content.replace('SYSTEM TOOL RESPONSE: CODE AUDITOR RESULTS', '').trim();
            const auditBlocks = rawAuditContent.split(/(?=AUDIT ID:)/g).filter(b => b.includes('AUDIT ID:'));

            let auditCardsHtml = '';

            if (auditBlocks.length > 0) {
                auditCardsHtml = auditBlocks.map(b => {
                    const idMatch = b.match(/AUDIT ID:\s*([^\n]+)/);
                    const patternMatch = b.match(/PATTERN:\s*([^\n]+)/);
                    const instMatch = b.match(/INSTANCES:\s*([^\n]+)/);

                    const auditId = idMatch ? idMatch[1].trim() : 'Audit';
                    const pattern = patternMatch ? patternMatch[1].trim() : '';
                    const instances = instMatch ? parseInt(instMatch[1].trim()) : null;

                    // Anchored regex: matches true item headers (e.g. "  1. FILE: path/to/file.php (Line 123)") and consumes full context block
                    const occRegex = /^\s*(?:\d+\.|•)?\s*FILE:\s*([^\n\s(]+|[^\n]+?)\s*(?:\(Line\s*(\d+)\))?(?:\s*\n---\s*CONTEXT\s*---\n([\s\S]*?)\n---------------+)?/gm;

                    let matchesHtml = '';
                    let match;
                    let matchCount = 0;

                    while ((match = occRegex.exec(b)) !== null) {
                        const filePath = match[1] ? match[1].trim() : '';
                        const lineNum = match[2] ? match[2].trim() : null;
                        const contextText = match[3] ? match[3].trim() : '';

                        // Skip empty or header noise rows
                        if (!filePath || filePath.toLowerCase() === 'files:') continue;

                        matchCount++;
                        matchesHtml += `
                            <div style="background: rgba(0, 0, 0, 0.2); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 8px; padding: 8px 10px; margin-top: 6px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; font-family: monospace; font-size: 11px;">
                                    <span style="font-weight: 600; color: var(--text-primary); text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">🔍 ${this.escapeHtml(filePath)}</span>
                                    ${lineNum ? `<span class="badge" style="font-size: 9px; margin-left: 6px; flex-shrink: 0;">Line ${lineNum}</span>` : ''}
                                </div>
                                ${contextText ? `<pre style="font-family: monospace; font-size: 10px; color: var(--text-secondary); background: rgba(0,0,0,0.3); padding: 6px 8px; border-radius: 6px; margin-top: 6px; white-space: pre-wrap; word-break: break-word; overflow-x: auto; margin-bottom: 0;">${this.escapeHtml(contextText)}</pre>` : ''}
                            </div>
                        `;
                    }

                    if (matchCount === 0 || instances === 0) {
                        matchesHtml = `<div style="font-size:11px; color:var(--text-secondary); padding:4px 0;">No matching occurrences found.</div>`;
                    }

                    return `
                        <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border-color);">
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom: 6px; min-width: 0;">
                                <span style="font-weight:700; font-size:11px; color:var(--primary-accent); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; flex:1;" title="${this.escapeHtml(auditId)}">${this.escapeHtml(auditId)}</span>
                                ${pattern ? `<span style="font-family:monospace; font-size:10px; color:var(--text-secondary); background:var(--bg-color); padding:2px 6px; border-radius:4px; border:1px solid var(--border-color); flex-shrink:0; white-space:nowrap; max-width:180px; overflow:hidden; text-overflow:ellipsis;" title="${this.escapeHtml(pattern)}">Pattern: ${this.escapeHtml(pattern)}</span>` : ''}
                            </div>
                            <div class="tool-card-file-list">${matchesHtml}</div>
                        </div>
                    `;
                }).join('');
            } else {
                // Fallback for raw text audit outputs
                auditCardsHtml = `<pre class="code-block-body" style="font-size:11px; max-height:200px; overflow-y:auto; margin-top:6px;">${this.escapeHtml(rawAuditContent)}</pre>`;
            }

            return `
                <div class="tool-card tool-card-audit">
                    <div class="tool-card-header">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 14px;">🔎</span>
                            <span style="font-weight: 700; color: var(--text-primary);">Code Auditor Results</span>
                        </div>
                    </div>
                    ${auditCardsHtml}
                    ${turnFooterHtml}
                </div>
            `;
        }

        // 3. ATOMIC COMMIT SUCCESSFUL
        if (content.includes('SYSTEM TOOL RESPONSE: COMMIT SUCCESSFUL')) {
            const cleanMsg = content.replace('SYSTEM TOOL RESPONSE: COMMIT SUCCESSFUL', '').trim();
            const filesMatch = cleanMsg.match(/Updated Files:\s*([^\n]+)/);
            const updatedFiles = filesMatch ? filesMatch[1].trim() : '';

            return `
                <div class="tool-card tool-card-success">
                    <div class="tool-card-header">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 14px;">⚡</span>
                            <span style="font-weight: 700; color: var(--success);">Atomic Commit Successful</span>
                        </div>
                    </div>
                    <div style="font-size: 12px; color: var(--text-primary); margin-top: 6px;">
                        All patches passed preflight simulation and committed cleanly to disk.
                    </div>
                    ${updatedFiles ? `<div style="font-family: monospace; font-size: 11px; color: var(--text-secondary); margin-top: 6px; background: rgba(16, 185, 129, 0.08); padding: 6px 10px; border-radius: 6px; border: 1px solid rgba(16, 185, 129, 0.2);">Updated: ${this.escapeHtml(updatedFiles)}</div>` : ''}
                    ${turnFooterHtml}
                </div>
            `;
        }

        // 4. PATCH PREFLIGHT FAILED
        if (content.includes('SYSTEM TOOL RESPONSE: PATCH PREFLIGHT FAILED')) {
            const cleanReport = content.replace('SYSTEM TOOL RESPONSE: PATCH PREFLIGHT FAILED', '').trim();

            return `
                <div class="tool-card tool-card-error">
                    <div class="tool-card-header">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 14px;">⚠️</span>
                            <span style="font-weight: 700; color: var(--danger);">Patch Preflight Failed (0 Changes Committed)</span>
                        </div>
                    </div>
                    <div style="font-family: monospace; font-size: 11px; color: #fca5a5; margin-top: 6px; background: rgba(239, 68, 68, 0.08); padding: 8px 10px; border-radius: 6px; border: 1px solid rgba(239, 68, 68, 0.2); white-space: pre-wrap; max-height: 140px; overflow-y: auto;">${this.escapeHtml(cleanReport)}</div>
                    ${turnFooterHtml}
                </div>
            `;
        }

        // Fallback
        return `<div class="tool-card"><div class="tool-card-header"><span style="font-weight:700;">System Tool Response</span></div><pre class="code-block-body" style="max-height:120px; overflow-y:auto; font-size:11px; margin-top:6px;">${this.escapeHtml(content.trim())}</pre>${turnFooterHtml}</div>`;
    },

    renderUserMessageContent(content) {
        if (!content) return "";

        const metaMatch = content.match(/<!-- ATTACHMENTS_META:(.*?) -->/);
        let textPrompt = content;
        let attachments = [];

        if (metaMatch) {
            try {
                attachments = JSON.parse(metaMatch[1]);
            } catch (e) {}
            textPrompt = content.substring(0, metaMatch.index).trim();
        } else if (content.includes('=== ATTACHMENT:')) {
            const idx = content.indexOf('=== ATTACHMENT:');
            textPrompt = content.substring(0, idx).trim();

            const regex = /=== ATTACHMENT:\s*(.*?)\s*\((.*?)\)\s*===/g;
            let match;
            while ((match = regex.exec(content)) !== null) {
                attachments.push({ name: match[1], size: match[2], type: 'file' });
            }
        }

        let html = textPrompt ? this.escapeHtml(textPrompt) : "";

        if (attachments.length > 0) {
            let pillsHtml = attachments.map(att => {
                const icon = att.type === 'image' ? '🖼️' : '📄';
                return `<div class="user-msg-attachment-pill">
                    <span style="flex-shrink: 0; margin-top: 1px;">${icon}</span>
                    <div style="flex: 1; min-width: 0; word-break: break-all;">
                        <span style="font-weight: 600; display: inline;">${this.escapeHtml(att.name)}</span>
                        <span style="opacity: 0.6; font-size: 10px; margin-left: 6px; display: inline-block;">(${this.escapeHtml(att.size || 'Attached')})</span>
                    </div>
                </div>`;
            }).join('');

            html += `<div class="user-msg-attachments-container">${pillsHtml}</div>`;
        }

        return html;
    },

    updateChatTokenCount(messages) {
        const badge = document.getElementById('chat-token-badge');
        if (!badge) return;

        if (!messages || messages.length === 0) {
            badge.style.display = 'none';
            return;
        }

        let totalChars = 0;
        messages.forEach(m => {
            if (m.content) totalChars += m.content.length;
        });

        const estTokens = Math.round(totalChars / 4);
        const estKb = (totalChars / 1024).toFixed(1);

        let formattedTokens = estTokens.toLocaleString();
        if (estTokens >= 1000) {
            formattedTokens = (estTokens / 1000).toFixed(1) + 'K';
        }

        badge.textContent = `~${formattedTokens} Tokens (${estKb} KB)`;
        badge.title = `Total Chat Content: ${totalChars.toLocaleString()} chars (~${estTokens.toLocaleString()} tokens)`;
        badge.style.display = 'inline-block';
    },

    openAgenticModal() {
        if (!this.activeThreadId || this.isProcessing) return;
        const currentInput = document.getElementById('chat-input').value.trim();
        const goalInp = document.getElementById('inp-agentic-goal');
        if (goalInp && currentInput) {
            goalInp.value = currentInput;
        }
        this.openModal('modal-agentic-mode');
    },

    async launchAgenticMode() {
        if (!this.activeThreadId || this.isProcessing) return;

        const turnsInp = document.getElementById('inp-agentic-turns');
        const goalInp = document.getElementById('inp-agentic-goal');

        const turns = parseInt(turnsInp ? turnsInp.value : '10') || 10;
        const goalText = (goalInp ? goalInp.value.trim() : '') || "Proceed with the agreed plan and execute the required modifications.";

        this.closeModal('modal-agentic-mode');

        // Clear input area
        document.getElementById('chat-input').value = '';
        if (goalInp) goalInp.value = '';

        let content = `🤖 AGENTIC AUTOMATION MODE ACTIVATED\nMaximum Allowed Turns: ${turns}\nAll patch transactions and tool actions are automatically approved during this execution, and an explicit "Let's go" confirmation has been issued.\nThis is Turn 1.\n\nTask Goal: ${goalText}\n\nNote: When all modifications and tasks are completed, provide a final report without tool blocks to conclude the automation process.`;

        // Append staged attachments if present
        if (this.stagedAttachments.length > 0) {
            let attPayload = "";
            let attSummary = [];

            this.stagedAttachments.forEach(att => {
                attSummary.push({ name: att.name, size: att.size, type: att.type });

                if (att.type === 'image') {
                    attPayload += `\n\n![${att.name}](${att.dataUrl})`;
                } else {
                    const ext = att.name.split('.').pop().toLowerCase() || 'text';
                    attPayload += `\n\n=== ATTACHMENT: ${att.name} (${att.size}) ===\n\`\`\`${ext}\n${att.content}\n\`\`\``;
                }
            });

            const summaryMeta = `\n<!-- ATTACHMENTS_META:${JSON.stringify(attSummary)} -->`;
            content += summaryMeta + attPayload;
            this.stagedAttachments = [];
            this.renderAttachmentTray();
        }

        // Render user activation prompt
        const container = document.getElementById('messages-container');
        const userDiv = document.createElement('div');
        userDiv.className = 'msg-bubble msg-user';
        userDiv.innerHTML = this.renderUserMessageContent(content);
        container.appendChild(userDiv);
        container.scrollTop = container.scrollHeight;

        await this.streamTurn('stream_message', {
            thread_id: this.activeThreadId,
            content: content,
            max_iterations: turns
        });
    },

    triggerFileAttachment() {
        const inp = document.getElementById('inp-chat-file');
        if (inp) inp.click();
    },

    handleFileAttachmentSelect(e) {
        const files = Array.from(e.target.files || []);
        if (files.length === 0) return;

        files.forEach(file => {
            const isImg = file.type.startsWith('image/');
            const sizeStr = (file.size / 1024).toFixed(1) + ' KB';

            if (isImg) {
                const reader = new FileReader();
                reader.onload = (evt) => {
                    this.stagedAttachments.push({
                        name: file.name,
                        type: 'image',
                        size: sizeStr,
                        dataUrl: evt.target.result
                    });
                    this.renderAttachmentTray();
                };
                reader.readAsDataURL(file);
            } else {
                const reader = new FileReader();
                reader.onload = (evt) => {
                    this.stagedAttachments.push({
                        name: file.name,
                        type: 'text',
                        size: sizeStr,
                        content: evt.target.result
                    });
                    this.renderAttachmentTray();
                };
                reader.readAsText(file);
            }
        });

        e.target.value = '';
    },

    renderAttachmentTray() {
        const tray = document.getElementById('attachment-preview-bar');
        if (!tray) return;

        if (this.stagedAttachments.length === 0) {
            tray.style.display = 'none';
            tray.innerHTML = '';
            return;
        }

        tray.style.display = 'flex';
        tray.innerHTML = this.stagedAttachments.map((att, idx) => {
            const thumb = att.type === 'image' 
                ? `<img src="${att.dataUrl}" class="attachment-thumb">`
                : `<span style="font-size:12px;">📄</span>`;
            return `
                <div class="attachment-pill">
                    ${thumb}
                    <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:110px;">${this.escapeHtml(att.name)}</span>
                    <span style="opacity:0.5; font-size:9px;">(${att.size})</span>
                    <button type="button" class="btn-remove-attachment" onclick="App.removeAttachment(${idx})">×</button>
                </div>
            `;
        }).join('');
    },

    removeAttachment(idx) {
        this.stagedAttachments.splice(idx, 1);
        this.renderAttachmentTray();
    },

    renderMarkdown(text) {
        if (!text) return "";
        let trimmed = text.trim();

        // Preprocess ~~~ tilde blocks into standard ``` fenced code blocks
        trimmed = trimmed.replace(/~~~(?:Patcher Transaction:[^\n]*)?\n([\s\S]*?)~~~/gi, (match, innerCode) => {
            return "```text\n" + innerCode.trim() + "\n```";
        });

        if (typeof marked !== 'undefined') {
            try {
                marked.setOptions({
                    gfm: true,
                    breaks: false
                });
                let html = marked.parse(trimmed);

                // Convert code blocks into custom code-block-cards or patch representation cards
                html = html.replace(/<pre><code class="(?:language-)?([a-z0-9_]*)">([\s\S]*?)<\/code><\/pre>/gi, (match, lang, code) => {
                    if (code.includes('#ACTION:') || code.includes('#PATCH_ID:')) {
                        const patchCards = this.parsePatchBlocksFromCode(code);
                        if (patchCards) return patchCards;
                    }
                    const langLabel = lang ? lang.toUpperCase() : 'CODE';
                    return `<div class="code-block-card"><div class="code-block-header"><span>${langLabel}</span><button type="button" class="btn-copy-code" onclick="App.copyCodeBlock(this)">Copy</button></div><pre class="code-block-body"><code>${code}</code></pre></div>`;
                });

                html = html.replace(/<pre><code>([\s\S]*?)<\/code><\/pre>/gi, (match, code) => {
                    if (code.includes('#ACTION:') || code.includes('#PATCH_ID:')) {
                        const patchCards = this.parsePatchBlocksFromCode(code);
                        if (patchCards) return patchCards;
                    }
                    return `<div class="code-block-card"><div class="code-block-header"><span>CODE</span><button type="button" class="btn-copy-code" onclick="App.copyCodeBlock(this)">Copy</button></div><pre class="code-block-body"><code>${code}</code></pre></div>`;
                });

                return html;
            } catch (e) {
                console.error("Marked parse error", e);
            }
        }

        return this.escapeHtml(trimmed);
    },

    parsePatchBlocksFromCode(code) {
        const unescaped = code.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
        
        const rawBlocks = unescaped.split(/(?=#ACTION:)/g);
        const patches = [];

        rawBlocks.forEach(block => {
            if (!block.includes('#ACTION:') && !block.includes('#PATCH_ID:')) return;

            const actionMatch = block.match(/#ACTION:\s*([^\n]+)/);
            const idMatch = block.match(/#PATCH_ID:\s*([^\n]+)/);
            const fileMatch = block.match(/#FILE:\s*([^\n]+)/);
            const commentMatch = block.match(/#COMMENT:\s*([^\n]+)/);

            const actionRaw = actionMatch ? actionMatch[1].trim() : 'file_update';
            const patchId = idMatch ? idMatch[1].trim() : 'PATCH';
            const file = fileMatch ? fileMatch[1].trim() : '';
            const comment = commentMatch ? commentMatch[1].trim() : '';

            let actionLabel = 'UPDATE';
            let actionType = 'update';

            if (actionRaw.includes('create') || actionRaw.includes('overwrite')) {
                actionLabel = 'CREATE';
                actionType = 'create';
            } else if (actionRaw.includes('delete') || actionRaw.includes('cut')) {
                actionLabel = 'DELETE';
                actionType = 'delete';
            } else if (actionRaw.includes('audit')) {
                actionLabel = 'AUDIT';
                actionType = 'audit';
            } else if (actionRaw.includes('export')) {
                actionLabel = 'EXPORT';
                actionType = 'export';
            } else if (actionRaw.includes('trace')) {
                actionLabel = 'TRACE';
                actionType = 'trace';
            }

            const latestSnippet = this.getPatchLatestSnippet(block);

            patches.push({
                id: patchId,
                actionRaw: actionRaw,
                actionLabel: actionLabel,
                actionType: actionType,
                file: file,
                comment: comment,
                rawBlock: block.trim(),
                latestSnippet: latestSnippet
            });
        });

        if (patches.length === 0) return null;

        let groupB64 = "";
        try {
            groupB64 = btoa(unescape(encodeURIComponent(unescaped.trim())));
        } catch (e) {}

        let html = '<div class="patch-cards-group">';
        patches.forEach(p => {
            let b64 = "";
            try {
                b64 = btoa(unescape(encodeURIComponent(p.rawBlock)));
            } catch (e) {}

            html += `
                <div class="patch-card">
                    <div class="patch-card-header">
                        <div class="patch-card-title-row">
                            <span style="font-size: 13px;">⚡</span>
                            <span class="patch-card-id">${this.escapeHtml(p.id)}</span>
                            <span class="patch-action-badge patch-badge-${p.actionType}">${this.escapeHtml(p.actionLabel)}</span>
                        </div>
                        <button type="button" class="btn-copy-patch" onclick="App.copySinglePatch(this)" data-patch-b64="${b64}">Copy</button>
                    </div>
                    ${p.file ? `<div class="patch-card-file">📄 <code>${this.escapeHtml(p.file)}</code></div>` : ''}
                    ${p.comment ? `<div class="patch-card-comment">${this.escapeHtml(p.comment)}</div>` : ''}
                    ${p.latestSnippet ? `
                        <div class="patch-live-stream">
                            <span class="patch-live-stream-text"><code>${this.escapeHtml(p.latestSnippet)}</code></span>
                        </div>
                    ` : ''}
                </div>
            `;
        });

        const hasModifyingPatches = patches.some(p =>
            !['file_export', 'logic_trace', 'audit', 'refactor', 'edit_log'].includes(p.actionRaw)
        );

        if (hasModifyingPatches && groupB64) {
            html += `
                <div class="patch-group-actions">
                    <button type="button" class="btn-execute-patches" onclick="App.executeHistoricalPatchGroup(this)" data-patch-b64="${groupB64}">
                        Execute Patches
                    </button>
                </div>
            `;
        }

        html += '</div>';

        return html;
    },

    getPatchLatestSnippet(rawBlock) {
        if (!rawBlock) return "";
        let clean = rawBlock.trim();
        if (clean.endsWith('#END')) {
            clean = clean.substring(0, clean.length - 4).trim();
        }
        const lines = clean.split('\n').map(l => l.trim()).filter(l => l);
        if (lines.length === 0) return "";

        let tailText = lines.slice(-2).join(' ');
        const words = tailText.split(/\s+/);
        if (words.length > 12) {
            tailText = words.slice(-12).join(' ');
        }
        return tailText;
    },

    copySinglePatch(btn) {
        const b64 = btn.getAttribute('data-patch-b64');
        if (!b64) return;
        try {
            const rawText = decodeURIComponent(escape(atob(b64)));
            navigator.clipboard.writeText(rawText).then(() => {
                const orig = btn.textContent;
                btn.textContent = 'Copied!';
                btn.style.color = 'var(--success)';
                btn.style.borderColor = 'var(--success)';
                setTimeout(() => {
                    btn.textContent = orig;
                    btn.style.color = '';
                    btn.style.borderColor = '';
                }, 1500);
            });
        } catch (e) {
            console.error("Copy patch error", e);
        }
    },

    async executeHistoricalPatchGroup(btn) {
        const b64 = btn.getAttribute('data-patch-b64');
        if (!b64) return;

        let rawText = '';
        try {
            rawText = decodeURIComponent(escape(atob(b64)));
        } catch (e) {
            this.showManualPatchResult({
                status: 'error',
                message: 'Could not decode the historical patch group payload.'
            });
            return;
        }

        const parsed = this.cpDescribeHistoricalPatchGroup(rawText);
        const confirmTitle = document.getElementById('confirm-title');
        const confirmMessage = document.getElementById('confirm-message');
        const confirmOk = document.getElementById('btn-confirm-ok');

        if (!confirmTitle || !confirmMessage || !confirmOk) {
            this.showManualPatchResult({
                status: 'error',
                message: 'Agent Studio confirmation dialog is unavailable.'
            });
            return;
        }

        confirmTitle.textContent = 'Execute Historical Patches';
        confirmMessage.textContent = `Apply ${parsed.count} patch(es) as one atomic group to the current live files? This preserves patch order and in-memory dependencies. Chat history will not be changed.`;
        confirmOk.textContent = 'Execute Patches';
        confirmOk.onclick = async () => {
            this.closeModal('modal-confirm');

            btn.disabled = true;
            const originalText = btn.textContent.trim();
            btn.textContent = 'Executing...';

            const fd = new FormData();
            fd.append('action', 'manual_execute_patch');
            fd.append('raw_input', rawText);

            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    body: fd
                });

                let data;
                try {
                    data = await response.json();
                } catch (e) {
                    data = {
                        status: 'error',
                        message: `Invalid execution response (HTTP ${response.status}).`
                    };
                }

                this.showManualPatchResult(data);

                if (data.status === 'committed') {
                    btn.textContent = 'Executed';
                    btn.classList.add('is-executed');
                } else {
                    btn.textContent = originalText;
                }
            } catch (e) {
                btn.textContent = originalText;
                this.showManualPatchResult({
                    status: 'error',
                    message: e.message || 'Network error during grouped patch execution.'
                });
            } finally {
                btn.disabled = false;
            }
        };

        this.openModal('modal-confirm');
    },

    cpDescribeHistoricalPatchGroup(rawText) {
        const blocks = rawText.split(/(?=#ACTION:)/g).filter(block => block.includes('#ACTION:'));
        const first = blocks[0] || rawText;
        const fileMatches = rawText.match(/^#FILE:\s*([^\n]+)/gmi) || [];

        return {
            count: blocks.length || 1,
            action: (first.match(/^#ACTION:\s*([^\n]+)/mi) || [])[1] || 'patch',
            files: fileMatches.map(line => line.replace(/^#FILE:\s*/i, '').trim())
        };
    },

    cpDescribeHistoricalPatch(rawText) {
        const actionMatch = rawText.match(/^#ACTION:\s*([^\n]+)/mi);
        const idMatch = rawText.match(/^#PATCH_ID:\s*([^\n]+)/mi);
        const fileMatch = rawText.match(/^#FILE:\s*([^\n]+)/mi);

        return {
            action: actionMatch ? actionMatch[1].trim() : 'patch',
            id: idMatch ? idMatch[1].trim() : '',
            file: fileMatch ? fileMatch[1].trim() : ''
        };
    },

    copyCodeBlock(btn) {
        const card = btn.closest('.code-block-card');
        if (!card) return;
        const codeEl = card.querySelector('code');
        if (codeEl) {
            navigator.clipboard.writeText(codeEl.innerText);
            const orig = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(() => btn.textContent = orig, 1500);
        }
    },

    startMsgLongPress(e, msgId, role, el) {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        this.endMsgLongPress();
        
        const msgObj = this.currentMessages.find(m => m.id == msgId);
        const content = msgObj ? msgObj.content : el.innerText;

        this.longPressTimer = setTimeout(() => {
            if (navigator.vibrate) navigator.vibrate(15);
            this.openMsgActionSheet(msgId, role, content);
        }, 600);
    },

    showThinkingIndicator(statusText = 'Agent is working') {
        const container = document.getElementById('messages-container');
        const oldInd = document.getElementById('thinking-indicator');
        if (oldInd) oldInd.remove();

        const div = document.createElement('div');
        div.id = 'thinking-indicator';
        div.className = 'msg-bubble msg-thinking';
        div.innerHTML = `<span>${this.escapeHtml(statusText)}</span><div class="typing-dots"><span></span><span></span><span></span></div>`;
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
    },

    hideThinkingIndicator() {
        const ind = document.getElementById('thinking-indicator');
        if (ind) ind.remove();
    },

    endMsgLongPress() {
        if (this.longPressTimer) {
            clearTimeout(this.longPressTimer);
            this.longPressTimer = null;
        }
    },

    openMsgActionSheet(msgId, role, content) {
        this.activeMsgTarget = { id: msgId, role: role, content: content };
        const editBtn = document.getElementById('btn-action-edit');
        if (editBtn) {
            editBtn.style.display = (role === 'user') ? 'block' : 'none';
        }
        this.openModal('modal-msg-actions');
    },

    copyMessageText() {
        if (!this.activeMsgTarget) return;
        navigator.clipboard.writeText(this.activeMsgTarget.content);
        this.closeModal('modal-msg-actions');
        this.showToast('Copied to clipboard');
    },

    copyFullThread() {
        if (!this.currentMessages || this.currentMessages.length === 0) {
            this.showToast('No chat messages to copy', 'error');
            return;
        }

        const title = document.getElementById('chat-header-title').textContent || 'Mission';
        let formatted = `~~~~
# Mission: ${title}

`;

        this.currentMessages.forEach(m => {
            let roleLabel = 'User';
            if (m.role === 'assistant') {
                roleLabel = 'Assistant';
            } else if (m.content.startsWith('SYSTEM TOOL RESPONSE')) {
                roleLabel = 'System Tool';
            }

            formatted += `### ${roleLabel}:\n${m.content.trim()}\n\n`;
        });

        formatted += `~~~~`;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(formatted).then(() => {
                this.showToast('Copied full thread (4 tildes)');
            }).catch(() => {
                this.fallbackCopyText(formatted);
            });
        } else {
            this.fallbackCopyText(formatted);
        }
    },

    fallbackCopyText(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            this.showToast('Copied full thread (4 tildes)');
        } catch (e) {
            this.showToast('Copy failed', 'error');
        }
        document.body.removeChild(ta);
    },

    openEditMessageModal() {
        if (!this.activeMsgTarget) return;
        this.closeModal('modal-msg-actions');
        document.getElementById('inp-edit-msg-content').value = this.activeMsgTarget.content;
        this.openModal('modal-edit-msg');
    },

    async submitEditMessage() {
        if (!this.activeMsgTarget) return;
        const newContent = document.getElementById('inp-edit-msg-content').value.trim();
        if (!newContent) return;

        this.closeModal('modal-edit-msg');
        this.isProcessing = true;

        // Truncate messages in memory & UI, and render user edit optimistically
        const targetIdx = this.currentMessages.findIndex(m => m.id == this.activeMsgTarget.id);
        if (targetIdx !== -1) {
            this.currentMessages = this.currentMessages.slice(0, targetIdx);
            this.currentMessages.push({ id: this.activeMsgTarget.id, role: 'user', content: newContent });
            this.renderMessages(this.currentMessages);
        }

        this.showThinkingIndicator('Agent is processing edited prompt');

        if (this.pollTimer) clearInterval(this.pollTimer);
        this.pollTimer = setInterval(() => this.loadMessages(), 2000);

        const fd = new FormData();
        fd.append('action', 'edit_message');
        fd.append('message_id', this.activeMsgTarget.id);
        fd.append('content', newContent);

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                await this.loadMessages();
            } else {
                this.showToast(data.error || 'Failed to edit message', 'error');
            }
        } catch (e) {
            console.error(e);
            this.showToast('Error editing message', 'error');
        } finally {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
            this.hideThinkingIndicator();
            this.isProcessing = false;
        }
    },

    async executeBranchThread() {
        if (!this.activeMsgTarget) return;
        const msgId = this.activeMsgTarget.id;
        this.closeModal('modal-msg-actions');

        const fd = new FormData();
        fd.append('action', 'branch_thread');
        fd.append('message_id', msgId);

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                this.showToast('Branched into new mission');
                await this.loadThreads();
                this.selectThread(data.new_thread_id, data.title);
            } else {
                this.showToast(data.error || 'Failed to branch thread', 'error');
            }
        } catch (e) {
            console.error(e);
            this.showToast('Error branching thread', 'error');
        }
    },

    async executeDeleteMessage() {
        if (!this.activeMsgTarget) return;
        const msgId = this.activeMsgTarget.id;
        this.closeModal('modal-msg-actions');

        const fd = new FormData();
        fd.append('action', 'delete_message');
        fd.append('message_id', msgId);

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                this.showToast('Message deleted');
                await this.loadMessages();
            } else {
                this.showToast(data.error || 'Failed to delete message', 'error');
            }
        } catch (e) {
            console.error(e);
        }
    },

    async streamTurn(actionName, params) {
        this.isProcessing = true;
        this.currentThinkingStatus = 'Agent is thinking';
        this.showThinkingIndicator(this.currentThinkingStatus);

        const sendBtn = document.getElementById('btn-send-message');
        const stopBtn = document.getElementById('btn-stop-agent');
        if (sendBtn) sendBtn.style.display = 'none';
        if (stopBtn) stopBtn.style.display = 'flex';

        this.activeAbortController = new AbortController();

        const fd = new FormData();
        fd.append('action', actionName);
        for (const [k, v] of Object.entries(params)) {
            fd.append(k, v);
        }

        let assistantBubble = null;
        let streamedText = "";

        try {
            const response = await fetch('index.php', {
                method: 'POST',
                body: fd,
                signal: this.activeAbortController.signal
            });
            const reader = response.body.getReader();
            const decoder = new TextDecoder("utf-8");
            let buffer = "";

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const events = buffer.split("\n\n");
                buffer = events.pop();

                for (const eventStr of events) {
                    if (!eventStr.trim()) continue;

                    let eventName = "message";
                    let dataStr = "";

                    const lines = eventStr.split("\n");
                    for (const line of lines) {
                        if (line.startsWith("event: ")) eventName = line.substring(7).trim();
                        if (line.startsWith("data: ")) dataStr = line.substring(6).trim();
                    }

                    if (!dataStr) continue;

                    try {
                        const data = JSON.parse(dataStr);

                        if (eventName === "thinking") {
                            this.currentThinkingStatus = data.status || 'Agent is thinking';
                            this.showThinkingIndicator(this.currentThinkingStatus);
                        } else if (eventName === "chunk") {
                            if (!data.chunk) continue;

                            if (!assistantBubble) {
                                this.hideThinkingIndicator();
                                const container = document.getElementById('messages-container');
                                assistantBubble = document.createElement('div');
                                assistantBubble.className = 'msg-bubble msg-assistant';
                                container.appendChild(assistantBubble);
                            }

                            streamedText += data.chunk;
                            assistantBubble.innerHTML = this.renderMarkdown(streamedText);
                            const container = document.getElementById('messages-container');
                            container.scrollTop = container.scrollHeight;
                        } else if (eventName === "tool_result") {
                            assistantBubble = null;
                            streamedText = "";
                            await this.loadMessages();
                            this.currentThinkingStatus = 'Executing tool & running next turn';
                            this.showThinkingIndicator(this.currentThinkingStatus);
                        } else if (eventName === "done") {
                            await this.loadMessages();
                        } else if (eventName === "error") {
                            this.hideThinkingIndicator();
                            this.showToast(data.error || "Turn error", "error");
                            await this.loadMessages();
                        }
                    } catch (e) {
                        console.error("SSE parse error", e);
                    }
                }
            }
        } catch (e) {
            console.error("Streaming failed", e);
            this.showToast("Connection error during stream", "error");
        } finally {
            this.hideThinkingIndicator();
            this.isProcessing = false;
            this.activeAbortController = null;

            const sendBtn = document.getElementById('btn-send-message');
            const stopBtn = document.getElementById('btn-stop-agent');
            if (sendBtn) sendBtn.style.display = 'flex';
            if (stopBtn) stopBtn.style.display = 'none';

            await this.loadMessages();
            this.refreshCredits();
        }
    },

    async sendMessage() {
        const input = document.getElementById('chat-input');
        let content = input.value.trim();
        if ((!content && this.stagedAttachments.length === 0) || !this.activeThreadId || this.isProcessing) return;

        input.value = '';

        // Format and append staged attachments if present
        if (this.stagedAttachments.length > 0) {
            let attPayload = "";
            let attSummary = [];

            this.stagedAttachments.forEach(att => {
                attSummary.push({ name: att.name, size: att.size, type: att.type });

                if (att.type === 'image') {
                    attPayload += `\n\n![${att.name}](${att.dataUrl})`;
                } else {
                    const ext = att.name.split('.').pop().toLowerCase() || 'text';
                    attPayload += `\n\n=== ATTACHMENT: ${att.name} (${att.size}) ===\n\`\`\`${ext}\n${att.content}\n\`\`\``;
                }
            });

            const summaryMeta = `\n<!-- ATTACHMENTS_META:${JSON.stringify(attSummary)} -->`;
            content = (content ? content : "Attached files:") + summaryMeta + attPayload;
            this.stagedAttachments = [];
            this.renderAttachmentTray();
        }

        // Append user prompt optimistically
        const container = document.getElementById('messages-container');
        const userDiv = document.createElement('div');
        userDiv.className = 'msg-bubble msg-user';
        userDiv.innerHTML = this.renderUserMessageContent(content);
        container.appendChild(userDiv);
        container.scrollTop = container.scrollHeight;

        await this.streamTurn('stream_message', {
            thread_id: this.activeThreadId,
            content: content
        });
    },

    async submitEditMessage() {
        if (!this.activeMsgTarget || this.isProcessing) return;
        const newContent = document.getElementById('inp-edit-msg-content').value.trim();
        if (!newContent) return;

        this.closeModal('modal-edit-msg');

        // Truncate messages in memory and render optimistically
        const targetIdx = this.currentMessages.findIndex(m => m.id == this.activeMsgTarget.id);
        if (targetIdx !== -1) {
            this.currentMessages = this.currentMessages.slice(0, targetIdx);
            this.currentMessages.push({ id: this.activeMsgTarget.id, role: 'user', content: newContent });
            this.renderMessages(this.currentMessages);
        }

        await this.streamTurn('stream_edit_message', {
            message_id: this.activeMsgTarget.id,
            content: newContent
        });
    },

    allModels: [],
    starredModels: [],
    filterFreeOnly: false,

    async openModelPicker() {
        this.openModal('modal-model-picker');
        const list = document.getElementById('model-picker-list');
        list.innerHTML = '<div style="text-align:center; padding:20px; font-size:12px; color:var(--text-secondary);">Loading OpenRouter models...</div>';

        try {
            const res = await fetch('index.php?action=get_models');
            const data = await res.json();
            if (data.success) {
                this.allModels = data.models || [];
                this.starredModels = data.starred_models || [];
                this.filterModels();
            } else {
                list.innerHTML = '<div style="text-align:center; padding:20px; color:var(--danger);">Failed to load models</div>';
            }
        } catch (e) {
            console.error('Failed to fetch models', e);
            list.innerHTML = '<div style="text-align:center; padding:20px; color:var(--danger);">Error loading models</div>';
        }
    },

    toggleFreeFilter() {
        this.filterFreeOnly = !this.filterFreeOnly;
        const btn = document.getElementById('btn-filter-free');
        btn.classList.toggle('active', this.filterFreeOnly);
        this.filterModels();
    },

    filterModels() {
        const query = (document.getElementById('inp-model-search').value || '').toLowerCase().trim();
        const list = document.getElementById('model-picker-list');

        const filtered = this.allModels.filter(m => {
            const matchesQuery = !query || m.name.toLowerCase().includes(query) || m.id.toLowerCase().includes(query);
            const matchesFree = !this.filterFreeOnly || m.is_free;
            return matchesQuery && matchesFree;
        });

        const starredSet = new Set(this.starredModels || []);

        filtered.sort((a, b) => {
            const aStarred = starredSet.has(a.id);
            const bStarred = starredSet.has(b.id);
            if (aStarred && !bStarred) return -1;
            if (!aStarred && bStarred) return 1;
            return 0;
        });

        if (filtered.length === 0) {
            list.innerHTML = '<div style="text-align:center; padding:20px; font-size:12px; color:var(--text-secondary);">No models match your search query</div>';
            return;
        }

        list.innerHTML = filtered.slice(0, 80).map(m => {
            const isStarred = starredSet.has(m.id);
            const ctxKb = m.context_length ? Math.round(m.context_length / 1024) + 'K' : 'N/A';
            const promptPrice = m.prompt_price === 0 ? 'Free' : `$${m.prompt_price.toFixed(2)}/1M`;
            const compPrice = m.completion_price === 0 ? 'Free' : `$${m.completion_price.toFixed(2)}/1M`;

            return `
                <div class="model-card ${isStarred ? 'starred' : ''}" onclick="App.selectModel('${m.id}')">
                    <div class="model-card-header">
                        <div style="flex: 1; min-width: 0;">
                            <div class="model-card-title">${this.escapeHtml(m.name)}</div>
                            <div class="model-card-id">${this.escapeHtml(m.id)}</div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            ${m.is_free ? '<span class="badge badge-free">FREE</span>' : ''}
                            <button type="button" class="btn-star-model ${isStarred ? 'active' : ''}" onclick="App.toggleStarModel(event, '${m.id}')" title="${isStarred ? 'Unstar model' : 'Star model'}">
                                ${isStarred ? '★' : '☆'}
                            </button>
                        </div>
                    </div>
                    <div class="model-badges">
                        <span class="badge">Context: ${ctxKb}</span>
                        <span class="badge">Prompt: ${promptPrice}</span>
                        <span class="badge">Completion: ${compPrice}</span>
                    </div>
                </div>
            `;
        }).join('');
    },

    async toggleStarModel(e, modelId) {
        if (e) e.stopPropagation();

        const fd = new FormData();
        fd.append('action', 'toggle_star_model');
        fd.append('model_id', modelId);

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                this.starredModels = data.starred_models || [];
                this.filterModels();
            } else {
                this.showToast(data.error || 'Failed to star model', 'error');
            }
        } catch (err) {
            console.error(err);
            this.showToast('Error starring model', 'error');
        }
    },

    selectModel(modelId) {
        document.getElementById('inp-model').value = modelId;
        this.closeModal('modal-model-picker');
    },

    toggleApiKeyVisibility() {
        const inp = document.getElementById('inp-api-key');
        const btn = document.getElementById('btn-toggle-key');
        if (inp.type === 'password') {
            inp.type = 'text';
            btn.textContent = 'Hide';
        } else {
            inp.type = 'password';
            btn.textContent = 'Show';
        }
    },

    currentContextMode: 'foundation',

    async selectContextMode(mode, fetchFiles = true) {
        this.currentContextMode = mode;
        const inp = document.getElementById('inp-context-mode');
        if (inp) inp.value = mode;

        ['none', 'foundation', 'project'].forEach(m => {
            const btn = document.getElementById(`btn-ctx-${m}`);
            if (btn) btn.classList.toggle('active', m === mode);
        });

        const labelEl = document.getElementById('context-mode-title-label');
        if (labelEl) {
            if (mode === 'none') labelEl.textContent = 'Disabled';
            else if (mode === 'foundation') labelEl.textContent = 'Foundation';
            else if (mode === 'project') labelEl.textContent = 'Foundation + Project';
        }

        const wrap = document.getElementById('foundation-files-wrap');
        if (mode === 'none') {
            if (wrap) {
                wrap.style.opacity = '0.4';
                wrap.style.pointerEvents = 'none';
            }
            this.renderFoundationFiles([]);
        } else {
            if (wrap) {
                wrap.style.opacity = '1';
                wrap.style.pointerEvents = 'auto';
            }
            if (fetchFiles) {
                try {
                    const res = await fetch(`index.php?action=get_context_files&tier=${mode}`);
                    const data = await res.json();
                    if (data.success) {
                        this.renderFoundationFiles(data.files || []);
                    }
                } catch (e) {
                    console.error('Failed to fetch context files for mode', mode, e);
                }
            }
        }
    },

    async openSettings() {
        try {
            const res = await fetch('index.php?action=get_settings');
            const data = await res.json();
            if (data.success) {
                const s = data.settings;
                document.getElementById('inp-model').value = s.model || 'anthropic/claude-3.5-sonnet';
                document.getElementById('inp-system-prompt').value = s.system_prompt || '';
                document.getElementById('inp-max-iter').value = s.max_iterations || 10;
                document.getElementById('inp-api-key').value = data.api_key || '';

                const mode = data.context_mode || s.context_mode || (s.include_foundation_context !== false ? 'foundation' : 'none');
                this.selectContextMode(mode, false);
                this.renderFoundationFiles(data.context_files || data.foundation_files || []);

                this.openModal('modal-settings');
            }
        } catch (e) {
            console.error(e);
        }
    },

    renderFoundationFiles(files) {
        const listEl = document.getElementById('foundation-files-list');
        const countEl = document.getElementById('foundation-file-count');
        const sizeEl = document.getElementById('foundation-file-size');
        if (!listEl) return;

        if (countEl) countEl.textContent = files.length;

        let totalKb = 0;
        files.forEach(f => {
            const kb = parseFloat(f.size) || 0;
            totalKb += kb;
        });
        if (sizeEl) sizeEl.textContent = totalKb.toFixed(1) + ' KB';

        if (files.length === 0) {
            listEl.innerHTML = '<div style="padding: 8px; text-align: center; color: var(--text-secondary); opacity: 0.6;">No context files injected</div>';
            return;
        }

        listEl.innerHTML = files.map((f, i) => `
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 4px 6px; background: rgba(255,255,255,0.02); border-radius: 6px; border: 1px solid rgba(255,255,255,0.04);">
                <div style="display: flex; align-items: center; gap: 6px; min-width: 0; flex: 1; margin-right: 8px;">
                    <span style="opacity: 0.4; font-size: 10px;">${i + 1}.</span>
                    <span style="color: var(--text-primary); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${this.escapeHtml(f.path)}">${this.escapeHtml(f.name)}</span>
                </div>
                <span style="font-size: 10px; opacity: 0.6; flex-shrink: 0;">${f.size}</span>
            </div>
        `).join('');
    },

    toggleFoundationFilesList() {
        this.selectContextMode(this.currentContextMode || 'foundation', false);
    },

    async saveSettings() {
        const fd = new FormData();
        fd.append('action', 'save_settings');
        fd.append('model', document.getElementById('inp-model').value);
        fd.append('system_prompt', document.getElementById('inp-system-prompt').value);
        fd.append('max_iterations', document.getElementById('inp-max-iter').value);
        fd.append('openrouter_api_key', document.getElementById('inp-api-key').value);
        fd.append('context_mode', document.getElementById('inp-context-mode').value);

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                this.showToast('Settings saved');
                this.closeModal('modal-settings');
            }
        } catch (e) {
            console.error(e);
        }
    },

    escapeHtml(text) {
        return text ? text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;") : "";
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());