package com.conjure.floatassist;

import android.os.Build;
import android.provider.Settings;
import android.webkit.JavascriptInterface;

public class WebBridge {
    private MainActivity activity;

    public WebBridge(MainActivity activity) {
        this.activity = activity;
    }

    @JavascriptInterface
    public boolean getServiceStatus() {
        return FloatService.isRunning;
    }

    @JavascriptInterface
    public void toggleService(final boolean start) {
        activity.runOnUiThread(new Runnable() {
            @Override
            public void run() {
                activity.toggleService(start);
            }
        });
    }

    @JavascriptInterface
    public boolean getPermissionStatus() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            return Settings.canDrawOverlays(activity);
        }
        return true;
    }

    @JavascriptInterface
    public void requestPermission() {
        activity.runOnUiThread(new Runnable() {
            @Override
            public void run() {
                activity.requestOverlayPermission();
            }
        });
    }

    @JavascriptInterface
    public void saveSettingInt(String key, int value) {
        FloatSettingsStore.putInt(activity, key, value);
        activity.syncPreferencesWithService();
    }

    @JavascriptInterface
    public int getSettingInt(String key, int defValue) {
        return FloatSettingsStore.getInt(activity, key, defValue);
    }

    @JavascriptInterface
    public void saveSettingString(String key, String value) {
        FloatSettingsStore.putString(activity, key, value);
        activity.syncPreferencesWithService();
    }

    @JavascriptInterface
    public String getSettingString(String key, String defValue) {
        return FloatSettingsStore.getString(activity, key, defValue);
    }

    @JavascriptInterface
    public String getShortcutsJson() {
        return FloatSettingsStore.getShortcutsJson(activity);
    }

    @JavascriptInterface
    public void saveShortcutsJson(String jsonStr) {
        FloatSettingsStore.saveShortcutsJson(activity, jsonStr);
    }

    @JavascriptInterface
    public String getRuntimeActiveJson() {
        org.json.JSONObject manifest = FloatSettingsStore.getRuntimeActiveManifest();
        return manifest != null ? manifest.toString() : "{}";
    }
}