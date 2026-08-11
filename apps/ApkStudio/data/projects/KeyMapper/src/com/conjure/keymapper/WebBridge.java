package com.conjure.keymapper;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.provider.Settings;
import android.webkit.JavascriptInterface;
import java.io.File;

public class WebBridge {
    private final Activity activity;
    private static boolean recordingMode = false;

    public WebBridge(Activity activity) {
        this.activity = activity;
    }

    public static boolean isRecordingMode() {
        return recordingMode;
    }

    public static void setRecordingMode(boolean recording) {
        recordingMode = recording;
    }

    @JavascriptInterface
    public void startKeyRecording() {
        recordingMode = true;
    }

    @JavascriptInterface
    public void stopKeyRecording() {
        recordingMode = false;
    }

    private static File getCentralConfigDir() {
        File configDir = new File(android.os.Environment.getExternalStorageDirectory(), "Conjure_Config");
        if (!configDir.exists()) {
            configDir.mkdirs();
        }
        return configDir;
    }

    public static String readMappingsJson(Context context) {
        try {
            File configDir = getCentralConfigDir();
            File rulesFile = new File(configDir, "keymapper_rules.json");

            if (rulesFile.exists() && rulesFile.length() > 0) {
                java.io.FileInputStream fis = new java.io.FileInputStream(rulesFile);
                java.io.ByteArrayOutputStream baos = new java.io.ByteArrayOutputStream();
                byte[] buf = new byte[1024];
                int len;
                while ((len = fis.read(buf)) != -1) baos.write(buf, 0, len);
                fis.close();
                String fileContent = baos.toString("UTF-8");

                if (fileContent != null && !fileContent.trim().isEmpty()) {
                    if (context != null) {
                        SharedPreferences prefs = context.getSharedPreferences("KeyMapperPrefs", Context.MODE_PRIVATE);
                        prefs.edit().putString("mappings_json", fileContent).apply();
                    }
                    return fileContent;
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }

        if (context != null) {
            SharedPreferences prefs = context.getSharedPreferences("KeyMapperPrefs", Context.MODE_PRIVATE);
            String prefContent = prefs.getString("mappings_json", "[]");
            if (prefContent != null && !prefContent.equals("[]")) {
                writeMappingsJsonFile(prefContent);
            }
            return prefContent;
        }
        return "[]";
    }

    public static void writeMappingsJsonFile(String jsonPayload) {
        try {
            File configDir = getCentralConfigDir();
            File rulesFile = new File(configDir, "keymapper_rules.json");
            java.io.FileWriter writer = new java.io.FileWriter(rulesFile, false);
            writer.write(jsonPayload != null ? jsonPayload : "[]");
            writer.flush();
            writer.close();
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    public static String readSettingsJson(Context context) {
        try {
            File configDir = getCentralConfigDir();
            File settingsFile = new File(configDir, "keymapper_settings.json");

            if (settingsFile.exists() && settingsFile.length() > 0) {
                java.io.FileInputStream fis = new java.io.FileInputStream(settingsFile);
                java.io.ByteArrayOutputStream baos = new java.io.ByteArrayOutputStream();
                byte[] buf = new byte[1024];
                int len;
                while ((len = fis.read(buf)) != -1) baos.write(buf, 0, len);
                fis.close();
                String fileContent = baos.toString("UTF-8");
                if (fileContent != null && !fileContent.trim().isEmpty()) {
                    return fileContent;
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return "{}";
    }

    public static void writeSettingsJsonFile(String jsonPayload) {
        try {
            File configDir = getCentralConfigDir();
            File settingsFile = new File(configDir, "keymapper_settings.json");
            java.io.FileWriter writer = new java.io.FileWriter(settingsFile, false);
            writer.write(jsonPayload != null ? jsonPayload : "{}");
            writer.flush();
            writer.close();
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    public static String readRuntimeActiveJson() {
        try {
            File configDir = getCentralConfigDir();
            File manifestFile = new File(configDir, "runtime_active.json");
            if (manifestFile.exists() && manifestFile.length() > 0) {
                java.io.FileInputStream fis = new java.io.FileInputStream(manifestFile);
                java.io.ByteArrayOutputStream baos = new java.io.ByteArrayOutputStream();
                byte[] buf = new byte[1024];
                int len;
                while ((len = fis.read(buf)) != -1) baos.write(buf, 0, len);
                fis.close();
                return baos.toString("UTF-8");
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return "{}";
    }

    @JavascriptInterface
    public String getSettingsJson() {
        return readSettingsJson(activity);
    }

    @JavascriptInterface
    public void saveSettingsJson(final String jsonPayload) {
        writeSettingsJsonFile(jsonPayload);
    }

    @JavascriptInterface
    public String getRuntimeActiveJson() {
        return readRuntimeActiveJson();
    }

    @JavascriptInterface
    public String getMappingsJson() {
        return readMappingsJson(activity);
    }

    @JavascriptInterface
    public void saveMappingsJson(final String jsonPayload) {
        SharedPreferences prefs = activity.getSharedPreferences("KeyMapperPrefs", Context.MODE_PRIVATE);
        prefs.edit().putString("mappings_json", jsonPayload).apply();
        writeMappingsJsonFile(jsonPayload);
    }

    @JavascriptInterface
    public String getSortOrder() {
        SharedPreferences prefs = activity.getSharedPreferences("KeyMapperPrefs", Context.MODE_PRIVATE);
        return prefs.getString("sort_order", "newest");
    }

    @JavascriptInterface
    public void saveSortOrder(final String sortOrder) {
        SharedPreferences prefs = activity.getSharedPreferences("KeyMapperPrefs", Context.MODE_PRIVATE);
        prefs.edit().putString("sort_order", sortOrder).apply();
    }

    @JavascriptInterface
    public boolean isMasterEnabled() {
        SharedPreferences prefs = activity.getSharedPreferences("KeyMapperPrefs", Context.MODE_PRIVATE);
        return prefs.getBoolean("master_enabled", true);
    }

    @JavascriptInterface
    public String getFailedUploadsJson() {
        return RemoteUploaderEngine.getFailedUploadsJson(activity);
    }

    @JavascriptInterface
    public boolean retryFailedUpload(String id) {
        return RemoteUploaderEngine.retryFailedUpload(activity, id);
    }

    @JavascriptInterface
    public boolean deleteFailedUpload(String id) {
        return RemoteUploaderEngine.deleteFailedUpload(activity, id);
    }

    @JavascriptInterface
    public void setMasterEnabled(final boolean enabled) {
        SharedPreferences prefs = activity.getSharedPreferences("KeyMapperPrefs", Context.MODE_PRIVATE);
        prefs.edit().putBoolean("master_enabled", enabled).apply();
    }

    @JavascriptInterface
    public boolean isAccessibilityEnabled() {
        return KeyAccessibilityService.isServiceRunning();
    }

    @JavascriptInterface
    public void openAccessibilitySettings() {
        activity.runOnUiThread(new Runnable() {
            @Override
            public void run() {
                try {
                    Intent intent = new Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS);
                    intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                    activity.startActivity(intent);
                } catch (Exception e) {
                    e.printStackTrace();
                }
            }
        });
    }

    @JavascriptInterface
    public void executeActionsSequence(final String actionsJson) {
        try {
            org.json.JSONArray array = new org.json.JSONArray(actionsJson);
            ActionExecutor.executeActionsSequence(activity, array);
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    @JavascriptInterface
    public void toggleFlashlight() {
        ActionExecutor.executeAction(activity, "toggle_flashlight");
    }

    @JavascriptInterface
    public void playSound() {
        ActionExecutor.playSoundChime("beep");
    }

    @JavascriptInterface
    public void playSound(String soundType) {
        ActionExecutor.playSoundChime(soundType);
    }

    @JavascriptInterface
    public void vibrate(long ms) {
        ActionExecutor.vibrateDevice(activity, ms, -1);
    }

    @JavascriptInterface
    public void vibrate(long ms, int amplitude) {
        ActionExecutor.vibrateDevice(activity, ms, amplitude);
    }
}