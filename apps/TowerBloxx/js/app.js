// ============================================================================
// TOWER BLOXX - 3D ENGINE
// ============================================================================

// --- GAME CONFIG ---
const CONFIG = {
    blockHeight: 3,
    blockWidth: 4,
    blockDepth: 4,
    swingAmplitude: 6,
    swingSpeed: 2.5,
    dropSpeed: 25,
    perfectTolerance: 0.3,
    okTolerance: 2.0,
    cameraOffset: { x: 0, y: 8, z: 25 },
    gravity: 40
};

// --- STATE ---
let state = {
    mode: 'menu', // menu, swinging, dropping, gameover
    score: 0,
    combo: 1,
    lives: 3,
    towerOffset: 0, // Cumulative offset causing tower sway
    swingTime: 0,
    cameraTargetY: CONFIG.cameraOffset.y
};

// --- THREE.JS GLOBALS ---
let scene, camera, renderer;
let texWall, texEnv, texBg; // CDN Textures
let placedBlocks =[];
let activeBlock = null;
let craneLine = null;
let cityscapeGroup = null;
let particleSystem = null;
let particles = [];

// --- DOM ELEMENTS ---
const uiScore = document.getElementById('score');
const uiCombo = document.getElementById('combo-display');
const uiLives = document.getElementById('lives-display');
const startScreen = document.getElementById('start-screen');
const gameOverScreen = document.getElementById('game-over-screen');
const finalScore = document.getElementById('final-score');
const flashFx = document.getElementById('flash-fx');

// --- INITIALIZATION ---
// --- PROCEDURAL TEXTURE GENERATORS ---
function createNoiseTexture(color1, color2) {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 256;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = color1;
    ctx.fillRect(0, 0, 256, 256);
    for (let i = 0; i < 5000; i++) {
        ctx.fillStyle = Math.random() > 0.5 ? color2 : 'rgba(0,0,0,0.05)';
        ctx.fillRect(Math.random() * 256, Math.random() * 256, 2, 2);
    }
    const tex = new THREE.CanvasTexture(canvas);
    tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
    return tex;
}

function createGridTexture() {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 128;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#1a252f';
    ctx.fillRect(0, 0, 128, 128);
    ctx.strokeStyle = 'rgba(255,255,255,0.05)';
    ctx.lineWidth = 2;
    ctx.strokeRect(0, 0, 128, 128);
    const tex = new THREE.CanvasTexture(canvas);
    tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
    return tex;
}

function init() {
    // Generate Procedural Textures (100% Reliable)
    texWall = createNoiseTexture('#ffffff', '#eeeeee');
    texBg = createGridTexture();
    
    // Setup Scene
    scene = new THREE.Scene();
    scene.background = new THREE.Color(0x87CEEB); // Sky blue
    scene.fog = new THREE.Fog(0x87CEEB, 20, 80);

    // Setup Camera
    camera = new THREE.PerspectiveCamera(45, window.innerWidth / window.innerHeight, 0.1, 100);
    camera.position.set(CONFIG.cameraOffset.x, CONFIG.cameraOffset.y, CONFIG.cameraOffset.z);
    camera.lookAt(0, 5, 0);

    // Setup Renderer
    renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setSize(window.innerWidth, window.innerHeight);
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    document.getElementById('game-container').appendChild(renderer.domElement);

    // Setup Lights
    const hemiLight = new THREE.HemisphereLight(0xffffff, 0x888888, 0.9);
    hemiLight.position.set(0, 20, 0);
    scene.add(hemiLight);

    const ambientLight = new THREE.AmbientLight(0xffffff, 0.4);
    scene.add(ambientLight);

    const dirLight = new THREE.DirectionalLight(0xffffff, 1.0);
    dirLight.position.set(10, 20, 10);
    dirLight.castShadow = true;
    dirLight.shadow.bias = -0.001; // Fixes shadow flickering (Z-fighting on shadows)
    dirLight.shadow.mapSize.width = 1024;
    dirLight.shadow.mapSize.height = 1024;
    dirLight.shadow.camera.top = 20;
    dirLight.shadow.camera.bottom = -20;
    dirLight.shadow.camera.left = -20;
    dirLight.shadow.camera.right = 20;
    scene.add(dirLight);

    // Setup Environment (Ground)
    const groundGeo = new THREE.PlaneGeometry(200, 200);
    const groundMat = new THREE.MeshStandardMaterial({ color: 0x34495e, roughness: 1 });
    const ground = new THREE.Mesh(groundGeo, groundMat);
    ground.rotation.x = -Math.PI / 2;
    ground.receiveShadow = true;
    scene.add(ground);

    createCityscape();

    // Event Listeners
    window.addEventListener('resize', onWindowResize, false);
    document.addEventListener('pointerdown', handleInput, false);
    document.getElementById('start-btn').addEventListener('click', startGame);
    document.getElementById('restart-btn').addEventListener('click', startGame);

    // Start Loop
    requestAnimationFrame(animate);
}

