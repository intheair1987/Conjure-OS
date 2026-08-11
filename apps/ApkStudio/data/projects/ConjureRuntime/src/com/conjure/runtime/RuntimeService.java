package com.conjure.runtime;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.IBinder;

import java.io.File;

public final class RuntimeService extends Service {
    private static final String CHANNEL_ID = "ConjureRuntimeService";
    private static final int NOTIFICATION_ID = 7201;
    private static RuntimeService instance;

    private ProcessManager processManager;
    private boolean bundleUnavailable;

    private final android.os.Handler watchdogHandler = new android.os.Handler(android.os.Looper.getMainLooper());
    private static final long WATCHDOG_INTERVAL_MS = 10000;

    private final Runnable watchdogRunnable = new Runnable() {
        @Override
        public void run() {
            if (processManager != null && !bundleUnavailable) {
                if (!processManager.isRunning()) {
                    String restartResult = processManager.start();
                    broadcastStatus("Watchdog restarted runtime: " + restartResult);
                }
            }
            watchdogHandler.postDelayed(this, WATCHDOG_INTERVAL_MS);
        }
    };

    public static RuntimeService getInstance() {
        return instance;
    }

    public ProcessManager getProcessManager() {
        return processManager;
    }

    private void persistStatus(String status, String message) {
        getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE)
            .edit()
            .putString("runtime_status", status)
            .putString("runtime_message", message)
            .apply();
    }

    @Override
    public void onCreate() {
        super.onCreate();
        instance = this;

        File runtimeRoot = new File(getFilesDir(), "runtime");
        runtimeRoot.mkdirs();
        String nativeLibDir = getApplicationInfo().nativeLibraryDir;

        processManager = new ProcessManager(this, runtimeRoot, nativeLibDir);

        createNotificationChannel();
        startForeground(NOTIFICATION_ID, buildNotification("Preparing runtime"));

        String result = processManager.start();
        String status = processManager.getStatus();
        bundleUnavailable = "BUNDLES_REQUIRED".equals(status);
        persistStatus(status, result);
        updateNotification(result);
        broadcastStatus(result);

        if (bundleUnavailable) {
            stopSelf();
        } else {
            watchdogHandler.postDelayed(watchdogRunnable, WATCHDOG_INTERVAL_MS);

            android.content.SharedPreferences prefs = getSharedPreferences("ConjureRuntimeState", MODE_PRIVATE);
            boolean autoStartTailscale = prefs.getBoolean("auto_start_tailscale", false);

            if (autoStartTailscale && "RUNNING".equals(status)) {
                autoStartTailscaleInternal(prefs);
            }
        }
    }

    private void autoStartTailscaleInternal(android.content.SharedPreferences prefs) {
        int httpsPort = prefs.getInt("https_port", 8000);
        int httpPort = prefs.getInt("http_port", 8001);
        String apiKey = prefs.getString("tailscale_api_key", "");
        String tags = prefs.getString("tailscale_tags", "");

        if (processManager != null && processManager.getTailscaleManager() != null) {
            processManager.getTailscaleManager().start(httpsPort, httpPort, apiKey, tags);
        }
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        watchdogHandler.removeCallbacks(watchdogRunnable);
        if (processManager != null) {
            processManager.stop();
        }

        if (!bundleUnavailable) {
            persistStatus("STOPPED", "Runtime services are not running.");
        }

        broadcastStatus(bundleUnavailable
            ? "Runtime binaries are not installed"
            : "Runtime stopped");
        instance = null;
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    public String getStatus() {
        return processManager == null ? "STOPPED" : processManager.getStatus();
    }

    @SuppressWarnings("deprecation")
    private Notification buildNotification(String message) {
        Notification.Builder builder;

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            builder = new Notification.Builder(this, CHANNEL_ID);
        } else {
            builder = new Notification.Builder(this);
        }

        return builder
            .setContentTitle("Conjure Runtime")
            .setContentText(message)
            .setSmallIcon(android.R.drawable.stat_notify_sync)
            .setOngoing(true)
            .build();
    }

    private void updateNotification(String message) {
        NotificationManager manager = (NotificationManager)getSystemService(NOTIFICATION_SERVICE);
        if (manager != null) {
            manager.notify(NOTIFICATION_ID, buildNotification(message));
        }
    }

    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return;
        }

        NotificationChannel channel = new NotificationChannel(
            CHANNEL_ID,
            "Conjure Runtime",
            NotificationManager.IMPORTANCE_LOW
        );

        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) {
            manager.createNotificationChannel(channel);
        }
    }

    private void broadcastStatus(String message) {
        Intent statusIntent = new Intent("com.conjure.runtime.RUNTIME_STATUS");
        statusIntent.setPackage(getPackageName());
        statusIntent.putExtra("status", getStatus());
        statusIntent.putExtra("message", message);
        sendBroadcast(statusIntent);
    }
}