package com.conjure.apkwrapper;

import android.app.Activity;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.view.Window;
import android.view.WindowManager;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

public class MainActivity extends Activity {
    private WebView mWebView;
    private WebBridge mWebBridge;
    private ValueCallback<Uri[]> mFilePathCallback;
    private android.webkit.PermissionRequest mPendingPermissionRequest;
    private final static int FILECHOOSER_RESULTCODE = 1;

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == 2) {
            if (grantResults.length > 0 && grantResults[0] == android.content.pm.PackageManager.PERMISSION_GRANTED) {
                if (mPendingPermissionRequest != null) {
                    mPendingPermissionRequest.grant(mPendingPermissionRequest.getResources());
                    mPendingPermissionRequest = null;
                }
            } else {
                if (mPendingPermissionRequest != null) {
                    mPendingPermissionRequest.deny();
                    mPendingPermissionRequest = null;
                }
            }
        }
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        
        android.os.StrictMode.VmPolicy.Builder builder = new android.os.StrictMode.VmPolicy.Builder();
        android.os.StrictMode.setVmPolicy(builder.build());
        initToolchain();
        
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
        mWebView.setBackgroundColor(android.graphics.Color.parseColor("#050508"));
        
        WebSettings ws = mWebView.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        ws.setCacheMode(WebSettings.LOAD_NO_CACHE);
        mWebView.clearCache(true);
        
        mWebBridge = new WebBridge(this, mWebView);
        mWebView.addJavascriptInterface(mWebBridge, "Android");
        mWebView.setWebViewClient(new WebViewClient() {
            @Override
            public void onReceivedSslError(WebView view, android.webkit.SslErrorHandler handler, android.net.http.SslError error) {
                handler.proceed();
            }

            @Override
            public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
                super.onPageStarted(view, url, favicon);
                String polyfillJs = "if(!window.speechSynthesis || !window.speechSynthesis.__isNativePolyfill){" +
                    "window.SpeechSynthesisUtterance = function(text){" +
                    "  this.text = text || ''; this.lang = 'en-US'; this.pitch = 1.0; this.rate = 1.0; this.volume = 1.0; this.onend = null; this.onerror = null; this.onstart = null;" +
                    "};" +
                    "window.speechSynthesis = {" +
                    "  __isNativePolyfill: true, speaking: false, pending: false, paused: false," +
                    "  speak: function(u){" +
                    "    if(!u) return;" +
                    "    var t = (typeof u === 'string') ? u : (u.text || '');" +
                    "    if(!t) return;" +
                    "    var l = u.lang || 'en-US'; var p = u.pitch || 1.0; var r = u.rate || 1.0;" +
                    "    if(window.Android && window.Android.speak) window.Android.speak(t, l, p, r);" +
                    "    if(typeof u.onstart === 'function') setTimeout(function(){ try{u.onstart();}catch(e){} }, 10);" +
                    "    if(typeof u.onend === 'function') setTimeout(function(){ try{u.onend();}catch(e){} }, Math.max(600, t.length * 80));" +
                    "  }," +
                    "  cancel: function(){ if(window.Android && window.Android.stopSpeaking) window.Android.stopSpeaking(); }," +
                    "  pause: function(){}," +
                    "  resume: function(){}," +
                    "  getVoices: function(){ return [{ name: 'Android Native Voice', lang: 'en-US', default: true }]; }" +
                    "};" +
                    "}";
                view.evaluateJavascript(polyfillJs, null);
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                String polyfillJs = "if(!window.speechSynthesis || !window.speechSynthesis.__isNativePolyfill){" +
                    "window.SpeechSynthesisUtterance = function(text){" +
                    "  this.text = text || ''; this.lang = 'en-US'; this.pitch = 1.0; this.rate = 1.0; this.volume = 1.0; this.onend = null; this.onerror = null; this.onstart = null;" +
                    "};" +
                    "window.speechSynthesis = {" +
                    "  __isNativePolyfill: true, speaking: false, pending: false, paused: false," +
                    "  speak: function(u){" +
                    "    if(!u) return;" +
                    "    var t = (typeof u === 'string') ? u : (u.text || '');" +
                    "    if(!t) return;" +
                    "    var l = u.lang || 'en-US'; var p = u.pitch || 1.0; var r = u.rate || 1.0;" +
                    "    if(window.Android && window.Android.speak) window.Android.speak(t, l, p, r);" +
                    "    if(typeof u.onstart === 'function') setTimeout(function(){ try{u.onstart();}catch(e){} }, 10);" +
                    "    if(typeof u.onend === 'function') setTimeout(function(){ try{u.onend();}catch(e){} }, Math.max(600, t.length * 80));" +
                    "  }," +
                    "  cancel: function(){ if(window.Android && window.Android.stopSpeaking) window.Android.stopSpeaking(); }," +
                    "  pause: function(){}," +
                    "  resume: function(){}," +
                    "  getVoices: function(){ return [{ name: 'Android Native Voice', lang: 'en-US', default: true }]; }" +
                    "};" +
                    "}";
                view.evaluateJavascript(polyfillJs, null);
            }
        });
        
        mWebView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onPermissionRequest(final android.webkit.PermissionRequest request) {
                runOnUiThread(new Runnable() {
                    @Override
                    public void run() {
                        if (checkSelfPermission(android.Manifest.permission.RECORD_AUDIO) == android.content.pm.PackageManager.PERMISSION_GRANTED) {
                            request.grant(request.getResources());
                        } else {
                            mPendingPermissionRequest = request;
                            requestPermissions(new String[]{android.Manifest.permission.RECORD_AUDIO}, 2);
                        }
                    }
                });
            }

            @Override
            public boolean onShowFileChooser(WebView webView, ValueCallback<Uri[]> filePathCallback, WebChromeClient.FileChooserParams fileChooserParams) {
                if (mFilePathCallback != null) {
                    mFilePathCallback.onReceiveValue(null);
                }
                mFilePathCallback = filePathCallback;
                try {
                    Intent intent = fileChooserParams.createIntent();
                    startActivityForResult(intent, FILECHOOSER_RESULTCODE);
                } catch (Exception e) {
                    Intent fallbackIntent = new Intent(Intent.ACTION_GET_CONTENT);
                    fallbackIntent.addCategory(Intent.CATEGORY_OPENABLE);
                    fallbackIntent.setType("*/*");
                    startActivityForResult(Intent.createChooser(fallbackIntent, "Select File"), FILECHOOSER_RESULTCODE);
                }
                return true;
            }
        });
        
        mWebView.loadUrl("file:///android_asset/index.html");
        setContentView(mWebView);
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        if (requestCode == FILECHOOSER_RESULTCODE) {
            if (mFilePathCallback == null) return;
            Uri[] results = null;
            if (resultCode == Activity.RESULT_OK && data != null) {
                String dataString = data.getDataString();
                if (dataString != null) {
                    results = new Uri[]{Uri.parse(dataString)};
                }
            }
            mFilePathCallback.onReceiveValue(results);
            mFilePathCallback = null;
        } else {
            super.onActivityResult(requestCode, resultCode, data);
        }
    }

    private void initToolchain() {
        final java.io.File toolchainDir = new java.io.File(getFilesDir(), "toolchain");
        if (!toolchainDir.exists()) {
            toolchainDir.mkdirs();
        }
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    String[] expected = {"android.jar", "ecj.jar", "d8.jar", "apksigner.jar", "debug.keystore"};
                    for (String name : expected) {
                        java.io.File dest = new java.io.File(toolchainDir, name);
                        if (!dest.exists()) {
                            try (java.io.InputStream in = getAssets().open(name);
                                 java.io.OutputStream out = new java.io.FileOutputStream(dest)) {
                                byte[] buf = new byte[8192];
                                int len;
                                while ((len = in.read(buf)) > 0) out.write(buf, 0, len);
                            }
                        }
                        dest.setWritable(false, false);
                    }
                } catch (Exception e) {
                    e.printStackTrace();
                }
            }
        }).start();
    }

    @Override
    protected void onDestroy() {
        if (mWebBridge != null) {
            mWebBridge.destroyTts();
        }
        if (mWebView != null) {
            try { mWebView.destroy(); } catch(Exception ignored) {}
        }
        super.onDestroy();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (mWebView != null) {
            mWebView.evaluateJavascript("if(window.onAppResume) window.onAppResume();", null);
        }
        // Request storage permissions to allow saving compiled APKs to Downloads
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
                e.printStackTrace();
            }
        } else if (Build.VERSION.SDK_INT >= 23) {
            if (checkSelfPermission(android.Manifest.permission.WRITE_EXTERNAL_STORAGE) != android.content.pm.PackageManager.PERMISSION_GRANTED) {
                requestPermissions(new String[]{android.Manifest.permission.WRITE_EXTERNAL_STORAGE}, 1);
            }
        }
    }
}