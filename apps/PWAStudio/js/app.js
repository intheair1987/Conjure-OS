// Global promise and request tracking to eliminate async race conditions
const pendingFetches = {};
let previewRequestCount = 0;

// Global Secure Press Controller to prevent accidental taps while scrolling
const SecurePress = {
  timer: null,
  isLongPress: false,
  hasMoved: false,
  startX: 0,
  startY: 0,
  activeAction: null,
  
  start: function(e, actionFn) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    this.isLongPress = false;
    this.hasMoved = false;
    this.startX = e.clientX;
    this.startY = e.clientY;
    this.activeAction = actionFn;
    
    if (state.securePressEnabled) {
      this.timer = setTimeout(() => {
        this.isLongPress = true;
        navigator.vibrate?.(40);
        if (this.activeAction) this.activeAction();
      }, 400);
    }
  },
  
  cancel: function() {
    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }
  },
  
  move: function(e) {
    if (Math.abs(e.clientX - this.startX) > 10 || Math.abs(e.clientY - this.startY) > 10) {
      this.hasMoved = true;
      this.cancel();
    }
  },
  
  up: function(e) {
    this.cancel();
    if (!this.hasMoved && this.activeAction) {
      if (state.securePressEnabled) {
        if (!this.isLongPress) {
          showToast('Long press to select');
        }
      } else {
        // Instant tap mode: execute on release if hasn't moved
        navigator.vibrate?.(20);
        this.activeAction();
      }
    }
    this.activeAction = null;
  }
};

window.addEventListener('pointermove', (e) => SecurePress.move(e), { passive: true });
window.addEventListener('pointerup', (e) => SecurePress.up(e));
window.addEventListener('pointercancel', () => SecurePress.cancel());

function extractPwaConfig(svgString) {
  if (!svgString || !svgString.trim().startsWith('<svg')) return null;
  try {
    const parser = new DOMParser();
    const doc = parser.parseFromString(svgString, 'image/svg+xml');
    const svgEl = doc.querySelector('svg');
    if (svgEl) {
      const configAttr = svgEl.getAttribute('data-pwa-studio-config');
      if (configAttr) {
        return JSON.parse(configAttr);
      }
    }
  } catch (e) {
    console.error("DOMParser config extraction failed", e);
  }
  return null;
}

// State Model
const state = {
  activeApp: '',
  activeTab: 'outline',       // Controls which panel/tab is open in the UI
  currentIconType: 'outline', // Tracks the actual layout pipeline chosen (outline vs emoji)
  shape: 'squircle',
  background: '#E5E7EB',
  shadowIntensity: 5,
  outlineIcon: 'box',
strokeWeight: 2,
strokeColor: '#1c1917',
iconSize: 100,
emojiChar: '📦',
bgTexture: 'none',
bgTextureScale: 20,
bgTextureThickness: 2,
bgTextureColor: '',
contShadowDist: 0,contShadowBlur: 0,
contShadowAngle: 90,
contShadowEnabled: false,
contOutlineStyle: 'none',contOutlineWidth: 4,
contOutlineColor: '#1c1917',
innerRotation: 0,
bgOpacity: 100,
contSize: 100,
contSizeLocked: false,
lockedRatio: 1.0,
activeDatabase: 'Lucide',
securePressEnabled: window.PWA_STUDIO_SECURE_PRESS !== undefined ? window.PWA_STUDIO_SECURE_PRESS : true,
drafts: [],
presets: window.PWA_STUDIO_PRESETS || []};

// Icon Size controls
function updateIconSize(val, silent = false) {
  state.iconSize = parseInt(val);
  const sizeLabel = document.getElementById('size-value-label');
  if (sizeLabel) sizeLabel.textContent = `${state.iconSize}%`;

  if (state.contSizeLocked && !silent) {
    let newContSize = Math.round(state.iconSize / state.lockedRatio);
    let clamped = false;
    
    if (newContSize < 50) { newContSize = 50; clamped = true; }
    if (newContSize > 100) { newContSize = 100; clamped = true; }
    
    state.contSize = newContSize;
    const contSlider = document.getElementById('cont-size-slider');
    const contLabel = document.getElementById('cont-size-label');
    if (contSlider) contSlider.value = newContSize;
    if (contLabel) contLabel.textContent = `${newContSize}%`;
    
    if (clamped) {
      state.lockedRatio = state.iconSize / state.contSize;
    }
  }

  if (!silent) renderActivePreview();
}

// Expandable CDN Driver Registry
const CdnRegistry = {
  'Lucide': {
    baseUrl: 'https://unpkg.com/lucide-static@latest/icons/',
    ext: '.svg',
    icons: [] // Populated dynamically from CDN
  },
  'Feather': {
    baseUrl: 'https://unpkg.com/feather-icons/dist/icons/',
    ext: '.svg',
    icons: [] // Populated dynamically from CDN
  },
  'Phosphor': {
    baseUrl: 'https://unpkg.com/phosphor-icons@1.4.2/src/regular/',
    ext: '.svg',
    icons: [] // Populated dynamically from CDN
  },
  'Local': {
    baseUrl: 'data/local_svgs/',
    ext: '.svg',
    icons: []
  }
};

function updateDbChips(dbName) {
  const databases = ['Lucide', 'Feather', 'Phosphor', 'Local'];
  databases.forEach(db => {
    const chip = document.getElementById(`db-chip-${db}`);
    if (!chip) return;
    if (db === dbName) {
      chip.className = "px-3 py-1 text-[10px] font-bold uppercase tracking-wider rounded-full bg-[#1B4332] text-white transition-all duration-150 active:scale-95 whitespace-nowrap";
    } else {
      chip.className = "px-3 py-1 text-[10px] font-bold uppercase tracking-wider rounded-full bg-stone-100 text-stone-500 hover:bg-stone-200/60 transition-all duration-150 active:scale-95 whitespace-nowrap";
    }
  });
  const uploadBtn = document.getElementById('upload-local-svg-btn');
  if (uploadBtn) {
      if (dbName === 'Local') uploadBtn.classList.remove('hidden');
      else uploadBtn.classList.add('hidden');
  }
}

// Elements
const screenHome = document.getElementById('screen-home');
const screenWorkspace = document.getElementById('screen-workspace');
const workspaceTitle = document.getElementById('workspace-title');
const toast = document.getElementById('toast');
const toastText = document.getElementById('toast-text');

const previewMockupMain = document.getElementById('preview-mockup-main');
const previewMockupGrid = document.getElementById('preview-mockup-grid');
const previewMockupDock = document.getElementById('preview-mockup-dock');

const previewElementIcon = document.getElementById('preview-element-icon');
const previewElementGridIcon = document.getElementById('preview-element-grid-icon');
const previewElementDockIcon = document.getElementById('preview-element-dock-icon');

const tabBtnOutline = document.getElementById('tab-btn-outline');
const tabBtnEmoji = document.getElementById('tab-btn-emoji');
const tabContentOutline = document.getElementById('tab-content-outline');
const tabContentEmoji = document.getElementById('tab-content-emoji');

const hexColorLabel = document.getElementById('hex-color-label');
const strokeHexColorLabel = document.getElementById('stroke-hex-color-label');
const strokeValueLabel = document.getElementById('stroke-value-label');
const shadowValueLabel = document.getElementById('shadow-value-label');

const contOutlineHexColorLabel = document.getElementById('cont-outline-hex-color-label');
const contShadowDistLabel = document.getElementById('cont-shadow-dist-label');
const contShadowBlurLabel = document.getElementById('cont-shadow-blur-label');
const contShadowAngleLabel = document.getElementById('cont-shadow-angle-label');
const bgTextureScaleLabel = document.getElementById('bg-texture-scale-label');
const bgTextureThicknessLabel = document.getElementById('bg-texture-thickness-label');
const bgTextureHexColorLabel = document.getElementById('bg-texture-hex-color-label');
const innerRotationLabel = document.getElementById('inner-rotation-label');

// Page load setup
window.addEventListener('DOMContentLoaded', () => {
  // Initialize baseline state on browser history stack
  history.replaceState({ screen: 'home' }, '', '');
  
  if (window.lucide) lucide.createIcons();
  renderDrafts();
  selectDatabase('Lucide');
  updateShape('squircle');
  setupColorWheelEvents(); // Bind interactive color wheel dragging events
});

// Centralized history state listener for back gesture navigation
window.addEventListener('popstate', (e) => {
  const isWorkspaceOpen = !screenWorkspace.classList.contains('translate-x-full');
  if (isWorkspaceOpen && (!e.state || e.state.screen !== 'workspace')) {
    closeWorkspace(true); // Close the customizer silently without triggering double popstate
  }
});

// Track targeted restore folder
let targetRestoreFolder = null;

function openRestoreModal(appFolder) {
  targetRestoreFolder = appFolder;
  document.getElementById('restore-app-name').textContent = appFolder;
  const modal = document.getElementById('restore-modal');
  const panel = document.getElementById('restore-modal-panel');
  modal.classList.remove('opacity-0', 'pointer-events-none');
  panel.classList.remove('scale-95');
}

function closeRestoreModal() {
  targetRestoreFolder = null;
  const modal = document.getElementById('restore-modal');
  const panel = document.getElementById('restore-modal-panel');
  modal.classList.add('opacity-0', 'pointer-events-none');
  panel.classList.add('scale-95');
}

async function commitRestoreBackup() {
  if (!targetRestoreFolder) return;
  const btn = document.getElementById('btn-confirm-restore');
  btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 stroke-[2.5] animate-spin"></i><span>Restoring...</span>`;
  if (window.lucide) lucide.createIcons();
  
  try {
    const res = await fetch('modules/api.php?action=restore_backup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ appName: targetRestoreFolder })
    });
    const json = await res.json();
    if (json.success) {
      showToast('Original index.php restored successfully.');
      
      // Dynamic Hot Refresh: Query the filesystem for updated capabilities
      const scanRes = await fetch(`modules/api.php?action=scan_single_app&app=${encodeURIComponent(targetRestoreFolder)}`);
      const scanJson = await scanRes.json();
      if (scanJson.success && scanJson.app) {
        const app = scanJson.app;
        const card = document.querySelector(`.app-card[data-app-folder="${app.folder}"]`);
        if (card) {
          // Standardize updates through our centralized card refresher (SSOT)
          refreshAppCardDOM(card, app);
          
          // Execute dynamic visual highlight pulse
          card.classList.add('app-highlight-pulse');
          setTimeout(() => {
            card.classList.remove('app-highlight-pulse');
          }, 3000);
        }
      }
    } else {
      showToast('Restore failed: ' + (json.error || 'Unknown'));
    }
  } catch (e) {
    console.error(e);
    showToast('Network error restoring backup.');
  }
  
  btn.innerHTML = `<i data-lucide="check" class="w-4 h-4 stroke-[2.5]"></i><span>Restore</span>`;
  if (window.lucide) lucide.createIcons();
  closeRestoreModal();
}

// Show status indicator explanations
function showIndicatorStatus(type, isActive, appFolder = null) {
  let message = "";
  if (type === 'high_res') {
    message = isActive 
      ? "High-Res Icon: Active. App has a high-resolution apple-touch-icon declared in its index.php and a multi-size icons array in its manifest.json." 
      : "High-Res Icon: Inactive. App relies on a basic fallback icon. Apply PWA setup in the customizer to generate high-res vector assets.";
    showToast(message);
  } else if (type === 'fullscreen') {
    message = isActive 
      ? "Fullscreen Mode: Active. Standalone viewport meta tags are present; app will launch without standard browser frame controls." 
      : "Fullscreen Mode: Inactive. App runs inside standard browser frame tabs. Apply PWA setup to configure standalone launch meta tags.";
    showToast(message);
  } else if (type === 'tab_icon') {
    message = isActive 
      ? "Tab Favicon: Active. This app has a scalable vector SVG linked as its browser tab icon in index.php." 
      : "Tab Favicon: Inactive. Browser relies on standard fallback icon. Apply PWA setup to configure crisp, scalable SVG tab favicons.";
    showToast(message);
  } else if (type === 'backup') {
    if (isActive && appFolder) {
      openRestoreModal(appFolder);
    } else {
      message = "Secure Backup: Inactive. No backup restore point exists because PWA Studio has not modified this application yet.";
      showToast(message);
    }
  }
}

// Navigation (Zero dynamic layout height changes to completely prevent shifting)
function openWorkspaceFromElement(el) {
  const folderName = el.getAttribute('data-app-folder');
  const displayName = el.getAttribute('data-app-name');
  const icon = el.getAttribute('data-app-icon');
  const bg = el.getAttribute('data-app-bg');
  openWorkspace(folderName, displayName, icon, bg);
}

