package com.conjure.keymapper;

import android.content.Context;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.graphics.PixelFormat;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.view.Gravity;
import android.view.MotionEvent;
import android.view.View;
import android.view.WindowManager;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.TextView;

public class CaretOverlayManager {
    private static CaretOverlayManager instance;
    private WindowManager windowManager;
    private FrameLayout overlayView;
    private View reticleView;
    private boolean isOverlayShowing = false;
    private boolean isCloseBtnTapped = false;

    // Status Pill Overlay Fields
    private static FrameLayout statusPillView = null;
    private static TextView statusPillTextView = null;
    private static WindowManager statusWindowManager = null;
    private static boolean isStatusPillShowing = false;
    private static final Handler statusHandler = new Handler(Looper.getMainLooper());
    private static Runnable dismissStatusRunnable = null;

    public static synchronized CaretOverlayManager getInstance() {
        if (instance == null) {
            instance = new CaretOverlayManager();
        }
        return instance;
    }

    @SuppressWarnings("deprecation")
    public static void showStatusPill(final Context context, final String text) {
        statusHandler.post(new Runnable() {
            @Override
            public void run() {
                try {
                    if (context == null) return;
                    final Context appContext = context.getApplicationContext() != null ? context.getApplicationContext() : context;
                    if (isStatusPillShowing && statusPillTextView != null) {
                        statusPillTextView.setText(text);
                        return;
                    }

                    statusWindowManager = (WindowManager) appContext.getSystemService(Context.WINDOW_SERVICE);
                    if (statusWindowManager == null) return;

                    statusPillView = new FrameLayout(context);

                    LinearLayout pill = new LinearLayout(context);
                    pill.setOrientation(LinearLayout.HORIZONTAL);
                    pill.setGravity(Gravity.CENTER_VERTICAL);
                    pill.setPadding(dpToPxStatic(context, 16), dpToPxStatic(context, 8), dpToPxStatic(context, 16), dpToPxStatic(context, 8));

                    GradientDrawable pillBg = new GradientDrawable();
                    pillBg.setColor(Color.parseColor("#F212121A"));
                    pillBg.setCornerRadius(dpToPxStatic(context, 20));
                    pillBg.setStroke(dpToPxStatic(context, 1), Color.parseColor("#26FFFFFF"));
                    pill.setBackground(pillBg);

                    statusPillTextView = new TextView(context);
                    statusPillTextView.setText(text != null ? text : "Processing...");
                    statusPillTextView.setTextColor(Color.parseColor("#FFF5F5F7"));
                    statusPillTextView.setTextSize(11);
                    statusPillTextView.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
                    statusPillTextView.setGravity(Gravity.CENTER);
                    pill.addView(statusPillTextView);

                    statusPillView.addView(pill);

                    int layoutType;
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                        layoutType = WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY;
                    } else {
                        layoutType = WindowManager.LayoutParams.TYPE_PHONE;
                    }

                    WindowManager.LayoutParams params = new WindowManager.LayoutParams(
                        WindowManager.LayoutParams.WRAP_CONTENT,
                        WindowManager.LayoutParams.WRAP_CONTENT,
                        layoutType,
                        WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE | WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE | WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
                        PixelFormat.TRANSLUCENT
                    );

                    params.gravity = Gravity.TOP | Gravity.CENTER_HORIZONTAL;
                    params.y = dpToPxStatic(context, 32);

                    statusPillView.setAlpha(0f);
                    statusPillView.setTranslationY((float) -dpToPxStatic(context, 32));

                    statusWindowManager.addView(statusPillView, params);
                    isStatusPillShowing = true;

                    statusPillView.animate()
                        .alpha(1f)
                        .translationY(0f)
                        .setDuration(250)
                        .start();

                } catch (Exception e) {
                    e.printStackTrace();
                }
            }
        });
    }

    public static void updateStatusPill(final String text) {
        statusHandler.post(new Runnable() {
            @Override
            public void run() {
                if (isStatusPillShowing && statusPillTextView != null) {
                    statusPillTextView.setText(text);
                }
            }
        });
    }

    public static void dismissStatusPillDelayed(final long delayMs) {
        if (dismissStatusRunnable != null) {
            statusHandler.removeCallbacks(dismissStatusRunnable);
        }
        dismissStatusRunnable = new Runnable() {
            @Override
            public void run() {
                dismissStatusPill();
            }
        };
        statusHandler.postDelayed(dismissStatusRunnable, delayMs);
    }

    public static void dismissStatusPill() {
        statusHandler.post(new Runnable() {
            @Override
            public void run() {
                if (isStatusPillShowing && statusPillView != null && statusWindowManager != null) {
                    final FrameLayout targetView = statusPillView;
                    final WindowManager targetWm = statusWindowManager;
                    statusPillView = null;
                    statusPillTextView = null;
                    isStatusPillShowing = false;

                    try {
                        targetView.animate()
                            .alpha(0f)
                            .translationY((float) -dpToPxStatic(targetView.getContext(), 32))
                            .setDuration(250)
                            .withEndAction(new Runnable() {
                                @Override
                                public void run() {
                                    try {
                                        targetWm.removeView(targetView);
                                    } catch (Exception ignored) {}
                                }
                            })
                            .start();
                    } catch (Exception e) {
                        try {
                            targetWm.removeView(targetView);
                        } catch (Exception ignored) {}
                    }
                }
            }
        });
    }

    public static void showCaretOverlay(final Context context, final String textToPaste) {
        new Handler(Looper.getMainLooper()).post(new Runnable() {
            @Override
            public void run() {
                getInstance().showOverlayInternal(context, textToPaste);
            }
        });
    }

    @SuppressWarnings("deprecation")
    private void showOverlayInternal(final Context context, final String textToPaste) {
        if (context == null || isOverlayShowing) return;

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && !android.provider.Settings.canDrawOverlays(context)) {
            KeyAccessibilityService.performPasteAction();
            return;
        }

        try {
            windowManager = (WindowManager) context.getSystemService(Context.WINDOW_SERVICE);
            if (windowManager == null) return;

            final int displayWidth = context.getResources().getDisplayMetrics().widthPixels;
            final int displayHeight = context.getResources().getDisplayMetrics().heightPixels;

            isCloseBtnTapped = false;
            overlayView = new FrameLayout(context);

            int cardWidthPx = dpToPx(context, 148);
            int cardHeightPx = dpToPx(context, 86);

            SharedPreferences prefs = context.getSharedPreferences("CaretOverlayPrefs", Context.MODE_PRIVATE);
            int savedX = prefs.getInt("last_caret_x", -1);
            int savedY = prefs.getInt("last_caret_y", -1);

            int layoutType;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                layoutType = WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY;
            } else {
                layoutType = WindowManager.LayoutParams.TYPE_PHONE;
            }

            final WindowManager.LayoutParams params = new WindowManager.LayoutParams(
                WindowManager.LayoutParams.WRAP_CONTENT,
                WindowManager.LayoutParams.WRAP_CONTENT,
                layoutType,
                WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE | WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
                PixelFormat.TRANSLUCENT
            );

            params.gravity = Gravity.TOP | Gravity.START;
            if (savedX >= 0 && savedY >= 0) {
                int maxX = Math.max(0, displayWidth - cardWidthPx);
                int maxY = Math.max(0, displayHeight - cardHeightPx);
                params.x = Math.max(0, Math.min(savedX, maxX));
                params.y = Math.max(0, Math.min(savedY, maxY));
            } else {
                params.x = (displayWidth / 2) - (cardWidthPx / 2);
                params.y = (displayHeight / 2) - (cardHeightPx / 2);
            }

            LinearLayout card = new LinearLayout(context);
            card.setOrientation(LinearLayout.VERTICAL);
            card.setGravity(Gravity.CENTER);
            card.setPadding(dpToPx(context, 10), dpToPx(context, 8), dpToPx(context, 10), dpToPx(context, 8));

            GradientDrawable cardBg = new GradientDrawable();
            cardBg.setColor(Color.parseColor("#F212121A"));
            cardBg.setCornerRadius(dpToPx(context, 18));
            cardBg.setStroke(dpToPx(context, 1), Color.parseColor("#557C6CFF"));
            card.setBackground(cardBg);

            // Header Bar: Title + Dismiss [✕] Button
            LinearLayout header = new LinearLayout(context);
            header.setOrientation(LinearLayout.HORIZONTAL);
            header.setGravity(Gravity.CENTER_VERTICAL);

            TextView titleText = new TextView(context);
            titleText.setText("🎯 Drop to Paste");
            titleText.setTextColor(Color.parseColor("#FFFFFFFF"));
            titleText.setTextSize(11);
            titleText.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
            LinearLayout.LayoutParams titleParams = new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1.0f);
            titleText.setLayoutParams(titleParams);

            TextView btnClose = new TextView(context);
            btnClose.setText(" ✕ ");
            btnClose.setTextColor(Color.parseColor("#FFA1A1AA"));
            btnClose.setTextSize(13);
            btnClose.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
            btnClose.setPadding(dpToPx(context, 6), dpToPx(context, 2), dpToPx(context, 4), dpToPx(context, 2));

            btnClose.setOnClickListener(new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    isCloseBtnTapped = true;
                    ActionExecutor.vibrateDevice(context, 30);
                    ActionExecutor.playSoundChime("pip");
                    saveCurrentPosition(context, params.x, params.y);
                    dismissOverlay();
                }
            });

            header.addView(titleText);
            header.addView(btnClose);
            card.addView(header, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT));

            // Glowing Target Reticle Icon
            FrameLayout reticleContainer = new FrameLayout(context);
            int reticleSize = dpToPx(context, 38);
            LinearLayout.LayoutParams reticleParams = new LinearLayout.LayoutParams(reticleSize, reticleSize);
            reticleParams.topMargin = dpToPx(context, 6);
            reticleParams.gravity = Gravity.CENTER_HORIZONTAL;

            GradientDrawable reticleBg = new GradientDrawable();
            reticleBg.setShape(GradientDrawable.OVAL);
            reticleBg.setColor(Color.parseColor("#337C6CFF"));
            reticleBg.setStroke(dpToPx(context, 2), Color.parseColor("#FF7C6CFF"));
            reticleContainer.setBackground(reticleBg);

            View dot = new View(context);
            int dotSize = dpToPx(context, 10);
            FrameLayout.LayoutParams dotParams = new FrameLayout.LayoutParams(dotSize, dotSize);
            dotParams.gravity = Gravity.CENTER;
            GradientDrawable dotBg = new GradientDrawable();
            dotBg.setShape(GradientDrawable.OVAL);
            dotBg.setColor(Color.parseColor("#FF34D399"));
            dot.setBackground(dotBg);
            reticleContainer.addView(dot, dotParams);

            this.reticleView = reticleContainer;
            card.addView(reticleContainer, reticleParams);

            FrameLayout.LayoutParams cardLayoutParams = new FrameLayout.LayoutParams(cardWidthPx, cardHeightPx);
            overlayView.addView(card, cardLayoutParams);

            overlayView.setOnTouchListener(new View.OnTouchListener() {
                private int initialX, initialY;
                private float initialTouchX, initialTouchY;

                @Override
                public boolean onTouch(View v, MotionEvent event) {
                    if (isCloseBtnTapped) return false;

                    switch (event.getAction()) {
                        case MotionEvent.ACTION_DOWN:
                            initialX = params.x;
                            initialY = params.y;
                            initialTouchX = event.getRawX();
                            initialTouchY = event.getRawY();
                            ActionExecutor.vibrateDevice(context, 20);
                            return true;

                        case MotionEvent.ACTION_MOVE:
                            params.x = initialX + (int) (event.getRawX() - initialTouchX);
                            params.y = initialY + (int) (event.getRawY() - initialTouchY);
                            windowManager.updateViewLayout(overlayView, params);
                            return true;

                        case MotionEvent.ACTION_UP:
                            saveCurrentPosition(context, params.x, params.y);
                            if (isCloseBtnTapped) return true;

                            int[] reticleLoc = new int[2];
                            if (reticleView != null) {
                                reticleView.getLocationOnScreen(reticleLoc);
                            }
                            final float dropX = (reticleView != null && reticleLoc[0] > 0) 
                                ? (reticleLoc[0] + reticleView.getWidth() / 2f) 
                                : event.getRawX();
                            final float dropY = (reticleView != null && reticleLoc[1] > 0) 
                                ? (reticleLoc[1] + reticleView.getHeight() / 2f) 
                                : event.getRawY();

                            dismissOverlay();
                            showStatusPill(context, "🎯 Focusing target input field...");

                            new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
                                @Override
                                public void run() {
                                    KeyAccessibilityService.dispatchTapAndPaste(dropX, dropY, textToPaste);
                                }
                            }, 100);
                            return true;
                    }
                    return false;
                }
            });

            windowManager.addView(overlayView, params);
            isOverlayShowing = true;
            ActionExecutor.vibrateDevice(context, 60);
            ActionExecutor.playSoundChime("air_pop");

        } catch (Exception e) {
            e.printStackTrace();
            isOverlayShowing = false;
        }
    }

    private void saveCurrentPosition(Context context, int x, int y) {
        if (context == null) return;
        try {
            context.getSharedPreferences("CaretOverlayPrefs", Context.MODE_PRIVATE)
                .edit()
                .putInt("last_caret_x", x)
                .putInt("last_caret_y", y)
                .apply();
        } catch (Exception ignored) {}
    }

    public synchronized void dismissOverlay() {
        if (isOverlayShowing && overlayView != null && windowManager != null) {
            try {
                windowManager.removeView(overlayView);
            } catch (Exception ignored) {}
            overlayView = null;
            reticleView = null;
            isOverlayShowing = false;
        }
    }

    private int dpToPx(Context context, int dp) {
        return Math.round(dp * context.getResources().getDisplayMetrics().density);
    }

    private static int dpToPxStatic(Context context, int dp) {
        return Math.round(dp * context.getResources().getDisplayMetrics().density);
    }
}