package com.conjure.runtime;

import java.io.BufferedReader;
import java.io.File;
import java.io.FileOutputStream;
import java.io.FileReader;
import java.util.ArrayList;
import java.util.List;

public final class ProcessManager {
    private final android.content.Context context;
    private final File runtimeRoot;
    private final String nativeLibDir;
    private final TailscaleManager tailscaleManager;
    private final MdnsManager mdnsManager;
    private Process phpProcess;
    private Process nginxProcess;
    private int httpsPort = 8000;
    private int httpPort = 8001;

    public ProcessManager(android.content.Context context, File runtimeRoot, String nativeLibDir) {
        this.context = context;
        this.runtimeRoot = runtimeRoot;
        this.nativeLibDir = nativeLibDir;
        this.tailscaleManager = new TailscaleManager(runtimeRoot, nativeLibDir);
        this.mdnsManager = new MdnsManager(context);
    }

    public ProcessManager(File runtimeRoot, String nativeLibDir, int httpsPort, int httpPort) {
        this.context = null;
        this.runtimeRoot = runtimeRoot;
        this.nativeLibDir = nativeLibDir;
        this.httpsPort = httpsPort;
        this.httpPort = httpPort;
        this.tailscaleManager = new TailscaleManager(runtimeRoot, nativeLibDir);
        this.mdnsManager = new MdnsManager(null);
    }

    public MdnsManager getMdnsManager() {
        return mdnsManager;
    }

    public TailscaleManager getTailscaleManager() {
        return tailscaleManager;
    }