async function fetchDrafts(appName) {
  try {
    const res = await fetch(`modules/api.php?action=get_drafts&app=${encodeURIComponent(appName)}`);
    const json = await res.json();
    if (json.success) {
      state.presets = json.presets || [];
      // Sanitize broken draft values from previous regex bug
      state.drafts = json.drafts.map(d => {
        if (d.type === 'outline' && d.value && d.value.includes('/')) {
          d.value = d.value.split('/').pop();
        }
        return d;
      });
      renderDrafts();
      
      // Recover DB if missing from parsed SVG but exists in drafts
      if (state.currentIconType === 'outline') {
        const exactDraft = state.drafts.find(d => d.type === 'outline' && d.value === state.outlineIcon);
        if (exactDraft && exactDraft.db && state.activeDatabase !== exactDraft.db) {
          state.activeDatabase = exactDraft.db;
          updateDbChips(state.activeDatabase);
          renderActivePreview();
        }
      }

      // Recover DB if missing from parsed SVG but exists in drafts
      if (state.currentIconType === 'outline') {
        const exactDraft = state.drafts.find(d => d.type === 'outline' && d.value === state.outlineIcon);
        if (exactDraft && exactDraft.db && state.activeDatabase !== exactDraft.db) {
          state.activeDatabase = exactDraft.db;
          updateDbChips(state.activeDatabase);
          renderActivePreview();
        }
      }

      // Legacy Auto-Recovery: If we opened the workspace and defaulted to "box",
      // find any matching outline draft to recover the correct icon selection and parameters
      if (state.currentIconType === 'outline' && state.outlineIcon === 'box' && state.drafts.length > 0) {
        const matchingDraft = state.drafts.find(d => d.type === 'outline');
        if (matchingDraft) {
          state.outlineIcon = matchingDraft.value;
          if (matchingDraft.shape) {
            state.shape = matchingDraft.shape;
            updateShape(state.shape, true);
          }
          if (matchingDraft.stroke) {
            state.strokeWeight = matchingDraft.stroke;
            document.getElementById('stroke-slider').value = matchingDraft.stroke;
            strokeValueLabel.textContent = `${matchingDraft.stroke}px`;
          }
          if (matchingDraft.strokeColor) {
            state.strokeColor = matchingDraft.strokeColor;
            if (strokeHexColorLabel) strokeHexColorLabel.textContent = matchingDraft.strokeColor;
          }
          if (matchingDraft.size) {
            state.iconSize = matchingDraft.size;
            const sizeSlider = document.getElementById('size-slider');
            if (sizeSlider) {
              sizeSlider.value = matchingDraft.size;
              document.getElementById('size-value-label').textContent = `${matchingDraft.size}%`;
            }
          }
          if (matchingDraft.bgTexture) updateBgTexture(matchingDraft.bgTexture, true);
          if (matchingDraft.bgTextureScale !== undefined) {
              updateBgTextureScale(matchingDraft.bgTextureScale, true);
              if (document.getElementById('bg-texture-scale-slider')) document.getElementById('bg-texture-scale-slider').value = matchingDraft.bgTextureScale;
          }
          if (matchingDraft.bgTextureThickness !== undefined) {
              updateBgTextureThickness(matchingDraft.bgTextureThickness, true);
              if (document.getElementById('bg-texture-thickness-slider')) document.getElementById('bg-texture-thickness-slider').value = matchingDraft.bgTextureThickness;
          }
          if (matchingDraft.bgTextureColor !== undefined) {
              updateBgTextureColor(matchingDraft.bgTextureColor, true);
          }
          if (matchingDraft.contShadowDist !== undefined) {
              updateContShadowDist(matchingDraft.contShadowDist, true);
              if (document.getElementById('cont-shadow-dist-slider')) document.getElementById('cont-shadow-dist-slider').value = matchingDraft.contShadowDist;
          }
          if (matchingDraft.contShadowBlur !== undefined) {
              updateContShadowBlur(matchingDraft.contShadowBlur, true);
              if (document.getElementById('cont-shadow-blur-slider')) document.getElementById('cont-shadow-blur-slider').value = matchingDraft.contShadowBlur;
          }
          if (matchingDraft.contShadowAngle !== undefined) {
              updateContShadowAngle(matchingDraft.contShadowAngle, true);
              if (document.getElementById('cont-shadow-angle-slider')) document.getElementById('cont-shadow-angle-slider').value = matchingDraft.contShadowAngle;
          }
          if (matchingDraft.contOutlineStyle) updateContOutlineStyle(matchingDraft.contOutlineStyle, true);
          if (matchingDraft.contOutlineWidth !== undefined) {
              updateContOutlineWidth(matchingDraft.contOutlineWidth, true);
              if (document.getElementById('cont-outline-width-slider')) document.getElementById('cont-outline-width-slider').value = matchingDraft.contOutlineWidth;
          }
          if (matchingDraft.contOutlineColor) updateContOutlineColor(matchingDraft.contOutlineColor, true);
          if (matchingDraft.innerRotation !== undefined) {
              updateInnerRotation(matchingDraft.innerRotation, true);
              if (document.getElementById('inner-rotation-slider')) document.getElementById('inner-rotation-slider').value = matchingDraft.innerRotation;
          }
          if (matchingDraft.bgOpacity !== undefined) {
              updateBgOpacity(matchingDraft.bgOpacity, true);
              if (document.getElementById('bg-opacity-slider')) document.getElementById('bg-opacity-slider').value = matchingDraft.bgOpacity;
          }
          if (matchingDraft.contSize !== undefined) {
              updateContSize(matchingDraft.contSize, true);
              if (document.getElementById('cont-size-slider')) document.getElementById('cont-size-slider').value = matchingDraft.contSize;
          }
          if (matchingDraft.contShadowEnabled !== undefined) {
              toggleContShadow(matchingDraft.contShadowEnabled, true);
          }
          
          renderActivePreview();
        }
      }
    }
  } catch (e) {
    console.error("Failed to load drafts", e);
  }
}

function openWorkspace(folderName, displayName, iconData, bg) {
  state.activeApp = folderName;
  workspaceTitle.textContent = displayName;
  
  // Fallback defaults if app manifest is missing colors/icons
  bg = bg && bg !== '' ? bg : '#E5E7EB';
  bg = resolveCssColor(bg); // Resolve CSS variable tokens to hexadecimal values immediately
  iconData = iconData && iconData !== '' ? iconData : '📦';
  
  state.background = bg;
  
  let configParsed = false;
      
if (iconData.trim().startsWith('<svg')) {
  const conf = extractPwaConfig(iconData);
  if (conf) {
    try {
      state.shape = conf.shape || 'squircle';
      state.currentIconType = conf.type || 'outline';
      state.activeTab = state.currentIconType;
      state.outlineIcon = conf.icon || 'box';
      state.strokeWeight = conf.stroke !== undefined ? conf.stroke : 2;
      state.strokeColor = conf.strokeColor || '#1c1917';
      state.emojiChar = conf.emoji || '📦';
      state.shadowIntensity = conf.shadow !== undefined ? conf.shadow : 5;
      state.iconSize = conf.size !== undefined ? conf.size : 100;
            
      state.bgTexture = conf.bgTexture || 'none';
      state.bgTextureScale = conf.bgTextureScale !== undefined ? conf.bgTextureScale : 20;
      state.bgTextureThickness = conf.bgTextureThickness !== undefined ? conf.bgTextureThickness : 2;
      state.bgTextureColor = conf.bgTextureColor || '';
      state.contShadowDist = conf.contShadowDist !== undefined ? conf.contShadowDist : 0;
      state.contShadowBlur = conf.contShadowBlur !== undefined ? conf.contShadowBlur : 0;
      state.contShadowAngle = conf.contShadowAngle !== undefined ? conf.contShadowAngle : 90;
      state.contOutlineStyle = conf.contOutlineStyle || 'none';
      state.contOutlineWidth = conf.contOutlineWidth !== undefined ? conf.contOutlineWidth : 4;
      state.contOutlineColor = conf.contOutlineColor || '#1c1917';
      state.innerRotation = conf.innerRotation !== undefined ? conf.innerRotation : 0;
      state.bgOpacity = conf.bgOpacity !== undefined ? conf.bgOpacity : 100;
      state.contShadowEnabled = conf.contShadowEnabled !== undefined ? conf.contShadowEnabled : ((conf.contShadowDist || 0) > 0 || (conf.contShadowBlur || 0) > 0);
      state.contSize = conf.contSize !== undefined ? conf.contSize : 100;
      state.contSizeLocked = conf.contSizeLocked !== undefined ? conf.contSizeLocked : false;
      state.lockedRatio = state.iconSize / state.contSize;
      state.activeDatabase = conf.db || 'Lucide';
            
      configParsed = true;
    } catch(e) { console.error("Failed to parse embedded config", e); }
  }

  if (!configParsed) {
    // 1. Detect background shape directly from the SVG structure
    if (iconData.includes('<circle cx="256" cy="256" r="256"')) {
      state.shape = 'circle';
    } else if (iconData.includes('<rect width="512" height="512"')) {
      state.shape = 'square';
    } else {
      state.shape = 'squircle';
    }

    // 2. Smart SVG Parser: Differentiate compiled Emoji SVGs from Outline SVGs
    if (iconData.includes('<text') || iconData.includes('id="emoji-shadow"')) {
      state.activeTab = 'emoji';
      state.currentIconType = 'emoji';
      const emojiMatch = iconData.match(/>([^<]+)<\/text>/);
      state.emojiChar = (emojiMatch && emojiMatch[1]) ? emojiMatch[1] : '📦';
      state.outlineIcon = 'box';
      state.strokeWeight = 2; // default
            
      const fontMatch = iconData.match(/font-size="([\d.]+)"/);
      state.iconSize = (fontMatch && fontMatch[1]) ? Math.round((parseFloat(fontMatch[1]) / 240) * 100) : 100;
    } else {
      state.activeTab = 'outline';
      state.currentIconType = 'outline';
      const match = iconData.match(/lucide-([a-zA-Z0-9-]+)/);
      if (match && match[1]) {
        state.outlineIcon = match[1];
      } else {
        state.outlineIcon = 'box'; // Will try auto-recovery once drafts load
      }
            
      // Parse physical stroke-width from the compiled SVG code
      const strokeMatch = iconData.match(/stroke-width="([0-9.]+)"/);
      state.strokeWeight = (strokeMatch && strokeMatch[1]) ? parseFloat(strokeMatch[1]) : 2;
            
      // Parse physical stroke color from compiled SVG code
      const strokeColorMatch = iconData.match(/stroke="([^"]+)"/);
      state.strokeColor = (strokeColorMatch && strokeColorMatch[1] !== 'none') ? strokeColorMatch[1] : '#1c1917';
            
      const scaleMatch = iconData.match(/scale\(([\d.]+)\)/);
      state.iconSize = (scaleMatch && scaleMatch[1]) ? Math.round((parseFloat(scaleMatch[1]) / 12) * 100) : 100;
    }
    
    // Parse fill-opacity
    const opacityMatch = iconData.match(/fill-opacity="([0-9.]+)"/);
    state.bgOpacity = opacityMatch ? Math.round(parseFloat(opacityMatch[1]) * 100) : 100;
    
    // Parse inner shadow
    const shadowMatch = iconData.match(/flood-opacity="([0-9.]+)"/);
    state.shadowIntensity = shadowMatch ? Math.round(parseFloat(shadowMatch[1]) * 100) : 0;
  }
} else {
  // Raw emoji string fallback
  state.activeTab = 'emoji';
  state.currentIconType = 'emoji';
  state.emojiChar = iconData;
  state.outlineIcon = 'box';
  state.shape = 'squircle';
  state.strokeWeight = 2;
  state.iconSize = 100;
}
      
// Synchronize UI components with the newly parsed state
updateBackground(state.background, true);
updateShape(state.shape, true);
updateBgTexture(state.bgTexture, true);
updateContOutlineStyle(state.contOutlineStyle, true);
updateShadowIntensity(state.shadowIntensity, true);
      
document.getElementById('stroke-slider').value = state.strokeWeight;
if (strokeValueLabel) strokeValueLabel.textContent = `${state.strokeWeight}px`;
if (strokeHexColorLabel) strokeHexColorLabel.textContent = state.strokeColor;
      
if (document.getElementById('size-slider')) {
  document.getElementById('size-slider').value = state.iconSize;
  document.getElementById('size-value-label').textContent = `${state.iconSize}%`;
}
      
if (document.getElementById('shadow-slider')) {
  document.getElementById('shadow-slider').value = state.shadowIntensity;
}
      
if (document.getElementById('bg-texture-scale-slider')) document.getElementById('bg-texture-scale-slider').value = state.bgTextureScale;
if (bgTextureScaleLabel) bgTextureScaleLabel.textContent = `${state.bgTextureScale}px`;
      
if (document.getElementById('bg-texture-thickness-slider')) document.getElementById('bg-texture-thickness-slider').value = state.bgTextureThickness;
if (bgTextureThicknessLabel) bgTextureThicknessLabel.textContent = `${state.bgTextureThickness}px`;
      
if (bgTextureHexColorLabel) bgTextureHexColorLabel.textContent = state.bgTextureColor || 'Auto';
      
if (document.getElementById('cont-shadow-dist-slider')) document.getElementById('cont-shadow-dist-slider').value = state.contShadowDist;
if (contShadowDistLabel) contShadowDistLabel.textContent = `${state.contShadowDist}px`;
      
if (document.getElementById('cont-shadow-blur-slider')) document.getElementById('cont-shadow-blur-slider').value = state.contShadowBlur;
if (contShadowBlurLabel) contShadowBlurLabel.textContent = `${state.contShadowBlur}px`;
      
if (document.getElementById('cont-shadow-angle-slider')) document.getElementById('cont-shadow-angle-slider').value = state.contShadowAngle;
if (contShadowAngleLabel) contShadowAngleLabel.innerHTML = `${state.contShadowAngle}&deg;`;
      
if (document.getElementById('cont-outline-width-slider')) document.getElementById('cont-outline-width-slider').value = state.contOutlineWidth;
if (contOutlineHexColorLabel) contOutlineHexColorLabel.textContent = state.contOutlineColor;
      
if (document.getElementById('inner-rotation-slider')) document.getElementById('inner-rotation-slider').value = state.innerRotation;
if (innerRotationLabel) innerRotationLabel.innerHTML = `${state.innerRotation}&deg;`;

if (document.getElementById('bg-opacity-slider')) {
    document.getElementById('bg-opacity-slider').value = state.bgOpacity;
    document.getElementById('bg-opacity-label').textContent = `${state.bgOpacity}%`;
}

if (document.getElementById('cont-size-slider')) {
    document.getElementById('cont-size-slider').value = state.contSize;
    document.getElementById('cont-size-label').textContent = `${state.contSize}%`;
}

const lockIcon = document.getElementById('cont-size-lock-icon');
if (lockIcon) {
    if (state.contSizeLocked) {
        lockIcon.setAttribute('data-lucide', 'lock');
        lockIcon.classList.replace('text-stone-400', 'text-brand');
    } else {
        lockIcon.setAttribute('data-lucide', 'unlock');
        lockIcon.classList.replace('text-brand', 'text-stone-400');
    }
}

if (document.getElementById('shadow-slider')) {
    document.getElementById('shadow-slider').value = state.shadowIntensity;
    if (shadowValueLabel) shadowValueLabel.textContent = `${state.shadowIntensity}%`;
}

toggleContShadow(state.contShadowEnabled, true);
updateBgTextureSlidersState();
updateContOutlineSlidersState();
updateDbChips(state.activeDatabase);
      
switchTab(state.activeTab);renderActivePreview(); // Explicitly draw preview matching current state
  
  // Load variations for this app from the server (triggers legacy auto-recovery if needed)
  fetchDrafts(folderName);
  
  // Push virtual history state for back-gesture support
  history.pushState({ screen: 'workspace' }, '', '');
  
  screenHome.classList.add('-translate-x-full');
  screenWorkspace.classList.remove('translate-x-full');
}

function closeWorkspace(isPopState = false) {
  screenHome.classList.remove('-translate-x-full');
  screenWorkspace.classList.add('translate-x-full');
  if (!isPopState) {
    history.back();
  }
}

// Tab switching (Styling matching Mistake 4 / No jumpy heights)
function switchTab(tab) {
  state.activeTab = tab;
  if (tab === 'outline') {
    tabBtnOutline.className = "flex-1 text-xs font-semibold py-2 rounded-lg text-center transition-all duration-200 flex items-center justify-center space-x-1.5 bg-white text-stone-800 shadow-[0_2px_8px_rgba(0,0,0,0.03)]";
    tabBtnEmoji.className = "flex-1 text-xs font-semibold py-2 rounded-lg text-center transition-all duration-200 flex items-center justify-center space-x-1.5 text-stone-500";
    tabContentOutline.classList.remove('hidden');
    tabContentEmoji.classList.add('hidden');
  } else {
    tabBtnEmoji.className = "flex-1 text-xs font-semibold py-2 rounded-lg text-center transition-all duration-200 flex items-center justify-center space-x-1.5 bg-white text-stone-800 shadow-[0_2px_8px_rgba(0,0,0,0.03)]";
    tabBtnOutline.className = "flex-1 text-xs font-semibold py-2 rounded-lg text-center transition-all duration-200 flex items-center justify-center space-x-1.5 text-stone-500";
    tabContentEmoji.classList.remove('hidden');
    tabContentOutline.classList.add('hidden');
  }
}

// --- SSOT Core Engine ---
const iconCache = {};

async function getRawIconSvg(dbName, iconName) {
  const key = `${dbName}-${iconName}`;
  if (iconCache[key]) return iconCache[key];
  if (pendingFetches[key]) return pendingFetches[key];
  
  const registry = CdnRegistry[dbName];
  if (!registry) return '';
  const url = `${registry.baseUrl}${iconName}${registry.ext}`;
  
  pendingFetches[key] = fetch(url)
    .then(res => res.text())
    .then(rawSvg => {
      if (dbName === 'Local') {
        rawSvg = rawSvg.replace(/stroke="(?!(?:none|transparent)")[^"]+"/gi, '');
        rawSvg = rawSvg.replace(/fill="(?!(?:none|transparent)")[^"]+"/gi, 'fill="currentColor"');
      }
      const innerMatch = rawSvg.match(/<svg[^>]*>([\s\S]*?)<\/svg>/i);
      iconCache[key] = innerMatch ? innerMatch[1] : '';
      delete pendingFetches[key];
      return iconCache[key];
    })
    .catch(e => {
      console.error("Vector Fetch Error", e);
      delete pendingFetches[key];
      return '';
    });
    
  return pendingFetches[key];
}