// --- PROCEDURAL BUILDING GENERATOR ---
function createBuildingBlock(colorHex) {
    const group = new THREE.Group();
    
    // Materials with Procedural Textures
    const bodyMat = new THREE.MeshStandardMaterial({ 
        color: colorHex, 
        roughness: 0.7, 
        metalness: 0.1,
        map: texWall,
        bumpMap: texWall,
        bumpScale: 0.1
    });
    const trimMat = new THREE.MeshStandardMaterial({ color: 0xf1c40f, roughness: 0.4, metalness: 0.3, map: texWall }); 
    const frameMat = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.8 }); 
    const winMat = new THREE.MeshStandardMaterial({ 
        color: 0x3498db, 
        roughness: 0.2, 
        metalness: 0.5
    }); // High-gloss blue glass
    const shineMat = new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.3, depthWrite: false }); // Sparkle

    // 1. Central Core
    const coreGeo = new THREE.BoxGeometry(CONFIG.blockWidth * 0.8, CONFIG.blockHeight, CONFIG.blockDepth * 0.8);
    const core = new THREE.Mesh(coreGeo, bodyMat);
    core.castShadow = true;
    core.receiveShadow = true;
    group.add(core);

    // 2. Bay Windows (Front, Left, Right)
    const bayGeo = new THREE.BoxGeometry(CONFIG.blockWidth * 0.7, CONFIG.blockHeight * 0.9, 1.2);
    const bayPositions = [
        { pos: [0, 0, CONFIG.blockDepth/2], rot: 0 },
        { pos: [-CONFIG.blockWidth/2, 0, 0], rot: Math.PI/2 },
        { pos: [CONFIG.blockWidth/2, 0, 0], rot: -Math.PI/2 }
    ];

    bayPositions.forEach(config => {
        const bay = new THREE.Mesh(bayGeo, bodyMat);
        bay.position.set(...config.pos);
        bay.rotation.y = config.rot;
        group.add(bay);

        // Add Windows to Bay
        for(let i = -1; i <= 1; i++) {
            // White Frame
            const frameGeo = new THREE.BoxGeometry(0.9, 1.8, 0.05);
            const frame = new THREE.Mesh(frameGeo, frameMat);
            frame.position.set(i * 0.8, 0, 0.61);
            bay.add(frame);

            // Glass
            const glassGeo = new THREE.BoxGeometry(0.7, 1.6, 0.02);
            const glass = new THREE.Mesh(glassGeo, winMat);
            glass.position.z = 0.03;
            frame.add(glass);

            // Shine Sparkle (Fixed Z-Fighting)
            const shineGeo = new THREE.PlaneGeometry(0.2, 2.0);
            const shine = new THREE.Mesh(shineGeo, shineMat);
            shine.rotation.z = Math.PI / 4;
            shine.position.z = 0.015; // Shifted out to prevent coplanar flickering
            glass.add(shine);
        }
    });

    // 3. Yellow Roof Rim
    const rimGeo = new THREE.BoxGeometry(CONFIG.blockWidth + 0.4, 0.4, CONFIG.blockDepth + 0.4);
    const rim = new THREE.Mesh(rimGeo, trimMat);
    rim.position.y = (CONFIG.blockHeight / 2) - 0.2;
    group.add(rim);

    // 4. Roof Attachment Knob (from photo)
    const knobGeo = new THREE.CylinderGeometry(0.3, 0.3, 0.4);
    const knob = new THREE.Mesh(knobGeo, trimMat);
    knob.position.y = (CONFIG.blockHeight / 2) + 0.1;
    group.add(knob);

    return group;
}

