package com.conjure.floatassist;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.graphics.Color;
import android.graphics.PixelFormat;
import android.net.http.SslError;
import android.os.Build;
import android.os.IBinder;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.MotionEvent;
import android.view.View;
import android.view.WindowManager;
import android.webkit.SslErrorHandler;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import java.util.Locale;

@SuppressWarnings("deprecation")
public class FloatService extends Service {
    private static final String CHANNEL_ID = "FloatAssistServiceChannel";
    public static boolean isRunning = false;

    private WindowManager windowManager;
    private FrameLayout ballContainer;
    private WebView ballWebView;
    private WindowManager.LayoutParams ballParams;
    
    private FrameLayout consoleContainer;
    private WebView consoleWebView;
    private WindowManager.LayoutParams consoleParams;
    
    private TorchUtility torchUtility;
    
    private int currentSize = 56;
    private long lastTime = 0;
    private float lastTouchX = 0;
    private float lastTouchY = 0;
    private long lastJsUpdateTime = 0;
    private long lastTelemetryTime = 0;

    private BroadcastReceiver actionReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            if (intent != null) {
                String action = intent.getAction();
                if ("com.conjure.floatassist.ACTION_STOP".equals(action)) {
                    stopSelf();
                    sendBroadcast(new Intent("com.conjure.floatassist.UPDATE_SETTINGS"));
                } else if ("com.conjure.floatassist.ACTION_FLASHLIGHT".equals(action)) {
                    if (torchUtility != null) {
                        torchUtility.toggleTorch();
                        NotificationManager manager = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
                        if (manager != null) manager.notify(2, buildNotification());
                        
                        // Push bi-directional sync to HTML pod if it is currently open
                        if (consoleWebView != null) {
                            consoleWebView.evaluateJavascript("javascript:if(typeof window.syncFlashlight === 'function') window.syncFlashlight();", null);
                        }
                    }
                }
            }
        }
    };

    private BroadcastReceiver settingsReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            if (intent != null && "com.conjure.floatassist.UPDATE_SETTINGS".equals(intent.getAction())) {
                reloadSettings();
            }
        }
    };

    @Override
    public void onCreate() {
        super.onCreate();
        isRunning = true;
        Intent stateIntent = new Intent("com.conjure.floatassist.SERVICE_STATE_CHANGED");
        stateIntent.setPackage(getPackageName());
        sendBroadcast(stateIntent);
        currentSize = FloatSettingsStore.getInt(this, "ball_size", 56);

        torchUtility = new TorchUtility(this);
        windowManager = (WindowManager) getSystemService(WINDOW_SERVICE);

        createNotificationChannel();
        
        IntentFilter actionFilter = new IntentFilter();
        actionFilter.addAction("com.conjure.floatassist.ACTION_STOP");
        actionFilter.addAction("com.conjure.floatassist.ACTION_FLASHLIGHT");
        if (Build.VERSION.SDK_INT >= 33) {
            registerReceiver(actionReceiver, actionFilter, 4); // Context.RECEIVER_NOT_EXPORTED
        } else {
            registerReceiver(actionReceiver, actionFilter);
        }

        Notification notification = buildNotification();
        
        if (Build.VERSION.SDK_INT >= 29) {
            try {
                java.lang.reflect.Method startForegroundMethod = Service.class.getMethod(
                    "startForeground", int.class, Notification.class, int.class);
                startForegroundMethod.invoke(this, 2, notification, 1); 
            } catch (Exception e) {
                startForeground(2, notification);
            }
        } else {
            startForeground(2, notification);
        }

        setupFloatingBall();

        IntentFilter filter = new IntentFilter("com.conjure.floatassist.UPDATE_SETTINGS");
        if (Build.VERSION.SDK_INT >= 33) {
            registerReceiver(settingsReceiver, filter, 4); // 4 = Context.RECEIVER_NOT_EXPORTED
        } else {
            registerReceiver(settingsReceiver, filter);
        }
    }

    private Notification buildNotification() {
        Intent stopIntent = new Intent("com.conjure.floatassist.ACTION_STOP");
        stopIntent.setPackage(getPackageName());
        android.app.PendingIntent pStop = android.app.PendingIntent.getBroadcast(
            this, 0, stopIntent, android.app.PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= 23 ? android.app.PendingIntent.FLAG_IMMUTABLE : 0));

        Intent flashIntent = new Intent("com.conjure.floatassist.ACTION_FLASHLIGHT");
        flashIntent.setPackage(getPackageName());
        android.app.PendingIntent pFlash = android.app.PendingIntent.getBroadcast(
            this, 1, flashIntent, android.app.PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= 23 ? android.app.PendingIntent.FLAG_IMMUTABLE : 0));

        String flashText = (torchUtility != null && torchUtility.isOn()) ? "Turn Off Flashlight" : "Turn On Flashlight";

        Notification.Builder builder;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            builder = new Notification.Builder(this, CHANNEL_ID);
        } else {
            builder = new Notification.Builder(this);
        }

        return builder.setContentTitle("FloatAssist Active")
               .setContentText("Global floating assistant is running")
               .setSmallIcon(android.R.drawable.ic_menu_compass)
               .addAction(android.R.drawable.ic_menu_camera, flashText, pFlash)
               .addAction(android.R.drawable.ic_menu_close_clear_cancel, "Stop Service", pStop)
               .build();
    }

    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel serviceChannel = new NotificationChannel(
                    CHANNEL_ID, "FloatAssist Service Channel", NotificationManager.IMPORTANCE_DEFAULT);
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager != null) manager.createNotificationChannel(serviceChannel);
        }
    }

    private void reloadSettings() {
        currentSize = FloatSettingsStore.getInt(this, "ball_size", 56);
        if (ballContainer != null) {
            // Update physical window constraints (+48dp padding space for CSS shadow overflow)
            ballParams.width = dpToPx(currentSize + 48);
            ballParams.height = dpToPx(currentSize + 48);
            windowManager.updateViewLayout(ballContainer, ballParams);
            
            // Pipe color/glow changes into the HTML DOM via JS
            if (ballWebView != null) {
                ballWebView.evaluateJavascript("syncSettings();", null);
            }
        }
    }

    private void setupFloatingBall() {
        // We use a custom FrameLayout to intercept all touches. This prevents the WebView from 
        // stealing the drag events, keeping the high-performance physics tracking strictly in Native Java.
        ballContainer = new FrameLayout(this) {
            @Override
            public boolean onInterceptTouchEvent(MotionEvent ev) {
                return true; 
            }
        };

        ballWebView = new WebView(this);
        ballWebView.setBackgroundColor(Color.TRANSPARENT);
        ballWebView.setVerticalScrollBarEnabled(false);
        ballWebView.setHorizontalScrollBarEnabled(false);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.KITKAT) {
            ballWebView.setLayerType(View.LAYER_TYPE_HARDWARE, null);
        }
        
        WebSettings ws = ballWebView.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        
        ballWebView.addJavascriptInterface(new ServiceBridge(this), "Service");
        ballWebView.loadUrl("file:///android_asset/floating_ball.html");
        
        ballContainer.addView(ballWebView, new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT));

        ballParams = new WindowManager.LayoutParams(
                dpToPx(currentSize + 48),
                dpToPx(currentSize + 48),
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.O ? 
                        WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY : 
                        WindowManager.LayoutParams.TYPE_PHONE,
                WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE,
                PixelFormat.TRANSLUCENT
        );
        ballParams.gravity = Gravity.TOP | Gravity.START;
        ballParams.x = 20;
        ballParams.y = 250;

        ballContainer.setOnTouchListener(new View.OnTouchListener() {
            private int initialX;
            private int initialY;
            private float initialTouchX;
            private float initialTouchY;
            private long touchStartTime;

            @Override
            public boolean onTouch(View v, MotionEvent event) {
                switch (event.getAction()) {
                    case MotionEvent.ACTION_DOWN:
                        initialX = ballParams.x;
                        initialY = ballParams.y;
                        initialTouchX = event.getRawX();
                        initialTouchY = event.getRawY();
                        touchStartTime = System.currentTimeMillis();
                        lastTouchX = event.getRawX();
                        lastTouchY = event.getRawY();
                        lastTime = System.currentTimeMillis();
                        
                        // Push tactile squeeze command to HTML
                        ballWebView.evaluateJavascript("squeeze();", null);
                        return true;

                    case MotionEvent.ACTION_MOVE:
                        ballParams.x = initialX + (int) (event.getRawX() - initialTouchX);
                        ballParams.y = initialY + (int) (event.getRawY() - initialTouchY);
                        windowManager.updateViewLayout(ballContainer, ballParams);
                        
                        long now = System.currentTimeMillis();
                        long dt = Math.max(1, now - lastTime);
                        float moveDistX = event.getRawX() - lastTouchX;
                        float moveDistY = event.getRawY() - lastTouchY;
                        double speed = Math.sqrt(moveDistX * moveDistX + moveDistY * moveDistY) / dt;
                        
                        double angle = Math.atan2(moveDistY, moveDistX) * (180 / Math.PI);
                        float stretchX = 1.0f + (float) Math.min(speed * 0.16, 0.22);
                        float stretchY = 1.0f - (float) Math.min(speed * 0.12, 0.18);
                        
                        // Throttle JavaScript JNI calls to ~60fps to prevent UI thread lag
                        if (now - lastJsUpdateTime > 16) {
                            if (speed > 0.05) {
                                String js = String.format(Locale.US, "setDeformation(%f, %f, %f);", stretchX, stretchY, angle);
                                ballWebView.evaluateJavascript(js, null);
                            } else {
                                ballWebView.evaluateJavascript("resetDeformation();", null);
                            }
                            lastJsUpdateTime = now;
                        }
                        
                        // Throttle IPC Broadcasts to ~10fps to prevent CPU saturation
                        if (now - lastTelemetryTime > 100) {
                            sendTelemetryBroadcast(speed * 1000.0, stretchX - 1.0f, ballParams.x, ballParams.y, "Dragging");
                            lastTelemetryTime = now;
                        }
                        
                        lastTouchX = event.getRawX();
                        lastTouchY = event.getRawY();
                        lastTime = now;
                        return true;

                    case MotionEvent.ACTION_UP:
                        ballWebView.evaluateJavascript("resetDeformation();", null);
                        
                        long duration = System.currentTimeMillis() - touchStartTime;
                        float diffX = event.getRawX() - initialTouchX;
                        float diffY = event.getRawY() - initialTouchY;
                        if (duration < 200 && Math.abs(diffX) < 10 && Math.abs(diffY) < 10) {
                            expandMenu();
                        } else {
                            snapToClosestEdge();
                        }
                        return true;
                }
                return false;
            }
        });

        windowManager.addView(ballContainer, ballParams);
    }

    private void snapToClosestEdge() {
        int screenWidth = windowManager.getDefaultDisplay().getWidth();
        int orbSize = dpToPx(currentSize + 48);
        int leftMargin = dpToPx(-16); // Offset the padding so the visual orb touches the edge
        int rightMargin = screenWidth - orbSize + dpToPx(16);
        
        final int targetX = (ballParams.x + (orbSize / 2) < screenWidth / 2) ? leftMargin : rightMargin;
        final int startX = ballParams.x;
        
        sendTelemetryBroadcast(0, 0, ballParams.x, ballParams.y, "Snapping");
        
        if (Build.VERSION.SDK_INT >= 11) {
            android.animation.ValueAnimator animator = android.animation.ValueAnimator.ofInt(startX, targetX);
            animator.setDuration(300);
            animator.setInterpolator(new android.view.animation.OvershootInterpolator(1.2f));
            animator.addUpdateListener(new android.animation.ValueAnimator.AnimatorUpdateListener() {
                @Override
                public void onAnimationUpdate(android.animation.ValueAnimator animation) {
                    ballParams.x = (Integer) animation.getAnimatedValue();
                    if (ballContainer != null && ballContainer.getParent() != null) {
                        windowManager.updateViewLayout(ballContainer, ballParams);
                        sendTelemetryBroadcast(0, 0, ballParams.x, ballParams.y, "Snapping");
                    }
                }
            });
            animator.addListener(new android.animation.AnimatorListenerAdapter() {
                @Override
                public void onAnimationEnd(android.animation.Animator animation) {
                    sendTelemetryBroadcast(0, 0, ballParams.x, ballParams.y, "Clamped");
                }
            });
            animator.start();
        } else {
            ballParams.x = targetX;
            windowManager.updateViewLayout(ballContainer, ballParams);
            sendTelemetryBroadcast(0, 0, ballParams.x, ballParams.y, "Clamped");
        }
    }

    private void expandMenu() {
        ballContainer.setVisibility(View.GONE);
        sendTelemetryBroadcast(0, 0, ballParams.x, ballParams.y, "Active (Open)");

        if (consoleContainer != null) {
            consoleContainer.setAlpha(0f);
            consoleContainer.setVisibility(View.VISIBLE);
            if (consoleWebView != null) {
                consoleWebView.requestFocus();
                consoleWebView.evaluateJavascript("if(typeof window.onPodExpand === 'function') window.onPodExpand();", null);
            }
            animateExpand(250);
            return;
        }

        consoleContainer = new FrameLayout(this) {
            @Override
            public boolean dispatchKeyEvent(android.view.KeyEvent event) {
                if (event != null && event.getKeyCode() == android.view.KeyEvent.KEYCODE_BACK) {
                    if (event.getAction() == android.view.KeyEvent.ACTION_UP) {
                        boolean dismissWithBack = FloatSettingsStore.getInt(FloatService.this, "dismiss_pod_with_back", 1) == 1;
                        if (dismissWithBack) {
                            if (consoleWebView != null) {
                                consoleWebView.evaluateJavascript("if(typeof window.closeOverlay === 'function') { window.closeOverlay(); } else { Service.animateCollapse(250); }", null);
                            } else {
                                animateCollapseFromBridge(250);
                            }
                        } else {
                            if (consoleWebView != null) {
                                consoleWebView.evaluateJavascript("if(typeof window.showPodToast === 'function') { window.showPodToast('Close Pod with Dismiss button'); }", null);
                            }
                        }
                    }
                    return true;
                }
                return super.dispatchKeyEvent(event);
            }
        };
        consoleContainer.setAlpha(0f);
        
        consoleWebView = new WebView(this);
        consoleWebView.setBackgroundColor(Color.TRANSPARENT);
        consoleWebView.setVerticalScrollBarEnabled(false);
        consoleWebView.setHorizontalScrollBarEnabled(false);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.KITKAT) {
            consoleWebView.setLayerType(View.LAYER_TYPE_HARDWARE, null);
        }
        
        WebSettings ws = consoleWebView.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        ws.setAllowFileAccess(true);
        ws.setAllowContentAccess(true);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.JELLY_BEAN_MR1) {
            ws.setAllowUniversalAccessFromFileURLs(true);
            ws.setAllowFileAccessFromFileURLs(true);
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            ws.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        }

        consoleWebView.setWebViewClient(new WebViewClient() {
            @Override
            public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
                handler.proceed();
            }
        });
        
        consoleWebView.addJavascriptInterface(new ServiceBridge(this), "Service");
        consoleWebView.loadUrl("file:///android_asset/expanded_overlay.html");
        
        consoleContainer.addView(consoleWebView, new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT));

        consoleParams = new WindowManager.LayoutParams(
                WindowManager.LayoutParams.MATCH_PARENT,
                WindowManager.LayoutParams.MATCH_PARENT,
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.O ? 
                        WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY : 
                        WindowManager.LayoutParams.TYPE_PHONE,
                WindowManager.LayoutParams.FLAG_NOT_TOUCH_MODAL |
                WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN |
                WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS,
                PixelFormat.TRANSLUCENT
        );

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            consoleParams.layoutInDisplayCutoutMode = 
                    WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES;
        }

        if (Build.VERSION.SDK_INT >= 31) {
            consoleParams.flags |= WindowManager.LayoutParams.FLAG_BLUR_BEHIND;
            try {
                java.lang.reflect.Method setBlurMethod = WindowManager.LayoutParams.class.getMethod("setBlurBehindRadius", int.class);
                setBlurMethod.invoke(consoleParams, 80);
            } catch (Exception e) {
                e.printStackTrace();
            }
            consoleContainer.setBackgroundColor(Color.parseColor("#40000000"));
        } else {
            consoleContainer.setBackgroundColor(Color.parseColor("#99000000"));
        }

        windowManager.addView(consoleContainer, consoleParams);
        animateExpand(250);
    }

    private void animateExpand(final long durationMs) {
        if (consoleContainer == null) return;
        android.animation.ValueAnimator animator = android.animation.ValueAnimator.ofFloat(0f, 1f);
        animator.setDuration(durationMs);
        animator.setInterpolator(new android.view.animation.DecelerateInterpolator());
        animator.addUpdateListener(new android.animation.ValueAnimator.AnimatorUpdateListener() {
            @Override
            public void onAnimationUpdate(android.animation.ValueAnimator animation) {
                float val = (Float) animation.getAnimatedValue();
                if (consoleContainer != null) {
                    consoleContainer.setAlpha(val);
                    if (Build.VERSION.SDK_INT >= 31 && consoleParams != null) {
                        try {
                            int currentBlur = (int) (80 * val);
                            java.lang.reflect.Method setBlurMethod = WindowManager.LayoutParams.class.getMethod("setBlurBehindRadius", int.class);
                            setBlurMethod.invoke(consoleParams, currentBlur);
                            windowManager.updateViewLayout(consoleContainer, consoleParams);
                        } catch (Exception ignored) {}
                    }
                }
            }
        });
        animator.start();
    }

    public void animateCollapseFromBridge(final long durationMs) {
        new android.os.Handler(android.os.Looper.getMainLooper()).post(new Runnable() {
            @Override
            public void run() {
                if (consoleContainer == null || consoleContainer.getVisibility() != View.VISIBLE) {
                    return;
                }

                android.animation.ValueAnimator animator = android.animation.ValueAnimator.ofFloat(1f, 0f);
                animator.setDuration(durationMs);
                animator.setInterpolator(new android.view.animation.AccelerateInterpolator());
                animator.addUpdateListener(new android.animation.ValueAnimator.AnimatorUpdateListener() {
                    @Override
                    public void onAnimationUpdate(android.animation.ValueAnimator animation) {
                        float val = (Float) animation.getAnimatedValue();
                        if (consoleContainer != null) {
                            consoleContainer.setAlpha(val);
                            if (Build.VERSION.SDK_INT >= 31 && consoleParams != null) {
                                try {
                                    int currentBlur = (int) (80 * val);
                                    java.lang.reflect.Method setBlurMethod = WindowManager.LayoutParams.class.getMethod("setBlurBehindRadius", int.class);
                                    setBlurMethod.invoke(consoleParams, currentBlur);
                                    windowManager.updateViewLayout(consoleContainer, consoleParams);
                                } catch (Exception ignored) {}
                            }
                        }
                    }
                });
                animator.addListener(new android.animation.AnimatorListenerAdapter() {
                    @Override
                    public void onAnimationEnd(android.animation.Animator animation) {
                        collapseMenuFromBridge();
                    }
                });
                animator.start();
            }
        });
    }

    public void collapseMenuFromBridge() {
        // Must run on UI thread since called from JS
        new android.os.Handler(android.os.Looper.getMainLooper()).post(new Runnable() {
            @Override
            public void run() {
                if (consoleContainer != null) {
                    consoleContainer.setVisibility(View.GONE);
                }
                if (ballContainer != null) {
                    ballContainer.setVisibility(View.VISIBLE);
                }
                sendTelemetryBroadcast(0, 0, ballParams.x, ballParams.y, "Clamped");
            }
        });
    }

    public void toggleFlashlightFromBridge() {
        torchUtility.toggleTorch();
        if (consoleWebView != null) {
            consoleWebView.post(new Runnable() {
                @Override
                public void run() {
                    consoleWebView.evaluateJavascript("if(typeof window.syncFlashlight === 'function') window.syncFlashlight();", null);
                }
            });
        }
    }

    public boolean isFlashlightOn() {
        return torchUtility.isOn();
    }

    public String runTermuxCommand(final String command) {
        try {
            Intent intent = new Intent();
            intent.setClassName("com.termux", "com.termux.app.RunCommandService");
            intent.setAction("com.termux.RUN_COMMAND");
            intent.putExtra("com.termux.RUN_COMMAND_PATH", "/data/data/com.termux/files/usr/bin/bash");
            intent.putExtra("com.termux.RUN_COMMAND_ARGUMENTS", new String[]{"-c", command});
            intent.putExtra("com.termux.RUN_COMMAND_WORKDIR", "/data/data/com.termux/files/home");
            intent.putExtra("com.termux.RUN_COMMAND_BACKGROUND", false);
            intent.putExtra("com.termux.RUN_COMMAND_SESSION_ACTION", "0");
            
            startService(intent);
            return "SUCCESS: Intent dispatched to Termux RunCommandService.";
        } catch (SecurityException se) {
            return "ERROR: SecurityException - Permission denied. Grant 'Run commands in Termux environment' to FloatAssist in Android Settings. (" + se.getMessage() + ")";
        } catch (Exception e) {
            return "ERROR: Failed to dispatch intent to Termux: " + e.getMessage();
        }
    }

    public String executeSmartCommand(final String rawCommand) {
        if (rawCommand == null || rawCommand.trim().isEmpty()) {
            return "ERROR: Command string is empty.";
        }
        String cmd = rawCommand.trim();

        // 1. URI / Deep Link Pattern (e.g. keymapper://run/alias or https://...)
        if (cmd.matches("(?i)^[a-z0-9+.-]+://.*")) {
            try {
                Intent intent = new Intent(Intent.ACTION_VIEW, android.net.Uri.parse(cmd));
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                startActivity(intent);
                return "SUCCESS: Dispatched native URI deep link (" + cmd + ")";
            } catch (Exception e) {
                return "ERROR: Failed to launch URI: " + e.getMessage();
            }
        }

        // 2. KeyMapper CLI Broadcast Pattern (e.g. am broadcast -a com.conjure.keymapper.TRIGGER ...)
        if (cmd.startsWith("am broadcast") || cmd.contains("com.conjure.keymapper.TRIGGER")) {
            try {
                String action = "com.conjure.keymapper.TRIGGER";
                String targetPackage = "com.conjure.keymapper";
                String extraKey = "rule";
                String extraValue = "";

                // Parse action -a <action>
                java.util.regex.Matcher actionMatcher = java.util.regex.Pattern.compile("-a\\s+([\\w\\.]+)", java.util.regex.Pattern.CASE_INSENSITIVE).matcher(cmd);
                if (actionMatcher.find()) {
                    action = actionMatcher.group(1);
                }

                // Parse package -p <package>
                java.util.regex.Matcher pkgMatcher = java.util.regex.Pattern.compile("-p\\s+([\\w\\.]+)", java.util.regex.Pattern.CASE_INSENSITIVE).matcher(cmd);
                if (pkgMatcher.find()) {
                    targetPackage = pkgMatcher.group(1);
                }

                // Parse string extra --es <key> "<val>" or --es <key> <val>
                java.util.regex.Matcher extraMatcher = java.util.regex.Pattern.compile("--es\\s+([\\w]+)\\s+[\"']?([^\"'\\s]+)[\"']?", java.util.regex.Pattern.CASE_INSENSITIVE).matcher(cmd);
                if (extraMatcher.find()) {
                    extraKey = extraMatcher.group(1);
                    extraValue = extraMatcher.group(2);
                }

                Intent broadcastIntent = new Intent(action);
                if (targetPackage != null && !targetPackage.isEmpty()) {
                    broadcastIntent.setPackage(targetPackage);
                }
                if (extraKey != null && !extraKey.isEmpty() && extraValue != null && !extraValue.isEmpty()) {
                    broadcastIntent.putExtra(extraKey, extraValue);
                }

                sendBroadcast(broadcastIntent);
                return "SUCCESS: Dispatched native broadcast (" + action + " -> " + extraKey + "=" + extraValue + ")";
            } catch (Exception e) {
                return "ERROR: Failed to dispatch native broadcast: " + e.getMessage();
            }
        }

        // 3. Fallback: Termux command execution
        return runTermuxCommand(cmd);
    }

    private void sendTelemetryBroadcast(double speed, float deformation, int x, int y, String status) {
        Intent intent = new Intent("com.conjure.floatassist.TELEMETRY_UPDATE");
        intent.setPackage(getPackageName());
        intent.putExtra("velocity", String.format(Locale.US, "%.2f px/s", speed));
        intent.putExtra("deformation", String.format(Locale.US, "%.1f%%", deformation * 100f));
        intent.putExtra("coords", "X: " + x + "px / Y: " + y + "px");
        intent.putExtra("constraint", status);
        sendBroadcast(intent);
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        isRunning = false;
        Intent stateIntent = new Intent("com.conjure.floatassist.SERVICE_STATE_CHANGED");
        stateIntent.setPackage(getPackageName());
        sendBroadcast(stateIntent);
        try { unregisterReceiver(settingsReceiver); } catch (Exception e) {}
        try { unregisterReceiver(actionReceiver); } catch (Exception e) {}
        
        if (consoleContainer != null) {
            windowManager.removeView(consoleContainer);
            consoleContainer = null;
            consoleWebView = null;
        }
        if (ballContainer != null) {
            windowManager.removeView(ballContainer);
            ballContainer = null;
            ballWebView = null;
        }
        if (torchUtility != null) torchUtility.turnOff();
        
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    private int dpToPx(int dp) {
        return (int) TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, dp, getResources().getDisplayMetrics());
    }
}