function buildIconSvg(config, innerSvgContent = null) {
  const bg = config.bg || '#E5E7EB';
  const sizeVal = config.size !== undefined && config.size !== null ? parseFloat(config.size) : 100;
  const id = config.id || 'main';

  const bgTexture = config.bgTexture || 'none';
  const bgTextureScale = parseInt(config.bgTextureScale || 20);
  const bgTextureThickness = parseInt(config.bgTextureThickness || 2);
  const bgTextureColor = config.bgTextureColor || '';
  const contShadowDist = parseInt(config.contShadowDist || 0);
  const contShadowBlur = parseInt(config.contShadowBlur || 0);
  const contShadowAngle = parseInt(config.contShadowAngle !== undefined ? config.contShadowAngle : 90);
  const contOutlineStyle = config.contOutlineStyle || 'none';
  const contOutlineWidth = parseInt(config.contOutlineWidth || 4);
  const contOutlineColor = config.contOutlineColor || '#1c1917';
  const innerRotation = parseInt(config.innerRotation || 0);
  const bgOpacity = config.bgOpacity !== undefined ? parseFloat(config.bgOpacity) : 100;
  const fillOpacity = bgOpacity / 100;
  const contShadowEnabled = config.contShadowEnabled !== undefined ? config.contShadowEnabled : (contShadowDist > 0 || contShadowBlur > 0);

  const contSize = config.contSize !== undefined ? parseFloat(config.contSize) : 100;
  const contScale = contSize / 100;

  let defs = '';
  if (bgTexture !== 'none') {
      const patColor = bgTextureColor || getContrastColor(bg);
      const patId = `pat-${bgTexture}-${id}`;
      const halfScale = bgTextureScale / 2;
      if (bgTexture === 'dots') {
          defs += `<pattern id="${patId}" width="${bgTextureScale}" height="${bgTextureScale}" patternUnits="userSpaceOnUse"><circle cx="${halfScale}" cy="${halfScale}" r="${bgTextureThickness}" fill="${patColor}" opacity="0.15"/></pattern>`;
      } else if (bgTexture === 'lines') {
          defs += `<pattern id="${patId}" width="${bgTextureScale}" height="${bgTextureScale}" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><line x1="0" y1="0" x2="0" y2="${bgTextureScale}" stroke="${patColor}" stroke-width="${bgTextureThickness}" opacity="0.15"/></pattern>`;
      } else if (bgTexture === 'grid') {
          defs += `<pattern id="${patId}" width="${bgTextureScale}" height="${bgTextureScale}" patternUnits="userSpaceOnUse"><rect width="${bgTextureScale}" height="${bgTextureScale}" fill="none" stroke="${patColor}" stroke-width="${bgTextureThickness}" opacity="0.15"/></pattern>`;
      }
  }

  let contFilterAttr = '';
  let masterTransform = '';
  const needsPadding = (contShadowEnabled && (contShadowDist > 0 || contShadowBlur > 0)) || (contOutlineStyle !== 'none' && contOutlineWidth > 0);
  
  const baseScale = needsPadding ? 0.83 : 1.0;
  if (baseScale !== 1.0) {
      const trans = (256 * (1 - baseScale)).toFixed(2);
      masterTransform = `transform="translate(${trans}, ${trans}) scale(${baseScale})"`;
  }

  let contTransform = '';
  if (contScale !== 1.0) {
      const ctrans = (256 * (1 - contScale)).toFixed(2);
      contTransform = `transform="translate(${ctrans}, ${ctrans}) scale(${contScale})"`;
  }
  if (contShadowEnabled && (contShadowDist > 0 || contShadowBlur > 0)) {
      const filterId = `cont-shadow-${id}`;
      const rad = contShadowAngle * Math.PI / 180;
      const dx = (contShadowDist * Math.cos(rad)).toFixed(2);
      const dy = (contShadowDist * Math.sin(rad)).toFixed(2);
      defs += `<filter id="${filterId}" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="${dx}" dy="${dy}" stdDeviation="${contShadowBlur}" flood-color="#000000" flood-opacity="0.4"/></filter>`;
      contFilterAttr = `filter="url(#${filterId})"`;
  }

  let shapePath = '';
  if (config.shape === 'circle') shapePath = `<circle cx="256" cy="256" r="256"`;
  else if (config.shape === 'square') shapePath = `<rect width="512" height="512" rx="112"`;
  else shapePath = `<path d="M256,0 C76.8,0 0,76.8 0,256 C0,435.2 76.8,512 256,512 C435.2,512 512,435.2 512,256 C512,76.8 435.2,0 256,0 Z"`;

  let shapeXml = `${shapePath} fill="${bg}" fill-opacity="${fillOpacity}" />`;
  if (bgTexture !== 'none') {
      shapeXml += `\n  ${shapePath} fill="url(#pat-${bgTexture}-${id})" />`;
  }
  if (contOutlineStyle !== 'none') {
      shapeXml += `\n  ${shapePath} fill="none" stroke="${contOutlineColor}" stroke-width="${contOutlineWidth}" />`;
      if (contOutlineStyle === 'double') {
          shapeXml += `\n  ${shapePath} fill="none" stroke="${bg}" stroke-width="${contOutlineWidth / 2}" stroke-opacity="${fillOpacity}" />`;
      }
  }

  let contentXml = '';
  let shadowVal = config.shadow !== undefined && config.shadow !== null ? config.shadow : 5;
  let innerFilterAttr = '';
  if (shadowVal > 0) {
      const opacity = shadowVal / 100;
      const filterId = `inner-shadow-${id}`;
      defs += `<filter id="${filterId}" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="16" stdDeviation="24" flood-color="#112C20" flood-opacity="${opacity}"/></filter>`;
      innerFilterAttr = `filter="url(#${filterId})"`;
  }

  if (config.type === 'outline') {
      if (innerSvgContent) {
          const scale = 12 * (sizeVal / 100);
          const translate = (512 - (24 * scale)) / 2;
          contentXml = `<g ${innerFilterAttr}><g class="lucide-${config.icon}" transform="translate(${translate}, ${translate}) scale(${scale})" stroke="${config.strokeColor || '#1c1917'}" stroke-width="${config.stroke}" fill="none" stroke-linecap="round" stroke-linejoin="round" color="${config.strokeColor || '#1c1917'}">${innerSvgContent}</g></g>`;
      }
  } else {
      const fontSize = 240 * (sizeVal / 100);
      contentXml = `<text x="256" y="340" font-size="${fontSize}" font-family="'Apple Color Emoji', 'Segoe UI Emoji', 'Noto Color Emoji', sans-serif" text-anchor="middle" ${innerFilterAttr}>${config.emoji}</text>`;
  }

  const innerGroup = `<g transform="rotate(${innerRotation}, 256, 256)">\n    ${contentXml}\n  </g>`;
  const containerGroup = `<g ${contTransform} ${contFilterAttr}>\n    ${shapeXml}\n  </g>`;
  const masterGroup = `<g ${masterTransform}>\n    ${containerGroup}\n    ${innerGroup}\n  </g>`;
  const defsXml = defs ? `<defs>\n    ${defs}\n  </defs>\n  ` : "";

  const configStr = JSON.stringify(config).replace(/"/g, '&quot;');
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="100%" height="100%" data-pwa-studio-config="${configStr}">\n  ${defsXml}${masterGroup}\n</svg>`;
}// Shape customization controller
function updateShape(shapeType, silent = false) {
  state.shape = shapeType;
  
  const shapes = ['squircle', 'circle', 'square'];
  const chips = {
    'squircle': document.getElementById('shape-chip-squircle'),
    'circle': document.getElementById('shape-chip-circle'),
    'square': document.getElementById('shape-chip-square')
  };

  shapes.forEach(sh => {
    chips[sh].className = "py-2.5 text-xs font-semibold rounded-xl transition-all border flex flex-col items-center space-y-1 bg-stone-100 text-stone-800 border-transparent active:scale-95";
  });

  chips[shapeType].className = "py-2.5 text-xs font-semibold rounded-xl transition-all border flex flex-col items-center space-y-1 bg-[#EAF0EC] text-[#1B4332] border-[#1B4332]/20 active:scale-95";

  if (!silent) renderActivePreview();
}

// Color Customizer Swatch Updates
function updateBackground(hex, silent = false) {
  state.background = hex;
  hexColorLabel.textContent = hex;
  if (!silent) renderActivePreview();
}

function updateBgOpacity(val, silent = false) {
  state.bgOpacity = parseInt(val);
  const label = document.getElementById('bg-opacity-label');
  if (label) label.textContent = `${val}%`;
  if (!silent) renderActivePreview();
}

function updateContSize(val, silent = false) {
  state.contSize = parseInt(val);
  const label = document.getElementById('cont-size-label');
  if (label) label.textContent = `${state.contSize}%`;

  if (state.contSizeLocked && !silent) {
    let newIconSize = Math.round(state.contSize * state.lockedRatio);
    let clamped = false;
    
    if (newIconSize < 50) { newIconSize = 50; clamped = true; }
    if (newIconSize > 150) { newIconSize = 150; clamped = true; }
    
    state.iconSize = newIconSize;
    const sizeSlider = document.getElementById('size-slider');
    const sizeLabel = document.getElementById('size-value-label');
    if (sizeSlider) sizeSlider.value = newIconSize;
    if (sizeLabel) sizeLabel.textContent = `${newIconSize}%`;
    
    if (clamped) {
      state.lockedRatio = state.iconSize / state.contSize;
    }
  }

  if (!silent) renderActivePreview();
}

function toggleContSizeLock() {
  state.contSizeLocked = !state.contSizeLocked;
  if (state.contSizeLocked) {
    state.lockedRatio = state.iconSize / state.contSize;
  }
  const icon = document.getElementById('cont-size-lock-icon');
  if (icon) {
    if (state.contSizeLocked) {
      icon.setAttribute('data-lucide', 'lock');
      icon.classList.remove('text-stone-400');
      icon.classList.add('text-brand');
    } else {
      icon.setAttribute('data-lucide', 'unlock');
      icon.classList.remove('text-brand');
      icon.classList.add('text-stone-400');
    }
  }
  if (window.lucide) lucide.createIcons();
}

// Stroke weight controls
function updateStrokeWeight(val, silent = false) {
  state.strokeWeight = val;
  strokeValueLabel.textContent = `${val}px`;
  if (!silent) renderActivePreview();
}

// Stroke color controls
function updateStrokeColor(hex, silent = false) {
  state.strokeColor = hex;
  if (strokeHexColorLabel) strokeHexColorLabel.textContent = hex;
  if (!silent) renderActivePreview();
}

// Advanced Settings Controllers
function updateBgTextureSlidersState() {
  const noneActive = state.bgTexture === 'none';
  const scaleSlider = document.getElementById('bg-texture-scale-slider');
  const thickSlider = document.getElementById('bg-texture-thickness-slider');
  const colorBtn = document.querySelector('button[onclick="promptAddColor(\'bgTexture\')"]');
  const presetsContainer = document.getElementById('bg-texture-color-presets-container');
  
  const elements = [scaleSlider, thickSlider, colorBtn, presetsContainer];
  elements.forEach(el => {
    if (!el) return;
    if (noneActive) {
      el.setAttribute('disabled', 'true');
      el.classList.add('opacity-40', 'pointer-events-none');
    } else {
      el.removeAttribute('disabled');
      el.classList.remove('opacity-40', 'pointer-events-none');
    }
  });
}

function updateBgTexture(texture, silent = false) {
  state.bgTexture = texture;
  const textures = ['none', 'dots', 'lines', 'grid'];
  textures.forEach(t => {
    const chip = document.getElementById(`texture-chip-${t}`);
    if (chip) {
      if (t === texture) {
        chip.className = "px-2 py-1 text-[9px] font-bold uppercase rounded bg-white text-stone-800 shadow-sm";
      } else {
        chip.className = "px-2 py-1 text-[9px] font-bold uppercase rounded text-stone-500 hover:bg-stone-200/50";
      }
    }
  });
  updateBgTextureSlidersState();
  if (!silent) renderActivePreview();
}

function updateBgTextureScale(val, silent = false) {
  state.bgTextureScale = val;
  if (bgTextureScaleLabel) bgTextureScaleLabel.textContent = `${val}px`;
  if (!silent) renderActivePreview();
}

function updateBgTextureThickness(val, silent = false) {
  state.bgTextureThickness = val;
  if (bgTextureThicknessLabel) bgTextureThicknessLabel.textContent = `${val}px`;
  if (!silent) renderActivePreview();
}

function updateBgTextureColor(hex, silent = false) {
  state.bgTextureColor = hex;
  if (bgTextureHexColorLabel) bgTextureHexColorLabel.textContent = hex || 'Auto';
  if (!silent) renderActivePreview();
}

function updateContOutlineSlidersState() {
  const noneActive = state.contOutlineStyle === 'none';
  const widthSlider = document.getElementById('cont-outline-width-slider');
  const colorBtn = document.querySelector('button[onclick="promptAddColor(\'contOutline\')"]');
  const presetsContainer = document.getElementById('cont-outline-color-presets-container');
  
  const elements = [widthSlider, colorBtn, presetsContainer];
  elements.forEach(el => {
    if (!el) return;
    if (noneActive) {
      el.setAttribute('disabled', 'true');
      el.classList.add('opacity-40', 'pointer-events-none');
    } else {
      el.removeAttribute('disabled');
      el.classList.remove('opacity-40', 'pointer-events-none');
    }
  });
}

function updateContOutlineStyle(style, silent = false) {
  state.contOutlineStyle = style;
  const styles = ['none', 'solid', 'double'];
  styles.forEach(s => {
    const btn = document.getElementById(`outline-style-${s}`);
    if (btn) {
      if (s === style) {
        btn.className = "px-2 py-1 text-[9px] font-bold uppercase rounded bg-white text-stone-800 shadow-sm";
      } else {
        btn.className = "px-2 py-1 text-[9px] font-bold uppercase rounded text-stone-500 hover:bg-stone-200/50";
      }
    }
  });
  updateContOutlineSlidersState();
  if (!silent) renderActivePreview();
}

function updateContOutlineWidth(val, silent = false) {
  state.contOutlineWidth = val;
  if (!silent) renderActivePreview();
}

function updateContOutlineColor(hex, silent = false) {
  state.contOutlineColor = hex;
  if (contOutlineHexColorLabel) contOutlineHexColorLabel.textContent = hex;
  if (!silent) renderActivePreview();
}

function updateContShadowSlidersState() {
  const disabled = !state.contShadowEnabled;
  const distSlider = document.getElementById('cont-shadow-dist-slider');
  const blurSlider = document.getElementById('cont-shadow-blur-slider');
  const angleSlider = document.getElementById('cont-shadow-angle-slider');
  
  const elements = [distSlider, blurSlider, angleSlider];
  elements.forEach(el => {
    if (!el) return;
    if (disabled) {
      el.setAttribute('disabled', 'true');
      el.classList.add('opacity-40', 'pointer-events-none');
    } else {
      el.removeAttribute('disabled');
      el.classList.remove('opacity-40', 'pointer-events-none');
    }
  });
}

function toggleContShadow(enabled, silent = false) {
  state.contShadowEnabled = enabled;
  const btnOff = document.getElementById('shadow-toggle-off');
  const btnOn = document.getElementById('shadow-toggle-on');
  if (btnOff && btnOn) {
    if (enabled) {
      btnOn.className = "px-2.5 py-1 text-[9px] font-bold uppercase rounded bg-white text-stone-800 shadow-sm";
      btnOff.className = "px-2.5 py-1 text-[9px] font-bold uppercase rounded text-stone-500 hover:bg-stone-200/50";
    } else {
      btnOff.className = "px-2.5 py-1 text-[9px] font-bold uppercase rounded bg-white text-stone-800 shadow-sm";
      btnOn.className = "px-2.5 py-1 text-[9px] font-bold uppercase rounded text-stone-500 hover:bg-stone-200/50";
    }
  }
  updateContShadowSlidersState();
  if (!silent) renderActivePreview();
}

function updateContShadowDist(val, silent = false) {
  state.contShadowDist = val;
  if (contShadowDistLabel) contShadowDistLabel.textContent = `${val}px`;
  if (!silent) renderActivePreview();
}

function updateContShadowBlur(val, silent = false) {
  state.contShadowBlur = val;
  if (contShadowBlurLabel) contShadowBlurLabel.textContent = `${val}px`;
  if (!silent) renderActivePreview();
}

function updateContShadowAngle(val, silent = false) {
  state.contShadowAngle = val;
  if (contShadowAngleLabel) contShadowAngleLabel.innerHTML = `${val}&deg;`;
  if (!silent) renderActivePreview();
}

function updateInnerRotation(val, silent = false) {
  state.innerRotation = val;
  if (innerRotationLabel) innerRotationLabel.innerHTML = `${val}&deg;`;
  if (!silent) renderActivePreview();
}

// Shadow weights adjustments
function updateShadowIntensity(val, silent = false) {
  state.shadowIntensity = val;
  if (shadowValueLabel) shadowValueLabel.textContent = `${val}%`;
  if (!silent) renderActivePreview();
}

// Selector update logic
function updateEmoji(emoji) {
  state.emojiChar = emoji;
  state.currentIconType = 'emoji'; // Explicit change
  renderActivePreview();
}

// Vector selection rendering engine
function selectVectorIcon(iconName) {
  state.outlineIcon = iconName;
  state.currentIconType = 'outline'; // Explicit change
  renderActivePreview();
}

let EMOJI_LIST = window.PWA_STUDIO_EMOJIS || ['🍳', '✨', '📝', '🚀', '📊', '🌱', '⚡', '💬', '🔥', '🎨', '🥑', '🧩'];
let COLOR_PRESETS = window.PWA_STUDIO_COLORS || ['#FEF3C7', '#E0F2FE', '#FEE2E2', '#DCFCE7', '#F3E8FF', '#FFF1F2'];

function resolveCssColor(color) {
  if (!color) return '#E5E7EB';
  color = color.trim();
  if (color.startsWith('var(')) {
    const varName = color.match(/var\(([^)]+)\)/)[1].trim();
    let computed = getComputedStyle(document.documentElement).getPropertyValue(varName).trim();
    if (!computed) {
      computed = getComputedStyle(document.body).getPropertyValue(varName).trim();
    }
    if (computed && computed.startsWith('#')) {
      return computed;
    }
    // High-fidelity fallbacks for core variables
    if (varName === '--primary') return '#1B4332';
    if (varName === '--bg-color') return '#F5F5F4';
    return '#E5E7EB';
  }
  return color;
}

function getContrastColor(hex) {
  if (!hex || !hex.startsWith('#')) return '#1C1917';
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  // YIQ standard luminance formula
  const yiq = ((r * 299) + (g * 587) + (b * 114)) / 1000;
  return (yiq >= 128) ? '#1C1917' : '#FFFFFF';
}

function renderColorPresets() {
  renderSwatches('color-presets-container', 'bg');
  renderSwatches('stroke-color-presets-container', 'stroke');
  renderSwatches('cont-outline-color-presets-container', 'contOutline');
  renderSwatches('bg-texture-color-presets-container', 'bgTexture');
}

function renderSwatches(containerId, targetType) {
  const container = document.getElementById(containerId);
  if (!container) return;
  container.innerHTML = '';
  
  COLOR_PRESETS.forEach(color => {
  const btn = document.createElement('button');
  btn.onpointerdown = (e) => {
    SecurePress.start(e, () => {
      if (targetType === 'bg') updateBackground(color);
      else if (targetType === 'stroke') updateStrokeColor(color);
      else if (targetType === 'bgTexture') updateBgTextureColor(color);
      else updateContOutlineColor(color);
    });
  };
  btn.onclick = (e) => e.preventDefault();
  btn.className = "w-7 h-7 rounded-full border border-stone-200/20 shadow-sm relative active:scale-90 transition-all flex items-center justify-center shrink-0";
  btn.style.backgroundColor = color;
        
  let activeColor = state.background;
  if (targetType === 'stroke') activeColor = state.strokeColor;
  if (targetType === 'bgTexture') activeColor = state.bgTextureColor || '';
  if (targetType === 'contOutline') activeColor = state.contOutlineColor;

  if (activeColor.toLowerCase() === color.toLowerCase()) {const checkColor = getContrastColor(color);
      btn.innerHTML = `<i data-lucide="check" class="w-3.5 h-3.5 stroke-[3.5]" style="color: ${checkColor}"></i>`;
    }
    
    container.appendChild(btn);
  });
  
  if (window.lucide) lucide.createIcons();
}

function initColorWheel() {
  const canvas = document.getElementById('color-wheel-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const w = canvas.width;
  const h = canvas.height;
  const cx = w / 2;
  const cy = h / 2;
  const r = w / 2;
  
  ctx.clearRect(0, 0, w, h);
  
  // Paint standard 2D HSL Color Wheel
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const dx = x - cx;
      const dy = y - cy;
      const dist = Math.sqrt(dx*dx + dy*dy);
      
      if (dist <= r) {
        let angle = Math.atan2(dy, dx) * 180 / Math.PI;
        if (angle < 0) angle += 360;
        
        const sat = dist / r;
        ctx.fillStyle = `hsl(${angle}, ${sat * 100}%, 50%)`;
        ctx.fillRect(x, y, 1, 1);
      }
    }
  }
  
  const marker = document.getElementById('color-wheel-marker');
  if (marker) marker.style.display = 'none';
}

function handleColorWheelSelect(e) {
  const canvas = document.getElementById('color-wheel-canvas');
  if (!canvas) return;
  const rect = canvas.getBoundingClientRect();
  const x = e.clientX - rect.left;
  const y = e.clientY - rect.top;
  
  const cx = canvas.width / 2;
  const cy = canvas.height / 2;
  const r = canvas.width / 2;
  const dx = x - cx;
  const dy = y - cy;
  const dist = Math.sqrt(dx*dx + dy*dy);
  
  let targetX = x;
  let targetY = y;
  
  // If dragged outside the circle, lock selector coordinates to the outer radius
  if (dist > r) {
    targetX = cx + (dx / dist) * r;
    targetY = cy + (dy / dist) * r;
  }
  
  const ctx = canvas.getContext('2d');
  const imgData = ctx.getImageData(Math.floor(targetX), Math.floor(targetY), 1, 1).data;
  
  const rHex = imgData[0].toString(16).padStart(2, '0').toUpperCase();
  const gHex = imgData[1].toString(16).padStart(2, '0').toUpperCase();
  const bHex = imgData[2].toString(16).padStart(2, '0').toUpperCase();
  const hex = `#${rHex}${gHex}${bHex}`;
  
  document.getElementById('custom-color-hex-input').value = hex;
  updateCustomColorDot(hex);
  
  const marker = document.getElementById('color-wheel-marker');
  if (marker) {
    marker.style.display = 'block';
    const cssX = (targetX / canvas.width) * 180; // Map coordinates to 180px CSS container size
    const cssY = (targetY / canvas.height) * 180;
    marker.style.left = `${cssX + 6}px`; // Accommodate the 6px flex padding offset
    marker.style.top = `${cssY + 6}px`;
    marker.style.backgroundColor = hex;
  }
}

function setupColorWheelEvents() {
  const canvas = document.getElementById('color-wheel-canvas');
  if (!canvas) return;
  
  let isDragging = false;
  
  canvas.addEventListener('pointerdown', (e) => {
    isDragging = true;
    handleColorWheelSelect(e);
  });
  
  window.addEventListener('pointermove', (e) => {
    if (isDragging) {
      handleColorWheelSelect(e);
    }
  });
  
  window.addEventListener('pointerup', () => {
    isDragging = false;
  });
}

let activeColorTarget = 'bg';

function promptAddColor(target = 'bg') {
  activeColorTarget = target;
  const modal = document.getElementById('color-input-modal');
  const panel = document.getElementById('color-input-modal-panel');
  const input = document.getElementById('custom-color-hex-input');
  input.value = '';
  updateCustomColorDot('');
  modal.classList.remove('opacity-0', 'pointer-events-none');
  panel.classList.remove('scale-95');
  
  initColorWheel(); // Compile HTML5 color spectrum on launch
}

function updateCustomColorDot(val) {
  const dot = document.getElementById('custom-color-dot');
  if (!dot) return;
  if (!val) {
    dot.style.backgroundColor = 'transparent';
    return;
  }
  val = val.trim();
  if (!val.startsWith('#') && (val.length === 3 || val.length === 6)) {
    val = '#' + val;
  }
  if (/^#[0-9A-F]{3,6}$/i.test(val)) {
    dot.style.backgroundColor = val;
  } else {
    dot.style.backgroundColor = 'transparent';
  }
}

function closeColorInputModal() {
  const modal = document.getElementById('color-input-modal');
  const panel = document.getElementById('color-input-modal-panel');
  modal.classList.add('opacity-0', 'pointer-events-none');
  panel.classList.add('scale-95');
}

function selectSwatchInModal(hex) {
  document.getElementById('custom-color-hex-input').value = hex;
  updateCustomColorDot(hex);
}

async function commitAddColor() {
  const input = document.getElementById('custom-color-hex-input');
  let val = input.value.trim().toUpperCase();
  
  if (!val) {
    showToast('Please enter a color.');
    return;
  }

  if (!val.startsWith('#')) {
    val = '#' + val;
  }

  if (!/^#[0-9A-F]{6}$/i.test(val)) {
    showToast('Invalid hex format (e.g. #FFD166).');
    return;
  }

  if (COLOR_PRESETS.map(c => c.toUpperCase()).includes(val)) {
  if (activeColorTarget === 'bg') {
    updateBackground(val);
  } else if (activeColorTarget === 'stroke') {
    updateStrokeColor(val);
  } else if (activeColorTarget === 'bgTexture') {
    updateBgTextureColor(val);
  } else {
    updateContOutlineColor(val);
  }
  renderColorPresets();
  closeColorInputModal();
  return;
}

COLOR_PRESETS.push(val);
renderColorPresets();
      
if (activeColorTarget === 'bg') {
  updateBackground(val);
} else if (activeColorTarget === 'stroke') {
  updateStrokeColor(val);
} else if (activeColorTarget === 'bgTexture') {
  updateBgTextureColor(val);
} else {
  updateContOutlineColor(val);
}try {
    const res = await fetch('modules/api.php?action=save_colors', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ colors: COLOR_PRESETS })
    });
    const json = await res.json();
    if (json.success) {
      showToast('Color preset saved successfully!');
    }
  } catch (e) {
    console.error("Failed to save colors", e);
  }

  closeColorInputModal();
}

