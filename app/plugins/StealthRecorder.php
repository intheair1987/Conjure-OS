<?php
// ==============================================================================
// PLUGIN: Stealth Recorder
// DESCRIPTION: Logo Record Trigger.
// Turns the App Logo into a hidden record button.
// ==============================================================================

// --- SETTINGS UI ---
$plugin_settings_map['StealthRecorder'] = <<<'HTML'
    <div data-sui-setting="Logo Recorder" data-sui-desc="Tap the top-left logo to start/stop recording." data-sui-id="stealth-rec-toggle" data-sui-onchange="toggleStealthRec(this.checked)"></div>
HTML;

// --- CLIENT-SIDE LOGIC ---
$plugin_js .= <<<'JS'
// --- STEALTH RECORDER JS ---

const stealthRecEnabled = localStorage.getItem("cjos_stealth_rec") !== "false"; // Default true

window.toggleStealthRec = function(enabled) {
    localStorage.setItem("cjos_stealth_rec", enabled);
    location.reload();
};

window.addEventListener("load", () => {
    // 1. Init Settings Toggle
    const toggle = document.getElementById("stealth-rec-toggle");
    if(toggle) toggle.checked = stealthRecEnabled;

    if (!stealthRecEnabled) return;

    // 2. Inject CSS for the "Live" State
    const style = document.createElement("style");
    style.innerHTML = `
        @keyframes stealthPulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.15); opacity: 0.8; }
            100% { transform: scale(1); opacity: 1; }
        }
        .app-logo.stealth-active {
            color: #FF3B30 !important; /* Red */
            animation: stealthPulse 1.5s infinite ease-in-out;
            cursor: pointer;
        }
        .app-logo {
            transition: color 0.3s, transform 0.3s;
            /* Ensure it receives clicks even if header is collapsed */
            pointer-events: auto; 
            cursor: pointer;
        }
    `;
    document.head.appendChild(style);

    // 3. Logic Setup
    const logo = document.querySelector(".app-logo");
    const fab = document.getElementById("fab-record");

    if (logo && fab) {
        
        // A. Handle Click
        logo.onclick = (e) => {
            e.stopPropagation(); // Prevent header collapse logic if any
            
            // Trigger the main FAB click to reuse all recording/upload logic
            fab.click();
        };

        // B. Sync Visual State (MutationObserver)
        // We watch the FAB for the "recording" class. 
        // This ensures the logo updates even if you start recording via the bottom button.
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.type === "attributes" && mutation.attributeName === "class") {
                    if (fab.classList.contains("recording")) {
                        logo.classList.add("stealth-active");
                    } else {
                        logo.classList.remove("stealth-active");
                    }
                }
            });
        });

        observer.observe(fab, { attributes: true });
        
        // Initial check in case recording is already active (unlikely on load, but good practice)
        if (fab.classList.contains("recording")) {
            logo.classList.add("stealth-active");
        }
    }
});
JS;
?>