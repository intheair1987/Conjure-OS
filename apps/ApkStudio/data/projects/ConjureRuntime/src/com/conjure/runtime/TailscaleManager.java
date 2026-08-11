package com.conjure.runtime;

import java.io.BufferedReader;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.FileReader;
import java.io.InputStreamReader;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

public final class TailscaleManager {
    private final File runtimeRoot;
    private final String nativeLibDir;
    private Process tailscaleProcess;
    private String authUrl = "";
    private String tailscaleIp = "";
    private String magicDns = "";
    private String lastStatus = "STOPPED";
    private boolean certFetched = false;
    private boolean certReady = false;
    private long lastCertFetchTime = 0;
    private int httpsPort = 8000;
    private int httpPort = 8001;

    public TailscaleManager(File runtimeRoot, String nativeLibDir) {
        this.runtimeRoot = runtimeRoot;
        this.nativeLibDir = nativeLibDir;
    }

    private void setupFakeNetworkBinaries(ProcessBuilder pb) {
        try {
            File fakeBinDir = new File(runtimeRoot, "fake_bin");
            if (!fakeBinDir.exists()) fakeBinDir.mkdirs();

            File fakeIfconfig = new File(fakeBinDir, "ifconfig");
            fakeIfconfig.delete();
            try {
                android.system.Os.symlink(new File(nativeLibDir, "libifconfig.so").getAbsolutePath(), fakeIfconfig.getAbsolutePath());
            } catch (Exception ignored) {}

            File fakeIp = new File(fakeBinDir, "ip");
            fakeIp.delete();
            try {
                android.system.Os.symlink(new File(nativeLibDir, "libip.so").getAbsolutePath(), fakeIp.getAbsolutePath());
            } catch (Exception ignored) {}

            String currentPath = System.getenv("PATH");
            if (currentPath == null) currentPath = "/system/bin:/system/xbin";
            pb.environment().put("PATH", fakeBinDir.getAbsolutePath() + ":" + currentPath);
        } catch (Exception ignored) {}
    }

