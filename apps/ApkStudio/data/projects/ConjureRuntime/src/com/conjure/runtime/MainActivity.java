package com.conjure.runtime;

import android.animation.ObjectAnimator;
import android.animation.ValueAnimator;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.DialogInterface;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.SharedPreferences;
import android.app.DownloadManager;
import android.hardware.Sensor;
import android.hardware.SensorEvent;
import android.hardware.SensorEventListener;
import android.hardware.SensorManager;
import android.content.pm.PackageManager;
import android.content.pm.ShortcutInfo;
import android.content.pm.ShortcutManager;
import android.util.Base64;
import android.webkit.DownloadListener;
import android.webkit.PermissionRequest;
import android.webkit.URLUtil;
import android.content.res.ColorStateList;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Paint;
import android.graphics.Typeface;
import android.graphics.drawable.Icon;
import android.net.Uri;
import android.net.http.SslError;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.os.PowerManager;
import android.os.Vibrator;
import android.os.VibrationEffect;
import android.provider.Settings;
import android.view.Gravity;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.webkit.JsResult;
import android.webkit.SslErrorHandler;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import java.io.BufferedReader;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.FileReader;
import java.io.FilterInputStream;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

public class MainActivity extends Activity {
    private static final int ZIP_PICKER_REQUEST = 4101;
    private static final int RECORD_AUDIO_REQUEST_CODE = 4103;
    private static final int FOLDER_PICKER_REQUEST = 4104;
    private static final int FILE_CHOOSER_REQUEST_CODE = 4105;
    private static final long MAX_REMOTE_ZIP_BYTES = 512L * 1024L * 1024L;

    private PermissionRequest mPendingPermissionRequest;
    private android.webkit.ValueCallback<Uri[]> mFilePathCallback;

    private FrameLayout mContainerLayout;
    private WebView dashboardWebView;
    private WebView wrapperWebView;
    private ProgressBar mProgressBar;
    private boolean isWrapperMode = false;

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

    private FrameLayout mWrapperWebViewContainer;
    private FrameLayout mChildOverlayContainer;
    private WebView mSecondaryWebView;
    private TextView mChildTitleView;

    private FrameLayout mFloatingTabBadge;
    private TextView mFloatingTabCount;

    private FrameLayout mSplashLayout;
    private TextView mSplashTitle;
    private TextView mSplashStatus;
    private View mSplashLogo;
    private ProgressBar mSplashProgress;
    private ObjectAnimator mPulseAnimatorX;
    private ObjectAnimator mPulseAnimatorY;

    private SharedPreferences statePreferences;
    private volatile boolean remoteDownloadInProgress;

    private SensorManager mSensorManager;
    private Sensor mAccelerometer;
    private long mLastShakeTimestamp = 0;
    private FrameLayout mShakePromptBanner;
    private Handler mShakeBannerHandler = new Handler(Looper.getMainLooper());
    private Runnable mHideShakeBannerRunnable;

    private final SensorEventListener mSensorListener = new SensorEventListener() {
        @Override
        public void onSensorChanged(SensorEvent event) {
            if (event == null || event.sensor == null || event.sensor.getType() != Sensor.TYPE_ACCELEROMETER) return;

            SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
            if (!urlPrefs.getBoolean("shake_to_refresh", false)) return;

            float x = event.values[0];
            float y = event.values[1];
            float z = event.values[2];

            float gX = x / SensorManager.GRAVITY_EARTH;
            float gY = y / SensorManager.GRAVITY_EARTH;
            float gZ = z / SensorManager.GRAVITY_EARTH;

            float gForce = (float) Math.sqrt(gX * gX + gY * gY + gZ * gZ);

            if (gForce > 2.2f) {
                long now = System.currentTimeMillis();
                if (now - mLastShakeTimestamp > 1500) {
                    mLastShakeTimestamp = now;
                    vibrate(30);
                    showShakeRefreshPrompt();
                }
            }
        }

        @Override
        public void onAccuracyChanged(Sensor sensor, int accuracy) {}
    };
    private volatile String runtimeStatus = "STOPPED";
    private volatile String runtimeMessage = "Runtime services are not running.";
    private volatile String installTitle = "Waiting for package";
    private volatile String installMessage = "The shared runtime folder will be created automatically.";
    private volatile String installType = "";

    private final BroadcastReceiver runtimeStatusReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            if (intent == null || !"com.conjure.runtime.RUNTIME_STATUS".equals(intent.getAction())) {
                return;
            }

            String status = intent.getStringExtra("status");
            String message = intent.getStringExtra("message");
            updateRuntimeStatus(
                status == null ? "STOPPED" : status,
                message == null ? "Runtime status unavailable." : message
            );
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        installTitle = statePreferences.getString("install_title", installTitle);
        installMessage = statePreferences.getString("install_message", installMessage);
        installType = statePreferences.getString("install_type", installType);
        runtimeStatus = statePreferences.getString("runtime_status", runtimeStatus);
        runtimeMessage = statePreferences.getString("runtime_message", runtimeMessage);

        IntentFilter runtimeFilter = new IntentFilter("com.conjure.runtime.RUNTIME_STATUS");
        if (Build.VERSION.SDK_INT >= 33) {
            registerReceiver(runtimeStatusReceiver, runtimeFilter, 4);
        } else {
            registerReceiver(runtimeStatusReceiver, runtimeFilter);
        }

        File existingRoot = new File(Environment.getExternalStorageDirectory(), "Conjure OS");
        File existingIndex = new File(existingRoot, "index.php");
        File existingApp = new File(existingRoot, "app");
        File existingApps = new File(existingRoot, "apps");

        if (existingIndex.isFile()
                && existingIndex.length() > 0
                && (existingApp.isDirectory() || existingApps.isDirectory())) {
            installTitle = "Conjure OS installation found";
            installMessage = String.format(Locale.US, "Existing files are ready at %s", existingRoot.getAbsolutePath());
            installType = "success";
            persistInstallStatus();
        }

        RuntimeService activeService = RuntimeService.getInstance();
        if (activeService == null) {
            runtimeStatus = "STOPPED";
            runtimeMessage = "Runtime services are not running.";
            if (statePreferences != null) {
                statePreferences.edit()
                    .putString("runtime_status", "STOPPED")
                    .putString("runtime_message", runtimeMessage)
                    .commit();
            }
        }

        initMasterContainerLayout();
        registerLauncherShortcuts();
        requestSharedStorageAccessIfNeeded();
        checkAutoStartOnLaunch();

        Intent intent = getIntent();
        String action = intent != null ? intent.getAction() : null;
        String pkg = getPackageName();

        boolean openConjureOsByDefault = statePreferences.getBoolean("open_conjure_os_by_default", false);