    public synchronized String start() {
        if (isRunning()) {
            return "Runtime already running";
        }

        File php = new File(nativeLibDir, "libphp.so");
        File nginx = new File(nativeLibDir, "libnginx.so");

        if (!php.isFile() || !nginx.isFile()) {
            return "Runtime binaries are not installed in native library directory";
        }

        try {
            File conjureRoot;
            if (context != null) {
                android.content.SharedPreferences prefs = context.getSharedPreferences("ConjureRuntimeState", android.content.Context.MODE_PRIVATE);
                String activePath = prefs.getString("active_system_path", "/storage/emulated/0/Conjure OS");
                conjureRoot = new File(activePath);
            } else {
                conjureRoot = new File("/storage/emulated/0/Conjure OS");
            }

            File indexFile = new File(conjureRoot, "index.php");
            File appDirectory = new File(conjureRoot, "app");
            File appsDirectory = new File(conjureRoot, "apps");

            if (!indexFile.isFile()
                    || indexFile.length() == 0
                    || (!appDirectory.isDirectory() && !appsDirectory.isDirectory())) {
                return "A valid Conjure OS package is required before starting the runtime";
            }

            File logDir = new File(runtimeRoot, "logs");
            File configDir = new File(runtimeRoot, "config");
            File tempDir = new File(runtimeRoot, "temp");

            if (!logDir.mkdirs() && !logDir.isDirectory()) {
                return "Unable to create runtime log directory";
            }

            if (!configDir.mkdirs() && !configDir.isDirectory()) {
                return "Unable to create runtime configuration directory";
            }

            if (!tempDir.mkdirs() && !tempDir.isDirectory()) {
                return "Unable to create runtime temp directory";
            }

            if (context != null) {
                android.content.SharedPreferences prefs = context.getSharedPreferences("ConjureRuntimeState", android.content.Context.MODE_PRIVATE);
                this.httpsPort = prefs.getInt("https_port", 8000);
                this.httpPort = prefs.getInt("http_port", 8001);
            }

            // Kill orphan/stale background processes FIRST before checking port availability
            killOrphanRuntimeProcesses();
            try { Thread.sleep(150); } catch (Exception ignored) {}

            if (!isPortAvailable(httpsPort)) {
                return "HTTPS Port " + httpsPort + " is occupied by another app. Please change the HTTPS port in settings.";
            }

            if (!isPortAvailable(httpPort)) {
                return "HTTP Port " + httpPort + " is occupied by another app. Please change the HTTP port in settings.";
            }

            clearLogFiles(logDir);

            try {
                SslManager sslManager = new SslManager(runtimeRoot, nativeLibDir);
                sslManager.ensureCertificates();
            } catch (Exception sslErr) {
                return "SSL Certificate generation failed: " + sslErr.getMessage();
            }

            new File(tempDir, "client_body").mkdirs();
            new File(tempDir, "proxy").mkdirs();
            new File(tempDir, "fastcgi").mkdirs();
            new File(tempDir, "uwsgi").mkdirs();
            new File(tempDir, "scgi").mkdirs();

            int phpPort = findAvailableLoopbackPort(9900);

            List<String> phpCommand = new ArrayList<>();
            phpCommand.add(php.getAbsolutePath());
            phpCommand.add("-d");
            phpCommand.add("display_errors=0");
            phpCommand.add("-d");
            phpCommand.add("html_errors=0");
            phpCommand.add("-d");
            phpCommand.add("log_errors=1");
            phpCommand.add("-d");
            phpCommand.add("error_log=" + new File(logDir, "php_error.log").getAbsolutePath());
            phpCommand.add("-d");
            phpCommand.add("upload_max_filesize=100M");
            phpCommand.add("-d");
            phpCommand.add("post_max_size=100M");
            phpCommand.add("-d");
            phpCommand.add("memory_limit=256M");
            phpCommand.add("-d");
            phpCommand.add("opcache.enable=0");
            phpCommand.add("-d");
            phpCommand.add("opcache.enable_cli=0");
            phpCommand.add("-d");
            phpCommand.add("sys_temp_dir=" + tempDir.getAbsolutePath());
            phpCommand.add("-d");
            phpCommand.add("upload_tmp_dir=" + tempDir.getAbsolutePath());
            phpCommand.add("-S");
            phpCommand.add("127.0.0.1:" + phpPort);
            phpCommand.add("-t");
            phpCommand.add(conjureRoot.getAbsolutePath());

            ProcessBuilder phpBuilder = new ProcessBuilder(phpCommand);
            phpBuilder.directory(runtimeRoot);
            phpBuilder.environment().put("LD_LIBRARY_PATH", nativeLibDir);
            phpBuilder.environment().put("PHP_CLI_SERVER_WORKERS", "16");
            phpBuilder.environment().put("TMPDIR", tempDir.getAbsolutePath());
            phpBuilder.environment().put("TMP", tempDir.getAbsolutePath());
            phpBuilder.environment().put("TEMP", tempDir.getAbsolutePath());
            phpBuilder.redirectErrorStream(true);
            phpBuilder.redirectOutput(new File(logDir, "php.log"));
            phpProcess = phpBuilder.start();

            File nginxConfig = new File(configDir, "nginx.conf");
            writeProxyNginxConfig(runtimeRoot, nginxConfig, httpsPort, httpPort, phpPort);

            List<String> nginxCommand = new ArrayList<>();
            nginxCommand.add(nginx.getAbsolutePath());
            nginxCommand.add("-c");
            nginxCommand.add(nginxConfig.getAbsolutePath());
            nginxCommand.add("-p");
            nginxCommand.add(runtimeRoot.getAbsolutePath());
            nginxCommand.add("-e");
            nginxCommand.add(new File(logDir, "nginx-error.log").getAbsolutePath());

            ProcessBuilder nginxBuilder = new ProcessBuilder(nginxCommand);
            nginxBuilder.directory(runtimeRoot);
            nginxBuilder.environment().put("LD_LIBRARY_PATH", nativeLibDir);
            nginxBuilder.environment().put("TMPDIR", tempDir.getAbsolutePath());
            nginxBuilder.environment().put("TMP", tempDir.getAbsolutePath());
            nginxBuilder.environment().put("TEMP", tempDir.getAbsolutePath());
            nginxBuilder.redirectErrorStream(true);
            nginxBuilder.redirectOutput(new File(logDir, "nginx.log"));
            nginxProcess = nginxBuilder.start();

            Thread.sleep(600);

            if (!isAlive(phpProcess) || !isAlive(nginxProcess)) {
                stop();
                String nginxError = readNginxLastError(new File(logDir, "nginx-error.log"));
                if (!nginxError.isEmpty()) {
                    return "Nginx Startup Error: " + nginxError;
                }
                return "PHP or Nginx exited during startup. Check runtime/logs/php.log and nginx-error.log";
            }

            if (mdnsManager != null) {
                mdnsManager.setRuntimeRoot(runtimeRoot);
                mdnsManager.start();
            }

            exportRuntimeActiveManifest("RUNNING");

            return "Runtime started";
        } catch (Exception error) {
            stop();
            return error.getMessage() == null ? "Runtime start failed" : error.getMessage();
        }
    }