function promptAddEmoji() {
  const modal = document.getElementById('emoji-input-modal');
  const panel = document.getElementById('emoji-input-modal-panel');
  const input = document.getElementById('custom-emoji-input');
  input.value = '';
  modal.classList.remove('opacity-0', 'pointer-events-none');
  panel.classList.remove('scale-95');
  setTimeout(() => input.focus(), 300);
}

function closeEmojiInputModal() {
  const modal = document.getElementById('emoji-input-modal');
  const panel = document.getElementById('emoji-input-modal-panel');
  modal.classList.add('opacity-0', 'pointer-events-none');
  panel.classList.add('scale-95');
}

async function commitAddEmoji() {
  const input = document.getElementById('custom-emoji-input');
  const val = input.value.trim();
  
  if (!val) {
    showToast('Please enter an emoji.');
    return;
  }

  if (EMOJI_LIST.includes(val)) {
    showToast('Emoji already exists.');
    closeEmojiInputModal();
    return;
  }

  EMOJI_LIST.push(val);
  renderEmojiPicker();

  try {
    const res = await fetch('modules/api.php?action=save_emojis', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ emojis: EMOJI_LIST })
    });
    const json = await res.json();
    if (json.success) {
      showToast('Emoji added successfully!');
    }
  } catch (e) {
    console.error("Failed to save emojis", e);
  }

  closeEmojiInputModal();
}

function renderEmojiPicker() {
  const container = document.getElementById('emoji-picker-grid');
  if (!container) return;
  container.innerHTML = '';
  
  EMOJI_LIST.forEach((emoji, idx) => {
    const btn = document.createElement('button');
    btn.onpointerdown = (e) => SecurePress.start(e, () => updateEmoji(emoji));
    btn.onclick = (e) => e.preventDefault();
    btn.className = "w-9 h-9 flex items-center justify-center hover:scale-110 active:scale-90 rounded-lg transition-all drop-shadow-sm pwa-app-icon";
    if (state.emojiChar === emoji && state.currentIconType === 'emoji') {
      btn.classList.add('ring-2', 'ring-brand', 'ring-offset-1');
    }
    
    const config = {
      id: `picker-${idx}`,
      type: 'emoji',
      bg: state.background,
      shape: state.shape,
      emoji: emoji,
      shadow: state.shadowIntensity,
      size: state.iconSize
    };
    
    btn.innerHTML = buildIconSvg(config, null);
    container.appendChild(btn);
  });
}

// Simultaneous real-time preview sync using SSOT
async function renderActivePreview() {
  previewRequestCount++;
  const currentRequestId = previewRequestCount;

  const config = {
      id: 'main',
      type: state.currentIconType,
      db: state.activeDatabase,
      bg: state.background,
      shape: state.shape,
      icon: state.outlineIcon,
      stroke: state.strokeWeight,
      strokeColor: state.strokeColor,
      emoji: state.emojiChar,
      shadow: state.shadowIntensity,
      size: state.iconSize,
      bgTexture: state.bgTexture,
      bgTextureScale: state.bgTextureScale,
      bgTextureThickness: state.bgTextureThickness,
      bgTextureColor: state.bgTextureColor,
      contShadowDist: state.contShadowDist,
      contShadowBlur: state.contShadowBlur,
      contShadowAngle: state.contShadowAngle,
      contOutlineStyle: state.contOutlineStyle,
      contOutlineWidth: state.contOutlineWidth,
      contOutlineColor: state.contOutlineColor,
      innerRotation: state.innerRotation,
      bgOpacity: state.bgOpacity,
      contShadowEnabled: state.contShadowEnabled,
      contSize: state.contSize,
      contSizeLocked: state.contSizeLocked
  };

  let innerSvg = '';
  if (config.type === 'outline') {
      innerSvg = await getRawIconSvg(state.activeDatabase, config.icon);
  }

  // Safe lock: Discard older asynchronous renders if a newer request has been made
  if (currentRequestId !== previewRequestCount) {
      return;
  }

  const svgContent = buildIconSvg(config, innerSvg);
  
  previewElementIcon.innerHTML = svgContent;
  previewElementGridIcon.innerHTML = svgContent;
  previewElementDockIcon.innerHTML = svgContent;
  
  renderEmojiPicker();
  renderColorPresets(); // Re-render color swatches to update checkmark indicators
  updateDynamicPresets(); // Dynamically update preset SVGs to match the current icon
}