    public synchronized String start(int httpsPort, int httpPort, String apiKey, String tags) {
        this.httpsPort = httpsPort;
        this.httpPort = httpPort;

        if (isRunning()) {
            return "Tailscale already running";
        }

        File tailscaledBin = new File(nativeLibDir, "libtailscaled.so");
        File tailscaleBin = new File(nativeLibDir, "libtailscale.so");
        if (!tailscaledBin.isFile() || !tailscaleBin.isFile()) {
            return "Tailscale binaries not found in native library directory";
        }

        try {
            File logDir = new File(runtimeRoot, "logs");
            File tsDir = new File(runtimeRoot, "tailscale");

            if (!logDir.exists()) logDir.mkdirs();
            if (!tsDir.exists()) tsDir.mkdirs();

            final File logFile = new File(logDir, "tailscale.log");
            if (logFile.exists()) logFile.delete();

            authUrl = "";
            tailscaleIp = "";
            magicDns = "";
            lastStatus = "STARTING";
            certFetched = false;
            lastCertFetchTime = 0;

            File socketFile = new File(tsDir, "tailscaled.sock");
            File stateFile = new File(tsDir, "tailscaled.state");

            // Restore persistent node state & SSL certificates from domain-isolated shared storage (/sdcard/.conjure_os_ssl/)
            restorePersistentState(logFile);

            // 1. Start the Background Daemon
            List<String> command = new ArrayList<>();
            command.add(tailscaledBin.getAbsolutePath());
            command.add("--tun=userspace-networking");
            command.add("--socket=" + socketFile.getAbsolutePath());
            command.add("--state=" + stateFile.getAbsolutePath());

            ProcessBuilder pb = new ProcessBuilder(command);
            pb.directory(runtimeRoot);
            pb.environment().put("LD_LIBRARY_PATH", nativeLibDir);
            setupFakeNetworkBinaries(pb);
            pb.redirectErrorStream(true);
            pb.redirectOutput(logFile);

            tailscaleProcess = pb.start();
            Thread.sleep(1500);

            if (!isAlive(tailscaleProcess)) {
                lastStatus = "ERROR";
                return "Tailscale exited during startup. Check logs/tailscale.log";
            }

            // 2. Trigger the `tailscale up` CLI command
            List<String> upCmd = new ArrayList<>();
            upCmd.add(tailscaleBin.getAbsolutePath());
            upCmd.add("--socket=" + socketFile.getAbsolutePath());
            upCmd.add("up");
            upCmd.add("--hostname=conjure");

            if (apiKey != null && !apiKey.trim().isEmpty()) {
                String cleanKey = apiKey.trim();

                // Pre-flight Tailnet sweep: Delete numbered stale nodes (conjure-1, conjure-2) to reclaim primary hostname "conjure"
                reclaimTailscaleHostname(cleanKey, "conjure", logFile);

                if (cleanKey.startsWith("tskey-auth-")) {
                    // Direct Node Auth Key
                    upCmd.add("--authkey=" + cleanKey);
                } else if (cleanKey.startsWith("tskey-client-")) {
                    // OAuth Client Secret: Generate pre-authorized Auth Key natively in Java
                    String generatedAuthKey = generateAuthKey(cleanKey, tags, logFile);
                    if (generatedAuthKey != null && (generatedAuthKey.startsWith("tskey-auth-") || generatedAuthKey.startsWith("tskey-client-"))) {
                        upCmd.add("--authkey=" + generatedAuthKey);
                        if (generatedAuthKey.startsWith("tskey-client-")) {
                            if (tags != null && !tags.trim().isEmpty()) {
                                String formattedTag = tags.trim();
                                if (!formattedTag.startsWith("tag:")) formattedTag = "tag:" + formattedTag;
                                upCmd.add("--advertise-tags=" + formattedTag);
                            } else {
                                upCmd.add("--advertise-tags=tag:server");
                            }
                        }
                    }
                }
                // Note: tskey-api- keys are used EXCLUSIVELY for REST API management (reclaimTailscaleHostname)
                // and are NOT passed as --authkey to tailscaled, eliminating node auth errors.
            }

            ProcessBuilder upPb = new ProcessBuilder(upCmd);
            upPb.directory(runtimeRoot);
            upPb.environment().put("LD_LIBRARY_PATH", nativeLibDir);
            setupFakeNetworkBinaries(upPb);
            upPb.redirectErrorStream(true);
            final Process upProcess = upPb.start();

            // Safe stream consumer for Android API 24 compatibility
            new Thread(new Runnable() {
                public void run() {
                    try (BufferedReader br = new BufferedReader(new InputStreamReader(upProcess.getInputStream()));
                         FileOutputStream fos = new FileOutputStream(logFile, true)) {
                        String line;
                        while ((line = br.readLine()) != null) {
                            String out = line + "\n";
                            fos.write(out.getBytes("UTF-8"));
                        }
                    } catch (Exception ignored) {}
                }
            }).start();

            return "Tailscale process started";
        } catch (Exception e) {
            stop();
            lastStatus = "ERROR";
            return e.getMessage() == null ? "Tailscale start failed" : e.getMessage();
        }
    }

    public synchronized void stop() {
        if (tailscaleProcess != null) {
            try {
                tailscaleProcess.destroy();
                if (android.os.Build.VERSION.SDK_INT >= 26) {
                    tailscaleProcess.destroyForcibly();
                }
            } catch (Exception ignored) {
            }
            tailscaleProcess = null;
        }
        lastStatus = "STOPPED";
        authUrl = "";
        tailscaleIp = "";
        magicDns = "";
        certFetched = false;
        certReady = false;
        lastCertFetchTime = 0;
    }

    public boolean isCertReady() {
        return certReady;
    }

