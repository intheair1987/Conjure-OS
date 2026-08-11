package com.conjure.keymapper;

import android.accessibilityservice.AccessibilityService;
import android.accessibilityservice.GestureDescription;
import android.content.BroadcastReceiver;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.SharedPreferences;
import android.graphics.Path;
import android.hardware.camera2.CameraManager;
import android.media.AudioManager;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.KeyEvent;
import android.view.accessibility.AccessibilityEvent;
import android.view.accessibility.AccessibilityNodeInfo;
import java.util.HashSet;
import java.util.Set;
import org.json.JSONArray;
import org.json.JSONObject;

public class KeyAccessibilityService extends AccessibilityService {
    public static final int GLOBAL_ACTION_PASTE = 1000;
    private static KeyAccessibilityService instance = null;
    private String currentActivePackage = "";

    public static CharSequence getClipboardText(Context context) {
        if (context == null) return null;
        try {
            ClipboardManager cm = (ClipboardManager) context.getSystemService(Context.CLIPBOARD_SERVICE);
            if (cm != null && cm.hasPrimaryClip()) {
                ClipData clip = cm.getPrimaryClip();
                if (clip != null && clip.getItemCount() > 0) {
                    return clip.getItemAt(0).coerceToText(context);
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return null;
    }

    public static void dispatchTapAndPaste(final float x, final float y, final String textToPaste) {
        new Handler(Looper.getMainLooper()).post(new Runnable() {
            @Override
            public void run() {
                if (instance == null || Build.VERSION.SDK_INT < Build.VERSION_CODES.N) {
                    boolean success = performPasteAction();
                    if (success) {
                        CaretOverlayManager.updateStatusPill("✨ Text pasted successfully!");
                        CaretOverlayManager.dismissStatusPillDelayed(1000);
                    } else {
                        CaretOverlayManager.updateStatusPill("📋 Copied to clipboard!");
                        CaretOverlayManager.dismissStatusPillDelayed(2000);
                    }
                    return;
                }

                try {
                    Path path = new Path();
                    path.moveTo(x, y);
                    GestureDescription.StrokeDescription stroke = new GestureDescription.StrokeDescription(path, 0, 50);
                    GestureDescription.Builder builder = new GestureDescription.Builder();
                    builder.addStroke(stroke);

                    boolean queued = instance.dispatchGesture(builder.build(), new AccessibilityService.GestureResultCallback() {
                        @Override
                        public void onCompleted(GestureDescription gestureDescription) {
                            super.onCompleted(gestureDescription);
                            CaretOverlayManager.updateStatusPill("📋 Injecting transcription text...");

                            new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
                                @Override
                                public void run() {
                                    if (textToPaste != null && !textToPaste.isEmpty() && instance != null) {
                                        try {
                                            ClipboardManager clipboard = (ClipboardManager) instance.getSystemService(Context.CLIPBOARD_SERVICE);
                                            if (clipboard != null) {
                                                ClipData clip = ClipData.newPlainText("Conjure Caret Drop", textToPaste);
                                                clipboard.setPrimaryClip(clip);
                                            }
                                        } catch (Exception ignored) {}
                                    }
                                    boolean pasted = performPasteAction();
                                    if (pasted) {
                                        CaretOverlayManager.updateStatusPill("✨ Text pasted successfully!");
                                        CaretOverlayManager.dismissStatusPillDelayed(1000);
                                    } else {
                                        CaretOverlayManager.updateStatusPill("📋 Copied to clipboard! (Tap field to paste)");
                                        CaretOverlayManager.dismissStatusPillDelayed(2200);
                                    }

                                    if (instance != null) {
                                        ActionExecutor.vibrateDevice(instance, 80);
                                        ActionExecutor.playSoundChime("warm_bloom");
                                    }
                                }
                            }, 150);
                        }
                    }, null);

                    if (!queued) {
                        boolean pasted = performPasteAction();
                        if (pasted) {
                            CaretOverlayManager.updateStatusPill("✨ Text pasted successfully!");
                            CaretOverlayManager.dismissStatusPillDelayed(1000);
                        } else {
                            CaretOverlayManager.updateStatusPill("📋 Copied to clipboard!");
                            CaretOverlayManager.dismissStatusPillDelayed(2000);
                        }
                    }
                } catch (Exception e) {
                    e.printStackTrace();
                    performPasteAction();
                    CaretOverlayManager.updateStatusPill("📋 Copied to clipboard!");
                    CaretOverlayManager.dismissStatusPillDelayed(2000);
                }
            }
        });
    }

    public static boolean performPasteAction() {
        if (instance == null) return false;

        try {
            AccessibilityNodeInfo root = instance.getRootInActiveWindow();
            if (root == null) return false;

            java.util.List<AccessibilityNodeInfo> candidates = new java.util.ArrayList<>();

            AccessibilityNodeInfo inputFocus = root.findFocus(AccessibilityNodeInfo.FOCUS_INPUT);
            if (inputFocus != null) candidates.add(inputFocus);

            AccessibilityNodeInfo accFocus = root.findFocus(AccessibilityNodeInfo.FOCUS_ACCESSIBILITY);
            if (accFocus != null && !candidates.contains(accFocus)) candidates.add(accFocus);

            collectEditableAndFocusedNodes(root, candidates);

            if (candidates.isEmpty()) {
                candidates.add(root);
            }

            CharSequence clipText = getClipboardText(instance);

            for (AccessibilityNodeInfo node : candidates) {
                if (node == null) continue;

                try {
                    node.performAction(AccessibilityNodeInfo.ACTION_FOCUS);
                    node.performAction(AccessibilityNodeInfo.ACTION_ACCESSIBILITY_FOCUS);
                } catch (Exception ignored) {}

                // Tier A: Direct ACTION_PASTE
                if (node.performAction(AccessibilityNodeInfo.ACTION_PASTE)) {
                    return true;
                }

                // Tier B: ACTION_SET_TEXT (Crucial for WebViews and browser text boxes)
                if (clipText != null && clipText.length() > 0) {
                    if (trySetTextOnNode(node, clipText)) {
                        return true;
                    }
                }

                // Tier C: Traverse parent tree up to 3 levels (e.g., WebView input wrappers)
                AccessibilityNodeInfo parent = node.getParent();
                int depth = 0;
                while (parent != null && depth < 3) {
                    if (parent.performAction(AccessibilityNodeInfo.ACTION_PASTE)) {
                        return true;
                    }
                    if (clipText != null && clipText.length() > 0) {
                        if (trySetTextOnNode(parent, clipText)) {
                            return true;
                        }
                    }
                    parent = parent.getParent();
                    depth++;
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return false;
    }

    private static boolean trySetTextOnNode(AccessibilityNodeInfo node, CharSequence clipText) {
        if (node == null || clipText == null) return false;
        try {
            Bundle args = new Bundle();
            CharSequence existingText = node.getText();
            CharSequence newText;

            if (existingText != null && existingText.length() > 0) {
                newText = existingText.toString() + clipText.toString();
            } else {
                newText = clipText;
            }

            args.putCharSequence(AccessibilityNodeInfo.ACTION_ARGUMENT_SET_TEXT_CHARSEQUENCE, newText);
            return node.performAction(AccessibilityNodeInfo.ACTION_SET_TEXT, args);
        } catch (Exception e) {
            return false;
        }
    }

    private static void collectEditableAndFocusedNodes(AccessibilityNodeInfo node, java.util.List<AccessibilityNodeInfo> list) {
        if (node == null) return;
        try {
            if ((node.isEditable() || node.isFocused()) && !list.contains(node)) {
                list.add(node);
            }
            CharSequence cls = node.getClassName();
            if (cls != null) {
                String clsStr = cls.toString().toLowerCase();
                if ((clsStr.contains("edit") || clsStr.contains("input") || clsStr.contains("webview") || clsStr.contains("text")) && !list.contains(node)) {
                    list.add(node);
                }
            }
            for (int i = 0; i < node.getChildCount(); i++) {
                AccessibilityNodeInfo child = node.getChild(i);
                if (child != null) {
                    collectEditableAndFocusedNodes(child, list);
                }
            }
        } catch (Exception ignored) {}
    }

    public boolean performActionOrGlobal(int action) {
        if (action == GLOBAL_ACTION_PASTE) {
            return performPasteAction();
        }
        return performGlobalAction(action);
    }

    private long lastKeyPressTime = 0;
    private int lastKeyCode = -1;
    private int consecutivePressCount = 0;

    private final Set<Integer> currentlyPressedKeys = new HashSet<>();
    private final Handler mHandler = new Handler(Looper.getMainLooper());
    private Runnable mPendingLongPressRunnable = null;
    private boolean longPressExecuted = false;
    private boolean comboExecuted = false;

    public static boolean isServiceRunning() {
        return instance != null;
    }

    private Object mTorchCallback = null;
    private BroadcastReceiver mSystemStateReceiver = null;

    @Override
    protected void onServiceConnected() {
        super.onServiceConnected();
        instance = this;
        ConditionEvaluator.initSensors(this);
        registerTorchCallback();
        registerSystemBroadcastReceivers();
    }

    @Override
    public void onDestroy() {
        super.onDestroy();
        if (mHandler != null && mPendingLongPressRunnable != null) {
            mHandler.removeCallbacks(mPendingLongPressRunnable);
        }
        unregisterTorchAndStateListeners();
        instance = null;
    }

    private void registerTorchCallback() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            try {
                final CameraManager cm = (CameraManager) getSystemService(Context.CAMERA_SERVICE);
                if (cm != null) {
                    CameraManager.TorchCallback cb = new CameraManager.TorchCallback() {
                        @Override
                        public void onTorchModeChanged(String cameraId, boolean enabled) {
                            super.onTorchModeChanged(cameraId, enabled);
                            evaluateStateRules(enabled ? "flashlight_on" : "flashlight_off");
                        }
                    };
                    mTorchCallback = cb;
                    cm.registerTorchCallback(cb, mHandler);
                }
            } catch (Exception ignored) {}
        }
    }

    private void registerSystemBroadcastReceivers() {
        try {
            mSystemStateReceiver = new BroadcastReceiver() {
                @Override
                public void onReceive(Context context, Intent intent) {
                    if (intent == null) return;
                    String action = intent.getAction();
                    if (Intent.ACTION_SCREEN_OFF.equals(action)) {
                        evaluateStateRules("screen_off");
                    } else if (Intent.ACTION_SCREEN_ON.equals(action)) {
                        evaluateStateRules("screen_on");
                    } else if (AudioManager.RINGER_MODE_CHANGED_ACTION.equals(action)) {
                        AudioManager am = (AudioManager) getSystemService(Context.AUDIO_SERVICE);
                        if (am != null) {
                            int mode = am.getRingerMode();
                            if (mode == AudioManager.RINGER_MODE_SILENT) evaluateStateRules("ringer_silent");
                            else if (mode == AudioManager.RINGER_MODE_VIBRATE) evaluateStateRules("ringer_vibrate");
                            else if (mode == AudioManager.RINGER_MODE_NORMAL) evaluateStateRules("ringer_normal");
                        }
                    }
                }
            };
            IntentFilter filter = new IntentFilter();
            filter.addAction(Intent.ACTION_SCREEN_OFF);
            filter.addAction(Intent.ACTION_SCREEN_ON);
            filter.addAction(AudioManager.RINGER_MODE_CHANGED_ACTION);
            registerReceiver(mSystemStateReceiver, filter);
        } catch (Exception ignored) {}
    }

    private void unregisterTorchAndStateListeners() {
        try {
            if (mTorchCallback != null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                CameraManager cm = (CameraManager) getSystemService(Context.CAMERA_SERVICE);
                if (cm != null) {
                    cm.unregisterTorchCallback((CameraManager.TorchCallback) mTorchCallback);
                }
            }
            if (mSystemStateReceiver != null) {
                unregisterReceiver(mSystemStateReceiver);
            }
        } catch (Exception ignored) {}
    }

    private long lastStateTriggerTime = 0;
    private String lastExecutedStateEvent = "";

    private static JSONArray getRuleTriggerSets(JSONObject rule) {
        if (rule == null) return new JSONArray();
        JSONArray sets = rule.optJSONArray("triggerSets");
        if (sets != null && sets.length() > 0) {
            return sets;
        }

        JSONArray fallbackList = new JSONArray();
        try {
            JSONObject legacySet = new JSONObject();
            legacySet.put("id", "legacy-set");
            legacySet.put("triggerType", rule.optString("triggerType", "button"));
            legacySet.put("keyCode", rule.optString("keyCode", ""));
            legacySet.put("keyLabel", rule.optString("keyLabel", ""));
            legacySet.put("secondaryKeyCode", rule.optString("secondaryKeyCode", ""));
            legacySet.put("secondaryKeyLabel", rule.optString("secondaryKeyLabel", ""));
            legacySet.put("pressType", rule.optString("pressType", "single"));
            legacySet.put("stateEvent", rule.optString("stateEvent", ""));
            legacySet.put("conditions", rule.optJSONArray("conditions"));
            legacySet.put("enabled", rule.optBoolean("enabled", true));
            fallbackList.put(legacySet);
        } catch (Exception e) {
            e.printStackTrace();
        }
        return fallbackList;
    }

    private static void setMatchedSetId(JSONObject rule, JSONObject set) {
        if (rule != null && set != null) {
            try {
                rule.put("_matchedSetId", set.optString("id", ""));
            } catch (Exception ignored) {}
        }
    }

    public void evaluateStateRules(String stateEvent) {
        if ("flashlight_on".equalsIgnoreCase(stateEvent)) {
            ActionExecutor.setTorchState(true);
        } else if ("flashlight_off".equalsIgnoreCase(stateEvent)) {
            ActionExecutor.setTorchState(false);
        }

        SharedPreferences prefs = getSharedPreferences("KeyMapperPrefs", Context.MODE_PRIVATE);
        if (!prefs.getBoolean("master_enabled", true)) {
            return;
        }

        long now = System.currentTimeMillis();
        if (stateEvent.equalsIgnoreCase(lastExecutedStateEvent) && (now - lastStateTriggerTime) < 200) {
            return;
        }
        lastStateTriggerTime = now;
        lastExecutedStateEvent = stateEvent;

        String mappingsJson = WebBridge.readMappingsJson(this);

        try {
            JSONArray array = new JSONArray(mappingsJson);
            for (int i = 0; i < array.length(); i++) {
                JSONObject rule = array.getJSONObject(i);
                if (!rule.optBoolean("enabled", true)) continue;

                JSONArray triggerSets = getRuleTriggerSets(rule);
                boolean ruleMatched = false;

                for (int j = 0; j < triggerSets.length(); j++) {
                    JSONObject set = triggerSets.getJSONObject(j);
                    if (!set.optBoolean("enabled", true)) continue;

                    String triggerType = set.optString("triggerType", "button");
                    if (!"state".equalsIgnoreCase(triggerType)) continue;

                    String targetStateEvent = set.optString("stateEvent", "");
                    if (!stateEvent.equalsIgnoreCase(targetStateEvent)) continue;

                    JSONArray conditions = set.optJSONArray("conditions");
                    if (ConditionEvaluator.evaluateAll(this, conditions, currentActivePackage)) {
                        ruleMatched = true;
                        setMatchedSetId(rule, set);
                        break;
                    }
                }

                if (ruleMatched) {
                    executeRuleActions(rule);
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    @Override
    public void onAccessibilityEvent(AccessibilityEvent event) {
        if (event.getEventType() == AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED) {
            CharSequence pkgName = event.getPackageName();
            if (pkgName != null) {
                currentActivePackage = pkgName.toString();
            }
        }
    }

    @Override
    public void onInterrupt() {}

    @Override
    protected boolean onKeyEvent(KeyEvent event) {
        if (WebBridge.isRecordingMode()) {
            return super.onKeyEvent(event);
        }

        SharedPreferences prefs = getSharedPreferences("KeyMapperPrefs", Context.MODE_PRIVATE);
        if (!prefs.getBoolean("master_enabled", true)) {
            return super.onKeyEvent(event);
        }

        int action = event.getAction();
        final int keyCode = event.getKeyCode();

        if (action == KeyEvent.ACTION_UP) {
            currentlyPressedKeys.remove(keyCode);

            boolean hadPendingLongPress = (mPendingLongPressRunnable != null);

            // Cancel any pending long-press timer if key is released early
            if (mPendingLongPressRunnable != null) {
                mHandler.removeCallbacks(mPendingLongPressRunnable);
                mPendingLongPressRunnable = null;
            }

            if (comboExecuted) {
                if (currentlyPressedKeys.isEmpty()) {
                    comboExecuted = false;
                }
                return true;
            }

            if (longPressExecuted) {
                longPressExecuted = false;
                return true;
            }

            // Only evaluate single/double press on ACTION_UP if we were holding back for a long-press timer
            if (hadPendingLongPress) {
                return evaluateSingleOrDoubleRule(keyCode);
            }
            return super.onKeyEvent(event);
        }

        if (action != KeyEvent.ACTION_DOWN) {
            return super.onKeyEvent(event);
        }

        currentlyPressedKeys.add(keyCode);

        long now = System.currentTimeMillis();
        if (keyCode == lastKeyCode && (now - lastKeyPressTime) < 400) {
            consecutivePressCount++;
        } else {
            consecutivePressCount = 1;
            lastKeyCode = keyCode;
        }
        lastKeyPressTime = now;

        // 1. HIGHEST PRIORITY: Evaluate Multi-Key Combinations
        JSONObject matchingComboRule = findMatchingComboRule(keyCode);
        if (matchingComboRule != null) {
            if (mPendingLongPressRunnable != null) {
                mHandler.removeCallbacks(mPendingLongPressRunnable);
                mPendingLongPressRunnable = null;
            }
            comboExecuted = true;
            executeRuleActions(matchingComboRule);
            return true; // Consume combo event!
        }

        // Cancel previous long press timer if a new key arrives
        if (mPendingLongPressRunnable != null) {
            mHandler.removeCallbacks(mPendingLongPressRunnable);
            mPendingLongPressRunnable = null;
        }
        longPressExecuted = false;

        // 2. Check if a Long Press rule is configured for this key
        final JSONObject longPressRule = findMatchingRule(keyCode, "long");
        if (longPressRule != null) {
            mPendingLongPressRunnable = new Runnable() {
                @Override
                public void run() {
                    longPressExecuted = true;
                    executeRuleActions(longPressRule);
                }
            };
            mHandler.postDelayed(mPendingLongPressRunnable, 400); // 400ms hold time
            return true; // Hold event to allow long press evaluation
        }

        // 3. Evaluate immediate Single or Double press rules if NO long press rule is pending
        return evaluateSingleOrDoubleRule(keyCode);
    }

    private JSONObject findMatchingComboRule(int currentKeyCode) {
        String mappingsJson = WebBridge.readMappingsJson(this);
        try {
            JSONArray array = new JSONArray(mappingsJson);
            for (int i = 0; i < array.length(); i++) {
                JSONObject rule = array.getJSONObject(i);
                if (!rule.optBoolean("enabled", true)) continue;

                JSONArray triggerSets = getRuleTriggerSets(rule);
                for (int j = 0; j < triggerSets.length(); j++) {
                    JSONObject set = triggerSets.getJSONObject(j);
                    if (!set.optBoolean("enabled", true)) continue;

                    String targetKeyCode = set.optString("keyCode", "");
                    String targetSecondaryKeyCode = set.optString("secondaryKeyCode", "");
                    String pressType = set.optString("pressType", "single");

                    if ("combo".equalsIgnoreCase(pressType) || (!targetSecondaryKeyCode.isEmpty() && !"0".equals(targetSecondaryKeyCode))) {
                        boolean primaryInSet = isKeyMatchInSet(currentlyPressedKeys, targetKeyCode);
                        boolean secondaryInSet = isKeyMatchInSet(currentlyPressedKeys, targetSecondaryKeyCode);

                        if (primaryInSet && secondaryInSet) {
                            JSONArray conditions = set.optJSONArray("conditions");
                            if (ConditionEvaluator.evaluateAll(this, conditions, currentActivePackage)) {
                                setMatchedSetId(rule, set);
                                return rule;
                            }
                        }
                    }
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return null;
    }

    private JSONObject findMatchingRule(int keyCode, String targetPressType) {
        String mappingsJson = WebBridge.readMappingsJson(this);
        try {
            JSONArray array = new JSONArray(mappingsJson);
            for (int i = 0; i < array.length(); i++) {
                JSONObject rule = array.getJSONObject(i);
                if (!rule.optBoolean("enabled", true)) continue;

                JSONArray triggerSets = getRuleTriggerSets(rule);
                for (int j = 0; j < triggerSets.length(); j++) {
                    JSONObject set = triggerSets.getJSONObject(j);
                    if (!set.optBoolean("enabled", true)) continue;

                    String targetKeyCode = set.optString("keyCode", "");
                    String pressType = set.optString("pressType", "single");

                    if (isKeyMatch(keyCode, targetKeyCode) && targetPressType.equalsIgnoreCase(pressType)) {
                        JSONArray conditions = set.optJSONArray("conditions");
                        if (ConditionEvaluator.evaluateAll(this, conditions, currentActivePackage)) {
                            setMatchedSetId(rule, set);
                            return rule;
                        }
                    }
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return null;
    }

    private boolean evaluateSingleOrDoubleRule(int keyCode) {
        String mappingsJson = WebBridge.readMappingsJson(this);

        try {
            JSONArray array = new JSONArray(mappingsJson);
            for (int i = 0; i < array.length(); i++) {
                JSONObject rule = array.getJSONObject(i);
                if (!rule.optBoolean("enabled", true)) continue;

                JSONArray triggerSets = getRuleTriggerSets(rule);
                for (int j = 0; j < triggerSets.length(); j++) {
                    JSONObject set = triggerSets.getJSONObject(j);
                    if (!set.optBoolean("enabled", true)) continue;

                    String targetKeyCode = set.optString("keyCode", "");
                    String targetSecondaryKeyCode = set.optString("secondaryKeyCode", "");
                    String pressType = set.optString("pressType", "single");

                    if ("combo".equalsIgnoreCase(pressType) || (!targetSecondaryKeyCode.isEmpty() && !"0".equals(targetSecondaryKeyCode))) {
                        continue;
                    }

                    if ("long".equalsIgnoreCase(pressType)) {
                        continue;
                    }

                    if (!isKeyMatch(keyCode, targetKeyCode)) {
                        continue;
                    }

                    if ("double".equalsIgnoreCase(pressType)) {
                        if (consecutivePressCount < 2) {
                            continue;
                        }
                    }

                    JSONArray conditions = set.optJSONArray("conditions");
                    if (ConditionEvaluator.evaluateAll(this, conditions, currentActivePackage)) {
                        setMatchedSetId(rule, set);
                        executeRuleActions(rule);
                        return true;
                    }
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }

        return false;
    }

    private void executeRuleActions(JSONObject rule) {
        try {
            JSONArray actions = rule.optJSONArray("actions");
            if (actions != null && actions.length() > 0) {
                String matchedSetId = rule.optString("_matchedSetId", "");
                ActionExecutor.executeActionsSequence(this, actions, matchedSetId);
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    private boolean isKeyMatchInSet(Set<Integer> keySet, String ruleKeyCode) {
        if (ruleKeyCode == null || ruleKeyCode.isEmpty()) return false;
        for (int k : keySet) {
            if (isKeyMatch(k, ruleKeyCode)) return true;
        }
        return false;
    }

    private boolean isKeyMatch(int eventKeyCode, String ruleKeyCode) {
        if (ruleKeyCode == null || ruleKeyCode.isEmpty()) return false;
        try {
            int targetInt = Integer.parseInt(ruleKeyCode);
            if (eventKeyCode == targetInt) return true;
        } catch (NumberFormatException ignored) {}

        if (ruleKeyCode.startsWith("KEYCODE_")) {
            String expected = "KEYCODE_" + eventKeyCode;
            if (expected.equalsIgnoreCase(ruleKeyCode)) return true;
        }

        if ("Volume Down".equalsIgnoreCase(ruleKeyCode) && eventKeyCode == KeyEvent.KEYCODE_VOLUME_DOWN) return true;
        if ("Volume Up".equalsIgnoreCase(ruleKeyCode) && eventKeyCode == KeyEvent.KEYCODE_VOLUME_UP) return true;

        return false;
    }
}