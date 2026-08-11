# Whiteboard Plugin Protocol

This document defines the Orchestrated Architecture of the Whiteboard app. Use these patterns to add new features without bloating the core files.

## 1. PHP Backend (The Orchestrator)
The `index.php` file acts as a central router. Logic should be placed in `modules/*.php`.

### Registering an API Action
Do not add `if/else` blocks to `index.php`. Instead, use `wb_register_api`:

```php
// modules/my_feature.php
wb_register_api('my_action_name', function($db) {
    $data = $_POST['data'];
    // Perform logic...
    echo json_encode(['status' => 'success']);
});
```

## 2. JS Frontend (The Plugin Engine)
The global `window.wb` object is initialized in the document `<head>` and is available to all scripts.

### Hook System
Plugins should listen for lifecycle events instead of modifying `core-engine.js`.

| Hook | Arguments | Description |
| :--- | :--- | :--- |
| `onRenderViewport` | `vp, index, activeIndex` | Fired for each viewport during the render loop. Use to position UI elements (like text editors) relative to the canvas. |
| `onRenderEnd` | none | Fired once at the end of the render cycle. Use to update global UI (Undo/Redo buttons, Zoom indicators). |

**Example Usage:**
```javascript
window.wb.on('onRenderEnd', () => {
    console.log("Frame rendered!");
});
```

### Core State Access
The following globals are managed by the Core Engine and are safe to read/modify:
- `allStrokes`: The master array of drawing objects.
- `viewports`: Array of active canvas viewports.
- `touchMode`: The currently active tool string.
- `brushColor` / `brushWidth`: Current drawing settings.

## 3. File Size Mandate
To maintain AI-patching reliability, no single JavaScript module should exceed **50KB**. If a feature grows beyond this, it must be split into a logic provider and a UI provider.