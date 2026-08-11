package com.conjure.apkwrapper;

import android.app.Activity;
import android.webkit.JavascriptInterface;
import android.webkit.WebView;
import java.io.File;

public class WebBridge {
    private Activity activity;
    private WebView webView;
    private android.speech.tts.TextToSpeech tts;
    private boolean ttsReady = false;

    public WebBridge(Activity activity, WebView webView) {
        this.activity = activity;
        this.webView = webView;
        try {
            this.tts = new android.speech.tts.TextToSpeech(activity, new android.speech.tts.TextToSpeech.OnInitListener() {
                @Override
                public void onInit(int status) {
                    if (status == android.speech.tts.TextToSpeech.SUCCESS) {
                        ttsReady = true;
                        if (tts != null) {
                            tts.setLanguage(java.util.Locale.US);
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
        activity.runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (tts != null && ttsReady) {
                    try {
                        if (lang != null && !lang.trim().isEmpty()) {
                            tts.setLanguage(new java.util.Locale(lang.trim()));
                        }
                    } catch (Exception ignored) {}
                    try {
                        tts.setPitch(pitch > 0 ? pitch : 1.0f);
                        tts.setSpeechRate(rate > 0 ? rate : 1.0f);
                        tts.speak(cleanText, android.speech.tts.TextToSpeech.QUEUE_FLUSH, null, "Utterance_" + System.currentTimeMillis());
                    } catch (Exception e) {
                        e.printStackTrace();
                    }
                }
            }
        });
    }

    @JavascriptInterface
    public void stopSpeaking() {
        activity.runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (tts != null) {
                    try { tts.stop(); } catch (Exception ignored) {}
                }
            }
        });
    }

    @JavascriptInterface
    public boolean isSpeaking() {
        return (tts != null && ttsReady && tts.isSpeaking());
    }

    @JavascriptInterface
    public void saveBase64File(final String base64Data, final String filename, final String mimeType) {
        if (base64Data == null || base64Data.trim().isEmpty()) return;
        activity.runOnUiThread(new Runnable() {
            @Override
            public void run() {
                try {
                    String cleanBase64 = base64Data;
                    if (cleanBase64.contains(",")) {
                        cleanBase64 = cleanBase64.substring(cleanBase64.indexOf(",") + 1);
                    }
                    byte[] fileBytes = android.util.Base64.decode(cleanBase64.trim(), android.util.Base64.DEFAULT);

                    String safeFilename = (filename != null && !filename.trim().isEmpty()) 
                        ? filename.trim() 
                        : ("download_" + System.currentTimeMillis() + ".bin");

                    java.io.File downloadDir = android.os.Environment.getExternalStoragePublicDirectory(android.os.Environment.DIRECTORY_DOWNLOADS);
                    if (!downloadDir.exists()) downloadDir.mkdirs();
                    java.io.File destFile = new java.io.File(downloadDir, safeFilename);

                    try (java.io.FileOutputStream fos = new java.io.FileOutputStream(destFile)) {
                        fos.write(fileBytes);
                    }

                    android.widget.Toast.makeText(activity.getApplicationContext(), "Saved to Downloads: " + safeFilename, android.widget.Toast.LENGTH_LONG).show();
                    vibrate(20);
                } catch (Exception e) {
                    android.widget.Toast.makeText(activity.getApplicationContext(), "Save failed: " + e.getMessage(), android.widget.Toast.LENGTH_SHORT).show();
                }
            }
        });
    }

    public void destroyTts() {
        if (tts != null) {
            try {
                tts.stop();
                tts.shutdown();
            } catch (Exception ignored) {}
        }
    }

