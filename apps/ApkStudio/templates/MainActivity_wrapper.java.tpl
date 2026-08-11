package {{PACKAGE_NAME}};

import android.app.Activity;
import android.content.Intent;
import android.content.SharedPreferences;
import android.hardware.Sensor;
import android.hardware.SensorEvent;
import android.hardware.SensorEventListener;
import android.hardware.SensorManager;
import android.net.Uri;
import android.os.Bundle;
import android.webkit.JavascriptInterface;
import android.webkit.ValueCallback;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.webkit.SslErrorHandler;
import android.net.http.SslError;
import android.view.Window;
import android.view.WindowManager;
import android.webkit.DownloadListener;
import android.webkit.URLUtil;
import android.app.DownloadManager;
import android.os.Environment;
import android.util.Base64;
import android.widget.Toast;
import java.io.File;

public class MainActivity extends Activity implements SensorEventListener {
    private WebView mWebView;
    private android.widget.ProgressBar mProgressBar;
    private android.webkit.PermissionRequest mPendingPermissionRequest;

    // Native Text-To-Speech (TTS) Engine Elements
    private android.speech.tts.TextToSpeech mTts;
    private boolean mTtsReady = false;

    private void initTtsEngine() {
        try {
            mTts = new android.speech.tts.TextToSpeech(this, new android.speech.tts.TextToSpeech.OnInitListener() {
                @Override
                public void onInit(int status) {
                    if (status == android.speech.tts.TextToSpeech.SUCCESS) {
                        mTtsReady = true;
                        if (mTts != null) {
                            mTts.setLanguage(java.util.Locale.US);
                        }
                    }
                }
            });
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    @JavascriptInterface
    public void speak(final String text) {
        speak(text, "en-US", 1.0f, 1.0f);
    }

    @JavascriptInterface
    public void speak(final String text, final String lang, final float pitch, final float rate) {
        if (text == null || text.trim().isEmpty()) return;
        final String cleanText = text.trim();
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mTts != null && mTtsReady) {
                    try {
                        if (lang != null && !lang.trim().isEmpty()) {
                            mTts.setLanguage(new java.util.Locale(lang.trim()));
                        }
                    } catch (Exception ignored) {}
                    try {
                        mTts.setPitch(pitch > 0 ? pitch : 1.0f);
                        mTts.setSpeechRate(rate > 0 ? rate : 1.0f);
                        mTts.speak(cleanText, android.speech.tts.TextToSpeech.QUEUE_FLUSH, null, "Utterance_" + System.currentTimeMillis());
                    } catch (Exception e) {
                        e.printStackTrace();
                    }
                }
            }
        });
    }

    @JavascriptInterface
    public void stopSpeaking() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mTts != null) {
                    try { mTts.stop(); } catch (Exception ignored) {}
                }
            }
        });
    }

    @JavascriptInterface
    public boolean isSpeaking() {
        return (mTts != null && mTtsReady && mTts.isSpeaking());
    }

    private void destroyTtsEngine() {
        if (mTts != null) {
            try {
                mTts.stop();
                mTts.shutdown();
            } catch (Exception ignored) {}
        }
    }

    private void injectTtsPolyfill(WebView view) {
        if (view == null) return;
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

    // File Chooser Elements
    private ValueCallback<Uri[]> mFilePathCallback;
    private static final int FILECHOOSER_RESULTCODE = 5102;

    // Sensor Engine Elements (Shake to Refresh)
    private SensorManager mSensorManager;
    private float mAccel;
    private float mAccelCurrent;
    private float mAccelLast;
    private long mLastShakeTime = 0;
    private static final float SHAKE_THRESHOLD = 2.7f;
    private static final long SHAKE_COOLDOWN_MS = 1500;

    // Shake HUD Banner Elements
    private android.widget.LinearLayout mShakeRefreshCard;
    private final android.os.Handler mShakeDismissHandler = new android.os.Handler(android.os.Looper.getMainLooper());
    private Runnable mShakeDismissRunnable;

    // Multi-Tab & Child Overlay Engine Elements
    private static class TabItem {
        WebView webView;
        String title = "New Tab";
        String url = "";

        TabItem(WebView webView, String url) {
            this.webView = webView;
            this.url = url;
        }
    }

    private final java.util.List<TabItem> mTabList = new java.util.ArrayList<>();
    private int mActiveTabIndex = 0;

    private android.widget.FrameLayout mWebViewContainer;
    private android.widget.FrameLayout mChildOverlayContainer;
    private WebView mSecondaryWebView;
    private android.widget.TextView mChildTitleView;

    private android.widget.FrameLayout mFloatingTabBadge;
    private android.widget.TextView mFloatingTabCount;

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == 4103) {
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
                android.widget.Toast.makeText(this, "Microphone permission required for audio recording.", android.widget.Toast.LENGTH_SHORT).show();
            }
        }
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        // Retrieve persistent theme preference
        SharedPreferences prefs = getSharedPreferences("ThemePrefs", MODE_PRIVATE);
        String themeMode = prefs.getString("theme_mode", "system");

        // Process incoming Launcher Shortcut intents
        Intent intent = getIntent();
        boolean openSettingsOnStart = false;
        if (intent != null && intent.getAction() != null) {
            String action = intent.getAction();
            if (action.endsWith(".OPEN_SETTINGS")) {
                openSettingsOnStart = true;
                intent.setAction(getPackageName() + ".MAIN");
            }
        }

        // Dynamically alter theme attributes before onCreate to bind prefers-color-scheme
        if ("light".equals(themeMode)) {
            setTheme(android.R.style.Theme_DeviceDefault_Light_NoActionBar_Fullscreen);
        } else if ("dark".equals(themeMode)) {
            setTheme(android.R.style.Theme_DeviceDefault_NoActionBar_Fullscreen);
        }

        super.onCreate(savedInstanceState);
        initTtsEngine();

        SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
        String statusBarMode = urlPrefs.getString("status_bar_mode", "fullscreen");
        
        requestWindowFeature(Window.FEATURE_NO_TITLE);

        if ("fullscreen".equals(statusBarMode)) {
            getWindow().setFlags(WindowManager.LayoutParams.FLAG_FULLSCREEN,
                    WindowManager.LayoutParams.FLAG_FULLSCREEN);
        } else {
            getWindow().clearFlags(WindowManager.LayoutParams.FLAG_FULLSCREEN);
        }

        // Stretch content behind display cutouts
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.P) {
            getWindow().getAttributes().layoutInDisplayCutoutMode = 
                WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES;
        }

        // Force status bar colors according to selected display mode
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.LOLLIPOP) {
            getWindow().clearFlags(WindowManager.LayoutParams.FLAG_TRANSLUCENT_STATUS);
            getWindow().addFlags(WindowManager.LayoutParams.FLAG_DRAWS_SYSTEM_BAR_BACKGROUNDS);

            if ("transparent".equals(statusBarMode)) {
                getWindow().setStatusBarColor(android.graphics.Color.TRANSPARENT);
            } else if ("solid".equals(statusBarMode)) {
                int solidBgColor = "light".equals(themeMode) 
                    ? android.graphics.Color.parseColor("#ffffff") 
                    : android.graphics.Color.parseColor("#08080d");
                getWindow().setStatusBarColor(solidBgColor);
            } else {
                getWindow().setStatusBarColor(android.graphics.Color.TRANSPARENT);
            }
        }

        // Layout the view under the status bar boundaries seamlessly
        int uiFlags = android.view.View.SYSTEM_UI_FLAG_LAYOUT_STABLE;
        if ("transparent".equals(statusBarMode) || "fullscreen".equals(statusBarMode)) {
            uiFlags |= android.view.View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN;
        }

        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.M) {
            boolean isLightTheme = "light".equals(themeMode);
            if (!"light".equals(themeMode) && !"dark".equals(themeMode)) {
                int currentNightMode = getResources().getConfiguration().uiMode & android.content.res.Configuration.UI_MODE_NIGHT_MASK;
                isLightTheme = (currentNightMode != android.content.res.Configuration.UI_MODE_NIGHT_YES);
            }
            if (isLightTheme && !"fullscreen".equals(statusBarMode)) {
                uiFlags |= android.view.View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR;
            }
        }

        getWindow().getDecorView().setSystemUiVisibility(uiFlags);

        android.widget.FrameLayout layout = new android.widget.FrameLayout(this);
        
        // Dynamically match loading backgrounds to target themes
        if ("light".equals(themeMode)) {
            layout.setBackgroundColor(android.graphics.Color.parseColor("#ffffff"));
        } else if ("dark".equals(themeMode)) {
            layout.setBackgroundColor(android.graphics.Color.parseColor("#050508"));
        } else {
            int currentNightMode = getResources().getConfiguration().uiMode & android.content.res.Configuration.UI_MODE_NIGHT_MASK;
            if (currentNightMode == android.content.res.Configuration.UI_MODE_NIGHT_YES) {
                layout.setBackgroundColor(android.graphics.Color.parseColor("#050508"));
            } else {
                layout.setBackgroundColor(android.graphics.Color.parseColor("#ffffff"));
            }
        }

        mWebView = new WebView(this);
        mWebView.setAlpha(0f);
        WebSettings webSettings = mWebView.getSettings();
        
        webSettings.setJavaScriptEnabled(true);
        webSettings.setDomStorageEnabled(true);
        webSettings.setDatabaseEnabled(true);
        webSettings.setCacheMode(WebSettings.LOAD_DEFAULT);
        webSettings.setSupportMultipleWindows(true);
        webSettings.setJavaScriptCanOpenWindowsAutomatically(true);
        
        mWebView.setLayerType(WebView.LAYER_TYPE_HARDWARE, null);
        mWebView.addJavascriptInterface(this, "Android");
        applyZoomSettings(mWebView);
        
        mWebView.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
                handleDownload(url, userAgent, contentDisposition, mimeType, contentLength);
            }
        });
        
        mWebView.setWebViewClient(new WebViewClient() {
            @Override
            public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
                handler.proceed();
            }

            @Override
            public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
                super.onPageStarted(view, url, favicon);
                injectTtsPolyfill(view);
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                if (url != null && url.startsWith("intent://")) {
                    if (!handleIntentUri(url)) {
                        Toast.makeText(MainActivity.this, "Target app is not installed", Toast.LENGTH_SHORT).show();
                    }
                    return true;
                }
                if (isExternalOrNewTabLink(view, url)) {
                    handleLinkOpening(url);
                    return true;
                }
                return false;
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, android.webkit.WebResourceRequest request) {
                if (request != null && request.getUrl() != null) {
                    String url = request.getUrl().toString();
                    if (url.startsWith("intent://")) {
                        if (!handleIntentUri(url)) {
                            Toast.makeText(MainActivity.this, "Target app is not installed", Toast.LENGTH_SHORT).show();
                        }
                        return true;
                    }
                    if (isExternalOrNewTabLink(view, url)) {
                        handleLinkOpening(url);
                        return true;
                    }
                }
                return false;
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                injectTtsPolyfill(view);
                hideLoadingSpinner();
                enforceViewportZoomJs(view);
                
                if (!mTabList.isEmpty()) {
                    mTabList.get(0).url = url;
                    String title = view.getTitle();
                    if (title != null && !title.trim().isEmpty()) {
                        mTabList.get(0).title = title.trim();
                    }
                }

                SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
                if (urlPrefs.getBoolean("resume_last_url", false)) {
                    urlPrefs.edit().putString("last_visited_url", url).apply();
                    saveTabSessionState();
                }
            }
        });

        mWebView.setWebChromeClient(new android.webkit.WebChromeClient() {
            @Override
            public void onReceivedTitle(WebView view, String title) {
                super.onReceivedTitle(view, title);
                if (!mTabList.isEmpty() && title != null && !title.trim().isEmpty()) {
                    mTabList.get(0).title = title.trim();
                    saveTabSessionState();
                }
            }

            @Override
            public boolean onCreateWindow(WebView view, boolean isDialog, boolean isUserGesture, android.os.Message resultMsg) {
                WebView dummy = new WebView(MainActivity.this);
                dummy.setWebViewClient(new WebViewClient() {
                    @Override
                    public boolean shouldOverrideUrlLoading(WebView wv, String url) {
                        handleLinkOpening(url);
                        return true;
                    }

                    @Override
                    public boolean shouldOverrideUrlLoading(WebView wv, android.webkit.WebResourceRequest request) {
                        if (request != null && request.getUrl() != null) {
                            handleLinkOpening(request.getUrl().toString());
                        }
                        return true;
                    }
                });

                WebView.WebViewTransport transport = (WebView.WebViewTransport) resultMsg.obj;
                transport.setWebView(dummy);
                resultMsg.sendToTarget();
                return true;
            }

            @Override
            public void onPermissionRequest(final android.webkit.PermissionRequest request) {
                runOnUiThread(new Runnable() {
                    @Override
                    public void run() {
                        if (checkSelfPermission(android.Manifest.permission.RECORD_AUDIO) == android.content.pm.PackageManager.PERMISSION_GRANTED) {
                            request.grant(request.getResources());
                        } else {
                            mPendingPermissionRequest = request;
                            requestPermissions(new String[]{android.Manifest.permission.RECORD_AUDIO}, 4103);
                        }
                    }
                });
            }

            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                super.onProgressChanged(view, newProgress);
                if (newProgress >= 90) {
                    hideLoadingSpinner();
                }
            }

            @Override
            public boolean onShowFileChooser(WebView webView, ValueCallback<Uri[]> filePathCallback, FileChooserParams fileChooserParams) {
                return handleShowFileChooser(filePathCallback, fileChooserParams);
            }
        });

        mProgressBar = new android.widget.ProgressBar(this, null, android.R.attr.progressBarStyleLarge);
        android.widget.FrameLayout.LayoutParams pbParams = new android.widget.FrameLayout.LayoutParams(
                android.widget.FrameLayout.LayoutParams.WRAP_CONTENT,
                android.widget.FrameLayout.LayoutParams.WRAP_CONTENT
            );
        pbParams.gravity = android.view.Gravity.CENTER;
        mProgressBar.setLayoutParams(pbParams);

        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.LOLLIPOP) {
            String tintColor = "light".equals(themeMode) ? "#5856d6" : "#6366f1";
            mProgressBar.setIndeterminateTintList(android.content.res.ColorStateList.valueOf(android.graphics.Color.parseColor(tintColor)));
        }

        mWebViewContainer = new android.widget.FrameLayout(this);
        layout.addView(mWebViewContainer, new android.widget.FrameLayout.LayoutParams(
                android.widget.FrameLayout.LayoutParams.MATCH_PARENT,
                android.widget.FrameLayout.LayoutParams.MATCH_PARENT
        ));

        // Mount Primary Tab 0
        mTabList.add(new TabItem(mWebView, ""));
        mWebViewContainer.addView(mWebView, new android.widget.FrameLayout.LayoutParams(
                android.widget.FrameLayout.LayoutParams.MATCH_PARENT,
                android.widget.FrameLayout.LayoutParams.MATCH_PARENT
        ));

        // Create Child Overlay Container (Option A)
        initChildOverlayContainer(layout);

        // Create Floating Tab Counter Badge (Option B - bottom right)
        initFloatingTabBadge(layout);

        // Initialize Accelerometer Sensor Engine
        mSensorManager = (SensorManager) getSystemService(SENSOR_SERVICE);
        mAccel = 10.00f;
        mAccelCurrent = SensorManager.GRAVITY_EARTH;
        mAccelLast = SensorManager.GRAVITY_EARTH;

        // Initialize Shake-to-Refresh HUD Banner
        initShakeRefreshCard(layout);

        layout.addView(mProgressBar);

        // Single Source of Truth for URLs
        final String savedUrl = urlPrefs.getString("custom_url", "{{WRAPPER_URL}}");

        boolean confirmResume = urlPrefs.getBoolean("confirm_resume", false);
        final String lastVisitedUrl = urlPrefs.getString("last_visited_url", "");

        boolean hasSavedSession = hasResumableSession(urlPrefs, savedUrl);

        setContentView(layout);

        if (hasSavedSession) {
            if (confirmResume) {
                if (openSettingsOnStart) {
                    restoreFullSessionState(savedUrl);
                    new android.os.Handler(android.os.Looper.getMainLooper()).postDelayed(new Runnable() {
                        @Override
                        public void run() {
                            showSettingsDialog();
                        }
                    }, 500);
                } else {
                    new android.os.Handler(android.os.Looper.getMainLooper()).postDelayed(new Runnable() {
                        @Override
                        public void run() {
                            showResumeConfirmationDialog(savedUrl, lastVisitedUrl);
                        }
                    }, 300);
                }
            } else {
                restoreFullSessionState(lastVisitedUrl);
                if (openSettingsOnStart) {
                    new android.os.Handler(android.os.Looper.getMainLooper()).postDelayed(new Runnable() {
                        @Override
                        public void run() {
                            showSettingsDialog();
                        }
                    }, 500);
                }
            }
        } else {
            restoreFullSessionState(savedUrl);
            if (openSettingsOnStart) {
                new android.os.Handler(android.os.Looper.getMainLooper()).postDelayed(new Runnable() {
                    @Override
                    public void run() {
                        showSettingsDialog();
                    }
                }, 500);
            }
        }

        registerSettingsShortcut();
    }

    private android.graphics.drawable.Icon createEmojiIcon(String emoji, String bgColor) {
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.M) {
            int size = 144;
            android.graphics.Bitmap bitmap = android.graphics.Bitmap.createBitmap(size, size, android.graphics.Bitmap.Config.ARGB_8888);
            android.graphics.Canvas canvas = new android.graphics.Canvas(bitmap);
            
            android.graphics.Paint bgPaint = new android.graphics.Paint(android.graphics.Paint.ANTI_ALIAS_FLAG);
            bgPaint.setColor(android.graphics.Color.parseColor(bgColor));
            canvas.drawCircle(size / 2f, size / 2f, size / 2f, bgPaint);

            android.graphics.Paint textPaint = new android.graphics.Paint(android.graphics.Paint.ANTI_ALIAS_FLAG);
            textPaint.setTextSize(72);
            textPaint.setTextAlign(android.graphics.Paint.Align.CENTER);
            
            int xPos = (canvas.getWidth() / 2);
            int yPos = (int) ((canvas.getHeight() / 2) - ((textPaint.descent() + textPaint.ascent()) / 2)); 
            
            canvas.drawText(emoji, xPos, yPos, textPaint);
            return android.graphics.drawable.Icon.createWithBitmap(bitmap);
        }
        return null;
    }

    private void registerSettingsShortcut() {
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.N_MR1) {
            android.content.pm.ShortcutManager shortcutManager = getSystemService(android.content.pm.ShortcutManager.class);
            if (shortcutManager != null) {
                java.util.List<android.content.pm.ShortcutInfo> shortcuts = new java.util.ArrayList<>();
                String pkg = getPackageName();

                Intent settingsIntent = new Intent(this, MainActivity.class);
                settingsIntent.setAction(pkg + ".OPEN_SETTINGS");
                android.content.pm.ShortcutInfo settingsShortcut = new android.content.pm.ShortcutInfo.Builder(this, "shortcut_settings")
                        .setShortLabel("Settings")
                        .setLongLabel("Configure theme and target URL")
                        .setIcon(createEmojiIcon("⚙️", "#ffffff"))
                        .setIntent(settingsIntent)
                        .build();
                shortcuts.add(settingsShortcut);

                shortcutManager.setDynamicShortcuts(shortcuts);
            }
        }
    }

    @JavascriptInterface
    public boolean isAppInstalled(final String pkgName) {
        if (pkgName == null || pkgName.trim().isEmpty()) return false;
        try {
            getPackageManager().getPackageInfo(pkgName.trim(), 0);
            return true;
        } catch (Exception e) {
            return false;
        }
    }

    @JavascriptInterface
    public boolean launchApp(final String pkgName) {
        if (pkgName == null || pkgName.trim().isEmpty()) return false;
        try {
            Intent launchIntent = getPackageManager().getLaunchIntentForPackage(pkgName.trim());
            if (launchIntent != null) {
                launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                startActivity(launchIntent);
                return true;
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return false;
    }

    private boolean handleIntentUri(String url) {
        if (url == null || !url.startsWith("intent://")) return false;
        try {
            Intent intent = Intent.parseUri(url, Intent.URI_INTENT_SCHEME);
            if (intent != null) {
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                if (getPackageManager().resolveActivity(intent, 0) != null) {
                    startActivity(intent);
                    return true;
                } else {
                    String fallbackUrl = intent.getStringExtra("browser_fallback_url");
                    if (fallbackUrl != null && !fallbackUrl.isEmpty()) {
                        mWebView.loadUrl(fallbackUrl);
                        return true;
                    }
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return false;
    }

    @JavascriptInterface
    public void openSettingsPage() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                openInChildOverlay("file:///android_asset/settings.html");
            }
        });
    }

    private void showSettingsDialog() {
        openSettingsPage();
    }

    private void initChildOverlayContainer(android.widget.FrameLayout rootLayout) {
        mChildOverlayContainer = new android.widget.FrameLayout(this);
        mChildOverlayContainer.setVisibility(android.view.View.GONE);
        mChildOverlayContainer.setBackgroundColor(android.graphics.Color.parseColor("#08080d"));

        android.widget.LinearLayout innerLayout = new android.widget.LinearLayout(this);
        innerLayout.setOrientation(android.widget.LinearLayout.VERTICAL);

        // Top Header Bar
        android.widget.LinearLayout header = new android.widget.LinearLayout(this);
        header.setOrientation(android.widget.LinearLayout.HORIZONTAL);
        header.setGravity(android.view.Gravity.CENTER_VERTICAL);
        header.setBackgroundColor(android.graphics.Color.parseColor("#12121a"));
        header.setPadding(dpToPx(12), dpToPx(10), dpToPx(12), dpToPx(10));

        android.widget.TextView btnClose = new android.widget.TextView(this);
        btnClose.setText("✕");
        btnClose.setTextSize(16);
        btnClose.setTextColor(android.graphics.Color.parseColor("#a1a1aa"));
        btnClose.setPadding(dpToPx(6), dpToPx(4), dpToPx(12), dpToPx(4));
        btnClose.setOnClickListener(new android.view.View.OnClickListener() {
            @Override
            public void onClick(android.view.View v) {
                closeChildOverlay();
            }
        });

        mChildTitleView = new android.widget.TextView(this);
        mChildTitleView.setText("Overlay Tab");
        mChildTitleView.setTextSize(13);
        mChildTitleView.setTextColor(android.graphics.Color.parseColor("#f5f5f7"));
        mChildTitleView.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        mChildTitleView.setSingleLine(true);
        mChildTitleView.setEllipsize(android.text.TextUtils.TruncateAt.END);
        android.widget.LinearLayout.LayoutParams titleParams = new android.widget.LinearLayout.LayoutParams(
            0, android.widget.LinearLayout.LayoutParams.WRAP_CONTENT, 1.0f
        );
        mChildTitleView.setLayoutParams(titleParams);

        android.widget.TextView btnExternal = new android.widget.TextView(this);
        btnExternal.setText("↗");
        btnExternal.setTextSize(16);
        btnExternal.setTextColor(android.graphics.Color.parseColor("#7c6cff"));
        btnExternal.setPadding(dpToPx(12), dpToPx(4), dpToPx(6), dpToPx(4));
        btnExternal.setOnClickListener(new android.view.View.OnClickListener() {
            @Override
            public void onClick(android.view.View v) {
                if (mSecondaryWebView != null && mSecondaryWebView.getUrl() != null) {
                    openExternalBrowser(mSecondaryWebView.getUrl());
                }
            }
        });

        header.addView(btnClose);
        header.addView(mChildTitleView);
        header.addView(btnExternal);
        innerLayout.addView(header, new android.widget.LinearLayout.LayoutParams(
            android.widget.LinearLayout.LayoutParams.MATCH_PARENT, android.widget.LinearLayout.LayoutParams.WRAP_CONTENT
        ));

        mSecondaryWebView = new WebView(this);
        WebSettings ws = mSecondaryWebView.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        ws.setDatabaseEnabled(true);
        ws.setAllowFileAccess(true);
        ws.setSupportMultipleWindows(true);
        ws.setJavaScriptCanOpenWindowsAutomatically(true);
        mSecondaryWebView.setLayerType(WebView.LAYER_TYPE_HARDWARE, null);
        applyZoomSettings(mSecondaryWebView);

        mSecondaryWebView.setWebViewClient(new WebViewClient() {
            @Override
            public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
                handler.proceed();
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                if (isExternalOrNewTabLink(view, url)) {
                    handleLinkOpening(url);
                    return true;
                }
                return false;
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, android.webkit.WebResourceRequest request) {
                if (request != null && request.getUrl() != null) {
                    String url = request.getUrl().toString();
                    if (isExternalOrNewTabLink(view, url)) {
                        handleLinkOpening(url);
                        return true;
                    }
                }
                return false;
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                if (mChildTitleView != null) {
                    String title = view.getTitle();
                    mChildTitleView.setText((title != null && !title.isEmpty()) ? title : url);
                }
            }
        });

        mSecondaryWebView.setWebChromeClient(new android.webkit.WebChromeClient() {
            @Override
            public boolean onCreateWindow(WebView view, boolean isDialog, boolean isUserGesture, android.os.Message resultMsg) {
                WebView dummy = new WebView(MainActivity.this);
                dummy.setWebViewClient(new WebViewClient() {
                    @Override
                    public boolean shouldOverrideUrlLoading(WebView wv, String url) {
                        handleLinkOpening(url);
                        return true;
                    }

                    @Override
                    public boolean shouldOverrideUrlLoading(WebView wv, android.webkit.WebResourceRequest request) {
                        if (request != null && request.getUrl() != null) {
                            handleLinkOpening(request.getUrl().toString());
                        }
                        return true;
                    }
                });

                WebView.WebViewTransport transport = (WebView.WebViewTransport) resultMsg.obj;
                transport.setWebView(dummy);
                resultMsg.sendToTarget();
                return true;
            }

            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> filePathCallback, FileChooserParams fileChooserParams) {
                return handleShowFileChooser(filePathCallback, fileChooserParams);
            }
        });

        mSecondaryWebView.addJavascriptInterface(this, "Android");

        mSecondaryWebView.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
                handleDownload(url, userAgent, contentDisposition, mimeType, contentLength);
            }
        });

        innerLayout.addView(mSecondaryWebView, new android.widget.LinearLayout.LayoutParams(
            android.widget.LinearLayout.LayoutParams.MATCH_PARENT, 0, 1.0f
        ));

        mChildOverlayContainer.addView(innerLayout, new android.widget.FrameLayout.LayoutParams(
            android.widget.FrameLayout.LayoutParams.MATCH_PARENT, android.widget.FrameLayout.LayoutParams.MATCH_PARENT
        ));

        rootLayout.addView(mChildOverlayContainer, new android.widget.FrameLayout.LayoutParams(
            android.widget.FrameLayout.LayoutParams.MATCH_PARENT, android.widget.FrameLayout.LayoutParams.MATCH_PARENT
        ));
    }

    private void initFloatingTabBadge(android.widget.FrameLayout rootLayout) {
        mFloatingTabBadge = new android.widget.FrameLayout(this);
        mFloatingTabBadge.setVisibility(android.view.View.GONE);

        android.graphics.drawable.GradientDrawable shape = new android.graphics.drawable.GradientDrawable();
        shape.setShape(android.graphics.drawable.GradientDrawable.RECTANGLE);
        shape.setCornerRadius(dpToPx(8));
        shape.setColor(android.graphics.Color.parseColor("#1a1a26"));
        shape.setStroke(dpToPx(1), android.graphics.Color.parseColor("#7c6cff"));
        mFloatingTabBadge.setBackground(shape);

        mFloatingTabCount = new android.widget.TextView(this);
        mFloatingTabCount.setText("1");
        mFloatingTabCount.setTextSize(13);
        mFloatingTabCount.setTextColor(android.graphics.Color.WHITE);
        mFloatingTabCount.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        mFloatingTabCount.setGravity(android.view.Gravity.CENTER);

        android.widget.FrameLayout.LayoutParams countParams = new android.widget.FrameLayout.LayoutParams(
            android.widget.FrameLayout.LayoutParams.MATCH_PARENT, android.widget.FrameLayout.LayoutParams.MATCH_PARENT
        );
        mFloatingTabBadge.addView(mFloatingTabCount, countParams);

        int size = dpToPx(38);
        android.widget.FrameLayout.LayoutParams badgeParams = new android.widget.FrameLayout.LayoutParams(size, size);
        badgeParams.gravity = android.view.Gravity.BOTTOM | android.view.Gravity.END;
        badgeParams.rightMargin = dpToPx(16);
        badgeParams.bottomMargin = dpToPx(16);

        mFloatingTabBadge.setOnClickListener(new android.view.View.OnClickListener() {
            @Override
            public void onClick(android.view.View v) {
                showTabSwitcherDialog();
            }
        });

        rootLayout.addView(mFloatingTabBadge, badgeParams);
    }

    private void updateTabBadge() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mFloatingTabBadge != null && mFloatingTabCount != null) {
                    int count = mTabList.size();
                    if (count > 1) {
                        mFloatingTabBadge.setVisibility(android.view.View.VISIBLE);
                        mFloatingTabBadge.bringToFront();
                        mFloatingTabCount.setText(String.valueOf(count));
                    } else {
                        mFloatingTabBadge.setVisibility(android.view.View.GONE);
                    }
                }
            }
        });
    }

    private void openInChildOverlay(final String url) {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mChildOverlayContainer != null && mSecondaryWebView != null) {
                    mSecondaryWebView.loadUrl(url);
                    mChildOverlayContainer.setVisibility(android.view.View.VISIBLE);
                    mChildOverlayContainer.bringToFront();
                    if (mFloatingTabBadge != null) {
                        mFloatingTabBadge.bringToFront();
                    }
                    vibrate(15);
                    saveTabSessionState();
                }
            }
        });
    }

    @JavascriptInterface
    public void closeChildOverlay() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mChildOverlayContainer != null) {
                    mChildOverlayContainer.setVisibility(android.view.View.GONE);
                }
                if (mSecondaryWebView != null) {
                    mSecondaryWebView.loadUrl("about:blank");
                }
                vibrate(15);
                saveTabSessionState();
            }
        });
    }

    private void openExternalBrowser(String url) {
        try {
            android.content.Intent intent;
            if (url != null && url.startsWith("intent://")) {
                intent = android.content.Intent.parseUri(url, android.content.Intent.URI_INTENT_SCHEME);
            } else {
                intent = new android.content.Intent(android.content.Intent.ACTION_VIEW, android.net.Uri.parse(url));
            }
            if (intent != null) {
                intent.addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK);
                startActivity(intent);
            }
        } catch (Exception error) {
            android.widget.Toast.makeText(this, "Unable to launch external application", android.widget.Toast.LENGTH_SHORT).show();
        }
    }

    private boolean isExternalOrNewTabLink(WebView view, String targetUrl) {
        if (targetUrl == null || targetUrl.trim().isEmpty()) return false;
        if (targetUrl.startsWith("file://") || targetUrl.startsWith("about:") || targetUrl.startsWith("javascript:") || targetUrl.startsWith("intent://")) return false;
        
        String currentUrl = view.getUrl();
        if (currentUrl == null || currentUrl.trim().isEmpty() || currentUrl.equals("about:blank")) {
            return false;
        }

        try {
            android.net.Uri currentUri = android.net.Uri.parse(currentUrl);
            android.net.Uri targetUri = android.net.Uri.parse(targetUrl);

            String currentHost = currentUri.getHost();
            String targetHost = targetUri.getHost();

            if (targetHost != null && currentHost != null) {
                if (!targetHost.equalsIgnoreCase(currentHost)) {
                    return true;
                }
            }
        } catch (Exception ignored) {}

        return false;
    }

    private void handleLinkOpening(final String url) {
        if (url == null || url.trim().isEmpty() || url.startsWith("javascript:")) return;

        SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
        boolean multiTabMode = urlPrefs.getBoolean("multi_tab_mode", false);
        String linkMode = urlPrefs.getString("link_mode", "prompt");

        if (multiTabMode) {
            createNewTab(url);
            return;
        }

        if ("external".equals(linkMode)) {
            openExternalBrowser(url);
        } else if ("overlay".equals(linkMode)) {
            openInChildOverlay(url);
        } else {
            // "prompt" (Default Option A + C)
            runOnUiThread(new Runnable() {
                @Override
                public void run() {
                    new android.app.AlertDialog.Builder(MainActivity.this, android.R.style.Theme_DeviceDefault_Dialog_Alert)
                        .setTitle("Open External Link")
                        .setMessage(url)
                        .setPositiveButton("Overlay Tab", new android.content.DialogInterface.OnClickListener() {
                            @Override
                            public void onClick(android.content.DialogInterface dialog, int which) {
                                openInChildOverlay(url);
                            }
                        })
                        .setNeutralButton("External Browser", new android.content.DialogInterface.OnClickListener() {
                            @Override
                            public void onClick(android.content.DialogInterface dialog, int which) {
                                openExternalBrowser(url);
                            }
                        })
                        .setNegativeButton("Cancel", null)
                        .show();
                }
            });
        }
    }

    private String getDisplayTitle(TabItem item) {
        if (item == null) return "New Tab";
        if (item.title != null && !item.title.trim().isEmpty() && !"New Tab".equalsIgnoreCase(item.title.trim())) {
            return item.title.trim();
        }
        if (item.webView != null) {
            String webTitle = item.webView.getTitle();
            if (webTitle != null && !webTitle.trim().isEmpty() && !"about:blank".equalsIgnoreCase(webTitle.trim())) {
                item.title = webTitle.trim();
                return item.title;
            }
        }
        if (item.url != null && !item.url.trim().isEmpty()) {
            try {
                android.net.Uri uri = android.net.Uri.parse(item.url);
                String host = uri.getHost();
                if (host != null && !host.isEmpty()) return host;
                String lastSeg = uri.getLastPathSegment();
                if (lastSeg != null && !lastSeg.isEmpty()) return lastSeg;
            } catch (Exception ignored) {}
            return item.url;
        }
        return "New Tab";
    }

    private android.graphics.Bitmap captureWebViewThumbnail(WebView webView, int widthPx, int heightPx) {
        if (webView == null || webView.getWidth() <= 0 || webView.getHeight() <= 0) return null;
        try {
            android.graphics.Bitmap bitmap = android.graphics.Bitmap.createBitmap(widthPx, heightPx, android.graphics.Bitmap.Config.ARGB_8888);
            android.graphics.Canvas canvas = new android.graphics.Canvas(bitmap);
            float scaleX = (float) widthPx / webView.getWidth();
            float scaleY = (float) heightPx / webView.getHeight();
            canvas.scale(scaleX, scaleY);
            webView.draw(canvas);
            return bitmap;
        } catch (Exception e) {
            return null;
        }
    }

    private WebView createTabWebView() {
        WebView v = new WebView(this);
        WebSettings webSettings = v.getSettings();
        webSettings.setJavaScriptEnabled(true);
        webSettings.setDomStorageEnabled(true);
        webSettings.setDatabaseEnabled(true);
        webSettings.setCacheMode(WebSettings.LOAD_DEFAULT);
        webSettings.setSupportMultipleWindows(true);
        webSettings.setJavaScriptCanOpenWindowsAutomatically(true);
        v.setLayerType(WebView.LAYER_TYPE_HARDWARE, null);
        applyZoomSettings(v);

        v.setWebViewClient(new WebViewClient() {
            @Override
            public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
                handler.proceed();
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                if (isExternalOrNewTabLink(view, url)) {
                    handleLinkOpening(url);
                    return true;
                }
                return false;
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, android.webkit.WebResourceRequest request) {
                if (request != null && request.getUrl() != null) {
                    String url = request.getUrl().toString();
                    if (isExternalOrNewTabLink(view, url)) {
                        handleLinkOpening(url);
                        return true;
                    }
                }
                return false;
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                for (TabItem item : mTabList) {
                    if (item.webView == view) {
                        String pageTitle = view.getTitle();
                        if (pageTitle != null && !pageTitle.trim().isEmpty()) {
                            item.title = pageTitle.trim();
                        }
                        item.url = url;
                        break;
                    }
                }
            }
        });

        v.setWebChromeClient(new android.webkit.WebChromeClient() {
            @Override
            public void onReceivedTitle(WebView view, String title) {
                super.onReceivedTitle(view, title);
                if (title != null && !title.trim().isEmpty()) {
                    for (TabItem item : mTabList) {
                        if (item.webView == view) {
                            item.title = title.trim();
                            break;
                        }
                    }
                }
            }

            @Override
            public boolean onCreateWindow(WebView view, boolean isDialog, boolean isUserGesture, android.os.Message resultMsg) {
                WebView dummy = new WebView(MainActivity.this);
                dummy.setWebViewClient(new WebViewClient() {
                    @Override
                    public boolean shouldOverrideUrlLoading(WebView wv, String url) {
                        handleLinkOpening(url);
                        return true;
                    }

                    @Override
                    public boolean shouldOverrideUrlLoading(WebView wv, android.webkit.WebResourceRequest request) {
                        if (request != null && request.getUrl() != null) {
                            handleLinkOpening(request.getUrl().toString());
                        }
                        return true;
                    }
                });

                WebView.WebViewTransport transport = (WebView.WebViewTransport) resultMsg.obj;
                transport.setWebView(dummy);
                resultMsg.sendToTarget();
                return true;
            }

            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> filePathCallback, FileChooserParams fileChooserParams) {
                return handleShowFileChooser(filePathCallback, fileChooserParams);
            }
        });

        v.addJavascriptInterface(this, "Android");

        v.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
                handleDownload(url, userAgent, contentDisposition, mimeType, contentLength);
            }
        });

        return v;
    }

    private void createNewTab(String url) {
        WebView newWeb = createTabWebView();
        final TabItem tab = new TabItem(newWeb, url);
        mTabList.add(tab);

        mWebViewContainer.addView(newWeb, new android.widget.FrameLayout.LayoutParams(
            android.widget.FrameLayout.LayoutParams.MATCH_PARENT, android.widget.FrameLayout.LayoutParams.MATCH_PARENT
        ));

        switchToTab(mTabList.size() - 1);
        newWeb.loadUrl(url);
        updateTabBadge();
        saveTabSessionState();
    }

    private void switchToTab(int index) {
        if (index < 0 || index >= mTabList.size()) return;
        mActiveTabIndex = index;
        for (int i = 0; i < mTabList.size(); i++) {
            mTabList.get(i).webView.setVisibility(i == index ? android.view.View.VISIBLE : android.view.View.GONE);
        }
        saveTabSessionState();
    }

    private void closeTab(int index) {
        if (index < 0 || index >= mTabList.size() || mTabList.size() <= 1) return;

        TabItem item = mTabList.remove(index);
        mWebViewContainer.removeView(item.webView);
        item.webView.destroy();

        if (mActiveTabIndex >= mTabList.size()) {
            mActiveTabIndex = mTabList.size() - 1;
        }

        switchToTab(mActiveTabIndex);
        updateTabBadge();
        saveTabSessionState();
    }

    private void showTabSwitcherDialog() {
        final android.app.Dialog dialog = new android.app.Dialog(this, android.R.style.Theme_DeviceDefault_Dialog_NoActionBar);
        dialog.requestWindowFeature(Window.FEATURE_NO_TITLE);

        final SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
        final boolean isGridView = "grid".equals(urlPrefs.getString("tab_switcher_mode", "list"));

        android.widget.LinearLayout root = new android.widget.LinearLayout(this);
        root.setOrientation(android.widget.LinearLayout.VERTICAL);
        root.setBackgroundColor(android.graphics.Color.parseColor("#12121a"));
        root.setPadding(dpToPx(16), dpToPx(16), dpToPx(16), dpToPx(16));

        // Top Header Bar
        android.widget.LinearLayout header = new android.widget.LinearLayout(this);
        header.setOrientation(android.widget.LinearLayout.HORIZONTAL);
        header.setGravity(android.view.Gravity.CENTER_VERTICAL);
        header.setPadding(0, 0, 0, dpToPx(12));

        android.widget.TextView titleView = new android.widget.TextView(this);
        titleView.setText("Open Tabs (" + mTabList.size() + ")");
        titleView.setTextSize(16);
        titleView.setTextColor(android.graphics.Color.parseColor("#f5f5f7"));
        titleView.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        android.widget.LinearLayout.LayoutParams titleParams = new android.widget.LinearLayout.LayoutParams(0, android.view.ViewGroup.LayoutParams.WRAP_CONTENT, 1.0f);
        titleView.setLayoutParams(titleParams);

        // View Mode Toggle Button
        android.widget.TextView btnToggleView = new android.widget.TextView(this);
        btnToggleView.setText(isGridView ? "☰ List" : "⊞ Grid");
        btnToggleView.setTextSize(12);
        btnToggleView.setTextColor(android.graphics.Color.parseColor("#7c6cff"));
        btnToggleView.setPadding(dpToPx(8), dpToPx(4), dpToPx(8), dpToPx(4));
        btnToggleView.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        btnToggleView.setOnClickListener(new android.view.View.OnClickListener() {
            @Override
            public void onClick(android.view.View v) {
                urlPrefs.edit().putString("tab_switcher_mode", isGridView ? "list" : "grid").apply();
                dialog.dismiss();
                showTabSwitcherDialog();
            }
        });

        // Header Close Button [ ✕ ]
        android.widget.TextView btnHeaderClose = new android.widget.TextView(this);
        btnHeaderClose.setText("✕");
        btnHeaderClose.setTextSize(18);
        btnHeaderClose.setTextColor(android.graphics.Color.parseColor("#a1a1aa"));
        btnHeaderClose.setPadding(dpToPx(12), dpToPx(4), dpToPx(4), dpToPx(4));
        btnHeaderClose.setOnClickListener(new android.view.View.OnClickListener() {
            @Override
            public void onClick(android.view.View v) {
                dialog.dismiss();
            }
        });

        header.addView(titleView);
        header.addView(btnToggleView);
        header.addView(btnHeaderClose);
        root.addView(header);

        android.widget.ScrollView scrollView = new android.widget.ScrollView(this);
        scrollView.setFillViewport(true);

        if (isGridView) {
            // Grid View (2 Columns with Thumbnails)
            android.widget.GridLayout grid = new android.widget.GridLayout(this);
            grid.setColumnCount(2);
            grid.setUseDefaultMargins(true);

            for (int i = 0; i < mTabList.size(); i++) {
                final int tabIndex = i;
                final TabItem t = mTabList.get(i);
                boolean isActive = (i == mActiveTabIndex);

                android.widget.LinearLayout card = new android.widget.LinearLayout(this);
                card.setOrientation(android.widget.LinearLayout.VERTICAL);
                card.setPadding(dpToPx(6), dpToPx(6), dpToPx(6), dpToPx(6));

                android.graphics.drawable.GradientDrawable cardBg = new android.graphics.drawable.GradientDrawable();
                cardBg.setCornerRadius(dpToPx(10));
                cardBg.setColor(android.graphics.Color.parseColor(isActive ? "#1e1b4b" : "#1a1a24"));
                cardBg.setStroke(dpToPx(isActive ? 2 : 1), android.graphics.Color.parseColor(isActive ? "#7c6cff" : "#2c2c3e"));
                card.setBackground(cardBg);

                android.widget.LinearLayout cardHeader = new android.widget.LinearLayout(this);
                cardHeader.setOrientation(android.widget.LinearLayout.HORIZONTAL);
                cardHeader.setGravity(android.view.Gravity.CENTER_VERTICAL);

                android.widget.TextView cardTitle = new android.widget.TextView(this);
                cardTitle.setText(getDisplayTitle(t));
                cardTitle.setTextSize(11);
                cardTitle.setTextColor(android.graphics.Color.parseColor("#f5f5f7"));
                cardTitle.setSingleLine(true);
                cardTitle.setEllipsize(android.text.TextUtils.TruncateAt.END);
                cardTitle.setLayoutParams(new android.widget.LinearLayout.LayoutParams(0, android.view.ViewGroup.LayoutParams.WRAP_CONTENT, 1.0f));

                android.widget.TextView btnTabClose = new android.widget.TextView(this);
                btnTabClose.setText("✕");
                btnTabClose.setTextSize(13);
                btnTabClose.setTextColor(android.graphics.Color.parseColor("#a1a1aa"));
                btnTabClose.setPadding(dpToPx(6), dpToPx(2), dpToPx(2), dpToPx(2));
                btnTabClose.setOnClickListener(new android.view.View.OnClickListener() {
                    @Override
                    public void onClick(android.view.View v) {
                        closeTab(tabIndex);
                        dialog.dismiss();
                        if (mTabList.size() > 1) {
                            showTabSwitcherDialog();
                        }
                    }
                });

                cardHeader.addView(cardTitle);
                if (mTabList.size() > 1) {
                    cardHeader.addView(btnTabClose);
                }
                card.addView(cardHeader);

                android.widget.ImageView thumbView = new android.widget.ImageView(this);
                thumbView.setScaleType(android.widget.ImageView.ScaleType.FIT_CENTER);
                android.graphics.Bitmap thumb = captureWebViewThumbnail(t.webView, dpToPx(130), dpToPx(160));
                if (thumb != null) {
                    thumbView.setImageBitmap(thumb);
                } else {
                    thumbView.setBackgroundColor(android.graphics.Color.parseColor("#08080d"));
                }
                android.widget.LinearLayout.LayoutParams thumbParams = new android.widget.LinearLayout.LayoutParams(dpToPx(130), dpToPx(160));
                thumbParams.topMargin = dpToPx(4);
                card.addView(thumbView, thumbParams);

                card.setOnClickListener(new android.view.View.OnClickListener() {
                    @Override
                    public void onClick(android.view.View v) {
                        switchToTab(tabIndex);
                        dialog.dismiss();
                    }
                });

                grid.addView(card);
            }
            scrollView.addView(grid);
        } else {
            // List View (Vertical Card Rows)
            android.widget.LinearLayout list = new android.widget.LinearLayout(this);
            list.setOrientation(android.widget.LinearLayout.VERTICAL);

            for (int i = 0; i < mTabList.size(); i++) {
                final int tabIndex = i;
                final TabItem t = mTabList.get(i);
                boolean isActive = (i == mActiveTabIndex);

                android.widget.LinearLayout row = new android.widget.LinearLayout(this);
                row.setOrientation(android.widget.LinearLayout.HORIZONTAL);
                row.setGravity(android.view.Gravity.CENTER_VERTICAL);
                row.setPadding(dpToPx(12), dpToPx(10), dpToPx(12), dpToPx(10));

                android.graphics.drawable.GradientDrawable rowBg = new android.graphics.drawable.GradientDrawable();
                rowBg.setCornerRadius(dpToPx(8));
                rowBg.setColor(android.graphics.Color.parseColor(isActive ? "#1e1b4b" : "#1a1a24"));
                rowBg.setStroke(dpToPx(isActive ? 2 : 1), android.graphics.Color.parseColor(isActive ? "#7c6cff" : "#2c2c3e"));
                row.setBackground(rowBg);

                android.widget.LinearLayout.LayoutParams rowParams = new android.widget.LinearLayout.LayoutParams(
                    android.view.ViewGroup.LayoutParams.MATCH_PARENT, android.view.ViewGroup.LayoutParams.WRAP_CONTENT
                );
                rowParams.bottomMargin = dpToPx(8);
                row.setLayoutParams(rowParams);

                android.widget.TextView indicator = new android.widget.TextView(this);
                indicator.setText(isActive ? "✓ " : "  ");
                indicator.setTextColor(android.graphics.Color.parseColor("#7c6cff"));
                indicator.setTextSize(14);

                android.widget.LinearLayout textCol = new android.widget.LinearLayout(this);
                textCol.setOrientation(android.widget.LinearLayout.VERTICAL);
                android.widget.LinearLayout.LayoutParams textParams = new android.widget.LinearLayout.LayoutParams(0, android.view.ViewGroup.LayoutParams.WRAP_CONTENT, 1.0f);
                textCol.setLayoutParams(textParams);

                android.widget.TextView tabTitle = new android.widget.TextView(this);
                tabTitle.setText(getDisplayTitle(t));
                tabTitle.setTextSize(13);
                tabTitle.setTextColor(android.graphics.Color.parseColor("#f5f5f7"));
                tabTitle.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
                tabTitle.setSingleLine(true);
                tabTitle.setEllipsize(android.text.TextUtils.TruncateAt.END);

                android.widget.TextView tabUrl = new android.widget.TextView(this);
                tabUrl.setText(t.url != null ? t.url : "");
                tabUrl.setTextSize(11);
                tabUrl.setTextColor(android.graphics.Color.parseColor("#a1a1aa"));
                tabUrl.setSingleLine(true);
                tabUrl.setEllipsize(android.text.TextUtils.TruncateAt.END);

                textCol.addView(tabTitle);
                textCol.addView(tabUrl);

                row.addView(indicator);
                row.addView(textCol);

                if (mTabList.size() > 1) {
                    android.widget.TextView btnCloseTab = new android.widget.TextView(this);
                    btnCloseTab.setText("✕");
                    btnCloseTab.setTextSize(14);
                    btnCloseTab.setTextColor(android.graphics.Color.parseColor("#a1a1aa"));
                    btnCloseTab.setPadding(dpToPx(10), dpToPx(4), dpToPx(4), dpToPx(4));
                    btnCloseTab.setOnClickListener(new android.view.View.OnClickListener() {
                        @Override
                        public void onClick(android.view.View v) {
                            closeTab(tabIndex);
                            dialog.dismiss();
                            if (mTabList.size() > 1) {
                                showTabSwitcherDialog();
                            }
                        }
                    });
                    row.addView(btnCloseTab);
                }

                row.setOnClickListener(new android.view.View.OnClickListener() {
                    @Override
                    public void onClick(android.view.View v) {
                        switchToTab(tabIndex);
                        dialog.dismiss();
                    }
                });

                list.addView(row);
            }
            scrollView.addView(list);
        }

        android.widget.LinearLayout.LayoutParams scrollParams = new android.widget.LinearLayout.LayoutParams(
            android.view.ViewGroup.LayoutParams.MATCH_PARENT, 0, 1.0f
        );
        scrollParams.bottomMargin = dpToPx(12);
        root.addView(scrollView, scrollParams);

        // Footer Actions
        android.widget.LinearLayout footer = new android.widget.LinearLayout(this);
        footer.setOrientation(android.widget.LinearLayout.HORIZONTAL);

        android.widget.TextView btnNewTab = new android.widget.TextView(this);
        btnNewTab.setText("+ Open New Tab");
        btnNewTab.setTextSize(13);
        btnNewTab.setTextColor(android.graphics.Color.parseColor("#7c6cff"));
        btnNewTab.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        btnNewTab.setPadding(dpToPx(10), dpToPx(8), dpToPx(10), dpToPx(8));
        btnNewTab.setOnClickListener(new android.view.View.OnClickListener() {
            @Override
            public void onClick(android.view.View v) {
                SharedPreferences prefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
                String defaultUrl = prefs.getString("custom_url", "{{WRAPPER_URL}}");
                createNewTab(defaultUrl);
                dialog.dismiss();
            }
        });

        footer.addView(btnNewTab);
        root.addView(footer);

        dialog.setContentView(root);
        if (dialog.getWindow() != null) {
            dialog.getWindow().setLayout(
                (int) (getResources().getDisplayMetrics().widthPixels * 0.90),
                android.view.ViewGroup.LayoutParams.WRAP_CONTENT
            );
        }
        dialog.show();
    }

    private void applyZoomSettings(WebView v) {
        if (v == null) return;
        SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
        boolean forceZoom = urlPrefs.getBoolean("force_zoom", false);
        WebSettings ws = v.getSettings();
        ws.setSupportZoom(forceZoom);
        ws.setBuiltInZoomControls(forceZoom);
        ws.setDisplayZoomControls(false);
    }

    private void applyZoomSettingsToAllWebViews() {
        applyZoomSettings(mWebView);
        if (mSecondaryWebView != null) applyZoomSettings(mSecondaryWebView);
        if (mTabList != null) {
            for (TabItem t : mTabList) {
                if (t.webView != null) applyZoomSettings(t.webView);
            }
        }
    }

    private void enforceViewportZoomJs(WebView view) {
        SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
        if (urlPrefs.getBoolean("force_zoom", false)) {
            String zoomJs = "var metas=document.getElementsByTagName('meta');" +
                "for(var i=0;i<metas.length;i++){" +
                "if(metas[i].getAttribute('name')==='viewport'){" +
                "metas[i].setAttribute('content','width=device-width,initial-scale=1.0,maximum-scale=10.0,user-scalable=yes');" +
                "}" +
                "}";
            view.evaluateJavascript(zoomJs, null);
        }
    }

    @JavascriptInterface
    public String getWrapperSettingsJson() {
        SharedPreferences themePrefs = getSharedPreferences("ThemePrefs", MODE_PRIVATE);
        String themeMode = themePrefs.getString("theme_mode", "system");

        SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
        String customUrl = urlPrefs.getString("custom_url", "{{WRAPPER_URL}}");
        boolean resumeLastUrl = urlPrefs.getBoolean("resume_last_url", false);
        boolean confirmResume = urlPrefs.getBoolean("confirm_resume", false);
        String linkMode = urlPrefs.getString("link_mode", "prompt");
        String statusBarMode = urlPrefs.getString("status_bar_mode", "fullscreen");
        boolean multiTabMode = urlPrefs.getBoolean("multi_tab_mode", false);
        boolean forceZoom = urlPrefs.getBoolean("force_zoom", false);
        boolean shakeToRefresh = urlPrefs.getBoolean("shake_to_refresh", true);

        return "{\"theme\":\"" + escapeJson(themeMode)
            + "\",\"custom_url\":\"" + escapeJson(customUrl)
            + "\",\"resume_last_url\":" + resumeLastUrl
            + ",\"confirm_resume\":" + confirmResume
            + ",\"link_mode\":\"" + escapeJson(linkMode)
            + "\",\"status_bar_mode\":\"" + escapeJson(statusBarMode)
            + "\",\"multi_tab_mode\":" + multiTabMode
            + ",\"force_zoom\":" + forceZoom
            + ",\"shake_to_refresh\":" + shakeToRefresh + "}";
    }

    @JavascriptInterface
    public void saveWrapperSettings(String themeMode, String customUrl, boolean resumeLastUrl, boolean confirmResume, String linkMode, boolean multiTabMode, boolean forceZoom) {
        saveWrapperSettings(themeMode, customUrl, resumeLastUrl, confirmResume, linkMode, "fullscreen", multiTabMode, forceZoom, true);
    }

    @JavascriptInterface
    public void saveWrapperSettings(String themeMode, String customUrl, boolean resumeLastUrl, boolean confirmResume, String linkMode, boolean multiTabMode, boolean forceZoom, boolean shakeToRefresh) {
        saveWrapperSettings(themeMode, customUrl, resumeLastUrl, confirmResume, linkMode, "fullscreen", multiTabMode, forceZoom, shakeToRefresh);
    }

    @JavascriptInterface
    public void saveWrapperSettings(String themeMode, String customUrl, boolean resumeLastUrl, boolean confirmResume, String linkMode, String statusBarMode, boolean multiTabMode, boolean forceZoom) {
        saveWrapperSettings(themeMode, customUrl, resumeLastUrl, confirmResume, linkMode, statusBarMode, multiTabMode, forceZoom, true);
    }

    @JavascriptInterface
    public void saveWrapperSettings(final String themeMode, final String customUrl, final boolean resumeLastUrl, final boolean confirmResume, final String linkMode, final String statusBarMode, final boolean multiTabMode, final boolean forceZoom, final boolean shakeToRefresh) {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                SharedPreferences themePrefs = getSharedPreferences("ThemePrefs", MODE_PRIVATE);
                String oldTheme = themePrefs.getString("theme_mode", "system");

                SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
                String oldCustomUrl = urlPrefs.getString("custom_url", "");
                String oldStatusBarMode = urlPrefs.getString("status_bar_mode", "fullscreen");

                urlPrefs.edit()
                    .putString("custom_url", customUrl != null ? customUrl.trim() : "")
                    .putBoolean("resume_last_url", resumeLastUrl)
                    .putBoolean("confirm_resume", confirmResume)
                    .putString("link_mode", linkMode != null ? linkMode : "prompt")
                    .putString("status_bar_mode", statusBarMode != null ? statusBarMode : "fullscreen")
                    .putBoolean("multi_tab_mode", multiTabMode)
                    .putBoolean("force_zoom", forceZoom)
                    .putBoolean("shake_to_refresh", shakeToRefresh)
                    .putBoolean("overlay_tab_active", false)
                    .putString("overlay_tab_url", "")
                    .apply();

                applyZoomSettingsToAllWebViews();

                if (getIntent() != null) {
                    getIntent().setAction(getPackageName() + ".MAIN");
                }

                if (mChildOverlayContainer != null) {
                    mChildOverlayContainer.setVisibility(android.view.View.GONE);
                }
                if (mSecondaryWebView != null) {
                    mSecondaryWebView.stopLoading();
                    mSecondaryWebView.loadUrl("about:blank");
                }

                vibrate(20);

                if (!oldTheme.equals(themeMode) || !oldStatusBarMode.equals(statusBarMode)) {
                    themePrefs.edit().putString("theme_mode", themeMode).apply();
                    recreate();
                } else {
                    if (customUrl != null && !customUrl.trim().isEmpty() && !customUrl.trim().equalsIgnoreCase(oldCustomUrl.trim())) {
                        loadTargetUrl(customUrl);
                    }
                }
            }
        });
    }

    private boolean isInternalAssetUrl(String url) {
        if (url == null) return false;
        String u = url.trim().toLowerCase(java.util.Locale.US);
        return u.startsWith("file:///android_asset/") || u.contains("settings.html");
    }

    private boolean isEquivalentUrl(String url1, String url2) {
        if (url1 == null || url2 == null) return false;
        String u1 = url1.trim().replaceAll("/+$", "");
        String u2 = url2.trim().replaceAll("/+$", "");
        return u1.equalsIgnoreCase(u2);
    }

    private boolean hasResumableSession(SharedPreferences urlPrefs, String savedUrl) {
        boolean resumeLastUrl = urlPrefs.getBoolean("resume_last_url", false);
        if (!resumeLastUrl) return false;

        String lastVisitedUrl = urlPrefs.getString("last_visited_url", "").trim();
        String savedTabsJson = urlPrefs.getString("last_open_tabs_json", "").trim();
        boolean multiTabMode = urlPrefs.getBoolean("multi_tab_mode", false);
        boolean overlayActive = urlPrefs.getBoolean("overlay_tab_active", false);
        String overlayUrl = urlPrefs.getString("overlay_tab_url", "").trim();

        if (overlayActive && !overlayUrl.isEmpty() && !"about:blank".equalsIgnoreCase(overlayUrl) && !isInternalAssetUrl(overlayUrl)) {
            return true;
        }

        if (multiTabMode && !savedTabsJson.isEmpty() && !savedTabsJson.equals("[]")) {
            try {
                org.json.JSONArray arr = new org.json.JSONArray(savedTabsJson);
                int validUserTabCount = 0;
                for (int i = 0; i < arr.length(); i++) {
                    String tabUrl = arr.getJSONObject(i).optString("url", "").trim();
                    if (!tabUrl.isEmpty() && !"about:blank".equalsIgnoreCase(tabUrl) && !isInternalAssetUrl(tabUrl)) {
                        validUserTabCount++;
                        if (!isEquivalentUrl(tabUrl, savedUrl)) {
                            return true;
                        }
                    }
                }
                if (validUserTabCount > 1) {
                    return true;
                }
            } catch (Exception ignored) {}
        }

        if (!lastVisitedUrl.isEmpty() && !isInternalAssetUrl(lastVisitedUrl) && !isEquivalentUrl(lastVisitedUrl, savedUrl)) {
            return true;
        }

        return false;
    }

    private void saveTabSessionState() {
        SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
        if (!urlPrefs.getBoolean("resume_last_url", false)) return;

        try {
            org.json.JSONArray arr = new org.json.JSONArray();
            for (TabItem item : mTabList) {
                String u = item.url != null ? item.url.trim() : "";
                if (!u.isEmpty() && !"about:blank".equalsIgnoreCase(u) && !isInternalAssetUrl(u)) {
                    org.json.JSONObject obj = new org.json.JSONObject();
                    obj.put("url", u);
                    obj.put("title", item.title != null ? item.title : "");
                    arr.put(obj);
                }
            }

            boolean isOverlayActive = (mChildOverlayContainer != null && mChildOverlayContainer.getVisibility() == android.view.View.VISIBLE);
            String overlayUrl = "";
            if (isOverlayActive && mSecondaryWebView != null && mSecondaryWebView.getUrl() != null) {
                String secUrl = mSecondaryWebView.getUrl().trim();
                if (!secUrl.isEmpty() && !"about:blank".equalsIgnoreCase(secUrl) && !isInternalAssetUrl(secUrl)) {
                    overlayUrl = secUrl;
                } else {
                    isOverlayActive = false;
                }
            } else {
                isOverlayActive = false;
            }

            urlPrefs.edit()
                .putString("last_open_tabs_json", arr.toString())
                .putInt("last_active_tab_index", mActiveTabIndex)
                .putBoolean("overlay_tab_active", isOverlayActive)
                .putString("overlay_tab_url", overlayUrl)
                .apply();
        } catch (Exception ignored) {}
    }

    private void checkAndRestoreOverlayTab() {
        SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
        boolean overlayActive = urlPrefs.getBoolean("overlay_tab_active", false);
        String overlayUrl = urlPrefs.getString("overlay_tab_url", "");

        if (overlayActive && overlayUrl != null && !overlayUrl.trim().isEmpty() && !"about:blank".equalsIgnoreCase(overlayUrl.trim())) {
            openInChildOverlay(overlayUrl.trim());
        }
    }

    private void restoreFullSessionState(final String fallbackUrl) {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
                boolean multiTabMode = urlPrefs.getBoolean("multi_tab_mode", false);
                String savedTabsJson = urlPrefs.getString("last_open_tabs_json", "");
                int lastActiveIndex = urlPrefs.getInt("last_active_tab_index", 0);

                if (multiTabMode && savedTabsJson != null && !savedTabsJson.trim().isEmpty() && !savedTabsJson.equals("[]")) {
                    try {
                        org.json.JSONArray arr = new org.json.JSONArray(savedTabsJson);
                        if (arr.length() > 0) {
                            while (mTabList.size() > 1) {
                                closeTab(1);
                            }

                            for (int i = 0; i < arr.length(); i++) {
                                org.json.JSONObject obj = arr.getJSONObject(i);
                                String url = obj.optString("url", "");
                                String title = obj.optString("title", "");
                                if (!url.isEmpty() && !"about:blank".equalsIgnoreCase(url)) {
                                    if (i == 0) {
                                        mTabList.get(0).url = url;
                                        mTabList.get(0).title = title;
                                        mWebView.loadUrl(url);
                                    } else {
                                        WebView newWeb = createTabWebView();
                                        TabItem tab = new TabItem(newWeb, url);
                                        tab.title = title;
                                        mTabList.add(tab);
                                        mWebViewContainer.addView(newWeb, new android.widget.FrameLayout.LayoutParams(
                                            android.widget.FrameLayout.LayoutParams.MATCH_PARENT,
                                            android.widget.FrameLayout.LayoutParams.MATCH_PARENT
                                        ));
                                        newWeb.loadUrl(url);
                                    }
                                }
                            }

                            int targetIndex = Math.min(lastActiveIndex, mTabList.size() - 1);
                            switchToTab(Math.max(0, targetIndex));
                            updateTabBadge();

                            checkAndRestoreOverlayTab();
                            return;
                        }
                    } catch (Exception ignored) {}
                }

                loadTargetUrl(fallbackUrl);
                checkAndRestoreOverlayTab();
            }
        });
    }

    @JavascriptInterface
    public void loadTargetUrl(final String url) {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                WebView targetWeb = (mTabList != null && !mTabList.isEmpty() && mActiveTabIndex >= 0 && mActiveTabIndex < mTabList.size())
                    ? mTabList.get(mActiveTabIndex).webView
                    : mWebView;

                if (targetWeb != null) {
                    if (url != null && !url.trim().isEmpty() && url.startsWith("http")) {
                        targetWeb.loadUrl(url.trim());
                    } else {
                        SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
                        String defaultUrl = urlPrefs.getString("custom_url", "{{WRAPPER_URL}}");
                        targetWeb.loadUrl(defaultUrl);
                    }
                }
            }
        });
    }

    @SuppressWarnings("deprecation")
    @JavascriptInterface
    public void clearAllSiteData() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mWebView != null) {
                    mWebView.clearCache(true);
                    mWebView.clearHistory();
                    mWebView.clearFormData();
                }
                android.webkit.WebStorage.getInstance().deleteAllData();
                if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.LOLLIPOP) {
                    android.webkit.CookieManager.getInstance().removeAllCookies(null);
                    android.webkit.CookieManager.getInstance().flush();
                } else {
                    android.webkit.CookieManager.getInstance().removeAllCookie();
                }
                vibrate(30);
            }
        });
    }

    @JavascriptInterface
    public void clearCacheOnly() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mWebView != null) {
                    mWebView.clearCache(true);
                }
                vibrate(20);
            }
        });
    }

    @JavascriptInterface
    public void clearWebStorageOnly() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                android.webkit.WebStorage.getInstance().deleteAllData();
                vibrate(20);
            }
        });
    }

    @SuppressWarnings("deprecation")
    @JavascriptInterface
    public void clearCookiesOnly() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.LOLLIPOP) {
                    android.webkit.CookieManager.getInstance().removeAllCookies(null);
                    android.webkit.CookieManager.getInstance().flush();
                } else {
                    android.webkit.CookieManager.getInstance().removeAllCookie();
                }
                vibrate(20);
            }
        });
    }

    @SuppressWarnings("deprecation")
    @JavascriptInterface
    public void vibrate(long ms) {
        try {
            android.os.Vibrator v = (android.os.Vibrator) getSystemService(VIBRATOR_SERVICE);
            if (v != null && v.hasVibrator()) {
                if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.O) {
                    v.vibrate(android.os.VibrationEffect.createOneShot(ms, android.os.VibrationEffect.DEFAULT_AMPLITUDE));
                } else {
                    v.vibrate(ms);
                }
            }
        } catch (Exception ignored) {}
    }

    private String escapeJson(String value) {
        if (value == null) return "";
        return value.replace("\\", "\\\\")
            .replace("\"", "\\\"")
            .replace("\r", "\\r")
            .replace("\n", "\\n");
    }

    private void showResumeConfirmationDialog(final String savedUrl, final String lastVisitedUrl) {
        android.app.AlertDialog.Builder builder = new android.app.AlertDialog.Builder(this, android.R.style.Theme_DeviceDefault_Dialog_Alert);
        builder.setTitle("Resume Session");
        builder.setMessage("Would you like to resume your last session where you left off?");
        builder.setCancelable(false);
        
        builder.setPositiveButton("Yes", new android.content.DialogInterface.OnClickListener() {
            @Override
            public void onClick(android.content.DialogInterface dialog, int which) {
                restoreFullSessionState(lastVisitedUrl);
            }
        });
        
        builder.setNegativeButton("No", new android.content.DialogInterface.OnClickListener() {
            @Override
            public void onClick(android.content.DialogInterface dialog, int which) {
                getSharedPreferences("UrlPrefs", MODE_PRIVATE).edit()
                    .putBoolean("overlay_tab_active", false)
                    .putString("overlay_tab_url", "")
                    .apply();
                closeChildOverlay();
                loadTargetUrl(savedUrl);
            }
        });
        
        final android.app.AlertDialog dialog = builder.create();
        dialog.setOnShowListener(new android.content.DialogInterface.OnShowListener() {
            @Override
            public void onShow(android.content.DialogInterface d) {
                android.widget.Button positiveBtn = dialog.getButton(android.content.DialogInterface.BUTTON_POSITIVE);
                android.widget.Button negativeBtn = dialog.getButton(android.content.DialogInterface.BUTTON_NEGATIVE);
                if (positiveBtn != null) {
                    positiveBtn.setTextColor(android.graphics.Color.parseColor("#6366f1"));
                    positiveBtn.setTypeface(android.graphics.Typeface.create("sans-serif-medium", android.graphics.Typeface.NORMAL));
                    positiveBtn.setAllCaps(false);
                }
                if (negativeBtn != null) {
                    negativeBtn.setTextColor(android.graphics.Color.parseColor("#8e8e93"));
                    negativeBtn.setTypeface(android.graphics.Typeface.create("sans-serif-normal", android.graphics.Typeface.NORMAL));
                    negativeBtn.setAllCaps(false);
                }
            }
        });
        dialog.show();
    }

    private int dpToPx(int dp) {
        float density = getResources().getDisplayMetrics().density;
        return Math.round((float) dp * density);
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);

        if (intent != null && intent.getAction() != null) {
            String action = intent.getAction();
            if (action.endsWith(".OPEN_SETTINGS")) {
                intent.setAction(getPackageName() + ".MAIN");
                showSettingsDialog();
            }
        }
    }

    private void hideLoadingSpinner() {
        if (mProgressBar.getVisibility() == android.view.View.VISIBLE) {
            mProgressBar.animate()
                .alpha(0f)
                .setDuration(300)
                .withEndAction(new Runnable() {
                    @Override
                    public void run() {
                        mProgressBar.setVisibility(android.view.View.GONE);
                    }
                });

            mWebView.animate()
                .alpha(1f)
                .setDuration(400);
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (mWebView != null) {
            mWebView.evaluateJavascript("if(window.onAppResume) window.onAppResume();", null);
        }

        SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
        boolean shakeEnabled = urlPrefs.getBoolean("shake_to_refresh", true);
        if (shakeEnabled && mSensorManager != null) {
            Sensor accel = mSensorManager.getDefaultSensor(Sensor.TYPE_ACCELEROMETER);
            if (accel != null) {
                mSensorManager.registerListener(this, accel, SensorManager.SENSOR_DELAY_UI);
            }
        }
    }

    @Override
    protected void onDestroy() {
        destroyTtsEngine();
        if (mWebView != null) {
            try { mWebView.destroy(); } catch(Exception ignored) {}
        }
        super.onDestroy();
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (mSensorManager != null) {
            mSensorManager.unregisterListener(this);
        }
        saveTabSessionState();
    }

    @Override
    public void onSensorChanged(SensorEvent event) {
        if (event.sensor.getType() == Sensor.TYPE_ACCELEROMETER) {
            float x = event.values[0];
            float y = event.values[1];
            float z = event.values[2];
            mAccelLast = mAccelCurrent;
            mAccelCurrent = (float) Math.sqrt((double) (x * x + y * y + z * z));
            float delta = mAccelCurrent - mAccelLast;
            mAccel = mAccel * 0.9f + delta;

            float gForce = mAccelCurrent / SensorManager.GRAVITY_EARTH;
            if (gForce > SHAKE_THRESHOLD) {
                long now = System.currentTimeMillis();
                if (now - mLastShakeTime > SHAKE_COOLDOWN_MS) {
                    mLastShakeTime = now;
                    onShakeDetected();
                }
            }
        }
    }

    @Override
    public void onAccuracyChanged(Sensor sensor, int accuracy) {
        // Not needed for accelerometer
    }

    private void onShakeDetected() {
        vibrate(15);
        showShakeRefreshCard();
    }

    private void initShakeRefreshCard(android.widget.FrameLayout rootLayout) {
        mShakeRefreshCard = new android.widget.LinearLayout(this);
        mShakeRefreshCard.setOrientation(android.widget.LinearLayout.HORIZONTAL);
        mShakeRefreshCard.setGravity(android.view.Gravity.CENTER_VERTICAL);
        mShakeRefreshCard.setPadding(dpToPx(6), dpToPx(4), dpToPx(6), dpToPx(4));
        mShakeRefreshCard.setVisibility(android.view.View.GONE);

        android.graphics.drawable.GradientDrawable cardBg = new android.graphics.drawable.GradientDrawable();
        cardBg.setCornerRadius(dpToPx(20));
        cardBg.setColor(android.graphics.Color.parseColor("#12121a"));
        cardBg.setStroke(dpToPx(1), android.graphics.Color.parseColor("#7c6cff"));
        mShakeRefreshCard.setBackground(cardBg);

        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.LOLLIPOP) {
            mShakeRefreshCard.setElevation(dpToPx(8));
        }

        // Left Action Pill: Settings / Options
        android.widget.TextView btnSettings = new android.widget.TextView(this);
        btnSettings.setText("⚙️ Settings");
        btnSettings.setTextSize(13);
        btnSettings.setTextColor(android.graphics.Color.parseColor("#f5f5f7"));
        btnSettings.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        btnSettings.setPadding(dpToPx(12), dpToPx(8), dpToPx(12), dpToPx(8));

        btnSettings.setOnClickListener(new android.view.View.OnClickListener() {
            @Override
            public void onClick(android.view.View v) {
                if (mShakeDismissRunnable != null) {
                    mShakeDismissHandler.removeCallbacks(mShakeDismissRunnable);
                }
                hideShakeRefreshCard();
                vibrate(15);
                openSettingsPage();
            }
        });

        // Vertical Divider Line
        android.view.View divider = new android.view.View(this);
        divider.setBackgroundColor(android.graphics.Color.parseColor("#3f3f56"));
        android.widget.LinearLayout.LayoutParams divParams = new android.widget.LinearLayout.LayoutParams(
            dpToPx(1), dpToPx(18)
        );

        // Right Action Pill: Refresh
        android.widget.TextView btnRefresh = new android.widget.TextView(this);
        btnRefresh.setText("🔄 Refresh");
        btnRefresh.setTextSize(13);
        btnRefresh.setTextColor(android.graphics.Color.parseColor("#7c6cff"));
        btnRefresh.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        btnRefresh.setPadding(dpToPx(12), dpToPx(8), dpToPx(12), dpToPx(8));

        btnRefresh.setOnClickListener(new android.view.View.OnClickListener() {
            @Override
            public void onClick(android.view.View v) {
                executeShakeReload();
            }
        });

        mShakeRefreshCard.addView(btnSettings);
        mShakeRefreshCard.addView(divider, divParams);
        mShakeRefreshCard.addView(btnRefresh);

        android.widget.FrameLayout.LayoutParams params = new android.widget.FrameLayout.LayoutParams(
            android.widget.FrameLayout.LayoutParams.WRAP_CONTENT,
            android.widget.FrameLayout.LayoutParams.WRAP_CONTENT
        );
        params.gravity = android.view.Gravity.TOP | android.view.Gravity.CENTER_HORIZONTAL;
        params.topMargin = dpToPx(48);

        rootLayout.addView(mShakeRefreshCard, params);
    }

    private void showShakeRefreshCard() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mShakeRefreshCard == null) return;

                if (mShakeDismissRunnable != null) {
                    mShakeDismissHandler.removeCallbacks(mShakeDismissRunnable);
                }

                mShakeRefreshCard.setVisibility(android.view.View.VISIBLE);
                mShakeRefreshCard.bringToFront();
                if (mFloatingTabBadge != null) {
                    mFloatingTabBadge.bringToFront();
                }

                mShakeRefreshCard.setAlpha(0f);
                mShakeRefreshCard.setTranslationY((float) -dpToPx(20));
                mShakeRefreshCard.animate()
                    .alpha(1f)
                    .translationY(0f)
                    .setDuration(250)
                    .start();

                mShakeDismissRunnable = new Runnable() {
                    @Override
                    public void run() {
                        hideShakeRefreshCard();
                    }
                };
                mShakeDismissHandler.postDelayed(mShakeDismissRunnable, 3000);
            }
        });
    }

    private void hideShakeRefreshCard() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mShakeRefreshCard == null || mShakeRefreshCard.getVisibility() != android.view.View.VISIBLE) return;
                mShakeRefreshCard.animate()
                    .alpha(0f)
                    .translationY((float) -dpToPx(20))
                    .setDuration(250)
                    .withEndAction(new Runnable() {
                        @Override
                        public void run() {
                            mShakeRefreshCard.setVisibility(android.view.View.GONE);
                        }
                    })
                    .start();
            }
        });
    }

    private void executeShakeReload() {
        if (mShakeDismissRunnable != null) {
            mShakeDismissHandler.removeCallbacks(mShakeDismissRunnable);
        }
        hideShakeRefreshCard();
        vibrate(20);

        if (mChildOverlayContainer != null && mChildOverlayContainer.getVisibility() == android.view.View.VISIBLE) {
            if (mSecondaryWebView != null) {
                mSecondaryWebView.reload();
            }
        } else if (mTabList != null && !mTabList.isEmpty() && mActiveTabIndex >= 0 && mActiveTabIndex < mTabList.size()) {
            WebView activeWeb = mTabList.get(mActiveTabIndex).webView;
            if (activeWeb != null) {
                activeWeb.reload();
            }
        } else if (mWebView != null) {
            mWebView.reload();
        }
    }

    @JavascriptInterface
    public void saveBase64File(final String base64Data, final String filename, final String mimeType) {
        if (base64Data == null || base64Data.trim().isEmpty()) return;
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                try {
                    String cleanBase64 = base64Data;
                    if (cleanBase64.contains(",")) {
                        cleanBase64 = cleanBase64.substring(cleanBase64.indexOf(",") + 1);
                    }
                    byte[] fileBytes = Base64.decode(cleanBase64.trim(), Base64.DEFAULT);

                    String safeFilename = (filename != null && !filename.trim().isEmpty()) 
                        ? filename.trim() 
                        : ("download_" + System.currentTimeMillis() + ".bin");

                    File downloadDir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS);
                    if (!downloadDir.exists()) downloadDir.mkdirs();
                    File destFile = new File(downloadDir, safeFilename);

                    try (java.io.FileOutputStream fos = new java.io.FileOutputStream(destFile)) {
                        fos.write(fileBytes);
                    }

                    Toast.makeText(getApplicationContext(), "Saved to Downloads: " + safeFilename, Toast.LENGTH_LONG).show();
                    vibrate(20);
                } catch (Exception e) {
                    Toast.makeText(getApplicationContext(), "Save failed: " + e.getMessage(), Toast.LENGTH_SHORT).show();
                }
            }
        });
    }

    @JavascriptInterface
    public void processBlobDownload(final String dataUrl, final String contentDisposition, final String mimeType) {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                handleDownload(dataUrl, null, contentDisposition, mimeType, 0);
            }
        });
    }

    private void handleDownload(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
        if (url == null || url.trim().isEmpty()) return;

        try {
            if (url.startsWith("blob:")) {
                String safeDisposition = contentDisposition != null ? contentDisposition.replace("'", "\\'") : "";
                String safeMime = mimeType != null ? mimeType.replace("'", "\\'") : "";
                String blobJs = "(function() {" +
                    "var xhr = new XMLHttpRequest();" +
                    "xhr.open('GET', '" + url.replace("'", "\\'") + "', true);" +
                    "xhr.responseType = 'blob';" +
                    "xhr.onload = function() {" +
                    "  if (this.status === 200) {" +
                    "    var reader = new FileReader();" +
                    "    reader.onloadend = function() {" +
                    "      if (window.Android && window.Android.processBlobDownload) {" +
                    "        window.Android.processBlobDownload(reader.result, '" + safeDisposition + "', '" + safeMime + "');" +
                    "      }" +
                    "    };" +
                    "    reader.readAsDataURL(this.response);" +
                    "  }" +
                    "};" +
                    "xhr.send();" +
                    "})()";

                WebView activeWeb = (mChildOverlayContainer != null && mChildOverlayContainer.getVisibility() == android.view.View.VISIBLE && mSecondaryWebView != null)
                    ? mSecondaryWebView
                    : ((mTabList != null && !mTabList.isEmpty() && mActiveTabIndex >= 0 && mActiveTabIndex < mTabList.size()) ? mTabList.get(mActiveTabIndex).webView : mWebView);

                if (activeWeb != null) {
                    activeWeb.evaluateJavascript(blobJs, null);
                }
                return;
            }

            if (url.startsWith("data:")) {
                int commaIndex = url.indexOf(",");
                if (commaIndex > 0) {
                    String header = url.substring(0, commaIndex);
                    String dataStr = url.substring(commaIndex + 1);
                    boolean isBase64 = header.contains(";base64");
                    byte[] data = isBase64 ? Base64.decode(dataStr, Base64.DEFAULT) : dataStr.getBytes("UTF-8");

                    String guessedMime = mimeType;
                    if (guessedMime == null || guessedMime.isEmpty() || guessedMime.equals("text/plain")) {
                        if (header.startsWith("data:")) {
                            int semi = header.indexOf(";");
                            if (semi > 5) guessedMime = header.substring(5, semi);
                        }
                    }
                    String filename = null;
                    if (contentDisposition != null && contentDisposition.contains("filename=")) {
                        int idx = contentDisposition.indexOf("filename=");
                        filename = contentDisposition.substring(idx + 9).replace("\"", "").replace("'", "").trim();
                        if (filename.contains(";")) {
                            filename = filename.substring(0, filename.indexOf(";")).trim();
                        }
                    }
                    if (filename == null || filename.isEmpty() || filename.startsWith("data:") || filename.startsWith("vnd.")) {
                        filename = URLUtil.guessFileName(url, contentDisposition, guessedMime);
                        if (filename == null || filename.startsWith("data:") || filename.startsWith("vnd.")) {
                            filename = "download_" + System.currentTimeMillis();
                            if (guessedMime != null && guessedMime.contains("/")) {
                                String ext = guessedMime.substring(guessedMime.indexOf("/") + 1);
                                if (ext.contains("+") || ext.contains(".")) ext = "bin";
                                filename += "." + ext;
                            }
                        }
                    }

                    File downloadDir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS);
                    if (!downloadDir.exists()) downloadDir.mkdirs();
                    File destFile = new File(downloadDir, filename);

                    try (java.io.FileOutputStream fos = new java.io.FileOutputStream(destFile)) {
                        fos.write(data);
                    }

                    Toast.makeText(getApplicationContext(), "Saved to Downloads: " + filename, Toast.LENGTH_LONG).show();
                    vibrate(20);
                }
                return;
            }

            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
            String filename = URLUtil.guessFileName(url, contentDisposition, mimeType);

            if (mimeType != null && !mimeType.trim().isEmpty()) {
                request.setMimeType(mimeType);
            }

            String cookies = android.webkit.CookieManager.getInstance().getCookie(url);
            if (cookies != null) {
                request.addRequestHeader("cookie", cookies);
            }
            if (userAgent != null && !userAgent.trim().isEmpty()) {
                request.addRequestHeader("User-Agent", userAgent);
            }

            request.setDescription("Downloading file...");
            request.setTitle(filename);
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, filename);

            DownloadManager dm = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
            if (dm != null) {
                dm.enqueue(request);
                Toast.makeText(getApplicationContext(), "Downloading: " + filename, Toast.LENGTH_LONG).show();
                vibrate(20);
            }
        } catch (Exception e) {
            Toast.makeText(getApplicationContext(), "Download failed: " + e.getMessage(), Toast.LENGTH_SHORT).show();
        }
    }

    private boolean handleShowFileChooser(ValueCallback<Uri[]> filePathCallback, android.webkit.WebChromeClient.FileChooserParams fileChooserParams) {
        if (mFilePathCallback != null) {
            mFilePathCallback.onReceiveValue(null);
            mFilePathCallback = null;
        }
        mFilePathCallback = filePathCallback;

        Intent intent = null;
        if (fileChooserParams != null) {
            try {
                intent = fileChooserParams.createIntent();
            } catch (Exception ignored) {}
        }

        if (intent == null) {
            intent = new Intent(Intent.ACTION_GET_CONTENT);
            intent.addCategory(Intent.CATEGORY_OPENABLE);
            intent.setType("*/*");
            intent = Intent.createChooser(intent, "Select File");
        }

        try {
            startActivityForResult(intent, FILECHOOSER_RESULTCODE);
            return true;
        } catch (Exception e) {
            if (mFilePathCallback != null) {
                mFilePathCallback.onReceiveValue(null);
                mFilePathCallback = null;
            }
            android.widget.Toast.makeText(this, "No file chooser application found.", android.widget.Toast.LENGTH_SHORT).show();
            return false;
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        if (requestCode == FILECHOOSER_RESULTCODE) {
            if (mFilePathCallback == null) {
                super.onActivityResult(requestCode, resultCode, data);
                return;
            }
            Uri[] results = null;
            if (resultCode == Activity.RESULT_OK && data != null) {
                String dataString = data.getDataString();
                if (dataString != null) {
                    results = new Uri[]{Uri.parse(dataString)};
                } else if (data.getClipData() != null) {
                    int count = data.getClipData().getItemCount();
                    results = new Uri[count];
                    for (int i = 0; i < count; i++) {
                        results[i] = data.getClipData().getItemAt(i).getUri();
                    }
                }
            }
            mFilePathCallback.onReceiveValue(results);
            mFilePathCallback = null;
        } else {
            super.onActivityResult(requestCode, resultCode, data);
        }
    }

    @Override
    public void onBackPressed() {
        // 1. Check if Child Overlay is active
        if (mChildOverlayContainer != null && mChildOverlayContainer.getVisibility() == android.view.View.VISIBLE) {
            if (mSecondaryWebView != null && mSecondaryWebView.canGoBack()) {
                mSecondaryWebView.goBack();
            } else {
                closeChildOverlay();
            }
            return;
        }

        // 2. Check active tab inside Multi-Tab Mode
        if (mTabList != null && !mTabList.isEmpty() && mActiveTabIndex >= 0 && mActiveTabIndex < mTabList.size()) {
            WebView activeWeb = mTabList.get(mActiveTabIndex).webView;
            if (activeWeb.canGoBack()) {
                activeWeb.goBack();
                return;
            } else if (mTabList.size() > 1 && mActiveTabIndex > 0) {
                closeTab(mActiveTabIndex);
                return;
            }
        }

        // 3. Main primary webview back navigation
        if (mWebView != null && mWebView.canGoBack()) {
            mWebView.goBack();
        }
    }
}