    private boolean isCertCachedForDomain(String domain) {
        if (domain == null || domain.trim().isEmpty()) return false;
        File sslDir = new File(runtimeRoot, "ssl");
        File certFile = new File(sslDir, "server.crt");
        File keyFile = new File(sslDir, "server.key");
        File markerFile = new File(sslDir, "tailscale_cert.marker");

        if (!certFile.exists() || certFile.length() == 0 || !keyFile.exists() || keyFile.length() == 0 || !markerFile.exists()) {
            return false;
        }

        try (BufferedReader br = new BufferedReader(new FileReader(markerFile))) {
            String savedDomain = br.readLine();
            if (savedDomain != null && savedDomain.trim().equals(domain.trim())) {
                return true;
            } else {
                markerFile.delete();
                return false;
            }
        } catch (Exception e) {
            return false;
        }
    }

    private void writeCertMarker(String domain) {
        try {
            File sslDir = new File(runtimeRoot, "ssl");
            if (!sslDir.exists()) sslDir.mkdirs();
            File markerFile = new File(sslDir, "tailscale_cert.marker");
            try (FileOutputStream fos = new FileOutputStream(markerFile)) {
                fos.write(domain.trim().getBytes("UTF-8"));
                fos.flush();
            }
        } catch (Exception ignored) {}
    }

    public synchronized void resetNodeState() {
        stop();
        try {
            Thread.sleep(200);
        } catch (Exception ignored) {
        }

        File tsDir = new File(runtimeRoot, "tailscale");
        deleteRecursively(tsDir);

        File persistentTsDir = new File(android.os.Environment.getExternalStorageDirectory(), "Conjure OS/.tailscale");
        deleteRecursively(persistentTsDir);

        File markerFile = new File(runtimeRoot, "ssl/tailscale_cert.marker");
        if (markerFile.exists()) markerFile.delete();

        File logFile = new File(runtimeRoot, "logs/tailscale.log");
        if (logFile.exists()) {
            logFile.delete();
        }

        authUrl = "";
        tailscaleIp = "";
        magicDns = "";
        lastStatus = "STOPPED";
        certFetched = false;
    }

    private File getPersistentBaseDir() {
        File baseDir = new File(android.os.Environment.getExternalStorageDirectory(), "Conjure_Config/ssl");
        if (!baseDir.exists()) baseDir.mkdirs();
        return baseDir;
    }

    private void restorePersistentState(File logFile) {
        try {
            File baseDir = getPersistentBaseDir();
            File activeDomainFile = new File(baseDir, "active_domain.txt");
            String activeDomain = "";
            if (activeDomainFile.exists() && activeDomainFile.length() > 0) {
                try (BufferedReader br = new BufferedReader(new FileReader(activeDomainFile))) {
                    activeDomain = br.readLine();
                }
            }

            File targetDomainDir = null;
            if (activeDomain != null && !activeDomain.trim().isEmpty()) {
                targetDomainDir = new File(baseDir, "domains/" + activeDomain.trim());
            }

            if (targetDomainDir == null || !targetDomainDir.exists()) {
                File domainsDir = new File(baseDir, "domains");
                if (domainsDir.exists() && domainsDir.isDirectory()) {
                    File[] subdirs = domainsDir.listFiles();
                    if (subdirs != null && subdirs.length > 0) {
                        for (File sub : subdirs) {
                            if (sub.isDirectory() && new File(sub, "tailscaled.state").exists()) {
                                targetDomainDir = sub;
                                break;
                            }
                        }
                    }
                }
            }

            if (targetDomainDir != null && targetDomainDir.exists()) {
                File savedState = new File(targetDomainDir, "tailscaled.state");
                File localState = new File(runtimeRoot, "tailscale/tailscaled.state");
                if ((!localState.exists() || localState.length() == 0) && savedState.exists() && savedState.length() > 0) {
                    copyFile(savedState, localState);
                    appendTsLog(logFile, "[state] Restored node state from persistent domain vault: " + savedState.getAbsolutePath());
                }

                File savedCert = new File(targetDomainDir, "tailscale_server.crt");
                File savedKey = new File(targetDomainDir, "tailscale_server.key");
                File savedMarker = new File(targetDomainDir, "tailscale_cert.marker");

                File localSslDir = new File(runtimeRoot, "ssl");
                if (!localSslDir.exists()) localSslDir.mkdirs();

                File localCert = new File(localSslDir, "tailscale_server.crt");
                File localKey = new File(localSslDir, "tailscale_server.key");
                File localMarker = new File(localSslDir, "tailscale_cert.marker");

                if (savedCert.exists() && savedKey.exists() && savedMarker.exists() && (!localCert.exists() || localCert.length() == 0)) {
                    copyFile(savedCert, localCert);
                    copyFile(savedKey, localKey);
                    copyFile(savedMarker, localMarker);
                    appendTsLog(logFile, "[ssl] Restored Let's Encrypt TLS cert from persistent domain vault: " + savedCert.getAbsolutePath());
                }
            }
        } catch (Exception e) {
            appendTsLog(logFile, "[state] Exception in restorePersistentState: " + e.getMessage());
        }
    }