    @JavascriptInterface
    public void startCompilation(final String appName, final String pkgName, final String targetUrl, final String base64Icon) {
        logToTerminal("Received compilation request for: " + appName);
        logToTerminal("Package: " + pkgName);
        logToTerminal("Target URL: " + targetUrl);
        if (base64Icon != null && !base64Icon.isEmpty()) {
            logToTerminal("Custom icon provided (" + base64Icon.length() + " bytes).");
        } else {
            logToTerminal("No custom icon provided. Using default.");
        }
        
        new Thread(new Runnable() {
            @Override
            public void run() {
                File toolchainDir = new File(activity.getFilesDir(), "toolchain");
                File workspaceDir = new File(activity.getFilesDir(), "workspace");
                String nativeLibDir = activity.getApplicationInfo().nativeLibraryDir;
                
                CompileEngine engine = new CompileEngine(activity, toolchainDir, workspaceDir, nativeLibDir, new CompileEngine.LogCallback() {
                    @Override
                    public void onLog(String message) {
                        logToTerminal(message);
                    }
                });
                
                final File outputApk = new File("/sdcard/Download/" + appName.replace(" ", "") + ".apk");
                final boolean success = engine.compile(appName, pkgName, targetUrl, base64Icon, outputApk);
                
                activity.runOnUiThread(new Runnable() {
                    @Override
                    public void run() {
                        if (success) {
                            webView.evaluateJavascript("window.updateTerminal('Compilation finished successfully.', 'done');", null);
                            installApk(outputApk);
                        } else {
                            webView.evaluateJavascript("window.updateTerminal('Compilation failed.', 'error');", null);
                        }
                    }
                });
            }
        }).start();
    }

