package com.conjure.keymapper;

import android.app.Activity;
import android.content.Intent;
import android.content.SharedPreferences;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.KeyEvent;
import android.view.Window;
import android.view.WindowManager;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import org.json.JSONArray;
import org.json.JSONObject;

public class MainActivity extends Activity {
    private WebView mWebView;
    private int firstCapturedCode = -1;
    private String firstCapturedLabel = "";
    private long firstCapturedTime = 0;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        requestWindowFeature(Window.FEATURE_NO_TITLE);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            getWindow().getAttributes().layoutInDisplayCutoutMode = WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES;
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            getWindow().clearFlags(WindowManager.LayoutParams.FLAG_TRANSLUCENT_STATUS);
            getWindow().addFlags(WindowManager.LayoutParams.FLAG_DRAWS_SYSTEM_BAR_BACKGROUNDS);
            getWindow().setStatusBarColor(android.graphics.Color.TRANSPARENT);
        }
        getWindow().getDecorView().setSystemUiVisibility(
            android.view.View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN | android.view.View.SYSTEM_UI_FLAG_LAYOUT_STABLE
        );

        mWebView = new WebView(this);
        mWebView.setBackgroundColor(android.graphics.Color.parseColor("#08080d"));

        WebSettings ws = mWebView.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        ws.setDatabaseEnabled(true);
        ws.setCacheMode(WebSettings.LOAD_DEFAULT);

        mWebView.addJavascriptInterface(new WebBridge(this), "Android");
        mWebView.setWebViewClient(new WebViewClient() {
            @Override
            public void onReceivedSslError(WebView view, android.webkit.SslErrorHandler handler, android.net.http.SslError error) {
                handler.proceed(); // Allow local / self-signed HTTPS endpoints
            }
        });

        mWebView.loadUrl("file:///android_asset/index.html");
        setContentView(mWebView);

        requestPermissionsIfNeeded();
        handleDeepLinkIntent(getIntent());
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        handleDeepLinkIntent(intent);
    }

    private void handleDeepLinkIntent(Intent intent) {
        if (intent == null || !Intent.ACTION_VIEW.equals(intent.getAction())) {
            return;
        }

        Uri data = intent.getData();
        if (data == null || !"keymapper".equalsIgnoreCase(data.getScheme())) {
            return;
        }

        String host = data.getHost();
        String path = data.getPath();
        String ruleIdParam = data.getQueryParameter("id");
        String aliasParam = data.getQueryParameter("alias");
        String ruleParam = data.getQueryParameter("rule");

        String targetKey = "";
        if (ruleIdParam != null && !ruleIdParam.isEmpty()) {
            targetKey = ruleIdParam;
        } else if (aliasParam != null && !aliasParam.isEmpty()) {
            targetKey = aliasParam;
        } else if (ruleParam != null && !ruleParam.isEmpty()) {
            targetKey = ruleParam;
        } else if (path != null && path.length() > 1) {
            targetKey = path.substring(1);
        } else if (host != null && !host.isEmpty() && !"trigger".equalsIgnoreCase(host) && !"run".equalsIgnoreCase(host)) {
            targetKey = host;
        }

        if (targetKey.isEmpty()) return;

        processDeepLinkExecution(targetKey.trim());
    }

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

    private void processDeepLinkExecution(final String searchKey) {
        executeDeepLinkRule(this, searchKey);
    }

    public static void executeDeepLinkRule(final android.content.Context context, final String searchKey) {
        if (searchKey == null || searchKey.isEmpty() || context == null) return;

        SharedPreferences prefs = context.getSharedPreferences("KeyMapperPrefs", MODE_PRIVATE);
        if (!prefs.getBoolean("master_enabled", true)) {
            CaretOverlayManager.showStatusPill(context, "⚠️ KeyMapper Engine is Paused");
            CaretOverlayManager.dismissStatusPillDelayed(2000);
            return;
        }

        String mappingsJson = WebBridge.readMappingsJson(context);
        boolean matched = false;

        try {
            JSONArray array = new JSONArray(mappingsJson);
            for (int i = 0; i < array.length(); i++) {
                JSONObject rule = array.getJSONObject(i);
                if (!rule.optBoolean("enabled", true)) continue;

                JSONArray triggerSets = getRuleTriggerSets(rule);
                if (triggerSets == null || triggerSets.length() == 0) continue;

                for (int j = 0; j < triggerSets.length(); j++) {
                    JSONObject set = triggerSets.getJSONObject(j);
                    if (!set.optBoolean("enabled", true)) continue;

                    String setAlias = set.optString("alias", set.optString("uri", set.optString("slug", "")));
                    String setLabel = set.optString("name", "");
                    String ruleId = rule.optString("id", "");
                    String ruleName = rule.optString("name", "");
                    String ruleAlias = rule.optString("alias", rule.optString("slug", ""));

                    boolean isUriMatch = searchKey.equalsIgnoreCase(setAlias) ||
                                         searchKey.equalsIgnoreCase(setLabel) ||
                                         searchKey.equalsIgnoreCase(ruleId) ||
                                         searchKey.equalsIgnoreCase(ruleAlias) ||
                                         searchKey.equalsIgnoreCase(ruleName);

                    if (isUriMatch) {
                        JSONArray conditions = set.optJSONArray("conditions");
                        if (ConditionEvaluator.evaluateAll(context, conditions, "")) {
                            matched = true;
                            setMatchedSetId(rule, set);
                            JSONArray actions = rule.optJSONArray("actions");
                            if (actions != null && actions.length() > 0) {
                                String matchedSetId = set.optString("id", "");
                                ActionExecutor.executeActionsSequence(context, actions, matchedSetId);
                                ActionExecutor.vibrateDevice(context, 60);
                                CaretOverlayManager.showStatusPill(context, "🔗 Executed: " + (ruleName.isEmpty() ? searchKey : ruleName));
                                CaretOverlayManager.dismissStatusPillDelayed(2500);

                                boolean autoMin = set.optBoolean("autoMinimize", false);
                                if (autoMin && context instanceof Activity) {
                                    final Activity act = (Activity) context;
                                    new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
                                        @Override
                                        public void run() {
                                            try {
                                                act.moveTaskToBack(true);
                                            } catch (Exception ignored) {}
                                        }
                                    }, 150);
                                }
                            }
                            break;
                        }
                    }
                }

                if (matched) {
                    break;
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }

        if (!matched) {
            CaretOverlayManager.showStatusPill(context, "❓ No active rule/condition matched for URI: " + searchKey);
            CaretOverlayManager.dismissStatusPillDelayed(2500);
        }
    }

    @Override
    public boolean dispatchKeyEvent(KeyEvent event) {
        if (event.getAction() == KeyEvent.ACTION_DOWN) {
            if (WebBridge.isRecordingMode()) {
                final int keyCode = event.getKeyCode();
                final String label = getKeyLabel(keyCode);
                final String codeStr = "KEYCODE_" + keyCode;
                long now = System.currentTimeMillis();

                if (firstCapturedCode != -1 && firstCapturedCode != keyCode && (now - firstCapturedTime) < 400) {
                    // Two keys pressed close together -> Capture Combination!
                    final int primary = firstCapturedCode;
                    final String primaryLabel = firstCapturedLabel;
                    final int secondary = keyCode;
                    final String secondaryLabel = label;

                    firstCapturedCode = -1;
                    WebBridge.setRecordingMode(false);

                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            if (mWebView != null) {
                                String js = "if(window.onNativeKeyCapturedCombo) window.onNativeKeyCapturedCombo(" 
                                    + primary + ", '" + escapeJs(primaryLabel) + "', "
                                    + secondary + ", '" + escapeJs(secondaryLabel) + "');";
                                mWebView.evaluateJavascript(js, null);
                            }
                        }
                    });
                    return true;
                } else {
                    firstCapturedCode = keyCode;
                    firstCapturedLabel = label;
                    firstCapturedTime = now;

                    // Single key dispatch with short delayed fallback if no second key arrives
                    new android.os.Handler(android.os.Looper.getMainLooper()).postDelayed(new Runnable() {
                        @Override
                        public void run() {
                            if (firstCapturedCode == keyCode && WebBridge.isRecordingMode()) {
                                firstCapturedCode = -1;
                                WebBridge.setRecordingMode(false);
                                if (mWebView != null) {
                                    String js = "if(window.onNativeKeyCaptured) window.onNativeKeyCaptured(" 
                                        + keyCode + ", '" + escapeJs(label) + "', '" + escapeJs(codeStr) + "');";
                                    mWebView.evaluateJavascript(js, null);
                                }
                            }
                        }
                    }, 300);
                    return true;
                }
            }
        }
        return super.dispatchKeyEvent(event);
    }

    private String getKeyLabel(int keyCode) {
        switch (keyCode) {
            case KeyEvent.KEYCODE_VOLUME_DOWN: return "Volume Down";
            case KeyEvent.KEYCODE_VOLUME_UP: return "Volume Up";
            case KeyEvent.KEYCODE_MEDIA_PLAY_PAUSE: return "Media Play/Pause";
            case KeyEvent.KEYCODE_MEDIA_NEXT: return "Media Next";
            case KeyEvent.KEYCODE_MEDIA_PREVIOUS: return "Media Previous";
            case KeyEvent.KEYCODE_HEADSETHOOK: return "Headset Hook";
            case KeyEvent.KEYCODE_CAMERA: return "Camera Button";
            case KeyEvent.KEYCODE_MUTE: return "Mute Button";
            case KeyEvent.KEYCODE_VOLUME_MUTE: return "Volume Mute";
            case 133: return "Alert Slider / F3 (Key 133)";
            default: return "Key (" + keyCode + ")";
        }
    }

    private String escapeJs(String str) {
        if (str == null) return "";
        return str.replace("\\", "\\\\").replace("'", "\\'");
    }

    private void requestPermissionsIfNeeded() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            String[] permissions = {
                android.Manifest.permission.CAMERA,
                android.Manifest.permission.VIBRATE,
                android.Manifest.permission.RECORD_AUDIO,
                android.Manifest.permission.WRITE_EXTERNAL_STORAGE
            };
            boolean needRequest = false;
            for (String p : permissions) {
                if (checkSelfPermission(p) != android.content.pm.PackageManager.PERMISSION_GRANTED) {
                    needRequest = true;
                    break;
                }
            }
            if (needRequest) {
                requestPermissions(permissions, 1001);
            }
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (mWebView != null) {
            mWebView.evaluateJavascript("if(window.checkAccessibilityStatus) window.checkAccessibilityStatus();", null);
        }
        checkStoragePermission();
        checkOverlayPermission();
    }

    private void checkOverlayPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            if (!android.provider.Settings.canDrawOverlays(this)) {
                try {
                    android.content.Intent intent = new android.content.Intent(
                        android.provider.Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                        android.net.Uri.parse("package:" + getPackageName())
                    );
                    startActivity(intent);
                } catch (Exception e) {
                    e.printStackTrace();
                }
            }
        }
    }

    private void checkStoragePermission() {
        if (Build.VERSION.SDK_INT >= 30) {
            try {
                Class<?> envClass = Class.forName("android.os.Environment");
                java.lang.reflect.Method isManagerMethod = envClass.getMethod("isExternalStorageManager");
                boolean isManager = (Boolean) isManagerMethod.invoke(null);
                if (!isManager) {
                    android.content.Intent intent = new android.content.Intent(
                        "android.settings.MANAGE_APP_ALL_FILES_ACCESS_PERMISSION",
                        android.net.Uri.parse("package:" + getPackageName())
                    );
                    startActivity(intent);
                }
            } catch (Exception e) {
                try {
                    android.content.Intent intent = new android.content.Intent("android.settings.MANAGE_ALL_FILES_ACCESS_PERMISSION");
                    startActivity(intent);
                } catch (Exception ignored) {}
            }
        }
    }

    @Override
    public void onBackPressed() {
        if (mWebView != null && mWebView.canGoBack()) {
            mWebView.goBack();
        } else {
            super.onBackPressed();
        }
    }
}