function createCrane() {
    if (craneLine) scene.remove(craneLine);
    
    const group = new THREE.Group();
    
    // Cable
    const material = new THREE.LineBasicMaterial({ color: 0x333333 });
    const geometry = new THREE.BufferGeometry().setFromPoints([
        new THREE.Vector3(0, 0, 0),
        new THREE.Vector3(0, -10, 0)
    ]);
    const line = new THREE.Line(geometry, material);
    group.add(line);

    // Hook Assembly (Yellow/Red from photo)
    const hookBaseGeo = new THREE.BoxGeometry(1, 0.5, 1);
    const hookBaseMat = new THREE.MeshStandardMaterial({ color: 0xf1c40f });
    const hookBase = new THREE.Mesh(hookBaseGeo, hookBaseMat);
    hookBase.position.y = -10;
    group.add(hookBase);

    const hookTipGeo = new THREE.CylinderGeometry(0.2, 0.2, 0.8);
    const hookTipMat = new THREE.MeshStandardMaterial({ color: 0xe74c3c });
    const hookTip = new THREE.Mesh(hookTipGeo, hookTipMat);
    hookTip.position.y = -10.5;
    group.add(hookTip);

    craneLine = group;
    scene.add(craneLine);
}

function updateCrane() {
    if (!craneLine || !activeBlock || state.mode !== 'swinging') return;
    
    const pivotY = state.cameraTargetY + 15;
    craneLine.position.set(0, pivotY, 0);
    
    // Point hook at block
    const targetPos = activeBlock.position.clone();
    targetPos.y += (CONFIG.blockHeight / 2);
    
    // We adjust the scale of the cable to reach the block
    const dist = pivotY - targetPos.y;
    craneLine.children[0].scale.y = dist / 10;
    craneLine.children[1].position.y = -dist;
    craneLine.children[2].position.y = -dist - 0.5;
    
    // Swing the whole crane group
    craneLine.children[0].rotation.z = activeBlock.rotation.z;
    craneLine.children[1].position.x = activeBlock.position.x;
    craneLine.children[2].position.x = activeBlock.position.x;
}

function createCityscape() {
    cityscapeGroup = new THREE.Group();
    const cityColors =[0x2c3e50, 0x34495e, 0x1a252f];
    
    for(let i = 0; i < 40; i++) {
        const h = 10 + Math.random() * 30;
        const w = 4 + Math.random() * 8;
        const geo = new THREE.BoxGeometry(w, h, w);
        
        const tex = texBg.clone();
        tex.needsUpdate = true;
        tex.repeat.set(w/2, h/2);

        const mat = new THREE.MeshStandardMaterial({ 
            color: cityColors[Math.floor(Math.random() * cityColors.length)],
            roughness: 0.9,
            map: tex
        });const b = new THREE.Mesh(geo, mat);
        
        const angle = Math.random() * Math.PI * 2;
        const dist = 30 + Math.random() * 40;
        b.position.set(Math.cos(angle) * dist, h/2 - 5, Math.sin(angle) * dist);
        cityscapeGroup.add(b);
    }
    scene.add(cityscapeGroup);
}

function createImpactEffect(pos, intensity) {
    const count = 15;
    const geo = new THREE.SphereGeometry(0.2, 4, 4);
    const mat = new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.8 });

    for(let i = 0; i < count; i++) {
        const p = new THREE.Mesh(geo, mat.clone());
        p.position.copy(pos);
        p.position.y += 1.5;
        
        const vel = new THREE.Vector3(
            (Math.random() - 0.5) * 10 * intensity,
            Math.random() * 5 * intensity,
            (Math.random() - 0.5) * 10 * intensity
        );
        
        particles.push({ mesh: p, velocity: vel, life: 1.0 });
        scene.add(p);
    }
}

function updateParticles(delta) {
    for(let i = particles.length - 1; i >= 0; i--) {
        const p = particles[i];
        p.life -= delta * 2;
        p.mesh.position.add(p.velocity.clone().multiplyScalar(delta));
        p.mesh.material.opacity = p.life;
        p.mesh.scale.setScalar(p.life);
        
        if(p.life <= 0) {
            scene.remove(p.mesh);
            particles.splice(i, 1);
        }
    }
}

function triggerCameraShake(intensity) {
    const ui = document.getElementById('ui-layer');
    ui.classList.add('shake-ui');
    setTimeout(() => ui.classList.remove('shake-ui'), 400);

    const originalPos = camera.position.clone();
    new TWEEN.Tween({ t: 0 })
        .to({ t: 1 }, 200)
        .onUpdate((obj) => {
            const s = (1 - obj.t) * intensity;
            camera.position.x = originalPos.x + (Math.random() - 0.5) * s;
            camera.position.z = originalPos.z + (Math.random() - 0.5) * s;
        })
        .start();
}