    private void installApk(File apkFile) {
        android.content.Intent intent = new android.content.Intent(android.content.Intent.ACTION_VIEW);
        intent.setDataAndType(android.net.Uri.fromFile(apkFile), "application/vnd.android.package-archive");
        intent.setFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK);
        try {
            activity.startActivity(intent);
        } catch (Exception e) {
            logToTerminal("Could not auto-launch installer. APK saved to: " + apkFile.getAbsolutePath());
        }
    }

    private void logToTerminal(final String message) {
        activity.runOnUiThread(new Runnable() {
            @Override
            public void run() {
                // Escape single quotes and newlines for JS
                String safeMsg = message.replace("'", "\\'").replace("\n", "\\n");
                webView.evaluateJavascript("window.updateTerminal('" + safeMsg + "', 'running');", null);
            }
        });
    }

    @JavascriptInterface
    public String loadProjects() {
        File projectsDir = new File(activity.getFilesDir(), "projects");
        if (!projectsDir.exists()) {
            return "[]";
        }
        File[] files = projectsDir.listFiles();
        if (files == null || files.length == 0) {
            return "[]";
        }
        StringBuilder sb = new StringBuilder();
        sb.append("[");
        boolean first = true;
        for (File f : files) {
            if (f.isFile() && f.getName().endsWith(".json")) {
                String content = readFile(f);
                if (content != null && !content.trim().isEmpty()) {
                    if (!first) {
                        sb.append(",");
                    }
                    sb.append(content);
                    first = false;
                }
            }
        }
        sb.append("]");
        return sb.toString();
    }

    @JavascriptInterface
    public boolean saveProject(String id, String jsonPayload) {
        try {
            File projectsDir = new File(activity.getFilesDir(), "projects");
            if (!projectsDir.exists()) {
                projectsDir.mkdirs();
            }
            File dest = new File(projectsDir, id + ".json");
            writeFile(dest, jsonPayload);
            return true;
        } catch (Exception e) {
            e.printStackTrace();
            return false;
        }
    }

    @JavascriptInterface
    public boolean isAppInstalled(final String pkgName) {
        if (pkgName == null || pkgName.trim().isEmpty()) return false;
        try {
            activity.getPackageManager().getPackageInfo(pkgName.trim(), 0);
            return true;
        } catch (Exception e) {
            return false;
        }
    }

    @JavascriptInterface
    public boolean launchApp(final String pkgName) {
        if (pkgName == null || pkgName.trim().isEmpty()) return false;
        try {
            android.content.Intent intent = activity.getPackageManager().getLaunchIntentForPackage(pkgName.trim());
            if (intent != null) {
                intent.addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK);
                activity.startActivity(intent);
                return true;
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return false;
    }

    @JavascriptInterface
    public void uninstallApp(final String pkgName) {
        if (pkgName == null || pkgName.trim().isEmpty()) return;
        final String cleanPkg = pkgName.trim();
        logToTerminal("Launching uninstaller for package: " + cleanPkg);
        activity.runOnUiThread(new Runnable() {
            @Override
            public void run() {
                try {
                    android.net.Uri packageUri = android.net.Uri.fromParts("package", cleanPkg, null);
                    android.content.Intent intent = new android.content.Intent(android.content.Intent.ACTION_UNINSTALL_PACKAGE, packageUri);
                    intent.putExtra(android.content.Intent.EXTRA_RETURN_RESULT, true);
                    intent.addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK);
                    activity.startActivity(intent);
                } catch (Exception e) {
                    try {
                        android.net.Uri packageUri = android.net.Uri.fromParts("package", cleanPkg, null);
                        android.content.Intent intent2 = new android.content.Intent(android.content.Intent.ACTION_DELETE, packageUri);
                        intent2.addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK);
                        activity.startActivity(intent2);
                    } catch (Exception ex) {
                        logToTerminal("Failed to launch uninstaller for " + cleanPkg + ": " + ex.getMessage());
                    }
                }
            }
        });
    }

    @SuppressWarnings("deprecation")
    @JavascriptInterface
    public void vibrate(long ms) {
        try {
            android.os.Vibrator v = (android.os.Vibrator) activity.getSystemService(Activity.VIBRATOR_SERVICE);
            if (v != null && v.hasVibrator()) {
                if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.O) {
                    v.vibrate(android.os.VibrationEffect.createOneShot(ms, android.os.VibrationEffect.DEFAULT_AMPLITUDE));
                } else {
                    v.vibrate(ms);
                }
            }
        } catch (Exception ignored) {}
    }

    @JavascriptInterface
    public boolean deleteProject(String id) {
        try {
            File projectsDir = new File(activity.getFilesDir(), "projects");
            File target = new File(projectsDir, id + ".json");
            if (target.exists()) {
                return target.delete();
            }
            return true;
        } catch (Exception e) {
            e.printStackTrace();
            return false;
        }
    }

    @JavascriptInterface
    public String exportCatalogToDownloads(String jsonPayload) {
        try {
            File downloadDir = new File("/sdcard/Download");
            if (!downloadDir.exists()) {
                downloadDir.mkdirs();
            }
            File backupFile = new File(downloadDir, "ApkWrapper_Catalog_Backup.json");
            writeFile(backupFile, jsonPayload);
            return "Catalog exported to /sdcard/Download/ApkWrapper_Catalog_Backup.json";
        } catch (Exception e) {
            e.printStackTrace();
            return "Error exporting catalog: " + e.getMessage();
        }
    }

    @JavascriptInterface
    public String getRuntimeActiveJson() {
        try {
            File configFile = new File("/sdcard/Conjure_Config/runtime_active.json");
            if (configFile.exists()) {
                String content = readFile(configFile);
                if (content != null && !content.trim().isEmpty()) {
                    return content;
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return "{}";
    }

    private String readFile(File file) {
        try (java.io.FileInputStream fis = new java.io.FileInputStream(file);
             java.io.ByteArrayOutputStream baos = new java.io.ByteArrayOutputStream()) {
            byte[] buf = new byte[1024];
            int len;
            while ((len = fis.read(buf)) != -1) {
                baos.write(buf, 0, len);
            }
            return baos.toString("UTF-8");
        } catch (Exception e) {
            e.printStackTrace();
            return null;
        }
    }

    private void writeFile(File file, String content) throws java.io.IOException {
        try (java.io.FileOutputStream fos = new java.io.FileOutputStream(file)) {
            fos.write(content.getBytes("UTF-8"));
        }
    }
}