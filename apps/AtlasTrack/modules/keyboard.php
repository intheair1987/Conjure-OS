<?php
/**
 * AtlasTrack Custom Numpad Module
 * Provides a specialized gym-friendly keyboard with integrated fill tools.
 */
?>
<div id="at-numpad" class="numpad-container">
    <div id="kb-calc-preview" class="numpad-preview"></div>
    
    <!-- Row 1 -->
    <button class="numpad-key" onclick="AtlasTrack.kbPress('1')">1</button>
    <button class="numpad-key" onclick="AtlasTrack.kbPress('2')">2</button>
    <button class="numpad-key" onclick="AtlasTrack.kbPress('3')">3</button>
    <button class="numpad-key" style="color:var(--warn);" onclick="AtlasTrack.kbPress('+')"><i data-lucide="plus" style="width:24px;"></i></button>
    
    <!-- Row 2 -->
    <button class="numpad-key" onclick="AtlasTrack.kbPress('4')">4</button>
    <button class="numpad-key" onclick="AtlasTrack.kbPress('5')">5</button>
    <button class="numpad-key" onclick="AtlasTrack.kbPress('6')">6</button>
    <button class="numpad-key" style="color:var(--warn);" onclick="AtlasTrack.kbPress('-')"><i data-lucide="minus" style="width:24px;"></i></button>
    
    <!-- Row 3 -->
    <button class="numpad-key" onclick="AtlasTrack.kbPress('7')">7</button>
    <button class="numpad-key" onclick="AtlasTrack.kbPress('8')">8</button>
    <button class="numpad-key" onclick="AtlasTrack.kbPress('9')">9</button>
    <button class="numpad-key" style="color:var(--primary-accent);" onclick="AtlasTrack.kbFill('up')"><i data-lucide="arrow-up" style="width:22px;"></i></button>
    
    <!-- Row 4 -->
    <button class="numpad-key" onclick="AtlasTrack.kbPress('.')">.</button>
    <button class="numpad-key" onclick="AtlasTrack.kbPress('0')">0</button>
    <button class="numpad-key" onclick="AtlasTrack.kbPress('backspace')"><i data-lucide="delete" style="width:24px;"></i></button>
    <button class="numpad-key" style="color:var(--primary-accent);" onclick="AtlasTrack.kbFill('down')"><i data-lucide="arrow-down" style="width:22px;"></i></button>
    
    <!-- Row 5 -->
    <button class="numpad-key key-done" style="grid-column: span 3; height: 60px;" onclick="AtlasTrack.closeKeyboard()">DONE</button>
    <button class="numpad-key" style="color:var(--primary-accent); font-size: 12px; font-weight: 900;" onclick="AtlasTrack.kbFill('all')">ALL</button>
</div>

<script>
Object.assign(AtlasTrack, {
    activeInput: null,

    openKeyboard(input) {
        this.haptic();
        document.activeElement.blur();
        
        if (this.activeInput) {
            this.activeInput.classList.remove('custom-focused');
            this.activeInput.classList.remove('custom-selected');
        }
        
        this.activeInput = input;
        this.activeInput.classList.add('custom-focused');
        this.activeInput.classList.add('custom-selected');
        this.state.kbIsFirstPress = true;
        
        // Reset Calculator Mode
        this.state.kbCalcActive = false;
        this.state.kbCalcOp = null;
        this.state.kbCalcBase = 0;
        this.state.kbCalcOperand = "";
        this.updateCalcPreview();
        
        document.body.classList.add('kb-active');
        document.getElementById('at-numpad').classList.add('active');
        document.getElementById('main-nav').style.transform = 'translateY(100%)';

        setTimeout(() => {
            this.activeInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 50);
    },

    closeKeyboard() {
        if (this.activeInput) {
            this.activeInput.classList.remove('custom-focused');
            this.activeInput.classList.remove('custom-selected');
        }
        this.activeInput = null;
        this.state.kbIsAdding = false;
        document.body.classList.remove('kb-active');
        document.getElementById('at-numpad').classList.remove('active');
        // Clear inline style to allow CSS class-based transforms (like the timer push) to work
        document.getElementById('main-nav').style.transform = '';
    },

    kbPress(key) {
        if (!this.activeInput) return;
        this.haptic();

        // --- CALCULATOR MODE TRIGGER ---
        if (key === '+' || key === '-') {
            this.state.kbCalcActive = true;
            this.state.kbCalcOp = key;
            this.state.kbCalcBase = parseFloat(this.activeInput.value) || 0;
            this.state.kbCalcOperand = "";
            this.state.kbIsFirstPress = false;
            this.activeInput.classList.remove('custom-selected');
            this.updateCalcPreview();
            return;
        }
        
        let val = this.activeInput.value;

        // --- CALCULATOR MODE LOGIC ---
        if (this.state.kbCalcActive) {
            if (key === 'backspace') {
                this.state.kbCalcOperand = this.state.kbCalcOperand.slice(0, -1);
            } else {
                if (key === '.' && this.state.kbCalcOperand.includes('.')) return;
                this.state.kbCalcOperand += key;
            }
            
            const opVal = parseFloat(this.state.kbCalcOperand) || 0;
            if (this.state.kbCalcOp === '+') {
                this.activeInput.value = this.state.kbCalcBase + opVal;
            } else {
                this.activeInput.value = Math.max(0, this.state.kbCalcBase - opVal);
            }
            
            // Trigger real-time calculation in calculator mode
            if (this.activeInput.classList.contains('weight-input')) {
                const card = this.activeInput.closest('.ex-card');
                if (card) this.updateCalculatedWeights(card.id);
            }

            this.updateCalcPreview();
            this.calculateVolume();
            return;
        }
        
        // --- STANDARD MODE LOGIC ---
        if (this.state.kbIsFirstPress && key !== 'backspace') {
            val = "";
            this.state.kbIsFirstPress = false;
            this.activeInput.classList.remove('custom-selected');
        }

        if (key === 'backspace') {
            val = val.slice(0, -1);
            this.state.kbIsFirstPress = false;
            this.activeInput.classList.remove('custom-selected');
        } else {
            if (key === '.' && val.includes('.')) return;
            if (val === "0") val = "";
            val += key;
        }
        
        this.activeInput.value = val;
        
        // Trigger real-time calculation if editing weight
        if (this.activeInput.classList.contains('weight-input')) {
            const card = this.activeInput.closest('.ex-card');
            if (card) this.updateCalculatedWeights(card.id);
        }

        this.calculateVolume();
        this.syncActiveState();
    },

    updateCalcPreview() {
        const el = document.getElementById('kb-calc-preview');
        if (this.state.kbCalcActive) {
            el.innerText = `${this.state.kbCalcBase} ${this.state.kbCalcOp} ${this.state.kbCalcOperand}`;
            el.classList.add('active');
        } else {
            el.innerText = "";
            el.classList.remove('active');
        }
    },

    kbFill(dir) {
        if (!this.activeInput) return;
        this.executeFill(this.activeInput, dir);
    }
});
</script>

    kbFill(dir) {
        if (!this.activeInput) return;
        this.executeFill(this.activeInput, dir);
    }
});
</script>