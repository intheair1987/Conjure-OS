package com.conjure.buildkernel;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.IBinder;
import java.io.*;
import java.net.Socket;
import java.util.Map;

@SuppressWarnings("deprecation")
public class BuildService extends Service {
    private static final String CHANNEL_ID = "BuildKernelServiceChannel";
    private NanoHTTPD server;
    private boolean isCompiling = false;
    
    // High-performance static tracking variable to check active state cleanly
    public static boolean isRunning = false;
    public static int activePort = 8089;

    @Override
    public void onCreate() {
        super.onCreate();
        isRunning = true;
        createNotificationChannel();
        Notification notification;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            notification = new Notification.Builder(this, CHANNEL_ID)
                    .setContentTitle("BuildKernel Daemon Active")
                    .setContentText("Listening on port 8089")
                    .setSmallIcon(android.R.drawable.stat_notify_sync)
                    .build();
        } else {
            notification = new Notification.Builder(this)
                    .setContentTitle("BuildKernel Daemon Active")
                    .setContentText("Listening on port 8089")
                    .setSmallIcon(android.R.drawable.stat_notify_sync)
                    .build();
        }
        if (Build.VERSION.SDK_INT >= 29) {
            try {
                java.lang.reflect.Method startForegroundMethod = Service.class.getMethod(
                    "startForeground", int.class, Notification.class, int.class);
                startForegroundMethod.invoke(this, 1, notification, 1); // 1 = ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC
            } catch (Exception e) {
                e.printStackTrace();
                startForeground(1, notification);
            }
        } else {
            startForeground(1, notification);
        }
        startServer();
    }

    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel serviceChannel = new NotificationChannel(
                    CHANNEL_ID,
                    "BuildKernel Service Channel",
                    NotificationManager.IMPORTANCE_DEFAULT
            );
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager != null) {
                manager.createNotificationChannel(serviceChannel);
            }
        }
    }

    private void startServer() {
        int[] candidatePorts = {8089, 8090, 8091, 8092};
        for (int port : candidatePorts) {
            final int currentPort = port;
            server = new NanoHTTPD(currentPort, new NanoHTTPD.OnRequestCallback() {
                @Override
                public void handleRequest(Socket socket, String method, String uri, Map<String, String> queryParams, InputStream bodyStream) throws Exception {
                    final OutputStream out = socket.getOutputStream();
                    if (uri.equals("/status")) {
                        String json = "{\"service\":\"buildkernel\",\"online\":true,\"compiling\":" + isCompiling + ",\"port\":" + currentPort + "}";
                        out.write("HTTP/1.1 200 OK\r\n".getBytes());
                        out.write("Access-Control-Allow-Origin: *\r\n".getBytes());
                        out.write("Content-Type: application/json\r\n".getBytes());
                        out.write(("Content-Length: " + json.length() + "\r\n\r\n").getBytes());
                        out.write(json.getBytes());
                        out.flush();
                    } else if (uri.equals("/compile") && method.equalsIgnoreCase("POST")) {
                    if (isCompiling) {
                        sendErrorResponse(out, "409 Conflict", "Compilation is already in progress");
                        return;
                    }
                    isCompiling = true;
                    try {
                        String name = queryParams.containsKey("name") ? queryParams.get("name") : "UnnamedApp";
                        
                        // Create private working directories
                        File toolchainDir = new File(getFilesDir(), "toolchain");
                        File workspaceDir = new File(getFilesDir(), "workspace");
                        if (!toolchainDir.exists()) toolchainDir.mkdirs();
                        if (!workspaceDir.exists()) workspaceDir.mkdirs();

                        // Extract the raw request body stream directly to a temp ZIP
                        File tempZip = File.createTempFile("project", ".zip", getCacheDir());
                        try (FileOutputStream fos = new FileOutputStream(tempZip)) {
                            byte[] buffer = new byte[8192];
                            int bytesRead;
                            while ((bytesRead = bodyStream.read(buffer)) != -1) {
                                fos.write(buffer, 0, bytesRead);
                            }
                        }

                        // Send HTTP OK headers
                        out.write("HTTP/1.1 200 OK\r\n".getBytes());
                        out.write("Access-Control-Allow-Origin: *\r\n".getBytes());
                        out.write("Content-Type: text/plain; charset=utf-8\r\n".getBytes());
                        out.write("Connection: close\r\n\r\n".getBytes());
                        out.flush();

                        CompileEngine.LogCallback callback = new CompileEngine.LogCallback() {
                            @Override
                            public void onLog(String msg) {
                                try {
                                    out.write((msg + "\n").getBytes("UTF-8"));
                                    out.flush();
                                } catch (IOException e) {
                                    e.printStackTrace();
                                }
                            }
                        };

                        String nativeLibDir = getApplicationInfo().nativeLibraryDir;
                        CompileEngine engine = new CompileEngine(toolchainDir, workspaceDir, nativeLibDir, callback);
                        File outputApk = new File("/sdcard/Download/" + name + "-debug.apk");
                        
                        boolean success = engine.compile(name, tempZip, outputApk);
                        if (success) {
                            out.write(("[BUILD_RESULT] SUCCESS " + outputApk.getAbsolutePath() + "\n").getBytes("UTF-8"));
                        } else {
                            out.write("[BUILD_RESULT] FAILED\n".getBytes("UTF-8"));
                        }
                        out.flush();
                        tempZip.delete();
                    } finally {
                        isCompiling = false;
                    }
                } else {
                    sendErrorResponse(out, "404 Not Found", "Endpoint not found");
                }
            }
        });

            try {
                server.start();
                activePort = currentPort;
                updateNotification("Listening on port " + activePort);
                break;
            } catch (IOException e) {
                // Port occupied, try next candidate port
            }
        }
    }

    private void updateNotification(String text) {
        NotificationManager manager = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        if (manager != null) {
            Notification notification;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                notification = new Notification.Builder(this, CHANNEL_ID)
                        .setContentTitle("BuildKernel Daemon Active")
                        .setContentText(text)
                        .setSmallIcon(android.R.drawable.stat_notify_sync)
                        .build();
            } else {
                notification = new Notification.Builder(this)
                        .setContentTitle("BuildKernel Daemon Active")
                        .setContentText(text)
                        .setSmallIcon(android.R.drawable.stat_notify_sync)
                        .build();
            }
            manager.notify(1, notification);
        }
    }

    private void sendErrorResponse(OutputStream out, String status, String message) throws IOException {
        String json = "{\"error\":\"" + message + "\"}";
        out.write(("HTTP/1.1 " + status + "\r\n").getBytes());
        out.write("Access-Control-Allow-Origin: *\r\n".getBytes());
        out.write("Content-Type: application/json\r\n".getBytes());
        out.write(("Content-Length: " + json.length() + "\r\n\r\n").getBytes());
        out.write(json.getBytes());
        out.flush();
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        isRunning = false;
        if (server != null) {
            server.stop();
        }
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}