// Extensible Online Database Switcher
async function selectDatabase(dbName) {
  state.activeDatabase = dbName;
  updateDbChips(dbName);
  await loadCdnIndex(dbName);
  renderDatabaseIcons();
}

async function loadCdnIndex(dbName) {
  const registry = CdnRegistry[dbName];
  if (registry.icons.length > 0) return; // Already loaded

  const resultsContainer = document.getElementById('vector-search-results');
  resultsContainer.innerHTML = `<div class="col-span-6 py-4 text-center text-xs font-medium text-stone-400 flex flex-col items-center justify-center space-y-2"><i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i><span>Fetching live CDN index...</span></div>`;
  if (window.lucide) lucide.createIcons();

  try {
    const res = await fetch(`modules/api.php?action=get_cdn_index&db=${dbName}`);
    const json = await res.json();
    if (json.success && json.icons && json.icons.length > 0) {
      registry.icons = json.icons;
    }
  } catch (e) {
    console.error("Failed to fetch CDN index", e);
    resultsContainer.innerHTML = `<div class="col-span-6 py-2 text-center text-[11px] font-medium text-red-500">Failed to load CDN index</div>`;
  }
}

// Dynamic library generation inside static preview grid (No jumpy sizing)
function renderDatabaseIcons() {
  const resultsContainer = document.getElementById('vector-search-results');
  resultsContainer.innerHTML = '';
  
  const registry = CdnRegistry[state.activeDatabase];
  const icons = registry.icons.slice(0, 100); // Cap at 100 to prevent DOM lag
  
  icons.forEach(ico => {
    const iconBtn = document.createElement('button');
    iconBtn.onpointerdown = (e) => SecurePress.start(e, () => selectVectorIcon(ico));
    iconBtn.onclick = (e) => e.preventDefault();
    iconBtn.className = "w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white active:scale-90 border border-transparent hover:border-stone-200/50 transition-all text-stone-700";
    
    if (state.activeDatabase === 'Local') {
        iconBtn.innerHTML = `<div class="w-4 h-4 flex items-center justify-center opacity-40 animate-pulse bg-stone-200 rounded"></div>`;
        fetch(`${registry.baseUrl}${ico}${registry.ext}`).then(r => r.text()).then(svg => {
            let cleanSvg = svg.replace(/stroke="(?!(?:none|transparent)")[^"]+"/gi, 'stroke="currentColor"');
            cleanSvg = cleanSvg.replace(/fill="(?!(?:none|transparent)")[^"]+"/gi, 'fill="currentColor"');
            iconBtn.innerHTML = `<div class="w-4 h-4 flex items-center justify-center pointer-events-none text-stone-700 [&>svg]:w-full [&>svg]:h-full">${cleanSvg}</div>`;
        }).catch(() => {
            iconBtn.innerHTML = `<i data-lucide="image-off" class="w-4 h-4 stroke-[2]"></i>`;
            if (window.lucide) lucide.createIcons();
        });
    } else {
        // For preview purposes in the studio, we still use Lucide rendering for uniformity, 
        // but in Phase 3/4 the compiler will fetch the raw SVG from the registry.baseUrl.
        iconBtn.innerHTML = `<i data-lucide="${ico}" class="w-4 h-4 stroke-[2]"></i>`;
    }
    resultsContainer.appendChild(iconBtn);
  });
  
  if (window.lucide) lucide.createIcons();
}

// Live filter for target companion mini-apps
function handleAppSearch() {
  const query = document.getElementById('app-search-input').value.toLowerCase().trim();
  const cards = document.querySelectorAll('.app-card');
  
  cards.forEach(card => {
    const appName = card.getAttribute('data-app-name').toLowerCase();
    const appFolder = card.getAttribute('data-app-folder').toLowerCase();
    if (appName.includes(query) || appFolder.includes(query)) {
      card.style.display = '';
    } else {
      card.style.display = 'none';
    }
  });
}

// Basic icon look-up search simulator
function handleIconSearch() {
  const query = document.getElementById('icon-search-input').value.toLowerCase();
  const resultsContainer = document.getElementById('vector-search-results');
  resultsContainer.innerHTML = '';
  
  const registry = CdnRegistry[state.activeDatabase];
  const allIcons = registry.icons;
  const filtered = query ? allIcons.filter(ico => ico.includes(query)).slice(0, 100) : allIcons.slice(0, 100);
  
  filtered.forEach(ico => {
  const iconBtn = document.createElement('button');
  iconBtn.onpointerdown = (e) => SecurePress.start(e, () => selectVectorIcon(ico));
  iconBtn.onclick = (e) => e.preventDefault();
  iconBtn.className = "w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white active:scale-90 border border-transparent hover:border-stone-200/50 transition-all text-stone-700";
        
  if (state.activeDatabase === 'Local') {
      iconBtn.innerHTML = `<div class="w-4 h-4 flex items-center justify-center opacity-40 animate-pulse bg-stone-200 rounded"></div>`;
      fetch(`${registry.baseUrl}${ico}${registry.ext}`).then(r => r.text()).then(svg => {
          let cleanSvg = svg.replace(/stroke="(?!(?:none|transparent)")[^"]+"/gi, 'stroke="currentColor"');
          cleanSvg = cleanSvg.replace(/fill="(?!(?:none|transparent)")[^"]+"/gi, 'fill="currentColor"');
          iconBtn.innerHTML = `<div class="w-4 h-4 flex items-center justify-center pointer-events-none text-stone-700 [&>svg]:w-full [&>svg]:h-full">${cleanSvg}</div>`;
      }).catch(() => {
          iconBtn.innerHTML = `<i data-lucide="image-off" class="w-4 h-4 stroke-[2]"></i>`;
          if (window.lucide) lucide.createIcons();
      });
  } else {
      iconBtn.innerHTML = `<i data-lucide="${ico}" class="w-4 h-4 stroke-[2]"></i>`;
  }
  resultsContainer.appendChild(iconBtn);
});if (filtered.length === 0) {
    resultsContainer.innerHTML = `<div class="col-span-6 py-2 text-center text-[11px] font-medium text-stone-400">No icons found in list</div>`;
  }
  
  if (window.lucide) lucide.createIcons();
}

async function handleLocalSvgUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    if (!file.name.toLowerCase().endsWith('.svg')) {
        showToast('Please select a valid SVG file (.svg)');
        event.target.value = '';
        return;
    }
    
    const formData = new FormData();
    formData.append('svg_file', file);
    
    showToast('Uploading SVG...');
    
    try {
        const res = await fetch('modules/api.php?action=upload_local_svg', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        if (json.success) {
            showToast('SVG uploaded successfully!');
            // Force refresh local index
            CdnRegistry['Local'].icons = [];
            await loadCdnIndex('Local');
            renderDatabaseIcons();
            selectVectorIcon(json.filename);
        } else {
            showToast('Upload failed: ' + (json.error || 'Unknown'));
        }
    } catch (e) {
        console.error(e);
        showToast('Network error during upload.');
    }
    
    // Reset input
    event.target.value = '';
}

// Draft Variations Shelf Engine (Tactile feedback - Mistake 7)
async function saveCurrentDraft() {
  const btn = document.getElementById('save-draft-btn');
  
  // Tactile button loading animation
  btn.innerHTML = `<i data-lucide="loader-2" class="w-3.5 h-3.5 stroke-[2.5] animate-spin"></i><span>Saving...</span>`;
  if (window.lucide) lucide.createIcons();
  
  const isOutline = state.currentIconType === 'outline';
  const newDraft = {
    id: Date.now(),
    type: state.currentIconType,
    value: isOutline ? state.outlineIcon : state.emojiChar,
    bg: state.background,
    shape: state.shape,
    stroke: isOutline ? state.strokeWeight : null,
    strokeColor: isOutline ? state.strokeColor : null,
    shadow: state.shadowIntensity,
    size: state.iconSize,
    bgTexture: state.bgTexture,
    bgTextureScale: state.bgTextureScale,
    bgTextureThickness: state.bgTextureThickness,
    bgTextureColor: state.bgTextureColor,
    contShadowDist: state.contShadowDist,
    contShadowBlur: state.contShadowBlur,
    contShadowAngle: state.contShadowAngle,
    contOutlineStyle: state.contOutlineStyle,
    contOutlineWidth: state.contOutlineWidth,
    contOutlineColor: state.contOutlineColor,
    innerRotation: state.innerRotation,
    bgOpacity: state.bgOpacity,
    contShadowEnabled: state.contShadowEnabled,
    contSize: state.contSize,
    contSizeLocked: state.contSizeLocked,
    db: state.activeDatabase
  };
  
  try {
    const res = await fetch('modules/api.php?action=save_draft', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        appName: state.activeApp,
        draft: newDraft
      })
    });
    
    const json = await res.json();
    if (json.success) {
      state.drafts = json.drafts;
      renderDrafts();
      showToast('Variations shelf updated successfully!');
    } else {
      showToast('Failed to save draft.');
    }
  } catch (e) {
    console.error("Save error", e);
    showToast('Network error saving draft.');
  }
  
  // Reset active custom button
  btn.innerHTML = `<i data-lucide="folder-plus" class="w-3.5 h-3.5 stroke-[2.5]"></i><span>Save Draft</span>`;
  if (window.lucide) lucide.createIcons();
}

// Track targeted deletion element
let targetDraftId = null;
let targetPresetId = null;

function openDraftActionModal(draftId) {
  targetDraftId = draftId;
  const modal = document.getElementById('draft-action-modal');
  const panel = document.getElementById('draft-action-modal-panel');
  modal.classList.remove('opacity-0', 'pointer-events-none');
  panel.classList.remove('scale-95');
}

function closeDraftActionModal() {
  targetDraftId = null;
  const modal = document.getElementById('draft-action-modal');
  const panel = document.getElementById('draft-action-modal-panel');
  modal.classList.add('opacity-0', 'pointer-events-none');
  panel.classList.add('scale-95');
}

function openPresetActionModal(presetId) {
  targetPresetId = presetId;
  const modal = document.getElementById('delete-preset-modal');
  const panel = document.getElementById('delete-preset-modal-panel');
  modal.classList.remove('opacity-0', 'pointer-events-none');
  panel.classList.remove('scale-95');
}

function closePresetActionModal() {
  targetPresetId = null;
  const modal = document.getElementById('delete-preset-modal');
  const panel = document.getElementById('delete-preset-modal-panel');
  modal.classList.add('opacity-0', 'pointer-events-none');
  panel.classList.add('scale-95');
}

function openSettingsModal() {
  const modal = document.getElementById('settings-modal');
  const panel = document.getElementById('settings-modal-panel');
  modal.classList.remove('opacity-0', 'pointer-events-none');
  panel.classList.remove('scale-95');
  updateSecurePressUI();
}

function closeSettingsModal() {
  const modal = document.getElementById('settings-modal');
  const panel = document.getElementById('settings-modal-panel');
  modal.classList.add('opacity-0', 'pointer-events-none');
  panel.classList.add('scale-95');
}

function updateSecurePressUI() {
  const btn = document.getElementById('toggle-secure-press-btn');
  const knob = document.getElementById('toggle-secure-press-knob');
  if (!btn || !knob) return;
  if (state.securePressEnabled) {
    btn.classList.replace('bg-stone-300', 'bg-emerald-500');
    if (!btn.classList.contains('bg-emerald-500')) btn.classList.add('bg-emerald-500');
    knob.classList.add('translate-x-5');
  } else {
    btn.classList.replace('bg-emerald-500', 'bg-stone-300');
    if (!btn.classList.contains('bg-stone-300')) btn.classList.add('bg-stone-300');
    knob.classList.remove('translate-x-5');
  }
}

async function toggleSecurePress() {
  state.securePressEnabled = !state.securePressEnabled;
  updateSecurePressUI();
  
  try {
    await fetch('modules/api.php?action=save_secure_press', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ securePressEnabled: state.securePressEnabled })
    });
  } catch (e) {
    console.error("Failed to save secure press setting", e);
  }
}

async function commitDeleteDraft() {
  if (!targetDraftId) return;
  const btn = document.getElementById('btn-confirm-delete');
  btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 stroke-[2.5] animate-spin"></i><span>Deleting...</span>`;
  if (window.lucide) lucide.createIcons();
  
  try {
    const res = await fetch('modules/api.php?action=delete_draft', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        appName: state.activeApp,
        draftId: targetDraftId
      })
    });
    const json = await res.json();
    if (json.success) {
      state.drafts = json.drafts;
      renderDrafts();
      showToast('Variation deleted.');
    } else {
      showToast('Failed to delete variation.');
    }
  } catch (e) {
    console.error(e);
    showToast('Network error deleting variation.');
  }
  
  btn.innerHTML = `<i data-lucide="trash-2" class="w-5 h-5 stroke-[2.5]"></i><span>Delete Variation</span>`;
  if (window.lucide) lucide.createIcons();
  closeDraftActionModal();
}

async function commitDeletePreset() {
  if (!targetPresetId) return;
  const btn = document.getElementById('btn-confirm-delete-preset');
  btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 stroke-[2.5] animate-spin"></i><span>Deleting...</span>`;
  if (window.lucide) lucide.createIcons();
  
  try {
    const res = await fetch('modules/api.php?action=delete_preset', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ presetId: targetPresetId })
    });
    const json = await res.json();
    if (json.success) {
      state.presets = json.presets;
      renderDrafts();
      showToast('Global preset deleted.');
    } else {
      showToast('Failed to delete preset.');
    }
  } catch (e) {
    console.error(e);
    showToast('Network error deleting preset.');
  }
  
  btn.innerHTML = `<i data-lucide="trash-2" class="w-4 h-4 stroke-[2.5]"></i><span>Delete</span>`;
  if (window.lucide) lucide.createIcons();
  closePresetActionModal();
}

async function commitSavePresetFromDraft() {
  if (!targetDraftId) return;
  const dr = state.drafts.find(d => d.id == targetDraftId);
  if (!dr) return;
  
  const btn = document.getElementById('btn-confirm-save-preset');
  btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 stroke-[2.5] animate-spin"></i><span>Saving...</span>`;
  if (window.lucide) lucide.createIcons();

  const preset = { ...dr, id: Date.now() };

  try {
    const res = await fetch('modules/api.php?action=save_preset', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ preset })
    });
    const json = await res.json();
    if (json.success) {
      state.presets = json.presets;
      renderDrafts();
      showToast('Saved as Global Preset!');
    } else {
      showToast('Failed to save preset.');
    }
  } catch (e) {
    console.error(e);
    showToast('Network error saving preset.');
  }
  
  btn.innerHTML = `<i data-lucide="globe" class="w-5 h-5 stroke-[2.5]"></i><span>Save as Global Preset</span>`;
  if (window.lucide) lucide.createIcons();
  closeDraftActionModal();
}