// --- CRANE CABLE ---
function createCrane() {
    if (craneLine) scene.remove(craneLine);
    const material = new THREE.LineBasicMaterial({ color: 0x333333, linewidth: 2 });
    const geometry = new THREE.BufferGeometry().setFromPoints([
        new THREE.Vector3(0, 0, 0),
        new THREE.Vector3(0, -10, 0)
    ]);
    craneLine = new THREE.Line(geometry, material);
    scene.add(craneLine);
}

function updateCrane() {
    if (!craneLine || !activeBlock || state.mode !== 'swinging') return;
    const positions = craneLine.geometry.attributes.position.array;
    
    // Pivot point is high above the camera target
    const pivotY = state.cameraTargetY + 15;
    
    positions[0] = 0; // Pivot X
    positions[1] = pivotY; // Pivot Y
    positions[2] = 0; // Pivot Z
    
    positions[3] = activeBlock.position.x;
    positions[4] = activeBlock.position.y + (CONFIG.blockHeight/2);
    positions[5] = activeBlock.position.z;
    
    craneLine.geometry.attributes.position.needsUpdate = true;
}

// --- GAME LOGIC ---
function startGame(e) {
    if (e) e.stopPropagation(); // Prevent immediate drop
    
    // Reset State
    state.mode = 'swinging';
    state.score = 0;
    state.combo = 1;
    state.lives = 3;
    state.towerOffset = 0;
    state.swingTime = 0;
    state.cameraTargetY = CONFIG.cameraOffset.y;
    
    updateUI();
    startScreen.classList.remove('active');
    gameOverScreen.classList.remove('active');

    // Clear Scene
    placedBlocks.forEach(b => scene.remove(b.mesh));
    placedBlocks =[];
    if (activeBlock) scene.remove(activeBlock);

    // Create Base Block
    const baseColor = 0x95a5a6;
    const base = createBuildingBlock(baseColor);
    base.position.y = CONFIG.blockHeight / 2;
    scene.add(base);
    placedBlocks.push({ mesh: base, targetX: 0 });

    createCrane();
    spawnNextBlock();
}

function spawnNextBlock() {
    // Alternate colors to mimic the game
    const colors =[0x2ecc71, 0x3498db, 0xe74c3c, 0xf1c40f];
    const color = colors[(state.score) % colors.length];
    
    activeBlock = createBuildingBlock(color);
    
    // Calculate spawn height based on tower
    const topY = placedBlocks.length * CONFIG.blockHeight;
    activeBlock.position.y = topY + 10; // Hang above the tower
    activeBlock.position.z = 0;
    
    scene.add(activeBlock);
    state.mode = 'swinging';
    
    // Move Camera Up
    state.cameraTargetY = topY + CONFIG.cameraOffset.y;
}

function handleInput() {
    if (state.mode === 'swinging') {
        state.mode = 'dropping';
        if (craneLine) scene.remove(craneLine); // Detach cable
    }
}

function dropLogic(delta) {
    // Apply gravity
    activeBlock.position.y -= CONFIG.dropSpeed * delta;

    const topBlock = placedBlocks[placedBlocks.length - 1];
    const targetY = topBlock.mesh.position.y + CONFIG.blockHeight;

    // Collision Check
    if (activeBlock.position.y <= targetY) {
        activeBlock.position.y = targetY; // Snap to height
        
        // Calculate X offset
        const offset = activeBlock.position.x - topBlock.mesh.position.x;
        const absOffset = Math.abs(offset);

        if (absOffset < CONFIG.perfectTolerance) {
            // PERFECT DROP
            activeBlock.position.x = topBlock.mesh.position.x;
            state.combo++;
            state.score += (10 * state.combo);
            state.towerOffset *= 0.3; 
            
            flashFx.style.opacity = '1';
            setTimeout(() => flashFx.style.opacity = '0', 50);
            
            createImpactEffect(activeBlock.position, 0.5);
            triggerCameraShake(0.3);
            document.querySelector('.score-pill').classList.add('pulse');
            setTimeout(() => document.querySelector('.score-pill').classList.remove('pulse'), 200);

            addToTower();
        } 
        else if (absOffset < CONFIG.okTolerance) {
            // OK DROP
            state.combo = 1;
            state.score += 10;
            state.towerOffset += offset;
            
            createImpactEffect(activeBlock.position, 1.2);
            triggerCameraShake(0.8);

            addToTower();
        } 
        else {
            // MISS
            state.mode = 'missed';
            state.combo = 1;
            state.lives--;
            updateUI();
            
            // Let it fall off screen
            const fallTarget = { y: -10, rotZ: offset > 0 ? -Math.PI : Math.PI };
            new TWEEN.Tween(activeBlock.position)
                .to({ y: fallTarget.y }, 1000)
                .easing(TWEEN.Easing.Quadratic.In)
                .start();
                
            new TWEEN.Tween(activeBlock.rotation)
                .to({ z: fallTarget.rotZ }, 1000)
                .start()
                .onComplete(() => {
                    scene.remove(activeBlock);
                    if (state.lives <= 0) {
                        triggerGameOver();
                    } else {
                        spawnNextBlock();
                    }
                });
        }
    }
}