    private boolean isPortAvailable(int port) {
        try (java.net.ServerSocket ss = new java.net.ServerSocket()) {
            ss.setReuseAddress(true);
            ss.bind(new java.net.InetSocketAddress("0.0.0.0", port));
            return true;
        } catch (Exception e) {
            return false;
        }
    }

    private void killOrphanRuntimeProcesses() {
        killStaleProcess(new File(runtimeRoot, "nginx.pid"));

        try {
            File procDir = new File("/proc");
            File[] pids = procDir.listFiles();
            if (pids != null) {
                int myPid = android.os.Process.myPid();

                for (File pidDir : pids) {
                    if (!pidDir.isDirectory()) continue;
                    String name = pidDir.getName();
                    if (!name.matches("\\d+")) continue;

                    try {
                        int pid = Integer.parseInt(name);
                        if (pid == myPid) continue;

                        File cmdlineFile = new File(pidDir, "cmdline");
                        if (cmdlineFile.exists()) {
                            try (BufferedReader br = new BufferedReader(new FileReader(cmdlineFile))) {
                                String cmdline = br.readLine();
                                if (cmdline != null && (cmdline.contains("libphp.so") || cmdline.contains("libnginx.so"))) {
                                    android.os.Process.killProcess(pid);
                                }
                            }
                        }
                    } catch (Exception ignored) {}
                }
            }
        } catch (Exception ignored) {}
    }

    private String readNginxLastError(File logFile) {
        if (!logFile.exists() || !logFile.isFile()) return "";
        try (java.io.BufferedReader br = new java.io.BufferedReader(new java.io.FileReader(logFile))) {
            String line;
            String lastError = "";
            while ((line = br.readLine()) != null) {
                if (line.contains("[emerg]") || line.contains("[error]") || line.contains("bind() failed")) {
                    lastError = line.trim();
                }
            }
            return lastError;
        } catch (Exception ignored) {
            return "";
        }
    }

    private void clearLogFiles(File logDir) {
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
    }

    private void killStaleProcess(File pidFile) {
        if (pidFile.exists() && pidFile.isFile()) {
            try {
                String pidStr;
                try (java.io.BufferedReader reader = new java.io.BufferedReader(new java.io.FileReader(pidFile))) {
                    pidStr = reader.readLine();
                }
                if (pidStr != null && !pidStr.trim().isEmpty()) {
                    int pid = Integer.parseInt(pidStr.trim());
                    android.os.Process.killProcess(pid);
                }
            } catch (Exception ignored) {
            } finally {
                pidFile.delete();
            }
        }
    }

    private int findAvailableLoopbackPort(int startPort) {
        for (int port = startPort; port < startPort + 20; port++) {
            try (java.net.ServerSocket ss = new java.net.ServerSocket()) {
                ss.bind(new java.net.InetSocketAddress("127.0.0.1", port));
                return port;
            } catch (Exception ignored) {
            }
        }
        return startPort;
    }

