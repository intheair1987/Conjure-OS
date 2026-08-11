package com.conjure.keymapper;

import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.os.Handler;
import android.os.Looper;

import java.io.BufferedReader;
import java.io.ByteArrayOutputStream;
import java.io.DataOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.FileWriter;
import java.io.IOException;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import org.json.JSONArray;
import org.json.JSONObject;

public class RemoteUploaderEngine {
    private static JSONObject lastConjureConfig = null;

    public static synchronized void startConjureRecording(Context context, JSONObject config) {
        lastConjureConfig = config;
        long intervalMs = config != null ? config.optLong("intervalMs", 3000) : 3000;
        long pulseLen = config != null ? config.optLong("pulseDurationMs", 100) : 100;
        int pulseAmp = config != null ? config.optInt("pulseAmplitude", 128) : 128;
        int bitRate = config != null ? config.optInt("bitRate", 64000) : 64000;
        int sampleRate = config != null ? config.optInt("sampleRate", 16000) : 16000;
        String format = config != null ? config.optString("format", "m4a") : "m4a";

        AudioRecorderEngine.startRecording(context, intervalMs, pulseLen, pulseAmp, bitRate, sampleRate, format);
    }

    public static synchronized boolean stopConjureRecording(final Context context) {
        AudioRecorderEngine.stopRecording(context);

        final File recFile = AudioRecorderEngine.getLatestRecordingFile();
        if (recFile == null || !recFile.exists()) {
            return false;
        }

        final JSONObject config = lastConjureConfig != null ? lastConjureConfig : new JSONObject();

        return uploadFileToConjure(context, recFile, config, true);
    }