async function updateDynamicPresets() {
    const container = document.getElementById('drafts-container');
    if (!container) return;
    const presetCards = container.querySelectorAll('.preset-card');
    for (const card of presetCards) {
        const prId = card.getAttribute('data-preset-id');
        const pr = state.presets.find(p => p.id == prId);
        if (!pr) continue;
        
        let innerSvg = '';
        if (state.currentIconType === 'outline') {
            const db = state.activeDatabase || 'Lucide';
            innerSvg = await getRawIconSvg(db, state.outlineIcon);
        }
        
        const config = {
            id: `preset-${pr.id}`,
            type: state.currentIconType,
            db: state.activeDatabase,
            bg: pr.bg,
            shape: pr.shape || 'squircle',
            icon: state.currentIconType === 'outline' ? state.outlineIcon : null,
            stroke: state.currentIconType === 'outline' ? (pr.stroke || state.strokeWeight) : null,
            strokeColor: state.currentIconType === 'outline' ? (pr.strokeColor || state.strokeColor) : null,
            emoji: state.currentIconType === 'emoji' ? state.emojiChar : null,
            shadow: pr.shadow !== null ? pr.shadow : 5,
            size: pr.size || 100,
            bgTexture: pr.bgTexture || 'none',
            bgTextureScale: pr.bgTextureScale || 20,
            bgTextureThickness: pr.bgTextureThickness || 2,
            bgTextureColor: pr.bgTextureColor || '',
            contShadowDist: pr.contShadowDist || 0,
            contShadowBlur: pr.contShadowBlur || 0,
            contShadowAngle: pr.contShadowAngle !== undefined ? pr.contShadowAngle : 90,
            contOutlineStyle: pr.contOutlineStyle || 'none',
            contOutlineWidth: pr.contOutlineWidth || 4,
            contOutlineColor: pr.contOutlineColor || '#1c1917',
            innerRotation: pr.innerRotation || 0,
            bgOpacity: pr.bgOpacity !== undefined ? pr.bgOpacity : 100,
            contShadowEnabled: pr.contShadowEnabled !== undefined ? pr.contShadowEnabled : ((pr.contShadowDist || 0) > 0 || (pr.contShadowBlur || 0) > 0),
            contSize: pr.contSize || 100,
            contSizeLocked: pr.contSizeLocked || false
        };
        
        const svgHtml = buildIconSvg(config, innerSvg);
        
        let badge = '';
        if (pr.type === 'outline') {
            badge = `<span class="absolute top-0 right-0 bg-stone-800/80 backdrop-blur-md text-white text-[7px] font-bold px-1 py-0.5 rounded-bl-xl rounded-tr-xl z-10 pointer-events-none">SVG</span>`;
        }
        
        card.innerHTML = badge + svgHtml;
    }
}

function selectPreset(pr, cardElement) {
    const bgOpacity = pr.bgOpacity !== undefined ? parseInt(pr.bgOpacity) : 100;
    const isTransparent = bgOpacity === 0;
    const shape = pr.shape || 'squircle';
    const overlayShapeClass = shape === 'circle' ? 'rounded-full' : (shape === 'square' || isTransparent ? 'rounded-xl' : 'squircle');

    const spinnerOverlay = document.createElement('div');
    spinnerOverlay.className = `absolute inset-0 bg-stone-900/10 flex items-center justify-center backdrop-blur-[0.5px] transition-all z-20 ${overlayShapeClass}`;
    spinnerOverlay.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 text-stone-800 animate-spin stroke-[3]"></i>`;
    cardElement.appendChild(spinnerOverlay);
    if (window.lucide) lucide.createIcons();

    setTimeout(() => {
        updateBackground(pr.bg, true);
        updateShape(pr.shape || 'squircle', true);
        updateBgTexture(pr.bgTexture || 'none', true);
        updateBgTextureScale(pr.bgTextureScale || 20, true);
        updateBgTextureThickness(pr.bgTextureThickness || 2, true);
        updateBgTextureColor(pr.bgTextureColor || '', true);
        updateContShadowDist(pr.contShadowDist || 0, true);
        updateContShadowBlur(pr.contShadowBlur || 0, true);
        updateContShadowAngle(pr.contShadowAngle !== undefined ? pr.contShadowAngle : 90, true);
        updateContOutlineStyle(pr.contOutlineStyle || 'none', true);
        updateContOutlineWidth(pr.contOutlineWidth || 4, true);
        updateContOutlineColor(pr.contOutlineColor || '#1c1917', true);
        updateInnerRotation(pr.innerRotation || 0, true);
        updateBgOpacity(pr.bgOpacity !== undefined ? pr.bgOpacity : 100, true);
        updateContSize(pr.contSize !== undefined ? pr.contSize : 100, true);
        
        state.contSizeLocked = pr.contSizeLocked !== undefined ? pr.contSizeLocked : false;
        state.lockedRatio = state.iconSize / state.contSize;
        const pLockIcon = document.getElementById('cont-size-lock-icon');
        if (pLockIcon) {
            if (state.contSizeLocked) {
                pLockIcon.setAttribute('data-lucide', 'lock');
                pLockIcon.classList.replace('text-stone-400', 'text-brand');
            } else {
                pLockIcon.setAttribute('data-lucide', 'unlock');
                pLockIcon.classList.replace('text-brand', 'text-stone-400');
            }
        }

        const shadowEnabled = pr.contShadowEnabled !== undefined ? pr.contShadowEnabled : ((pr.contShadowDist || 0) > 0 || (pr.contShadowBlur || 0) > 0);
        toggleContShadow(shadowEnabled, true);

        if (pr.size) {
            document.getElementById('size-slider').value = pr.size;
            state.iconSize = pr.size;
            document.getElementById('size-value-label').textContent = `${pr.size}%`;
        } else {
            document.getElementById('size-slider').value = 100;
            state.iconSize = 100;
            document.getElementById('size-value-label').textContent = `100%`;
        }
        
        if (pr.shadow !== undefined && pr.shadow !== null) {
            document.getElementById('shadow-slider').value = pr.shadow;
            updateShadowIntensity(pr.shadow, true);
        } else {
            document.getElementById('shadow-slider').value = 5;
            updateShadowIntensity(5, true);
        }

        if (pr.type === 'outline') {
            if (pr.stroke) {
                document.getElementById('stroke-slider').value = pr.stroke;
                updateStrokeWeight(pr.stroke, true);
            }
            if (pr.strokeColor) {
                updateStrokeColor(pr.strokeColor, true);
            } else {
                updateStrokeColor('#1c1917', true);
            }
            if (pr.db) {
                state.activeDatabase = pr.db;
                updateDbChips(pr.db);
            }
        }

        if (document.getElementById('cont-outline-width-slider')) document.getElementById('cont-outline-width-slider').value = pr.contOutlineWidth || 4;
        if (document.getElementById('cont-shadow-dist-slider')) document.getElementById('cont-shadow-dist-slider').value = pr.contShadowDist || 0;
        if (document.getElementById('cont-shadow-blur-slider')) document.getElementById('cont-shadow-blur-slider').value = pr.contShadowBlur || 0;
        if (document.getElementById('cont-shadow-angle-slider')) document.getElementById('cont-shadow-angle-slider').value = pr.contShadowAngle !== undefined ? pr.contShadowAngle : 90;
        if (document.getElementById('bg-texture-scale-slider')) document.getElementById('bg-texture-scale-slider').value = pr.bgTextureScale || 20;
        if (document.getElementById('bg-texture-thickness-slider')) document.getElementById('bg-texture-thickness-slider').value = pr.bgTextureThickness || 2;
        if (document.getElementById('inner-rotation-slider')) document.getElementById('inner-rotation-slider').value = pr.innerRotation || 0;
        if (document.getElementById('bg-opacity-slider')) document.getElementById('bg-opacity-slider').value = pr.bgOpacity !== undefined ? pr.bgOpacity : 100;
        if (document.getElementById('cont-size-slider')) document.getElementById('cont-size-slider').value = pr.contSize !== undefined ? pr.contSize : 100;

        updateBgTextureSlidersState();
        updateContOutlineSlidersState();

        renderActivePreview();

        if (pr.type === 'outline' && state.currentIconType === 'emoji') {
            showToast('Applied SVG preset to Emoji (stroke settings ignored)');
        } else if (pr.type === 'emoji' && state.currentIconType === 'outline') {
            showToast('Applied Universal preset to SVG');
        } else {
            showToast('Preset applied!');
        }

        spinnerOverlay.remove();
    }, 400);
}

// Inject variations into horizontal shell using SSOT
async function renderDrafts() {
  const container = document.getElementById('drafts-container');
  container.innerHTML = '';
  
  // 1. Render Global Presets
  for (const pr of state.presets) {
    const card = document.createElement('div');
    const bgOpacity = pr.bgOpacity !== undefined ? parseInt(pr.bgOpacity) : 100;
    const isTransparent = bgOpacity === 0;
    const shapeClass = (pr.shape === 'circle') ? 'rounded-full' : 'rounded-xl';
    const baseClasses = "preset-card relative w-12 h-12 flex-shrink-0 cursor-pointer hover:scale-105 active:scale-95 transition-all flex items-center justify-center select-none pwa-app-icon";
    card.className = isTransparent ? `${baseClasses} border border-dashed border-stone-300 bg-stone-100/20 ${shapeClass}` : `${baseClasses} drop-shadow-sm`;
    card.setAttribute('data-preset-id', pr.id);
    
    let longPressTimer = null;
    let isLongPress = false;
    
    const cancelTimer = () => { if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; } };

    card.addEventListener('pointerdown', (e) => {
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      isLongPress = false;
      const startX = e.clientX; const startY = e.clientY;
      longPressTimer = setTimeout(() => {
        isLongPress = true; navigator.vibrate?.(40);
        openPresetActionModal(pr.id);
      }, 700);
      const onPointerMove = (moveEvent) => {
        if (Math.abs(moveEvent.clientX - startX) > 10 || Math.abs(moveEvent.clientY - startY) > 10) cancelTimer();
      };
      card.addEventListener('pointermove', onPointerMove, { passive: true });
      const onPointerUp = () => { cancelTimer(); card.removeEventListener('pointermove', onPointerMove); card.removeEventListener('pointerup', onPointerUp); card.removeEventListener('pointerleave', onPointerUp); };
      card.addEventListener('pointerup', onPointerUp, { passive: true });
      card.addEventListener('pointerleave', onPointerUp, { passive: true });
    });
    
    card.onclick = (e) => {
      if (isLongPress) { e.preventDefault(); e.stopPropagation(); return; }
      selectPreset(pr, card);
    };
    
    container.appendChild(card);
  }

  // Visual separator between global presets and app-specific variations
  if (state.presets.length > 0 && state.drafts.length > 0) {
    const divider = document.createElement('div');
    divider.className = "w-px h-8 bg-stone-200/80 shrink-0 mx-1 self-center rounded-full";
    container.appendChild(divider);
  }

  // 2. Render App-Specific Variations
  for (const dr of state.drafts) {
    const draftCard = document.createElement('div');
    const bgOpacity = dr.bgOpacity !== undefined ? parseInt(dr.bgOpacity) : 100;
    const isTransparent = bgOpacity === 0;
    const shapeClass = (dr.shape === 'circle') ? 'rounded-full' : 'rounded-xl';
    const baseClasses = "draft-card relative w-12 h-12 flex-shrink-0 cursor-pointer hover:scale-105 active:scale-95 transition-all flex items-center justify-center select-none pwa-app-icon";
    draftCard.className = isTransparent ? `${baseClasses} border border-dashed border-stone-300 bg-stone-100/20 ${shapeClass}` : `${baseClasses} drop-shadow-sm`;
    
    let longPressTimer = null;
    let isLongPress = false;
    
    const cancelTimer = () => {
      if (longPressTimer) {
        clearTimeout(longPressTimer);
        longPressTimer = null;
      }
    };

    draftCard.addEventListener('pointerdown', (e) => {
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      isLongPress = false;
      
      const startX = e.clientX;
      const startY = e.clientY;
      
      longPressTimer = setTimeout(() => {
        isLongPress = true;
        navigator.vibrate?.(40); // Tactile Haptic Pulse
        openDraftActionModal(dr.id);
      }, 700);
      
      const onPointerMove = (moveEvent) => {
        const diffX = Math.abs(moveEvent.clientX - startX);
        const diffY = Math.abs(moveEvent.clientY - startY);
        // If finger drags more than 10px in any direction, cancel long press
        if (diffX > 10 || diffY > 10) {
          cancelTimer();
        }
      };
      
      draftCard.addEventListener('pointermove', onPointerMove, { passive: true });
      
      const onPointerUp = () => {
        cancelTimer();
        draftCard.removeEventListener('pointermove', onPointerMove);
        draftCard.removeEventListener('pointerup', onPointerUp);
        draftCard.removeEventListener('pointerleave', onPointerUp);
      };
      
      draftCard.addEventListener('pointerup', onPointerUp, { passive: true });
      draftCard.addEventListener('pointerleave', onPointerUp, { passive: true });
    });
    
    draftCard.onclick = (e) => {
      if (isLongPress) {
        e.preventDefault();
        e.stopPropagation();
        return;
      }
      selectDraft(dr, draftCard);
    };
    
    let innerSvg = '';
    if (dr.type === 'outline') {
        const db = dr.db || state.activeDatabase || 'Lucide';
        innerSvg = await getRawIconSvg(db, dr.value);
    }
    
    const config = {
        id: `draft-${dr.id}`,
        type: dr.type,
        bg: dr.bg,
        shape: dr.shape || 'squircle',
        icon: dr.type === 'outline' ? dr.value : null,
        stroke: dr.stroke || 2,
        strokeColor: dr.strokeColor || '#1c1917',
        emoji: dr.type === 'emoji' ? dr.value : null,
        shadow: dr.shadow !== null ? dr.shadow : 5,
        size: dr.size || 100,
        bgTexture: dr.bgTexture || 'none',
        bgTextureScale: dr.bgTextureScale || 20,
        bgTextureThickness: dr.bgTextureThickness || 2,
        bgTextureColor: dr.bgTextureColor || '',
        contShadowDist: dr.contShadowDist || 0,
        contShadowBlur: dr.contShadowBlur || 0,
        contShadowAngle: dr.contShadowAngle !== undefined ? dr.contShadowAngle : 90,
        contOutlineStyle: dr.contOutlineStyle || 'none',
        contOutlineWidth: dr.contOutlineWidth || 4,
        contOutlineColor: dr.contOutlineColor || '#1c1917',
        innerRotation: dr.innerRotation || 0,
        bgOpacity: dr.bgOpacity !== undefined ? dr.bgOpacity : 100,
        contShadowEnabled: dr.contShadowEnabled !== undefined ? dr.contShadowEnabled : ((dr.contShadowDist || 0) > 0 || (dr.contShadowBlur || 0) > 0),
        contSize: dr.contSize || 100,
        contSizeLocked: dr.contSizeLocked || false
    };
    
    draftCard.innerHTML = buildIconSvg(config, innerSvg);
    container.appendChild(draftCard);
  }

  // Update dynamic preset SVGs asynchronously
  updateDynamicPresets();
}

