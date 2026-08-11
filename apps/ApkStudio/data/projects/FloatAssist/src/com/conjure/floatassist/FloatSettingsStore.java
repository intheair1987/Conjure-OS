package com.conjure.floatassist;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.Environment;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileWriter;
import org.json.JSONArray;
import org.json.JSONObject;

public class FloatSettingsStore {
    private static final String SETTINGS_FILENAME = "floatassist_settings.json";

    private static File getSettingsFile() {
        try {
            File configDir = new File(Environment.getExternalStorageDirectory(), "Conjure_Config");
            if (!configDir.exists()) {
                configDir.mkdirs();
            }
            return new File(configDir, SETTINGS_FILENAME);
        } catch (Exception e) {
            e.printStackTrace();
            return null;
        }
    }

    public static synchronized JSONObject getRuntimeActiveManifest() {
        try {
            File configDir = new File(Environment.getExternalStorageDirectory(), "Conjure_Config");
            File manifestFile = new File(configDir, "runtime_active.json");
            if (manifestFile.exists() && manifestFile.length() > 0) {
                try (FileInputStream fis = new FileInputStream(manifestFile);
                     ByteArrayOutputStream baos = new ByteArrayOutputStream()) {
                    byte[] buf = new byte[1024];
                    int len;
                    while ((len = fis.read(buf)) != -1) baos.write(buf, 0, len);
                    String content = baos.toString("UTF-8");
                    if (content != null && !content.trim().isEmpty()) {
                        return new JSONObject(content);
                    }
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return null;
    }

    public static synchronized JSONObject loadSettingsJson(Context context) {
        File file = getSettingsFile();
        JSONObject json = new JSONObject();

        if (file != null && file.exists() && file.length() > 0) {
            try (FileInputStream fis = new FileInputStream(file);
                 ByteArrayOutputStream baos = new ByteArrayOutputStream()) {
                byte[] buf = new byte[1024];
                int len;
                while ((len = fis.read(buf)) != -1) {
                    baos.write(buf, 0, len);
                }
                String content = baos.toString("UTF-8");
                if (content != null && !content.trim().isEmpty()) {
                    json = new JSONObject(content);
                }
            } catch (Exception e) {
                e.printStackTrace();
            }
        }

        // Automatic one-time migration from legacy SharedPreferences
        if (context != null) {
            SharedPreferences prefs = context.getSharedPreferences("FloatAssistPrefs", Context.MODE_PRIVATE);
            boolean modified = false;

            try {
                if (!json.has("ball_size")) { json.put("ball_size", prefs.getInt("ball_size", 56)); modified = true; }
                if (!json.has("ball_glow")) { json.put("ball_glow", prefs.getInt("ball_glow", 10)); modified = true; }
                if (!json.has("ball_color")) { json.put("ball_color", prefs.getString("ball_color", "#6366f1")); modified = true; }
                if (!json.has("ball_color_rgb")) { json.put("ball_color_rgb", prefs.getString("ball_color_rgb", "99, 102, 241")); modified = true; }
                if (!json.has("ball_color_name")) { json.put("ball_color_name", prefs.getString("ball_color_name", "Indigo Flare")); modified = true; }
                if (!json.has("patcher_url")) { json.put("patcher_url", prefs.getString("patcher_url", "http://localhost:8000/patcher.php")); modified = true; }
                if (!json.has("https_port")) { json.put("https_port", prefs.getInt("https_port", 8000)); modified = true; }
                if (!json.has("http_port")) { json.put("http_port", prefs.getInt("http_port", 8002)); modified = true; }
            } catch (Exception ignored) {}

            if (modified) {
                saveSettingsJson(json);
            }
        }

        return json;
    }

    public static synchronized boolean saveSettingsJson(JSONObject json) {
        if (json == null) return false;
        File file = getSettingsFile();
        if (file == null) return false;

        try {
            File dir = file.getParentFile();
            if (dir != null && !dir.exists()) {
                dir.mkdirs();
            }

            try (FileWriter writer = new FileWriter(file, false)) {
                writer.write(json.toString(2));
                writer.flush();
            }
            return true;
        } catch (Exception e) {
            e.printStackTrace();
            return false;
        }
    }

    public static int getInt(Context context, String key, int defValue) {
        JSONObject json = loadSettingsJson(context);
        return json.optInt(key, defValue);
    }

    public static void putInt(Context context, String key, int value) {
        JSONObject json = loadSettingsJson(context);
        try {
            json.put(key, value);
            saveSettingsJson(json);
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    public static String getString(Context context, String key, String defValue) {
        JSONObject json = loadSettingsJson(context);
        return json.optString(key, defValue);
    }

    public static void putString(Context context, String key, String value) {
        JSONObject json = loadSettingsJson(context);
        try {
            json.put(key, value);
            saveSettingsJson(json);
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    public static String getShortcutsJson(Context context) {
        JSONObject json = loadSettingsJson(context);
        JSONArray arr = json.optJSONArray("custom_shortcuts");
        return arr != null ? arr.toString() : "[]";
    }

    public static void saveShortcutsJson(Context context, String shortcutsJsonStr) {
        JSONObject json = loadSettingsJson(context);
        try {
            JSONArray arr = (shortcutsJsonStr != null && !shortcutsJsonStr.trim().isEmpty())
                ? new JSONArray(shortcutsJsonStr)
                : new JSONArray();
            json.put("custom_shortcuts", arr);
            saveSettingsJson(json);
        } catch (Exception e) {
            e.printStackTrace();
        }
    }
}