    public static String getActiveRemoteUploadUrl(Context context) {
        try {
            String settingsJson = WebBridge.readSettingsJson(context);
            JSONObject settings = new JSONObject(settingsJson);
            boolean isAutoDetect = settings.optBoolean("auto_detect_urls", true);
            boolean isManual = settings.optBoolean("manual_urls_enabled", false);
            String manualHttp = settings.optString("manual_http_url", "http://127.0.0.1:8001");
            String manualHttps = settings.optString("manual_https_url", "https://127.0.0.1:8000");

            if (isAutoDetect) {
                String runtimeJson = WebBridge.readRuntimeActiveJson();
                JSONObject runtime = new JSONObject(runtimeJson);
                if ("RUNNING".equalsIgnoreCase(runtime.optString("status", ""))) {
                    String baseUrl = runtime.optString("http_url", "");
                    if (baseUrl.isEmpty()) baseUrl = runtime.optString("https_url", "");
                    if (!baseUrl.isEmpty()) {
                        return baseUrl.replaceAll("/+$", "") + "/index.php?plugin_action=remote_upload";
                    }
                }
            }

            if (isManual) {
                String baseUrl = manualHttp.isEmpty() ? manualHttps : manualHttp;
                if (!baseUrl.isEmpty()) {
                    return baseUrl.replaceAll("/+$", "") + "/index.php?plugin_action=remote_upload";
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }

        return "http://127.0.0.1:8001/index.php?plugin_action=remote_upload";
    }

    public static boolean uploadFileToConjure(Context context, File audioFile, JSONObject config, boolean saveOnFailure) {
        String apiUrl = getActiveRemoteUploadUrl(context);
        String folder = config.optString("folder", "Unsorted");
        boolean autoTranscribe = config.optBoolean("transcribe", true);

        try {
            if (apiUrl.toLowerCase().startsWith("https://")) {
                setupGlobalTrustManager();
            }

            String boundary = "KeyMapperBoundary" + System.currentTimeMillis();
            URL url = new URL(apiUrl);
            HttpURLConnection conn = (HttpURLConnection) url.openConnection();

            if (conn instanceof javax.net.ssl.HttpsURLConnection) {
                trustAllCertificates((javax.net.ssl.HttpsURLConnection) conn);
            }
            conn.setDoOutput(true);
            conn.setDoInput(true);
            conn.setUseCaches(false);
            conn.setRequestMethod("POST");
            conn.setConnectTimeout(15000);
            conn.setReadTimeout(180000); // 3 minutes timeout for AI transcription

            conn.setRequestProperty("Connection", "Keep-Alive");
            conn.setRequestProperty("Content-Type", "multipart/form-data; boundary=" + boundary);
            conn.setRequestProperty("X-Folder", folder);
            conn.setRequestProperty("X-Transcribe", autoTranscribe ? "on" : "off");

            DataOutputStream dos = new DataOutputStream(conn.getOutputStream());

            // Field: folder
            dos.writeBytes("--" + boundary + "\r\n");
            dos.writeBytes("Content-Disposition: form-data; name=\"folder\"\r\n\r\n");
            dos.writeBytes(folder + "\r\n");

            // Field: transcribe
            dos.writeBytes("--" + boundary + "\r\n");
            dos.writeBytes("Content-Disposition: form-data; name=\"transcribe\"\r\n\r\n");
            dos.writeBytes((autoTranscribe ? "on" : "off") + "\r\n");

            // File: audio
            dos.writeBytes("--" + boundary + "\r\n");
            dos.writeBytes("Content-Disposition: form-data; name=\"audio\"; filename=\"" + audioFile.getName() + "\"\r\n");
            dos.writeBytes("Content-Type: audio/m4a\r\n\r\n");

            FileInputStream fis = new FileInputStream(audioFile);
            byte[] buffer = new byte[8192];
            int bytesRead;
            while ((bytesRead = fis.read(buffer)) != -1) {
                dos.write(buffer, 0, bytesRead);
            }
            fis.close();
            dos.writeBytes("\r\n");
            dos.writeBytes("--" + boundary + "--\r\n");
            dos.flush();
            dos.close();

            int responseCode = conn.getResponseCode();
            if (responseCode == 200) {
                InputStream is = conn.getInputStream();
                BufferedReader reader = new BufferedReader(new InputStreamReader(is, "UTF-8"));
                StringBuilder sb = new StringBuilder();
                String line;
                while ((line = reader.readLine()) != null) {
                    sb.append(line);
                }
                reader.close();

                JSONObject responseJson = new JSONObject(sb.toString());
                String status = responseJson.optString("status", "");
                if ("success".equalsIgnoreCase(status) || responseJson.has("transcription")) {
                    String text = responseJson.optString("transcription", responseJson.optString("text", ""));
                    if (text != null && !text.trim().isEmpty() && !"null".equalsIgnoreCase(text)) {
                        copyToClipboard(context, text.trim());

                        boolean targetCaretDrop = config.optBoolean("targetCaretDrop", false);
                        if (targetCaretDrop) {
                            CaretOverlayManager.showCaretOverlay(context, text.trim());
                        }
                    }
                    ActionExecutor.vibrateDevice(context, 100);

                    // Clean up local recording file upon successful upload
                    if (audioFile != null && audioFile.exists()) {
                        audioFile.delete();
                    }
                    return true;
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }

        // Handle Failure
        if (saveOnFailure) {
            saveToFailedQueue(context, audioFile, config);
        }
        return false;
    }

    private static void setupGlobalTrustManager() {
        try {
            javax.net.ssl.TrustManager[] trustAllCerts = new javax.net.ssl.TrustManager[] {
                new javax.net.ssl.X509TrustManager() {
                    public java.security.cert.X509Certificate[] getAcceptedIssuers() { return new java.security.cert.X509Certificate[0]; }
                    public void checkClientTrusted(java.security.cert.X509Certificate[] certs, String authType) {}
                    public void checkServerTrusted(java.security.cert.X509Certificate[] certs, String authType) {}
                }
            };
            javax.net.ssl.SSLContext sc = javax.net.ssl.SSLContext.getInstance("TLS");
            sc.init(null, trustAllCerts, new java.security.SecureRandom());
            javax.net.ssl.HttpsURLConnection.setDefaultSSLSocketFactory(sc.getSocketFactory());
            javax.net.ssl.HttpsURLConnection.setDefaultHostnameVerifier(new javax.net.ssl.HostnameVerifier() {
                public boolean verify(String hostname, javax.net.ssl.SSLSession session) { return true; }
            });
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    private static void trustAllCertificates(javax.net.ssl.HttpsURLConnection conn) {
        try {
            javax.net.ssl.TrustManager[] trustAllCerts = new javax.net.ssl.TrustManager[] {
                new javax.net.ssl.X509TrustManager() {
                    public java.security.cert.X509Certificate[] getAcceptedIssuers() { return new java.security.cert.X509Certificate[0]; }
                    public void checkClientTrusted(java.security.cert.X509Certificate[] certs, String authType) {}
                    public void checkServerTrusted(java.security.cert.X509Certificate[] certs, String authType) {}
                }
            };
            javax.net.ssl.SSLContext sc = javax.net.ssl.SSLContext.getInstance("TLS");
            sc.init(null, trustAllCerts, new java.security.SecureRandom());
            conn.setSSLSocketFactory(sc.getSocketFactory());
            conn.setHostnameVerifier(new javax.net.ssl.HostnameVerifier() {
                public boolean verify(String hostname, javax.net.ssl.SSLSession session) { return true; }
            });
        } catch (Exception ignored) {}
    }

    private static void copyToClipboard(final Context context, final String text) {
        if (context == null || text == null || text.trim().isEmpty()) return;
        new Handler(Looper.getMainLooper()).post(new Runnable() {
            @Override
            public void run() {
                try {
                    ClipboardManager clipboard = (ClipboardManager) context.getSystemService(Context.CLIPBOARD_SERVICE);
                    if (clipboard != null) {
                        ClipData clip = ClipData.newPlainText("Conjure Transcription", text.trim());
                        clipboard.setPrimaryClip(clip);
                    }
                } catch (Exception e) {
                    e.printStackTrace();
                }
            }
        });
    }

    private static File getFailedDirectory() {
        File configDir = new File(android.os.Environment.getExternalStorageDirectory(), "Conjure_Config");
        File failedDir = new File(configDir, "KeyMapper/Failed_Uploads");
        if (!failedDir.exists()) {
            failedDir.mkdirs();
        }
        return failedDir;
    }

    private static void saveToFailedQueue(Context context, File audioFile, JSONObject config) {
        try {
            File failedDir = getFailedDirectory();

            String baseName = "FAILED_" + System.currentTimeMillis();
            String ext = audioFile.getName().contains(".") ? audioFile.getName().substring(audioFile.getName().lastIndexOf('.')) : ".m4a";
            File destAudio = new File(failedDir, baseName + ext);

            copyFile(audioFile, destAudio);

            if (audioFile != null && audioFile.exists()) {
                audioFile.delete();
            }

            JSONObject meta = new JSONObject();
            meta.put("id", baseName);
            meta.put("originalPath", audioFile.getAbsolutePath());
            meta.put("audioFileName", destAudio.getName());
            meta.put("timestamp", System.currentTimeMillis());
            meta.put("apiUrl", config.optString("apiUrl", ""));
            meta.put("folder", config.optString("folder", "Unsorted"));
            meta.put("transcribe", config.optBoolean("transcribe", true));

            File metaFile = new File(failedDir, baseName + ".json");
            FileWriter writer = new FileWriter(metaFile);
            writer.write(meta.toString(2));
            writer.flush();
            writer.close();

            // Triple error vibration
            ActionExecutor.vibrateDevice(context, 150);

        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    public static String getFailedUploadsJson(Context context) {
        try {
            File failedDir = getFailedDirectory();
            File[] files = failedDir.listFiles();
            if (files == null) return "[]";

            JSONArray list = new JSONArray();
            for (File f : files) {
                if (f.getName().endsWith(".json")) {
                    String content = readFileToString(f);
                    if (content != null) {
                        list.put(new JSONObject(content));
                    }
                }
            }
            return list.toString();
        } catch (Exception e) {
            return "[]";
        }
    }

    public static boolean retryFailedUpload(final Context context, final String id) {
        try {
            File failedDir = getFailedDirectory();
            File metaFile = new File(failedDir, id + ".json");
            if (!metaFile.exists()) return false;

            JSONObject meta = new JSONObject(readFileToString(metaFile));
            String audioFileName = meta.optString("audioFileName", "");
            File audioFile = new File(failedDir, audioFileName);

            if (!audioFile.exists()) return false;

            boolean success = uploadFileToConjure(context, audioFile, meta, false);
            if (success) {
                metaFile.delete();
                audioFile.delete();
                return true;
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return false;
    }

    public static boolean deleteFailedUpload(Context context, String id) {
        try {
            File failedDir = getFailedDirectory();
            File metaFile = new File(failedDir, id + ".json");
            if (metaFile.exists()) {
                JSONObject meta = new JSONObject(readFileToString(metaFile));
                String audioFileName = meta.optString("audioFileName", "");
                String originalPath = meta.optString("originalPath", "");

                File audioFile = new File(failedDir, audioFileName);
                if (audioFile.exists()) audioFile.delete();

                if (originalPath != null && !originalPath.isEmpty()) {
                    File origFile = new File(originalPath);
                    if (origFile.exists()) origFile.delete();
                }

                metaFile.delete();
                return true;
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
        return false;
    }

    private static void copyFile(File src, File dst) throws IOException {
        try (InputStream in = new FileInputStream(src);
             OutputStream out = new FileOutputStream(dst)) {
            byte[] buf = new byte[8192];
            int len;
            while ((len = in.read(buf)) > 0) {
                out.write(buf, 0, len);
            }
        }
    }

    private static String readFileToString(File file) {
        try (FileInputStream fis = new FileInputStream(file);
             ByteArrayOutputStream baos = new ByteArrayOutputStream()) {
            byte[] buf = new byte[1024];
            int len;
            while ((len = fis.read(buf)) != -1) baos.write(buf, 0, len);
            return baos.toString("UTF-8");
        } catch (Exception e) {
            return null;
        }
    }
}