    private void savePersistentState(String domain) {
        if (domain == null || domain.trim().isEmpty()) return;
        try {
            String cleanDomain = domain.trim();
            File baseDir = getPersistentBaseDir();
            if (!baseDir.exists()) baseDir.mkdirs();

            File activeDomainFile = new File(baseDir, "active_domain.txt");
            try (FileOutputStream fos = new FileOutputStream(activeDomainFile)) {
                fos.write(cleanDomain.getBytes("UTF-8"));
                fos.flush();
            }

            File domainDir = new File(baseDir, "domains/" + cleanDomain);
            if (!domainDir.exists()) domainDir.mkdirs();

            File localState = new File(runtimeRoot, "tailscale/tailscaled.state");
            if (localState.exists() && localState.length() > 0) {
                copyFile(localState, new File(domainDir, "tailscaled.state"));
            }

            File localSslDir = new File(runtimeRoot, "ssl");
            File localCert = new File(localSslDir, "tailscale_server.crt");
            File localKey = new File(localSslDir, "tailscale_server.key");
            File localMarker = new File(localSslDir, "tailscale_cert.marker");

            if (localCert.exists() && localKey.exists() && localMarker.exists()) {
                copyFile(localCert, new File(domainDir, "tailscale_server.crt"));
                copyFile(localKey, new File(domainDir, "tailscale_server.key"));
                copyFile(localMarker, new File(domainDir, "tailscale_cert.marker"));
            }
        } catch (Exception ignored) {}
    }