// Micro-interaction copy function (Instantly copy variant configs cleanly)
function selectDraft(dr, cardElement) {
  const bgOpacity = dr.bgOpacity !== undefined ? parseInt(dr.bgOpacity) : 100;
  const isTransparent = bgOpacity === 0;
  const shape = dr.shape || 'squircle';
  const overlayShapeClass = shape === 'circle' ? 'rounded-full' : (shape === 'square' || isTransparent ? 'rounded-xl' : 'squircle');

  // Injected localized micro spinner overlay (Tactile Feedback confirmation)
  const spinnerOverlay = document.createElement('div');
  spinnerOverlay.className = `absolute inset-0 bg-stone-900/10 flex items-center justify-center backdrop-blur-[0.5px] transition-all z-20 ${overlayShapeClass}`;
  spinnerOverlay.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 text-stone-800 animate-spin stroke-[3]"></i>`;
  cardElement.appendChild(spinnerOverlay);
  if (window.lucide) lucide.createIcons();

  setTimeout(() => {
    // Apply historical variables smoothly without triggering intermediate race-prone visual draws
    updateBackground(dr.bg, true);
    updateShape(dr.shape, true);
    
    updateBgTexture(dr.bgTexture || 'none', true);
    updateBgTextureScale(dr.bgTextureScale || 20, true);
    updateBgTextureThickness(dr.bgTextureThickness || 2, true);
    updateBgTextureColor(dr.bgTextureColor || '', true);
    updateContShadowDist(dr.contShadowDist || 0, true);
    updateContShadowBlur(dr.contShadowBlur || 0, true);
    updateContShadowAngle(dr.contShadowAngle !== undefined ? dr.contShadowAngle : 90, true);
    updateContOutlineStyle(dr.contOutlineStyle || 'none', true);
    updateContOutlineWidth(dr.contOutlineWidth || 4, true);
    updateContOutlineColor(dr.contOutlineColor || '#1c1917', true);
    updateInnerRotation(dr.innerRotation || 0, true);
    
    if (document.getElementById('cont-outline-width-slider')) document.getElementById('cont-outline-width-slider').value = dr.contOutlineWidth || 4;
    if (document.getElementById('cont-shadow-dist-slider')) document.getElementById('cont-shadow-dist-slider').value = dr.contShadowDist || 0;
    if (document.getElementById('cont-shadow-blur-slider')) document.getElementById('cont-shadow-blur-slider').value = dr.contShadowBlur || 0;
    if (document.getElementById('cont-shadow-angle-slider')) document.getElementById('cont-shadow-angle-slider').value = dr.contShadowAngle !== undefined ? dr.contShadowAngle : 90;
    if (document.getElementById('bg-texture-scale-slider')) document.getElementById('bg-texture-scale-slider').value = dr.bgTextureScale || 20;
    if (document.getElementById('bg-texture-thickness-slider')) document.getElementById('bg-texture-thickness-slider').value = dr.bgTextureThickness || 2;
    if (document.getElementById('inner-rotation-slider')) document.getElementById('inner-rotation-slider').value = dr.innerRotation || 0;
    
    updateBgOpacity(dr.bgOpacity !== undefined ? dr.bgOpacity : 100, true);
if (document.getElementById('bg-opacity-slider')) document.getElementById('bg-opacity-slider').value = dr.bgOpacity !== undefined ? dr.bgOpacity : 100;
        
updateContSize(dr.contSize !== undefined ? dr.contSize : 100, true);
if (document.getElementById('cont-size-slider')) document.getElementById('cont-size-slider').value = dr.contSize !== undefined ? dr.contSize : 100;

state.contSizeLocked = dr.contSizeLocked !== undefined ? dr.contSizeLocked : false;
state.lockedRatio = state.iconSize / state.contSize;
const dLockIcon = document.getElementById('cont-size-lock-icon');if (dLockIcon) {
    if (state.contSizeLocked) {
        dLockIcon.setAttribute('data-lucide', 'lock');
        dLockIcon.classList.replace('text-stone-400', 'text-brand');
    } else {
        dLockIcon.setAttribute('data-lucide', 'unlock');
        dLockIcon.classList.replace('text-brand', 'text-stone-400');
    }
}

const shadowEnabled = dr.contShadowEnabled !== undefined ? dr.contShadowEnabled : ((dr.contShadowDist || 0) > 0 || (dr.contShadowBlur || 0) > 0);toggleContShadow(shadowEnabled, true);
    updateBgTextureSlidersState();
    updateContOutlineSlidersState();

    if (dr.size) {
      document.getElementById('size-slider').value = dr.size;
      state.iconSize = dr.size;
      document.getElementById('size-value-label').textContent = `${dr.size}%`;
    } else {
      document.getElementById('size-slider').value = 100;
      state.iconSize = 100;
      document.getElementById('size-value-label').textContent = `100%`;
    }
    
    if (dr.shadow !== undefined && dr.shadow !== null) {
      document.getElementById('shadow-slider').value = dr.shadow;
      updateShadowIntensity(dr.shadow, true);
    } else {
      document.getElementById('shadow-slider').value = 5;
      updateShadowIntensity(5, true);
    }

    state.currentIconType = dr.type;
    
    if (dr.type === 'outline') {
      switchTab('outline');
      if (dr.stroke) {
        document.getElementById('stroke-slider').value = dr.stroke;
        updateStrokeWeight(dr.stroke, true);
      }
      if (dr.strokeColor) {
        updateStrokeColor(dr.strokeColor, true);
      } else {
        updateStrokeColor('#1c1917', true);
      }
      if (dr.db) {
        state.activeDatabase = dr.db;
        updateDbChips(dr.db);
      }
      selectVectorIcon(dr.value); // This finalizes and triggers the only necessary render
    } else {
      switchTab('emoji');
      updateEmoji(dr.value); // This finalizes and triggers the only necessary render
    }

    // Clean up indicator spinner
    spinnerOverlay.remove();
    showToast('Quietly loaded saved variation to active design!');
  }, 400);
}

// --- PHASE 3 & 4: COMPILER & COMMIT LOGIC ---

async function generateSvgPayload() {
  const config = {
      id: 'export',
      type: state.currentIconType,
      db: state.activeDatabase,
      bg: state.background,
      shape: state.shape,
      icon: state.outlineIcon,
      stroke: state.strokeWeight,
      strokeColor: state.strokeColor,
      emoji: state.emojiChar,
      shadow: state.shadowIntensity,
      size: state.iconSize,
      bgTexture: state.bgTexture,
      bgTextureScale: state.bgTextureScale,
      bgTextureThickness: state.bgTextureThickness,
      bgTextureColor: state.bgTextureColor,
      contShadowDist: state.contShadowDist,
      contShadowBlur: state.contShadowBlur,
      contShadowAngle: state.contShadowAngle,
      contOutlineStyle: state.contOutlineStyle,
      contOutlineWidth: state.contOutlineWidth,
      contOutlineColor: state.contOutlineColor,
      innerRotation: state.innerRotation,
      bgOpacity: state.bgOpacity,
      contShadowEnabled: state.contShadowEnabled
  };

  let innerSvg = '';
  if (config.type === 'outline') {
      innerSvg = await getRawIconSvg(state.activeDatabase, config.icon);
  }

  // We replace the 100% width/height with fixed 512 for the final export file
  const rawSvg = buildIconSvg(config, innerSvg);
  return rawSvg.replace('width="100%" height="100%"', 'width="512" height="512"');
}

function openApplyModal() {
  document.getElementById('apply-app-name').textContent = state.activeApp;
  document.getElementById('apply-path-icon').textContent = `/apps/${state.activeApp}/icon.svg`;
  
  const modal = document.getElementById('apply-modal');
  const panel = document.getElementById('apply-modal-panel');
  modal.classList.remove('opacity-0', 'pointer-events-none');
  panel.classList.remove('scale-95');
}

function closeApplyModal() {
  const modal = document.getElementById('apply-modal');
  const panel = document.getElementById('apply-modal-panel');
  modal.classList.add('opacity-0', 'pointer-events-none');
  panel.classList.add('scale-95');
}

function updateIndicatorEl(el, type, isActive, appFolder) {
  if (isActive) {
    el.className = "w-8 h-8 rounded-lg bg-emerald-50 text-emerald-700 hover:opacity-80 active:scale-90 transition-all flex items-center justify-center active-indicator";
  } else {
    el.className = "w-8 h-8 rounded-lg bg-stone-100/50 text-stone-300 hover:opacity-80 active:scale-90 transition-all flex items-center justify-center active-indicator";
  }
  // Dynamic update of onclick state to keep status explanation correct
  el.setAttribute('onclick', `showIndicatorStatus('${type}', ${isActive ? 'true' : 'false'}, '${appFolder}')`);
}

// Centralized Single Source of Truth card DOM refresher
function refreshAppCardDOM(card, app) {
  // Update state attributes on the card element
  card.setAttribute('data-app-icon', app.icon);
  card.setAttribute('data-app-bg', app.color);
  
  // Dynamic high-fidelity inline SVG rendering (SSOT pipeline)
  const wrapper = card.querySelector('.app-icon-wrapper');
  if (wrapper) {
    if (app.icon.trim().startsWith('<svg')) {
      wrapper.innerHTML = app.icon;
    } else {
      wrapper.innerHTML = buildIconSvg({
        type: 'emoji',
        bg: app.color,
        shape: 'squircle',
        emoji: app.icon,
        shadow: 5,
        id: 'list-' + app.name
      });
    }
    
    // Detect transparency and update wrapper classes dynamically (SSOT)
    const isTransparent = app.icon.includes('fill-opacity="0"') || app.icon.includes('fill-opacity="0.0"');
    if (isTransparent) {
      let shape = 'squircle';
      if (app.icon.includes('<circle cx="256" cy="256"')) {
        shape = 'circle';
      } else if (app.icon.includes('<rect width="512" height="512"')) {
        shape = 'square';
      }
      
      wrapper.className = "app-icon-wrapper w-12 h-12 shrink-0 relative flex items-center justify-center pwa-app-icon border border-dashed border-stone-300 bg-stone-100/20";
      if (shape === 'circle') {
        wrapper.classList.add('rounded-full');
      } else {
        wrapper.classList.add('rounded-xl');
      }
    } else {
      wrapper.className = "app-icon-wrapper w-12 h-12 shrink-0 relative flex items-center justify-center pwa-app-icon drop-shadow-sm";
    }
  }
  
  // Dynamic indicator switches
  const indicators = card.querySelectorAll('.active-indicator');
  if (indicators.length >= 4) {
    updateIndicatorEl(indicators[0], 'high_res', app.is_high_res, app.folder);
    updateIndicatorEl(indicators[1], 'fullscreen', app.is_fullscreen, app.folder);
    updateIndicatorEl(indicators[2], 'tab_icon', app.is_tab_icon, app.folder);
    updateIndicatorEl(indicators[3], 'backup', app.is_backup_safe, app.folder);
  }
}

async function commitPwaSetup() {
  const btn = document.getElementById('btn-confirm-apply');
  btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 stroke-[2.5] animate-spin"></i><span>Compiling...</span>`;
  if (window.lucide) lucide.createIcons();
  
  try {
    const svgPayload = await generateSvgPayload();
    
    const res = await fetch('modules/api.php?action=apply_pwa', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        appName: state.activeApp,
        svgContent: svgPayload,
        bgColor: state.background
      })
    });
    
    const json = await res.json();
    
    if (json.success) {
       btn.innerHTML = `<i data-lucide="check-circle" class="w-4 h-4 stroke-[2.5]"></i><span>Success!</span>`;
       if (window.lucide) lucide.createIcons();
       showToast('PWA Configuration Applied successfully!');
       
       // Dynamic Hot Refresh: Query the real current state directly from the target filesystem folder
       const scanRes = await fetch(`modules/api.php?action=scan_single_app&app=${encodeURIComponent(state.activeApp)}`);
       const scanJson = await scanRes.json();
       
       if (scanJson.success && scanJson.app) {
         const app = scanJson.app;
         
         // Locate card on the home screen
         const card = document.querySelector(`.app-card[data-app-folder="${app.folder}"]`);
         if (card) {
           // Standardize updates through our centralized card refresher (SSOT)
           refreshAppCardDOM(card, app);
           
           // Smooth transition: close modules & highlight
           setTimeout(() => {
             closeApplyModal();
             closeWorkspace();
             
             // Smoothly scroll and pulse
             setTimeout(() => {
               card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
               card.classList.add('app-highlight-pulse');
               setTimeout(() => {
                 card.classList.remove('app-highlight-pulse');
               }, 3000);
             }, 350);
           }, 1000);
           
         } else {
           setTimeout(() => { window.location.reload(); }, 1200);
         }
       } else {
         setTimeout(() => { window.location.reload(); }, 1200);
       }
    } else {
       btn.innerHTML = `<span>Error</span>`;
       showToast('Error: ' + (json.error || 'Unknown'));
       setTimeout(() => closeApplyModal(), 2000);
    }
  } catch (e) {
    console.error(e);
    btn.innerHTML = `<span>Network Error</span>`;
    showToast('Network error during commit.');
    setTimeout(() => closeApplyModal(), 2000);
  }
}

// Batch Drawer State
let isBatchDrawerOpen = false;
let isPreviewMode = false;
let originalAppsState = {}; // { folder: { icon: "...", bg: "...", is_high_res: ... } }
let activeBatchPreset = null;
let currentBatchPayloads = [];

function toggleBatchDrawer() {
  const drawer = document.getElementById('batch-drawer');
  
  if (isBatchDrawerOpen) {
    drawer.classList.add('translate-x-full');
    isBatchDrawerOpen = false;
    if (isPreviewMode) {
      cancelBatchPreview();
    }
  } else {
    drawer.classList.remove('translate-x-full');
    isBatchDrawerOpen = true;
    renderBatchPresets();
  }
}

function renderBatchPresets() {
  const container = document.getElementById('batch-presets-container');
  container.innerHTML = '';
  
  state.presets.forEach(pr => {
    const card = document.createElement('div');
    const bgOpacity = pr.bgOpacity !== undefined ? parseInt(pr.bgOpacity) : 100;
    const isTransparent = bgOpacity === 0;
    const shapeClass = (pr.shape === 'circle') ? 'rounded-full' : 'rounded-xl';
    const baseClasses = "preset-card relative w-12 h-12 flex-shrink-0 cursor-pointer hover:scale-105 active:scale-95 transition-all flex items-center justify-center select-none pwa-app-icon mx-auto";
    card.className = isTransparent ? `${baseClasses} border border-dashed border-stone-300 bg-stone-100/20 ${shapeClass}` : `${baseClasses} drop-shadow-sm`;
    
    const config = {
        id: `batch-preset-${pr.id}`,
        type: pr.type,
        bg: pr.bg,
        shape: pr.shape || 'squircle',
        icon: pr.type === 'outline' ? 'layers' : null,
        stroke: pr.type === 'outline' ? (pr.stroke || 2) : null,
        strokeColor: pr.type === 'outline' ? (pr.strokeColor || '#1c1917') : null,
        emoji: pr.type === 'emoji' ? pr.value : null,
        shadow: pr.shadow !== null ? pr.shadow : 5,
        size: pr.size || 100,
        bgTexture: pr.bgTexture || 'none',
        bgTextureScale: pr.bgTextureScale || 20,
        bgTextureThickness: pr.bgTextureThickness || 2,
        bgTextureColor: pr.bgTextureColor || '',
        contShadowDist: pr.contShadowDist || 0,
        contShadowBlur: pr.contShadowBlur || 0,
        contShadowAngle: pr.contShadowAngle !== undefined ? pr.contShadowAngle : 90,
        contOutlineStyle: pr.contOutlineStyle || 'none',
        contOutlineWidth: pr.contOutlineWidth || 4,
        contOutlineColor: pr.contOutlineColor || '#1c1917',
        innerRotation: pr.innerRotation || 0,
        bgOpacity: pr.bgOpacity !== undefined ? pr.bgOpacity : 100,
        contShadowEnabled: pr.contShadowEnabled !== undefined ? pr.contShadowEnabled : ((pr.contShadowDist || 0) > 0 || (pr.contShadowBlur || 0) > 0),
        contSize: pr.contSize || 100,
        contSizeLocked: pr.contSizeLocked || false
    };
    
    const innerSvg = pr.type === 'outline' ? `<circle cx="12" cy="12" r="10"></circle><path d="M12 2a10 10 0 0 1 10 10c0 5.523-4.477 10-10 10S2 17.523 2 12 7.477 2 12 2z"></path><path d="m8 12 2.5 2.5L16 9"></path>` : null;
    card.innerHTML = buildIconSvg(config, innerSvg);
    
    card.onclick = () => previewBatchPreset(pr, card);
    container.appendChild(card);
  });
  if (window.lucide) lucide.createIcons();
}