function addToTower() {
    placedBlocks.push({
        mesh: activeBlock,
        targetX: activeBlock.position.x // Store where it landed
    });
    
    updateUI();
    
    // Check if tower is too unstable
    if (Math.abs(state.towerOffset) > 4.0) {
        triggerGameOver();
    } else {
        createCrane();
        spawnNextBlock();
    }
}

// --- THE WOBBLE ENGINE ---
function updateTowerSway(time) {
    if (placedBlocks.length < 2) return;

    // The whole tower bends based on the cumulative offset and time
    const swayFactor = Math.sin(time * 2) * state.towerOffset * 0.1;

    for (let i = 1; i < placedBlocks.length; i++) {
        const block = placedBlocks[i];
        // Higher blocks sway more (quadratic curve for bending)
        const heightMultiplier = Math.pow(i / placedBlocks.length, 1.5);
        block.mesh.position.x = block.targetX + (swayFactor * heightMultiplier * i);
        
        // Slight tilt
        block.mesh.rotation.z = (swayFactor * heightMultiplier * 0.1);
    }
}

function triggerGameOver() {
    state.mode = 'gameover';
    finalScore.innerText = state.score;
    gameOverScreen.classList.add('active');
    
    // Optional: Collapse animation for the tower
    placedBlocks.forEach((block, index) => {
        if (index === 0) return; // leave base
        new TWEEN.Tween(block.mesh.position)
            .to({ 
                y: 0, 
                x: block.mesh.position.x + (Math.random() * 10 - 5) 
            }, 1000 + (Math.random() * 500))
            .easing(TWEEN.Easing.Bounce.Out)
            .delay(index * 50)
            .start();
            
        new TWEEN.Tween(block.mesh.rotation)
            .to({ 
                x: Math.random() * Math.PI,
                y: Math.random() * Math.PI,
                z: Math.random() * Math.PI
            }, 1000)
            .delay(index * 50)
            .start();
    });
}

function updateUI() {
    uiScore.innerText = state.score;
    
    if (state.combo > 1) {
        uiCombo.innerText = `Combo x${state.combo}`;
        uiCombo.classList.add('active');
        uiScore.style.color = '#ffd32a';
    } else {
        uiCombo.classList.remove('active');
        uiScore.style.color = 'white';
    }

    uiLives.innerHTML = '';
    for(let i=0; i<3; i++) {
        const box = document.createElement('div');
        box.className = `heart-box ${i >= state.lives ? 'lost' : ''}`;
        box.innerHTML = '❤️';
        uiLives.appendChild(box);
    }
}

// --- MAIN LOOP ---
const clock = new THREE.Clock();

function animate(time) {
    requestAnimationFrame(animate);
    TWEEN.update(time);

    const delta = clock.getDelta();
    const elapsedTime = clock.getElapsedTime();

    if (state.mode === 'swinging' && activeBlock) {
        state.swingTime += delta * CONFIG.swingSpeed;
        
        // Pendulum math
        const angle = Math.sin(state.swingTime);
        const topY = placedBlocks.length * CONFIG.blockHeight;
        
        // The block swings along an arc
        activeBlock.position.x = angle * CONFIG.swingAmplitude;
        activeBlock.rotation.z = angle * 0.1; // slight tilt while swinging
        
        updateCrane();
    } 
    else if (state.mode === 'dropping') {
        dropLogic(delta);
    }

    // Apply Tower Sway
    if (state.mode !== 'gameover') {
        updateTowerSway(elapsedTime);
    }

    // Parallax Cityscape (Moves slower than tower)
    if (cityscapeGroup) {
        cityscapeGroup.position.y = -camera.position.y * 0.4;
    }

    updateParticles(delta);

    // Smooth Camera Follow
    camera.position.y += (state.cameraTargetY - camera.position.y) * 0.05;
    camera.lookAt(0, camera.position.y - CONFIG.cameraOffset.y + 5, 0);

    renderer.render(scene, camera);
}

function onWindowResize() {
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight);
}

// Boot
init();