    private String fetchOAuthTokenJava(String clientSecret) {
        try {
            java.net.URL url = new java.net.URL("https://api.tailscale.com/api/v2/oauth/token");
            java.net.HttpURLConnection conn = (java.net.HttpURLConnection) url.openConnection();
            conn.setRequestMethod("POST");
            conn.setDoOutput(true);
            conn.setConnectTimeout(8000);
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
                while ((line = br.readLine()) != null) sb.append(line);
                br.close();
                org.json.JSONObject res = new org.json.JSONObject(sb.toString());
                return res.optString("access_token", "");
            }
        } catch (Exception ignored) {}
        return "";
    }

    private void reclaimTailscaleHostname(String apiKey, String targetHostname, File logFile) {
        try {
            String token = apiKey;
            if (apiKey.startsWith("tskey-client-")) {
                token = fetchOAuthTokenJava(apiKey);
                if (token == null || token.isEmpty()) return;
            } else if (!apiKey.startsWith("tskey-api-")) {
                return;
            }

            appendTsLog(logFile, "[reclaim] Pre-flight Tailnet sweep: Checking for stale duplicate nodes...");
            java.net.URL url = new java.net.URL("https://api.tailscale.com/api/v2/tailnet/-/devices");
            java.net.HttpURLConnection conn = (java.net.HttpURLConnection) url.openConnection();
            conn.setRequestMethod("GET");
            conn.setConnectTimeout(8000);
            conn.setReadTimeout(10000);
            conn.setRequestProperty("Authorization", "Bearer " + token);

            if (conn.getResponseCode() != 200) {
                appendTsLog(logFile, "[reclaim] Device API returned HTTP " + conn.getResponseCode() + ". Skipping sweep.");
                return;
            }

            BufferedReader br = new BufferedReader(new InputStreamReader(conn.getInputStream()));
            StringBuilder sb = new StringBuilder();
            String line;
            while ((line = br.readLine()) != null) sb.append(line);
            br.close();

            org.json.JSONObject response = new org.json.JSONObject(sb.toString());
            if (!response.has("devices")) return;

            org.json.JSONArray devices = response.getJSONArray("devices");
            String targetLower = targetHostname.toLowerCase(Locale.US);

            for (int i = 0; i < devices.length(); i++) {
                org.json.JSONObject dev = devices.getJSONObject(i);
                String devId = dev.optString("id", "");
                String devHost = dev.optString("hostname", "").toLowerCase(Locale.US);
                String devName = dev.optString("name", "").toLowerCase(Locale.US);

                boolean isNumberedMatch = devHost.startsWith(targetLower + "-") ||
                    devHost.startsWith("conjure-runtime") ||
                    devHost.startsWith("tailscale-termux") ||
                    devName.startsWith(targetLower + "-") ||
                    devName.startsWith("tailscale-termux");

                if (isNumberedMatch && !devId.isEmpty()) {
                    appendTsLog(logFile, "[reclaim] Purging stale duplicate node: '" + dev.optString("name", "") + "' (ID: " + devId + ")");
                    try {
                        java.net.URL delUrl = new java.net.URL("https://api.tailscale.com/api/v2/device/" + devId);
                        java.net.HttpURLConnection delConn = (java.net.HttpURLConnection) delUrl.openConnection();
                        delConn.setRequestMethod("DELETE");
                        delConn.setConnectTimeout(5000);
                        delConn.setRequestProperty("Authorization", "Bearer " + token);
                        int delCode = delConn.getResponseCode();
                        delConn.disconnect();
                        appendTsLog(logFile, "[reclaim] -> Successfully purged duplicate node " + devId + " (HTTP " + delCode + ")");
                    } catch (Exception ignored) {}
                }
            }
        } catch (Exception e) {
            appendTsLog(logFile, "[reclaim] Pre-flight sweep exception: " + e.getMessage());
        }
    }

    private void copyFile(File src, File dest) {
        try {
            File parent = dest.getParentFile();
            if (parent != null && !parent.exists()) parent.mkdirs();
            try (FileInputStream in = new FileInputStream(src);
                 FileOutputStream out = new FileOutputStream(dest)) {
                byte[] buf = new byte[8192];
                int len;
                while ((len = in.read(buf)) > 0) {
                    out.write(buf, 0, len);
                }
                out.flush();
            }
        } catch (Exception ignored) {}
    }

    private void deleteRecursively(File target) {
        if (target == null || !target.exists()) return;
        if (target.isDirectory()) {
            File[] children = target.listFiles();
            if (children != null) {
                for (File child : children) {
                    deleteRecursively(child);
                }
            }
        }
        boolean deleted = target.delete();
        if (!deleted && target.exists()) {
            try { Thread.sleep(50); } catch (Exception ignored) {}
            target.delete();
        }
    }

    private String generateAuthKey(String inputKey, String tags, File logFile) {
        try {
            String bearerToken = inputKey;

            if (inputKey.startsWith("tskey-client-")) {
                appendTsLog(logFile, "[auth] Exchanging OAuth Client Secret for Bearer Token via Java...");
                java.net.URL url = new java.net.URL("https://api.tailscale.com/api/v2/oauth/token");
                java.net.HttpURLConnection conn = (java.net.HttpURLConnection) url.openConnection();
                conn.setRequestMethod("POST");
                conn.setDoOutput(true);
                conn.setConnectTimeout(10000);
                conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded");

                String body = "client_secret=" + java.net.URLEncoder.encode(inputKey, "UTF-8");
                java.io.OutputStream os = conn.getOutputStream();
                os.write(body.getBytes("UTF-8"));
                os.flush();
                os.close();

                if (conn.getResponseCode() != 200) {
                    appendTsLog(logFile, "[auth] Failed to exchange OAuth token. HTTP " + conn.getResponseCode());
                    return inputKey;
                }

                BufferedReader br = new BufferedReader(new InputStreamReader(conn.getInputStream()));
                StringBuilder sb = new StringBuilder();
                String line;
                while ((line = br.readLine()) != null) sb.append(line);
                br.close();
                
                org.json.JSONObject res = new org.json.JSONObject(sb.toString());
                bearerToken = res.optString("access_token", "");
                if (bearerToken.isEmpty()) return inputKey;

                appendTsLog(logFile, "[auth] Bearer Token acquired.");
            } else if (inputKey.startsWith("tskey-api-")) {
                appendTsLog(logFile, "[auth] Using standard API Key as Bearer Token...");
            } else {
                return inputKey;
            }

            appendTsLog(logFile, "[auth] Generating pre-authorized Auth Key...");

            java.net.URL keysUrl = new java.net.URL("https://api.tailscale.com/api/v2/tailnet/-/keys");
            java.net.HttpURLConnection keysConn = (java.net.HttpURLConnection) keysUrl.openConnection();
            keysConn.setRequestMethod("POST");
            keysConn.setDoOutput(true);
            keysConn.setConnectTimeout(10000);
            keysConn.setRequestProperty("Authorization", "Bearer " + bearerToken);
            keysConn.setRequestProperty("Content-Type", "application/json");

            String targetTags = (tags != null && !tags.trim().isEmpty()) ? tags.trim() : "tag:server";
            String[] tagArray = targetTags.split(",");
            org.json.JSONArray tagsJson = new org.json.JSONArray();
            for (String t : tagArray) {
                t = t.trim();
                if (!t.startsWith("tag:")) {
                    t = "tag:" + t;
                }
                tagsJson.put(t);
            }

            org.json.JSONObject createObj = new org.json.JSONObject();
            createObj.put("reusable", false);
            createObj.put("ephemeral", false);
            createObj.put("preauthorized", true);
            createObj.put("tags", tagsJson);

            org.json.JSONObject devicesObj = new org.json.JSONObject();
            devicesObj.put("create", createObj);

            org.json.JSONObject capObj = new org.json.JSONObject();
            capObj.put("devices", devicesObj);

            org.json.JSONObject payload = new org.json.JSONObject();
            payload.put("capabilities", capObj);

            java.io.OutputStream kos = keysConn.getOutputStream();
            kos.write(payload.toString().getBytes("UTF-8"));
            kos.flush();
            kos.close();

            if (keysConn.getResponseCode() == 200) {
                BufferedReader kbr = new BufferedReader(new InputStreamReader(keysConn.getInputStream()));
                StringBuilder ksb = new StringBuilder();
                String kline;
                while ((kline = kbr.readLine()) != null) ksb.append(kline);
                kbr.close();

                org.json.JSONObject keyRes = new org.json.JSONObject(ksb.toString());
                String authKey = keyRes.optString("key", "");
                if (!authKey.isEmpty()) {
                    appendTsLog(logFile, "[auth] Successfully generated Auth Key!");
                    return authKey;
                }
            } else {
                java.io.InputStream errStream = keysConn.getErrorStream();
                if (errStream != null) {
                    BufferedReader errBr = new BufferedReader(new InputStreamReader(errStream));
                    StringBuilder errSb = new StringBuilder();
                    String errLine;
                    while ((errLine = errBr.readLine()) != null) errSb.append(errLine);
                    errBr.close();
                    appendTsLog(logFile, "[auth] Failed to generate Auth Key. HTTP " + keysConn.getResponseCode() + " " + errSb.toString());
                } else {
                    appendTsLog(logFile, "[auth] Failed to generate Auth Key. HTTP " + keysConn.getResponseCode());
                }
            }

        } catch (Exception e) {
            appendTsLog(logFile, "[auth] Exception: " + e.getMessage());
        }
        return inputKey;
    }

    public synchronized boolean isRunning() {
        return isAlive(tailscaleProcess);
    }

    public String getStatus() {
        if (!isRunning()) {
            return lastStatus.equals("ERROR") ? "ERROR" : "STOPPED";
        }

        updateStatusFromCli();

        if (!tailscaleIp.isEmpty() || !magicDns.isEmpty()) {
            return "CONNECTED";
        }
        if (!authUrl.isEmpty()) {
            return "NEEDS_AUTH";
        }
        return "STARTING";
    }

    public String getAuthUrl() {
        updateStatusFromCli();
        return authUrl;
    }

    public String getTailscaleIp() {
        updateStatusFromCli();
        return tailscaleIp;
    }

    public String getMagicDns() {
        updateStatusFromCli();
        return magicDns;
    }

    private void updateStatusFromCli() {
        if (!isRunning()) return;
        File tailscaleBin = new File(nativeLibDir, "libtailscale.so");
        File socketFile = new File(runtimeRoot, "tailscale/tailscaled.sock");
        if (!tailscaleBin.exists() || !socketFile.exists()) return;

        try {
            ProcessBuilder pb = new ProcessBuilder(
                tailscaleBin.getAbsolutePath(),
                "--socket=" + socketFile.getAbsolutePath(),
                "status", "--json"
            );
            pb.environment().put("LD_LIBRARY_PATH", nativeLibDir);
            setupFakeNetworkBinaries(pb);
            Process p = pb.start();
            BufferedReader br = new BufferedReader(new InputStreamReader(p.getInputStream()));
            StringBuilder sb = new StringBuilder();
            String line;
            while ((line = br.readLine()) != null) {
                sb.append(line);
            }
            p.waitFor();
            String json = sb.toString();
            
            if (!json.isEmpty()) {
                org.json.JSONObject obj = new org.json.JSONObject(json);
                String backendState = obj.optString("BackendState", "");
                
                if ("NeedsLogin".equals(backendState)) {
                    authUrl = obj.optString("AuthURL", "");
                    tailscaleIp = "";
                    magicDns = "";
                } else if ("Running".equals(backendState)) {
                    if (!magicDns.isEmpty()) {
                        savePersistentState(magicDns);
                    }
                    authUrl = "";
                    org.json.JSONArray ips = obj.optJSONArray("TailscaleIPs");
                    if (ips != null && ips.length() > 0) {
                        tailscaleIp = ips.getString(0);
                    }
                    org.json.JSONObject self = obj.optJSONObject("Self");
                    if (self != null) {
                        magicDns = self.optString("DNSName", "");
                        // Tailscale returns FQDNs with a trailing dot. The 'cert' command rejects this.
                        if (magicDns.endsWith(".")) {
                            magicDns = magicDns.substring(0, magicDns.length() - 1);
                        }
                    }
                    
                    if (!magicDns.isEmpty()) {
                        if (isCertCachedForDomain(magicDns)) {
                            certFetched = true;
                            certReady = true;
                        } else if (!certFetched) {
                            long now = System.currentTimeMillis();
                            if (now - lastCertFetchTime > 15000) {
                                lastCertFetchTime = now;
                                certFetched = true;
                                certReady = false;
                                fetchCertificateAsync(magicDns);
                            }
                        }
                    }
                }
            }
        } catch (Exception e) {
        }
    }

    private void fetchCertificateAsync(final String domain) {
        new Thread(new Runnable() {
            public void run() {
                try {
                    File tailscaleBin = new File(nativeLibDir, "libtailscale.so");
                    File socketFile = new File(runtimeRoot, "tailscale/tailscaled.sock");
                    File sslDir = new File(runtimeRoot, "ssl");
                    final File logFile = new File(runtimeRoot, "logs/tailscale.log");

                    appendTsLog(logFile, "[cert] Requesting Let's Encrypt certificate for " + domain + "...");
                    
                    List<String> certCmd = new ArrayList<>();
                    certCmd.add(tailscaleBin.getAbsolutePath());
                    certCmd.add("--socket=" + socketFile.getAbsolutePath());
                    certCmd.add("cert");
                    certCmd.add("--cert-file=" + new File(sslDir, "tailscale_server.crt").getAbsolutePath());
                    certCmd.add("--key-file=" + new File(sslDir, "tailscale_server.key").getAbsolutePath());
                    certCmd.add(domain);

                    ProcessBuilder pb = new ProcessBuilder(certCmd);
                    pb.directory(runtimeRoot);
                    pb.environment().put("LD_LIBRARY_PATH", nativeLibDir);
                    setupFakeNetworkBinaries(pb);
                    pb.redirectErrorStream(true);
                    final Process p = pb.start();

                    // Safe stream consumer
                    new Thread(new Runnable() {
                        public void run() {
                            try (BufferedReader br = new BufferedReader(new InputStreamReader(p.getInputStream()));
                                 FileOutputStream fos = new FileOutputStream(logFile, true)) {
                                String line;
                                while ((line = br.readLine()) != null) {
                                    String out = line + "\n";
                                    fos.write(out.getBytes("UTF-8"));
                                }
                            } catch (Exception ignored) {}
                        }
                    }).start();

                    int exitCode = p.waitFor();

                    if (exitCode == 0) {
                        writeCertMarker(domain);
                        appendTsLog(logFile, "[cert] Successfully acquired Let's Encrypt certificate! Reloading Nginx to apply...");
                        reloadNginx();
                        certReady = true;
                    } else {
                        appendTsLog(logFile, "[cert] Failed to acquire certificate. Exit code: " + exitCode);
                        certFetched = false; // Reset to allow retry later
                        certReady = false;
                    }
                } catch (Exception e) {
                    certFetched = false;
                }
            }
        }).start();
    }

    private void reloadNginx() {
        try {
            File nginxBin = new File(nativeLibDir, "libnginx.so");
            File configDir = new File(runtimeRoot, "config");
            File nginxConfig = new File(configDir, "nginx.conf");
            File logDir = new File(runtimeRoot, "logs");

            if (nginxBin.exists() && nginxConfig.exists()) {
                int phpPort = 9900;
                
                try {
                    // Regenerate nginx.conf to include SNI server block for tailscale_server.crt with active custom ports
                    ProcessManager.writeProxyNginxConfig(runtimeRoot, nginxConfig, httpsPort, httpPort, phpPort);
                } catch (Exception ignored) {}

                ProcessBuilder pb = new ProcessBuilder(
                    nginxBin.getAbsolutePath(),
                    "-c", nginxConfig.getAbsolutePath(),
                    "-p", runtimeRoot.getAbsolutePath(),
                    "-e", new File(logDir, "nginx-error.log").getAbsolutePath(),
                    "-s", "reload"
                );
                pb.directory(runtimeRoot);
                pb.environment().put("LD_LIBRARY_PATH", nativeLibDir);
                pb.start().waitFor();
            }
        } catch (Exception ignored) {}
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

    public String readLog() {
        File logFile = new File(runtimeRoot, "logs/tailscale.log");
        if (!logFile.exists()) return "No Tailscale log found.";

        StringBuilder sb = new StringBuilder();
        try (BufferedReader reader = new BufferedReader(new FileReader(logFile))) {
            String line;
            while ((line = reader.readLine()) != null) {
                sb.append(line).append("\n");
            }
        } catch (Exception e) {
            return "Error reading log: " + e.getMessage();
        }
        return sb.toString();
    }

    private boolean isAlive(Process p) {
        if (p == null) return false;
        try {
            p.exitValue();
            return false;
        } catch (IllegalThreadStateException e) {
            return true;
        }
    }
}