async function previewBatchPreset(pr, cardElement) {
  activeBatchPreset = pr;
  document.getElementById('batch-preview-controls').classList.remove('hidden');
  document.getElementById('batch-preview-controls').classList.add('flex');
  
  document.querySelectorAll('#batch-presets-container .preset-card').forEach(c => c.classList.remove('ring-2', 'ring-brand', 'ring-offset-2'));
  cardElement.classList.add('ring-2', 'ring-brand', 'ring-offset-2');
  
  const cards = document.querySelectorAll('.app-card');
  
  if (!isPreviewMode) {
    originalAppsState = {};
    cards.forEach(card => {
      const folder = card.getAttribute('data-app-folder');
      originalAppsState[folder] = {
        icon: card.getAttribute('data-app-icon'),
        bg: card.getAttribute('data-app-bg'),
        name: card.getAttribute('data-app-name')
      };
    });
    isPreviewMode = true;
  }
  
  currentBatchPayloads = [];
  
  for (const card of cards) {
    const folder = card.getAttribute('data-app-folder');
    const orig = originalAppsState[folder];
    
    let origType = 'outline';
    let origIcon = 'box';
    let origEmoji = '📦';
    let origDb = 'Lucide';
    let origStroke = 2;
    let origStrokeColor = '#1c1917';
    let rawSvgContent = '';
    
    if (orig.icon.trim().startsWith('<svg')) {
      const conf = extractPwaConfig(orig.icon);
      if (conf) {
        origType = conf.type || 'outline';
        origIcon = conf.icon || 'box';
        origEmoji = conf.emoji || '📦';
        origDb = conf.db || 'Lucide';
        origStroke = conf.stroke !== undefined ? conf.stroke : 2;
        origStrokeColor = conf.strokeColor || '#1c1917';
      } else {
        if (orig.icon.includes('<text') || orig.icon.includes('id="emoji-shadow"')) {
          origType = 'emoji';
          const emojiMatch = orig.icon.match(/>([^<]+)<\/text>/);
          origEmoji = (emojiMatch && emojiMatch[1]) ? emojiMatch[1] : '📦';
        } else {
          origType = 'outline';
          const match = orig.icon.match(/lucide-([a-zA-Z0-9-]+)/);
          origIcon = (match && match[1]) ? match[1] : 'box';
        }
      }
    } else {
      origType = 'emoji';
      origEmoji = orig.icon;
    }
    
    if (origType === 'outline') {
      rawSvgContent = await getRawIconSvg(origDb, origIcon);
    }
    
    const config = {
      id: `batch-${folder}`,
      type: origType,
      db: origDb,
      bg: pr.bg,
      shape: pr.shape || 'squircle',
      icon: origType === 'outline' ? origIcon : null,
      stroke: origType === 'outline' ? (pr.type === 'outline' ? (pr.stroke || origStroke) : origStroke) : null,
      strokeColor: origType === 'outline' ? (pr.type === 'outline' ? (pr.strokeColor || origStrokeColor) : origStrokeColor) : null,
      emoji: origType === 'emoji' ? origEmoji : null,
      shadow: pr.shadow !== null ? pr.shadow : 5,
      size: pr.size || 100,
      bgTexture: pr.bgTexture || 'none',
      bgTextureScale: pr.bgTextureScale || 20,
      bgTextureThickness: pr.bgTextureThickness || 2,
      bgTextureColor: pr.bgTextureColor || '',
      contShadowDist: pr.contShadowDist || 0,
      contShadowBlur: pr.contShadowBlur || 0,
      contShadowAngle: pr.contShadowAngle !== undefined ? pr.contShadowAngle : 90,
      contOutlineStyle: pr.contOutlineStyle || 'none',
      contOutlineWidth: pr.contOutlineWidth || 4,
      contOutlineColor: pr.contOutlineColor || '#1c1917',
      innerRotation: pr.innerRotation || 0,
      bgOpacity: pr.bgOpacity !== undefined ? pr.bgOpacity : 100,
      contShadowEnabled: pr.contShadowEnabled !== undefined ? pr.contShadowEnabled : ((pr.contShadowDist || 0) > 0 || (pr.contShadowBlur || 0) > 0),
      contSize: pr.contSize || 100,
      contSizeLocked: pr.contSizeLocked || false
    };
    
    const newSvg = buildIconSvg(config, rawSvgContent);
    const exportSvg = newSvg.replace('width="100%" height="100%"', 'width="512" height="512"');
    
    currentBatchPayloads.push({
      appName: folder,
      svgContent: exportSvg,
      bgColor: pr.bg
    });
    
    const wrapper = card.querySelector('.app-icon-wrapper');
    if (wrapper) {
      wrapper.innerHTML = newSvg;
      const isTransparent = config.bgOpacity === 0;
      wrapper.className = "app-icon-wrapper w-12 h-12 shrink-0 relative flex items-center justify-center pwa-app-icon";
      if (isTransparent) {
        wrapper.className += " border border-dashed border-stone-300 bg-stone-100/20";
        wrapper.className += config.shape === 'circle' ? " rounded-full" : " rounded-xl";
      } else {
        wrapper.className += " drop-shadow-sm";
      }
    }
  }
}

function cancelBatchPreview() {
  if (!isPreviewMode) return;
  
  const cards = document.querySelectorAll('.app-card');
  cards.forEach(card => {
    const folder = card.getAttribute('data-app-folder');
    const orig = originalAppsState[folder];
    if (orig) {
      refreshAppCardDOM(card, {
        icon: orig.icon,
        color: orig.bg,
        name: orig.name,
        folder: folder,
        is_high_res: card.querySelectorAll('.active-indicator')[0].classList.contains('bg-emerald-50'),
        is_fullscreen: card.querySelectorAll('.active-indicator')[1].classList.contains('bg-emerald-50'),
        is_tab_icon: card.querySelectorAll('.active-indicator')[2].classList.contains('bg-emerald-50'),
        is_backup_safe: card.querySelectorAll('.active-indicator')[3].classList.contains('bg-emerald-50')
      });
    }
  });
  
  isPreviewMode = false;
  activeBatchPreset = null;
  currentBatchPayloads = [];
  
  document.getElementById('batch-preview-controls').classList.add('hidden');
  document.getElementById('batch-preview-controls').classList.remove('flex');
  document.querySelectorAll('#batch-presets-container .preset-card').forEach(c => c.classList.remove('ring-2', 'ring-brand', 'ring-offset-2'));
}

async function commitBatchApply() {
  if (!isPreviewMode || currentBatchPayloads.length === 0) return;
  
  const btn = document.getElementById('btn-batch-apply');
  btn.innerHTML = `<i data-lucide="loader-2" class="w-6 h-6 stroke-[2.5] animate-spin"></i>`;
  if (window.lucide) lucide.createIcons();
  
  try {
    const res = await fetch('modules/api.php?action=apply_batch_pwa', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ apps: currentBatchPayloads })
    });
    
    const json = await res.json();
    if (json.success) {
      showToast('Batch PWA Configuration Applied!');
      setTimeout(() => { window.location.reload(); }, 1200);
    } else {
      showToast('Batch Error: ' + (json.error || 'Unknown'));
      btn.innerHTML = `<i data-lucide="check" class="w-6 h-6 stroke-[2.5]"></i>`;
      if (window.lucide) lucide.createIcons();
    }
  } catch(e) {
    console.error(e);
    showToast('Network error during batch apply.');
    btn.innerHTML = `<i data-lucide="check" class="w-6 h-6 stroke-[2.5]"></i>`;
    if (window.lucide) lucide.createIcons();
  }
}

function openBatchBackupsModal() {
  const modal = document.getElementById('batch-backups-modal');
  const panel = document.getElementById('batch-backups-modal-panel');
  modal.classList.remove('opacity-0', 'pointer-events-none');
  panel.classList.remove('scale-95');
  fetchBatchBackups();
}

function closeBatchBackupsModal() {
  const modal = document.getElementById('batch-backups-modal');
  const panel = document.getElementById('batch-backups-modal-panel');
  modal.classList.add('opacity-0', 'pointer-events-none');
  panel.classList.add('scale-95');
}

async function fetchBatchBackups() {
  const container = document.getElementById('batch-backups-container');
  container.innerHTML = `<div class="text-xs text-stone-400 py-2 text-center"><i data-lucide="loader-2" class="w-4 h-4 animate-spin mx-auto"></i></div>`;
  if (window.lucide) lucide.createIcons();
  
  try {
    const res = await fetch('modules/api.php?action=get_batch_backups');
    const json = await res.json();
    if (json.success) {
      container.innerHTML = '';
      if (json.backups.length === 0) {
        container.innerHTML = `<div class="text-xs text-stone-400 py-4 text-center">No batch backups found.</div>`;
        return;
      }
      
      json.backups.forEach(bk => {
        const btn = document.createElement('button');
        btn.className = "w-full flex items-center justify-between p-3 bg-white border border-stone-200 rounded-xl hover:border-stone-300 active:scale-[0.98] transition-all text-left";
        btn.onclick = () => {
          closeBatchBackupsModal();
          setTimeout(() => openBatchActionModal(bk.id, bk.date), 300);
        };
        btn.innerHTML = `
          <div>
            <div class="text-xs font-bold text-stone-700">${bk.date}</div>
            <div class="text-[10px] text-stone-400">${bk.appCount} apps affected</div>
          </div>
          <i data-lucide="more-vertical" class="w-4 h-4 text-stone-400"></i>
        `;
        container.appendChild(btn);
      });
      if (window.lucide) lucide.createIcons();
    }
  } catch(e) {
    container.innerHTML = `<div class="text-xs text-red-500 py-2">Failed to load backups.</div>`;
  }
}

let targetBatchId = null;

function openBatchActionModal(batchId, dateStr) {
  targetBatchId = batchId;
  document.getElementById('batch-action-date').textContent = dateStr;
  
  const modal = document.getElementById('batch-action-modal');
  const panel = document.getElementById('batch-action-modal-panel');
  modal.classList.remove('opacity-0', 'pointer-events-none');
  panel.classList.remove('scale-95');
  
  document.getElementById('btn-batch-restore').onclick = () => commitBatchRestore();
  document.getElementById('btn-batch-delete').onclick = () => commitBatchDelete();
}

function closeBatchActionModal() {
  targetBatchId = null;
  const modal = document.getElementById('batch-action-modal');
  const panel = document.getElementById('batch-action-modal-panel');
  modal.classList.add('opacity-0', 'pointer-events-none');
  panel.classList.add('scale-95');
}

async function commitBatchDelete() {
  if (!targetBatchId) return;
  const btn = document.getElementById('btn-batch-delete');
  btn.innerHTML = `<i data-lucide="loader-2" class="w-5 h-5 stroke-[2.5] animate-spin"></i><span>Deleting...</span>`;
  if (window.lucide) lucide.createIcons();
  
  try {
    const res = await fetch('modules/api.php?action=delete_batch_backup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ batchId: targetBatchId })
    });
    const json = await res.json();
    if (json.success) {
      showToast('Batch backup deleted.');
      fetchBatchBackups();
    }
  } catch(e) {}
  
  btn.innerHTML = `<i data-lucide="trash-2" class="w-5 h-5 stroke-[2.5]"></i><span>Delete Backup</span>`;
  if (window.lucide) lucide.createIcons();
  closeBatchActionModal();
}

async function commitBatchRestore(skipDrifted = false) {
  if (!targetBatchId) return;
  
  const btn = document.getElementById('btn-batch-restore');
  if (!skipDrifted) {
    btn.innerHTML = `<i data-lucide="loader-2" class="w-5 h-5 stroke-[2.5] animate-spin"></i><span>Restoring...</span>`;
    if (window.lucide) lucide.createIcons();
  }
  
  try {
    const res = await fetch('modules/api.php?action=restore_batch_backup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ batchId: targetBatchId, skipDrifted: skipDrifted })
    });
    const json = await res.json();
    
    if (json.requires_confirmation) {
      closeBatchActionModal();
      const list = document.getElementById('batch-drifted-list');
      list.innerHTML = '';
      json.drifted_apps.forEach(app => {
        const li = document.createElement('li');
        li.textContent = `- ${app}`;
        list.appendChild(li);
      });
      
      const dModal = document.getElementById('batch-restore-modal');
      const dPanel = document.getElementById('batch-restore-modal-panel');
      dModal.classList.remove('opacity-0', 'pointer-events-none');
      dPanel.classList.remove('scale-95');
      
      document.getElementById('btn-batch-restore-skip').onclick = () => {
        dModal.classList.add('opacity-0', 'pointer-events-none');
        dPanel.classList.add('scale-95');
        commitBatchRestore(true);
      };
      
      btn.innerHTML = `<i data-lucide="rotate-ccw" class="w-5 h-5 stroke-[2.5]"></i><span>Restore Batch</span>`;
      if (window.lucide) lucide.createIcons();
      return;
    }
    
    if (json.success) {
      showToast(`Batch restored. ${json.restored.length} apps reverted.`);
      setTimeout(() => { window.location.reload(); }, 1200);
    } else {
      showToast('Error: ' + (json.error || 'Unknown'));
    }
  } catch(e) {
    showToast('Error restoring batch.');
  }
  
  if (!skipDrifted) {
    btn.innerHTML = `<i data-lucide="rotate-ccw" class="w-5 h-5 stroke-[2.5]"></i><span>Restore Batch</span>`;
    if (window.lucide) lucide.createIcons();
    closeBatchActionModal();
  }
}

function closeBatchRestoreModal() {
  const dModal = document.getElementById('batch-restore-modal');
  const dPanel = document.getElementById('batch-restore-modal-panel');
  dModal.classList.add('opacity-0', 'pointer-events-none');
  dPanel.classList.add('scale-95');
}

// System Feedback Toast
function showToast(message) {
  toastText.textContent = message;
  toast.className = "absolute top-12 left-4 right-4 bg-stone-900 text-stone-100 text-xs py-3 px-4 rounded-xl shadow-lg flex items-center space-x-2 transform translate-y-0 opacity-100 transition-all duration-300 z-50 pointer-events-none";
  
  setTimeout(() => {
    toast.className = "absolute top-12 left-4 right-4 bg-stone-900 text-stone-100 text-xs py-3 px-4 rounded-xl shadow-lg flex items-center space-x-2 transform translate-y-[-100px] opacity-0 transition-all duration-300 z-50 pointer-events-none";
  }, 3000);
}