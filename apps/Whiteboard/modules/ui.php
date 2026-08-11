<?php
/**
 * Whiteboard UI Module
 * Custom Modal Hijacker for Alerts and Confirms
 */
?>
<div id="wbui-overlay" style="
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.4); backdrop-filter: blur(5px);
    display: none; align-items: center; justify-content: center;
    z-index: 10000; padding: 20px;
">
    <div id="wbui-modal" style="
        background: var(--card-bg); width: 100%; max-width: 320px;
        border-radius: 24px; padding: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        text-align: center; transform: scale(0.9); opacity: 0;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    ">
        <div id="wbui-icon" style="
            width: 48px; height: 48px; background: var(--bg-color);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px auto; font-size: 24px; color: var(--primary-accent);
        "><svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.92 0 1.5-.58 1.5-1.5 0-.43-.17-.83-.44-1.1-.27-.27-.44-.67-.44-1.1 0-.92.58-1.5 1.5-1.5H16c3.31 0 6-2.69 6-6 0-4.97-4.48-9-10-9z"/></svg></div>
        <h3 id="wbui-title" style="margin: 0 0 8px 0; font-size: 18px; color: var(--text-primary);">Notice</h3>
        <p id="wbui-message" style="
            margin: 0 0 16px 0; font-size: 14px; color: var(--text-secondary);
            line-height: 1.5; white-space: pre-wrap;
        "></p>
        <input type="text" id="wbui-input-field" style="
            display: none; width: 100%; padding: 12px; margin-bottom: 20px;
            background: var(--bg-color); border: 1px solid rgba(0,0,0,0.1);
            color: var(--text-primary); border-radius: 12px; box-sizing: border-box;
            font-size: 16px; outline: none; text-align: center;
        ">
        <div id="wbui-actions" style="display: flex; gap: 10px;">
            <button id="wbui-btn-cancel" class="tool-btn" style="flex: 1; background: var(--bg-color); display: none;">Cancel</button>
            <button id="wbui-btn-ok" class="tool-btn" style="flex: 1; background: var(--primary-accent); color: white; border: none;">OK</button>
        </div>
    </div>
</div>

<script>
window.wbui = {
    _activeCallback: null,
    _activeCancel: null,

    show: function(title, message, icon = '', showCancel = false) {
        const overlay = document.getElementById('wbui-overlay');
        const modal = document.getElementById('wbui-modal');
        
        document.getElementById('wbui-title').innerText = title;
        document.getElementById('wbui-message').innerText = message;
        if (icon) document.getElementById('wbui-icon').innerHTML = icon;
        document.getElementById('wbui-btn-cancel').style.display = showCancel ? 'block' : 'none';
        
        overlay.style.display = 'flex';
        setTimeout(() => {
            modal.style.transform = 'scale(1)';
            modal.style.opacity = '1';
        }, 10);
    },

    hide: function() {
        const overlay = document.getElementById('wbui-overlay');
        const modal = document.getElementById('wbui-modal');
        modal.style.transform = 'scale(0.9)';
        modal.style.opacity = '0';
        setTimeout(() => {
            overlay.style.display = 'none';
        }, 200);
    },

    alert: function(message, title = "Notice", icon = '🎨') {
        return new Promise((resolve) => {
            this._activeCallback = resolve;
            this.show(title, message, icon, false);
        });
    },

    confirm: function(message, title = "Confirm", icon = '❓') {
        return new Promise((resolve) => {
            this._activeCallback = () => resolve(true);
            this._activeCancel = () => resolve(false);
            this.show(title, message, icon, true);
        });
    },

    input: function(message, title = "Input", defaultValue = "", icon = '✏️') {
        return new Promise((resolve) => {
            const inp = document.getElementById('wbui-input-field');
            inp.value = defaultValue;
            inp.placeholder = defaultValue;
            inp.style.display = 'block';
            this._activeCallback = () => {
                const val = inp.value.trim();
                inp.style.display = 'none';
                resolve(val || null);
            };
            this._activeCancel = () => {
                inp.style.display = 'none';
                resolve(null);
            };
            this.show(title, message, icon, true);
            setTimeout(() => {
                inp.focus();
                inp.select();
            }, 300);
        });
    }
};

// Hijack native alert
window.alert = (msg) => wbui.alert(msg);

document.getElementById('wbui-btn-ok').onclick = () => {
    wbui.hide();
    if (wbui._activeCallback) wbui._activeCallback();
    wbui._activeCallback = null;
    wbui._activeCancel = null;
};

document.getElementById('wbui-btn-cancel').onclick = () => {
    wbui.hide();
    if (wbui._activeCancel) wbui._activeCancel();
    wbui._activeCallback = null;
    wbui._activeCancel = null;
};
</script>