const pd = {
    peer: null,
    currentConn: null,
    queuedFiles: [],
    incoming: { name: '', size: 0, type: '', chunks: [], received: 0 },
    activeTransfer: false,

    async init() {
        this.log("Initializing PeerDrop Conduit...", "info");
        this.setupFileInputs();
        
        const urlParams = new URLSearchParams(window.location.search);
        const targetRoom = (window.PEERDROP_ROOM_ID || urlParams.get('room') || '').trim();
        
        if (targetRoom.length > 0) {
            this.initGuestConduit(targetRoom);
        } else {
            this.initHostConduit();
        }
    },

    setupFileInputs() {
        const fileInput = document.getElementById('file-input');
        if (fileInput) {
            fileInput.addEventListener('change', (e) => {
                if (e.target.files && e.target.files.length > 0) {
                    this.handleSelectedFiles(Array.from(e.target.files));
                }
            });
        }

        const dropZone = document.getElementById('drop-zone');
        if (dropZone) {
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });
            dropZone.addEventListener('dragleave', () => {
                dropZone.classList.remove('dragover');
            });
            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                    this.handleSelectedFiles(Array.from(e.dataTransfer.files));
                }
            });
        }
    },

    // --- CONDUIT CONNECTION ENGINE ---
    isConnected() {
        if (!this.currentConn) return false;
        if (this.currentConn.open) return true;
        if (this.currentConn.dataChannel && this.currentConn.dataChannel.readyState === 'open') return true;
        return false;
    },

    initHostConduit() {
        this.hideFileManagementSection();
        this.showQRCodeSection();
        this.log("Creating Host Conduit Node...", "info");
        this.updateStatus("Generating Conduit Room...", "info");

        this.peer = new Peer();
        this.peer.on('open', async (id) => {
            this.log(`Host Conduit Active. Room ID: ${id}`, "success");
            
            let shareUrl = window.location.origin + window.location.pathname + "?room=" + id;
            let isTunneled = false;

            try {
                this.updateStatus("Publishing Ephemeral Public QR...", "info");
                const res = await fetch(`index.php?action=publish_litterbox&room=${encodeURIComponent(id)}`);
                if (res.ok) {
                    const data = await res.json();
                    
                    if (data && data.debug_logs && Array.isArray(data.debug_logs)) {
                        data.debug_logs.forEach(logMsg => {
                            const isErr = logMsg.includes('Err:') && !logMsg.includes('Err: None') && !logMsg.includes('HTTP 200');
                            this.log(logMsg, isErr ? 'error' : 'info');
                        });
                    }

                    if (data && data.status === 'success' && data.public_url) {
                        shareUrl = data.public_url;
                        isTunneled = !!data.is_tunneled;
                        if (isTunneled) {
                            this.log(`Public Ephemeral Receiver Active: ${shareUrl}`, "success");
                        } else {
                            this.log(`Public Domain Mode Active: ${shareUrl}`, "info");
                        }

                        this.renderQRCode(shareUrl);

                        const qrHint = document.getElementById('qr-hint');
                        if (qrHint) {
                            qrHint.innerText = isTunneled 
                                ? "🔥 Ephemeral Link Active • Scan with native camera"
                                : "🟢 Public Domain Active • Scan or share link";
                        }

                        if (!this.isConnected()) {
                            this.updateStatus("🟢 Conduit Active • Waiting for partner...", "success");
                        }
                    } else {
                        const errMsg = (data && data.message) ? data.message : "Publishing failed";
                        this.log(`Public link publishing error: ${errMsg}`, "error");
                        this.updateStatus("❌ Public Link Failed • Check Internet", "error");
                        const qrHint = document.getElementById('qr-hint');
                        if (qrHint) {
                            qrHint.innerText = "❌ Could not generate public link. Ensure internet access and tap Reset.";
                        }
                    }
                } else {
                    this.updateStatus("❌ Public Link Failed • Server Error", "error");
                }
            } catch (e) {
                this.log(`Publishing exception: ${e.message}`, "error");
                this.updateStatus("❌ Public Link Failed • Check Network", "error");
                const qrHint = document.getElementById('qr-hint');
                if (qrHint) {
                    qrHint.innerText = "❌ Could not generate public link. Ensure internet access and tap Reset.";
                }
            }
        });

        this.peer.on('connection', (conn) => {
            this.bindConduitConnection(conn);
        });

        this.peer.on('disconnected', () => {
            this.log("Signaling relay disconnected. Attempting background reconnect...", "warn");
            if (this.peer && !this.peer.destroyed) {
                try { this.peer.reconnect(); } catch(e) {}
            }
        });

        this.peer.on('error', (err) => {
            this.log(`Signaling warning: ${err.message}`, "warn");
            if (!this.isConnected()) {
                this.updateStatus("❌ Connection Failed", "error");
                this.showQRCodeSection();
            }
        });
    },

    initGuestConduit(targetRoomId) {
        this.hideQRCodeSection();
        this.hideFileManagementSection();
        this.log(`Connecting to Host Conduit Room: ${targetRoomId}...`, "info");
        this.updateStatus("Connecting to Partner...", "info");

        this.peer = new Peer();
        this.peer.on('open', () => {
            const conn = this.peer.connect(targetRoomId);
            this.bindConduitConnection(conn);
        });

        this.peer.on('disconnected', () => {
            this.log("Signaling relay disconnected. Attempting background reconnect...", "warn");
            if (this.peer && !this.peer.destroyed) {
                try { this.peer.reconnect(); } catch(e) {}
            }
        });

        this.peer.on('error', (err) => {
            this.log(`Signaling warning: ${err.message}`, "warn");
            if (!this.isConnected()) {
                this.updateStatus("❌ Connection Failed", "error");
                this.showQRCodeSection();
            }
        });
    },

    bindConduitConnection(conn) {
        this.currentConn = conn;
        this.log(`Peer connected: ${conn.peer}`, "success");

        conn.on('open', () => {
            this.updateStatus("🟢 Partner Connected • Ready for transfers", "success");
            this.hideQRCodeSection();
            this.showFileManagementSection();
            this.toast("Partner device connected!");

            this.startHeartbeat(conn);

            if (this.queuedFiles.length > 0) {
                this.processSendQueue();
            }
        });

        conn.on('data', (d) => {
            this.handleIncomingData(d, conn);
        });

        conn.on('close', () => {
            this.stopHeartbeat();
            if (!this.isConnected()) {
                this.log("Partner disconnected.", "warn");
                this.updateStatus("🔴 Partner Disconnected", "error");
                this.toast("Partner disconnected.");
                this.hideFileManagementSection();
                this.showQRCodeSection();
            }
        });

        conn.on('error', (err) => {
            this.log(`DataChannel warning: ${err.message}`, "warn");
            if (!this.isConnected()) {
                this.updateStatus("❌ Connection Error", "error");
            }
        });
    },

    startHeartbeat(conn) {
        this.stopHeartbeat();
        this.heartbeatTimer = setInterval(() => {
            if (conn && conn.open) {
                try { conn.send({ t: 'PING' }); } catch(e) {}
            } else {
                this.stopHeartbeat();
            }
        }, 15000);
    },

    stopHeartbeat() {
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
    },

    calculateSpeedAndETA(currentBytes, totalBytes, startTime) {
        const elapsedSec = (performance.now() - startTime) / 1000;
        if (elapsedSec < 0.2 || currentBytes <= 0) {
            return { speedStr: 'Calculating...', etaStr: 'ETA: --' };
        }

        const bytesPerSec = currentBytes / elapsedSec;
        const mbPerSec = (bytesPerSec / 1024 / 1024).toFixed(1);
        const speedStr = `${mbPerSec} MB/s`;

        const remainingBytes = totalBytes - currentBytes;
        if (remainingBytes <= 0) {
            return { speedStr, etaStr: 'ETA: 0s' };
        }

        const remainingSec = Math.round(remainingBytes / bytesPerSec);
        let etaStr = '';
        if (remainingSec < 60) {
            etaStr = `ETA: ${remainingSec}s`;
        } else {
            const mins = Math.floor(remainingSec / 60);
            const secs = remainingSec % 60;
            etaStr = `ETA: ${mins}m ${secs < 10 ? '0' : ''}${secs}s`;
        }

        return { speedStr, etaStr };
    },

    // --- BIDIRECTIONAL TRANSFER CONTROLLER ---
    handleSelectedFiles(files) {
        files.forEach(f => this.queuedFiles.push(f));
        if (this.currentConn && this.currentConn.open) {
            this.processSendQueue();
        } else {
            this.toast(`${files.length} file(s) queued. Waiting for partner...`);
            this.log(`${files.length} file(s) queued for sending once connected.`, "info");
        }
    },

    async processSendQueue() {
        if (this.activeTransfer || this.queuedFiles.length === 0 || !this.currentConn || !this.currentConn.open) return;
        this.activeTransfer = true;

        const file = this.queuedFiles.shift();
        const conn = this.currentConn;
        const dc = conn.dataChannel;

        this.log(`Streaming outbound file: ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)...`, "info");
        this.addLedgerItem(`⬆️ Sending: ${file.name}`, "transferring", file.size);

        try {
            this.pendingTransferPromise = new Promise((resolve) => {
                this._resolveTransfer = resolve;
            });

            this.log(`Requesting partner approval to send: ${file.name}...`, "info");
            this.updateLedgerStatus(`⬆️ Sending: ${file.name}`, "Waiting for partner approval...");
            conn.send({ t: 'REQUEST_TRANSFER', n: file.name, s: file.size, m: file.type });

            const accepted = await Promise.race([
                this.pendingTransferPromise,
                new Promise(r => setTimeout(() => r(false), 30000))
            ]);

            if (!accepted) {
                this.log(`Transfer cancelled or rejected by partner for ${file.name}`, "warn");
                this.updateLedgerStatus(`⬆️ Sending: ${file.name}`, "❌ Rejected / Cancelled");
                this.toast(`Partner rejected ${file.name}`);
                this.activeTransfer = false;
                if (this.queuedFiles.length > 0) {
                    setTimeout(() => this.processSendQueue(), 100);
                }
                return;
            }

            if (dc) dc.bufferedAmountLowThreshold = 1024 * 1024; // 1 MB low threshold

            conn.send({ t: 'START', n: file.name, s: file.size, m: file.type });

            const CHUNK_SIZE = 64 * 1024; // 64 KB optimal SCTP socket payload
            let offset = 0;
            let lastProgressTime = 0;
            const startTime = performance.now();

            while (offset < file.size) {
                // Event-driven backpressure: pause if socket buffer exceeds threshold
                if (dc && dc.bufferedAmount > 2 * 1024 * 1024) {
                    await new Promise(resolve => {
                        const onLow = () => {
                            if (dc) dc.onbufferedamountlow = null;
                            resolve();
                        };
                        if (dc) dc.onbufferedamountlow = onLow;
                        else setTimeout(resolve, 5);
                    });
                }

                const chunkSlice = file.slice(offset, offset + CHUNK_SIZE);
                const buffer = await chunkSlice.arrayBuffer();
                
                // Stream raw ArrayBuffer directly over socket (zero JSON serialization)
                conn.send(buffer);
                offset += buffer.byteLength;

                // Throttle DOM progress updates to 10 fps (every 100ms) to prevent UI thread lag
                const now = performance.now();
                if (now - lastProgressTime > 100 || offset >= file.size) {
                    this.updateProgressBar(offset, file.size, startTime);
                    lastProgressTime = now;
                }
            }

            conn.send({ t: 'END', n: file.name });
            this.log(`Stream complete for ${file.name}`, "success");
            this.updateLedgerStatus(`⬆️ Sending: ${file.name}`, "✓ Complete");
            this.toast(`Sent ${file.name}`);
        } catch (e) {
            this.log(`Send error for ${file.name}: ${e.message}`, "error");
            this.updateLedgerStatus(`⬆️ Sending: ${file.name}`, "❌ Failed");
        }

        this.hideProgressBar();
        this.activeTransfer = false;
        
        if (this.queuedFiles.length > 0) {
            setTimeout(() => this.processSendQueue(), 100);
        }
    },

    handleIncomingData(d, conn) {
        if (!d) return;

        // WebRTC Keep-Alive Heartbeat Messages
        if (typeof d === 'object' && d.t === 'PING') {
            if (conn && conn.open) {
                try { conn.send({ t: 'PONG' }); } catch(e) {}
            }
            return;
        }

        if (typeof d === 'object' && d.t === 'PONG') {
            return;
        }

        // 1. Control Metadata Messages (REQUEST_TRANSFER, ACCEPT_TRANSFER, REJECT_TRANSFER, START, END, status)
        if (typeof d === 'object' && d.t === 'REQUEST_TRANSFER') {
            const totalSize = Number(d.s) || 0;
            const sizeMb = (totalSize / 1024 / 1024).toFixed(2);
            this.log(`Partner requested transfer: ${d.n} (${sizeMb} MB)`, "info");
            this.pendingIncoming = { name: d.n, size: totalSize, type: d.m, conn: conn };

            const nameEl = document.getElementById('req-file-name');
            if (nameEl) nameEl.innerText = d.n;
            const sizeEl = document.getElementById('req-file-size');
            if (sizeEl) sizeEl.innerText = `${sizeMb} MB`;

            const modal = document.getElementById('transfer-request-modal');
            if (modal) modal.style.display = 'flex';
            return;
        }

        if (typeof d === 'object' && d.t === 'ACCEPT_TRANSFER') {
            this.log(`Partner accepted file transfer: ${d.n}`, "success");
            if (this._resolveTransfer) this._resolveTransfer(true);
            return;
        }

        if (typeof d === 'object' && d.t === 'REJECT_TRANSFER') {
            this.log(`Partner rejected file transfer: ${d.n}`, "warn");
            if (this._resolveTransfer) this._resolveTransfer(false);
            return;
        }

        if (typeof d === 'object' && d.t === 'START') {
            const totalSize = Number(d.s) || 0;
            this.log(`Incoming file stream: ${d.n} (${(totalSize / 1024 / 1024).toFixed(2)} MB)`, "info");
            this.incoming = { name: d.n, size: totalSize, type: d.m, chunks: [], received: 0, startTime: performance.now() };
            this._lastRxProgress = 0;
            this.addLedgerItem(`⬇️ Receiving: ${d.n}`, "receiving", totalSize);
            this.showProgressBar();
            this.updateProgressBar(0, totalSize, this.incoming.startTime); // Force immediate 0% UI display
            return;
        }

        if (typeof d === 'object' && d.t === 'END') {
            this.log(`Incoming stream finished: ${this.incoming.name}. Finalizing...`, "success");
            this.finalizeReceivedFile(this.incoming.name, this.incoming.type, this.incoming.chunks);
            this.updateLedgerStatus(`⬇️ Receiving: ${this.incoming.name}`, "✓ Saved");
            this.hideProgressBar();
            this.toast(`Received ${this.incoming.name}`);
            if (conn) conn.send({ status: 'received' });
            return;
        }

        if (typeof d === 'object' && d.status === 'received') {
            this.log("Partner confirmed file received.", "success");
            return;
        }

        // 2. Binary Data Chunk Processing (Cross-Realm Robust Parser for iOS <-> Android)
        let byteLen = 0;
        let chunkBuf = d;

        if (d && typeof d === 'object' && d.t === 'CHUNK') {
            chunkBuf = d.d; // Unpack legacy chunk wrapper if present
        }

        if (chunkBuf) {
            if (typeof chunkBuf.byteLength === 'number' && chunkBuf.byteLength > 0) {
                byteLen = chunkBuf.byteLength;
            } else if (typeof chunkBuf.size === 'number' && chunkBuf.size > 0) {
                byteLen = chunkBuf.size;
            } else if (typeof chunkBuf.length === 'number' && chunkBuf.length > 0) {
                byteLen = chunkBuf.length;
            } else if (chunkBuf.buffer && typeof chunkBuf.buffer.byteLength === 'number') {
                byteLen = chunkBuf.buffer.byteLength;
            }
        }

        if (byteLen > 0) {
            this.incoming.chunks.push({ offset: this.incoming.received, buffer: chunkBuf });
            this.incoming.received += byteLen;

            // Update UI progress (throttled to 50ms / 20 fps for silky smooth counter rendering)
            const now = performance.now();
            const totalSize = this.incoming.size || 1;
            if (!this._lastRxProgress || now - this._lastRxProgress > 50 || this.incoming.received >= totalSize) {
                this.updateProgressBar(this.incoming.received, totalSize, this.incoming.startTime);
                this._lastRxProgress = now;
            }
        }
    },

    // --- ULTRA-FAST DIRECT-TO-DISK FILE SAVER ---
    async finalizeReceivedFile(fileName, mimeType, chunks) {
        chunks.sort((a, b) => a.offset - b.offset);
        const blobParts = chunks.map(c => c.buffer);
        const safeMime = mimeType || 'application/octet-stream';
        const safeName = fileName || 'received_file';
        const blob = new Blob(blobParts, { type: safeMime });

        // Priority 1: Modern File System Access API (Direct disk stream for large files)
        if ('showSaveFilePicker' in window && blob.size > 50 * 1024 * 1024) {
            try {
                const handle = await window.showSaveFilePicker({ suggestedName: safeName });
                const writable = await handle.createWritable();
                for (const chunk of chunks) {
                    await writable.write(chunk.buffer);
                }
                await writable.close();
                this.log(`File written directly to disk stream: ${safeName}`, "success");
                return;
            } catch (e) {
                this.log("File System Access API prompt dismissed, using browser download link fallback.", "info");
            }
        }

        // Priority 2: Native Android JNI Bridge (For WebWrapper / Conjure OS)
        if (window.Android && (window.Android.saveBase64File || window.Android.processBlobDownload)) {
            const reader = new FileReader();
            reader.onloadend = () => {
                if (window.Android.saveBase64File) {
                    window.Android.saveBase64File(reader.result, safeName, safeMime);
                } else if (window.Android.processBlobDownload) {
                    window.Android.processBlobDownload(reader.result, 'attachment; filename="' + safeName + '"', safeMime);
                }
            };
            reader.readAsDataURL(blob);
            return;
        }

        // Priority 3: Standard Browser Download Link with document.title Preservation
        const oldTitle = document.title;
        document.title = safeName;
        const downloadUrl = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = downloadUrl;
        a.setAttribute('download', safeName);
        a.download = safeName;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => {
            URL.revokeObjectURL(downloadUrl);
            document.title = oldTitle;
        }, 2000);
    },

    // --- UI CONTROLLERS ---
    renderQRCode(url) {
        const qrBox = document.getElementById("qrcode-container");
        if (!qrBox) return;
        qrBox.innerHTML = '';
        new QRCode(qrBox, {
            text: url,
            width: 180,
            height: 180,
            colorDark: "#000000",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });
        this.roomUrl = url;
    },

    hideQRCodeSection() {
        const qrSec = document.getElementById('qr-section');
        if (qrSec) qrSec.style.display = 'none';
    },

    showQRCodeSection() {
        const qrSec = document.getElementById('qr-section');
        if (qrSec) qrSec.style.display = 'flex';
    },

    showFileManagementSection() {
        const fmSec = document.getElementById('file-management-section');
        if (fmSec) fmSec.style.display = 'flex';
    },

    hideFileManagementSection() {
        const fmSec = document.getElementById('file-management-section');
        if (fmSec) fmSec.style.display = 'none';
    },

    updateStatus(msg, type = 'info') {
        const pill = document.getElementById('conduit-status');
        if (!pill) return;
        pill.innerText = msg;
        pill.className = 'status-pill ' + type;
    },

    showProgressBar() {
        const wrap = document.getElementById('progress-section');
        if (wrap) wrap.style.display = 'block';
    },

    hideProgressBar() {
        const wrap = document.getElementById('progress-section');
        if (wrap) wrap.style.display = 'none';
        const bar = document.getElementById('progress-bar');
        if (bar) bar.style.width = '0%';
    },

    updateProgressBar(current, total, startTime) {
        this.showProgressBar();
        const totalSize = total || 1;
        const pct = Math.min(100, Math.round((current / totalSize) * 100));
        const bar = document.getElementById('progress-bar');
        if (bar) bar.style.width = `${pct}%`;
        
        const mb = (current / 1024 / 1024).toFixed(1);
        const totalMb = (totalSize / 1024 / 1024).toFixed(1);

        let speedEtaText = '';
        if (startTime && current > 0) {
            const { speedStr, etaStr } = this.calculateSpeedAndETA(current, totalSize, startTime);
            speedEtaText = ` • ${speedStr} • ${etaStr}`;
        }

        const meta = document.getElementById('transfer-meta');
        if (meta) meta.innerText = `${pct}% • ${mb} / ${totalMb} MB${speedEtaText}`;
    },

    addLedgerItem(label, state, totalBytes) {
        const ledger = document.getElementById('activity-ledger');
        if (!ledger) return;
        
        const emptyMsg = ledger.querySelector('.ledger-empty');
        if (emptyMsg) emptyMsg.remove();

        const item = document.createElement('div');
        item.className = 'ledger-item';
        item.dataset.label = label;
        const sizeMb = totalBytes ? ` (${(totalBytes / 1024 / 1024).toFixed(2)} MB)` : '';
        item.innerHTML = `<span class="ledger-name">${label}${sizeMb}</span><span class="ledger-status">${state}</span>`;
        ledger.prepend(item);
    },

    updateLedgerStatus(label, statusText) {
        const ledger = document.getElementById('activity-ledger');
        if (!ledger) return;
        const items = ledger.querySelectorAll('.ledger-item');
        for (let item of items) {
            if (item.dataset.label === label) {
                const statusSpan = item.querySelector('.ledger-status');
                if (statusSpan) statusSpan.innerText = statusText;
                break;
            }
        }
    },

    copyRoomLink() {
        const url = this.roomUrl || window.location.href;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url);
            this.toast("Conduit Link Copied!");
        } else {
            this.toast("Copy URL: " + url);
        }
    },

    acceptIncomingTransfer() {
        const modal = document.getElementById('transfer-request-modal');
        if (modal) modal.style.display = 'none';

        if (this.pendingIncoming && this.pendingIncoming.conn) {
            const conn = this.pendingIncoming.conn;
            conn.send({ t: 'ACCEPT_TRANSFER', n: this.pendingIncoming.name });
            this.toast(`Accepted ${this.pendingIncoming.name}`);
        }
        this.pendingIncoming = null;
    },

    rejectIncomingTransfer() {
        const modal = document.getElementById('transfer-request-modal');
        if (modal) modal.style.display = 'none';

        if (this.pendingIncoming && this.pendingIncoming.conn) {
            const conn = this.pendingIncoming.conn;
            conn.send({ t: 'REJECT_TRANSFER', n: this.pendingIncoming.name });
            this.addLedgerItem(`⬇️ Rejected: ${this.pendingIncoming.name}`, "❌ Rejected", this.pendingIncoming.size);
            this.toast(`Rejected ${this.pendingIncoming.name}`);
        }
        this.pendingIncoming = null;
    },

    disconnectConduit() {
        const modal = document.getElementById('transfer-request-modal');
        if (modal) modal.style.display = 'none';
        if (this.peer) {
            try { this.peer.destroy(); } catch(e) {}
        }
        window.location.href = window.location.origin + window.location.pathname;
    },

    log(msg, type = 'info') {
        const now = new Date();
        const timeStr = now.toTimeString().split(' ')[0];
        
        let color = 'var(--text-secondary)';
        if (type === 'success') color = 'var(--success)';
        if (type === 'error') color = '#ff3b30';
        if (type === 'status') color = 'var(--primary-accent)';
        
        const html = `<span style="opacity:0.5;">[${timeStr}]</span> <span style="color:${color}; font-weight:600;">${msg}</span>`;
        
        const consoleLog = document.getElementById('console-log');
        if (consoleLog) {
            const div = document.createElement('div');
            div.innerHTML = html;
            consoleLog.appendChild(div);
            consoleLog.scrollTop = consoleLog.scrollHeight;
        }

        const inlineLog = document.getElementById('inline-console-log');
        if (inlineLog) {
            const div = document.createElement('div');
            div.innerHTML = html;
            inlineLog.appendChild(div);
            inlineLog.scrollTop = inlineLog.scrollHeight;
        }

        const badge = document.getElementById('telemetry-badge');
        if (badge) {
            if (type === 'error') {
                badge.innerText = 'ERROR';
                badge.style.color = '#ff3b30';
                badge.style.borderColor = '#ff3b30';
                const accordion = document.getElementById('telemetry-accordion');
                if (accordion) accordion.open = true; // Auto-expand accordion on error
            } else if (type === 'success') {
                badge.innerText = 'ACTIVE';
                badge.style.color = 'var(--success)';
                badge.style.borderColor = 'var(--success)';
            }
        }
    },

    toggleConsole() {
        const modal = document.getElementById('console-modal');
        if (modal) {
            modal.style.display = modal.style.display === 'none' ? 'flex' : 'none';
        }
    },

    copyConsole() {
        const inlineLog = document.getElementById('inline-console-log');
        const modalLog = document.getElementById('console-log');
        const targetLog = inlineLog || modalLog;
        if (!targetLog) return;

        const lines = Array.from(targetLog.querySelectorAll('div')).map(div => div.innerText.trim()).filter(Boolean);
        if (lines.length === 0) {
            this.toast("Console log is empty.");
            return;
        }

        const rawText = lines.join('\n');
        const markdownFormatted = "```text\n" + rawText + "\n```";

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(markdownFormatted).then(() => {
                this.toast("📋 Log Copied (Markdown)");
            }).catch(() => {
                this.fallbackCopy(markdownFormatted);
            });
        } else {
            this.fallbackCopy(markdownFormatted);
        }
    },

    fallbackCopy(text) {
        let textarea = document.getElementById('hidden-telemetry-textarea');
        if (!textarea) {
            textarea = document.createElement('textarea');
            textarea.id = 'hidden-telemetry-textarea';
            textarea.style.position = 'fixed';
            textarea.style.top = '-9999px';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
        }
        textarea.value = text;
        textarea.select();
        try {
            document.execCommand('copy');
            this.toast("📋 Log Copied (Markdown)");
        } catch(e) {
            this.toast("Copy failed.");
        }
    },

    clearConsole() {
        const consoleLog = document.getElementById('console-log');
        if (consoleLog) consoleLog.innerHTML = '';
        const inlineLog = document.getElementById('inline-console-log');
        if (inlineLog) inlineLog.innerHTML = '';
        const badge = document.getElementById('telemetry-badge');
        if (badge) {
            badge.innerText = 'IDLE';
            badge.style.color = '';
            badge.style.borderColor = '';
        }
        this.log("Console cleared.", "info");
    },

    toast(msg) {
        const t = document.getElementById('toast');
        if (!t) return;
        t.innerText = msg;
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3000);
    }
};

if (typeof window._loadPeerDropLibs === 'function') {
    window._loadPeerDropLibs(function() {
        pd.init();
    });
} else {
    pd.init();
}