    public static void writeProxyNginxConfig(File runtimeRoot, File configFile, int httpsPort, int httpPort, int phpPort) throws Exception {
        String prefix = runtimeRoot.getAbsolutePath().replace("\\", "/");
        String logDir = new File(runtimeRoot, "logs").getAbsolutePath().replace("\\", "/");
        String tempDir = new File(runtimeRoot, "temp").getAbsolutePath().replace("\\", "/");
        String sslDir = new File(runtimeRoot, "ssl").getAbsolutePath().replace("\\", "/");

        File tsCert = new File(sslDir, "tailscale_server.crt");
        File tsKey = new File(sslDir, "tailscale_server.key");
        boolean hasTsCert = tsCert.exists() && tsCert.length() > 0 && tsKey.exists() && tsKey.length() > 0;

        StringBuilder sb = new StringBuilder();
        sb.append("daemon off;\n");
        sb.append("worker_processes auto;\n");
        sb.append("pid ").append(prefix).append("/nginx.pid;\n");
        sb.append("error_log ").append(logDir).append("/nginx-error.log warn;\n");
        sb.append("events { worker_connections 1024; }\n");
        sb.append("http {\n");
        sb.append("    access_log ").append(logDir).append("/nginx-access.log;\n");
        sb.append("    client_max_body_size 512M;\n");
        sb.append("    keepalive_timeout 65;\n");
        sb.append("    proxy_read_timeout 300s;\n");
        sb.append("    proxy_send_timeout 300s;\n");
        sb.append("    proxy_buffers 16 32k;\n");
        sb.append("    proxy_buffer_size 64k;\n");
        sb.append("    proxy_busy_buffers_size 128k;\n");
        sb.append("    client_body_temp_path ").append(tempDir).append("/client_body;\n");
        sb.append("    proxy_temp_path ").append(tempDir).append("/proxy;\n");
        sb.append("    fastcgi_temp_path ").append(tempDir).append("/fastcgi;\n");
        sb.append("    uwsgi_temp_path ").append(tempDir).append("/uwsgi;\n");
        sb.append("    scgi_temp_path ").append(tempDir).append("/scgi;\n");

        // HTTP Server Block
        sb.append("    server {\n");
        sb.append("        listen 0.0.0.0:").append(httpPort).append(";\n");
        sb.append("        location / {\n");
        sb.append("            proxy_pass http://127.0.0.1:").append(phpPort).append(";\n");
        sb.append("            proxy_set_header Host $http_host;\n");
        sb.append("            proxy_set_header X-Real-IP $remote_addr;\n");
        sb.append("            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n");
        sb.append("            proxy_set_header X-Forwarded-Proto $scheme;\n");
        sb.append("            proxy_http_version 1.1;\n");
        sb.append("            proxy_set_header Upgrade $http_upgrade;\n");
        sb.append("            proxy_set_header Connection \"upgrade\";\n");
        sb.append("        }\n");
        sb.append("    }\n");

        // HTTPS Default Local IP Server Block (127.0.0.1 / Local LAN IPs)
        sb.append("    server {\n");
        sb.append("        listen 0.0.0.0:").append(httpsPort).append(" ssl default_server;\n");
        sb.append("        http2 on;\n");
        sb.append("        ssl_certificate ").append(sslDir).append("/server.crt;\n");
        sb.append("        ssl_certificate_key ").append(sslDir).append("/server.key;\n");
        sb.append("        ssl_protocols TLSv1.2 TLSv1.3;\n");
        sb.append("        error_page 497 https://$http_host$request_uri;\n");
        sb.append("        location / {\n");
        sb.append("            proxy_pass http://127.0.0.1:").append(phpPort).append(";\n");
        sb.append("            proxy_set_header Host $http_host;\n");
        sb.append("            proxy_set_header X-Real-IP $remote_addr;\n");
        sb.append("            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n");
        sb.append("            proxy_set_header X-Forwarded-Proto $scheme;\n");
        sb.append("            proxy_http_version 1.1;\n");
        sb.append("            proxy_set_header Upgrade $http_upgrade;\n");
        sb.append("            proxy_set_header Connection \"upgrade\";\n");
        sb.append("        }\n");
        sb.append("    }\n");

        // HTTPS Tailscale Domain SNI Server Block (*.ts.net)
        if (hasTsCert) {
            sb.append("    server {\n");
            sb.append("        listen 0.0.0.0:").append(httpsPort).append(" ssl;\n");
            sb.append("        http2 on;\n");
            sb.append("        server_name *.ts.net;\n");
            sb.append("        ssl_certificate ").append(sslDir).append("/tailscale_server.crt;\n");
            sb.append("        ssl_certificate_key ").append(sslDir).append("/tailscale_server.key;\n");
            sb.append("        ssl_protocols TLSv1.2 TLSv1.3;\n");
            sb.append("        error_page 497 https://$http_host$request_uri;\n");
            sb.append("        location / {\n");
            sb.append("            proxy_pass http://127.0.0.1:").append(phpPort).append(";\n");
            sb.append("            proxy_set_header Host $http_host;\n");
            sb.append("            proxy_set_header X-Real-IP $remote_addr;\n");
            sb.append("            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n");
            sb.append("            proxy_set_header X-Forwarded-Proto $scheme;\n");
            sb.append("            proxy_http_version 1.1;\n");
            sb.append("            proxy_set_header Upgrade $http_upgrade;\n");
            sb.append("            proxy_set_header Connection \"upgrade\";\n");
            sb.append("        }\n");
            sb.append("    }\n");
        }

        sb.append("}\n");

        try (FileOutputStream output = new FileOutputStream(configFile)) {
            output.write(sb.toString().getBytes("UTF-8"));
        }
    }