        if ((pkg + ".OPEN_CONJURE_OS").equals(action)) {
            loadConjureOsWrapperView(false);
        } else if ((pkg + ".OPEN_RUNTIME_SETTINGS").equals(action)) {
            loadDashboardView();
        } else if ((pkg + ".OPEN_WRAPPER_SETTINGS").equals(action)) {
            intent.setAction(pkg + ".MAIN");
            if (openConjureOsByDefault) {
                loadConjureOsWrapperView(true);
            } else {
                loadDashboardView();
                showWrapperSettingsDialog();
            }
        } else if (openConjureOsByDefault) {
            loadConjureOsWrapperView(false);
        } else {
            loadDashboardView();
        }
    }

    private void initMasterContainerLayout() {
        mContainerLayout = new FrameLayout(this);
        mContainerLayout.setBackgroundColor(Color.parseColor("#08080d"));

        dashboardWebView = new WebView(this);
        setupDashboardWebView(dashboardWebView);

        wrapperWebView = new WebView(this);
        setupWrapperWebView(wrapperWebView);

        mProgressBar = new ProgressBar(this, null, android.R.attr.progressBarStyleLarge);
        FrameLayout.LayoutParams pbParams = new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.WRAP_CONTENT,
            FrameLayout.LayoutParams.WRAP_CONTENT
        );
        pbParams.gravity = Gravity.CENTER;
        mProgressBar.setLayoutParams(pbParams);
        mProgressBar.setVisibility(View.GONE);

        mContainerLayout.addView(dashboardWebView, new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT
        ));

        mWrapperWebViewContainer = new FrameLayout(this);
        mContainerLayout.addView(mWrapperWebViewContainer, new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT
        ));

        // Mount Primary Tab 0
        mTabList.add(new TabItem(wrapperWebView, ""));
        mWrapperWebViewContainer.addView(wrapperWebView, new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT
        ));

        // Create Child Overlay Container (Option A)
        initChildOverlayContainer(mContainerLayout);

        // Create Floating Tab Counter Badge (Option B - bottom right)
        initFloatingTabBadge(mContainerLayout);

        // Create Shake Refresh Banner Prompt (Top Center)
        initShakePromptBanner(mContainerLayout);

        mContainerLayout.addView(mProgressBar);

        initSplashScreenLayout();
        mContainerLayout.addView(mSplashLayout, new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT
        ));

        setContentView(mContainerLayout);
    }

    private void initSplashScreenLayout() {
        mSplashLayout = new FrameLayout(this);
        mSplashLayout.setBackgroundColor(Color.parseColor("#08080d"));

        LinearLayout innerContainer = new LinearLayout(this);
        innerContainer.setOrientation(LinearLayout.VERTICAL);
        innerContainer.setGravity(Gravity.CENTER);

        mSplashLogo = new View(this) {
            private final Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG);
            private final Paint glowPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
            private final Paint corePaint = new Paint(Paint.ANTI_ALIAS_FLAG);

            @Override
            protected void onDraw(Canvas canvas) {
                super.onDraw(canvas);
                float cx = getWidth() / 2f;
                float cy = getHeight() / 2f;
                float radius = Math.min(cx, cy) * 0.65f;

                glowPaint.setColor(Color.parseColor("#7c6cff"));
                glowPaint.setAlpha(40);
                canvas.drawCircle(cx, cy, radius * 1.35f, glowPaint);

                paint.setColor(Color.parseColor("#7c6cff"));
                paint.setStyle(Paint.Style.FILL);
                canvas.drawCircle(cx, cy, radius, paint);

                corePaint.setColor(Color.WHITE);
                canvas.drawCircle(cx, cy, radius * 0.35f, corePaint);
            }
        };

        int logoSize = dpToPx(72);
        LinearLayout.LayoutParams logoParams = new LinearLayout.LayoutParams(logoSize, logoSize);
        logoParams.gravity = Gravity.CENTER_HORIZONTAL;
        logoParams.bottomMargin = dpToPx(20);
        innerContainer.addView(mSplashLogo, logoParams);

        mSplashTitle = new TextView(this);
        mSplashTitle.setText("CONJURE OS");
        mSplashTitle.setTextSize(22);
        mSplashTitle.setTextColor(Color.parseColor("#f5f5f7"));
        mSplashTitle.setTypeface(Typeface.create("sans-serif-medium", Typeface.BOLD));
        mSplashTitle.setGravity(Gravity.CENTER);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            mSplashTitle.setLetterSpacing(0.12f);
        }
        LinearLayout.LayoutParams titleParams = new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT
        );
        titleParams.gravity = Gravity.CENTER_HORIZONTAL;
        titleParams.bottomMargin = dpToPx(8);
        innerContainer.addView(mSplashTitle, titleParams);

        mSplashStatus = new TextView(this);
        mSplashStatus.setText("Initializing Runtime Engine...");
        mSplashStatus.setTextSize(12);
        mSplashStatus.setTextColor(Color.parseColor("#a1a1aa"));
        mSplashStatus.setTypeface(Typeface.create("sans-serif", Typeface.NORMAL));
        mSplashStatus.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams statusParams = new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT
        );
        statusParams.gravity = Gravity.CENTER_HORIZONTAL;
        statusParams.bottomMargin = dpToPx(24);
        innerContainer.addView(mSplashStatus, statusParams);

        mSplashProgress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        mSplashProgress.setIndeterminate(true);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            mSplashProgress.setIndeterminateTintList(ColorStateList.valueOf(Color.parseColor("#7c6cff")));
        }
        LinearLayout.LayoutParams progressParams = new LinearLayout.LayoutParams(dpToPx(140), dpToPx(4));
        progressParams.gravity = Gravity.CENTER_HORIZONTAL;
        innerContainer.addView(mSplashProgress, progressParams);

        FrameLayout.LayoutParams containerParams = new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.WRAP_CONTENT, FrameLayout.LayoutParams.WRAP_CONTENT
        );
        containerParams.gravity = Gravity.CENTER;
        mSplashLayout.addView(innerContainer, containerParams);

        startLogoPulseAnimation();
    }

    private void startLogoPulseAnimation() {
        if (mSplashLogo != null) {
            if (mPulseAnimatorX != null) mPulseAnimatorX.cancel();
            if (mPulseAnimatorY != null) mPulseAnimatorY.cancel();

            mPulseAnimatorX = ObjectAnimator.ofFloat(mSplashLogo, "scaleX", 0.92f, 1.08f);
            mPulseAnimatorX.setDuration(1200);
            mPulseAnimatorX.setRepeatCount(ValueAnimator.INFINITE);
            mPulseAnimatorX.setRepeatMode(ValueAnimator.REVERSE);

            mPulseAnimatorY = ObjectAnimator.ofFloat(mSplashLogo, "scaleY", 0.92f, 1.08f);
            mPulseAnimatorY.setDuration(1200);
            mPulseAnimatorY.setRepeatCount(ValueAnimator.INFINITE);
            mPulseAnimatorY.setRepeatMode(ValueAnimator.REVERSE);

            mPulseAnimatorX.start();
            mPulseAnimatorY.start();
        }
    }

    private void showSplashScreen(final String statusText) {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mSplashLayout != null) {
                    mSplashLayout.animate().cancel();
                    mSplashLayout.setAlpha(1.0f);
                    mSplashLayout.setVisibility(View.VISIBLE);
                    mSplashLayout.bringToFront();
                    if (mSplashStatus != null && statusText != null) {
                        mSplashStatus.setText(statusText);
                    }
                    startLogoPulseAnimation();
                }
            }
        });
    }

    private void updateSplashStatus(final String statusText) {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mSplashStatus != null && statusText != null) {
                    mSplashStatus.setText(statusText);
                }
            }
        });
    }

    private void hideSplashScreen() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mSplashLayout != null && mSplashLayout.getVisibility() == View.VISIBLE) {
                    mSplashLayout.animate()
                        .alpha(0f)
                        .setDuration(450)
                        .withEndAction(new Runnable() {
                            @Override
                            public void run() {
                                if (mSplashLayout != null) {
                                    mSplashLayout.setVisibility(View.GONE);
                                }
                                if (mPulseAnimatorX != null) mPulseAnimatorX.cancel();
                                if (mPulseAnimatorY != null) mPulseAnimatorY.cancel();
                            }
                        })
                        .start();
                }
            }
        });
    }

    private void setupDashboardWebView(WebView v) {
        WebSettings settings = v.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setAllowFileAccess(true);

        v.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                if (url != null && (url.startsWith("http://") || url.startsWith("https://"))) {
                    openExternalUrl(url);
                    return true;
                }
                return false;
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (request != null && request.getUrl() != null) {
                    String url = request.getUrl().toString();
                    if (url.startsWith("http://") || url.startsWith("https://")) {
                        openExternalUrl(url);
                        return true;
                    }
                }
                return false;
            }
        });

        v.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onJsAlert(WebView view, String url, String message, final JsResult result) {
                new AlertDialog.Builder(MainActivity.this, android.R.style.Theme_DeviceDefault_Dialog_Alert)
                    .setTitle("Conjure Runtime")
                    .setMessage(message)
                    .setPositiveButton(android.R.string.ok, new DialogInterface.OnClickListener() {
                        @Override
                        public void onClick(DialogInterface dialog, int which) {
                            result.confirm();
                        }
                    })
                    .setOnCancelListener(new DialogInterface.OnCancelListener() {
                        @Override
                        public void onCancel(DialogInterface dialog) {
                            result.confirm();
                        }
                    })
                    .show();
                return true;
            }

            @Override
            public boolean onJsConfirm(WebView view, String url, String message, final JsResult result) {
                new AlertDialog.Builder(MainActivity.this, android.R.style.Theme_DeviceDefault_Dialog_Alert)
                    .setTitle("Overwrite Package?")
                    .setMessage(message)
                    .setPositiveButton("Overwrite", new DialogInterface.OnClickListener() {
                        @Override
                        public void onClick(DialogInterface dialog, int which) {
                            result.confirm();
                        }
                    })
                    .setNegativeButton("Cancel", new DialogInterface.OnClickListener() {
                        @Override
                        public void onClick(DialogInterface dialog, int which) {
                            result.cancel();
                        }
                    })
                    .setOnCancelListener(new DialogInterface.OnCancelListener() {
                        @Override
                        public void onCancel(DialogInterface dialog) {
                            result.cancel();
                        }
                    })
                    .show();
                return true;
            }
        });

        v.addJavascriptInterface(new RuntimeBridge(this), "Android");
    }

    private void setupWrapperWebView(WebView v) {
        WebSettings settings = v.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setAllowFileAccess(true);
        settings.setCacheMode(WebSettings.LOAD_DEFAULT);
        settings.setSupportMultipleWindows(true);
        settings.setJavaScriptCanOpenWindowsAutomatically(true);

        v.setLayerType(WebView.LAYER_TYPE_HARDWARE, null);

        v.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
                handleDownload(url, userAgent, contentDisposition, mimeType, contentLength);
            }
        });

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
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
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
            public void onReceivedError(WebView view, int errorCode, String description, String failingUrl) {
                handleWrapperLoadError(view, failingUrl);
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request != null && request.isForMainFrame()) {
                    handleWrapperLoadError(view, request.getUrl().toString());
                }
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                connectionRetryCount = 0;
                hideLoadingSpinner();
                enforceViewportZoomJs(view);

                if (url != null && !url.contains("settings.html")) {
                    hideSplashScreen();
                }

                if (!mTabList.isEmpty()) {
                    mTabList.get(0).url = url;
                    String title = view.getTitle();
                    if (title != null && !title.trim().isEmpty()) {
                        mTabList.get(0).title = title.trim();
                    }
                }

                SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
                if (urlPrefs.getBoolean("resume_last_url", false) && url != null && url.startsWith("http")) {
                    urlPrefs.edit().putString("last_visited_url", url).apply();
                    saveTabSessionState();
                }
            }
        });

        v.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onShowFileChooser(WebView webView, android.webkit.ValueCallback<Uri[]> filePathCallback, WebChromeClient.FileChooserParams fileChooserParams) {
                if (mFilePathCallback != null) {
                    mFilePathCallback.onReceiveValue(null);
                    mFilePathCallback = null;
                }
                mFilePathCallback = filePathCallback;

                Intent intent = null;
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP && fileChooserParams != null) {
                    try {
                        intent = fileChooserParams.createIntent();
                    } catch (Exception ignored) {}
                }
                if (intent == null) {
                    intent = new Intent(Intent.ACTION_GET_CONTENT);
                    intent.addCategory(Intent.CATEGORY_OPENABLE);
                    intent.setType("*/*");
                }

                try {
                    startActivityForResult(intent, FILE_CHOOSER_REQUEST_CODE);
                } catch (Exception e) {
                    if (mFilePathCallback != null) {
                        mFilePathCallback.onReceiveValue(null);
                        mFilePathCallback = null;
                    }
                    Toast.makeText(MainActivity.this, "File picker error: " + e.getMessage(), Toast.LENGTH_SHORT).show();
                    return false;
                }
                return true;
            }

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
                    public boolean shouldOverrideUrlLoading(WebView wv, WebResourceRequest request) {
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
            public void onPermissionRequest(final PermissionRequest request) {
                runOnUiThread(new Runnable() {
                    @Override
                    public void run() {
                        if (checkSelfPermission(android.Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
                            request.grant(request.getResources());
                        } else {
                            mPendingPermissionRequest = request;
                            requestPermissions(new String[]{android.Manifest.permission.RECORD_AUDIO}, RECORD_AUDIO_REQUEST_CODE);
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
        });

        v.addJavascriptInterface(new RuntimeBridge(this), "Android");
    }

    private boolean isExternalOrNewTabLink(WebView view, String targetUrl) {
        if (targetUrl == null || targetUrl.trim().isEmpty()) return false;
        if (targetUrl.startsWith("file://") || targetUrl.startsWith("about:") || targetUrl.startsWith("javascript:")) return false;
        
        String currentUrl = view.getUrl();
        if (currentUrl == null || currentUrl.trim().isEmpty() || currentUrl.equals("about:blank") || currentUrl.contains("settings.html")) {
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

    public void loadDashboardView() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                isWrapperMode = false;
                restoreDashboardWindowFlags();

                if (wrapperWebView != null) {
                    wrapperWebView.setVisibility(View.GONE);
                }
                if (mProgressBar != null) {
                    mProgressBar.setVisibility(View.GONE);
                }

                if (dashboardWebView != null) {
                    dashboardWebView.setVisibility(View.VISIBLE);
                    String currentUrl = dashboardWebView.getUrl();
                    if (currentUrl == null || !currentUrl.contains("index.html")) {
                        dashboardWebView.loadUrl("file:///android_asset/index.html");
                    }
                }
                hideSplashScreen();
            }
        });
    }

    public void loadConjureOsWrapperView(final boolean openSettingsOnStart) {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                isWrapperMode = true;
                navigatedToSettingsFromWrapper = false;

                applyWrapperFullscreenWindowFlags();
                showSplashScreen("Starting Conjure OS...");

                if (dashboardWebView != null) {
                    dashboardWebView.setVisibility(View.GONE);
                }

                if (mWrapperWebViewContainer != null) {
                    mWrapperWebViewContainer.setVisibility(View.VISIBLE);
                }
                if (wrapperWebView != null) {
                    wrapperWebView.setVisibility(View.VISIBLE);
                }

                SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
                int httpsPort = statePreferences != null ? statePreferences.getInt("https_port", 8000) : 8000;
                String defaultUrl = "https://127.0.0.1:" + httpsPort + "/";
                String savedCustomUrl = urlPrefs.getString("custom_url", "");
                final String savedUrl = savedCustomUrl.isEmpty() ? defaultUrl : updateLoopbackUrlPort(savedCustomUrl, 0, httpsPort);

                boolean confirmResume = urlPrefs.getBoolean("confirm_resume", false);
                final String lastVisitedUrl = urlPrefs.getString("last_visited_url", "");

                boolean hasSavedSession = hasResumableSession(urlPrefs, savedUrl);
                boolean showConfirmDialog = (hasSavedSession && confirmResume && !openSettingsOnStart);

                String targetUrl = savedUrl;
                if (hasSavedSession && !confirmResume) {
                    targetUrl = lastVisitedUrl.isEmpty() ? savedUrl : lastVisitedUrl;
                }

                final String finalTargetUrl = targetUrl;
                final boolean finalShowConfirm = showConfirmDialog;

                RuntimeService activeService = RuntimeService.getInstance();
                boolean isRunning = (activeService != null && activeService.getProcessManager() != null && activeService.getProcessManager().isRunning());

                if (!isRunning) {
                    showSplashScreen("Starting Conjure OS...");
                    startRuntime();
                    waitForServerAndRestoreSession(finalTargetUrl, openSettingsOnStart, finalShowConfirm, savedUrl, lastVisitedUrl);
                } else {
                    if (openSettingsOnStart) {
                        hideSplashScreen();
                        openWrapperSettingsFromUi();
                    } else if (finalShowConfirm) {
                        hideSplashScreen();
                        showResumeConfirmationDialog(savedUrl, lastVisitedUrl);
                    } else {
                        showSplashScreen("Connecting to Conjure OS...");
                        restoreFullSessionState(finalTargetUrl);
                    }
                }
            }
        });
    }

    private void waitForServerAndRestoreSession(final String targetUrl, final boolean openSettingsOnStart, final boolean showConfirmDialog, final String savedUrl, final String lastVisitedUrl) {
        new Thread(new Runnable() {
            @Override
            public void run() {
                int attempts = 0;
                boolean ready = false;
                int httpsPort = statePreferences != null ? statePreferences.getInt("https_port", 8000) : 8000;

                while (attempts < 30 && !ready) {
                    attempts++;
                    if (attempts == 1) {
                        updateSplashStatus("Booting PHP & Nginx...");
                    } else if (attempts == 5) {
                        updateSplashStatus("Binding loopback sockets...");
                    }

                    try {
                        Thread.sleep(300);
                    } catch (InterruptedException ignored) {}

                    RuntimeService service = RuntimeService.getInstance();
                    if (service != null && service.getProcessManager() != null && service.getProcessManager().isRunning()) {
                        try (java.net.Socket s = new java.net.Socket()) {
                            s.connect(new java.net.InetSocketAddress("127.0.0.1", httpsPort), 200);
                            ready = true;
                        } catch (Exception ignored) {}
                    }
                }

                updateSplashStatus("Connecting to Conjure OS...");

                runOnUiThread(new Runnable() {
                    @Override
                    public void run() {
                        if (isWrapperMode) {
                            if (openSettingsOnStart) {
                                hideSplashScreen();
                                openWrapperSettingsFromUi();
                            } else if (showConfirmDialog) {
                                hideSplashScreen();
                                showResumeConfirmationDialog(savedUrl, lastVisitedUrl);
                            } else {
                                restoreFullSessionState(targetUrl);
                            }
                        }
                    }
                });
            }
        }).start();
    }

    private void restoreDashboardWindowFlags() {
        Window window = getWindow();
        window.clearFlags(WindowManager.LayoutParams.FLAG_FULLSCREEN);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            window.clearFlags(WindowManager.LayoutParams.FLAG_TRANSLUCENT_STATUS);
            window.addFlags(WindowManager.LayoutParams.FLAG_DRAWS_SYSTEM_BAR_BACKGROUNDS);
            window.setStatusBarColor(Color.parseColor("#08080d"));
            window.setNavigationBarColor(Color.parseColor("#08080d"));
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            View decor = window.getDecorView();
            int flags = decor.getSystemUiVisibility();
            flags &= ~View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR;
            decor.setSystemUiVisibility(flags);
        }
    }

    private void applyWrapperFullscreenWindowFlags() {
        Window window = getWindow();
        window.setFlags(WindowManager.LayoutParams.FLAG_FULLSCREEN, WindowManager.LayoutParams.FLAG_FULLSCREEN);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            window.getAttributes().layoutInDisplayCutoutMode =
                WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES;
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            window.clearFlags(WindowManager.LayoutParams.FLAG_TRANSLUCENT_STATUS);
            window.addFlags(WindowManager.LayoutParams.FLAG_DRAWS_SYSTEM_BAR_BACKGROUNDS);
            window.setStatusBarColor(Color.TRANSPARENT);
        }

        getWindow().getDecorView().setSystemUiVisibility(
            View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN | View.SYSTEM_UI_FLAG_LAYOUT_STABLE
        );
    }

    private void hideLoadingSpinner() {
        if (mProgressBar != null && mProgressBar.getVisibility() == View.VISIBLE) {
            mProgressBar.animate()
                .alpha(0f)
                .setDuration(300)
                .withEndAction(new Runnable() {
                    @Override
                    public void run() {
                        if (mProgressBar != null) {
                            mProgressBar.setVisibility(View.GONE);
                        }
                    }
                });

            if (wrapperWebView != null) {
                wrapperWebView.animate().alpha(1f).setDuration(400);
            }
        }
    }

    private Icon createShortcutIcon(String emoji, String bgColor) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            int size = 144;
            Bitmap bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888);
            Canvas canvas = new Canvas(bitmap);

            Paint bgPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
            bgPaint.setColor(Color.parseColor(bgColor));
            canvas.drawCircle(size / 2f, size / 2f, size / 2f, bgPaint);

            Paint textPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
            textPaint.setTextSize(72);
            textPaint.setTextAlign(Paint.Align.CENTER);

            int xPos = (canvas.getWidth() / 2);
            int yPos = (int) ((canvas.getHeight() / 2) - ((textPaint.descent() + textPaint.ascent()) / 2));

            canvas.drawText(emoji, xPos, yPos, textPaint);
            return Icon.createWithBitmap(bitmap);
        }
        return null;
    }

    private void registerLauncherShortcuts() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N_MR1) {
            ShortcutManager shortcutManager = getSystemService(ShortcutManager.class);
            if (shortcutManager != null) {
                List<ShortcutInfo> shortcuts = new ArrayList<>();
                String pkg = getPackageName();

                Intent runtimeIntent = new Intent(this, MainActivity.class);
                runtimeIntent.setAction(pkg + ".OPEN_RUNTIME_SETTINGS");
                ShortcutInfo runtimeShortcut = new ShortcutInfo.Builder(this, "shortcut_runtime_settings")
                        .setShortLabel("Runtime Settings")
                        .setLongLabel("PHP, Nginx & Tailscale Dashboard")
                        .setIcon(createShortcutIcon("⚙️", "#08080d"))
                        .setIntent(runtimeIntent)
                        .build();
                shortcuts.add(runtimeShortcut);

                Intent browserIntent = new Intent(this, MainActivity.class);
                browserIntent.setAction(pkg + ".OPEN_WRAPPER_SETTINGS");
                ShortcutInfo browserShortcut = new ShortcutInfo.Builder(this, "shortcut_browser_settings")
                        .setShortLabel("Browser Settings")
                        .setLongLabel("Configure theme and URL resume")
                        .setIcon(createShortcutIcon("🛠️", "#08080d"))
                        .setIntent(browserIntent)
                        .build();
                shortcuts.add(browserShortcut);

                Intent osIntent = new Intent(this, MainActivity.class);
                osIntent.setAction(pkg + ".OPEN_CONJURE_OS");
                ShortcutInfo osShortcut = new ShortcutInfo.Builder(this, "shortcut_open_conjure")
                        .setShortLabel("Open Conjure OS")
                        .setLongLabel("Launch Conjure OS directly")
                        .setIcon(createShortcutIcon("🚀", "#7c6cff"))
                        .setIntent(osIntent)
                        .build();
                shortcuts.add(osShortcut);

                shortcutManager.setDynamicShortcuts(shortcuts);
            }
        }
    }

    public void showWrapperSettingsDialog() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (wrapperWebView != null) {
                    wrapperWebView.loadUrl("file:///android_asset/settings.html");
                }
            }
        });
    }

    private void showResumeConfirmationDialog(final String savedUrl, final String lastVisitedUrl) {
        AlertDialog.Builder builder = new AlertDialog.Builder(this, android.R.style.Theme_DeviceDefault_Dialog_Alert);
        builder.setTitle("Resume Session");
        builder.setMessage("Would you like to resume your last session where you left off?");
        builder.setCancelable(false);

        builder.setPositiveButton("Yes", new DialogInterface.OnClickListener() {
            @Override
            public void onClick(DialogInterface dialog, int which) {
                restoreFullSessionState(lastVisitedUrl);
            }
        });

        builder.setNegativeButton("No", new DialogInterface.OnClickListener() {
            @Override
            public void onClick(DialogInterface dialog, int which) {
                getSharedPreferences("UrlPrefs", MODE_PRIVATE).edit()
                    .putBoolean("overlay_tab_active", false)
                    .putString("overlay_tab_url", "")
                    .apply();
                closeChildOverlay();
                loadConjureOsWrapperViewInternal(savedUrl);
            }
        });

        AlertDialog dialog = builder.create();
        dialog.show();
    }

    private void checkAutoStartOnLaunch() {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }
        boolean autoStartLaunch = statePreferences.getBoolean("auto_start_launch", false);
        final boolean autoStartTailscale = statePreferences.getBoolean("auto_start_tailscale", false);

        RuntimeService activeService = RuntimeService.getInstance();
        if (activeService == null && autoStartLaunch) {
            File existingRoot = new File(Environment.getExternalStorageDirectory(), "Conjure OS");
            File existingIndex = new File(existingRoot, "index.php");
            if (hasStoragePermission() && existingIndex.isFile() && existingIndex.length() > 0) {
                startRuntime();
                if (autoStartTailscale) {
                    new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
                        @Override
                        public void run() {
                            startTailscale();
                        }
                    }, 1200);
                }
            }
        }
    }

    public void restartApp() {
        try {
            stopTailscale();
            stopRuntime();

            Intent intent = Intent.makeRestartActivityTask(getComponentName());
            startActivity(intent);

            finishAffinity();
            Runtime.getRuntime().exit(0);
        } catch (Exception e) {
            restartRuntimeAndTailscale();
        }
    }

    public void restartRuntimeAndTailscale() {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }
        final boolean autoTailscale = statePreferences.getBoolean("auto_start_tailscale", false);

        RuntimeService service = RuntimeService.getInstance();
        final boolean tsWasRunning = (service != null && service.getProcessManager() != null && service.getProcessManager().getTailscaleManager().isRunning());

        updateInstallStatus("Restarting Services", "Recycling PHP, Nginx, and Tailscale daemons...", "");

        stopTailscale();
        stopRuntime();

        new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
            @Override
            public void run() {
                startRuntime();
                if (tsWasRunning || autoTailscale) {
                    new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
                        @Override
                        public void run() {
                            startTailscale();
                        }
                    }, 1000);
                }
            }
        }, 800);
    }

    public void setAutoStartSettings(boolean autoStartLaunch, boolean autoStartBoot, boolean autoStartTailscale) {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }
        statePreferences.edit()
            .putBoolean("auto_start_launch", autoStartLaunch)
            .putBoolean("auto_start_boot", autoStartBoot)
            .putBoolean("auto_start_tailscale", autoStartTailscale)
            .apply();
        updateInstallStatus("Automation Saved", "Auto-start settings updated.", "success");
    }

    public String getAutoStartSettingsJson() {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }
        boolean launch = statePreferences.getBoolean("auto_start_launch", false);
        boolean boot = statePreferences.getBoolean("auto_start_boot", false);
        boolean tailscale = statePreferences.getBoolean("auto_start_tailscale", false);
        return "{\"auto_start_launch\":" + launch + ",\"auto_start_boot\":" + boot + ",\"auto_start_tailscale\":" + tailscale + "}";
    }

    public void setOpenConjureOsByDefault(boolean enabled) {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }
        statePreferences.edit().putBoolean("open_conjure_os_by_default", enabled).apply();
        updateInstallStatus(
            "Default Launch Mode Saved",
            enabled ? "App will launch directly into Conjure OS. Long-press icon for Runtime Settings." : "App will launch into Runtime Settings Dashboard.",
            "success"
        );
    }

    public boolean getOpenConjureOsByDefault() {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }
        return statePreferences.getBoolean("open_conjure_os_by_default", false);
    }

    public void setInterceptBackButton(boolean enabled) {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }
        statePreferences.edit().putBoolean("intercept_back_button", enabled).apply();
        updateInstallStatus(
            "Back Button Interception Saved",
            enabled ? "Hardware Back button will trigger Magic Back (#back URL) inside Conjure OS." : "Hardware Back button will show exit options / browser history.",
            "success"
        );
    }

    public boolean getInterceptBackButton() {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }
        return statePreferences.getBoolean("intercept_back_button", false);
    }

    private void handleWrapperLoadError(final WebView view, final String failingUrl) {
        if (isWrapperMode && connectionRetryCount < MAX_CONNECTION_RETRIES) {
            connectionRetryCount++;
            updateSplashStatus("Waiting for local server (" + connectionRetryCount + ")...");
            if (mProgressBar != null) {
                mProgressBar.setVisibility(View.VISIBLE);
            }
            new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
                @Override
                public void run() {
                    if (isWrapperMode && view != null) {
                        view.loadUrl(failingUrl);
                    }
                }
            }, 600);
        }
    }

    @SuppressWarnings("deprecation")
    public void vibrate(long ms) {
        try {
            Vibrator v = (Vibrator) getSystemService(VIBRATOR_SERVICE);
            if (v != null && v.hasVibrator()) {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    v.vibrate(VibrationEffect.createOneShot(ms, VibrationEffect.DEFAULT_AMPLITUDE));
                } else {
                    v.vibrate(ms);
                }
            }
        } catch (Exception ignored) {}
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
                Uri uri = Uri.parse(item.url);
                String host = uri.getHost();
                if (host != null && !host.isEmpty()) return host;
                String lastSeg = uri.getLastPathSegment();
                if (lastSeg != null && !lastSeg.isEmpty()) return lastSeg;
            } catch (Exception ignored) {}
            return item.url;
        }
        return "New Tab";
    }

    private Bitmap captureWebViewThumbnail(WebView webView, int widthPx, int heightPx) {
        if (webView == null || webView.getWidth() <= 0 || webView.getHeight() <= 0) return null;
        try {
            Bitmap bitmap = Bitmap.createBitmap(widthPx, heightPx, Bitmap.Config.ARGB_8888);
            Canvas canvas = new Canvas(bitmap);
            float scaleX = (float) widthPx / webView.getWidth();
            float scaleY = (float) heightPx / webView.getHeight();
            canvas.scale(scaleX, scaleY);
            webView.draw(canvas);
            return bitmap;
        } catch (Exception e) {
            return null;
        }
    }

    private void initChildOverlayContainer(FrameLayout rootLayout) {
        mChildOverlayContainer = new FrameLayout(this);
        mChildOverlayContainer.setVisibility(View.GONE);
        mChildOverlayContainer.setBackgroundColor(Color.parseColor("#08080d"));

        LinearLayout innerLayout = new LinearLayout(this);
        innerLayout.setOrientation(LinearLayout.VERTICAL);

        // Top Header Bar
        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(Gravity.CENTER_VERTICAL);
        header.setBackgroundColor(Color.parseColor("#12121a"));
        header.setPadding(dpToPx(12), dpToPx(10), dpToPx(12), dpToPx(10));

        TextView btnClose = new TextView(this);
        btnClose.setText("✕");
        btnClose.setTextSize(16);
        btnClose.setTextColor(Color.parseColor("#a1a1aa"));
        btnClose.setPadding(dpToPx(6), dpToPx(4), dpToPx(12), dpToPx(4));
        btnClose.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                closeChildOverlay();
            }
        });

        mChildTitleView = new TextView(this);
        mChildTitleView.setText("Overlay Tab");
        mChildTitleView.setTextSize(13);
        mChildTitleView.setTextColor(Color.parseColor("#f5f5f7"));
        mChildTitleView.setTypeface(Typeface.DEFAULT_BOLD);
        mChildTitleView.setSingleLine(true);
        mChildTitleView.setEllipsize(android.text.TextUtils.TruncateAt.END);
        LinearLayout.LayoutParams titleParams = new LinearLayout.LayoutParams(
            0, LinearLayout.LayoutParams.WRAP_CONTENT, 1.0f
        );
        mChildTitleView.setLayoutParams(titleParams);

        TextView btnExternal = new TextView(this);
        btnExternal.setText("↗");
        btnExternal.setTextSize(16);
        btnExternal.setTextColor(Color.parseColor("#7c6cff"));
        btnExternal.setPadding(dpToPx(12), dpToPx(4), dpToPx(6), dpToPx(4));
        btnExternal.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                if (mSecondaryWebView != null && mSecondaryWebView.getUrl() != null) {
                    openExternalUrl(mSecondaryWebView.getUrl());
                }
            }
        });

        header.addView(btnClose);
        header.addView(mChildTitleView);
        header.addView(btnExternal);
        innerLayout.addView(header, new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT
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

        mSecondaryWebView.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
                handleDownload(url, userAgent, contentDisposition, mimeType, contentLength);
            }
        });

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
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
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
                saveTabSessionState();
            }
        });

        mSecondaryWebView.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onShowFileChooser(WebView webView, android.webkit.ValueCallback<Uri[]> filePathCallback, WebChromeClient.FileChooserParams fileChooserParams) {
                if (mFilePathCallback != null) {
                    mFilePathCallback.onReceiveValue(null);
                    mFilePathCallback = null;
                }
                mFilePathCallback = filePathCallback;

                Intent intent = null;
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP && fileChooserParams != null) {
                    try {
                        intent = fileChooserParams.createIntent();
                    } catch (Exception ignored) {}
                }
                if (intent == null) {
                    intent = new Intent(Intent.ACTION_GET_CONTENT);
                    intent.addCategory(Intent.CATEGORY_OPENABLE);
                    intent.setType("*/*");
                }

                try {
                    startActivityForResult(intent, FILE_CHOOSER_REQUEST_CODE);
                } catch (Exception e) {
                    if (mFilePathCallback != null) {
                        mFilePathCallback.onReceiveValue(null);
                        mFilePathCallback = null;
                    }
                    Toast.makeText(MainActivity.this, "File picker error: " + e.getMessage(), Toast.LENGTH_SHORT).show();
                    return false;
                }
                return true;
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
                    public boolean shouldOverrideUrlLoading(WebView wv, WebResourceRequest request) {
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
        });

        mSecondaryWebView.addJavascriptInterface(new RuntimeBridge(this), "Android");

        innerLayout.addView(mSecondaryWebView, new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT, 0, 1.0f
        ));

        mChildOverlayContainer.addView(innerLayout, new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT
        ));

        rootLayout.addView(mChildOverlayContainer, new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT
        ));
    }

    private void initFloatingTabBadge(FrameLayout rootLayout) {
        mFloatingTabBadge = new FrameLayout(this);
        mFloatingTabBadge.setVisibility(View.GONE);

        android.graphics.drawable.GradientDrawable shape = new android.graphics.drawable.GradientDrawable();
        shape.setShape(android.graphics.drawable.GradientDrawable.RECTANGLE);
        shape.setCornerRadius(dpToPx(8));
        shape.setColor(Color.parseColor("#1a1a26"));
        shape.setStroke(dpToPx(1), Color.parseColor("#7c6cff"));
        mFloatingTabBadge.setBackground(shape);

        mFloatingTabCount = new TextView(this);
        mFloatingTabCount.setText("1");
        mFloatingTabCount.setTextSize(13);
        mFloatingTabCount.setTextColor(Color.WHITE);
        mFloatingTabCount.setTypeface(Typeface.DEFAULT_BOLD);
        mFloatingTabCount.setGravity(Gravity.CENTER);

        FrameLayout.LayoutParams countParams = new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT
        );
        mFloatingTabBadge.addView(mFloatingTabCount, countParams);

        int size = dpToPx(38);
        FrameLayout.LayoutParams badgeParams = new FrameLayout.LayoutParams(size, size);
        badgeParams.gravity = Gravity.BOTTOM | Gravity.END;
        badgeParams.rightMargin = dpToPx(16);
        badgeParams.bottomMargin = dpToPx(16);

        mFloatingTabBadge.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
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
                        mFloatingTabBadge.setVisibility(View.VISIBLE);
                        mFloatingTabBadge.bringToFront();
                        mFloatingTabCount.setText(String.valueOf(count));
                    } else {
                        mFloatingTabBadge.setVisibility(View.GONE);
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
                    mChildOverlayContainer.setVisibility(View.VISIBLE);
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

    private void closeChildOverlay() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mChildOverlayContainer != null) {
                    mChildOverlayContainer.setVisibility(View.GONE);
                }
                if (mSecondaryWebView != null) {
                    mSecondaryWebView.loadUrl("about:blank");
                }
                vibrate(15);
                saveTabSessionState();
            }
        });
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
            openExternalUrl(url);
        } else if ("overlay".equals(linkMode)) {
            openInChildOverlay(url);
        } else {
            runOnUiThread(new Runnable() {
                @Override
                public void run() {
                    new AlertDialog.Builder(MainActivity.this, android.R.style.Theme_DeviceDefault_Dialog_Alert)
                        .setTitle("Open External Link")
                        .setMessage(url)
                        .setPositiveButton("Overlay Tab", new DialogInterface.OnClickListener() {
                            @Override
                            public void onClick(DialogInterface dialog, int which) {
                                openInChildOverlay(url);
                            }
                        })
                        .setNeutralButton("External Browser", new DialogInterface.OnClickListener() {
                            @Override
                            public void onClick(DialogInterface dialog, int which) {
                                openExternalUrl(url);
                            }
                        })
                        .setNegativeButton("Cancel", null)
                        .show();
                }
            });
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

        v.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
                handleDownload(url, userAgent, contentDisposition, mimeType, contentLength);
            }
        });

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
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
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
                saveTabSessionState();
            }
        });

        v.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onShowFileChooser(WebView webView, android.webkit.ValueCallback<Uri[]> filePathCallback, WebChromeClient.FileChooserParams fileChooserParams) {
                if (mFilePathCallback != null) {
                    mFilePathCallback.onReceiveValue(null);
                    mFilePathCallback = null;
                }
                mFilePathCallback = filePathCallback;

                Intent intent = null;
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP && fileChooserParams != null) {
                    try {
                        intent = fileChooserParams.createIntent();
                    } catch (Exception ignored) {}
                }
                if (intent == null) {
                    intent = new Intent(Intent.ACTION_GET_CONTENT);
                    intent.addCategory(Intent.CATEGORY_OPENABLE);
                    intent.setType("*/*");
                }

                try {
                    startActivityForResult(intent, FILE_CHOOSER_REQUEST_CODE);
                } catch (Exception e) {
                    if (mFilePathCallback != null) {
                        mFilePathCallback.onReceiveValue(null);
                        mFilePathCallback = null;
                    }
                    Toast.makeText(MainActivity.this, "File picker error: " + e.getMessage(), Toast.LENGTH_SHORT).show();
                    return false;
                }
                return true;
            }

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
                    public boolean shouldOverrideUrlLoading(WebView wv, WebResourceRequest request) {
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
        });

        v.addJavascriptInterface(new RuntimeBridge(this), "Android");
        return v;
    }

    private void createNewTab(String url) {
        WebView newWeb = createTabWebView();
        final TabItem tab = new TabItem(newWeb, url);
        mTabList.add(tab);

        mWrapperWebViewContainer.addView(newWeb, new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT
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
            mTabList.get(i).webView.setVisibility(i == index ? View.VISIBLE : View.GONE);
        }
        saveTabSessionState();
    }

    private void closeTab(int index) {
        if (index < 0 || index >= mTabList.size() || mTabList.size() <= 1) return;

        TabItem item = mTabList.remove(index);
        mWrapperWebViewContainer.removeView(item.webView);
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

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(Color.parseColor("#12121a"));
        root.setPadding(dpToPx(16), dpToPx(16), dpToPx(16), dpToPx(16));

        // Top Header Bar
        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(Gravity.CENTER_VERTICAL);
        header.setPadding(0, 0, 0, dpToPx(12));

        TextView titleView = new TextView(this);
        titleView.setText("Open Tabs (" + mTabList.size() + ")");
        titleView.setTextSize(16);
        titleView.setTextColor(Color.parseColor("#f5f5f7"));
        titleView.setTypeface(Typeface.DEFAULT_BOLD);
        LinearLayout.LayoutParams titleParams = new LinearLayout.LayoutParams(0, android.view.ViewGroup.LayoutParams.WRAP_CONTENT, 1.0f);
        titleView.setLayoutParams(titleParams);

        // View Mode Toggle Button
        TextView btnToggleView = new TextView(this);
        btnToggleView.setText(isGridView ? "☰ List" : "⊞ Grid");
        btnToggleView.setTextSize(12);
        btnToggleView.setTextColor(Color.parseColor("#7c6cff"));
        btnToggleView.setPadding(dpToPx(8), dpToPx(4), dpToPx(8), dpToPx(4));
        btnToggleView.setTypeface(Typeface.DEFAULT_BOLD);
        btnToggleView.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                urlPrefs.edit().putString("tab_switcher_mode", isGridView ? "list" : "grid").apply();
                dialog.dismiss();
                showTabSwitcherDialog();
            }
        });

        // Header Close Button [ ✕ ]
        TextView btnHeaderClose = new TextView(this);
        btnHeaderClose.setText("✕");
        btnHeaderClose.setTextSize(18);
        btnHeaderClose.setTextColor(Color.parseColor("#a1a1aa"));
        btnHeaderClose.setPadding(dpToPx(12), dpToPx(4), dpToPx(4), dpToPx(4));
        btnHeaderClose.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
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

                LinearLayout card = new LinearLayout(this);
                card.setOrientation(LinearLayout.VERTICAL);
                card.setPadding(dpToPx(6), dpToPx(6), dpToPx(6), dpToPx(6));

                android.graphics.drawable.GradientDrawable cardBg = new android.graphics.drawable.GradientDrawable();
                cardBg.setCornerRadius(dpToPx(10));
                cardBg.setColor(Color.parseColor(isActive ? "#1e1b4b" : "#1a1a24"));
                cardBg.setStroke(dpToPx(isActive ? 2 : 1), Color.parseColor(isActive ? "#7c6cff" : "#2c2c3e"));
                card.setBackground(cardBg);

                LinearLayout cardHeader = new LinearLayout(this);
                cardHeader.setOrientation(LinearLayout.HORIZONTAL);
                cardHeader.setGravity(Gravity.CENTER_VERTICAL);

                TextView cardTitle = new TextView(this);
                cardTitle.setText(getDisplayTitle(t));
                cardTitle.setTextSize(11);
                cardTitle.setTextColor(Color.parseColor("#f5f5f7"));
                cardTitle.setSingleLine(true);
                cardTitle.setEllipsize(android.text.TextUtils.TruncateAt.END);
                cardTitle.setLayoutParams(new LinearLayout.LayoutParams(0, android.view.ViewGroup.LayoutParams.WRAP_CONTENT, 1.0f));

                TextView btnTabClose = new TextView(this);
                btnTabClose.setText("✕");
                btnTabClose.setTextSize(13);
                btnTabClose.setTextColor(Color.parseColor("#a1a1aa"));
                btnTabClose.setPadding(dpToPx(6), dpToPx(2), dpToPx(2), dpToPx(2));
                btnTabClose.setOnClickListener(new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
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
                Bitmap thumb = captureWebViewThumbnail(t.webView, dpToPx(130), dpToPx(160));
                if (thumb != null) {
                    thumbView.setImageBitmap(thumb);
                } else {
                    thumbView.setBackgroundColor(Color.parseColor("#08080d"));
                }
                LinearLayout.LayoutParams thumbParams = new LinearLayout.LayoutParams(dpToPx(130), dpToPx(160));
                thumbParams.topMargin = dpToPx(4);
                card.addView(thumbView, thumbParams);

                card.setOnClickListener(new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        switchToTab(tabIndex);
                        dialog.dismiss();
                    }
                });

                grid.addView(card);
            }
            scrollView.addView(grid);
        } else {
            // List View (Vertical Card Rows)
            LinearLayout list = new LinearLayout(this);
            list.setOrientation(LinearLayout.VERTICAL);

            for (int i = 0; i < mTabList.size(); i++) {
                final int tabIndex = i;
                final TabItem t = mTabList.get(i);
                boolean isActive = (i == mActiveTabIndex);

                LinearLayout row = new LinearLayout(this);
                row.setOrientation(LinearLayout.HORIZONTAL);
                row.setGravity(Gravity.CENTER_VERTICAL);
                row.setPadding(dpToPx(12), dpToPx(10), dpToPx(12), dpToPx(10));

                android.graphics.drawable.GradientDrawable rowBg = new android.graphics.drawable.GradientDrawable();
                rowBg.setCornerRadius(dpToPx(8));
                rowBg.setColor(Color.parseColor(isActive ? "#1e1b4b" : "#1a1a24"));
                rowBg.setStroke(dpToPx(isActive ? 2 : 1), Color.parseColor(isActive ? "#7c6cff" : "#2c2c3e"));
                row.setBackground(rowBg);

                LinearLayout.LayoutParams rowParams = new LinearLayout.LayoutParams(
                    android.view.ViewGroup.LayoutParams.MATCH_PARENT, android.view.ViewGroup.LayoutParams.WRAP_CONTENT
                );
                rowParams.bottomMargin = dpToPx(8);
                row.setLayoutParams(rowParams);

                TextView indicator = new TextView(this);
                indicator.setText(isActive ? "✓ " : "  ");
                indicator.setTextColor(Color.parseColor("#7c6cff"));
                indicator.setTextSize(14);

                LinearLayout textCol = new LinearLayout(this);
                textCol.setOrientation(LinearLayout.VERTICAL);
                LinearLayout.LayoutParams textParams = new LinearLayout.LayoutParams(0, android.view.ViewGroup.LayoutParams.WRAP_CONTENT, 1.0f);
                textCol.setLayoutParams(textParams);

                TextView tabTitle = new TextView(this);
                tabTitle.setText(getDisplayTitle(t));
                tabTitle.setTextSize(13);
                tabTitle.setTextColor(Color.parseColor("#f5f5f7"));
                tabTitle.setTypeface(Typeface.DEFAULT_BOLD);
                tabTitle.setSingleLine(true);
                tabTitle.setEllipsize(android.text.TextUtils.TruncateAt.END);

                TextView tabUrl = new TextView(this);
                tabUrl.setText(t.url != null ? t.url : "");
                tabUrl.setTextSize(11);
                tabUrl.setTextColor(Color.parseColor("#a1a1aa"));
                tabUrl.setSingleLine(true);
                tabUrl.setEllipsize(android.text.TextUtils.TruncateAt.END);

                textCol.addView(tabTitle);
                textCol.addView(tabUrl);

                row.addView(indicator);
                row.addView(textCol);

                if (mTabList.size() > 1) {
                    TextView btnCloseTab = new TextView(this);
                    btnCloseTab.setText("✕");
                    btnCloseTab.setTextSize(14);
                    btnCloseTab.setTextColor(Color.parseColor("#a1a1aa"));
                    btnCloseTab.setPadding(dpToPx(10), dpToPx(4), dpToPx(4), dpToPx(4));
                    btnCloseTab.setOnClickListener(new View.OnClickListener() {
                        @Override
                        public void onClick(View v) {
                            closeTab(tabIndex);
                            dialog.dismiss();
                            if (mTabList.size() > 1) {
                                showTabSwitcherDialog();
                            }
                        }
                    });
                    row.addView(btnCloseTab);
                }

                row.setOnClickListener(new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        switchToTab(tabIndex);
                        dialog.dismiss();
                    }
                });

                list.addView(row);
            }
            scrollView.addView(list);
        }

        LinearLayout.LayoutParams scrollParams = new LinearLayout.LayoutParams(
            android.view.ViewGroup.LayoutParams.MATCH_PARENT, 0, 1.0f
        );
        scrollParams.bottomMargin = dpToPx(12);
        root.addView(scrollView, scrollParams);

        // Footer Actions
        LinearLayout footer = new LinearLayout(this);
        footer.setOrientation(LinearLayout.HORIZONTAL);

        TextView btnNewTab = new TextView(this);
        btnNewTab.setText("+ Open New Tab");
        btnNewTab.setTextSize(13);
        btnNewTab.setTextColor(Color.parseColor("#7c6cff"));
        btnNewTab.setTypeface(Typeface.DEFAULT_BOLD);
        btnNewTab.setPadding(dpToPx(10), dpToPx(8), dpToPx(10), dpToPx(8));
        btnNewTab.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                SharedPreferences prefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
                int httpsPort = statePreferences != null ? statePreferences.getInt("https_port", 8000) : 8000;
                String defaultUrl = "https://127.0.0.1:" + httpsPort + "/";
                String savedCustomUrl = prefs.getString("custom_url", "");
                String newTabUrl = savedCustomUrl.isEmpty() ? defaultUrl : updateLoopbackUrlPort(savedCustomUrl, 0, httpsPort);
                createNewTab(newTabUrl);
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

            boolean isOverlayActive = (mChildOverlayContainer != null && mChildOverlayContainer.getVisibility() == View.VISIBLE);
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
                                        wrapperWebView.loadUrl(url);
                                    } else {
                                        WebView newWeb = createTabWebView();
                                        TabItem tab = new TabItem(newWeb, url);
                                        tab.title = title;
                                        mTabList.add(tab);
                                        mWrapperWebViewContainer.addView(newWeb, new FrameLayout.LayoutParams(
                                            FrameLayout.LayoutParams.MATCH_PARENT,
                                            FrameLayout.LayoutParams.MATCH_PARENT
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

                loadConjureOsWrapperViewInternal(fallbackUrl);
                checkAndRestoreOverlayTab();
            }
        });
    }

    private void loadConjureOsWrapperViewInternal(String url) {
        if (wrapperWebView != null) {
            int httpsPort = statePreferences != null ? statePreferences.getInt("https_port", 8000) : 8000;
            if (url != null && !url.trim().isEmpty() && url.startsWith("http")) {
                String effectiveUrl = updateLoopbackUrlPort(url.trim(), 0, httpsPort);
                wrapperWebView.loadUrl(effectiveUrl);
            } else {
                String defaultUrl = "https://127.0.0.1:" + httpsPort + "/";
                wrapperWebView.loadUrl(defaultUrl);
            }
        }
    }

    @SuppressWarnings("deprecation")
    public void clearAllSiteData() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (wrapperWebView != null) {
                    wrapperWebView.clearCache(true);
                    wrapperWebView.clearHistory();
                    wrapperWebView.clearFormData();
                }
                if (dashboardWebView != null) {
                    dashboardWebView.clearCache(true);
                    dashboardWebView.clearHistory();
                    dashboardWebView.clearFormData();
                }
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
                    android.webkit.CookieManager.getInstance().removeAllCookies(null);
                    android.webkit.CookieManager.getInstance().flush();
                } else {
                    android.webkit.CookieManager.getInstance().removeAllCookie();
                }
                android.webkit.WebStorage.getInstance().deleteAllData();
                vibrate(40);
                updateInstallStatus("Site Data Cleared", "Browser cache, cookies, local storage, and history were wiped.", "success");
            }
        });
    }

    public void clearCacheOnly() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (wrapperWebView != null) wrapperWebView.clearCache(true);
                if (dashboardWebView != null) dashboardWebView.clearCache(true);
                vibrate(20);
                updateInstallStatus("Cache Cleared", "HTTP disk and memory cache flushes completed.", "success");
            }
        });
    }

    public void clearWebStorageOnly() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                android.webkit.WebStorage.getInstance().deleteAllData();
                vibrate(20);
                updateInstallStatus("Web Storage Cleared", "HTML5 LocalStorage, IndexedDB, and WebSQL wiped.", "success");
            }
        });
    }

    @SuppressWarnings("deprecation")
    public void clearCookiesOnly() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
                    android.webkit.CookieManager.getInstance().removeAllCookies(null);
                    android.webkit.CookieManager.getInstance().flush();
                } else {
                    android.webkit.CookieManager.getInstance().removeAllCookie();
                }
                vibrate(20);
                updateInstallStatus("Cookies Cleared", "Session and persistent cookies removed.", "success");
            }
        });
    }

    private void registerShakeSensorIfNeeded() {
        SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
        boolean enableShake = urlPrefs.getBoolean("shake_to_refresh", false);

        if (mSensorManager == null) {
            mSensorManager = (SensorManager) getSystemService(Context.SENSOR_SERVICE);
        }

        if (mSensorManager != null) {
            mSensorManager.unregisterListener(mSensorListener);
            if (enableShake) {
                if (mAccelerometer == null) {
                    mAccelerometer = mSensorManager.getDefaultSensor(Sensor.TYPE_ACCELEROMETER);
                }
                if (mAccelerometer != null) {
                    mSensorManager.registerListener(mSensorListener, mAccelerometer, SensorManager.SENSOR_DELAY_UI);
                }
            }
        }
    }

    private void unregisterShakeSensor() {
        if (mSensorManager != null) {
            mSensorManager.unregisterListener(mSensorListener);
        }
    }

    private void initShakePromptBanner(FrameLayout rootLayout) {
        mShakePromptBanner = new FrameLayout(this);
        mShakePromptBanner.setVisibility(View.GONE);

        android.graphics.drawable.GradientDrawable bg = new android.graphics.drawable.GradientDrawable();
        bg.setCornerRadius(dpToPx(24));
        bg.setColor(Color.parseColor("#1c1c28"));
        bg.setStroke(dpToPx(1), Color.parseColor("#7c6cff"));
        mShakePromptBanner.setBackground(bg);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            mShakePromptBanner.setElevation(dpToPx(12));
        }

        LinearLayout splitLayout = new LinearLayout(this);
        splitLayout.setOrientation(LinearLayout.HORIZONTAL);
        splitLayout.setGravity(Gravity.CENTER_VERTICAL);

        // Left Action: Options Menu
        TextView leftBtn = new TextView(this);
        leftBtn.setText("⚙️ Options");
        leftBtn.setTextSize(12);
        leftBtn.setTextColor(Color.parseColor("#f5f5f7"));
        leftBtn.setTypeface(Typeface.DEFAULT_BOLD);
        leftBtn.setPadding(dpToPx(16), dpToPx(10), dpToPx(12), dpToPx(10));
        leftBtn.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                vibrate(20);
                hideShakeRefreshPrompt();
                showExitDialog();
            }
        });

        // Vertical Divider Line
        View divider = new View(this);
        divider.setBackgroundColor(Color.parseColor("#3a3a4e"));
        LinearLayout.LayoutParams divParams = new LinearLayout.LayoutParams(dpToPx(1), dpToPx(16));

        // Right Action: Refresh Active View
        TextView rightBtn = new TextView(this);
        rightBtn.setText("🔄 Refresh");
        rightBtn.setTextSize(12);
        rightBtn.setTextColor(Color.parseColor("#f5f5f7"));
        rightBtn.setTypeface(Typeface.DEFAULT_BOLD);
        rightBtn.setPadding(dpToPx(12), dpToPx(10), dpToPx(16), dpToPx(10));
        rightBtn.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                vibrate(20);
                hideShakeRefreshPrompt();
                reloadActiveWebView();
            }
        });

        splitLayout.addView(leftBtn);
        splitLayout.addView(divider, divParams);
        splitLayout.addView(rightBtn);

        mShakePromptBanner.addView(splitLayout, new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.WRAP_CONTENT, FrameLayout.LayoutParams.WRAP_CONTENT
        ));

        FrameLayout.LayoutParams params = new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.WRAP_CONTENT, FrameLayout.LayoutParams.WRAP_CONTENT
        );
        params.gravity = Gravity.TOP | Gravity.CENTER_HORIZONTAL;
        params.topMargin = dpToPx(48);

        rootLayout.addView(mShakePromptBanner, params);
    }

    private void showShakeRefreshPrompt() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mShakePromptBanner == null) return;

                if (mHideShakeBannerRunnable != null) {
                    mShakeBannerHandler.removeCallbacks(mHideShakeBannerRunnable);
                }

                mShakePromptBanner.bringToFront();
                mShakePromptBanner.setAlpha(0f);
                mShakePromptBanner.setTranslationY(-dpToPx(20));
                mShakePromptBanner.setVisibility(View.VISIBLE);

                mShakePromptBanner.animate()
                    .alpha(1f)
                    .translationY(0)
                    .setDuration(250)
                    .start();

                mHideShakeBannerRunnable = new Runnable() {
                    @Override
                    public void run() {
                        hideShakeRefreshPrompt();
                    }
                };
                mShakeBannerHandler.postDelayed(mHideShakeBannerRunnable, 4000);
            }
        });
    }

    private void hideShakeRefreshPrompt() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (mShakePromptBanner != null && mShakePromptBanner.getVisibility() == View.VISIBLE) {
                    mShakePromptBanner.animate()
                        .alpha(0f)
                        .translationY(-dpToPx(20))
                        .setDuration(250)
                        .withEndAction(new Runnable() {
                            @Override
                            public void run() {
                                if (mShakePromptBanner != null) {
                                    mShakePromptBanner.setVisibility(View.GONE);
                                }
                            }
                        })
                        .start();
                }
            }
        });
    }

    private void reloadActiveWebView() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                WebView activeWeb = null;
                if (mChildOverlayContainer != null && mChildOverlayContainer.getVisibility() == View.VISIBLE && mSecondaryWebView != null) {
                    activeWeb = mSecondaryWebView;
                } else if (mTabList != null && !mTabList.isEmpty() && mActiveTabIndex >= 0 && mActiveTabIndex < mTabList.size()) {
                    activeWeb = mTabList.get(mActiveTabIndex).webView;
                } else {
                    activeWeb = wrapperWebView;
                }

                if (activeWeb != null) {
                    String url = activeWeb.getUrl();
                    if (url != null && (url.startsWith("http://") || url.startsWith("https://"))) {
                        activeWeb.evaluateJavascript("window.location.reload();", null);
                    } else {
                        activeWeb.reload();
                    }
                }
            }
        });
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
        applyZoomSettings(wrapperWebView);
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

    private String normalizeStoragePath(String path) {
        if (path == null || path.trim().isEmpty()) {
            return new File(Environment.getExternalStorageDirectory(), "Conjure OS").getAbsolutePath();
        }
        String clean = path.trim().replace("\\", "/");
        if (clean.startsWith("/sdcard/")) {
            clean = Environment.getExternalStorageDirectory().getAbsolutePath() + clean.substring(7);
        } else if (clean.startsWith("/sdcard")) {
            clean = Environment.getExternalStorageDirectory().getAbsolutePath() + clean.substring(7);
        }
        clean = clean.replaceAll("/+$", "");
        return clean;
    }

    private String getDisplayStoragePath(String path) {
        String normalized = normalizeStoragePath(path);
        String sdcard = Environment.getExternalStorageDirectory().getAbsolutePath();
        if (normalized.startsWith(sdcard)) {
            return "/sdcard" + normalized.substring(sdcard.length()) + "/";
        }
        return normalized + "/";
    }

    public String getSystemPathsJson() {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }
        String defaultPath = new File(Environment.getExternalStorageDirectory(), "Conjure OS").getAbsolutePath();
        String activePath = normalizeStoragePath(statePreferences.getString("active_system_path", defaultPath));

        String savedListJson = statePreferences.getString("system_paths_list", "");
        List<String> pathsList = new ArrayList<>();
        pathsList.add(defaultPath);

        if (savedListJson != null && !savedListJson.trim().isEmpty()) {
            try {
                org.json.JSONArray arr = new org.json.JSONArray(savedListJson);
                for (int i = 0; i < arr.length(); i++) {
                    String p = normalizeStoragePath(arr.getString(i));
                    if (!pathsList.contains(p)) {
                        pathsList.add(p);
                    }
                }
            } catch (Exception ignored) {}
        }

        try {
            org.json.JSONObject root = new org.json.JSONObject();
            root.put("active_path", activePath);
            root.put("default_path", defaultPath);

            org.json.JSONArray arr = new org.json.JSONArray();
            for (String p : pathsList) {
                boolean isDef = p.equalsIgnoreCase(defaultPath);
                boolean isAct = p.equalsIgnoreCase(activePath);
                String displayPath = getDisplayStoragePath(p);
                String label = isDef ? "Default Conjure OS" : new File(p).getName();

                org.json.JSONObject item = new org.json.JSONObject();
                item.put("path", p);
                item.put("display_path", displayPath);
                item.put("label", label);
                item.put("is_default", isDef);
                item.put("is_active", isAct);
                arr.put(item);
            }
            root.put("paths", arr);
            return root.toString();
        } catch (Exception e) {
            return "{\"active_path\":\"" + escapeJson(activePath) + "\",\"default_path\":\"" + escapeJson(defaultPath) + "\",\"paths\":[]}";
        }
    }

    public void setActiveSystemPath(String path) {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }
        String normalized = normalizeStoragePath(path);
        statePreferences.edit().putString("active_system_path", normalized).commit();

        updateInstallStatus("Active Path Updated", "Active system path changed to " + getDisplayStoragePath(normalized), "success");

        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                evalJsOnAllWebViews("if(window.Runtime && window.Runtime.loadSystemPaths) window.Runtime.loadSystemPaths();");
            }
        });
    }

    public void addSystemPath(String rawPath) {
        if (rawPath == null || rawPath.trim().isEmpty()) {
            updateInstallStatus("Invalid Path", "A valid directory path is required.", "error");
            return;
        }

        String normalized = normalizeStoragePath(rawPath);
        File dir = new File(normalized);
        if (!dir.exists()) {
            dir.mkdirs();
        }

        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }

        String defaultPath = new File(Environment.getExternalStorageDirectory(), "Conjure OS").getAbsolutePath();
        String savedListJson = statePreferences.getString("system_paths_list", "");
        List<String> pathsList = new ArrayList<>();
        pathsList.add(defaultPath);

        if (savedListJson != null && !savedListJson.trim().isEmpty()) {
            try {
                org.json.JSONArray arr = new org.json.JSONArray(savedListJson);
                for (int i = 0; i < arr.length(); i++) {
                    String p = normalizeStoragePath(arr.getString(i));
                    if (!pathsList.contains(p)) {
                        pathsList.add(p);
                    }
                }
            } catch (Exception ignored) {}
        }

        if (!pathsList.contains(normalized)) {
            pathsList.add(normalized);
        }

        org.json.JSONArray newArr = new org.json.JSONArray();
        for (String p : pathsList) {
            newArr.put(p);
        }

        statePreferences.edit()
            .putString("system_paths_list", newArr.toString())
            .putString("active_system_path", normalized)
            .commit();

        updateInstallStatus("System Path Added", "Added and activated " + getDisplayStoragePath(normalized), "success");

        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                evalJsOnAllWebViews("if(window.Runtime && window.Runtime.loadSystemPaths) window.Runtime.loadSystemPaths();");
            }
        });
    }

    public void removeSystemPath(String rawPath) {
        if (rawPath == null || rawPath.trim().isEmpty()) return;

        String normalized = normalizeStoragePath(rawPath);
        String defaultPath = new File(Environment.getExternalStorageDirectory(), "Conjure OS").getAbsolutePath();

        if (normalized.equalsIgnoreCase(defaultPath)) {
            updateInstallStatus("Cannot Remove Default Path", "The default Conjure OS directory cannot be removed.", "error");
            return;
        }

        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }

        String activePath = normalizeStoragePath(statePreferences.getString("active_system_path", defaultPath));
        String savedListJson = statePreferences.getString("system_paths_list", "");
        List<String> pathsList = new ArrayList<>();

        if (savedListJson != null && !savedListJson.trim().isEmpty()) {
            try {
                org.json.JSONArray arr = new org.json.JSONArray(savedListJson);
                for (int i = 0; i < arr.length(); i++) {
                    String p = normalizeStoragePath(arr.getString(i));
                    if (!p.equalsIgnoreCase(normalized)) {
                        pathsList.add(p);
                    }
                }
            } catch (Exception ignored) {}
        }

        if (!pathsList.contains(defaultPath)) {
            pathsList.add(0, defaultPath);
        }

        org.json.JSONArray newArr = new org.json.JSONArray();
        for (String p : pathsList) {
            newArr.put(p);
        }

        SharedPreferences.Editor editor = statePreferences.edit();
        editor.putString("system_paths_list", newArr.toString());

        if (activePath.equalsIgnoreCase(normalized)) {
            editor.putString("active_system_path", defaultPath);
        }

        editor.commit();
        updateInstallStatus("Path Removed", "Removed path from options list.", "success");

        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                evalJsOnAllWebViews("if(window.Runtime && window.Runtime.loadSystemPaths) window.Runtime.loadSystemPaths();");
            }
        });
    }

    public String getWrapperSettingsJson() {
        SharedPreferences themePrefs = getSharedPreferences("ThemePrefs", MODE_PRIVATE);
        String themeMode = themePrefs.getString("theme_mode", "system");

        SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
        int httpsPort = statePreferences != null ? statePreferences.getInt("https_port", 8000) : 8000;
        String defaultUrl = "https://127.0.0.1:" + httpsPort + "/";

        String savedCustomUrl = urlPrefs.getString("custom_url", "");
        String customUrl = savedCustomUrl.isEmpty() ? defaultUrl : updateLoopbackUrlPort(savedCustomUrl, 0, httpsPort);

        boolean resumeLastUrl = urlPrefs.getBoolean("resume_last_url", false);
        boolean confirmResume = urlPrefs.getBoolean("confirm_resume", false);
        String linkMode = urlPrefs.getString("link_mode", "prompt");
        boolean multiTabMode = urlPrefs.getBoolean("multi_tab_mode", false);
        boolean forceZoom = urlPrefs.getBoolean("force_zoom", false);
        boolean shakeToRefresh = urlPrefs.getBoolean("shake_to_refresh", false);

        return "{\"theme\":\"" + escapeJson(themeMode)
            + "\",\"custom_url\":\"" + escapeJson(customUrl)
            + "\",\"resume_last_url\":" + resumeLastUrl
            + ",\"confirm_resume\":" + confirmResume
            + ",\"link_mode\":\"" + escapeJson(linkMode)
            + "\",\"multi_tab_mode\":" + multiTabMode
            + ",\"force_zoom\":" + forceZoom
            + ",\"shake_to_refresh\":" + shakeToRefresh + "}";
    }

    public void saveWrapperSettings(final String themeMode, final String customUrl, final boolean resumeLastUrl, final boolean confirmResume, final String linkMode, final boolean multiTabMode, final boolean forceZoom) {
        saveWrapperSettings(themeMode, customUrl, resumeLastUrl, confirmResume, linkMode, multiTabMode, forceZoom, false);
    }

    public void saveWrapperSettings(final String themeMode, final String customUrl, final boolean resumeLastUrl, final boolean confirmResume, final String linkMode, final boolean multiTabMode, final boolean forceZoom, final boolean enableShake) {
        SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
        urlPrefs.edit()
            .putString("link_mode", linkMode != null ? linkMode : "prompt")
            .putBoolean("multi_tab_mode", multiTabMode)
            .putBoolean("force_zoom", forceZoom)
            .putBoolean("shake_to_refresh", enableShake)
            .apply();

        registerShakeSensorIfNeeded();
        saveWrapperSettingsInternal(themeMode, customUrl, resumeLastUrl, confirmResume);
    }

    private void saveWrapperSettingsInternal(final String themeMode, final String customUrl, final boolean resumeLastUrl, final boolean confirmResume) {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                SharedPreferences themePrefs = getSharedPreferences("ThemePrefs", MODE_PRIVATE);
                String oldTheme = themePrefs.getString("theme_mode", "system");

                SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
                String oldCustomUrl = urlPrefs.getString("custom_url", "");

                urlPrefs.edit()
                    .putString("custom_url", customUrl != null ? customUrl.trim() : "")
                    .putBoolean("resume_last_url", resumeLastUrl)
                    .putBoolean("confirm_resume", confirmResume)
                    .putBoolean("overlay_tab_active", false)
                    .putString("overlay_tab_url", "")
                    .apply();

                applyZoomSettingsToAllWebViews();

                if (getIntent() != null) {
                    getIntent().setAction(getPackageName() + ".MAIN");
                }

                if (mChildOverlayContainer != null) {
                    mChildOverlayContainer.setVisibility(View.GONE);
                }
                if (mSecondaryWebView != null) {
                    mSecondaryWebView.stopLoading();
                    mSecondaryWebView.loadUrl("about:blank");
                }

                vibrate(20);

                if (!oldTheme.equals(themeMode)) {
                    themePrefs.edit().putString("theme_mode", themeMode).apply();
                    recreate();
                } else {
                    if (customUrl != null && !customUrl.trim().isEmpty() && !customUrl.trim().equalsIgnoreCase(oldCustomUrl.trim())) {
                        loadConjureOsWrapperViewInternal(customUrl);
                    }
                }
            }
        });
    }

    public void openWrapperSettingsFromUi() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                navigatedToSettingsFromWrapper = true;
                isWrapperMode = true;
                applyWrapperFullscreenWindowFlags();

                if (dashboardWebView != null) {
                    dashboardWebView.setVisibility(View.GONE);
                }

                if (wrapperWebView != null) {
                    wrapperWebView.setVisibility(View.VISIBLE);
                    openInChildOverlay("file:///android_asset/settings.html");
                    hideSplashScreen();
                }
            }
        });
    }

    public void closeChildOverlayFromUi() {
        closeChildOverlay();
    }

    public void openConjureOsWrapperFromUi() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                loadConjureOsWrapperView(false);
            }
        });
    }

    public boolean hasExistingDefaultPackage() {
        File defaultRoot = new File(Environment.getExternalStorageDirectory(), "Conjure OS");
        File indexFile = new File(defaultRoot, "index.php");
        File appDirectory = new File(defaultRoot, "app");
        File appsDirectory = new File(defaultRoot, "apps");

        return indexFile.isFile()
            && indexFile.length() > 0
            && (appDirectory.isDirectory() || appsDirectory.isDirectory());
    }

    public boolean hasStoragePermission() {
        if (Build.VERSION.SDK_INT >= 30) {
            try {
                Class<?> environmentClass = Class.forName("android.os.Environment");
                java.lang.reflect.Method accessMethod = environmentClass.getMethod("isExternalStorageManager");
                return (Boolean) accessMethod.invoke(null);
            } catch (Exception ignored) {
                return false;
            }
        } else if (Build.VERSION.SDK_INT >= 23) {
            return checkSelfPermission(android.Manifest.permission.WRITE_EXTERNAL_STORAGE) == android.content.pm.PackageManager.PERMISSION_GRANTED;
        }
        return true;
    }

    public void requestStoragePermission() {
        requestSharedStorageAccessIfNeeded();
    }

    public boolean isBatteryOptimizationIgnored() {
        if (Build.VERSION.SDK_INT >= 23) {
            PowerManager pm = (PowerManager) getSystemService(POWER_SERVICE);
            if (pm != null) {
                return pm.isIgnoringBatteryOptimizations(getPackageName());
            }
        }
        return true;
    }

    public void requestIgnoreBatteryOptimizations() {
        if (Build.VERSION.SDK_INT >= 23) {
            try {
                Intent intent = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS);
                intent.setData(Uri.parse("package:" + getPackageName()));
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                startActivity(intent);
            } catch (Exception error) {
                updateInstallStatus("Battery Settings Error", "Unable to open battery optimization settings.", "error");
            }
        }
    }

    private void requestSharedStorageAccessIfNeeded() {
        if (Build.VERSION.SDK_INT >= 23
                && Build.VERSION.SDK_INT < 30
                && checkSelfPermission(android.Manifest.permission.WRITE_EXTERNAL_STORAGE)
                    != android.content.pm.PackageManager.PERMISSION_GRANTED) {
            requestPermissions(
                new String[]{android.Manifest.permission.WRITE_EXTERNAL_STORAGE},
                4102
            );
            return;
        }

        if (Build.VERSION.SDK_INT < 30) {
            return;
        }

        try {
            Class<?> environmentClass = Class.forName("android.os.Environment");
            java.lang.reflect.Method accessMethod = environmentClass.getMethod("isExternalStorageManager");
            boolean hasAccess = (Boolean) accessMethod.invoke(null);

            if (!hasAccess) {
                Intent accessIntent = new Intent(
                    "android.settings.MANAGE_APP_ALL_FILES_ACCESS_PERMISSION",
                    Uri.parse("package:" + getPackageName())
                );
                startActivity(accessIntent);
            }
        } catch (Exception ignored) {
        }
    }

    public void startRuntime() {
        Intent runtimeIntent = new Intent(this, RuntimeService.class);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            startForegroundService(runtimeIntent);
        } else {
            startService(runtimeIntent);
        }

        updateRuntimeStatus("STARTING", "Preparing PHP, Nginx, and SSL certificates...");
    }

    public void stopRuntime() {
        stopService(new Intent(this, RuntimeService.class));
        updateRuntimeStatus("STOPPED", "Runtime services stopped.");
    }

    public void openConjureOS() {
        int httpsPort = statePreferences.getInt("https_port", 8000);
        Intent browserIntent = new Intent(
            Intent.ACTION_VIEW,
            Uri.parse("https://127.0.0.1:" + httpsPort + "/")
        );

        try {
            startActivity(browserIntent);
        } catch (Exception error) {
            updateInstallStatus(
                "Unable to open Conjure OS",
                "No Android browser is available for the local runtime URL.",
                "error"
            );
        }
    }

    public void downloadRootCa() {
        try {
            File caCrt = new File(getFilesDir(), "runtime/ssl/ca.crt");
            File persistentCaCrt = new File(Environment.getExternalStorageDirectory(), "Conjure_Config/ssl/root_ca/ca.crt");
            File sourceCa = caCrt.exists() ? caCrt : persistentCaCrt;

            if (!sourceCa.exists()) {
                updateInstallStatus("Root CA not found", "Start the runtime once to generate the Root CA certificate.", "error");
                return;
            }

            File downloadDir = new File(Environment.getExternalStorageDirectory(), "Download");
            if (!downloadDir.exists()) {
                downloadDir.mkdirs();
            }
            File dest = new File(downloadDir, "Conjure-Runtime-Root-CA.crt");

            try (InputStream in = new FileInputStream(sourceCa);
                 FileOutputStream out = new FileOutputStream(dest)) {
                byte[] buf = new byte[8192];
                int len;
                while ((len = in.read(buf)) > 0) {
                    out.write(buf, 0, len);
                }
            }

            updateInstallStatus("Root CA exported", "Saved to " + dest.getAbsolutePath() + ". Tap it in file manager to install into Trusted Credentials.", "success");
        } catch (Exception e) {
            updateInstallStatus("CA Export Failed", e.getMessage() == null ? "Failed to export Root CA" : e.getMessage(), "error");
        }
    }

    public void openMainSettings() {
        try {
            Intent intent = new Intent(Settings.ACTION_SETTINGS);
            intent.putExtra("query", "certificate");
            intent.putExtra("search_query", "certificate");
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(intent);
        } catch (Exception e) {
            updateInstallStatus("Settings Error", "Unable to open system settings.", "error");
        }
    }

    private String updateLoopbackUrlPort(String url, int oldPort, int newPort) {
        if (url == null || url.trim().isEmpty()) {
            return "https://127.0.0.1:" + newPort + "/";
        }
        String trimmed = url.trim();
        try {
            android.net.Uri uri = android.net.Uri.parse(trimmed);
            String host = uri.getHost();
            if (host != null && (host.equals("127.0.0.1") || host.equals("localhost") || host.equals("0.0.0.0") || host.equals("conjure.local"))) {
                int port = uri.getPort();
                if (port <= 0 || port == oldPort || port == 8000 || port == 8001 || oldPort == 0) {
                    String scheme = uri.getScheme() != null ? uri.getScheme() : "https";
                    String path = uri.getEncodedPath() != null ? uri.getEncodedPath() : "/";
                    String query = uri.getEncodedQuery() != null ? "?" + uri.getEncodedQuery() : "";
                    String fragment = uri.getEncodedFragment() != null ? "#" + uri.getEncodedFragment() : "";
                    return scheme + "://" + host + ":" + newPort + path + query + fragment;
                }
            }
        } catch (Exception ignored) {}
        return trimmed;
    }

    public void setCustomPorts(int httpsPort, int httpPort) {
        if (statePreferences != null) {
            int oldHttpsPort = statePreferences.getInt("https_port", 8000);

            statePreferences.edit()
                .putInt("https_port", httpsPort)
                .putInt("http_port", httpPort)
                .apply();

            SharedPreferences urlPrefs = getSharedPreferences("UrlPrefs", MODE_PRIVATE);
            SharedPreferences.Editor editor = urlPrefs.edit();

            String customUrl = urlPrefs.getString("custom_url", "");
            if (!customUrl.isEmpty()) {
                editor.putString("custom_url", updateLoopbackUrlPort(customUrl, oldHttpsPort, httpsPort));
            } else {
                editor.putString("custom_url", "https://127.0.0.1:" + httpsPort + "/");
            }

            String lastVisitedUrl = urlPrefs.getString("last_visited_url", "");
            if (!lastVisitedUrl.isEmpty()) {
                editor.putString("last_visited_url", updateLoopbackUrlPort(lastVisitedUrl, oldHttpsPort, httpsPort));
            }

            String overlayUrl = urlPrefs.getString("overlay_tab_url", "");
            if (!overlayUrl.isEmpty()) {
                editor.putString("overlay_tab_url", updateLoopbackUrlPort(overlayUrl, oldHttpsPort, httpsPort));
            }

            String savedTabsJson = urlPrefs.getString("last_open_tabs_json", "");
            if (savedTabsJson != null && !savedTabsJson.isEmpty() && !savedTabsJson.equals("[]")) {
                try {
                    org.json.JSONArray arr = new org.json.JSONArray(savedTabsJson);
                    for (int i = 0; i < arr.length(); i++) {
                        org.json.JSONObject obj = arr.getJSONObject(i);
                        String tabUrl = obj.optString("url", "");
                        if (!tabUrl.isEmpty()) {
                            obj.put("url", updateLoopbackUrlPort(tabUrl, oldHttpsPort, httpsPort));
                        }
                    }
                    editor.putString("last_open_tabs_json", arr.toString());
                } catch (Exception ignored) {}
            }

            editor.apply();

            RuntimeService service = RuntimeService.getInstance();
            boolean isRunning = service != null && service.getProcessManager() != null && service.getProcessManager().isRunning();

            if (service != null && service.getProcessManager() != null) {
                service.getProcessManager().exportRuntimeActiveManifest(isRunning ? "RUNNING" : "STOPPED");
            }

            if (isRunning) {
                updateRuntimeStatus("RUNNING", "Ports updated to HTTPS: " + httpsPort + " | HTTP: " + httpPort + ". Restart runtime to apply changes.");
            } else {
                updateRuntimeStatus("STOPPED", "Ports updated to HTTPS: " + httpsPort + " | HTTP: " + httpPort + ". Tap Start Runtime to launch.");
            }
        }
    }

    public String getCustomPortsJson() {
        int https = statePreferences != null ? statePreferences.getInt("https_port", 8000) : 8000;
        int http = statePreferences != null ? statePreferences.getInt("http_port", 8001) : 8001;
        return "{\"https_port\":" + https + ",\"http_port\":" + http + "}";
    }

    public String getActiveNetworkIpsJson() {
        List<String> ips = SslManager.getActiveIpv4Addresses();
        StringBuilder sb = new StringBuilder("[");
        sb.append("\"127.0.0.1\"");
        for (String ip : ips) {
            sb.append(",\"").append(escapeJson(ip)).append("\"");
        }
        sb.append("]");
        return sb.toString();
    }

    public void copyLogsToClipboard() {
        final String formattedLogs = readAllLogs();
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                try {
                    android.content.ClipboardManager clipboard = (android.content.ClipboardManager) getSystemService(CLIPBOARD_SERVICE);
                    if (clipboard != null) {
                        android.content.ClipData clip = android.content.ClipData.newPlainText("Conjure Runtime Logs", formattedLogs);
                        clipboard.setPrimaryClip(clip);
                        updateInstallStatus("Logs copied to clipboard", "Runtime log output was copied to clipboard.", "success");
                    }
                } catch (Exception error) {
                    updateInstallStatus("Copy logs failed", error.getMessage() == null ? "Failed to copy logs" : error.getMessage(), "error");
                }
            }
        });
    }

    public void clearLogs() {
        File logDir = new File(getFilesDir(), "runtime/logs");
        if (logDir.exists() && logDir.isDirectory()) {
            File[] files = logDir.listFiles();
            if (files != null) {
                for (File f : files) {
                    if (f.isFile()) {
                        f.delete();
                    }
                }
            }
        }
        updateInstallStatus("Logs cleared", "All runtime log files have been reset.", "success");
    }

    public void openExternalUrl(final String url) {
        if (url == null || url.trim().isEmpty()) return;
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                try {
                    Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(url.trim()));
                    intent.addCategory(Intent.CATEGORY_BROWSABLE);
                    intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                    startActivity(intent);
                } catch (Exception e1) {
                    try {
                        Intent chooser = Intent.createChooser(new Intent(Intent.ACTION_VIEW, Uri.parse(url.trim())), "Open link in browser");
                        chooser.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                        startActivity(chooser);
                    } catch (Exception e2) {
                        updateInstallStatus("External Link Error", "Unable to launch browser for " + url, "error");
                    }
                }
            }
        });
    }

    public void saveTailscaleApiKey(String apiKey, String tags) {
        String cleanKey = apiKey == null ? "" : apiKey.trim();
        String cleanTags = tags == null ? "" : tags.trim();
        long now = System.currentTimeMillis();

        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }

        statePreferences.edit()
            .putString("tailscale_api_key", cleanKey)
            .putString("tailscale_tags", cleanTags)
            .putLong("tailscale_api_key_timestamp", now)
            .commit();

        try {
            File configDir = new File(getFilesDir(), "runtime/config");
            if (!configDir.exists()) configDir.mkdirs();

            File keyFile = new File(configDir, "tailscale_key.json");
            String json = "{\"api_key\":\"" + escapeJson(cleanKey) + "\",\"tags\":\"" + escapeJson(cleanTags) + "\",\"timestamp\":" + now + "}";

            try (FileOutputStream fos = new FileOutputStream(keyFile)) {
                fos.write(json.getBytes("UTF-8"));
                fos.flush();
                fos.getFD().sync();
            }
            updateInstallStatus("API Key Saved", "Credentials saved to storage. Tailscale HTTPS auto-activation enabled.", "success");
        } catch (Exception e) {
            updateInstallStatus("API Key Save Error", "Failed writing key to storage: " + e.getMessage(), "error");
        }
    }

    public String getTailscaleApiKeyJson() {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }

        String prefKey = statePreferences.getString("tailscale_api_key", "");
        String prefTags = statePreferences.getString("tailscale_tags", "");
        long prefTimestamp = statePreferences.getLong("tailscale_api_key_timestamp", 0L);

        if (prefKey != null && !prefKey.trim().isEmpty()) {
            return "{\"api_key\":\"" + escapeJson(prefKey.trim()) + "\",\"tags\":\"" + escapeJson(prefTags) + "\",\"timestamp\":" + prefTimestamp + "}";
        }

        File keyFile = new File(getFilesDir(), "runtime/config/tailscale_key.json");
        if (keyFile.exists() && keyFile.isFile() && keyFile.length() > 0) {
            try (BufferedReader br = new BufferedReader(new FileReader(keyFile))) {
                StringBuilder sb = new StringBuilder();
                String line;
                while ((line = br.readLine()) != null) {
                    sb.append(line);
                }
                String content = sb.toString().trim();
                if (!content.isEmpty()) return content;
            } catch (Exception ignored) {
            }
        }

        return "{\"api_key\":\"\",\"tags\":\"\",\"timestamp\":0}";
    }

    public void startTailscale() {
        RuntimeService service = RuntimeService.getInstance();
        final int httpsPort = statePreferences.getInt("https_port", 8000);
        final int httpPort = statePreferences.getInt("http_port", 8001);

        String key = "";
        String tags = "";
        try {
            String jsonRaw = getTailscaleApiKeyJson();
            if (jsonRaw != null && !jsonRaw.trim().isEmpty()) {
                org.json.JSONObject obj = new org.json.JSONObject(jsonRaw);
                key = obj.optString("api_key", "");
                tags = obj.optString("tags", "");
            }
        } catch (Exception ignored) {}

        final String apiKey = key;
        final String oauthTags = tags;

        if (service == null) {
            startRuntime();
            new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
                @Override
                public void run() {
                    RuntimeService s = RuntimeService.getInstance();
                    if (s != null && s.getProcessManager() != null) {
                        s.getProcessManager().getTailscaleManager().start(httpsPort, httpPort, apiKey, oauthTags);
                    }
                }
            }, 800);
            return;
        }

        if (service.getProcessManager() != null) {
            service.getProcessManager().getTailscaleManager().start(httpsPort, httpPort, apiKey, oauthTags);
        }
    }

    public void stopTailscale() {
        RuntimeService service = RuntimeService.getInstance();
        if (service != null && service.getProcessManager() != null) {
            service.getProcessManager().getTailscaleManager().stop();
        }
    }

    private void setCollisionFlagOnDisk(boolean active, String domain) {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }
        statePreferences.edit()
            .putBoolean("auto_collision_flag", active)
            .putString("auto_collision_domain", domain == null ? "" : domain)
            .apply();
    }

    private boolean isCollisionFlagActiveOnDisk() {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }
        return statePreferences.getBoolean("auto_collision_flag", false);
    }

    private String getCollisionDomainOnDisk() {
        if (statePreferences == null) {
            statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
        }
        return statePreferences.getString("auto_collision_domain", "");
    }

    public void dismissCollisionFlag() {
        setCollisionFlagOnDisk(false, "");
        updateInstallStatus("Warning Dismissed", "Numbered domain warning dismissed.", "success");
    }

    public String getTailscaleStatusJson() {
        RuntimeService service = RuntimeService.getInstance();
        if (service != null && service.getProcessManager() != null) {
            TailscaleManager tm = service.getProcessManager().getTailscaleManager();
            String status = tm.getStatus();
            String magicDns = tm.getMagicDns();

            if (!magicDns.isEmpty()) {
                String hostPrefix = magicDns.contains(".") ? magicDns.substring(0, magicDns.indexOf('.')) : magicDns;
                if (hostPrefix.matches(".*-\\d+$")) {
                    setCollisionFlagOnDisk(true, magicDns);
                }
            }

            boolean collisionActive = isCollisionFlagActiveOnDisk();
            String collisionDomain = getCollisionDomainOnDisk();

            return "{\"status\":\"" + escapeJson(status)
                + "\",\"auth_url\":\"" + escapeJson(tm.getAuthUrl())
                + "\",\"ip\":\"" + escapeJson(tm.getTailscaleIp())
                + "\",\"magic_dns\":\"" + escapeJson(magicDns)
                + "\",\"cert_ready\":" + tm.isCertReady()
                + ",\"collision_flag\":" + collisionActive
                + ",\"collision_domain\":\"" + escapeJson(collisionDomain) + "\"}";
        }

        boolean collisionActive = isCollisionFlagActiveOnDisk();
        String collisionDomain = getCollisionDomainOnDisk();
        return "{\"status\":\"STOPPED\",\"auth_url\":\"\",\"ip\":\"\",\"magic_dns\":\"\",\"cert_ready\":false,\"collision_flag\":" + collisionActive + ",\"collision_domain\":\"" + escapeJson(collisionDomain) + "\"}";
    }

    public void openTailscaleAuthUrl() {
        RuntimeService service = RuntimeService.getInstance();
        if (service != null && service.getProcessManager() != null) {
            String authUrl = service.getProcessManager().getTailscaleManager().getAuthUrl();
            if (authUrl != null && !authUrl.isEmpty()) {
                Intent browserIntent = new Intent(Intent.ACTION_VIEW, Uri.parse(authUrl));
                try {
                    startActivity(browserIntent);
                } catch (Exception error) {
                    updateInstallStatus("Auth Link Error", "Unable to launch browser for Tailscale authentication.", "error");
                }
            }
        }
    }

    public String getTailscaleLog() {
        RuntimeService service = RuntimeService.getInstance();
        if (service != null && service.getProcessManager() != null) {
            return service.getProcessManager().getTailscaleManager().readLog();
        }
        File logFile = new File(getFilesDir(), "runtime/logs/tailscale.log");
        if (logFile.exists()) {
            StringBuilder sb = new StringBuilder();
            try (BufferedReader br = new BufferedReader(new FileReader(logFile))) {
                String line;
                while ((line = br.readLine()) != null) {
                    sb.append(line).append("\n");
                }
            } catch (Exception ignored) {}
            return sb.toString();
        }
        return "Tailscale is not running.";
    }

    public void resetTailscaleNode() {
        RuntimeService service = RuntimeService.getInstance();
        if (service != null && service.getProcessManager() != null) {
            service.getProcessManager().getTailscaleManager().resetNodeState();
            updateInstallStatus("Tailscale Node Reset", "Node identity and credentials were cleared.", "success");
        } else {
            File tsDir = new File(getFilesDir(), "runtime/tailscale");
            ZipImporter.deleteRecursively(tsDir);
            updateInstallStatus("Tailscale Node Reset", "Local node state directory was cleared.", "success");
        }
    }

    public void logoutTailscale() {
        String savedKey = "";
        try {
            String jsonRaw = getTailscaleApiKeyJson();
            if (jsonRaw != null && !jsonRaw.trim().isEmpty()) {
                org.json.JSONObject obj = new org.json.JSONObject(jsonRaw);
                savedKey = obj.optString("api_key", "");
            }
        } catch (Exception ignored) {}

        final String apiKey = savedKey;

        RuntimeService service = RuntimeService.getInstance();
        if (service != null && service.getProcessManager() != null) {
            service.getProcessManager().getTailscaleManager().stop();
        }

        new Thread(new Runnable() {
            @Override
            public void run() {
                File logFile = new File(getFilesDir(), "runtime/logs/tailscale.log");
                appendTsLog(logFile, "[logout] 1. Initiating complete Tailscale Logout & Remote Device Purge...");

                if (apiKey != null && !apiKey.trim().isEmpty()) {
                    appendTsLog(logFile, "[logout] 2. API Key / Secret detected. Reaching out over the internet to Tailscale API...");
                    deleteRemoteTailscaleDevicesLogged(apiKey.trim(), "conjure", logFile);
                } else {
                    appendTsLog(logFile, "[logout] 2. No saved API Key found. Skipping remote device API deletion.");
                }

                appendTsLog(logFile, "[logout] 3. Pausing 200ms for OS process file locks to release...");
                try { Thread.sleep(200); } catch (Exception ignored) {}

                appendTsLog(logFile, "[logout] 4. Purging specific active domain subfolder from persistent vault (/sdcard/.conjure_os_ssl/)...");
                File persistentBaseDir = new File(Environment.getExternalStorageDirectory(), ".conjure_os_ssl");
                File activeDomainFile = new File(persistentBaseDir, "active_domain.txt");
                if (activeDomainFile.exists() && activeDomainFile.length() > 0) {
                    try (BufferedReader br = new BufferedReader(new FileReader(activeDomainFile))) {
                        String activeDomain = br.readLine();
                        if (activeDomain != null && !activeDomain.trim().isEmpty()) {
                            File domainDir = new File(persistentBaseDir, "domains/" + activeDomain.trim());
                            ZipImporter.deleteRecursively(domainDir);
                            appendTsLog(logFile, "[logout] -> Purged persistent vault subfolder: " + domainDir.getAbsolutePath());
                        }
                    } catch (Exception ignored) {}
                    activeDomainFile.delete();
                }

                setCollisionFlagOnDisk(false, "");

                appendTsLog(logFile, "[logout] 5. Purging local Tailscale node state directory (/runtime/tailscale/)...");
                RuntimeService s = RuntimeService.getInstance();
                if (s != null && s.getProcessManager() != null) {
                    s.getProcessManager().getTailscaleManager().resetNodeState();
                } else {
                    File tsDir = new File(getFilesDir(), "runtime/tailscale");
                    ZipImporter.deleteRecursively(tsDir);
                }

                appendTsLog(logFile, "[logout] 5. Erasing saved API keys and auth tokens from SharedPreferences & disk...");
                if (statePreferences == null) {
                    statePreferences = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
                }
                statePreferences.edit()
                    .remove("tailscale_api_key")
                    .remove("tailscale_tags")
                    .remove("tailscale_api_key_timestamp")
                    .commit();

                File keyFile = new File(getFilesDir(), "runtime/config/tailscale_key.json");
                if (keyFile.exists()) {
                    keyFile.delete();
                }

                appendTsLog(logFile, "[logout] ✨ Tailscale Logout Complete! All local state and remote instances purged.");
                updateInstallStatus("Tailscale Logged Out", "Remote Tailscale nodes deleted & local state purged.", "success");
            }
        }).start();
    }

    private void deleteRemoteTailscaleDevicesLogged(String apiKey, String baseHostname, File logFile) {
        try {
            String token = apiKey;
            if (apiKey.startsWith("tskey-client-")) {
                appendTsLog(logFile, "[logout] Exchanging OAuth Client Secret for Bearer token via Tailscale API...");
                token = fetchOAuthTokenJava(apiKey);
                if (token != null && !token.isEmpty()) {
                    appendTsLog(logFile, "[logout] OAuth Bearer token acquired successfully.");
                } else {
                    appendTsLog(logFile, "[logout] ERROR: OAuth token exchange failed. Check client secret permissions.");
                    return;
                }
            }

            appendTsLog(logFile, "[logout] Requesting device list from GET https://api.tailscale.com/api/v2/tailnet/-/devices...");
            URL url = new URL("https://api.tailscale.com/api/v2/tailnet/-/devices");
            HttpURLConnection conn = (HttpURLConnection) url.openConnection();
            conn.setRequestMethod("GET");
            conn.setConnectTimeout(10000);
            conn.setReadTimeout(15000);
            conn.setRequestProperty("Authorization", "Bearer " + token);

            int code = conn.getResponseCode();
            appendTsLog(logFile, "[logout] Device list API response code: HTTP " + code);

            if (code < 200 || code >= 300) {
                appendTsLog(logFile, "[logout] ERROR: Unable to fetch devices. Check API key rights (need Devices Read/Write).");
                conn.disconnect();
                return;
            }

            BufferedReader br = new BufferedReader(new InputStreamReader(conn.getInputStream()));
            StringBuilder sb = new StringBuilder();
            String line;
            while ((line = br.readLine()) != null) {
                sb.append(line);
            }
            br.close();
            conn.disconnect();

            org.json.JSONObject response = new org.json.JSONObject(sb.toString());
            if (!response.has("devices")) {
                appendTsLog(logFile, "[logout] Notice: No 'devices' array returned in API payload.");
                return;
            }

            org.json.JSONArray devices = response.getJSONArray("devices");
            String baseLower = baseHostname.toLowerCase(Locale.US);
            int matchCount = 0;
            int deleteCount = 0;

            appendTsLog(logFile, "[logout] Scanning " + devices.length() + " tailnet devices for matches against '" + baseHostname + "'...");

            for (int i = 0; i < devices.length(); i++) {
                org.json.JSONObject dev = devices.getJSONObject(i);
                String devId = dev.optString("id", "");
                String devHost = dev.optString("hostname", "").toLowerCase(Locale.US);
                String devName = dev.optString("name", "").toLowerCase(Locale.US);

                boolean isMatch = devHost.equals(baseLower) ||
                    devHost.startsWith(baseLower + "-") ||
                    devHost.startsWith("conjure-runtime") ||
                    devHost.startsWith("tailscale-termux") ||
                    devName.startsWith(baseLower + ".") ||
                    devName.startsWith(baseLower + "-") ||
                    devName.startsWith("tailscale-termux");

                if (isMatch && !devId.isEmpty()) {
                    matchCount++;
                    appendTsLog(logFile, String.format(Locale.US,
                        "[logout] Found matching remote node: '%s' (Hostname: '%s', ID: %s)",
                        dev.optString("name", ""), dev.optString("hostname", ""), devId));

                    try {
                        URL delUrl = new URL("https://api.tailscale.com/api/v2/device/" + devId);
                        HttpURLConnection delConn = (HttpURLConnection) delUrl.openConnection();
                        delConn.setRequestMethod("DELETE");
                        delConn.setConnectTimeout(8000);
                        delConn.setRequestProperty("Authorization", "Bearer " + token);

                        int delCode = delConn.getResponseCode();
                        delConn.disconnect();

                        if (delCode >= 200 && delCode < 300) {
                            deleteCount++;
                            appendTsLog(logFile, "[logout] -> Successfully DELETED remote device " + devId + " (HTTP " + delCode + ")");
                        } else {
                            appendTsLog(logFile, "[logout] -> FAILED to delete device " + devId + " (HTTP " + delCode + ")");
                        }
                    } catch (Exception delErr) {
                        appendTsLog(logFile, "[logout] -> Exception deleting device " + devId + ": " + delErr.getMessage());
                    }
                }
            }

            appendTsLog(logFile, String.format(Locale.US,
                "[logout] Remote device cleanup summary: %d matched, %d deleted.",
                matchCount, deleteCount));

        } catch (Exception e) {
            appendTsLog(logFile, "[logout] ERROR in remote device cleanup: " + e.getMessage());
        }
    }

    private void appendTsLog(File logFile, String message) {
        try {
            File parent = logFile.getParentFile();
            if (parent != null && !parent.exists()) parent.mkdirs();

            java.text.SimpleDateFormat sdf = new java.text.SimpleDateFormat("yyyy/MM/dd HH:mm:ss", Locale.US);
            String timestamp = sdf.format(new java.util.Date());
            String logLine = timestamp + " " + message + "\n";

            try (FileOutputStream fos = new FileOutputStream(logFile, true)) {
                fos.write(logLine.getBytes("UTF-8"));
                fos.flush();
            }
        } catch (Exception ignored) {}
    }

    private String fetchOAuthTokenJava(String clientSecret) {
        try {
            URL url = new URL("https://api.tailscale.com/api/v2/oauth/token");
            HttpURLConnection conn = (HttpURLConnection) url.openConnection();
            conn.setRequestMethod("POST");
            conn.setDoOutput(true);
            conn.setConnectTimeout(10000);
            conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded");

            String body = "client_secret=" + java.net.URLEncoder.encode(clientSecret, "UTF-8");
            java.io.OutputStream os = conn.getOutputStream();
            os.write(body.getBytes("UTF-8"));
            os.flush();
            os.close();

            if (conn.getResponseCode() == 200) {
                BufferedReader br = new BufferedReader(new InputStreamReader(conn.getInputStream()));
                StringBuilder sb = new StringBuilder();
                String line;
                while ((line = br.readLine()) != null) {
                    sb.append(line);
                }
                br.close();
                org.json.JSONObject res = new org.json.JSONObject(sb.toString());
                return res.optString("access_token", "");
            }
        } catch (Exception ignored) {}
        return "";
    }

    private String readAllLogs() {
        StringBuilder sb = new StringBuilder();
        File logDir = new File(getFilesDir(), "runtime/logs");

        appendLogFile(sb, "OPENSSL LOG", new File(logDir, "ssl.log"));
        appendLogFile(sb, "PHP LOG", new File(logDir, "php.log"));
        appendLogFile(sb, "NGINX LOG", new File(logDir, "nginx.log"));
        appendLogFile(sb, "NGINX ERROR LOG", new File(logDir, "nginx-error.log"));
        appendLogFile(sb, "TAILSCALE LOG", new File(logDir, "tailscale.log"));

        if (sb.length() == 0) {
            return "No runtime logs found in " + logDir.getAbsolutePath();
        }
        return sb.toString().trim();
    }

    private void appendLogFile(StringBuilder sb, String title, File file) {
        if (file.exists() && file.isFile() && file.length() > 0) {
            sb.append("=== ").append(title).append(" (").append(file.getName()).append(") ===\n");
            try (BufferedReader br = new BufferedReader(new FileReader(file))) {
                String line;
                while ((line = br.readLine()) != null) {
                    sb.append(line).append("\n");
                }
            } catch (Exception e) {
                sb.append("Error reading log: ").append(e.getMessage()).append("\n");
            }
            sb.append("\n");
        }
    }

    public String getMdnsStatusJson() {
        RuntimeService service = RuntimeService.getInstance();
        if (service != null && service.getProcessManager() != null && service.getProcessManager().getMdnsManager() != null) {
            return service.getProcessManager().getMdnsManager().getStatusJson();
        }
        return "{\"status\":\"STOPPED\",\"message\":\"Runtime services not running\",\"active_ip\":\"\",\"queries_handled\":0,\"verified\":false}";
    }

    public String getMdnsLog() {
        RuntimeService service = RuntimeService.getInstance();
        if (service != null && service.getProcessManager() != null && service.getProcessManager().getMdnsManager() != null) {
            return service.getProcessManager().getMdnsManager().readLog();
        }
        File logFile = new File(getFilesDir(), "runtime/logs/mdns.log");
        if (logFile.exists()) {
            StringBuilder sb = new StringBuilder();
            try (BufferedReader br = new BufferedReader(new FileReader(logFile))) {
                String line;
                while ((line = br.readLine()) != null) sb.append(line).append("\n");
            } catch (Exception ignored) {}
            return sb.toString();
        }
        return "mDNS responder is not running.";
    }

    public String getRuntimeStatusJson() {
        return "{\"status\":\"" + escapeJson(runtimeStatus)
            + "\",\"message\":\"" + escapeJson(runtimeMessage) + "\"}";
    }

    public String getRuntimeBundleInfoJson() {
        String abi = (Build.VERSION.SDK_INT >= 21
            && Build.SUPPORTED_ABIS != null
            && Build.SUPPORTED_ABIS.length > 0)
            ? Build.SUPPORTED_ABIS[0]
            : "arm64-v8a";

        return "{\"abi\":\"" + escapeJson(abi)
            + "\",\"required\":[\"libphp.so\",\"libnginx.so\"]}";
    }

    private void evalJsOnAllWebViews(String js) {
        if (dashboardWebView != null) {
            dashboardWebView.evaluateJavascript(js, null);
        }
        if (wrapperWebView != null) {
            wrapperWebView.evaluateJavascript(js, null);
        }
        if (mSecondaryWebView != null) {
            mSecondaryWebView.evaluateJavascript(js, null);
        }
        if (mTabList != null) {
            for (TabItem tab : mTabList) {
                if (tab != null && tab.webView != null) {
                    tab.webView.evaluateJavascript(js, null);
                }
            }
        }
    }

    private void updateRuntimeStatus(String status, String message) {
        runtimeStatus = status;
        runtimeMessage = message;

        if (statePreferences != null) {
            statePreferences.edit()
                .putString("runtime_status", runtimeStatus)
                .putString("runtime_message", runtimeMessage)
                .apply();
        }

        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                String js = "if(window.updateRuntimeStatus) window.updateRuntimeStatus("
                    + quoteJs(runtimeStatus) + ","
                    + quoteJs(runtimeMessage) + ");";
                evalJsOnAllWebViews(js);
            }
        });
    }

    public void openFolderPicker() {
        try {
            Intent intent = new Intent(Intent.ACTION_OPEN_DOCUMENT_TREE);
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION
                | Intent.FLAG_GRANT_WRITE_URI_PERMISSION
                | Intent.FLAG_GRANT_PERSISTABLE_URI_PERMISSION);
            startActivityForResult(intent, FOLDER_PICKER_REQUEST);
        } catch (Exception e) {
            updateInstallStatus("Folder Picker Error", "Unable to open system folder picker: " + e.getMessage(), "error");
        }
    }

    private String resolvePathFromTreeUri(Uri treeUri) {
        if (treeUri == null) return null;

        String path = treeUri.getPath();
        String docId = null;

        if (Build.VERSION.SDK_INT >= 21 && android.provider.DocumentsContract.isTreeUri(treeUri)) {
            docId = android.provider.DocumentsContract.getTreeDocumentId(treeUri);
        } else if (path != null) {
            docId = path;
        }

        if (docId != null) {
            if (docId.startsWith("primary:")) {
                String subPath = docId.substring("primary:".length());
                File externalStorage = Environment.getExternalStorageDirectory();
                return new File(externalStorage, subPath).getAbsolutePath();
            } else if (docId.contains("primary:")) {
                int idx = docId.indexOf("primary:");
                String subPath = docId.substring(idx + "primary:".length());
                File externalStorage = Environment.getExternalStorageDirectory();
                return new File(externalStorage, subPath).getAbsolutePath();
            } else if (docId.contains(":")) {
                String[] parts = docId.split(":");
                if (parts.length >= 2) {
                    String volume = parts[0];
                    String subPath = parts[1];
                    if (volume.contains("/")) {
                        volume = volume.substring(volume.lastIndexOf('/') + 1);
                    }
                    File storageDir = new File("/storage/" + volume);
                    if (storageDir.exists()) {
                        return new File(storageDir, subPath).getAbsolutePath();
                    }
                }
            }
        }

        if (path != null) {
            if (path.startsWith("/storage/") || path.startsWith("/sdcard/")) {
                return path;
            }
        }

        return null;
    }

    public void openConjureZipPicker() {
        Intent picker = new Intent(Intent.ACTION_OPEN_DOCUMENT);
        picker.addCategory(Intent.CATEGORY_OPENABLE);
        picker.setType("*/*");
        picker.putExtra(Intent.EXTRA_MIME_TYPES, new String[]{"application/zip", "application/octet-stream", "*/*"});
        startActivityForResult(picker, ZIP_PICKER_REQUEST);
    }

    public void downloadConjureZip(final String sourceUrl) {
        if (sourceUrl == null || sourceUrl.trim().isEmpty()) {
            updateInstallStatus("Invalid package URL", "A download URL is required.", "error");
            return;
        }

        try {
            URL validatedUrl = new URL(sourceUrl.trim());
            String protocol = validatedUrl.getProtocol();
            if (!"http".equalsIgnoreCase(protocol) && !"https".equalsIgnoreCase(protocol)) {
                updateInstallStatus("Invalid package URL", "Only HTTP and HTTPS package URLs are supported.", "error");
                return;
            }
        } catch (Exception error) {
            updateInstallStatus("Invalid package URL", "The supplied package URL could not be parsed.", "error");
            return;
        }

        synchronized (this) {
            if (remoteDownloadInProgress) {
                updateInstallStatus("Download already active", "Wait for the current package download to finish.", "");
                return;
            }
            remoteDownloadInProgress = true;
        }

        new Thread(new Runnable() {
            @Override
            public void run() {
                HttpURLConnection connection = null;

                try {
                    updateInstallStatus(
                        "Downloading package",
                        "Connecting to the supplied Conjure OS URL.",
                        ""
                    );

                    URL url = new URL(sourceUrl);
                    connection = (HttpURLConnection) url.openConnection();
                    connection.setConnectTimeout(15000);
                    connection.setReadTimeout(120000);
                    connection.setInstanceFollowRedirects(true);
                    connection.setRequestProperty("User-Agent", "ConjureRuntime/1.0");

                    int responseCode = connection.getResponseCode();
                    if (responseCode < 200 || responseCode >= 300) {
                        throw new Exception("Download failed with HTTP " + responseCode);
                    }

                    long declaredLength = connection.getContentLengthLong();
                    if (declaredLength > MAX_REMOTE_ZIP_BYTES) {
                        throw new Exception("Package exceeds the 512 MB download limit");
                    }

                    InputStream input = new LimitedInputStream(connection.getInputStream(), MAX_REMOTE_ZIP_BYTES);
                    try {
                        File stagingRoot = new File(getFilesDir(), "staging/conjure-os");
                        File sharedRoot = new File(Environment.getExternalStorageDirectory(), "Conjure OS");

                        RuntimeService activeService = RuntimeService.getInstance();
                        final boolean wasRunning = (activeService != null && activeService.getProcessManager() != null && activeService.getProcessManager().isRunning());

                        if (wasRunning) {
                            updateInstallStatus("Pausing Runtime", "Releasing open file locks for clean overwrite...", "importing", 15);
                            stopRuntime();
                            try { Thread.sleep(600); } catch (Exception ignored) {}
                        }

                        ZipImporter.importZip(input, stagingRoot, sharedRoot, new ZipImporter.ProgressListener() {
                            @Override
                            public void onProgress(String stage, String detail, int percent) {
                                updateInstallStatus(stage, detail, "importing", percent);
                            }
                        });

                        updateInstallStatus(
                            "Conjure OS Package Installed",
                            String.format(Locale.US, "Files are ready at %s", sharedRoot.getAbsolutePath()),
                            "success",
                            100
                        );

                        if (wasRunning) {
                            new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
                                @Override
                                public void run() {
                                    startRuntime();
                                }
                            }, 500);
                        }
                    } finally {
                        input.close();
                    }
                } catch (final Exception error) {
                    updateInstallStatus(
                        "Package download failed",
                        error.getMessage() == null ? "The package could not be downloaded." : error.getMessage(),
                        "error"
                    );
                } finally {
                    if (connection != null) {
                        connection.disconnect();
                    }
                    remoteDownloadInProgress = false;
                }
            }
        }).start();
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == RECORD_AUDIO_REQUEST_CODE) {
            if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                if (mPendingPermissionRequest != null) {
                    mPendingPermissionRequest.grant(mPendingPermissionRequest.getResources());
                    mPendingPermissionRequest = null;
                }
            } else {
                if (mPendingPermissionRequest != null) {
                    mPendingPermissionRequest.deny();
                    mPendingPermissionRequest = null;
                }
                Toast.makeText(this, "Microphone permission is required for voice recording.", Toast.LENGTH_SHORT).show();
            }
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);

        if (requestCode == FILE_CHOOSER_REQUEST_CODE) {
            if (mFilePathCallback == null) return;
            Uri[] results = null;
            if (resultCode == RESULT_OK && data != null) {
                String dataString = data.getDataString();
                android.content.ClipData clipData = data.getClipData();
                if (clipData != null) {
                    results = new Uri[clipData.getItemCount()];
                    for (int i = 0; i < clipData.getItemCount(); i++) {
                        results[i] = clipData.getItemAt(i).getUri();
                    }
                } else if (dataString != null) {
                    results = new Uri[]{Uri.parse(dataString)};
                }
            }
            mFilePathCallback.onReceiveValue(results);
            mFilePathCallback = null;
            return;
        }

        if (requestCode == FOLDER_PICKER_REQUEST) {
            if (resultCode != RESULT_OK || data == null || data.getData() == null) {
                return;
            }

            final Uri treeUri = data.getData();
            try {
                getContentResolver().takePersistableUriPermission(
                    treeUri,
                    Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_GRANT_WRITE_URI_PERMISSION
                );
            } catch (Exception ignored) {}

            String selectedPath = resolvePathFromTreeUri(treeUri);
            if (selectedPath != null && !selectedPath.trim().isEmpty()) {
                addSystemPath(selectedPath);
            } else {
                updateInstallStatus("Folder Selection Failed", "Unable to resolve physical directory path from selection.", "error");
            }
            return;
        }

        if (requestCode != ZIP_PICKER_REQUEST || resultCode != RESULT_OK || data == null || data.getData() == null) {
            return;
        }

        final Uri selectedUri = data.getData();
        try {
            getContentResolver().takePersistableUriPermission(
                selectedUri,
                Intent.FLAG_GRANT_READ_URI_PERMISSION
            );
        } catch (Exception ignored) {
        }

        updateInstallStatus("Preparing Package", "Reading selected ZIP from storage...", "importing", 10);

        new Thread(new Runnable() {
            @Override
            public void run() {
                RuntimeService activeService = RuntimeService.getInstance();
                final boolean wasRunning = (activeService != null && activeService.getProcessManager() != null && activeService.getProcessManager().isRunning());

                try {
                    if (wasRunning) {
                        updateInstallStatus("Pausing Runtime", "Releasing open file locks for clean overwrite...", "importing", 15);
                        stopRuntime();
                        try { Thread.sleep(600); } catch (Exception ignored) {}
                    }

                    File stagingRoot = new File(getFilesDir(), "staging/conjure-os");
                    File sharedRoot = new File(Environment.getExternalStorageDirectory(), "Conjure OS");

                    InputStream input = getContentResolver().openInputStream(selectedUri);
                    if (input == null) {
                        throw new Exception("Unable to open the selected ZIP");
                    }

                    try (InputStream source = input) {
                        ZipImporter.importZip(source, stagingRoot, sharedRoot, new ZipImporter.ProgressListener() {
                            @Override
                            public void onProgress(String stage, String detail, int percent) {
                                updateInstallStatus(stage, detail, "importing", percent);
                            }
                        });
                    }

                    updateInstallStatus(
                        "Conjure OS Package Installed",
                        String.format(Locale.US, "Files are ready at %s", sharedRoot.getAbsolutePath()),
                        "success",
                        100
                    );

                    if (wasRunning) {
                        new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
                            @Override
                            public void run() {
                                startRuntime();
                            }
                        }, 500);
                    }
                } catch (final Exception error) {
                    updateInstallStatus(
                        "Package Import Failed",
                        error.getMessage() == null ? "The selected package could not be imported." : error.getMessage(),
                        "error",
                        0
                    );
                }
            }
        }).start();
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);

        if (intent != null && intent.getAction() != null) {
            String action = intent.getAction();
            String pkg = getPackageName();
            if ((pkg + ".OPEN_RUNTIME_SETTINGS").equals(action)) {
                loadDashboardView();
            } else if ((pkg + ".OPEN_WRAPPER_SETTINGS").equals(action)) {
                showWrapperSettingsDialog();
            } else if ((pkg + ".OPEN_CONJURE_OS").equals(action) || Intent.ACTION_MAIN.equals(action)) {
                if (!isWrapperMode) {
                    loadConjureOsWrapperView(false);
                } else {
                    hideSplashScreen();
                }
            }
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        registerShakeSensorIfNeeded();
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                evalJsOnAllWebViews("if(window.checkPermissionsLive) window.checkPermissionsLive();");
            }
        });
    }

    @Override
    protected void onDestroy() {
        try {
            unregisterReceiver(runtimeStatusReceiver);
        } catch (Exception ignored) {
        }

        if (dashboardWebView != null) {
            dashboardWebView.stopLoading();
            dashboardWebView.destroy();
            dashboardWebView = null;
        }

        if (wrapperWebView != null) {
            wrapperWebView.stopLoading();
            wrapperWebView.destroy();
            wrapperWebView = null;
        }

        super.onDestroy();
    }

    private static final class LimitedInputStream extends FilterInputStream {
        private final long maximum;
        private long consumed;

        LimitedInputStream(InputStream input, long maximum) {
            super(input);
            this.maximum = maximum;
        }

        @Override
        public int read() throws java.io.IOException {
            if (consumed >= maximum) {
                throw new java.io.IOException("Package exceeds the 512 MB download limit");
            }

            int value = super.read();
            if (value != -1) {
                consumed++;
            }
            return value;
        }

        @Override
        public int read(byte[] buffer, int offset, int length) throws java.io.IOException {
            if (consumed >= maximum) {
                throw new java.io.IOException("Package exceeds the 512 MB download limit");
            }

            int allowed = (int) Math.min(length, maximum - consumed);
            int count = super.read(buffer, offset, allowed);
            if (count > 0) {
                consumed += count;
            }
            return count;
        }
    }

    public String getInstallStatusJson() {
        return "{\"title\":\"" + escapeJson(installTitle)
            + "\",\"message\":\"" + escapeJson(installMessage)
            + "\",\"type\":\"" + escapeJson(installType) + "\"}";
    }

    private void updateInstallStatus(final String title, final String message, final String type) {
        updateInstallStatus(title, message, type, 0);
    }

    private void updateInstallStatus(final String title, final String message, final String type, final int progress) {
        installTitle = title;
        installMessage = message;
        installType = type;
        persistInstallStatus();

        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                String js = "if(window.updateInstallStatus) window.updateInstallStatus("
                    + quoteJs(title) + ","
                    + quoteJs(message) + ","
                    + quoteJs(type) + ","
                    + progress + ");";
                evalJsOnAllWebViews(js);
            }
        });
    }

    private void persistInstallStatus() {
        if (statePreferences != null) {
            statePreferences.edit()
                .putString("install_title", installTitle)
                .putString("install_message", installMessage)
                .putString("install_type", installType)
                .apply();
        }
    }

    private String quoteJs(String value) {
        return "'" + escapeJs(value) + "'";
    }

    private String escapeJs(String value) {
        return value.replace("\\", "\\\\")
            .replace("'", "\\'")
            .replace("\r", "\\r")
            .replace("\n", "\\n");
    }

    private String escapeJson(String value) {
        return value.replace("\\", "\\\\")
            .replace("\"", "\\\"")
            .replace("\r", "\\r")
            .replace("\n", "\\n");
    }

    private int dpToPx(int dp) {
        float density = getResources().getDisplayMetrics().density;
        return Math.round((float) dp * density);
    }

    public void processBlobDownload(final String dataUrl, final String contentDisposition, final String mimeType) {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                handleDownload(dataUrl, null, contentDisposition, mimeType, 0);
            }
        });
    }

    private boolean isLocalLoopbackUrl(String url) {
        if (url == null) return false;
        String lower = url.toLowerCase(Locale.US);
        return lower.contains("127.0.0.1") || lower.contains("localhost") || lower.contains("conjure.local");
    }

    private void downloadLocalFileInApp(final String url, final String userAgent, final String contentDisposition, final String mimeType) {
        Toast.makeText(getApplicationContext(), "Downloading local file...", Toast.LENGTH_SHORT).show();
        new Thread(new Runnable() {
            @Override
            public void run() {
                HttpURLConnection conn = null;
                try {
                    URL requestUrl = new URL(url);
                    conn = (HttpURLConnection) requestUrl.openConnection();

                    if (conn instanceof javax.net.ssl.HttpsURLConnection) {
                        javax.net.ssl.HttpsURLConnection httpsConn = (javax.net.ssl.HttpsURLConnection) conn;
                        javax.net.ssl.SSLContext sc = javax.net.ssl.SSLContext.getInstance("TLS");
                        sc.init(null, new javax.net.ssl.TrustManager[]{
                            new javax.net.ssl.X509TrustManager() {
                                public java.security.cert.X509Certificate[] getAcceptedIssuers() { return null; }
                                public void checkClientTrusted(java.security.cert.X509Certificate[] certs, String authType) {}
                                public void checkServerTrusted(java.security.cert.X509Certificate[] certs, String authType) {}
                            }
                        }, new java.security.SecureRandom());
                        httpsConn.setSSLSocketFactory(sc.getSocketFactory());
                        httpsConn.setHostnameVerifier(new javax.net.ssl.HostnameVerifier() {
                            public boolean verify(String hostname, javax.net.ssl.SSLSession session) { return true; }
                        });
                    }

                    conn.setConnectTimeout(15000);
                    conn.setReadTimeout(120000);
                    conn.setInstanceFollowRedirects(true);

                    String cookies = android.webkit.CookieManager.getInstance().getCookie(url);
                    if (cookies != null && !cookies.trim().isEmpty()) {
                        conn.setRequestProperty("Cookie", cookies);
                    }
                    if (userAgent != null && !userAgent.trim().isEmpty()) {
                        conn.setRequestProperty("User-Agent", userAgent);
                    }

                    int code = conn.getResponseCode();
                    if (code < 200 || code >= 300) {
                        throw new Exception("HTTP " + code);
                    }

                    String serverDisposition = conn.getHeaderField("Content-Disposition");
                    if (serverDisposition == null || serverDisposition.trim().isEmpty()) {
                        serverDisposition = contentDisposition;
                    }
                    String serverMime = conn.getHeaderField("Content-Type");
                    if (serverMime == null || serverMime.trim().isEmpty()) {
                        serverMime = mimeType;
                    }

                    final String filename = URLUtil.guessFileName(url, serverDisposition, serverMime);

                    File downloadDir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS);
                    if (!downloadDir.exists()) downloadDir.mkdirs();
                    File destFile = new File(downloadDir, filename);

                    try (InputStream in = conn.getInputStream();
                         FileOutputStream out = new FileOutputStream(destFile)) {
                        byte[] buffer = new byte[16384];
                        int bytesRead;
                        while ((bytesRead = in.read(buffer)) != -1) {
                            out.write(buffer, 0, bytesRead);
                        }
                    }

                    try {
                        android.media.MediaScannerConnection.scanFile(
                            getApplicationContext(),
                            new String[]{destFile.getAbsolutePath()},
                            null,
                            null
                        );
                    } catch (Exception ignored) {}

                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            Toast.makeText(getApplicationContext(), "Saved to Downloads: " + filename, Toast.LENGTH_LONG).show();
                            vibrate(20);
                        }
                    });

                } catch (final Exception e) {
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            Toast.makeText(getApplicationContext(), "Local download error: " + e.getMessage(), Toast.LENGTH_SHORT).show();
                        }
                    });
                } finally {
                    if (conn != null) conn.disconnect();
                }
            }
        }).start();
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

                WebView activeWeb = (mChildOverlayContainer != null && mChildOverlayContainer.getVisibility() == View.VISIBLE && mSecondaryWebView != null)
                    ? mSecondaryWebView
                    : ((mTabList != null && !mTabList.isEmpty() && mActiveTabIndex >= 0 && mActiveTabIndex < mTabList.size()) ? mTabList.get(mActiveTabIndex).webView : wrapperWebView);

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
                    String filename = URLUtil.guessFileName(url, contentDisposition, guessedMime);

                    File downloadDir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS);
                    if (!downloadDir.exists()) downloadDir.mkdirs();
                    File destFile = new File(downloadDir, filename);

                    try (FileOutputStream fos = new FileOutputStream(destFile)) {
                        fos.write(data);
                    }

                    Toast.makeText(getApplicationContext(), "Saved to Downloads: " + filename, Toast.LENGTH_LONG).show();
                    vibrate(20);
                }
                return;
            }

            if (isLocalLoopbackUrl(url)) {
                downloadLocalFileInApp(url, userAgent, contentDisposition, mimeType);
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

    private long lastBackPressTime = 0;
    private int backPressCount = 0;
    private static final long MULTI_BACK_INTERVAL_MS = 1500;
    private boolean navigatedToSettingsFromWrapper = false;

    private int connectionRetryCount = 0;
    private static final int MAX_CONNECTION_RETRIES = 20;

    private void showExitDialog() {
        final String[] options = new String[]{
            "Browser Settings",
            "Runtime Settings",
            "Minimize App"
        };

        new AlertDialog.Builder(this, android.R.style.Theme_DeviceDefault_Dialog_Alert)
            .setTitle("Conjure OS")
            .setItems(options, new DialogInterface.OnClickListener() {
                @Override
                public void onClick(DialogInterface dialog, int which) {
                    if (which == 0) {
                        openWrapperSettingsFromUi();
                    } else if (which == 1) {
                        openRuntimeSettingsFromWrapper();
                    } else if (which == 2) {
                        moveTaskToBack(true);
                    }
                }
            })
            .setNegativeButton("Cancel", null)
            .show();
    }

    public void openRuntimeSettingsFromWrapper() {
        runOnUiThread(new Runnable() {
            @Override
            public void run() {
                openInChildOverlay("file:///android_asset/index.html");
            }
        });
    }

    @Override
    protected void onPause() {
        super.onPause();
        unregisterShakeSensor();
        saveTabSessionState();
    }

    @Override
    public void onBackPressed() {
        if (isWrapperMode) {
            // 1. Check if Child Overlay is active
            if (mChildOverlayContainer != null && mChildOverlayContainer.getVisibility() == View.VISIBLE) {
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

            // 3. Main primary webview navigation & gesture interceptor
            if (getInterceptBackButton()) {
                long now = System.currentTimeMillis();
                if (now - lastBackPressTime < MULTI_BACK_INTERVAL_MS) {
                    backPressCount++;
                } else {
                    backPressCount = 1;
                }
                lastBackPressTime = now;

                if (backPressCount >= 3) {
                    backPressCount = 0;
                    lastBackPressTime = 0;
                    vibrate(40);
                    showExitDialog();
                } else {
                    WebView activeWeb = (mTabList != null && !mTabList.isEmpty() && mActiveTabIndex >= 0 && mActiveTabIndex < mTabList.size())
                        ? mTabList.get(mActiveTabIndex).webView
                        : wrapperWebView;
                    if (activeWeb != null) {
                        activeWeb.evaluateJavascript("window.location.hash = '#back';", null);
                    }
                }
            } else {
                WebView activeWeb = (mTabList != null && !mTabList.isEmpty() && mActiveTabIndex >= 0 && mActiveTabIndex < mTabList.size())
                    ? mTabList.get(mActiveTabIndex).webView
                    : wrapperWebView;
                if (activeWeb != null && activeWeb.canGoBack()) {
                    activeWeb.goBack();
                } else {
                    showExitDialog();
                }
            }
        } else {
            if (dashboardWebView != null && dashboardWebView.canGoBack()) {
                dashboardWebView.goBack();
            } else if (navigatedToSettingsFromWrapper) {
                navigatedToSettingsFromWrapper = false;
                loadConjureOsWrapperView(false);
            } else {
                super.onBackPressed();
            }
        }
    }
}