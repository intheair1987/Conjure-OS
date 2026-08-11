package com.conjure.floatassist;

import android.app.Activity;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.view.Window;
import android.view.WindowManager;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

public class MainActivity extends Activity {
    private static final int OVERLAY_PERMISSION_REQ_CODE = 5469;
    private WebView mWebView;

    private BroadcastReceiver telemetryReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            if (intent != null && "com.conjure.floatassist.TELEMETRY_UPDATE".equals(intent.getAction())) {
                final String velocity = intent.getStringExtra("velocity");
                final String deformation = intent.getStringExtra("deformation");
                final String coords = intent.getStringExtra("coords");
                final String constraint = intent.getStringExtra("constraint");
                
                if (mWebView != null) {
                    mWebView.post(new Runnable() {
                        @Override
                        public void run() {
                            String js = String.format("window.updateTelemetry('%s', '%s', '%s', '%s');", 
                                velocity, deformation, coords, constraint);
                            mWebView.evaluateJavascript(js, null);
                        }
                    });
                }
            }
        }
    };

    private BroadcastReceiver stateReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            if (intent != null && "com.conjure.floatassist.SERVICE_STATE_CHANGED".equals(intent.getAction())) {
                if (mWebView != null) {
                    mWebView.post(new Runnable() {
                        @Override
                        public void run() {
                            mWebView.evaluateJavascript("if(window.updateServiceUI) window.updateServiceUI();", null);
                        }
                    });
                }
            }
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        
        // Make window borderless and stretch behind status bar (Status bar visible)
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

        // Initialize Native WebView
        mWebView = new WebView(this);
        mWebView.setBackgroundColor(android.graphics.Color.parseColor("#050508"));
        
        WebSettings ws = mWebView.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        
        // Bind the JavaScript Bridge
        mWebView.addJavascriptInterface(new WebBridge(this), "Android");
        mWebView.setWebViewClient(new WebViewClient());
        
        // Load the HTML Dashboard Asset
        mWebView.loadUrl("file:///android_asset/index.html");
        
        setContentView(mWebView);

        // Request Notification Permission on startup for Android 13+ (API 33+)
        if (Build.VERSION.SDK_INT >= 33) {
            if (checkSelfPermission("android.permission.POST_NOTIFICATIONS") != android.content.pm.PackageManager.PERMISSION_GRANTED) {
                requestPermissions(new String[]{"android.permission.POST_NOTIFICATIONS"}, 5470);
            }
        }
    }

    public void requestOverlayPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            Intent intent = new Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:" + getPackageName()));
            startActivityForResult(intent, OVERLAY_PERMISSION_REQ_CODE);
        }
    }

    public void toggleService(boolean start) {
        Intent intent = new Intent(this, FloatService.class);
        if (start) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                startForegroundService(intent);
            } else {
                startService(intent);
            }
        } else {
            stopService(intent);
        }
    }

    public void syncPreferencesWithService() {
        if (FloatService.isRunning) {
            Intent intent = new Intent("com.conjure.floatassist.UPDATE_SETTINGS");
            intent.setPackage(getPackageName());
            sendBroadcast(intent);
        }
    }

    private void ensureServiceRunning() {
        boolean canDraw = true;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            canDraw = Settings.canDrawOverlays(this);
        }
        if (canDraw && !FloatService.isRunning) {
            toggleService(true);
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        ensureServiceRunning();
        if (mWebView != null) {
            mWebView.evaluateJavascript("if(window.updatePermissionUI) window.updatePermissionUI();", null);
            mWebView.evaluateJavascript("if(window.updateServiceUI) window.updateServiceUI();", null);
        }
        
        IntentFilter filter = new IntentFilter("com.conjure.floatassist.TELEMETRY_UPDATE");
        IntentFilter stateFilter = new IntentFilter("com.conjure.floatassist.SERVICE_STATE_CHANGED");
        
        if (Build.VERSION.SDK_INT >= 33) {
            registerReceiver(telemetryReceiver, filter, 4); // 4 = Context.RECEIVER_NOT_EXPORTED
            registerReceiver(stateReceiver, stateFilter, 4);
        } else {
            registerReceiver(telemetryReceiver, filter);
            registerReceiver(stateReceiver, stateFilter);
        }
        checkStoragePermission();
    }

    private void checkStoragePermission() {
        if (Build.VERSION.SDK_INT >= 30) {
            try {
                Class<?> envClass = Class.forName("android.os.Environment");
                java.lang.reflect.Method isManagerMethod = envClass.getMethod("isExternalStorageManager");
                boolean isManager = (Boolean) isManagerMethod.invoke(null);
                if (!isManager) {
                    Intent intent = new Intent(
                        "android.settings.MANAGE_APP_ALL_FILES_ACCESS_PERMISSION",
                        Uri.parse("package:" + getPackageName())
                    );
                    startActivity(intent);
                }
            } catch (Exception e) {
                try {
                    Intent intent = new Intent("android.settings.MANAGE_ALL_FILES_ACCESS_PERMISSION");
                    startActivity(intent);
                } catch (Exception ignored) {}
            }
        }
    }

    @Override
    protected void onPause() {
        super.onPause();
        try {
            unregisterReceiver(telemetryReceiver);
        } catch (Exception e) {}
        try {
            unregisterReceiver(stateReceiver);
        } catch (Exception e) {}
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == OVERLAY_PERMISSION_REQ_CODE && mWebView != null) {
            mWebView.evaluateJavascript("if(window.updatePermissionUI) window.updatePermissionUI();", null);
        }
    }
}