    public synchronized void stop() {
        if (tailscaleManager != null) {
            tailscaleManager.stop();
        }

        if (nginxProcess != null) {
            try {
                File nginxBin = new File(nativeLibDir, "libnginx.so");
                File configDir = new File(runtimeRoot, "config");
                File nginxConfig = new File(configDir, "nginx.conf");
                File logDir = new File(runtimeRoot, "logs");
                if (nginxBin.exists() && nginxConfig.exists()) {
                    ProcessBuilder pb = new ProcessBuilder(
                        nginxBin.getAbsolutePath(),
                        "-c", nginxConfig.getAbsolutePath(),
                        "-p", runtimeRoot.getAbsolutePath(),
                        "-e", new File(logDir, "nginx-error.log").getAbsolutePath(),
                        "-s", "quit"
                    );
                    pb.directory(runtimeRoot);
                    pb.environment().put("LD_LIBRARY_PATH", nativeLibDir);
                    Process p = pb.start();
                    p.waitFor();
                }
            } catch (Exception ignored) {}

            nginxProcess.destroy();
            nginxProcess = null;
        }

        if (phpProcess != null) {
            phpProcess.destroy();
            if (android.os.Build.VERSION.SDK_INT >= 26) {
                phpProcess.destroyForcibly();
            }
            phpProcess = null;
        }

        killOrphanRuntimeProcesses();
        exportRuntimeActiveManifest("STOPPED");
    }

    public void exportRuntimeActiveManifest(String status) {
        try {
            File configDir = new File(android.os.Environment.getExternalStorageDirectory(), "Conjure_Config");
            if (!configDir.exists()) {
                configDir.mkdirs();
            }
            File manifestFile = new File(configDir, "runtime_active.json");
            long now = System.currentTimeMillis();

            String httpUrl = "http://127.0.0.1:" + httpPort;
            String httpsUrl = "https://127.0.0.1:" + httpsPort;

            StringBuilder sb = new StringBuilder();
            sb.append("{\n");
            sb.append("  \"status\": \"").append(status != null ? status : "STOPPED").append("\",\n");
            sb.append("  \"http_port\": ").append(httpPort).append(",\n");
            sb.append("  \"https_port\": ").append(httpsPort).append(",\n");
            sb.append("  \"http_url\": \"").append(httpUrl).append("\",\n");
            sb.append("  \"https_url\": \"").append(httpsUrl).append("\",\n");
            sb.append("  \"updated_at\": ").append(now).append("\n");
            sb.append("}\n");

            try (FileOutputStream fos = new FileOutputStream(manifestFile)) {
                fos.write(sb.toString().getBytes("UTF-8"));
                fos.flush();
                fos.getFD().sync();
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    public synchronized boolean isRunning() {
        return isAlive(phpProcess) && isAlive(nginxProcess);
    }

    public synchronized String getStatus() {
        if (isRunning()) {
            return "RUNNING";
        }

        if (phpProcess != null || nginxProcess != null) {
            return "STOPPED";
        }

        File php = new File(nativeLibDir, "libphp.so");
        File nginx = new File(nativeLibDir, "libnginx.so");

        if (!php.isFile() || !nginx.isFile()) {
            return "BUNDLES_REQUIRED";
        }

        return "STOPPED";
    }

    private boolean isAlive(Process process) {
        if (process == null) {
            return false;
        }

        try {
            process.exitValue();
            return false;
        } catch (IllegalThreadStateException running) {
            return true;
        }
    }
}